use serde_json::Value;
use sha2::{Digest, Sha256};

use crate::envelope::php_scalar_string;

const MAX_KEY_CHARACTERS: usize = 255;
const HASH_CHARACTERS: usize = 64;
const PREFIX_CHARACTERS: usize = MAX_KEY_CHARACTERS - HASH_CHARACTERS - 1;

pub fn key_for(record_type: &str, record: &Value) -> String {
    let record_type = fallback(record_type, "unknown");

    let key = match record_type.as_str() {
        "request" => php_trim(&format!(
            "{} {}",
            php_scalar_string(field(record, "method")),
            php_scalar_string(first_defined(record, &["route", "route_path", "uri"]))
        )),
        "query" => field(record, "sql")
            .filter(|value| !value.is_null())
            .map(|value| php_scalar_string(Some(value)))
            .unwrap_or_else(|| record_type.clone()),
        "queued-job" | "job-attempt" | "command" | "scheduled-task" => field(record, "name")
            .filter(|value| !value.is_null())
            .map(|value| php_scalar_string(Some(value)))
            .unwrap_or_else(|| record_type.clone()),
        "exception" => {
            let line = field(record, "line")
                .filter(|value| !value.is_null())
                .map(|value| format!(":{}", php_scalar_string(Some(value))))
                .unwrap_or_default();

            php_trim(&format!(
                "{} {}{}",
                php_scalar_string(field(record, "class")),
                php_scalar_string(field(record, "file")),
                line
            ))
        }
        "outgoing-request" => php_trim(&format!(
            "{} {}",
            php_scalar_string(field(record, "method")),
            php_scalar_string(first_defined(record, &["host", "url"]))
        )),
        "log" => field(record, "level")
            .filter(|value| !value.is_null())
            .map(|value| php_scalar_string(Some(value)))
            .unwrap_or_else(|| record_type.clone()),
        "user" => field(record, "id")
            .filter(|value| !value.is_null())
            .map(|value| php_scalar_string(Some(value)))
            .unwrap_or_else(|| record_type.clone()),
        "cache-event" => php_trim(&format!(
            "{}:{}",
            php_scalar_string(field(record, "store")),
            php_scalar_string(field(record, "type"))
        )),
        _ => record_type.clone(),
    };

    canonicalize(&fallback(&key, &record_type))
}

pub fn canonicalize(key: &str) -> String {
    let character_count = key.chars().count();

    if character_count <= MAX_KEY_CHARACTERS && !is_hashed_form(key, character_count) {
        return key.to_string();
    }

    let prefix: String = key.chars().take(PREFIX_CHARACTERS).collect();
    let digest = hex::encode(Sha256::digest(key.as_bytes()));

    format!("{prefix}#{digest}")
}

pub fn lookup_key(key: &str) -> String {
    if is_hashed_form(key, key.chars().count()) {
        key.to_string()
    } else {
        canonicalize(key)
    }
}

fn field<'a>(record: &'a Value, key: &str) -> Option<&'a Value> {
    record.as_object().and_then(|object| object.get(key))
}

fn first_defined<'a>(record: &'a Value, keys: &[&str]) -> Option<&'a Value> {
    keys.iter()
        .find_map(|key| field(record, key).filter(|value| !value.is_null()))
}

fn fallback(value: &str, fallback_value: &str) -> String {
    let value = php_trim(value);
    if !value.is_empty() {
        return value;
    }

    let fallback_value = php_trim(fallback_value);
    if fallback_value.is_empty() {
        "unknown".to_string()
    } else {
        fallback_value
    }
}

fn php_trim(value: &str) -> String {
    value
        .trim_matches([' ', '\t', '\n', '\r', '\0', '\u{000B}'])
        .to_string()
}

fn is_hashed_form(key: &str, character_count: usize) -> bool {
    if character_count != MAX_KEY_CHARACTERS {
        return false;
    }

    let mut characters = key.chars().skip(PREFIX_CHARACTERS);
    if characters.next() != Some('#') {
        return false;
    }

    let suffix: String = characters.collect();
    suffix.len() == HASH_CHARACTERS
        && suffix
            .bytes()
            .all(|character| character.is_ascii_digit() || (b'a'..=b'f').contains(&character))
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::*;

    #[test]
    fn ports_every_record_type_and_php_scalar_rules() {
        let cases = [
            (
                "request",
                json!({"method": "GET", "route_path": "/posts/{post}"}),
                "GET /posts/{post}",
            ),
            (
                "request",
                json!({"method": "POST", "route_path": ""}),
                "POST",
            ),
            ("query", json!({"sql": "select 1"}), "select 1"),
            (
                "queued-job",
                json!({"name": "SendWelcomeEmail"}),
                "SendWelcomeEmail",
            ),
            (
                "job-attempt",
                json!({"name": "SendWelcomeEmail"}),
                "SendWelcomeEmail",
            ),
            ("command", json!({"name": "reports:build"}), "reports:build"),
            (
                "scheduled-task",
                json!({"name": "backup:run"}),
                "backup:run",
            ),
            (
                "exception",
                json!({"class": "Failure", "file": "/app.php", "line": 42}),
                "Failure /app.php:42",
            ),
            (
                "outgoing-request",
                json!({"method": "GET", "host": "api.example.com"}),
                "GET api.example.com",
            ),
            ("log", json!({"level": "error"}), "error"),
            ("user", json!({"id": 42}), "42"),
            (
                "cache-event",
                json!({"store": "redis", "type": "hit"}),
                "redis:hit",
            ),
            ("custom", json!({}), "custom"),
        ];

        for (record_type, record, expected) in cases {
            assert_eq!(key_for(record_type, &record), expected);
        }

        assert_eq!(key_for("query", &json!({"sql": 1.0})), "1");
        assert_eq!(key_for("query", &json!({"sql": ["not scalar"]})), "query");
        assert_eq!(key_for("", &json!({})), "unknown");
    }

    #[test]
    fn preserves_the_bounded_namespace_and_unicode_character_counts() {
        let exact = "a".repeat(255);
        let unicode_exact = "Ω".repeat(255);
        let long_a = format!("{} one", "x".repeat(260));
        let long_b = format!("{} two", "x".repeat(260));

        assert_eq!(canonicalize(&exact), exact);
        assert_eq!(canonicalize(&unicode_exact), unicode_exact);
        assert_eq!(canonicalize(&long_a).chars().count(), 255);
        assert_ne!(canonicalize(&long_a), canonicalize(&long_b));

        let reserved = canonicalize(&long_a);
        assert_eq!(reserved.chars().count(), 255);
        assert_ne!(canonicalize(&reserved), reserved);
        assert_eq!(lookup_key(&reserved), reserved);
        assert_eq!(lookup_key(&long_a), canonicalize(&long_a));
    }
}
