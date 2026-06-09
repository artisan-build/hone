# hone-server

The **receive side** of [Hone](https://github.com/artisan-build/hone). It is consumed by the
Hone app and provides everything between the ingest endpoint and the coding agent: receive,
store, roll up, prune, and serve over MCP.

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/hone`](https://github.com/artisan-build/hone) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

## What it provides

- **Ingest endpoint** — validates the per-app `HONE_TOKEN` against the registry, resolves the
  source app, version-checks the envelope (parse if known, **4xx if newer**), and enqueues
  raw payloads to Redis. Returns fast; no synchronous DB writes.
- **Capabilities endpoint** — advertises the envelope majors this server supports, so a
  client's `hone:update` can check compatibility.
- **Worker** — drains Redis and writes raw events to Postgres, tagged by
  `(app, record_type, deploy, occurred_at)`.
- **App registry** — source apps plus hashed tokens, config-file driven from `.env`. Adding
  an app is a config entry plus a deploy.
- **Rollups & prune** — scheduled jobs that compute percentiles from raw data (before it is
  pruned) and persist daily aggregates that survive pruning.
- **MCP server** — a read-only, multi-app-aware surface a coding agent queries.

## Storage model

Telemetry is stored generically rather than as twelve bespoke schemas. Every record type —
requests, queries, jobs, exceptions, commands, cache, mail, notifications, outgoing HTTP,
scheduled tasks, logs, users — flows through the same three tables. `app` and `deploy` are
first-class dimensions on all of them.

| Table | Lifetime | Shape |
| --- | --- | --- |
| `raw_events` | short (~72h) | `id, app, record_type, deploy, occurred_at, normalized_key, payload (jsonb)` |
| `aggregates` | long (~90d) | `app, record_type, normalized_key, deploy, bucket_date, metric, value, sample_count` |
| `samples` | short (~7d) | a few slowest exemplars per `(app, record_type, normalized_key)` window |

The Nightwatch record bodies are stored **opaquely as `jsonb`** and interpreted only at
rollup and query time — so a change in Nightwatch's payload shape degrades to "a rollup
misses a field," never an ingest failure.

## Rollups & retention

- The **rollup job runs before the prune job.** Percentiles (p95/p99) are computed from raw
  data with Postgres `percentile_cont` within the raw-retention window and persisted to
  `aggregates`, surviving the prune.
- **Daily bucketing** is the regression unit.
- Retention is configurable per install from `.env`: `raw_events` ~72h · `aggregates` ~90d ·
  `samples` ~7d.

## MCP server

- **Transport:** Streamable HTTP with a per-environment bearer token (for remote agents and
  CI), plus stdio for local Claude Code.
- **Read-only and multi-app aware:** every tool takes an optional `app` filter and otherwise
  aggregates across the environment's apps.
- **Tools (v1):** `slow_requests`, `slow_queries`, `slow_jobs`, `slow_outgoing_requests`,
  `exceptions`, `cache_stats`, `queue_throughput`, `mail_volume`, `notification_volume`,
  `scheduled_task_health`, `command_stats`, `log_volume_by_level`, `top_users`,
  `regression_check`, `deploys`, `ingest_freshness`, `query_metric`, `record_types`,
  `list_apps`.

Output is PII-safe by construction — bindings are never captured and redaction happens
upstream — so feeding tool results into an agent context doesn't leak customer data.

## Installation

```bash
composer require artisan-build/hone-server
```

This package is consumed by the Hone app. See the
[Hone app README](https://github.com/artisan-build/hone) for environment setup, the app
registry, scheduling the rollup/prune jobs, and running the queue worker.

## License

MIT. See [LICENSE](LICENSE).
