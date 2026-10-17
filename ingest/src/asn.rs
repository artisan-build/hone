use std::fs::File;
use std::io::{BufRead, BufReader};
use std::net::IpAddr;
use std::path::Path;

#[derive(Clone, Debug, Default)]
pub struct AsnLookup {
    ranges: Vec<AsnRange>,
}

#[derive(Clone, Debug)]
struct AsnRange {
    family: u8,
    start: u128,
    end: u128,
    asn: Option<i64>,
}

impl AsnLookup {
    pub fn from_path(path: Option<&str>) -> Self {
        let Some(path) = path else {
            return Self::default();
        };

        Self::read(Path::new(path)).unwrap_or_default()
    }

    fn read(path: &Path) -> Option<Self> {
        let file = File::open(path).ok()?;
        let mut ranges = Vec::new();

        for line in BufReader::new(file).lines().map_while(Result::ok) {
            let mut columns = line.trim().split('\t');
            let start = columns
                .next()
                .and_then(|value| value.parse::<IpAddr>().ok());
            let end = columns
                .next()
                .and_then(|value| value.parse::<IpAddr>().ok());
            let Some(asn_column) = columns.next() else {
                continue;
            };
            let asn = Some(asn_column)
                .map(|value| value.trim_start_matches(['A', 'S', 'a', 's']))
                .and_then(|value| value.parse::<i64>().ok())
                .filter(|value| *value > 0);

            let (Some(start), Some(end)) = (start, end) else {
                continue;
            };
            let (start_family, start) = numeric_ip(start);
            let (end_family, end) = numeric_ip(end);
            if start_family != end_family {
                continue;
            }

            ranges.push(AsnRange {
                family: start_family,
                start,
                end,
                asn,
            });
        }

        ranges.sort_by_key(|range| (range.family, range.start));
        Some(Self { ranges })
    }

    pub fn lookup(&self, ip_address: Option<&str>) -> Option<i64> {
        let ip_address = ip_address?.parse::<IpAddr>().ok()?;
        if !is_public_ip(ip_address) {
            return None;
        }
        let (family, target) = numeric_ip(ip_address);

        let insertion = self
            .ranges
            .partition_point(|range| (range.family, range.start) <= (family, target));
        let range = insertion
            .checked_sub(1)
            .and_then(|index| self.ranges.get(index))?;

        (range.family == family && target <= range.end)
            .then_some(range.asn)
            .flatten()
    }
}

pub fn is_public_ip(ip_address: IpAddr) -> bool {
    match ip_address {
        IpAddr::V4(ip) => {
            let value = u32::from(ip);
            ![
                ("0.0.0.0", 8),
                ("10.0.0.0", 8),
                ("100.64.0.0", 10),
                ("127.0.0.0", 8),
                ("169.254.0.0", 16),
                ("172.16.0.0", 12),
                ("192.168.0.0", 16),
                ("240.0.0.0", 4),
            ]
            .iter()
            .any(|(network, prefix)| {
                let network = network
                    .parse::<std::net::Ipv4Addr>()
                    .map(u32::from)
                    .unwrap_or_default();
                let mask = u32::MAX.checked_shl(32 - prefix).unwrap_or_default();
                value & mask == network & mask
            })
        }
        IpAddr::V6(ip) => {
            let value = u128::from(ip);
            ip.to_ipv4_mapped().is_none()
                && ![("::", 128), ("::1", 128), ("fc00::", 7), ("fe80::", 10)]
                    .iter()
                    .any(|(network, prefix)| {
                        let network = network
                            .parse::<std::net::Ipv6Addr>()
                            .map(u128::from)
                            .unwrap_or_default();
                        let mask = u128::MAX.checked_shl(128 - prefix).unwrap_or_default();
                        value & mask == network & mask
                    })
        }
    }
}

fn numeric_ip(ip_address: IpAddr) -> (u8, u128) {
    match ip_address {
        IpAddr::V4(ip) => (4, u32::from(ip).into()),
        IpAddr::V6(ip) => (6, u128::from(ip)),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn resolves_a_configured_tsv_and_fails_closed_without_one() {
        let fixture = concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../packages/hone-server/tests/Fixtures/ip2asn.tsv"
        );
        let lookup = AsnLookup::from_path(Some(fixture));

        assert_eq!(lookup.lookup(Some("1.1.1.1")), Some(13335));
        assert_eq!(lookup.lookup(Some("100.64.1.1")), None);
        assert_eq!(lookup.lookup(Some("10.0.0.1")), None);
        assert!(is_public_ip("192.0.2.1".parse().unwrap()));
        assert!(is_public_ip("198.51.100.1".parse().unwrap()));
        assert!(is_public_ip("224.0.0.1".parse().unwrap()));
        assert!(!is_public_ip("::ffff:10.0.0.1".parse().unwrap()));
        assert!(!is_public_ip("::ffff:1.1.1.1".parse().unwrap()));
        assert_eq!(
            AsnLookup::from_path(Some("/missing/ip2asn.tsv")).lookup(Some("1.1.1.1")),
            None
        );
        assert_eq!(AsnLookup::from_path(None).lookup(Some("1.1.1.1")), None);
    }
}
