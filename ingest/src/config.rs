pub const DEFAULT_CREDENTIAL_TTL_SECONDS: u64 = 60;

pub fn credential_ttl_seconds(value: Option<&str>) -> u64 {
    value
        .and_then(|value| value.parse::<u64>().ok())
        .unwrap_or(DEFAULT_CREDENTIAL_TTL_SECONDS)
}

pub fn relevant_environment_names<I>(variables: I) -> Vec<String>
where
    I: IntoIterator<Item = (String, String)>,
{
    let mut names = variables
        .into_iter()
        .map(|(name, _value)| name)
        .filter(|name| {
            name == "DATABASE_URL"
                || name == "PORT"
                || name == "APP_ENV"
                || name == "APP_NAME"
                || name.starts_with("HONE_")
                || name.starts_with("BUILT_FOR_CLOUD_")
        })
        .collect::<Vec<_>>();
    names.sort();
    names
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn credential_ttl_has_default_override_and_disabled_forms() {
        assert_eq!(credential_ttl_seconds(None), 60);
        assert_eq!(credential_ttl_seconds(Some("17")), 17);
        assert_eq!(credential_ttl_seconds(Some("0")), 0);
        assert_eq!(credential_ttl_seconds(Some("invalid")), 60);
    }

    #[test]
    fn startup_inventory_contains_names_and_never_values() {
        let names = relevant_environment_names([
            ("DATABASE_URL".to_string(), "postgres://secret".to_string()),
            ("HONE_RUST_CRED_TTL_SECONDS".to_string(), "60".to_string()),
            ("UNRELATED".to_string(), "customer-data".to_string()),
        ]);
        let output = names.join(",");

        assert_eq!(output, "DATABASE_URL,HONE_RUST_CRED_TTL_SECONDS");
        assert!(!output.contains("postgres://"));
        assert!(!output.contains("customer-data"));
    }
}
