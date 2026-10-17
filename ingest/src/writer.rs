use std::sync::Arc;

use chrono::Timelike;
use sqlx::QueryBuilder;
use sqlx::postgres::PgPool;
use tracing::warn;

use crate::asn::AsnLookup;
use crate::envelope::Envelope;
use crate::error::SafeDatabaseError;
use crate::rows::{RawEventRow, build_row, record_type};

const INSERT_CHUNK_ROWS: usize = 500;
const KNOWN_RECORD_TYPES: &[&str] = &[
    "request",
    "http-request",
    "query",
    "queued-job",
    "job-attempt",
    "command",
    "artisan-command",
    "scheduled-task",
    "scheduled",
    "exception",
    "outgoing-request",
    "log",
    "user",
    "cache-event",
    "unknown",
];

#[derive(Clone)]
pub struct Writer {
    pool: PgPool,
    asn_lookup: Arc<AsnLookup>,
}

impl Writer {
    pub fn new(pool: PgPool, asn_lookup: AsnLookup) -> Self {
        Self {
            pool,
            asn_lookup: Arc::new(asn_lookup),
        }
    }

    pub async fn write(&self, app: &str, envelope: &Envelope) -> Result<(), SafeDatabaseError> {
        self.persist_loss_counters(app, envelope).await?;

        let mut rows = Vec::with_capacity(envelope.records.len());
        for record in &envelope.records {
            match build_row(
                app,
                envelope.deploy.as_deref(),
                &envelope.sent_at,
                record,
                &self.asn_lookup,
            ) {
                Ok(Some(row)) => rows.push(row),
                Ok(None) => {}
                Err(error) => log_row_build_failure(&record_type(record), error.category()),
            }
        }

        for chunk in rows.chunks(INSERT_CHUNK_ROWS) {
            match self.insert_rows(chunk).await {
                Ok(()) => {}
                Err(error) if error.is_row_rejection() => {
                    for row in chunk {
                        if let Err(error) = self.insert_rows(std::slice::from_ref(row)).await {
                            if !error.is_row_rejection() {
                                return Err(error);
                            }
                            log_row_rejection(&row.record_type, &error);
                        }
                    }
                }
                Err(error) => return Err(error),
            }
        }

        Ok(())
    }

    async fn insert_rows(&self, rows: &[RawEventRow]) -> Result<(), SafeDatabaseError> {
        if rows.is_empty() {
            return Ok(());
        }

        let mut query = QueryBuilder::new(
            "INSERT INTO raw_events (app, record_type, deploy, occurred_at, normalized_key, \
             duration_ms, payload, actor, ran_queries, response, request_path, request_host, \
             user_agent, client_ip, asn) ",
        );
        query.push_values(rows, |mut values, row| {
            values
                .push_bind(&row.app)
                .push_bind(&row.record_type)
                .push_bind(&row.deploy)
                .push_bind(
                    row.occurred_at
                        .with_nanosecond(0)
                        .unwrap_or(row.occurred_at),
                )
                .push_bind(&row.normalized_key)
                .push_bind(row.duration_ms)
                .push_bind(sqlx::types::Json(&row.payload))
                .push_bind(&row.actor)
                .push_bind(row.ran_queries)
                .push_bind(row.response.as_ref().map(sqlx::types::Json))
                .push_bind(&row.request_path)
                .push_bind(&row.request_host)
                .push_bind(&row.user_agent)
                .push_bind(&row.client_ip)
                .push_bind(row.asn);
        });

        let mut transaction = self
            .pool
            .begin()
            .await
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
        query
            .build()
            .execute(&mut *transaction)
            .await
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;
        transaction
            .commit()
            .await
            .map_err(|error| SafeDatabaseError::from_sqlx(&error))
    }

    async fn persist_loss_counters(
        &self,
        app: &str,
        envelope: &Envelope,
    ) -> Result<(), SafeDatabaseError> {
        let overflow_dropped_records = envelope.overflow_dropped_records.max(0);
        let failed_delivery_records = envelope.failed_delivery_records.max(0);
        if overflow_dropped_records == 0 && failed_delivery_records == 0 {
            return Ok(());
        }

        sqlx::query(
            r#"
            INSERT INTO ingest_counters (
                app, deploy, overflow_dropped_records, failed_delivery_records,
                created_at, updated_at
            ) VALUES ($1, $2, $3, $4, now(), now())
            ON CONFLICT (app, deploy) DO UPDATE SET
                overflow_dropped_records = ingest_counters.overflow_dropped_records + excluded.overflow_dropped_records,
                failed_delivery_records = ingest_counters.failed_delivery_records + excluded.failed_delivery_records,
                updated_at = excluded.updated_at
            "#,
        )
        .bind(app)
        .bind(
            envelope
                .deploy
                .as_deref()
                .filter(|deploy| !php_blank_string(deploy)),
        )
        .bind(overflow_dropped_records)
        .bind(failed_delivery_records)
        .execute(&self.pool)
        .await
        .map_err(|error| SafeDatabaseError::from_sqlx(&error))?;

        Ok(())
    }
}

fn log_row_rejection(record_type: &str, error: &SafeDatabaseError) {
    let safe_record_type = if KNOWN_RECORD_TYPES.contains(&record_type) {
        record_type
    } else {
        "custom"
    };

    warn!(
        record_type = safe_record_type,
        cause = "telemetry_database_rejected_row",
        error_category = error.category,
        sqlstate = error.sqlstate.as_deref().unwrap_or("none"),
        "failed to persist Hone telemetry record"
    );
}

fn log_row_build_failure(record_type: &str, error_category: &str) {
    let safe_record_type = if KNOWN_RECORD_TYPES.contains(&record_type) {
        record_type
    } else {
        "custom"
    };

    warn!(
        record_type = safe_record_type,
        cause = "building_telemetry_row_failed",
        error_category,
        sqlstate = "none",
        "failed to persist Hone telemetry record"
    );
}

fn php_blank_string(value: &str) -> bool {
    value
        .trim_matches([' ', '\t', '\n', '\r', '\0', '\u{000B}'])
        .is_empty()
}

#[cfg(test)]
mod tests {
    use chrono::Utc;
    use serde_json::json;
    use sqlx::Row;
    use tracing_test::traced_test;

    use super::*;
    use crate::test_support::TestDatabase;

    fn envelope(records: Vec<serde_json::Value>) -> Envelope {
        Envelope {
            app: "forged".to_string(),
            deploy: Some("abc123".to_string()),
            sent_at: "2026-06-09T12:00:00Z".to_string(),
            records,
            overflow_dropped_records: 0,
            failed_delivery_records: 0,
        }
    }

    #[tokio::test]
    async fn persists_1201_rows_as_three_bounded_chunks() {
        let database = TestDatabase::new().await;
        database.observe_raw_event_inserts().await;
        let writer = Writer::new(database.pool.clone(), AsnLookup::default());
        let records = (1..=1201)
            .map(|index| json!({"t": "query", "sql": format!("select {index}")}))
            .collect();

        writer.write("checkout", &envelope(records)).await.unwrap();

        let observations = sqlx::query(
            "SELECT row_count, transaction_id FROM raw_event_insert_observations ORDER BY ctid",
        )
        .fetch_all(&database.pool)
        .await
        .unwrap();
        assert_eq!(
            observations
                .iter()
                .map(|row| row.get::<i64, _>("row_count"))
                .collect::<Vec<_>>(),
            vec![500, 500, 201]
        );
        assert_eq!(
            observations
                .iter()
                .map(|row| row.get::<i64, _>("transaction_id"))
                .collect::<std::collections::HashSet<_>>()
                .len(),
            3
        );
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM raw_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            1201
        );
        database.cleanup().await;
    }

    #[tokio::test]
    #[traced_test]
    async fn rejected_rows_do_not_lose_siblings_or_leak_bound_values() {
        let database = TestDatabase::new().await;
        sqlx::query("ALTER TABLE raw_events ADD CONSTRAINT reject_query_rows CHECK (record_type <> 'query')")
            .execute(&database.pool)
            .await
            .unwrap();
        let writer = Writer::new(database.pool.clone(), AsnLookup::default());
        let sensitive = "select sensitive_customer_value from users";

        writer
            .write(
                "checkout",
                &envelope(vec![
                    json!({"t": "request", "method": "GET", "route": "/one"}),
                    json!({"t": "query", "sql": sensitive}),
                    json!({"t": "request", "method": "GET", "route": "/two"}),
                ]),
            )
            .await
            .unwrap();

        let keys =
            sqlx::query_scalar::<_, String>("SELECT normalized_key FROM raw_events ORDER BY id")
                .fetch_all(&database.pool)
                .await
                .unwrap();
        assert_eq!(keys, vec!["GET /one", "GET /two"]);
        assert!(logs_contain("record_type=\"query\""));
        assert!(logs_contain("sqlstate=\"23514\""));
        assert!(!logs_contain(sensitive));
        assert!(!logs_contain("reject_query_rows"));
        database.cleanup().await;
    }

    #[tokio::test]
    #[traced_test]
    async fn row_build_failures_are_dropped_and_safe_logged() {
        let database = TestDatabase::new().await;
        let writer = Writer::new(database.pool.clone(), AsnLookup::default());
        let sensitive = "sensitive-build-value";

        writer
            .write(
                "checkout",
                &envelope(vec![json!({
                    "t": "query",
                    "sql": sensitive,
                    "timestamp": 10_000_000_000_000_000_i64
                })]),
            )
            .await
            .unwrap();

        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM raw_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            0
        );
        assert!(logs_contain("error_category=\"timestamp_out_of_range\""));
        assert!(!logs_contain(sensitive));
        database.cleanup().await;
    }

    #[tokio::test]
    async fn loss_counters_add_with_null_deploy_and_skip_zeroes() {
        let database = TestDatabase::new().await;
        let writer = Writer::new(database.pool.clone(), AsnLookup::default());
        let mut batch = envelope(Vec::new());
        batch.deploy = Some(" ".to_string());
        batch.overflow_dropped_records = 2;
        batch.failed_delivery_records = 3;
        writer.write("checkout", &batch).await.unwrap();
        batch.overflow_dropped_records = 5;
        batch.failed_delivery_records = -9;
        writer.write("checkout", &batch).await.unwrap();

        let counter = sqlx::query("SELECT * FROM ingest_counters")
            .fetch_one(&database.pool)
            .await
            .unwrap();
        assert_eq!(counter.get::<i64, _>("overflow_dropped_records"), 7);
        assert_eq!(counter.get::<i64, _>("failed_delivery_records"), 3);
        assert_eq!(counter.get::<Option<String>, _>("deploy"), None);

        batch.overflow_dropped_records = 0;
        batch.failed_delivery_records = 0;
        writer.write("other", &batch).await.unwrap();
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM ingest_counters")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            1
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn writes_authenticated_app_list_records_and_configured_asn() {
        let database = TestDatabase::new().await;
        let fixture = concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../packages/hone-server/tests/Fixtures/ip2asn.tsv"
        );
        let writer = Writer::new(database.pool.clone(), AsnLookup::from_path(Some(fixture)));
        let mut batch = envelope(vec![
            json!(["list record"]),
            json!({"t": "request", "ip": "1.1.1.1", "user": ""}),
            json!(null),
        ]);
        batch.sent_at = Utc::now().to_rfc3339();

        writer.write("authenticated-app", &batch).await.unwrap();

        let rows = sqlx::query("SELECT app, record_type, payload, asn FROM raw_events ORDER BY id")
            .fetch_all(&database.pool)
            .await
            .unwrap();
        assert_eq!(rows.len(), 2);
        assert_eq!(rows[0].get::<String, _>("app"), "authenticated-app");
        assert_eq!(rows[0].get::<String, _>("record_type"), "unknown");
        assert_eq!(rows[1].get::<Option<i64>, _>("asn"), Some(13335));
        database.cleanup().await;
    }

    #[test]
    #[traced_test]
    fn custom_record_types_and_errors_are_reduced_to_safe_fields() {
        let error = SafeDatabaseError {
            category: "database",
            sqlstate: Some("23514".to_string()),
        };
        log_row_rejection("customer-secret-type", &error);
        assert!(logs_contain("record_type=\"custom\""));
        assert!(logs_contain("sqlstate=\"23514\""));
        assert!(!logs_contain("customer-secret-type"));
    }
}
