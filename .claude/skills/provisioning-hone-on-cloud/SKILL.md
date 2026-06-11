---
name: provisioning-hone-on-cloud
description: "Stand up a self-hosted Hone telemetry instance on Laravel Cloud with the `cloud` CLI. Gathers expected volume as a small/medium/large tier, recommends and — only after confirmation — provisions the matching resources (Postgres, Redis, a web instance, a queue worker, the scheduler), wires HONE_* config, runs migrations, deploys, and issues the first source-app token. Use when the user wants to deploy, set up, or provision a Hone instance / server / environment on Laravel Cloud, stand up Hone telemetry infrastructure, or onboard a new client's Hone environment."
---

# Provisioning Hone on Laravel Cloud

Stands up **one isolated Hone environment** (one client) on Laravel Cloud. Hone is single-tenant —
isolation is per-environment — so repeat this per client. One environment can ingest from many source
apps, so you do NOT provision per source app.

This skill **specializes** the generic `deploying-laravel-cloud` skill (shipped by the Cloud CLI). It
adds Hone's fixed topology, the `HONE_*` config, and a tier-based sizing recommender. The generic
skill's rules still apply and are non-negotiable:

- **Discover options at runtime** — `cloud <cmd> -h`, `cloud instance:sizes --json -n`,
  `cloud cache:types --json -n`. Never hardcode size names; the catalog changes.
- **Flags** — add `-n` to every command; `--json` on reads/creates; `--force` on updates and
  variable sets. Never `-q`/`--silent`.
- **Confirm before any `:create`/`:delete`** — these are billable. Show the command, wait for approval.
- **Delegate high-output** commands (`deploy:monitor`, `:list`, `deployment:get`) to a subagent so the
  main context stays small.

## Prerequisites (check, don't assume)

1. `cloud` CLI installed and authenticated — verify with `cloud usage --json -n`. If missing:
   `composer global require laravel/cloud-cli && cloud auth -n`.
2. You are in / targeting the **Hone app** (`artisan-build/hone`) — that repo is the deployable.
3. Know the target Cloud **organization**, **region**, and the **app repository** (`owner/repo`).
4. Pick the source app(s) you'll point at this instance for the test drive (separate repos).

## What gets provisioned (the Hone topology)

| Resource | Cloud command | Notes |
| --- | --- | --- |
| Application | `application:create` | One per client deployment (skip if it already exists). |
| Environment | `environment:create` | One isolated env per client. |
| Postgres cluster + schema | `database-cluster:create --type postgres` → `database:create` | Holds `raw_events`/`aggregates`/`samples` on the `hone` connection. |
| Redis | `cache:create --type upstash_redis` | Backs both the cache and the queue. |
| Web instance | `instance:create --type app … --uses-scheduler=true` | Serves `/ingest` + the MCP endpoint. **The scheduler is this flag** (runs `hone:maintain` hourly) — not a separate resource. |
| Queue worker | `background-process:create --type worker --connection redis` | Drains `ProcessTelemetryBatch`. |

**Do NOT enable Cloud's built-in Nightwatch integration on this app.** Hone uses Nightwatch
instrumentation in the *source* apps but never transmits to Nightwatch Cloud; the toggle would conflict.

## Step 1 — Pick a tier

Ask the user for **Small / Medium / Large**. Capacity hints + the exact resource set per tier are in
[reference/resource-plan.md](reference/resource-plan.md). These are **starting points** — Cloud
autoscaling and `:update` make scaling trivial once real load is visible, so bias toward the smaller
tier that plausibly fits. First run `cloud instance:sizes --json -n` and `cloud cache:types --json -n`
and map the tier to **currently-available** sizes in the target region.

## Step 2 — Recommend + confirm (REQUIRED gate)

Present, and **wait for explicit approval before creating anything**:
- the resolved resource list for the tier (concrete size names, region),
- a rough monthly cost (derive from the size catalog / `cloud usage --json -n`),
- the ordered command list you're about to run.

Never provision unprompted. If the user hasn't given volume info, ask — don't guess a tier.

## Step 3 — Provision (ordered)

Follow [reference/resource-plan.md](reference/resource-plan.md) exactly. Capture each resource's
`id`/`connection` from its `--json` output to feed the next step. The order:

app → environment → Postgres cluster → database → Redis cache → web instance (`--uses-scheduler=true`)
→ worker → environment variables → deploy → migrate → first token.

Several integration details (how the DB/cache attach to the env, whether Cloud auto-injects `DB_*` /
`REDIS_*`, the exact `database:create` args) are marked **VERIFY AT RUNTIME** in the reference — check
with `-h`/`:get --json -n` rather than assuming.

## Step 4 — Verify + hand off

- `cloud deploy:monitor -n` (delegate to a subagent) — confirm the deploy is green.
- Confirm migrations ran: `cloud command:run <env> "migrate:status" -n` lists the `hone` tables.
- Issue the first source-app token: `cloud command:run <env> "hone:issue-token <app-id>" -n`. It prints
  the plaintext token once + the `HONE_APP_TOKENS=` entry — append that to the env's `HONE_APP_TOKENS`
  and redeploy.
- Print the **source-app enable steps** (the test drive), to run in the repo being monitored:
  ```sh
  composer require laravel/nightwatch artisan-build/hone-client
  php artisan hone:install   # Hone ingest URL = https://<env-url>/ingest  +  the plaintext token
  ```
  Set `NIGHTWATCH_DEPLOY` in the source app at deploy time so Hone can compare releases.
- Connect a coding agent: MCP at `https://<env-url>/<HONE_MCP_PATH>` with
  `Authorization: Bearer <HONE_MCP_TOKEN>`.

## Step 5 — Scale later (after the test drive)

Use Hone's own MCP tools (ingest freshness, volume by record type) against the live instance to see real
load, then `cloud instance:update` (size/replicas), `cloud cache:update` (size), or raise the worker
`--processes`. Bump a whole tier up if a dimension is consistently saturated.
