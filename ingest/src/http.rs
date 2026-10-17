use std::sync::Arc;
use std::time::Instant;

use axum::Router;
use axum::body::to_bytes;
use axum::extract::{Request, State};
use axum::http::StatusCode;
use axum::response::{IntoResponse, Json, Response};
use axum::routing::{get, post};
use serde_json::{Value, json};
use tracing::{info, warn};

use crate::auth::Authenticator;
use crate::envelope::{self, EnvelopeError};
use crate::writer::Writer;

#[derive(Clone)]
pub struct AppState {
    pub authenticator: Authenticator,
    pub writer: Writer,
}

pub fn router(state: AppState) -> Router {
    Router::new()
        .route("/ingest", post(ingest))
        .route("/up", get(up))
        .with_state(Arc::new(state))
}

async fn up() -> StatusCode {
    StatusCode::OK
}

async fn ingest(State(state): State<Arc<AppState>>, request: Request) -> Response {
    let started = Instant::now();
    let (parts, body) = request.into_parts();
    let bearer = parts
        .headers
        .get("authorization")
        .and_then(|header| header.to_str().ok())
        .and_then(extract_bearer)
        .unwrap_or_default();

    let authorized = match state.authenticator.authorize(bearer).await {
        Ok(Some(authorized)) => authorized,
        Ok(None) => return json_message(StatusCode::UNAUTHORIZED, "Unauthorized."),
        Err(error) => {
            warn!(
                operation = "credential_resolution",
                error_category = error.category,
                sqlstate = error.sqlstate.as_deref().unwrap_or("none"),
                "ingest operation failed"
            );
            return server_error();
        }
    };

    let body = match to_bytes(body, usize::MAX).await {
        Ok(body) => body,
        Err(_) => return server_error(),
    };

    let data: Value = match serde_json::from_slice(&body) {
        Ok(value) => value,
        Err(error) => {
            return json_message(
                StatusCode::UNPROCESSABLE_ENTITY,
                &format!("Envelope is not valid JSON: {error}"),
            );
        }
    };

    if !envelope::accepts_root(&data) {
        return json_message(
            StatusCode::UNPROCESSABLE_ENTITY,
            "Envelope JSON must decode to an object.",
        );
    }

    let version = match envelope::version_from(&data) {
        Ok(version) => version,
        Err(EnvelopeError(message)) => {
            return json_message(StatusCode::UNPROCESSABLE_ENTITY, &message);
        }
    };
    if version > envelope::VERSION {
        return json_message(
            StatusCode::UNPROCESSABLE_ENTITY,
            &format!(
                "Envelope v{version} is newer than this Hone server (max v{}). Upgrade your Hone app.",
                envelope::VERSION
            ),
        );
    }

    let envelope = match envelope::from_value(&data) {
        Ok(envelope) => envelope,
        Err(EnvelopeError(message)) => {
            return json_message(StatusCode::UNPROCESSABLE_ENTITY, &message);
        }
    };

    if let Err(error) = state.writer.write(&authorized.subject_ref, &envelope).await {
        warn!(
            operation = "batch_persistence",
            error_category = error.category,
            sqlstate = error.sqlstate.as_deref().unwrap_or("none"),
            "ingest operation failed"
        );
        return server_error();
    }

    info!(
        records = envelope.records.len(),
        duration_ms = started.elapsed().as_millis() as u64,
        "ingest accepted"
    );

    json_message(StatusCode::ACCEPTED, "Accepted.")
}

pub fn extract_bearer(header: &str) -> Option<&str> {
    const PREFIX: &[u8] = b"Bearer ";
    let bytes = header.as_bytes();
    let position = bytes
        .windows(PREFIX.len())
        .enumerate()
        .filter(|(_, candidate)| {
            candidate
                .iter()
                .zip(PREFIX)
                .all(|(left, right)| left.eq_ignore_ascii_case(right))
        })
        .map(|(position, _)| position)
        .next_back()?;
    let bearer = &header[position + PREFIX.len()..];

    Some(bearer.split(',').next().unwrap_or(bearer))
}

fn json_message(status: StatusCode, message: &str) -> Response {
    (status, Json(json!({"message": message}))).into_response()
}

fn server_error() -> Response {
    json_message(StatusCode::INTERNAL_SERVER_ERROR, "Server Error")
}

#[cfg(test)]
mod tests {
    use axum::body::{Body, to_bytes};
    use axum::http::Request;
    use serde_json::json;
    use sqlx::Row;
    use tower::ServiceExt;

    use super::*;
    use crate::asn::AsnLookup;
    use crate::auth::AuditContext;
    use crate::test_support::TestDatabase;

    async fn response_body(response: Response) -> String {
        let bytes = to_bytes(response.into_body(), usize::MAX).await.unwrap();
        String::from_utf8(bytes.to_vec()).unwrap()
    }

    fn state(database: &TestDatabase, ttl: u64) -> AppState {
        AppState {
            authenticator: Authenticator::new(database.pool.clone(), ttl, AuditContext::default()),
            writer: Writer::new(database.pool.clone(), AsnLookup::default()),
        }
    }

    fn request(header: Option<&str>, body: &str) -> Request<Body> {
        let mut request = Request::builder()
            .method("POST")
            .uri("/ingest")
            .header("content-type", "application/json");
        if let Some(header) = header {
            request = request.header("authorization", header);
        }
        request.body(Body::from(body.to_string())).unwrap()
    }

    #[test]
    fn bearer_extraction_matches_laravel_noncanonical_forms() {
        assert_eq!(
            extract_bearer("Basic x, bEaReR wrong, BEARER secret,ignored"),
            Some("secret")
        );
        assert_eq!(extract_bearer("prefixBearer secret"), Some("secret"));
        assert_eq!(extract_bearer("Bearer  secret"), Some(" secret"));
        assert_eq!(extract_bearer("bearer secret "), Some("secret "));
        assert_eq!(extract_bearer("Basic secret"), None);
    }

    #[tokio::test]
    async fn authenticates_before_parsing_and_returns_exact_unauthorized_json() {
        let database = TestDatabase::new().await;
        let app = router(state(&database, 0));

        for header in [None, Some("Bearer unknown")] {
            let response = app
                .clone()
                .oneshot(request(header, "{not json"))
                .await
                .unwrap();
            assert_eq!(response.status(), StatusCode::UNAUTHORIZED);
            assert_eq!(
                response_body(response).await,
                r#"{"message":"Unauthorized."}"#
            );
        }
        database.cleanup().await;
    }

    #[tokio::test]
    async fn authenticates_before_collecting_large_bodies_and_accepts_large_envelopes() {
        let database = TestDatabase::new().await;
        database.insert_credential("secret", None).await;
        let app = router(state(&database, 60));
        let padding = "x".repeat(2 * 1024 * 1024 + 1);
        let large_body = json!({
            "envelope_version": 1,
            "app": "forged",
            "sent_at": "2026-06-09T12:00:00Z",
            "records": [{"t": "log", "level": "info", "padding": padding}]
        })
        .to_string();

        let unauthorized = app
            .clone()
            .oneshot(request(None, &large_body))
            .await
            .unwrap();
        assert_eq!(unauthorized.status(), StatusCode::UNAUTHORIZED);
        assert_eq!(
            response_body(unauthorized).await,
            r#"{"message":"Unauthorized."}"#
        );

        let accepted = app
            .oneshot(request(Some("Bearer secret"), &large_body))
            .await
            .unwrap();
        assert_eq!(accepted.status(), StatusCode::ACCEPTED);
        assert_eq!(response_body(accepted).await, r#"{"message":"Accepted."}"#);
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM raw_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            1
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn returns_every_reachable_validation_message() {
        let database = TestDatabase::new().await;
        database.insert_credential("secret", None).await;
        let app = router(state(&database, 60));
        let cases = [
            ("{not json", "Envelope is not valid JSON:"),
            ("null", "Envelope JSON must decode to an object."),
            ("42", "Envelope JSON must decode to an object."),
            ("[]", "Envelope is missing a numeric \"envelope_version\"."),
            (
                r#"{"app":"x"}"#,
                "Envelope is missing a numeric \"envelope_version\".",
            ),
            (
                r#"{"envelope_version":"no","app":"x"}"#,
                "Envelope is missing a numeric \"envelope_version\".",
            ),
            (
                r#"{"envelope_version":"\u00a01","app":"x"}"#,
                "Envelope is missing a numeric \"envelope_version\".",
            ),
            (
                r#"{"envelope_version":2,"app":"x"}"#,
                "Envelope v2 is newer than this Hone server (max v1). Upgrade your Hone app.",
            ),
            (
                r#"{"envelope_version":1,"app":""}"#,
                "Envelope is missing a non-empty string \"app\".",
            ),
            (
                r#"{"envelope_version":1,"app":42}"#,
                "Envelope is missing a non-empty string \"app\".",
            ),
            (
                r#"{"envelope_version":1,"app":"x","records":"no"}"#,
                "Envelope \"records\" must be an array.",
            ),
        ];

        for (body, expected_message) in cases {
            let response = app
                .clone()
                .oneshot(request(Some("Bearer secret"), body))
                .await
                .unwrap();
            assert_eq!(
                response.status(),
                StatusCode::UNPROCESSABLE_ENTITY,
                "{body}"
            );
            let body = response_body(response).await;
            if expected_message.ends_with(':') {
                assert!(body.starts_with(&format!(r#"{{"message":"{expected_message}"#)));
            } else {
                assert_eq!(body, json!({"message": expected_message}).to_string());
            }
        }
        database.cleanup().await;
    }

    #[tokio::test]
    async fn accepts_noncanonical_bearer_and_commits_before_exact_accepted_response() {
        let database = TestDatabase::new().await;
        database.insert_credential("secret", None).await;
        let app = router(state(&database, 0));
        let response = app
            .oneshot(request(
                Some("Basic ignored, bEaReR wrong, BEARER secret, ignored"),
                &json!({
                    "envelope_version": "1.9",
                    "app": "forged",
                    "deploy": "abc123",
                    "sent_at": "2026-06-09T12:00:00Z",
                    "records": {
                        "object": {"t": "query", "sql": "select 1"},
                        "list": ["inner list"]
                    },
                    "unknown": true
                })
                .to_string(),
            ))
            .await
            .unwrap();

        assert_eq!(response.status(), StatusCode::ACCEPTED);
        assert_eq!(response_body(response).await, r#"{"message":"Accepted."}"#);
        let rows = sqlx::query("SELECT app, record_type FROM raw_events ORDER BY id")
            .fetch_all(&database.pool)
            .await
            .unwrap();
        assert_eq!(rows.len(), 2);
        assert!(
            rows.iter()
                .all(|row| row.get::<String, _>("app") == "checkout")
        );
        assert_eq!(rows[0].get::<String, _>("record_type"), "query");
        assert_eq!(rows[1].get::<String, _>("record_type"), "unknown");
        database.cleanup().await;
    }

    #[tokio::test]
    async fn does_not_trim_the_extracted_secret() {
        let database = TestDatabase::new().await;
        database.insert_credential("secret", None).await;
        let app = router(state(&database, 0));
        let body = json!({"envelope_version": 1, "app": "x", "records": []}).to_string();

        for header in ["Bearer  secret", "Bearer secret "] {
            let response = app
                .clone()
                .oneshot(request(Some(header), &body))
                .await
                .unwrap();
            assert_eq!(response.status(), StatusCode::UNAUTHORIZED);
        }
        database.cleanup().await;
    }

    #[tokio::test]
    async fn counter_failures_return_a_generic_500_without_accepting_the_batch() {
        let database = TestDatabase::new().await;
        database.insert_credential("secret", None).await;
        sqlx::query("DROP TABLE ingest_counters")
            .execute(&database.pool)
            .await
            .unwrap();
        let app = router(state(&database, 0));
        let response = app
            .oneshot(request(
                Some("Bearer secret"),
                &json!({
                    "envelope_version": 1,
                    "app": "x",
                    "records": [{"t": "query", "sql": "sensitive payload"}],
                    "losses": {"overflow_dropped_records": 1}
                })
                .to_string(),
            ))
            .await
            .unwrap();

        assert_eq!(response.status(), StatusCode::INTERNAL_SERVER_ERROR);
        assert_eq!(
            response_body(response).await,
            r#"{"message":"Server Error"}"#
        );
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT count(*) FROM raw_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            0
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn an_individually_rejected_raw_row_does_not_change_the_accepted_response() {
        let database = TestDatabase::new().await;
        database.insert_credential("secret", None).await;
        sqlx::query(
            "ALTER TABLE raw_events ADD CONSTRAINT reject_query_rows CHECK (record_type <> 'query')",
        )
        .execute(&database.pool)
        .await
        .unwrap();
        let app = router(state(&database, 0));
        let response = app
            .oneshot(request(
                Some("Bearer secret"),
                &json!({
                    "envelope_version": 1,
                    "app": "x",
                    "sent_at": "2026-06-09T12:00:00Z",
                    "records": [
                        {"t": "query", "sql": "rejected"},
                        {"t": "request", "method": "GET", "route": "/kept"}
                    ]
                })
                .to_string(),
            ))
            .await
            .unwrap();

        assert_eq!(response.status(), StatusCode::ACCEPTED);
        assert_eq!(response_body(response).await, r#"{"message":"Accepted."}"#);
        assert_eq!(
            sqlx::query_scalar::<_, String>("SELECT normalized_key FROM raw_events")
                .fetch_one(&database.pool)
                .await
                .unwrap(),
            "GET /kept"
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn pool_failures_after_a_cache_hit_return_a_generic_500() {
        let database = TestDatabase::new().await;
        database.insert_credential("secret", None).await;
        let app = router(state(&database, 60));
        let warmup = json!({"envelope_version": 1, "app": "x", "records": []}).to_string();
        assert_eq!(
            app.clone()
                .oneshot(request(Some("Bearer secret"), &warmup))
                .await
                .unwrap()
                .status(),
            StatusCode::ACCEPTED
        );
        database.pool.close().await;
        let response = app
            .oneshot(request(
                Some("Bearer secret"),
                &json!({
                    "envelope_version": 1,
                    "app": "x",
                    "sent_at": "2026-06-09T12:00:00Z",
                    "records": [{"t": "query", "sql": "select 1"}]
                })
                .to_string(),
            ))
            .await
            .unwrap();

        assert_eq!(response.status(), StatusCode::INTERNAL_SERVER_ERROR);
        assert_eq!(
            response_body(response).await,
            r#"{"message":"Server Error"}"#
        );
        database.cleanup().await;
    }

    #[tokio::test]
    async fn up_is_public() {
        let database = TestDatabase::new().await;
        let response = router(state(&database, 0))
            .oneshot(Request::builder().uri("/up").body(Body::empty()).unwrap())
            .await
            .unwrap();
        assert_eq!(response.status(), StatusCode::OK);
        database.cleanup().await;
    }
}
