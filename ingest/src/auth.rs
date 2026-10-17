//! Credential resolution and usage recording for the append-only ingest path.
//!
//! A live cache entry deliberately skips both credential lookup and usage
//! recording. Revocation can therefore lag until that entry's TTL (or the
//! credential's own expiry) elapses; no broader or immediate revocation claim
//! is made for this service.

use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};

use chrono::{NaiveDateTime, Timelike, Utc};
use sha2::{Digest, Sha256};
use sqlx::postgres::PgPool;
use sqlx::{Postgres, Row, Transaction};
use tokio::sync::RwLock;
#[cfg(test)]
use tokio::sync::Semaphore;
use uuid::Uuid;

use crate::error::SafeDatabaseError;

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct AuthorizedApp {
    pub credential_id: Uuid,
    pub subject_ref: String,
}

#[derive(Clone, Debug, Default)]
pub struct AuditContext {
    pub provider: Option<String>,
    pub deployment: Option<String>,
    pub environment: Option<String>,
}

#[derive(Clone)]
pub struct Authenticator {
    pool: PgPool,
    ttl: Duration,
    cache: Arc<RwLock<HashMap<String, CacheEntry>>>,
    audit_context: AuditContext,
    #[cfg(test)]
    test_hooks: Option<Arc<AuthTestHooks>>,
}

#[derive(Clone, Debug)]
struct CacheEntry {
    expires_at: Instant,
    app: AuthorizedApp,
}

struct ResolvedCredential {
    app: AuthorizedApp,
    expires_at: Option<NaiveDateTime>,
    first_use: bool,
}

#[cfg(test)]
struct AuthTestHooks {
    pause_after_resolution: bool,
    resolution_reached: Semaphore,
    resolution_release: Semaphore,
    pause_after_first_code_lock: bool,
    code_lock_attempted: Semaphore,
    code_lock_count: std::sync::atomic::AtomicUsize,
    first_code_lock_reached: Semaphore,
    first_code_lock_release: Semaphore,
    credential_lock_attempted: Semaphore,
}

impl Authenticator {
    pub fn new(pool: PgPool, ttl_seconds: u64, audit_context: AuditContext) -> Self {
        Self {
            pool,
            ttl: Duration::from_secs(ttl_seconds),
            cache: Arc::new(RwLock::new(HashMap::new())),
            audit_context,
            #[cfg(test)]
            test_hooks: None,
        }
    }

    pub async fn authorize(
        &self,
        presented_secret: &str,
    ) -> Result<Option<AuthorizedApp>, SafeDatabaseError> {
        if presented_secret.is_empty() {
            return Ok(None);
        }

        let secret_hash = hex::encode(Sha256::digest(presented_secret.as_bytes()));

        if let Some(app) = self.cached(&secret_hash).await {
            return Ok(Some(app));
        }

        let credential = self.resolve(&secret_hash).await?;
        let Some(credential) = credential else {
            return Ok(None);
        };

        #[cfg(test)]
        self.pause_after_resolution().await;

        if !self.record_usage(&credential).await? {
            return Ok(None);
        }

        self.remember(secret_hash, &credential).await;

        Ok(Some(credential.app))
    }

    async fn resolve(
        &self,
        secret_hash: &str,
    ) -> Result<Option<ResolvedCredential>, SafeDatabaseError> {
        let row = sqlx::query(
            r#"
            SELECT c.id, c.subject_ref, c.expires_at, (c.last_used_at IS NULL) AS first_use
            FROM credentials c
            WHERE c.kind = 'bearer'
              AND c.secret_hash = $1
              AND c.purpose = 'consumption'
              AND c.subject_type = 'installation'
              AND c.user_id IS NULL
              AND c.status = 'active'
              AND c.revoked_at IS NULL
              AND (c.expires_at IS NULL OR c.expires_at > clock_timestamp() AT TIME ZONE 'UTC')
              AND NOT EXISTS (
                  SELECT 1
                  FROM credential_protocol_bindings binding
                  WHERE binding.credential_id = c.id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM offboarded_subjects offboarded
                  WHERE offboarded.subject_type = c.subject_type
                    AND offboarded.subject_ref = c.subject_ref
                    AND offboarded.user_id = ''
              )
            LIMIT 1
            "#,
        )
        .bind(secret_hash)
        .fetch_optional(&self.pool)
        .await
        .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;

        row.map(|row| {
            Ok(ResolvedCredential {
                app: AuthorizedApp {
                    credential_id: row.try_get("id")?,
                    subject_ref: row.try_get("subject_ref")?,
                },
                expires_at: row.try_get("expires_at")?,
                first_use: row.try_get("first_use")?,
            })
        })
        .transpose()
        .map_err(|error: sqlx::Error| SafeDatabaseError::from_sqlx(&error))
    }

    async fn record_usage(
        &self,
        credential: &ResolvedCredential,
    ) -> Result<bool, SafeDatabaseError> {
        if !credential.first_use {
            return self
                .record_subsequent_use(credential.app.credential_id)
                .await;
        }

        self.record_first_use(credential.app.credential_id).await
    }

    async fn record_first_use(&self, credential_id: Uuid) -> Result<bool, SafeDatabaseError> {
        let mut transaction = self
            .pool
            .begin()
            .await
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;

        #[cfg(test)]
        self.signal_code_lock_attempt();

        let pending_codes = sqlx::query(
            r#"
            SELECT id, email
            FROM onboarding_tokens
            WHERE durable_credential_id = $1
              AND consumed_at IS NULL
            FOR UPDATE
            "#,
        )
        .bind(credential_id)
        .fetch_all(&mut *transaction)
        .await
        .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;

        #[cfg(test)]
        self.pause_after_first_code_lock().await;

        #[cfg(test)]
        self.signal_credential_lock_attempt();

        let credential_exists =
            sqlx::query_scalar::<_, Uuid>("SELECT id FROM credentials WHERE id = $1 FOR UPDATE")
                .bind(credential_id)
                .fetch_optional(&mut *transaction)
                .await
                .map_err(|error| SafeDatabaseError::from_sqlx(&error))?
                .is_some();
        if !credential_exists {
            transaction
                .rollback()
                .await
                .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;

            return Ok(false);
        }

        let predicate_now: NaiveDateTime =
            sqlx::query_scalar("SELECT clock_timestamp() AT TIME ZONE 'UTC'")
                .fetch_one(&mut *transaction)
                .await
                .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
        let stored_now = truncate_to_whole_second(predicate_now);

        let updated = sqlx::query(
            r#"
            UPDATE credentials
            SET last_used_at = $2, updated_at = $2
            WHERE id = $1
              AND last_used_at IS NULL
              AND status = 'active'
              AND revoked_at IS NULL
              AND (expires_at IS NULL OR expires_at > $3)
            "#,
        )
        .bind(credential_id)
        .bind(stored_now)
        .bind(predicate_now)
        .execute(&mut *transaction)
        .await
        .map_err(|error| SafeDatabaseError::from_sqlx(&error))?
        .rows_affected();

        if updated != 1 {
            let authenticated = Self::record_subsequent_use_in(
                &mut transaction,
                credential_id,
                predicate_now,
                stored_now,
            )
            .await?;
            if authenticated {
                transaction
                    .commit()
                    .await
                    .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
            } else {
                transaction
                    .rollback()
                    .await
                    .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
            }

            return Ok(authenticated);
        }

        let code_ids = pending_codes
            .iter()
            .filter_map(|row| row.try_get::<Uuid, _>("id").ok())
            .collect::<Vec<_>>();
        let burned = if code_ids.is_empty() {
            0
        } else {
            sqlx::query(
                r#"
                UPDATE onboarding_tokens
                SET consumed_at = $2, updated_at = $2
                WHERE id = ANY($1)
                  AND durable_credential_id = $3
                  AND consumed_at IS NULL
                "#,
            )
            .bind(&code_ids)
            .bind(stored_now)
            .bind(credential_id)
            .execute(&mut *transaction)
            .await
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?
            .rows_affected()
        };

        let linked_code = if burned > 0 {
            pending_codes.first().map(code_identity).transpose()?
        } else {
            sqlx::query(
                r#"
                SELECT id, email
                FROM onboarding_tokens
                WHERE durable_credential_id = $1
                  AND consumed_at IS NOT NULL
                ORDER BY consumed_at DESC
                LIMIT 1
                "#,
            )
            .bind(credential_id)
            .fetch_optional(&mut *transaction)
            .await
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?
            .map(|row| code_identity(&row))
            .transpose()?
        };

        let (code_id, recipient) = linked_code.unzip();
        sqlx::query(
            r#"
            INSERT INTO credential_audit_events (
                id, event, code_id, credential_id, credential_authorization_id,
                superseded_by_credential_id, provider, deployment, environment,
                actor_type, actor_ref, recipient, code_ttl_seconds,
                credential_expires_at, reason_code, note, occurred_at, created_at
            ) VALUES (
                $1, 'first_used', $2, $3, NULL,
                NULL, $4, $5, $6,
                'credential_holder', $7, $8, NULL,
                NULL, NULL, NULL, $9, $9
            )
            "#,
        )
        .bind(Uuid::new_v4())
        .bind(code_id)
        .bind(credential_id)
        .bind(&self.audit_context.provider)
        .bind(&self.audit_context.deployment)
        .bind(&self.audit_context.environment)
        .bind(credential_id.to_string())
        .bind(recipient)
        .bind(stored_now)
        .execute(&mut *transaction)
        .await
        .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;

        transaction
            .commit()
            .await
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;

        Ok(true)
    }

    async fn record_subsequent_use(&self, credential_id: Uuid) -> Result<bool, SafeDatabaseError> {
        let mut transaction = self
            .pool
            .begin()
            .await
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
        let credential_exists =
            sqlx::query_scalar::<_, Uuid>("SELECT id FROM credentials WHERE id = $1 FOR UPDATE")
                .bind(credential_id)
                .fetch_optional(&mut *transaction)
                .await
                .map_err(|error| SafeDatabaseError::from_sqlx(&error))?
                .is_some();
        if !credential_exists {
            transaction
                .rollback()
                .await
                .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;

            return Ok(false);
        }

        let predicate_now: NaiveDateTime =
            sqlx::query_scalar("SELECT clock_timestamp() AT TIME ZONE 'UTC'")
                .fetch_one(&mut *transaction)
                .await
                .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
        let stored_now = truncate_to_whole_second(predicate_now);
        let affected = sqlx::query(
            r#"
            UPDATE credentials
            SET last_used_at = $2,
                updated_at = $2
            WHERE id = $1
              AND status = 'active'
              AND revoked_at IS NULL
              AND (expires_at IS NULL OR expires_at > $3)
            "#,
        )
        .bind(credential_id)
        .bind(stored_now)
        .bind(predicate_now)
        .execute(&mut *transaction)
        .await
        .map_err(|error| SafeDatabaseError::from_sqlx(&error))?
        .rows_affected();

        if affected == 1 {
            transaction
                .commit()
                .await
                .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
        } else {
            transaction
                .rollback()
                .await
                .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
        }

        Ok(affected == 1)
    }

    async fn record_subsequent_use_in(
        transaction: &mut Transaction<'_, Postgres>,
        credential_id: Uuid,
        predicate_now: NaiveDateTime,
        stored_now: NaiveDateTime,
    ) -> Result<bool, SafeDatabaseError> {
        let affected = sqlx::query(
            r#"
            UPDATE credentials
            SET last_used_at = $2,
                updated_at = $2
            WHERE id = $1
              AND status = 'active'
              AND revoked_at IS NULL
              AND (expires_at IS NULL OR expires_at > $3)
            "#,
        )
        .bind(credential_id)
        .bind(stored_now)
        .bind(predicate_now)
        .execute(&mut **transaction)
        .await
        .map_err(|error| SafeDatabaseError::from_sqlx(&error))?
        .rows_affected();

        Ok(affected == 1)
    }

    async fn cached(&self, secret_hash: &str) -> Option<AuthorizedApp> {
        if self.ttl.is_zero() {
            return None;
        }

        let now = Instant::now();
        let mut cache = self.cache.write().await;
        cache.retain(|_, entry| entry.expires_at > now);
        cache.get(secret_hash).map(|entry| entry.app.clone())
    }

    async fn remember(&self, secret_hash: String, credential: &ResolvedCredential) {
        if self.ttl.is_zero() {
            return;
        }

        let now = Utc::now().naive_utc();
        let ttl = credential
            .expires_at
            .map(|expires_at| {
                (expires_at - now)
                    .to_std()
                    .unwrap_or_default()
                    .min(self.ttl)
            })
            .unwrap_or(self.ttl);
        if ttl.is_zero() {
            return;
        }
        let Some(expires_at) = Instant::now().checked_add(ttl) else {
            return;
        };

        self.cache.write().await.insert(
            secret_hash,
            CacheEntry {
                expires_at,
                app: credential.app.clone(),
            },
        );
    }

    #[cfg(test)]
    fn with_test_hooks(mut self, hooks: Arc<AuthTestHooks>) -> Self {
        self.test_hooks = Some(hooks);
        self
    }

    #[cfg(test)]
    async fn pause_after_resolution(&self) {
        let Some(hooks) = &self.test_hooks else {
            return;
        };
        hooks.resolution_reached.add_permits(1);
        if !hooks.pause_after_resolution {
            return;
        }

        if let Ok(permit) = hooks.resolution_release.acquire().await {
            permit.forget();
        }
    }

    #[cfg(test)]
    async fn pause_after_first_code_lock(&self) {
        use std::sync::atomic::Ordering;

        let Some(hooks) = &self.test_hooks else {
            return;
        };
        if !hooks.pause_after_first_code_lock
            || hooks.code_lock_count.fetch_add(1, Ordering::SeqCst) != 0
        {
            return;
        }

        hooks.first_code_lock_reached.add_permits(1);
        if let Ok(permit) = hooks.first_code_lock_release.acquire().await {
            permit.forget();
        }
    }

    #[cfg(test)]
    fn signal_code_lock_attempt(&self) {
        if let Some(hooks) = &self.test_hooks {
            hooks.code_lock_attempted.add_permits(1);
        }
    }

    #[cfg(test)]
    fn signal_credential_lock_attempt(&self) {
        if let Some(hooks) = &self.test_hooks {
            hooks.credential_lock_attempted.add_permits(1);
        }
    }
}

fn truncate_to_whole_second(timestamp: NaiveDateTime) -> NaiveDateTime {
    timestamp.with_nanosecond(0).unwrap_or(timestamp)
}

fn code_identity(row: &sqlx::postgres::PgRow) -> Result<(Uuid, Option<String>), SafeDatabaseError> {
    Ok((
        row.try_get("id")
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?,
        row.try_get("email")
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?,
    ))
}

#[cfg(test)]
mod tests {
    use chrono::Duration as ChronoDuration;
    use sqlx::Executor;

    use super::*;
    use crate::test_support::TestDatabase;

    fn context() -> AuditContext {
        AuditContext {
            provider: Some("Hone".to_string()),
            deployment: Some("hone-app".to_string()),
            environment: Some("testing".to_string()),
        }
    }

    #[tokio::test]
    async fn rejects_every_authorization_predicate() {
        let cases = [
            (
                "kind",
                "UPDATE credentials SET kind = 'basic' WHERE id = $1",
            ),
            (
                "hash",
                "UPDATE credentials SET secret_hash = 'wrong' WHERE id = $1",
            ),
            (
                "purpose",
                "UPDATE credentials SET purpose = 'mcp' WHERE id = $1",
            ),
            (
                "subject type",
                "UPDATE credentials SET subject_type = 'application' WHERE id = $1",
            ),
            (
                "ownership",
                "UPDATE credentials SET user_id = 'account' WHERE id = $1",
            ),
            (
                "status",
                "UPDATE credentials SET status = 'pending' WHERE id = $1",
            ),
            (
                "revoked",
                "UPDATE credentials SET revoked_at = now() WHERE id = $1",
            ),
            (
                "expired",
                "UPDATE credentials SET expires_at = now() - interval '1 second' WHERE id = $1",
            ),
        ];

        for (label, mutation) in cases {
            let database = TestDatabase::new().await;
            let credential = database.insert_credential("secret", None).await;
            sqlx::query(mutation)
                .bind(credential)
                .execute(&database.pool)
                .await
                .unwrap_or_else(|_| panic!("failed mutation for {label}"));

            let authenticator = Authenticator::new(database.pool.clone(), 0, context());
            assert_eq!(
                authenticator.authorize("secret").await.unwrap(),
                None,
                "{label}"
            );
            database.cleanup().await;
        }

        for (label, related_row) in [
            (
                "protocol binding",
                "INSERT INTO credential_protocol_bindings (credential_id) VALUES ($1)",
            ),
            (
                "offboarded subject",
                "INSERT INTO offboarded_subjects (subject_type, subject_ref, user_id) VALUES ('installation', 'checkout', '')",
            ),
        ] {
            let database = TestDatabase::new().await;
            let credential = database.insert_credential("secret", None).await;
            sqlx::query(related_row)
                .bind(credential)
                .execute(&database.pool)
                .await
                .unwrap_or_else(|_| panic!("failed related row for {label}"));

            let authenticator = Authenticator::new(database.pool.clone(), 0, context());
            assert_eq!(
                authenticator.authorize("secret").await.unwrap(),
                None,
                "{label}"
            );
            database.cleanup().await;
        }
    }

    #[tokio::test]
    async fn first_use_burns_codes_and_writes_the_exact_audit_shape_transactionally() {
        let database = TestDatabase::new().await;
        let credential = database.insert_credential("secret", None).await;
        let code = Uuid::new_v4();
        sqlx::query(
            "INSERT INTO onboarding_tokens (id, email, consumed_at, durable_credential_id, updated_at) VALUES ($1, $2, NULL, $3, now())",
        )
        .bind(code)
        .bind("holder@example.test")
        .bind(credential)
        .execute(&database.pool)
        .await
        .unwrap();

        let authenticator = Authenticator::new(database.pool.clone(), 0, context());
        let app = authenticator.authorize("secret").await.unwrap().unwrap();

        assert_eq!(app.subject_ref, "checkout");
        assert!(
            sqlx::query_scalar::<_, Option<NaiveDateTime>>(
                "SELECT last_used_at FROM credentials WHERE id = $1"
            )
            .bind(credential)
            .fetch_one(&database.pool)
            .await
            .unwrap()
            .is_some()
        );
        assert!(
            sqlx::query_scalar::<_, Option<NaiveDateTime>>(
                "SELECT consumed_at FROM onboarding_tokens WHERE id = $1"
            )
            .bind(code)
            .fetch_one(&database.pool)
            .await
            .unwrap()
            .is_some()
        );

        let audit = sqlx::query("SELECT * FROM credential_audit_events")
            .fetch_one(&database.pool)
            .await
            .unwrap();
        assert_eq!(audit.get::<String, _>("event"), "first_used");
        assert_eq!(audit.get::<Option<Uuid>, _>("code_id"), Some(code));
        assert_eq!(
            audit.get::<Option<Uuid>, _>("credential_id"),
            Some(credential)
        );
        assert_eq!(
            audit.get::<Option<String>, _>("provider").as_deref(),
            Some("Hone")
        );
        assert_eq!(
            audit.get::<Option<String>, _>("deployment").as_deref(),
            Some("hone-app")
        );
        assert_eq!(
            audit.get::<Option<String>, _>("environment").as_deref(),
            Some("testing")
        );
        assert_eq!(
            audit.get::<Option<String>, _>("actor_type").as_deref(),
            Some("credential_holder")
        );
        assert_eq!(
            audit.get::<Option<String>, _>("actor_ref"),
            Some(credential.to_string())
        );
        assert_eq!(
            audit.get::<Option<String>, _>("recipient").as_deref(),
            Some("holder@example.test")
        );
        assert!(
            (audit.get::<NaiveDateTime, _>("created_at") - Utc::now().naive_utc())
                .num_seconds()
                .abs()
                <= 1
        );

        authenticator.authorize("secret").await.unwrap().unwrap();
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM credential_audit_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            1
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn truncates_php_parity_write_timestamps_before_postgres_persists_them() {
        let database = TestDatabase::new().await;
        let credential = database.insert_credential("secret", None).await;
        let code = Uuid::new_v4();
        sqlx::query(
            "INSERT INTO onboarding_tokens (id, email, consumed_at, durable_credential_id, updated_at) VALUES ($1, NULL, NULL, $2, now())",
        )
        .bind(code)
        .bind(credential)
        .execute(&database.pool)
        .await
        .unwrap();
        sqlx::raw_sql(
            r#"
            CREATE FUNCTION clock_timestamp() RETURNS timestamp with time zone AS $$
                SELECT TIMESTAMPTZ '2026-06-09 11:59:59.600000+00'
            $$ LANGUAGE sql;
            "#,
        )
        .execute(&database.pool)
        .await
        .unwrap();

        let rounded: NaiveDateTime = sqlx::query_scalar(
            "SELECT CAST(TIMESTAMP '2026-06-09 11:59:59.600000' AS timestamp(0))",
        )
        .fetch_one(&database.pool)
        .await
        .unwrap();
        let expected =
            NaiveDateTime::parse_from_str("2026-06-09 11:59:59", "%Y-%m-%d %H:%M:%S").unwrap();
        assert_eq!(
            rounded,
            NaiveDateTime::parse_from_str("2026-06-09 12:00:00", "%Y-%m-%d %H:%M:%S").unwrap()
        );

        let authenticator = Authenticator::new(database.pool.clone(), 0, context());
        assert!(authenticator.authorize("secret").await.unwrap().is_some());

        let credential_row =
            sqlx::query("SELECT last_used_at, updated_at FROM credentials WHERE id = $1")
                .bind(credential)
                .fetch_one(&database.pool)
                .await
                .unwrap();
        assert_eq!(
            credential_row.get::<NaiveDateTime, _>("last_used_at"),
            expected
        );
        assert_eq!(
            credential_row.get::<NaiveDateTime, _>("updated_at"),
            expected
        );

        let code_row =
            sqlx::query("SELECT consumed_at, updated_at FROM onboarding_tokens WHERE id = $1")
                .bind(code)
                .fetch_one(&database.pool)
                .await
                .unwrap();
        assert_eq!(code_row.get::<NaiveDateTime, _>("consumed_at"), expected);
        assert_eq!(code_row.get::<NaiveDateTime, _>("updated_at"), expected);

        let audit = sqlx::query("SELECT occurred_at, created_at FROM credential_audit_events")
            .fetch_one(&database.pool)
            .await
            .unwrap();
        assert_eq!(audit.get::<NaiveDateTime, _>("occurred_at"), expected);
        assert_eq!(audit.get::<NaiveDateTime, _>("created_at"), expected);
        database.cleanup().await;
    }

    #[tokio::test]
    async fn an_audit_failure_rolls_back_usage_and_code_consumption() {
        let database = TestDatabase::new().await;
        let credential = database.insert_credential("secret", None).await;
        sqlx::query("INSERT INTO onboarding_tokens (id, email, consumed_at, durable_credential_id, updated_at) VALUES ($1, NULL, NULL, $2, now())")
            .bind(Uuid::new_v4())
            .bind(credential)
            .execute(&database.pool)
            .await
            .unwrap();
        database.pool.execute("ALTER TABLE credential_audit_events ADD CONSTRAINT force_audit_failure CHECK (event <> 'first_used')").await.unwrap();

        let authenticator = Authenticator::new(database.pool.clone(), 0, context());
        assert!(authenticator.authorize("secret").await.is_err());
        assert!(
            sqlx::query_scalar::<_, Option<NaiveDateTime>>(
                "SELECT last_used_at FROM credentials WHERE id = $1"
            )
            .bind(credential)
            .fetch_one(&database.pool)
            .await
            .unwrap()
            .is_none()
        );
        assert!(
            sqlx::query_scalar::<_, Option<NaiveDateTime>>(
                "SELECT consumed_at FROM onboarding_tokens LIMIT 1"
            )
            .fetch_one(&database.pool)
            .await
            .unwrap()
            .is_none()
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn concurrent_first_use_writes_one_audit_row() {
        let database = TestDatabase::new().await;
        let credential = database.insert_credential("secret", None).await;
        sqlx::query(
            "INSERT INTO onboarding_tokens (id, email, consumed_at, durable_credential_id, updated_at) VALUES ($1, NULL, NULL, $2, now())",
        )
        .bind(Uuid::new_v4())
        .bind(credential)
        .execute(&database.pool)
        .await
        .unwrap();
        let hooks = Arc::new(AuthTestHooks {
            pause_after_resolution: false,
            resolution_reached: Semaphore::new(0),
            resolution_release: Semaphore::new(0),
            pause_after_first_code_lock: true,
            code_lock_attempted: Semaphore::new(0),
            code_lock_count: std::sync::atomic::AtomicUsize::new(0),
            first_code_lock_reached: Semaphore::new(0),
            first_code_lock_release: Semaphore::new(0),
            credential_lock_attempted: Semaphore::new(0),
        });
        let authenticator = Arc::new(
            Authenticator::new(database.pool.clone(), 0, context()).with_test_hooks(hooks.clone()),
        );
        let first = {
            let authenticator = authenticator.clone();
            tokio::spawn(async move { authenticator.authorize("secret").await })
        };
        hooks.resolution_reached.acquire().await.unwrap().forget();
        hooks.code_lock_attempted.acquire().await.unwrap().forget();
        hooks
            .first_code_lock_reached
            .acquire()
            .await
            .unwrap()
            .forget();
        let second = {
            let authenticator = authenticator.clone();
            tokio::spawn(async move { authenticator.authorize("secret").await })
        };
        hooks.resolution_reached.acquire().await.unwrap().forget();
        hooks.code_lock_attempted.acquire().await.unwrap().forget();

        database.wait_for_lock("FROM onboarding_tokens").await;
        assert!(!second.is_finished());
        hooks.first_code_lock_release.add_permits(1);
        assert!(first.await.unwrap().unwrap().is_some());
        assert!(second.await.unwrap().unwrap().is_some());
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM credential_audit_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            1
        );
        assert_eq!(
            sqlx::query_scalar::<_, i64>(
                "SELECT count(*) FROM onboarding_tokens WHERE consumed_at IS NOT NULL"
            )
            .fetch_one(&database.pool)
            .await
            .unwrap(),
            1
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn revocation_between_resolution_and_usage_write_fails_closed() {
        let database = TestDatabase::new().await;
        let credential = database.insert_credential("secret", None).await;
        let hooks = Arc::new(AuthTestHooks {
            pause_after_resolution: true,
            resolution_reached: Semaphore::new(0),
            resolution_release: Semaphore::new(0),
            pause_after_first_code_lock: false,
            code_lock_attempted: Semaphore::new(0),
            code_lock_count: std::sync::atomic::AtomicUsize::new(0),
            first_code_lock_reached: Semaphore::new(0),
            first_code_lock_release: Semaphore::new(0),
            credential_lock_attempted: Semaphore::new(0),
        });
        let authenticator =
            Authenticator::new(database.pool.clone(), 0, context()).with_test_hooks(hooks.clone());
        let authorization = tokio::spawn(async move { authenticator.authorize("secret").await });
        hooks.resolution_reached.acquire().await.unwrap().forget();
        sqlx::query("UPDATE credentials SET revoked_at = now() WHERE id = $1")
            .bind(credential)
            .execute(&database.pool)
            .await
            .unwrap();
        hooks.resolution_release.add_permits(1);

        assert_eq!(authorization.await.unwrap().unwrap(), None);
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM credential_audit_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            0
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn expiry_during_a_first_use_code_lock_wait_fails_against_current_wall_clock() {
        let database = TestDatabase::new().await;
        let credential = database
            .insert_credential("secret", Some(Utc::now() + ChronoDuration::hours(1)))
            .await;
        sqlx::query(
            "INSERT INTO onboarding_tokens (id, email, consumed_at, durable_credential_id, updated_at) VALUES ($1, NULL, NULL, $2, now())",
        )
        .bind(Uuid::new_v4())
        .bind(credential)
        .execute(&database.pool)
        .await
        .unwrap();
        let hooks = Arc::new(AuthTestHooks {
            pause_after_resolution: false,
            resolution_reached: Semaphore::new(0),
            resolution_release: Semaphore::new(0),
            pause_after_first_code_lock: true,
            code_lock_attempted: Semaphore::new(0),
            code_lock_count: std::sync::atomic::AtomicUsize::new(0),
            first_code_lock_reached: Semaphore::new(0),
            first_code_lock_release: Semaphore::new(0),
            credential_lock_attempted: Semaphore::new(0),
        });
        let authenticator =
            Authenticator::new(database.pool.clone(), 0, context()).with_test_hooks(hooks.clone());
        let authorization = tokio::spawn(async move { authenticator.authorize("secret").await });
        hooks
            .first_code_lock_reached
            .acquire()
            .await
            .unwrap()
            .forget();
        sqlx::query(
            "UPDATE credentials SET expires_at = date_trunc('second', clock_timestamp() AT TIME ZONE 'UTC') + interval '1 second' WHERE id = $1",
        )
        .bind(credential)
        .execute(&database.pool)
        .await
        .unwrap();
        sqlx::query("SELECT pg_sleep(1.1)")
            .execute(&database.pool)
            .await
            .unwrap();
        hooks.first_code_lock_release.add_permits(1);

        assert_eq!(authorization.await.unwrap().unwrap(), None);
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM credential_audit_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            0
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn expiry_during_the_credential_lock_wait_fails_against_post_lock_wall_clock() {
        let database = TestDatabase::new().await;
        let credential = database
            .insert_credential("secret", Some(Utc::now() + ChronoDuration::hours(1)))
            .await;
        sqlx::query(
            "INSERT INTO onboarding_tokens (id, email, consumed_at, durable_credential_id, updated_at) VALUES ($1, NULL, NULL, $2, now())",
        )
        .bind(Uuid::new_v4())
        .bind(credential)
        .execute(&database.pool)
        .await
        .unwrap();
        let hooks = Arc::new(AuthTestHooks {
            pause_after_resolution: true,
            resolution_reached: Semaphore::new(0),
            resolution_release: Semaphore::new(0),
            pause_after_first_code_lock: false,
            code_lock_attempted: Semaphore::new(0),
            code_lock_count: std::sync::atomic::AtomicUsize::new(0),
            first_code_lock_reached: Semaphore::new(0),
            first_code_lock_release: Semaphore::new(0),
            credential_lock_attempted: Semaphore::new(0),
        });
        let authenticator =
            Authenticator::new(database.pool.clone(), 0, context()).with_test_hooks(hooks.clone());
        let authorization = tokio::spawn(async move { authenticator.authorize("secret").await });
        hooks.resolution_reached.acquire().await.unwrap().forget();

        let mut holder = database.pool.begin().await.unwrap();
        sqlx::query("SELECT id FROM credentials WHERE id = $1 FOR UPDATE")
            .bind(credential)
            .fetch_one(&mut *holder)
            .await
            .unwrap();
        sqlx::query(
            "UPDATE credentials SET expires_at = date_trunc('second', clock_timestamp() AT TIME ZONE 'UTC') + interval '1 second' WHERE id = $1",
        )
        .bind(credential)
        .execute(&mut *holder)
        .await
        .unwrap();
        hooks.resolution_release.add_permits(1);
        hooks.code_lock_attempted.acquire().await.unwrap().forget();
        hooks
            .credential_lock_attempted
            .acquire()
            .await
            .unwrap()
            .forget();
        database
            .wait_for_lock("SELECT id FROM credentials WHERE id")
            .await;
        sqlx::query("SELECT pg_sleep(1.1)")
            .execute(&database.pool)
            .await
            .unwrap();
        holder.commit().await.unwrap();

        assert_eq!(authorization.await.unwrap().unwrap(), None);
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM credential_audit_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            0
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn expiry_during_a_subsequent_use_lock_wait_fails_against_release_time() {
        let database = TestDatabase::new().await;
        let credential = database
            .insert_credential("secret", Some(Utc::now() + ChronoDuration::hours(1)))
            .await;
        sqlx::query(
            "UPDATE credentials SET last_used_at = now(), expires_at = date_trunc('second', clock_timestamp() AT TIME ZONE 'UTC') + interval '2 seconds' WHERE id = $1",
        )
        .bind(credential)
        .execute(&database.pool)
        .await
        .unwrap();

        let mut holder = database.pool.begin().await.unwrap();
        sqlx::query("SELECT id FROM credentials WHERE id = $1 FOR UPDATE")
            .bind(credential)
            .fetch_one(&mut *holder)
            .await
            .unwrap();

        let hooks = Arc::new(AuthTestHooks {
            pause_after_resolution: false,
            resolution_reached: Semaphore::new(0),
            resolution_release: Semaphore::new(0),
            pause_after_first_code_lock: false,
            code_lock_attempted: Semaphore::new(0),
            code_lock_count: std::sync::atomic::AtomicUsize::new(0),
            first_code_lock_reached: Semaphore::new(0),
            first_code_lock_release: Semaphore::new(0),
            credential_lock_attempted: Semaphore::new(0),
        });
        let authenticator =
            Authenticator::new(database.pool.clone(), 0, context()).with_test_hooks(hooks.clone());
        let authorization = tokio::spawn(async move { authenticator.authorize("secret").await });

        hooks.resolution_reached.acquire().await.unwrap().forget();
        database
            .wait_for_lock("SELECT id FROM credentials WHERE id")
            .await;
        assert!(
            sqlx::query_scalar::<_, bool>(
                "SELECT expires_at > clock_timestamp() AT TIME ZONE 'UTC' FROM credentials WHERE id = $1"
            )
            .bind(credential)
            .fetch_one(&database.pool)
            .await
            .unwrap()
        );
        sqlx::query("SELECT pg_sleep(2.1)")
            .execute(&database.pool)
            .await
            .unwrap();
        assert!(
            sqlx::query_scalar::<_, bool>(
                "SELECT expires_at <= clock_timestamp() AT TIME ZONE 'UTC' FROM credentials WHERE id = $1"
            )
            .bind(credential)
            .fetch_one(&database.pool)
            .await
            .unwrap()
        );
        holder.commit().await.unwrap();

        assert_eq!(authorization.await.unwrap().unwrap(), None);
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM credential_audit_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            0
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn cache_hits_skip_database_work_and_expire_no_later_than_the_credential() {
        let database = TestDatabase::new().await;
        let expires_at = Utc::now() + ChronoDuration::seconds(2);
        let credential = database.insert_credential("secret", Some(expires_at)).await;
        let authenticator = Authenticator::new(database.pool.clone(), 60, context());

        assert!(authenticator.authorize("secret").await.unwrap().is_some());
        {
            let cache = authenticator.cache.read().await;
            assert!(
                cache
                    .keys()
                    .all(|key| key == &hex::encode(Sha256::digest(b"secret")))
            );
            assert!(cache.values().all(|entry| {
                entry.app.credential_id == credential && entry.app.subject_ref == "checkout"
            }));
            assert!(!format!("{cache:?}").contains("secret"));
        }
        let first_used_at = sqlx::query_scalar::<_, NaiveDateTime>(
            "SELECT last_used_at FROM credentials WHERE id = $1",
        )
        .bind(credential)
        .fetch_one(&database.pool)
        .await
        .unwrap();
        sqlx::query("UPDATE credentials SET revoked_at = now() WHERE id = $1")
            .bind(credential)
            .execute(&database.pool)
            .await
            .unwrap();

        assert!(authenticator.authorize("secret").await.unwrap().is_some());
        assert_eq!(
            sqlx::query_scalar::<_, NaiveDateTime>(
                "SELECT last_used_at FROM credentials WHERE id = $1"
            )
            .bind(credential)
            .fetch_one(&database.pool)
            .await
            .unwrap(),
            first_used_at
        );
        tokio::time::sleep(Duration::from_millis(3_100)).await;
        assert_eq!(authenticator.authorize("secret").await.unwrap(), None);
        database.cleanup().await;
    }

    #[tokio::test]
    async fn zero_ttl_disables_the_cache() {
        let database = TestDatabase::new().await;
        database.insert_credential("secret", None).await;
        let authenticator = Authenticator::new(database.pool.clone(), 0, context());

        assert!(authenticator.authorize("secret").await.unwrap().is_some());
        assert!(authenticator.cache.read().await.is_empty());
        assert!(authenticator.cache.read().await.is_empty());
        database.cleanup().await;
    }

    #[tokio::test]
    async fn enabled_cache_never_stores_rejections() {
        let database = TestDatabase::new().await;
        let credential = database.insert_credential("revoked", None).await;
        sqlx::query("UPDATE credentials SET revoked_at = now() WHERE id = $1")
            .bind(credential)
            .execute(&database.pool)
            .await
            .unwrap();
        let authenticator = Authenticator::new(database.pool.clone(), 60, context());

        assert_eq!(authenticator.authorize("unknown").await.unwrap(), None);
        assert_eq!(authenticator.authorize("unknown").await.unwrap(), None);
        assert_eq!(authenticator.authorize("revoked").await.unwrap(), None);
        assert!(authenticator.cache.read().await.is_empty());
        database.cleanup().await;
    }

    #[tokio::test]
    async fn configured_ttl_expires_without_a_credential_expiry() {
        let database = TestDatabase::new().await;
        let credential = database.insert_credential("secret", None).await;
        let authenticator = Authenticator::new(database.pool.clone(), 1, context());

        assert_eq!(
            authenticator
                .authorize("secret")
                .await
                .unwrap()
                .unwrap()
                .subject_ref,
            "checkout"
        );
        sqlx::query("UPDATE credentials SET subject_ref = 'changed' WHERE id = $1")
            .bind(credential)
            .execute(&database.pool)
            .await
            .unwrap();
        assert_eq!(
            authenticator
                .authorize("secret")
                .await
                .unwrap()
                .unwrap()
                .subject_ref,
            "checkout"
        );
        tokio::time::sleep(Duration::from_millis(1_100)).await;
        assert_eq!(
            authenticator
                .authorize("secret")
                .await
                .unwrap()
                .unwrap()
                .subject_ref,
            "changed"
        );
        database.cleanup().await;
    }
}
