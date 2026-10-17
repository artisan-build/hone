use serde_json::{Map, Value};

pub const VERSION: i64 = 1;

#[derive(Clone, Debug, PartialEq)]
pub struct Envelope {
    pub app: String,
    pub deploy: Option<String>,
    pub sent_at: String,
    pub records: Vec<Value>,
    pub overflow_dropped_records: i64,
    pub failed_delivery_records: i64,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct EnvelopeError(pub String);

pub fn accepts_root(value: &Value) -> bool {
    value.is_object() || value.is_array()
}

pub fn version_from(data: &Value) -> Result<i64, EnvelopeError> {
    data.as_object()
        .and_then(|object| object.get("envelope_version"))
        .and_then(php_numeric_integer)
        .ok_or_else(|| {
            EnvelopeError("Envelope is missing a numeric \"envelope_version\".".to_string())
        })
}

pub fn from_value(data: &Value) -> Result<Envelope, EnvelopeError> {
    version_from(data)?;
    let object = data.as_object();

    let app = match object.and_then(|value| value.get("app")) {
        Some(Value::String(app)) if !app.is_empty() => app.clone(),
        _ => {
            return Err(EnvelopeError(
                "Envelope is missing a non-empty string \"app\".".to_string(),
            ));
        }
    };

    let records = match object.and_then(|value| value.get("records")) {
        None | Some(Value::Null) => Vec::new(),
        Some(Value::Array(records)) => records.clone(),
        Some(Value::Object(records)) => records.values().cloned().collect(),
        Some(_) => {
            return Err(EnvelopeError(
                "Envelope \"records\" must be an array.".to_string(),
            ));
        }
    };

    let deploy = object
        .and_then(|value| value.get("deploy"))
        .filter(|value| !value.is_null())
        .map(php_cast_string);
    let sent_at = object
        .and_then(|value| value.get("sent_at"))
        .filter(|value| !value.is_null())
        .map(php_cast_string)
        .unwrap_or_default();
    let losses = object
        .and_then(|value| value.get("losses"))
        .and_then(php_array_map);

    Ok(Envelope {
        app,
        deploy,
        sent_at,
        records,
        overflow_dropped_records: loss(losses, "overflow_dropped_records"),
        failed_delivery_records: loss(losses, "failed_delivery_records"),
    })
}

fn php_array_map(value: &Value) -> Option<&Map<String, Value>> {
    value.as_object()
}

fn loss(losses: Option<&Map<String, Value>>, key: &str) -> i64 {
    losses
        .and_then(|values| values.get(key))
        .map(php_cast_integer)
        .unwrap_or_default()
        .max(0)
}

pub(crate) fn php_cast_string(value: &Value) -> String {
    match value {
        Value::Null => String::new(),
        Value::Bool(true) => "1".to_string(),
        Value::Bool(false) => String::new(),
        Value::Number(number) => php_number_string(number),
        Value::String(value) => value.clone(),
        Value::Array(_) | Value::Object(_) => "Array".to_string(),
    }
}

pub(crate) fn php_scalar_string(value: Option<&Value>) -> String {
    match value {
        None | Some(Value::Null) | Some(Value::Array(_)) | Some(Value::Object(_)) => String::new(),
        Some(value) => php_cast_string(value),
    }
}

fn php_number_string(number: &serde_json::Number) -> String {
    if let Some(value) = number.as_i64() {
        return value.to_string();
    }
    if let Some(value) = number.as_u64() {
        if value <= i64::MAX as u64 {
            return value.to_string();
        }

        return php_float_string(value as f64);
    }

    let Some(value) = number.as_f64() else {
        return number.to_string();
    };

    php_float_string(value)
}

fn php_float_string(value: f64) -> String {
    if value == 0.0 {
        return if value.is_sign_negative() {
            "-0".to_string()
        } else {
            "0".to_string()
        };
    }

    let scientific = format!("{value:.13e}");
    let (mantissa, exponent) = scientific.split_once('e').unwrap_or((&scientific, "0"));
    let exponent = exponent.parse::<i32>().unwrap_or_default();
    if !(-4..=13).contains(&exponent) {
        let mut mantissa = mantissa.trim_end_matches('0').to_string();
        if mantissa.ends_with('.') {
            mantissa.push('0');
        }

        return format!("{mantissa}E{exponent:+}");
    }

    let rounded = scientific.parse::<f64>().unwrap_or(value);
    let decimal_places = (13 - exponent).max(0) as usize;
    let formatted = format!("{rounded:.decimal_places$}");

    if formatted.contains('.') {
        formatted
            .trim_end_matches('0')
            .trim_end_matches('.')
            .to_string()
    } else {
        formatted
    }
}

fn php_numeric_integer(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number
            .as_i64()
            .or_else(|| {
                number.as_u64().map(|value| {
                    if value <= i64::MAX as u64 {
                        value as i64
                    } else {
                        php_float_to_integer(value as f64)
                    }
                })
            })
            .or_else(|| number.as_f64().map(php_float_to_integer)),
        Value::String(value) => php_numeric_string_integer(value),
        _ => None,
    }
}

fn php_cast_integer(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Number(number) => {
            php_numeric_integer(&Value::Number(number.clone())).unwrap_or_default()
        }
        Value::String(value) => php_leading_numeric_integer(value),
        Value::Array(values) => i64::from(!values.is_empty()),
        Value::Object(values) => i64::from(!values.is_empty()),
    }
}

fn php_float_to_integer(value: f64) -> i64 {
    if !value.is_finite() {
        return 0;
    }

    const TWO_TO_63: f64 = 9_223_372_036_854_775_808.0;
    const TWO_TO_64: f64 = 18_446_744_073_709_551_616.0;
    let mut wrapped = value.trunc() % TWO_TO_64;
    if wrapped < 0.0 {
        wrapped += TWO_TO_64;
    }
    if wrapped >= TWO_TO_63 {
        (wrapped - TWO_TO_64) as i64
    } else {
        wrapped as i64
    }
}

fn php_numeric_string_integer(value: &str) -> Option<i64> {
    let value = value.trim_matches(is_php_numeric_whitespace);
    let prefix_length = numeric_prefix_length(value)?;
    if prefix_length != value.len() {
        return None;
    }

    Some(cast_numeric_string(&value[..prefix_length]))
}

fn php_leading_numeric_integer(value: &str) -> i64 {
    let value = value.trim_start_matches(is_php_numeric_whitespace);
    numeric_prefix_length(value)
        .map(|length| cast_numeric_string(&value[..length]))
        .unwrap_or_default()
}

fn is_php_numeric_whitespace(character: char) -> bool {
    matches!(
        character,
        ' ' | '\t' | '\n' | '\r' | '\u{000B}' | '\u{000C}'
    )
}

fn cast_numeric_string(value: &str) -> i64 {
    if !value.contains(['.', 'e', 'E']) {
        return clamp_decimal_integer(value);
    }

    value
        .parse::<f64>()
        .ok()
        .filter(|value| value.is_finite())
        .map(|value| value as i64)
        .unwrap_or_default()
}

fn clamp_decimal_integer(value: &str) -> i64 {
    let (negative, digits) = match value.as_bytes().first() {
        Some(b'-') => (true, &value[1..]),
        Some(b'+') => (false, &value[1..]),
        _ => (false, value),
    };
    let magnitude = digits.parse::<u128>().unwrap_or(u128::MAX);
    if negative {
        let minimum_magnitude = i64::MAX as u128 + 1;
        if magnitude >= minimum_magnitude {
            i64::MIN
        } else {
            -(magnitude as i64)
        }
    } else if magnitude > i64::MAX as u128 {
        i64::MAX
    } else {
        magnitude as i64
    }
}

fn numeric_prefix_length(value: &str) -> Option<usize> {
    let bytes = value.as_bytes();
    let mut index = usize::from(matches!(bytes.first(), Some(b'+') | Some(b'-')));
    let mut digits = 0;

    while bytes.get(index).is_some_and(u8::is_ascii_digit) {
        index += 1;
        digits += 1;
    }
    if bytes.get(index) == Some(&b'.') {
        index += 1;
        while bytes.get(index).is_some_and(u8::is_ascii_digit) {
            index += 1;
            digits += 1;
        }
    }
    if digits == 0 {
        return None;
    }

    if matches!(bytes.get(index), Some(b'e') | Some(b'E')) {
        let exponent_start = index;
        index += 1;
        if matches!(bytes.get(index), Some(b'+') | Some(b'-')) {
            index += 1;
        }
        let exponent_digits_start = index;
        while bytes.get(index).is_some_and(u8::is_ascii_digit) {
            index += 1;
        }
        if index == exponent_digits_start {
            index = exponent_start;
        }
    }

    Some(index)
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::*;

    #[test]
    fn ports_envelope_coercions_and_tolerance() {
        let envelope = from_value(&json!({
            "envelope_version": "0.9",
            "app": "checkout",
            "deploy": false,
            "sent_at": true,
            "records": {"second": {"t": "query"}, "first": ["list record"]},
            "losses": {"overflow_dropped_records": "3.9", "failed_delivery_records": -2},
            "future_key": "ignored"
        }))
        .unwrap();

        assert_eq!(version_from(&json!({"envelope_version": "0.9"})), Ok(0));
        assert_eq!(envelope.deploy.as_deref(), Some(""));
        assert_eq!(envelope.sent_at, "1");
        assert_eq!(
            envelope.records,
            vec![json!({"t": "query"}), json!(["list record"])]
        );
        assert_eq!(envelope.overflow_dropped_records, 3);
        assert_eq!(envelope.failed_delivery_records, 0);

        let prefixed = from_value(&json!({
            "envelope_version": 1,
            "app": "checkout",
            "losses": {"overflow_dropped_records": " 4.9records"}
        }))
        .unwrap();
        assert_eq!(prefixed.overflow_dropped_records, 4);
    }

    #[test]
    fn ports_php_float_string_forms() {
        assert_eq!(php_cast_string(&json!(1.0)), "1");
        assert_eq!(php_cast_string(&json!(1.5)), "1.5");
        assert_eq!(php_cast_string(&json!(1e20)), "1.0E+20");
        assert_eq!(php_cast_string(&json!(1e-7)), "1.0E-7");
        assert_eq!(
            php_cast_string(&serde_json::from_str("9223372036854775808").unwrap()),
            "9.2233720368548E+18"
        );
        assert_eq!(php_cast_string(&json!(99999999999999.9)), "1.0E+14");
        assert_eq!(
            php_cast_string(&json!(1.2345678901234567)),
            "1.2345678901235"
        );
    }

    #[test]
    fn ports_php_64_bit_numeric_coercion_for_versions_and_losses() {
        let numeric_overflow: Value = serde_json::from_str(
            r#"{"envelope_version":9223372036854775808,"app":"checkout","losses":{"overflow_dropped_records":9223372036854775808}}"#,
        )
        .unwrap();
        assert_eq!(version_from(&numeric_overflow), Ok(i64::MIN));
        let envelope = from_value(&numeric_overflow).unwrap();
        assert_eq!(envelope.overflow_dropped_records, 0);

        let exact_string = json!({
            "envelope_version": "9007199254740993",
            "app": "checkout",
            "losses": {"overflow_dropped_records": "9007199254740993"}
        });
        assert_eq!(version_from(&exact_string), Ok(9_007_199_254_740_993));
        assert_eq!(
            from_value(&exact_string).unwrap().overflow_dropped_records,
            9_007_199_254_740_993
        );

        let string_overflow = json!({
            "envelope_version": "9223372036854775808",
            "app": "checkout",
            "losses": {"overflow_dropped_records": "999999999999999999999999"}
        });
        assert_eq!(version_from(&string_overflow), Ok(i64::MAX));
        assert_eq!(
            from_value(&string_overflow)
                .unwrap()
                .overflow_dropped_records,
            i64::MAX
        );
        assert_eq!(
            php_numeric_string_integer("-9223372036854775809"),
            Some(i64::MIN)
        );
    }

    #[test]
    fn numeric_strings_use_php_ascii_whitespace_only() {
        assert_eq!(
            version_from(&json!({"envelope_version": "\u{000C}1\t"})),
            Ok(1)
        );
        assert_eq!(
            version_from(&json!({"envelope_version": "\u{00A0}1"})),
            Err(EnvelopeError(
                "Envelope is missing a numeric \"envelope_version\".".to_string()
            ))
        );

        let envelope = from_value(&json!({
            "envelope_version": 1,
            "app": "checkout",
            "losses": {"overflow_dropped_records": "\u{00A0}4"}
        }))
        .unwrap();
        assert_eq!(envelope.overflow_dropped_records, 0);
    }

    #[test]
    fn a_list_root_reaches_version_validation_like_associative_json_decode() {
        assert!(accepts_root(&json!([])));
        assert_eq!(
            version_from(&json!([])),
            Err(EnvelopeError(
                "Envelope is missing a numeric \"envelope_version\".".to_string()
            ))
        );
    }
}
