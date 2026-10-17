use std::env;
use std::str::FromStr;
use std::time::Duration;

use hone_ingest::asn::AsnLookup;
use hone_ingest::auth::{AuditContext, Authenticator};
use hone_ingest::config::{credential_ttl_seconds, relevant_environment_names};
use hone_ingest::http::{AppState, router};
use hone_ingest::writer::Writer;
use sqlx::postgres::{PgConnectOptions, PgPoolOptions};
use tracing::info;
use tracing_subscriber::EnvFilter;

#[tokio::main]
async fn main() {
    let log_filter = EnvFilter::try_from_default_env()
        .unwrap_or_else(|_| EnvFilter::new("info"))
        .add_directive(
            "sqlx=off"
                .parse()
                .unwrap_or_else(|_| panic!("static log filter is invalid")),
        );
    tracing_subscriber::fmt().with_env_filter(log_filter).init();

    let relevant_names = relevant_environment_names(env::vars());
    info!(
        variables = relevant_names.join(","),
        "startup environment names"
    );

    let database_url = required_env("DATABASE_URL");
    let connect_options = PgConnectOptions::from_str(&database_url)
        .unwrap_or_else(|_| panic!("DATABASE_URL configuration is invalid"));
    let pool_size = optional_env("HONE_RUST_DB_POOL")
        .and_then(|value| value.parse::<u32>().ok())
        .filter(|value| *value > 0)
        .unwrap_or(10);
    let pool = PgPoolOptions::new()
        .max_connections(pool_size)
        .acquire_timeout(Duration::from_secs(10))
        .connect_with(connect_options)
        .await
        .unwrap_or_else(|_| panic!("database connection failed"));
    let ttl_seconds = credential_ttl_seconds(optional_env("HONE_RUST_CRED_TTL_SECONDS").as_deref());
    let audit_context = AuditContext {
        provider: optional_env("BUILT_FOR_CLOUD_PRODUCT")
            .or_else(|| optional_env("APP_NAME"))
            .or_else(|| Some("Laravel".to_string())),
        deployment: optional_env("BUILT_FOR_CLOUD_APPLICATION"),
        environment: optional_env("APP_ENV").or_else(|| Some("production".to_string())),
    };
    let asn_lookup = AsnLookup::from_path(optional_env("HONE_IPTOASN_PATH").as_deref());
    let state = AppState {
        authenticator: Authenticator::new(pool.clone(), ttl_seconds, audit_context),
        writer: Writer::new(pool, asn_lookup),
    };
    let port = optional_env("PORT").unwrap_or_else(|| "3000".to_string());
    let listener = tokio::net::TcpListener::bind(format!("[::]:{port}"))
        .await
        .unwrap_or_else(|_| panic!("server bind failed"));

    info!("hone ingest listening");
    axum::serve(listener, router(state))
        .with_graceful_shutdown(shutdown_signal())
        .await
        .unwrap_or_else(|_| panic!("server failed"));
}

fn optional_env(name: &str) -> Option<String> {
    env::var(name).ok().filter(|value| !value.is_empty())
}

fn required_env(name: &str) -> String {
    optional_env(name).unwrap_or_else(|| panic!("required environment variable is missing"))
}

async fn shutdown_signal() {
    let control_c = async {
        let _ = tokio::signal::ctrl_c().await;
    };

    #[cfg(unix)]
    let terminate = async {
        if let Ok(mut signal) =
            tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
        {
            signal.recv().await;
        }
    };

    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();

    tokio::select! {
        () = control_c => {},
        () = terminate => {},
    }
}
