use std::net::IpAddr;

use chrono::{DateTime, NaiveDate, NaiveDateTime, TimeZone, Timelike, Utc};
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
    TimestampOutOfRange,
}

impl RowBuildError {
    pub fn category(self) -> &'static str {
        match self {
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
    let enrichment = enrichment(&record_type, record, asn_lookup);
    let occurred_at = occurred_at(record, sent_at)?;

    Ok(Some(RawEventRow {
        app: app.to_string(),
        record_type: record_type.clone(),
        deploy: deploy
            .filter(|value| !php_blank_string(value))
            .map(str::to_string),
        occurred_at: occurred_at.with_nanosecond(0).unwrap_or(occurred_at),
        normalized_key: normalizer::key_for(&record_type, record),
        duration_ms: duration_ms(record),
        payload: php_json_value(record),
        actor: enrichment.actor,
        ran_queries: enrichment.ran_queries,
        response: enrichment.response,
        request_path: enrichment.request_path,
        request_host: enrichment.request_host,
        user_agent: enrichment.user_agent,
        client_ip: enrichment.client_ip.clone(),
        asn: asn_lookup.lookup(enrichment.client_ip.as_deref()),
    }))
}

fn php_json_value(value: &Value) -> Value {
    match value {
        Value::Number(number) if number.is_f64() => {
            let Some(number) = number.as_f64() else {
                return value.clone();
            };
            if number.fract() == 0.0 && number >= i64::MIN as f64 && number <= i64::MAX as f64 {
                Value::from(number as i64)
            } else {
                value.clone()
            }
        }
        Value::Array(values) => Value::Array(values.iter().map(php_json_value).collect()),
        Value::Object(values) => Value::Object(
            values
                .iter()
                .map(|(key, value)| (key.clone(), php_json_value(value)))
                .collect(),
        ),
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
            Some(number) => Some(timestamp_from_number(number)?),
            None => None,
        },
        Value::String(value) if !value.is_empty() => {
            if let Ok(number) = value.trim().parse::<f64>() {
                return timestamp_from_number(number).map(Some);
            }

            parse_date_string(value)
        }
        _ => None,
    };

    Ok(parsed)
}

fn timestamp_from_number(timestamp: f64) -> Result<DateTime<Utc>, RowBuildError> {
    if !timestamp.is_finite() {
        return Err(RowBuildError::TimestampOutOfRange);
    }

    let seconds = if timestamp > 9_999_999_999.0 {
        timestamp / 1000.0
    } else {
        timestamp
    };
    let whole_seconds = seconds.trunc() as i64;
    let nanoseconds = ((seconds - seconds.trunc()).abs() * 1_000_000_000.0).round() as u32;

    Utc.timestamp_opt(whole_seconds, nanoseconds)
        .single()
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

fn enrichment(record_type: &str, record: &Value, _asn_lookup: &AsnLookup) -> Enrichment {
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
        _ => return Enrichment::default(),
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

    Enrichment {
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
        request_host: is_request
            .then(|| request_host(record, headers.as_ref()))
            .flatten(),
        user_agent: (actor == "guest")
            .then(|| {
                first_scalar(record, &["user_agent", "userAgent"])
                    .or_else(|| header(headers.as_ref(), "user-agent"))
            })
            .flatten(),
        client_ip,
    }
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
            Value::String(value) => match value.to_ascii_lowercase().as_str() {
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

fn request_host(record: &Value, headers: Option<&Value>) -> Option<String> {
    let host = first_scalar(record, &["host", "hostname"]).or_else(|| header(headers, "host"))?;
    let authority = host.split('/').next()?.rsplit('@').next()?;
    let hostname = if authority.starts_with('[') {
        authority
            .find(']')
            .map(|end| authority[..=end].to_string())?
    } else {
        authority.split(':').next()?.to_string()
    };
    let hostname = hostname.to_lowercase();
    let hostname = hostname.trim_end_matches('.');

    (!hostname.is_empty()).then(|| hostname.to_string())
}

fn header(headers: Option<&Value>, name: &str) -> Option<String> {
    let headers = headers?.as_object()?;
    for (key, value) in headers {
        if !key.eq_ignore_ascii_case(name) {
            continue;
        }
        let value = match value {
            Value::Array(values) => values.first(),
            value => Some(value),
        };

        return value
            .filter(|value| {
                value.is_null() || value.is_boolean() || value.is_number() || value.is_string()
            })
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

    use super::*;

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

    #[test]
    fn matches_the_php_generated_golden_rows() {
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
        let asn_lookup = AsnLookup::from_path(Some(asn_fixture));
        let actual = records
            .iter()
            .filter_map(|record| build_row(app, deploy, sent_at, record, &asn_lookup).unwrap())
            .map(|row| {
                json!({
                    "app": row.app,
                    "record_type": row.record_type,
                    "deploy": row.deploy,
                    "occurred_at": row.occurred_at.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string(),
                    "normalized_key": row.normalized_key,
                    "duration_ms": row.duration_ms,
                    "payload": row.payload,
                    "actor": row.actor,
                    "ran_queries": row.ran_queries,
                    "response": row.response,
                    "request_path": row.request_path,
                    "request_host": row.request_host,
                    "user_agent": row.user_agent,
                    "client_ip": row.client_ip,
                    "asn": row.asn,
                })
            })
            .collect::<Vec<_>>();

        assert_eq!(&actual, expected);
    }
}
