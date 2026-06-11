# Hone resource plan — tiers, sizing, command sequence, env vars

All size names below are **examples from the live catalog at authoring time** — always re-resolve them
with `cloud instance:sizes --json -n` and `cloud cache:types --json -n` for the target region, because
the catalog changes. The tiers are deliberately coarse; calibrate after the first real test drive.

## Tier presets

| Tier | Web instance | Autoscale | Worker `--processes` | Redis (`upstash_redis`) | Postgres disk target | Rough capacity |
| --- | --- | --- | --- | --- | --- | --- |
| **Small** | `flex-512mb` (or `flex-1gb`) | none — 1 replica | `1` | `250mb` | smallest cluster | kicking the tires; 1–2 low-traffic source apps; **≲ 1M events/day** |
| **Medium** | `flex-2gb` | custom, `1`–`3` | `2` | `1gb` | size for ~30 GB raw | a handful of production apps; **~1–10M events/day** |
| **Large** | `flex-4gb`+ | custom, `2`–`5` | `4`+ | `2.5gb`+ | size for ~150 GB+ raw | many / high-traffic apps; **~10–50M+ events/day** |

"Events" = telemetry records, ~1 stored `raw_events` row each. Bias to the smaller tier that plausibly
fits — scaling up later is a one-line `:update`.

### Storage sizing (the dimension that actually drives Postgres)

`raw_events` dominate and are retained `HONE_RETENTION_RAW_HOURS` (default 72h = 3 days):

```
raw_disk  ≈  events/day  ×  (HONE_RETENTION_RAW_HOURS / 24)  ×  ~1 KB/event
```

e.g. 10M events/day × 3 days × 1 KB ≈ **~30 GB**. Add modest headroom for `aggregates` (rolled up daily,
retained 90 days) and `samples` (7 days), plus index overhead — round up generously. If the user raises
`HONE_RETENTION_RAW_HOURS`, raw disk scales linearly.

## Provisioning sequence

Run `cloud <cmd> -h` before each unfamiliar command. `<...>` are values captured from prior `--json`
output. Pick `<size>` / `<redis-size>` / `<N>` from the chosen tier after resolving them against the live
catalog. Use a consistent `<region>` for every resource.

```sh
# 0. Discover (don't hardcode)
cloud instance:sizes --json -n
cloud cache:types   --json -n

# 1. Application (skip if it already exists — check `cloud app:list --json -n`)
cloud application:create --name hone-<client> --repository artisan-build/hone --region <region> --json -n
#    capture: application id

# 2. Environment (one isolated env per client)
cloud environment:create <app-id> --name production --branch main --json -n
#    capture: environment id, url

# 3. Postgres cluster + schema
cloud database-cluster:create --name hone-<client> --type postgres --region <region> --json -n
#    capture: cluster id, connection (host/port/user/password/database)
cloud database:create <cluster-id> --name hone --json -n          # VERIFY AT RUNTIME: db:create arg/option names via -h

# 4. Redis cache (also the queue backend)
cloud cache:create --name hone-<client> --type upstash_redis --region <region> --size <redis-size> --json -n
#    capture: cache id, connection

# 5. Web instance (+ scheduler). Medium/Large add the autoscaling flags.
cloud instance:create <env-id> --type app --size <size> --uses-scheduler=true --json -n
#    Medium/Large also: --scaling-type custom --min-replicas <min> --max-replicas <max>
#    capture: instance id

# 6. Queue worker — drains ProcessTelemetryBatch off Redis
cloud background-process:create <instance-id> --type worker --connection redis --queue default --processes <N> --json -n

# 7. Attach DB + cache to the environment.
#    VERIFY AT RUNTIME: attachment may happen automatically at :create, or need an environment:update /
#    a flag. Check `cloud environment:get <env-id> --json -n` for databaseSchemaId / cacheId; if unset,
#    consult `cloud environment:update -h` and `cloud database:create -h` / `cloud cache:create -h`.

# 8. Environment variables (see checklist below). Either many --action set calls or one file:
cloud environment:variables <env-id> --action set --key HONE_MCP_TOKEN --value <generated> -n --force
#    ...repeat per var, or pass a prepared env file per `cloud environment:variables -h`.

# 9. Deploy + monitor (delegate monitor to a subagent)
cloud deploy hone-<client> production -n --open
cloud deploy:monitor -n

# 10. Migrate
cloud command:run <env-id> "migrate --force" -n

# 11. First source-app token (prints plaintext once + the HONE_APP_TOKENS= entry)
cloud command:run <env-id> "hone:issue-token <source-app-id>" -n
#    append the printed entry to HONE_APP_TOKENS (step 8) and redeploy
```

## Environment variable checklist

Mirrors the README's "Required production environment". Cloud may auto-inject `DB_*` and `REDIS_*` when a
database/cache is attached — **VERIFY AT RUNTIME** with `cloud environment:get <env-id> --json -n`; if it
does, set the `HONE_DB_*` values to match the injected `DB_*` rather than re-entering them.

| Key | Value |
| --- | --- |
| `APP_KEY` | generate (`base64:…`) — or let the deploy/build generate it |
| `APP_ENV` | `production` |
| `APP_URL` | `https://<env-url>` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | from the Postgres cluster `connection` (or Cloud-injected) |
| `HONE_DB_HOST` / `HONE_DB_PORT` / `HONE_DB_DATABASE` / `HONE_DB_USERNAME` / `HONE_DB_PASSWORD` | same values as the `DB_*` above |
| `QUEUE_CONNECTION` | `redis` |
| `HONE_QUEUE_CONNECTION` | `redis` |
| `HONE_APP_TOKENS` | `app-id=sha256hash` entries (from `hone:issue-token`); may start empty, fill after step 11 |
| `HONE_MCP_TOKEN` | a strong random secret (the agent's bearer token) |
| `HONE_MCP_PATH` | `/mcp` (default) |
| `HONE_RETENTION_RAW_HOURS` | `72` (raising this raises Postgres disk linearly) |
| `HONE_RETENTION_AGGREGATE_DAYS` | `90` |
| `HONE_RETENTION_SAMPLE_DAYS` | `7` |
| `NIGHTWATCH_DEPLOY` | the deployed short commit SHA — set via a build step (`echo "NIGHTWATCH_DEPLOY=$(git rev-parse --short HEAD)" >> .env`) |

Do **not** set a `NIGHTWATCH_TOKEN` (Hone doesn't use one) and do **not** enable Cloud's Nightwatch
integration on this app.

## Gotchas to confirm on the first real run

- **Region consistency** — app, database cluster, cache, and instance should share a region for low
  latency and to avoid cross-region surprises.
- **DB/cache attachment + env injection** — see step 7 and the env note above; this is the most likely
  spot to need a `-h` check on the live CLI.
- **Redis as the queue** — `background-process --connection redis` assumes `QUEUE_CONNECTION=redis`
  resolves to the attached cache. If Cloud models the queue as a separate `managed-queue` instead,
  switch to `managed-queue:create` + point the worker/`HONE_QUEUE_CONNECTION` at it.
- **Worker count vs `processes`** — start at the tier's `--processes`; if `ingest_freshness` (an MCP
  tool) shows the queue lagging, raise it before raising instance size.
