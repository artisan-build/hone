use std::net::IpAddr;

use chrono::{DateTime, NaiveDate, NaiveDateTime, TimeZone, Utc};
use serde::Serialize;
use serde_json::Value;

use crate::asn::{AsnLookup, is_public_ip};
use crate::envelope::php_scalar_string;
use crate::normalizer;

#[derive(Clone, Debug, Serialize)]
pub struct RawEventRow {
    pub app: String,
    pub record_type: String,
    pub deploy: Option<String>,
    pub occurred_at: DateTime<Utc>,
    pub normalized_key: String,
    pub duration_ms: Option<f64>,
    pub payload: Value,
    pub actor: Option<String>,
    pub ran_queries: Option<bool>,
    pub response: Option<Value>,
    pub request_path: Option<String>,
    pub request_host: Option<String>,
    pub user_agent: Option<String>,
    pub client_ip: Option<String>,
    pub asn: Option<i64>,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum RowBuildError {
    InvalidRequestHostEncoding,
    TimestampOutOfRange,
}

impl RowBuildError {
    pub fn category(self) -> &'static str {
        match self {
            Self::InvalidRequestHostEncoding => "invalid_request_host_encoding",
            Self::TimestampOutOfRange => "timestamp_out_of_range",
        }
    }
}

pub fn record_type(record: &Value) -> String {
    let value = record
        .as_object()
        .and_then(|object| object.get("t"))
        .filter(|value| {
            value.is_null() || value.is_boolean() || value.is_number() || value.is_string()
        });
    let value = php_trim(&php_scalar_string(value));

    if value.is_empty() {
        "unknown".to_string()
    } else {
        value
    }
}

pub fn build_row(
    app: &str,
    deploy: Option<&str>,
    sent_at: &str,
    record: &Value,
    asn_lookup: &AsnLookup,
) -> Result<Option<RawEventRow>, RowBuildError> {
    if !record.is_object() && !record.is_array() {
        return Ok(None);
    }

    let record_type = record_type(record);
    let enrichment = enrichment(&record_type, record, asn_lookup)?;
    let occurred_at = occurred_at(record, sent_at)?;
    let response = enrichment.response.as_ref().map(php_json_value);

    Ok(Some(RawEventRow {
        app: app.to_string(),
        record_type: record_type.clone(),
        deploy: deploy
            .filter(|value| !php_blank_string(value))
            .map(str::to_string),
        occurred_at,
        normalized_key: normalizer::key_for(&record_type, record),
        duration_ms: duration_ms(record),
        payload: php_json_value(record),
        actor: enrichment.actor,
        ran_queries: enrichment.ran_queries,
        response,
        request_path: enrichment.request_path,
        request_host: enrichment.request_host,
        user_agent: enrichment.user_agent,
        client_ip: enrichment.client_ip.clone(),
        asn: asn_lookup.lookup(enrichment.client_ip.as_deref()),
    }))
}

fn php_json_value(value: &Value) -> Value {
    match value {
        Value::Number(number)
            if number
                .as_u64()
                .is_some_and(|number| number > i64::MAX as u64) =>
        {
            number
                .as_f64()
                .and_then(serde_json::Number::from_f64)
                .map(Value::Number)
                .unwrap_or_else(|| value.clone())
        }
        Value::Number(number) if number.is_f64() => {
            let Some(number) = number.as_f64() else {
                return value.clone();
            };
            if number.fract() == 0.0 && number > i64::MIN as f64 && number < i64::MAX as f64 {
                Value::from(number as i64)
            } else {
                value.clone()
            }
        }
        Value::Array(values) => Value::Array(values.iter().map(php_json_value).collect()),
        Value::Object(values) => {
            let values = values
                .iter()
                .map(|(key, value)| (key.clone(), php_json_value(value)))
                .collect::<Vec<_>>();
            if values
                .iter()
                .enumerate()
                .all(|(index, (key, _value))| key == &index.to_string())
            {
                Value::Array(values.into_iter().map(|(_key, value)| value).collect())
            } else {
                Value::Object(values.into_iter().collect())
            }
        }
        _ => value.clone(),
    }
}

fn field<'a>(record: &'a Value, key: &str) -> Option<&'a Value> {
    record.as_object().and_then(|object| object.get(key))
}

fn duration_ms(record: &Value) -> Option<f64> {
    field(record, "duration")
        .and_then(Value::as_f64)
        .map(|duration| duration / 1000.0)
        .or_else(|| field(record, "duration_ms").and_then(Value::as_f64))
}

fn occurred_at(record: &Value, sent_at: &str) -> Result<DateTime<Utc>, RowBuildError> {
    for key in ["timestamp", "ts", "occurred_at", "time"] {
        if let Some(value) = field(record, key)
            && let Some(timestamp) = parse_timestamp(value)?
        {
            return Ok(timestamp);
        }
    }

    Ok(parse_timestamp(&Value::String(sent_at.to_string()))?.unwrap_or_else(Utc::now))
}

fn parse_timestamp(value: &Value) -> Result<Option<DateTime<Utc>>, RowBuildError> {
    let parsed = match value {
        Value::Number(number) => match number.as_f64() {
            Some(number) => timestamp_from_number(number)?,
            None => None,
        },
        Value::String(value) if !value.is_empty() => {
            if let Ok(number) = value.trim().parse::<f64>() {
                return timestamp_from_number(number);
            }

            parse_date_string(value)
        }
        _ => None,
    };

    Ok(parsed)
}

fn timestamp_from_number(timestamp: f64) -> Result<Option<DateTime<Utc>>, RowBuildError> {
    if !timestamp.is_finite() {
        return Ok(None);
    }

    let seconds = if timestamp > 9_999_999_999.0 {
        timestamp / 1000.0
    } else {
        timestamp
    };
    if seconds < i64::MIN as f64 || seconds > i64::MAX as f64 {
        return Ok(None);
    }
    let mut whole_seconds = seconds.floor() as i64;
    let mut nanoseconds = ((seconds - seconds.floor()) * 1_000_000_000.0).round() as u32;
    if nanoseconds == 1_000_000_000 {
        whole_seconds = whole_seconds.saturating_add(1);
        nanoseconds = 0;
    }

    Utc.timestamp_opt(whole_seconds, nanoseconds)
        .single()
        .map(Some)
        .ok_or(RowBuildError::TimestampOutOfRange)
}

fn parse_date_string(value: &str) -> Option<DateTime<Utc>> {
    let value = php_trim(value);
    if let Ok(timestamp) = DateTime::parse_from_rfc3339(&value) {
        return Some(timestamp.with_timezone(&Utc));
    }

    for format in [
        "%Y-%m-%d %H:%M:%S%.f%:z",
        "%Y-%m-%dT%H:%M:%S%.f%:z",
        "%Y-%m-%d %H:%M:%S%.f",
        "%Y-%m-%dT%H:%M:%S%.f",
    ] {
        if let Ok(timestamp) = DateTime::parse_from_str(&value, format) {
            return Some(timestamp.with_timezone(&Utc));
        }
        if let Ok(timestamp) = NaiveDateTime::parse_from_str(&value, format) {
            return Some(Utc.from_utc_datetime(&timestamp));
        }
    }

    NaiveDate::parse_from_str(&value, "%Y-%m-%d")
        .ok()
        .and_then(|date| date.and_hms_opt(0, 0, 0))
        .map(|timestamp| Utc.from_utc_datetime(&timestamp))
        .or_else(|| dateparser::parse_with_timezone(&value, &Utc).ok())
}

#[derive(Default)]
struct Enrichment {
    actor: Option<String>,
    ran_queries: Option<bool>,
    response: Option<Value>,
    request_path: Option<String>,
    request_host: Option<String>,
    user_agent: Option<String>,
    client_ip: Option<String>,
}

fn enrichment(
    record_type: &str,
    record: &Value,
    _asn_lookup: &AsnLookup,
) -> Result<Enrichment, RowBuildError> {
    let execution_type = record_type.to_lowercase().replace('_', "-");
    let actor = match execution_type.as_str() {
        "request" | "http-request" => {
            if has_authenticated_user(record) {
                "human"
            } else {
                "guest"
            }
        }
        "scheduled-task" | "scheduled" => "scheduled",
        "job-attempt" => "job",
        "command" | "artisan-command" => "command",
        _ => return Ok(Enrichment::default()),
    };

    let is_request = matches!(execution_type.as_str(), "request" | "http-request");
    let headers = if is_request {
        decoded_array(record, &["headers", "request_headers", "requestHeaders"])
    } else {
        None
    };
    let client_ip = is_request
        .then(|| client_ip(record, headers.as_ref()))
        .flatten();

    let request_host = if is_request {
        request_host(record, headers.as_ref())?
    } else {
        None
    };

    Ok(Enrichment {
        actor: Some(actor.to_string()),
        ran_queries: boolean_value(
            record,
            &[
                "queries",
                "ran_queries",
                "ranQueries",
                "has_queries",
                "hasQueries",
            ],
        ),
        response: is_request.then(|| response_context(record)).flatten(),
        request_path: is_request
            .then(|| first_scalar(record, &["route_path", "route", "uri"]))
            .flatten(),
        request_host,
        user_agent: (actor == "guest")
            .then(|| {
                first_scalar(record, &["user_agent", "userAgent"])
                    .or_else(|| header(headers.as_ref(), "user-agent"))
            })
            .flatten(),
        client_ip,
    })
}

fn has_authenticated_user(record: &Value) -> bool {
    for key in [
        "user_id",
        "userId",
        "authenticated_user_id",
        "authenticatedUserId",
    ] {
        if let Some(value) = field(record, key)
            && !php_blank(value)
        {
            return true;
        }
    }

    match field(record, "user") {
        Some(Value::Object(user)) => user.get("id").is_some_and(|value| !php_blank(value)),
        Some(value)
            if value.is_null() || value.is_boolean() || value.is_number() || value.is_string() =>
        {
            !php_blank(value)
        }
        _ => false,
    }
}

fn php_blank(value: &Value) -> bool {
    match value {
        Value::Null => true,
        Value::String(value) => php_blank_string(value),
        Value::Array(value) => value.is_empty(),
        Value::Object(value) => value.is_empty(),
        Value::Bool(_) | Value::Number(_) => false,
    }
}

fn php_blank_string(value: &str) -> bool {
    php_trim(value).is_empty()
}

fn boolean_value(record: &Value, keys: &[&str]) -> Option<bool> {
    for key in keys {
        let Some(value) = field(record, key) else {
            continue;
        };

        return match value {
            Value::Bool(value) => Some(*value),
            Value::Number(value) => value.as_f64().map(|value| value > 0.0),
            Value::String(value) => match php_trim(value).to_ascii_lowercase().as_str() {
                "1" | "true" | "on" | "yes" => Some(true),
                "" | "0" | "false" | "off" | "no" => Some(false),
                _ => None,
            },
            _ => None,
        };
    }

    None
}

fn decoded_array(record: &Value, keys: &[&str]) -> Option<Value> {
    for key in keys {
        let Some(value) = field(record, key) else {
            continue;
        };
        let value = match value {
            Value::String(value) => serde_json::from_str::<Value>(value).ok(),
            Value::Array(_) | Value::Object(_) => Some(value.clone()),
            _ => None,
        };

        if let Some(value @ (Value::Array(_) | Value::Object(_))) = value {
            return Some(value);
        }
    }

    None
}

fn response_context(record: &Value) -> Option<Value> {
    let context = decoded_array(record, &["context", "ctx"])?;
    let response = context
        .as_object()
        .and_then(|context| context.get("hone.response"))
        .filter(|response| !response.is_null())
        .cloned()
        .or_else(|| {
            context
                .as_object()
                .and_then(|context| context.get("hone"))
                .and_then(Value::as_object)
                .and_then(|hone| hone.get("response"))
                .cloned()
        })?;
    let response = match response {
        Value::String(response) => serde_json::from_str(&response).ok()?,
        response => response,
    };

    (response.is_array() || response.is_object()).then_some(response)
}

fn client_ip(record: &Value, headers: Option<&Value>) -> Option<String> {
    if let Some(cloudflare_ip) = header(headers, "cf-connecting-ip")
        && cloudflare_ip
            .parse::<IpAddr>()
            .ok()
            .is_some_and(is_public_ip)
    {
        return Some(cloudflare_ip);
    }

    first_scalar(
        record,
        &["ip", "remote_ip", "remoteIp", "client_ip", "clientIp"],
    )
    .filter(|value| value.parse::<IpAddr>().is_ok())
}

fn request_host(record: &Value, headers: Option<&Value>) -> Result<Option<String>, RowBuildError> {
    let Some(host) =
        first_scalar(record, &["host", "hostname"]).or_else(|| header(headers, "host"))
    else {
        return Ok(None);
    };
    let Some(mut hostname) = php_parse_url_host(host.as_bytes()) else {
        return Ok(None);
    };
    if hostname.is_empty() {
        return Ok(None);
    }

    for byte in &mut hostname {
        if (0x00..=0x1f).contains(byte) || *byte == 0x7f {
            *byte = b'_';
        } else {
            byte.make_ascii_lowercase();
        }
    }
    while hostname.last() == Some(&b'.') {
        hostname.pop();
    }

    String::from_utf8(hostname)
        .map(Some)
        .map_err(|_| RowBuildError::InvalidRequestHostEncoding)
}

fn php_parse_url_host(host: &[u8]) -> Option<Vec<u8>> {
    let authority_end = host
        .iter()
        .position(|byte| matches!(byte, b'/' | b'?' | b'#'))
        .unwrap_or(host.len());
    let authority = &host[..authority_end];
    let authority = authority
        .iter()
        .rposition(|byte| *byte == b'@')
        .map(|position| &authority[position + 1..])
        .unwrap_or(authority);
    if authority.is_empty() {
        return None;
    }

    if authority.first() == Some(&b'[')
        && let Some(closing_bracket) = authority.iter().position(|byte| *byte == b']')
        && (closing_bracket + 1 == authority.len()
            || (authority.get(closing_bracket + 1) == Some(&b':')
                && !authority[closing_bracket + 2..].contains(&b':')))
    {
        let port = if closing_bracket + 1 == authority.len() {
            &[][..]
        } else {
            &authority[closing_bracket + 2..]
        };
        return php_url_port_is_valid(port).then(|| authority[..=closing_bracket].to_vec());
    };

    let Some(colon) = authority.iter().rposition(|byte| *byte == b':') else {
        return Some(authority.to_vec());
    };
    php_url_port_is_valid(&authority[colon + 1..]).then(|| authority[..colon].to_vec())
}

fn php_url_port_is_valid(port: &[u8]) -> bool {
    if port.is_empty() {
        return true;
    }
    if port.len() > 5 {
        return false;
    }

    let mut index = 0;
    while port.get(index).is_some_and(u8::is_ascii_whitespace) {
        index += 1;
    }
    let negative = port.get(index) == Some(&b'-');
    if matches!(port.get(index), Some(b'+') | Some(b'-')) {
        index += 1;
    }
    let digits_start = index;
    let mut value = 0_u32;
    while let Some(byte) = port.get(index).filter(|byte| byte.is_ascii_digit()) {
        value = value * 10 + u32::from(*byte - b'0');
        index += 1;
    }

    index > digits_start && (!negative || value == 0) && value <= u32::from(u16::MAX)
}

fn header(headers: Option<&Value>, name: &str) -> Option<String> {
    let headers = headers?.as_object()?;
    for (key, value) in headers {
        if !key.eq_ignore_ascii_case(name) {
            continue;
        }
        let value = match value {
            Value::Array(values) => values.first(),
            Value::Object(values) => values.get("0"),
            value => Some(value),
        };

        return value
            .filter(|value| value.is_boolean() || value.is_number() || value.is_string())
            .map(|value| php_trim(&php_scalar_string(Some(value))));
    }

    None
}

fn first_scalar(record: &Value, keys: &[&str]) -> Option<String> {
    for key in keys {
        let Some(value) = field(record, key) else {
            continue;
        };
        if !value.is_null() && !value.is_boolean() && !value.is_number() && !value.is_string() {
            continue;
        }
        let value = php_trim(&php_scalar_string(Some(value)));
        if !value.is_empty() {
            return Some(value);
        }
    }

    None
}

fn php_trim(value: &str) -> String {
    value
        .trim_matches([' ', '\t', '\n', '\r', '\0', '\u{000B}'])
        .to_string()
}

#[cfg(test)]
mod tests {
    use serde_json::Value;
    use serde_json::json;
    use sqlx::Row;

    use super::*;
    use crate::envelope::Envelope;
    use crate::test_support::TestDatabase;
    use crate::writer::Writer;

    #[test]
    fn list_records_are_rows_with_unknown_named_fields() {
        let row = build_row(
            "checkout",
            Some("  "),
            "2026-06-09T12:00:00+00:00",
            &json!(["list record"]),
            &AsnLookup::default(),
        )
        .unwrap()
        .unwrap();

        assert_eq!(row.record_type, "unknown");
        assert_eq!(row.normalized_key, "unknown");
        assert_eq!(row.payload, json!(["list record"]));
        assert_eq!(row.deploy, None);
    }

    #[test]
    fn matches_timestamp_duration_and_enrichment_precedence() {
        let row = build_row(
            "checkout",
            Some("abc123"),
            "2026-06-09T12:00:00+00:00",
            &json!({
                "t": "request",
                "timestamp": "bad",
                "ts": 1781006402000_i64,
                "duration": 12000,
                "duration_ms": 99,
                "route_path": "/checkout",
                "queries": "true",
                "user": "",
                "ip": "8.8.8.8",
                "headers": {
                    "CF-Connecting-IP": ["100.64.1.1"],
                    "Host": ["Checkout.Example.com:443"],
                    "User-Agent": ["curl/9"]
                },
                "context": {"hone.response": {"status": 200}}
            }),
            &AsnLookup::default(),
        )
        .unwrap()
        .unwrap();

        assert_eq!(row.occurred_at.to_rfc3339(), "2026-06-09T12:00:02+00:00");
        assert_eq!(row.duration_ms, Some(12.0));
        assert_eq!(row.actor.as_deref(), Some("guest"));
        assert_eq!(row.ran_queries, Some(true));
        assert_eq!(row.request_path.as_deref(), Some("/checkout"));
        assert_eq!(row.request_host.as_deref(), Some("checkout.example.com"));
        assert_eq!(row.user_agent.as_deref(), Some("curl/9"));
        assert_eq!(row.client_ip.as_deref(), Some("8.8.8.8"));
        assert_eq!(row.response, Some(json!({"status": 200})));

        let carbon_style = build_row(
            "checkout",
            None,
            "2026-06-09T12:00:00Z",
            &json!({"t": "log", "timestamp": "May 8, 2009 5:57:51 PM"}),
            &AsnLookup::default(),
        )
        .unwrap()
        .unwrap();
        assert_eq!(
            carbon_style.occurred_at.to_rfc3339(),
            "2009-05-08T17:57:51+00:00"
        );

        let fallback = build_row(
            "checkout",
            None,
            "2026-06-09T12:00:00Z",
            &json!({"t": "log", "timestamp": 1e100, "ts": 1781006403000_i64}),
            &AsnLookup::default(),
        )
        .unwrap()
        .unwrap();
        assert_eq!(
            fallback.occurred_at.to_rfc3339(),
            "2026-06-09T12:00:03+00:00"
        );

        let negative = build_row(
            "checkout",
            None,
            "2026-06-09T12:00:00Z",
            &json!({"t": "log", "timestamp": -0.5}),
            &AsnLookup::default(),
        )
        .unwrap()
        .unwrap();
        assert_eq!(
            negative
                .occurred_at
                .format("%Y-%m-%dT%H:%M:%S%.6fZ")
                .to_string(),
            "1969-12-31T23:59:59.500000Z"
        );

        let coercions = build_row(
            "checkout",
            None,
            "2026-06-09T12:00:00Z",
            &json!({
                "t": "request",
                "queries": " yes ",
                "host": "Example.Test?x=1",
                "user": ""
            }),
            &AsnLookup::default(),
        )
        .unwrap()
        .unwrap();
        assert_eq!(coercions.ran_queries, Some(true));
        assert_eq!(coercions.request_host.as_deref(), Some("example.test"));

        let invalid_host = build_row(
            "checkout",
            None,
            "2026-06-09T12:00:00Z",
            &json!({"t": "request", "host": "example.test:bad", "user": ""}),
            &AsnLookup::default(),
        )
        .unwrap()
        .unwrap();
        assert_eq!(invalid_host.request_host, None);
    }

    #[test]
    fn recursively_matches_php_associative_json_encoding() {
        assert_eq!(php_json_value(&json!({})), json!([]));
        assert_eq!(
            php_json_value(&json!({
                "meta": {},
                "sequence": {"0": "a", "1": {"empty": {}}},
                "non_list": {"1": "b"}
            })),
            json!({
                "meta": [],
                "sequence": ["a", {"empty": []}],
                "non_list": {"1": "b"}
            })
        );
    }

    #[test]
    fn matches_php_request_host_boundary_outcomes() {
        for (host, expected) in [
            ("Example.COM:", Some("example.com")),
            ("host:", Some("host")),
            ("host:80:90", Some("host:80")),
            ("[::1]x", Some("[:")),
            ("a\nb.example", Some("a_b.example")),
            ("x:000080", None),
            ("BÜCHER.example", Some("bÜcher.example")),
            ("ĞÜ.com", Some("ĞÜ.com")),
            ("Ä.com", Some("Ä.com")),
            ("ā.com", Some("ā.com")),
            ("bücher.example", Some("bücher.example")),
            ("127.1", Some("127.1")),
            ("Example.COM:443", Some("example.com")),
            ("Example.COM?x=1", Some("example.com")),
            ("Example.COM#fragment", Some("example.com")),
            ("example.test:bad", None),
            ("example.test:65536", None),
            (".", Some("")),
            ("..", Some("")),
            ("...", Some("")),
            ("[a]:80:90", Some("[a]:80")),
            ("[::1]:80:90", Some("[::1]:80")),
            ("x:-0", Some("x")),
            ("x:-00", Some("x")),
        ] {
            let row = build_row(
                "checkout",
                None,
                "2026-06-09T12:00:00Z",
                &json!({"t": "request", "host": host, "user": ""}),
                &AsnLookup::default(),
            )
            .unwrap()
            .unwrap();
            assert_eq!(row.request_host.as_deref(), expected, "{host:?}");
        }
    }

    #[test]
    fn null_header_values_remain_null() {
        for value in [json!(null), json!([null]), json!({"0": null})] {
            let row = build_row(
                "checkout",
                None,
                "2026-06-09T12:00:00Z",
                &json!({
                    "t": "request",
                    "user": "",
                    "ip": "8.8.8.8",
                    "headers": {
                        "User-Agent": value,
                        "Host": value,
                        "CF-Connecting-IP": value
                    }
                }),
                &AsnLookup::default(),
            )
            .unwrap()
            .unwrap();

            assert_eq!(row.user_agent, None);
            assert_eq!(row.request_host, None);
            assert_eq!(row.client_ip.as_deref(), Some("8.8.8.8"));
        }
    }

    #[test]
    fn scalars_are_skipped_and_non_request_records_have_null_enrichment() {
        assert!(
            build_row("app", None, "", &json!(null), &AsnLookup::default())
                .unwrap()
                .is_none()
        );
        assert!(
            build_row("app", None, "", &json!("record"), &AsnLookup::default())
                .unwrap()
                .is_none()
        );

        let row = build_row(
            "app",
            None,
            "2026-06-09T12:00:00Z",
            &json!({"t": "query", "sql": "select 1"}),
            &AsnLookup::default(),
        )
        .unwrap()
        .unwrap();
        assert!(row.actor.is_none());
        assert!(row.ran_queries.is_none());
    }

    #[tokio::test]
    async fn matches_the_php_generated_golden_rows_after_real_postgres_persistence() {
        let fixture: Value = serde_json::from_str(include_str!(concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../packages/hone-server/tests/Fixtures/rust-ingest-rows.json"
        )))
        .unwrap();
        let app = fixture.get("app").and_then(Value::as_str).unwrap();
        let deploy = fixture.get("deploy").and_then(Value::as_str);
        let sent_at = fixture.get("sent_at").and_then(Value::as_str).unwrap();
        let records = fixture.get("records").and_then(Value::as_array).unwrap();
        let expected = fixture.get("expected").and_then(Value::as_array).unwrap();
        let asn_fixture = concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../packages/hone-server/tests/Fixtures/ip2asn.tsv"
        );
        let database = TestDatabase::new().await;
        let writer = Writer::new(
            database.pool.clone(),
            AsnLookup::from_path(Some(asn_fixture)),
        );
        writer
            .write(
                app,
                &Envelope {
                    app: "forged".to_string(),
                    deploy: deploy.map(str::to_string),
                    sent_at: sent_at.to_string(),
                    records: records.clone(),
                    overflow_dropped_records: 0,
                    failed_delivery_records: 0,
                },
            )
            .await
            .unwrap();
        let stored_rows = sqlx::query(
            r#"
            SELECT id, app, record_type, deploy, occurred_at, normalized_key,
                   duration_ms, payload, actor, ran_queries, response,
                   request_path, request_host, user_agent, client_ip, asn
            FROM raw_events
            ORDER BY id
            "#,
        )
        .fetch_all(&database.pool)
        .await
        .unwrap();
        assert_eq!(stored_rows.len(), expected.len());

        for (row, expected_row) in stored_rows.iter().zip(expected) {
            let expected_payload = expected_row.get("payload").cloned().unwrap();
            let expected_response = expected_row
                .get("response")
                .filter(|response| !response.is_null())
                .cloned()
                .map(sqlx::types::Json);
            let json_matches = sqlx::query_scalar::<_, bool>(
                "SELECT payload = $1 AND response IS NOT DISTINCT FROM $2 FROM raw_events WHERE id = $3",
            )
            .bind(sqlx::types::Json(expected_payload))
            .bind(expected_response)
            .bind(row.get::<i64, _>("id"))
            .fetch_one(&database.pool)
            .await
            .unwrap();
            assert!(json_matches);
        }

        let actual = stored_rows
            .iter()
            .map(|row| {
                let occurred_at = row.get::<DateTime<Utc>, _>("occurred_at");
                json!({
                    "app": row.get::<String, _>("app"),
                    "record_type": row.get::<String, _>("record_type"),
                    "deploy": row.get::<Option<String>, _>("deploy"),
                    "occurred_at": occurred_at.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string(),
                    "normalized_key": row.get::<String, _>("normalized_key"),
                    "duration_ms": row.get::<Option<f64>, _>("duration_ms"),
                        "actor": row.get::<Option<String>, _>("actor"),
                        "ran_queries": row.get::<Option<bool>, _>("ran_queries"),
                    "request_path": row.get::<Option<String>, _>("request_path"),
                    "request_host": row.get::<Option<String>, _>("request_host"),
                    "user_agent": row.get::<Option<String>, _>("user_agent"),
                    "client_ip": row.get::<Option<String>, _>("client_ip"),
                    "asn": row.get::<Option<i64>, _>("asn"),
                })
            })
            .collect::<Vec<_>>();
        let expected_without_json = expected
            .iter()
            .map(|row| {
                let mut row = row.as_object().cloned().unwrap();
                row.shift_remove("payload");
                row.shift_remove("response");
                Value::Object(row)
            })
            .collect::<Vec<_>>();

        assert_eq!(actual, expected_without_json);
        database.cleanup().await;
    }
}
