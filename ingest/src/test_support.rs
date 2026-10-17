use std::str::FromStr;

use chrono::{DateTime, Utc};
use sha2::{Digest, Sha256};
use sqlx::postgres::{PgConnectOptions, PgPool, PgPoolOptions};
use uuid::Uuid;

pub(crate) struct TestDatabase {
    pub pool: PgPool,
    administrative_pool: PgPool,
    schema: String,
}

impl TestDatabase {
    pub async fn new() -> Self {
        let database_url = std::env::var("TEST_DATABASE_URL")
            .or_else(|_| std::env::var("DATABASE_URL"))
            .unwrap_or_else(|_| "postgres://root@127.0.0.1:5432/hone_rust_test".to_string());
        let options = PgConnectOptions::from_str(&database_url).unwrap_or_else(|_| {
            panic!("TEST_DATABASE_URL or DATABASE_URL must be a PostgreSQL URL")
        });
        let administrative_pool = PgPoolOptions::new()
            .max_connections(2)
            .connect_with(options.clone())
            .await
            .unwrap_or_else(|_| {
                panic!("Rust integration tests require their configured PostgreSQL database")
            });
        let schema = format!("test_{}", Uuid::new_v4().simple());
        sqlx::query(&format!("CREATE SCHEMA {schema}"))
            .execute(&administrative_pool)
            .await
            .unwrap();

        let connection_search_path = format!("{schema}, pg_catalog");
        let test_options = options.application_name(&schema);
        let pool = PgPoolOptions::new()
            .max_connections(10)
            .after_connect(move |connection, _metadata| {
                let search_path = connection_search_path.clone();
                Box::pin(async move {
                    sqlx::query("SELECT set_config('search_path', $1, false)")
                        .bind(search_path)
                        .execute(connection)
                        .await?;
                    Ok(())
                })
            })
            .connect_with(test_options)
            .await
            .unwrap();

        sqlx::raw_sql(
            r#"
            CREATE TABLE credentials (
                id uuid PRIMARY KEY,
                kind text NOT NULL,
                purpose text,
                subject_type text NOT NULL,
                subject_ref text NOT NULL,
                user_id text,
                secret_hash text,
                status text NOT NULL DEFAULT 'active',
                revoked_at timestamp(0),
                expires_at timestamp(0),
                last_used_at timestamp(0),
                created_at timestamp(0),
                updated_at timestamp(0)
            );
            CREATE TABLE credential_protocol_bindings (credential_id uuid PRIMARY KEY);
            CREATE TABLE offboarded_subjects (
                subject_type text NOT NULL,
                subject_ref text NOT NULL,
                user_id text NOT NULL
            );
            CREATE TABLE onboarding_tokens (
                id uuid PRIMARY KEY,
                email text,
                consumed_at timestamp(0),
                durable_credential_id uuid,
                updated_at timestamp(0)
            );
            CREATE TABLE credential_audit_events (
                id uuid PRIMARY KEY,
                event text NOT NULL,
                code_id uuid,
                credential_id uuid,
                credential_authorization_id uuid,
                superseded_by_credential_id uuid,
                provider text,
                deployment text,
                environment text,
                actor_type text,
                actor_ref text,
                recipient text,
                code_ttl_seconds integer,
                credential_expires_at timestamp(0),
                reason_code text,
                note text,
                occurred_at timestamp(0),
                created_at timestamp(0)
            );
            CREATE TABLE raw_events (
                id bigserial PRIMARY KEY,
                app varchar(255) NOT NULL,
                record_type varchar(255) NOT NULL,
                deploy varchar(255),
                occurred_at timestamptz(0) NOT NULL,
                normalized_key varchar(255) NOT NULL,
                duration_ms double precision,
                payload jsonb NOT NULL,
                actor varchar(255),
                ran_queries boolean,
                response jsonb,
                request_path text,
                request_host varchar(255),
                user_agent text,
                client_ip varchar(45),
                asn bigint,
                created_at timestamptz(0) NOT NULL DEFAULT now()
            );
            CREATE TABLE ingest_counters (
                id bigserial PRIMARY KEY,
                app varchar(255) NOT NULL,
                deploy varchar(255),
                overflow_dropped_records bigint NOT NULL DEFAULT 0,
                failed_delivery_records bigint NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            );
            CREATE UNIQUE INDEX ingest_counters_app_deploy_unique
                ON ingest_counters (app, deploy) NULLS NOT DISTINCT;
            "#,
        )
        .execute(&pool)
        .await
        .unwrap();

        Self {
            pool,
            administrative_pool,
            schema,
        }
    }

    pub async fn insert_credential(&self, secret: &str, expires_at: Option<DateTime<Utc>>) -> Uuid {
        let credential_id = Uuid::new_v4();
        let secret_hash = hex::encode(Sha256::digest(secret.as_bytes()));
        sqlx::query(
            r#"
            INSERT INTO credentials (
                id, kind, purpose, subject_type, subject_ref, user_id,
                secret_hash, status, revoked_at, expires_at, last_used_at,
                created_at, updated_at
            ) VALUES (
                $1, 'bearer', 'consumption', 'installation', 'checkout', NULL,
                $2, 'active', NULL, $3, NULL, now(), now()
            )
            "#,
        )
        .bind(credential_id)
        .bind(secret_hash)
        .bind(expires_at.map(|expires_at| expires_at.naive_utc()))
        .execute(&self.pool)
        .await
        .unwrap();

        credential_id
    }

    pub async fn observe_raw_event_inserts(&self) {
        sqlx::raw_sql(
            r#"
            CREATE TABLE raw_event_insert_observations (
                row_count bigint NOT NULL,
                transaction_id bigint NOT NULL
            );

            CREATE FUNCTION observe_raw_event_insert() RETURNS trigger AS $$
            BEGIN
                INSERT INTO raw_event_insert_observations (row_count, transaction_id)
                SELECT count(*), txid_current() FROM inserted_rows;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER observe_raw_event_insert
            AFTER INSERT ON raw_events
            REFERENCING NEW TABLE AS inserted_rows
            FOR EACH STATEMENT EXECUTE FUNCTION observe_raw_event_insert();
            "#,
        )
        .execute(&self.pool)
        .await
        .unwrap();
    }

    pub async fn wait_for_lock(&self, query_fragment: &str) {
        for _attempt in 0..200 {
            let waiting = sqlx::query_scalar::<_, bool>(
                r#"
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_stat_activity
                    WHERE application_name = $1
                      AND wait_event_type = 'Lock'
                      AND query LIKE '%' || $2 || '%'
                )
                "#,
            )
            .bind(&self.schema)
            .bind(query_fragment)
            .fetch_one(&self.administrative_pool)
            .await
            .unwrap();
            if waiting {
                return;
            }
            tokio::time::sleep(std::time::Duration::from_millis(10)).await;
        }

        panic!("expected a PostgreSQL lock wait for {query_fragment}");
    }

    pub async fn cleanup(self) {
        self.pool.close().await;
        sqlx::query(&format!("DROP SCHEMA {} CASCADE", self.schema))
            .execute(&self.administrative_pool)
            .await
            .unwrap();
        self.administrative_pool.close().await;
    }
}
