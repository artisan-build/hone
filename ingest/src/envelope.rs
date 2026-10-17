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
            .or_else(|| number.as_u64().and_then(|value| i64::try_from(value).ok()))
            .or_else(|| {
                number
                    .as_f64()
                    .filter(|value| value.is_finite())
                    .map(|value| value as i64)
            }),
        Value::String(value) => value
            .trim()
            .parse::<f64>()
            .ok()
            .filter(|value| value.is_finite())
            .map(|value| value as i64),
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
        Value::String(value) => php_leading_number(value).unwrap_or_default() as i64,
        Value::Array(values) => i64::from(!values.is_empty()),
        Value::Object(values) => i64::from(!values.is_empty()),
    }
}

fn php_leading_number(value: &str) -> Option<f64> {
    let value = value.trim_start();
    if let Ok(number) = value.parse::<f64>() {
        return Some(number);
    }

    value
        .char_indices()
        .map(|(index, _)| index)
        .chain(std::iter::once(value.len()))
        .rev()
        .filter(|index| *index > 0)
        .find_map(|index| value[..index].parse::<f64>().ok())
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
