# Hone

**Self-hosted, MCP-only LLM-facing telemetry for Laravel.**

Hone is a thin telemetry layer for Laravel apps. It captures
[`laravel/nightwatch`](https://github.com/laravel/nightwatch) instrumentation, stores a
pruned slice in a Postgres database **you** control, and exposes it to a coding agent
(Claude Code, CI, etc.) over [MCP](https://modelcontextprotocol.io) to answer one question
well:

> **"What should we improve this week?"**

It is deliberately **not** a Nightwatch competitor. No UI. No deep request traces. No
long-term history. No alerting. Just agent-queryable telemetry with no per-event cost.

---

## Why Hone exists

Nightwatch's event-metered pricing doesn't fit a large class of apps where request volume
doesn't track revenue. To control cost, those apps throttle sampling so hard the data stops
being useful.

Hone is a free, self-hosted **floor**: agent-queryable telemetry with no per-event cost,
for the apps the metered model was never going to fit. Storage is your only cost.

**Positioning.** Nightwatch is the full-featured product — deep request diving, history,
alerting, collaboration, and the excellent UI the Laravel team built. Hone answers one
narrow question for one consumer (a coding agent). Outgrow it → **Nightwatch is the
upgrade.**

Hone is built on `laravel/nightwatch` (MIT, by Taylor Otwell). It **rebinds Nightwatch's
ingest transport** so an app's own telemetry routes to the app owner's own server. Hone
**never contacts Nightwatch's cloud and never uses their service.**

## Support posture

Hone is written for how [Artisan Build](https://artisan.build) uses it. Bugs get fixed.
Feature requests are a fork away. Client-specific features are **not** backfilled into the
OSS release. If you need the full product, that's what Nightwatch is for.

---

## Architecture

```
  Source app #1 ─┐  (hone-client: rebind transport → batch → HTTPS POST)
  Source app #2 ─┼──►  Hone app (one isolated env per client)
  Source app #N ─┘         │ ingest endpoint (HONE_TOKEN auth, tags source app, version-checks envelope)
                           ▼
                        Redis queue
                           ▼
                        Worker → Postgres  (raw, tagged by app + deploy + record_type)
                           ▼
                        Scheduled rollup (before prune) → aggregates
                           ▼
                        MCP server  ◄── coding agent (Claude Code / CI)
```

- **One isolated environment per client** on Laravel Cloud — its own compute, Postgres, and
  Redis, sized and billed per client. The Hone app is single-tenant; isolation is
  environmental.
- **Unlimited source apps per environment.** One client may feed many applications into one
  Hone environment — hence the `app` dimension and the app registry.
- **Cross-boundary transport is HTTP, not shared Redis.** Source apps are separate
  deployments; a TLS + token endpoint crosses environment isolation cleanly.

---

## Repository layout

This is a **monorepo**. Three packages are developed here under `packages/`, each split
read-only to its own repository and published to Packagist:

| Package | Repo | Installed in | Role |
| --- | --- | --- | --- |
| [`artisan-build/hone-contracts`](https://github.com/artisan-build/hone-contracts) | read-only split | both packages | The versioned wire envelope. The single place compatibility lives. |
| [`artisan-build/hone-client`](https://github.com/artisan-build/hone-client) | read-only split | monitored apps | The send side: Nightwatch transport rebind, batching, POST, install/update commands. |
| [`artisan-build/hone-server`](https://github.com/artisan-build/hone-server) | read-only split | the Hone app | The receive side: ingest, storage, rollups, prune, and the MCP server. |

The **Hone app** (this repository's root) is a slim Laravel shell — token handout plus
wiring `hone-server`. It pulls its packages from Packagist and stays thin so there's no
Hone-specific business logic to drift.

### Contributing

Issues and PRs are **disabled** on the three split repos — the same model as Laravel's own
`illuminate/*` read-only splits. All development happens here in the monorepo. See
[`SECURITY.md`](SECURITY.md) for private vulnerability disclosure; Hone sits on an ingest
path.

---

## Compatibility & versioning

Across N independently-deployed senders and one self-hosted receiver, **version skew is the
normal state, not an error.** The wire protocol is built to tolerate it:

- **Versioned envelope.** Every payload carries `ENVELOPE_VERSION`. The envelope evolves
  **additively within a major** — new optional fields only, never remove or repurpose one.
- **Backward-compatible server.** A newer `hone-server` parses every older envelope major.
- **Loud failure on the dangerous case.** Ingest returns a clear **4xx** for an envelope
  *newer* than it knows ("your senders are ahead of this Hone instance — upgrade it").
- **Opaque inner payloads.** Only the thin Hone envelope is version-sensitive. Nightwatch's
  own record bodies are stored as `jsonb` and interpreted at rollup/query time — a change in
  Nightwatch's payload shape degrades to "a rollup misses a field," never an ingest failure.
- **Canonical upgrade order: update the Hone app first, then bump the clients.** A
  backward-compatible server safely runs ahead of its senders; the only hazard is a sender
  getting ahead of the server, which server-first prevents.

---

## Deploying on Laravel Cloud

Run one isolated Laravel Cloud environment per client: the Hone app, one Postgres database,
and Redis. Hone stores its thin app tables and telemetry tables in Postgres through the
`hone` connection; in the default app setup `HONE_DB_*` points at the same database as
`DB_*`.

> **Using a coding agent? Let the skill do it.** This repo ships a
> [`provisioning-hone-on-cloud`](.claude/skills/provisioning-hone-on-cloud/SKILL.md) skill
> that automates everything in this section. Open the monorepo with a skill-aware agent
> (Claude Code, etc.) and ask it to *"provision a Hone instance on Laravel Cloud."* The skill
> sizes the environment as a small/medium/large tier, and **only after you confirm** uses the
> `cloud` CLI to provision Postgres, Redis, a web instance, a managed queue, and the
> scheduler, wire the `HONE_*` config, deploy, migrate, and issue the first source-app token.
> It specializes the Cloud CLI's generic `deploying-laravel-cloud` skill. The manual steps
> below are the same thing by hand.

Required production environment:

- `DB_CONNECTION=pgsql` plus `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and
  `DB_PASSWORD`.
- `HONE_DB_HOST`, `HONE_DB_PORT`, `HONE_DB_DATABASE`, `HONE_DB_USERNAME`, and
  `HONE_DB_PASSWORD`, usually copied from the matching `DB_*` values.
- `HONE_APP_TOKENS`, as comma-separated `app-id=sha256_token_hash` entries.
- `HONE_MCP_TOKEN` and `HONE_MCP_PATH`; the default MCP HTTP path is `/mcp`.
- `QUEUE_CONNECTION=redis` and `HONE_QUEUE_CONNECTION=redis` so accepted ingest batches
  are processed by Redis workers.
- `HONE_RETENTION_RAW_HOURS`, `HONE_RETENTION_AGGREGATE_DAYS`, and
  `HONE_RETENTION_SAMPLE_DAYS`; the defaults are `72`, `90`, and `7`.
- `NIGHTWATCH_DEPLOY`, used as the deploy dimension when source apps report it.

Do not enable Laravel Cloud's built-in Nightwatch integration for the Hone app. Hone uses
Nightwatch instrumentation in source apps but does not send telemetry to Nightwatch Cloud;
Cloud's Nightwatch toggle would ship data to Nightwatch's hosted service and conflicts with
this setup.

Add a build step that writes the deployed commit to the environment file:

```shell
echo "NIGHTWATCH_DEPLOY=$(git rev-parse --short HEAD)" >> .env
```

This assumes `.git` is present during the build. If your build runs from an exported archive,
write the short commit SHA from CI instead, for example from `github.sha` in GitHub Actions.

After deploy, run `php artisan migrate --force`; the `hone-server` migrations create the
`raw_events`, `aggregates`, and `samples` tables on the `hone` connection. Run Laravel's
scheduler in the environment; the package schedules `php artisan hone:maintain` hourly,
which runs `hone:rollup` and then `hone:prune`. Run a Redis queue worker cluster for the
default queue so `ProcessTelemetryBatch` jobs drain accepted ingest batches.

## Adding a source app

On the Hone server, issue a source application token:

```shell
php artisan hone:issue-token <app-id>
```

The app id must be non-empty and cannot contain `=` or `,`. The command prints the plaintext
token once, its SHA-256 hash, and the exact `HONE_APP_TOKENS` entry. Add that
`app-id=sha256hash` entry to `HONE_APP_TOKENS` on the Hone server, comma-separated from any
existing entries, and redeploy.

In the source Laravel app, install Nightwatch and the Hone client:

```shell
composer require laravel/nightwatch artisan-build/hone-client
php artisan hone:install
```

`hone:install` prompts for the Hone ingest URL and plaintext token, or accepts
`--url=` and `--token=`. Use the server ingest URL, ending in `/ingest`; the route is named
`hone-server.ingest`. The installer writes `HONE_URL`, `HONE_TOKEN`, and a truthy
`NIGHTWATCH_ENABLED=true` when needed, then pins `artisan-build/hone-client` to a clean caret
major constraint in `composer.json`. No `NIGHTWATCH_TOKEN` is needed for Hone. Set
`NIGHTWATCH_DEPLOY` in the source app at deploy time so Hone can compare releases.

> **Using a coding agent in the source app?** `hone-client` ships a `configuring-hone-client`
> skill (under `vendor/artisan-build/hone-client/skills/configuring-hone-client/` once the
> package is installed). Point your agent at it and ask it to *"configure the Hone client"* —
> it covers `composer require`, `hone:install`, **verifying the Nightwatch transport rebind is
> actually active**, setting the deploy dimension, confirming server-side receipt over MCP, and
> troubleshooting why telemetry isn't arriving. See the
> [hone-client README](packages/hone-client/README.md#installation) for details.

## Connecting a coding agent (MCP)

The HTTP MCP server is registered at `HONE_MCP_PATH` and requires an
`Authorization: Bearer <HONE_MCP_TOKEN>` header. Requests without the configured token fail
closed with `401`. For local Claude Code use, the same server is registered as a stdio MCP
server under `HONE_MCP_LOCAL_NAME`, defaulting to `hone`.

Hone exposes 19 read-only MCP tools: discovery tools for apps, record types, deploys, and
ingest freshness; slow-path tools for requests, queries, jobs, and outgoing requests;
`query_metric`, `regression_check`, `exceptions`, `top_users`, and volume/stat tools for
cache events, queues, mail, notifications, scheduled tasks, commands, and logs by level.

## Upgrading

Upgrade the Hone server first, run its migrations, and then update the source app clients.
Clients can run:

```shell
php artisan hone:update
```

The command derives `/capabilities` from `HONE_URL` (stripping a trailing `/ingest` when
present), sends its `HONE_TOKEN` as a bearer header (the capabilities endpoint itself does not
require it), and reports whether the client's envelope major is inside the server's supported
range. The envelope is additive within a major. If a source app
sends an envelope newer than the server understands, ingest returns a 4xx with an upgrade
message instead of accepting the batch.

## Privacy

Hone is designed to be safe to feed to an LLM when source apps use Nightwatch's normal
redaction configuration. Query bindings are not part of Hone's normalized query key, and Hone
does not add a second capture path for them; source-app Nightwatch redaction happens before
records are sent to Hone. MCP tools summarize by normalized keys such as route, SQL shape,
exception class/location, log level, user id, and cache `store:type`, rather than exposing raw
request bodies or arbitrary event payloads.

---

## Out of scope (non-goals)

- **No UI / dashboard.** The agent is the interface.
- **No deep distributed traces / span waterfalls** — Nightwatch's domain.
- **No long-term history** beyond aggregate retention.
- **No alerting / anomaly detection** — pull, not push.
- **No in-app multi-tenancy** — isolation is per-environment.
- **No sampling logic** — that lives in the source app's Nightwatch config.
- **No backfill** of client-specific features into the OSS release.

---

## License

Hone is open-sourced software licensed under the [MIT license](LICENSE).
