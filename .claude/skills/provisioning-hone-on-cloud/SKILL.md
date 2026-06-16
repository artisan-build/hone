---
name: provisioning-hone-on-cloud
description: "Stand up a self-hosted Hone telemetry instance on Laravel Cloud with the `cloud` CLI. Gathers expected volume as a small/medium/large tier, recommends and — only after confirmation — provisions the matching resources (Postgres, Redis, a web instance, a managed queue, the scheduler), wires HONE_* config, deploys, migrates, and issues the first source-app token. Use when the user wants to deploy, set up, or provision a Hone instance / server / environment on Laravel Cloud, stand up Hone telemetry infrastructure, or onboard a new client's Hone environment."
---

# Provisioning Hone on Laravel Cloud

Stands up **one isolated Hone environment** (one client) on Laravel Cloud. Hone is single-tenant —
isolation is per-environment — so repeat this per client. One environment ingests from many source apps,
so you do NOT provision per source app.

This skill **specializes** the generic `deploying-laravel-cloud` skill (shipped by the Cloud CLI). Its
rules still apply: **discover options at runtime** (`cloud <cmd> -h`, `cloud instance:sizes`,
`cloud cache:types`); add `-n` to every command; `--json` on reads/creates; `--force` on updates and
variable sets; **confirm before any `:create`** (billable); delegate high-output commands
(`deploy:monitor`, `:list`) to a subagent.

> This was validated by a real first run. **Read [reference/cli-reality.md](reference/cli-reality.md) — it
> documents exactly which steps work via the CLI on cloud-cli v0.5.0 and which currently require the
> dashboard.** Don't fight the CLI bugs; follow the documented path.

## What gets provisioned (the Hone topology)

| Resource | Cloud command | Notes |
| --- | --- | --- |
| Application | `application:create` | One per client (skip if it exists). A default **environment** is auto-created. |
| Postgres | `database-cluster:create --type neon_serverless_postgres_18` → `database:create <cluster> --name hone` | Neon serverless (autoscaling CU, suspends when idle — cheap). Holds `raw_events`/`aggregates`/`samples`. |
| Redis | `cache:create --type upstash_redis --size … --auto-upgrade-enabled=false --is-public=false` | Backs the cache. |
| Web instance | `instance:create … --type app --uses-scheduler=true` (**dashboard on v0.5.0** — see reality doc) | Serves `/ingest` + MCP. **Scheduler is this flag** (runs `hone:maintain` hourly), not a separate resource. |
| Queue | `managed-queue:create` | **Use a managed queue — background-process workers are deprecated.** Requires `aws/aws-sdk-php` in the app (SQS-backed). |
| Attach DB+cache to env | `environment:update --database-id … --cache-id …` (**dashboard on v0.5.0** — flags are silent no-ops) | Injects `DB_*`/`REDIS_*` at deploy. |

**Do NOT enable Cloud's built-in Nightwatch integration on this app.** Hone uses Nightwatch
instrumentation in the *source* apps but never transmits to Nightwatch Cloud; the toggle would conflict.

**Prerequisite in the Hone app:** it must require **`aws/aws-sdk-php`** (managed queues are SQS-backed).
The Hone app already ships this; if a fork removed it, `composer require aws/aws-sdk-php` or the deploy
fails with *"application has a managed queue but is missing aws/aws-sdk-php"*.

## Step 1 — Pick a tier

Ask the user for **Small / Medium / Large** (capacity hints + the exact resource set per tier in
[reference/resource-plan.md](reference/resource-plan.md)). These are **starting points** — Cloud
autoscaling + `:update` make scaling trivial, so bias to the smaller tier. First run
`cloud instance:sizes --json -n` and `cloud cache:types --json -n` and map the tier to currently-available
sizes in the target region. **Match the region to the source apps' region** (check the org's existing
apps with `cloud app:list --json -n`) so ingest stays same-region.

## Step 2 — Recommend + confirm (REQUIRED gate)

Present, and **wait for explicit approval before creating anything**: the resolved resource list (concrete
sizes + region), a rough cost note, and the ordered command list. Cloud's CLI does not expose per-resource
pricing — say so and point at `cloud usage --json -n`. Never provision unprompted.

## Step 3 — Provision

Follow [reference/resource-plan.md](reference/resource-plan.md) exactly — it has the real, working command
sequence with the v0.5.0 dashboard fallbacks called out. Capture each resource's `id`/`connection` from
`--json` for the next step. High level: app (auto-creates env) → Postgres cluster + `hone` schema → Redis
cache → web instance + scheduler (dashboard) → managed queue → **attach DB+cache (dashboard)** → set
`HONE_*` env vars → deploy → (Cloud auto-migrates, creating `api_tokens`) → `token:create` the first
source-app token (no redeploy needed) or set a bootstrap `FALLBACK_TOKEN`.

## Step 4 — Verify (don't trust `environment:get` — it under-reports)

`environment:get` does **not** reliably report `databaseSchemaId`/`cacheId`/`branch`. Verify functionally:

- `curl -s -o /dev/null -w '%{http_code}' https://<env-url>/capabilities` → **200** (app up).
- Ingest auth: POST `/ingest` with no token → **401**; with the real token → **422** (auth passed,
  envelope validation). 401 with the real token means the token row is missing — re-run `token:create`
  (or check `FALLBACK_TOKEN`).
- Migrations (Cloud auto-migrates on deploy, so a manual `migrate` may say "Nothing to migrate" — fine):
  `cloud command:run <env> --cmd="php artisan migrate:status" -n` lists the `hone` tables.
- MCP: POST `/mcp` (no token → 401; with `Authorization: Bearer <api-token-or-FALLBACK_TOKEN>` →
  `initialize` returns `serverInfo: Hone`). `tools/list` **paginates** (15 + a `nextCursor`) — all 19
  tools are there.

## Step 5 — Hand off (the source-app test drive)

- Issue the first token: `cloud command:run <env> --cmd="php artisan token:create <app-id>" -n`. It
  prints the plaintext token once and stores only its hash in `api_tokens` — **no env var to set, no
  redeploy**. The same token works for both ingest and MCP.
- In the source app: `composer require laravel/nightwatch artisan-build/hone-client` then
  `php artisan hone:install --url=https://<env-url>/ingest --token=<plaintext>`. Set `NIGHTWATCH_DEPLOY`
  at deploy time. (There is a companion client-side skill in the `hone-client` package.)
- Connect an agent: MCP at `https://<env-url>/<HONE_MCP_PATH>` with `Authorization: Bearer <token>` (any
  `token:create` token or the `FALLBACK_TOKEN`).

## Step 6 — Scale later

Use Hone's MCP tools (`ingest-freshness-tool`, `record-types-tool`) against the live instance, then
`cloud instance:update` (size/replicas), `cloud cache:update` (size), or resize the managed queue.
