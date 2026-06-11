# Laravel Cloud CLI reality (validated on cloud-cli v0.5.0)

What actually works non-interactively, what's bugged, and the workaround — learned standing up the first
real Hone instance. Re-check on newer CLI versions; several of these are fixed in unreleased branches.

## Works fine via CLI (non-interactive)

- `application:create --name … --repository owner/repo --region …` — creates the app **and a default
  environment** (named "main"). Don't create a second env; reuse the default (`application:get … --json`
  → `defaultEnvironmentId`).
- `database-cluster:create --name … --type neon_serverless_postgres_18 --region …` — Neon serverless.
  **No size flag** (CU-based, defaulted; scale later). `--type` value comes from existing clusters
  (`database-cluster:list --json` shows e.g. `neon_serverless_postgres_18`).
- `database:create <cluster-id-or-name> --name hone` — the `<cluster>` is a **positional** arg.
- `cache:create --name … --type upstash_redis --region … --size 250mb --auto-upgrade-enabled=false --is-public=false`
  — **both `--auto-upgrade-enabled` and `--is-public` are required** (the CLI errors one at a time until
  you supply them). `--is-public=false` keeps it private (reachable only from the attached env).
- `managed-queue:create` — the modern queue (replaces deprecated `background-process` workers). Its
  instance shows as `type: managed_queue`. `managed-queue:list` does **not** accept `--json`.
- `instance:update <inst> --uses-scheduler=true --json -n --force` — **works** to flip the scheduler on
  (unlike `instance:create`). Use this to enable the scheduler on the auto-created app instance.
- `environment:variables <env> --action set --key K --value V -n --force` — upserts a single key,
  preserving others (incl. Cloud-injected). Loop it for the `HONE_*` set.
- `command:run <env> --cmd="php artisan …" -n` — runs a **shell** command, so prefix `php artisan`.
  `--cmd="migrate"` runs the shell `migrate` (not found); `--cmd="php artisan migrate --force"` is right.
- `deploy <app> <environment> --no-wait -n` → returns a `deployment_id`; poll
  `deployment:get <id> --json -n` for `status` (`build.running` → `deployment.running` →
  `deployment.succeeded`/`failed`) and `failureReason`. The deploy **auto-runs migrations**.

## Bugged on v0.5.0 → use the dashboard (or an unreleased fix branch)

- **`instance:create` is broken non-interactively.** It never constructs the `InstanceScalingType` enum
  (`Argument #5 ($scalingType) must be of type … string given`) — fails with *and* without
  `--scaling-type`. Fix only in unreleased `fix/instance-create-non-interactive`. → **Create/size the web
  instance in the dashboard.** (`application:create` already makes a default app instance you can just
  resize there.) Then enable the scheduler via `instance:update` (which *does* work).
- **`environment:update --database-id/--cache-id` silently no-op.** They return success but the attach
  doesn't happen. Fix only in unreleased `fix/environment-update-attach-flags`. → **Attach the DB + cache
  to the env in the dashboard.** (A private cache can't be reached with manual connection vars, so attach
  is required, not optional.)

## `environment:get` under-reports — don't trust it to verify state

- `databaseSchemaId`, `cacheId`, and `branch` come back **null even when they're set** (UI confirms
  attached; the deploy shows `branchName=main`). The read endpoint is blind to these fields. **Verify
  functionally** (capabilities 200, ingest auth 401/422, `migrate:status`, MCP `initialize`) — never by
  reading these fields back.

## Deploy gotcha

- A managed queue makes the deploy fail with *"application has a managed queue but is missing
  aws/aws-sdk-php"* unless the app requires `aws/aws-sdk-php` (managed queues are SQS-backed). Ensure it's
  in the Hone app's `composer.json` (it ships with it).
