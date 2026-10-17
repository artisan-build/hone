use sqlx::Error;

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct SafeDatabaseError {
    pub category: &'static str,
    pub sqlstate: Option<String>,
}

impl SafeDatabaseError {
    pub fn from_sqlx(error: &Error) -> Self {
        let category = match error {
            Error::Database(_) => "database",
            Error::PoolTimedOut => "pool_timeout",
            Error::PoolClosed => "pool_closed",
            Error::Io(_) | Error::Tls(_) => "connection",
            Error::Protocol(_) => "protocol",
            Error::Configuration(_) => "configuration",
            _ => "database_client",
        };

        let sqlstate = match error {
            Error::Database(database_error) => database_error.code().map(|code| code.into_owned()),
            _ => None,
        };

        Self { category, sqlstate }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn safe_error_never_formats_the_underlying_message() {
        let sensitive = "telemetry-secret-value";
        let error = Error::Protocol(format!("driver echoed {sensitive}"));
        let safe = SafeDatabaseError::from_sqlx(&error);

        assert_eq!(safe.category, "protocol");
        assert!(!format!("{safe:?}").contains(sensitive));
    }
}
