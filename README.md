<p align="center">
  <img src="art/icon.png" alt="hone icon" width="128">
</p>

# Hone

**Your Laravel app's performance history, stored in your own database and answered by your coding agent.**

Hone collects the telemetry your Laravel apps already produce — slow requests, slow queries, job
durations, exceptions, cache hit rates — into a Postgres database you own. There is no dashboard.
Instead, Hone exposes the data over [MCP](https://modelcontextprotocol.io) (Model Context Protocol,
the standard way an AI coding agent talks to an external tool), so you can ask Claude Code or a CI
agent:

> **"What should we improve this week?"**

**What it replaces:** the one row of a hosted APM subscription most Laravel teams actually use —
performance history you can query. Storage is your only cost, and nothing leaves your infrastructure.

**What it deliberately is not:** no UI or dashboard, no alerting, no distributed traces or span
waterfalls, no long-term raw history beyond aggregate retention, no in-app multi-tenancy (isolation is
one deployment per client), and no sampling logic — that stays in your source app's Nightwatch
configuration. Those first four are real features of
[Laravel Nightwatch](https://github.com/laravel/nightwatch), Sentry and Datadog. If you use them, keep
paying for them — Nightwatch is the natural upgrade.

Hone is built on `laravel/nightwatch` (MIT, by Taylor Otwell). It **replaces Nightwatch's ingest
transport** so your app's telemetry is posted to *your* Hone server instead. Hone never contacts
Nightwatch's cloud and you do not need a Nightwatch subscription to use it.

---

## The easy way: Scalpels

Running Hone means forking this repository, deploying it to Laravel Cloud with its own compute,
Postgres, Redis and scheduler, and then pointing your apps at it. That is an afternoon of work and a
standing ops commitment.

[**Scalpels**](https://scalpels.app/products/hone) does it for you: it forks Hone into your GitHub
organisation, provisions exactly the resources it needs in **your** Laravel Cloud account, deploys it,
and keeps it updated. The repository and the infrastructure stay yours either way — the only question
is who spends the afternoon.

Everything below is the do-it-yourself path, documented in full, because it is real and complete.

---

## How it fits together

```
  Your app #1 ─┐   hone-client replaces Nightwatch's transport,
  Your app #2 ─┼──▶ batches records, and POSTs them over HTTPS
  Your app #N ─┘
                   │
                   ▼
              POST /ingest      ← bearer credential; returns 202 and queues the batch
                   │
                   ▼
              Redis queue ──▶ worker ──▶ Postgres (raw_events)
                   │
                   ▼
              hourly hone:maintain ──▶ aggregates (daily rollups), then prunes old raw rows
                   │
                   ▼
              POST /mcp  ◀── your coding agent, with a different bearer credential
```

Three ideas are worth naming before you start:

- **Envelope** — the small JSON wrapper each batch travels in. It carries a version number so an old
  app and a newer server can still talk to each other.
- **Credential purpose** — every bearer credential Hone issues is stamped with exactly one job.
  A `hone.ingest` credential may only send telemetry; a `hone.mcp` credential may only read it back
  over MCP. Presenting one where the other is required returns `401`. They are never interchangeable.
- **Installation ref** — the name you give a source app when you mint its ingest credential. Hone tags
  every record with that name, so one Hone deployment can receive telemetry from many apps.

Hone is **single-tenant**: one deployment per client, isolated by environment, serving as many of that
client's apps as you like.

---

## Run it yourself

### Prerequisites

| You need | Why |
| --- | --- |
| PHP 8.3+ (CI runs 8.5) | The app and its packages. |
| Composer 2 | Dependencies. |
| PostgreSQL (CI runs 16) | Telemetry uses `jsonb` columns and Postgres-only functions. MySQL and SQLite will not work. |
| Redis | The queue that drains accepted ingest batches. |

### Local development

Every command below is run from the root of your clone.

**1. Install the dependencies.**

```shell
composer install
```

**2. Create your environment file and an application key.**

```shell
cp .env.example .env
php artisan key:generate
```

You should see `INFO  Application key set successfully.` If `APP_KEY` stays empty, later commands
fail with an encryption error.

**3. Create the databases.** `.env.example` points at a local Postgres database called `hone`, and the
test suites use two more. Create all three:

```shell
psql -h 127.0.0.1 -U root -d postgres -c 'CREATE DATABASE hone;'
psql -h 127.0.0.1 -U root -d postgres -c 'CREATE DATABASE hone_app_test;'
psql -h 127.0.0.1 -U root -d postgres -c 'CREATE DATABASE hone_server_test;'
```

The test database names are fixed — `hone_app_test` in `phpunit.xml`, `hone_server_test` in
`packages/hone-server/tests/TestCase.php` — so use exactly those names.

**4. Run the migrations.**

```shell
php artisan migrate
```

These create two groups of tables in the same database: Hone's telemetry tables (`raw_events`,
`aggregates`, `samples`, `maintenance_markers`) and the credential and ownership tables that
[`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud) owns.

**5. Run the tests.**

```shell
composer test
```

This clears the config cache, checks formatting with Pint, and runs the Pest suite against
`hone_app_test`. All of it should pass before you change anything.

To run the telemetry package's own suite as well:

```shell
cd packages/hone-server && composer install && composer test
```

**6. Start the app and check it answers.**

```shell
php artisan serve --host=127.0.0.1 --port=8787
```

In another terminal:

```shell
curl http://127.0.0.1:8787/capabilities
```

You should see the envelope versions this build speaks:

```json
{"envelope":{"min_major":1,"max_major":1,"supported_majors":[1]}}
```

**7. Send yourself a telemetry record.** First mint an ingest credential. `installation` is the subject
type, `local-demo` is the installation ref (the app name Hone will tag records with), and
`consumption` is the protocol purpose that `hone.ingest` maps to:

```shell
php artisan bfc:credential:mint installation 'local-demo' --kind=bearer --purpose=consumption --name='hone-ingest-local-demo' --local
```

> **`--local` is required and it means "the database of whatever machine this command is running
> on".** Run it on your laptop and it writes to your laptop's database; run it through
> `cloud command:run` and it writes to that Cloud environment's database. Without `--local` the
> command refuses and does nothing. See [Credentials](#credentials) below.

The command prints the plaintext credential **once** and stores only a hash. Copy it, then post a
batch with it:

```shell
curl -X POST http://127.0.0.1:8787/ingest \
  -H "Authorization: Bearer <the credential>" \
  -H 'Content-Type: application/json' \
  -d '{"envelope_version":1,"app":"checkout","deploy":"abc123","sent_at":"2026-09-23T00:00:00Z",
       "records":[{"t":"request","timestamp":"2026-09-23T00:00:00Z","method":"GET","route":"/health","duration":12}]}'
```

You should get `202` and `{"message":"Accepted."}`. Without the header you get `401` and
`{"message":"Unauthorized."}`.

**8. Drain the queue and look at the row.**

```shell
php artisan queue:work redis --once
php artisan hone:rollup
```

`hone:rollup` prints something like `Processed 1 groups from 2026-09-21; upserted 5 aggregate rows.`

> **Note the app name.** The stored record is tagged `local-demo`, **not** the `"app": "checkout"` in
> the envelope. The server always uses the credential's installation ref, so a source app cannot
> claim to be another one.

**9. Check the instance is healthy.**

```shell
php artisan hone:health
```

A table with three checks — `maintenance`, `retention`, `aggregate_freshness` — each `ok`. The command
exits non-zero if any check alarms.

### Deploying to Laravel Cloud

> These steps are written from
> [`.claude/skills/provisioning-hone-on-cloud/SKILL.md`](.claude/skills/provisioning-hone-on-cloud/SKILL.md),
> which was validated against a real first run — they were not re-executed when this README was last
> edited. Read that skill before you start; a skill-aware coding agent can run all of it for you if you
> ask it to *"provision a Hone instance on Laravel Cloud."*

Hone needs five things in one isolated Laravel Cloud environment per client:

| Resource | Notes |
| --- | --- |
| A web instance | Serves `/ingest` and `/mcp`. Turn its **scheduler** on. |
| Postgres | Holds everything. Neon serverless is a good default. |
| Redis (cache resource) | Backs the cache. |
| A managed queue | Drains ingest batches. Managed queues are SQS-backed, which is why this app requires `aws/aws-sdk-php`. |
| The scheduler | A flag on the web instance, not a separate resource. Runs `hone:maintain` and `hone:health` hourly. |

**⚠️ The one rule you must not break: never set an environment variable for a resource Laravel Cloud
provisions.** When you attach a database, cache, queue or bucket, Cloud injects its configuration —
both the credentials (`DB_HOST`, `REDIS_*`, `AWS_*`) **and** the connection selectors
(`DB_CONNECTION`, `CACHE_STORE`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`) — into a managed environment
file the app reads. Anything you set yourself shadows the injected value and breaks that resource. Set
only genuinely app-specific variables, such as the `HONE_*` keys in the table further down.

This bites in one place that is easy to miss: `.env.example` sets `QUEUE_CONNECTION=redis` and
`HONE_QUEUE_CONNECTION=redis`, which is right for local development and wrong on Cloud. **Copy neither
to a Cloud environment.** Cloud injects `QUEUE_CONNECTION` for the managed queue, and leaving
`HONE_QUEUE_CONNECTION` unset makes ingest batches use that injected default.

The order that works:

1. Create the application; Cloud creates a default environment with it.
2. Create the Postgres cluster and a database on it. Create the Redis cache resource.
3. Create the web instance with the scheduler enabled, and a managed queue.
4. **Attach the database and cache to the environment.** On `cloud` CLI v0.5.0 the
   `environment:update --database-id` / `--cache-id` flags are silent no-ops — do this in the Laravel
   Cloud dashboard. The CLI also cannot read attach state back reliably, so verify functionally
   (step 7) rather than trusting `environment:get`.
5. Set the app-specific variables you actually want to change (see the configuration table). Add a
   build step that records the deployed commit so Hone can compare releases:
   ```shell
   echo "NIGHTWATCH_DEPLOY=$(git rev-parse --short HEAD)" >> .env
   ```
   If your build runs from an exported archive with no `.git`, write the SHA your CI provides instead.
6. Deploy. Cloud migrates on deploy; `php artisan migrate --force` afterwards is harmless and may
   report nothing to migrate.
7. Verify it is really up:
   - `curl https://<env-url>/capabilities` → `200` with the envelope JSON.
   - `POST /ingest` with no credential → `401`; with a correct `hone.ingest` credential and an empty
     body → `422` (authentication passed, the envelope failed validation).
   - `POST /mcp` with no credential → `401`.

**Do not enable Laravel Cloud's built-in Nightwatch integration**, on this app or on your source apps.
It runs Cloud's managed agent and ships data to Nightwatch's hosted service, which is exactly what
Hone's transport replacement exists to avoid.

**Two hourly commands run on the scheduler.** `hone:maintain` rolls raw events into daily aggregates
and then prunes what is past retention. `hone:health` checks that maintenance is keeping up and exits
non-zero if it is not. **Nothing collects that exit code.** There is no monitor, alert or pager behind
it — a failing check is visible only to someone who reads the scheduler's Cloud logs. Hone is pull,
not push, and this is the honest consequence.

### Connecting a source app

**1. On the Hone server,** mint that app an ingest credential. Pick an installation ref that names the
app; it becomes the app name in every Hone query.

```shell
php artisan bfc:credential:mint installation '<your-app-name>' --kind=bearer --purpose=consumption --name='hone-ingest-<your-app-name>' --local
```

On Laravel Cloud, run the same command through the CLI so it acts on that environment's database:

```shell
cloud command:run <env> --cmd "php artisan bfc:credential:mint installation '<your-app-name>' --kind=bearer --purpose=consumption --name='hone-ingest-<your-app-name>' --local" -n
```

The plaintext is shown once. Put it straight into the source app's secret environment — never into a
commit, a chat message or a ticket.

**2. In the source Laravel app,** install Nightwatch and the Hone client:

```shell
composer require laravel/nightwatch artisan-build/hone-client
php artisan hone:install
```

`hone:install` asks for your Hone ingest URL and the credential, or takes `--url=` and `--token=`. Give
it the full URL ending in `/ingest`. It writes `HONE_URL` and `HONE_TOKEN` to `.env`, sets
`NIGHTWATCH_ENABLED=true` if it is not already truthy, and pins `artisan-build/hone-client` to a caret
major constraint in `composer.json`. You do **not** need a `NIGHTWATCH_TOKEN`.

Set `NIGHTWATCH_DEPLOY` in the source app at deploy time (the commit SHA is ideal) so Hone can compare
one release against another.

> Using a coding agent in the source app? `hone-client` ships a `configuring-hone-client` skill at
> `vendor/artisan-build/hone-client/skills/configuring-hone-client/SKILL.md` once installed. It covers
> installation, proving the transport replacement is really active, and why telemetry sometimes does
> not arrive. See the [hone-client README](packages/hone-client/README.md#installation).

### Connecting a coding agent (MCP)

The MCP server is mounted at `HONE_MCP_PATH` (default `/mcp`) and needs an
`Authorization: Bearer <credential>` header. Mint a **separate** credential for it:

```shell
php artisan bfc:credential:mint installation '<your-app-name>' --kind=bearer --purpose=mcp --name='hone-mcp-<your-app-name>' --local
```

An ingest credential will not work here, and an MCP credential cannot send telemetry. Requests with no
valid credential get `401`. `GET /bfc/meta` is public and reports the mounted path under
`endpoints.mcp`, along with the `mcp-serve` and `mcp-delegated` capabilities.

Hone also accepts a delegated assertion issued by Scalpels whose signed `purpose` claim is `mcp`. Hone
verifies those assertions; it never issues them.

Point your agent at `https://<your-hone-host>/mcp` with that bearer credential. A quick check by hand:

```shell
curl -X POST https://<your-hone-host>/mcp \
  -H "Authorization: Bearer <the mcp credential>" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize",
       "params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```

A working server answers with `"serverInfo":{"name":"Hone","version":"1.0.0"}`.

---

## Using it

Hone exposes **19 read-only MCP tools**. Nothing writes, and every tool is classified as carrying
customer content, because even a count can be keyed by an app id, route, user id or deploy.

`tools/list` is paginated: the first page returns 15 tools and a `nextCursor`. If your client shows
only 15, it stopped at the first page.

| Group | Tools |
| --- | --- |
| Discovery | `list-apps-tool`, `record-types-tool`, `deploys-tool`, `ingest-freshness-tool` |
| Slow things | `slow_requests`, `slow_queries`, `slow_jobs`, `slow_outgoing_requests` |
| Analysis | `query_metric`, `regression_check`, `exceptions`, `top_users` |
| Volume and health | `cache_stats`, `queue_throughput`, `mail_volume`, `notification_volume`, `scheduled_task_health`, `command_stats`, `log_volume_by_level` |

In practice you ask your agent a question in English and it picks the tool. Calling one directly looks
like this:

```shell
curl -X POST https://<your-hone-host>/mcp \
  -H "Authorization: Bearer <the mcp credential>" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call",
       "params":{"name":"slow_requests","arguments":{}}}'
```

The result text is JSON: the window that was queried, an `offenders` list — each with a
`normalized_key` such as `GET /health`, a `count`, and `avg`/`max`/`p95`/`p99` durations — and an
`aggregate_freshness` block. That last one rides along on every aggregate-backed answer so you can
tell "nothing is slow" apart from "the rollup has not run since Tuesday".

---

## Configuration

Every key below is optional; the defaults are what the code uses when the variable is unset.

### The Hone server

| Variable | Default | What it does |
| --- | --- | --- |
| `HONE_MCP_PATH` | `/mcp` | Where the MCP server is mounted. |
| `HONE_ROUTE_PREFIX` | *(none)* | URL prefix for `/ingest` and `/capabilities`. |
| `HONE_QUEUE_CONNECTION` | *(none)* | Queue connection used for ingest batches. Unset means the app's default connection, which is what you want on Laravel Cloud. `.env.example` sets it to `redis` for local work. |
| `HONE_RETENTION_RAW_HOURS` | `72` | How long individual raw events are kept. |
| `HONE_RETENTION_AGGREGATE_DAYS` | `90` | How long daily aggregates are kept. |
| `HONE_RETENTION_SAMPLE_DAYS` | `7` | How long retained samples are kept. |
| `HONE_ROLLUP_LATE_ARRIVAL_HOURS` | `24` | How far back each hourly rollup re-reads, so a late-arriving event still gets aggregated. **The default is a guess, not a measurement** — size it from your own `created_at − occurred_at` spread. Older ranges are rebuilt with `hone:backfill`. |
| `HONE_MAINTENANCE_OVERLAP_LOCK_MINUTES` | `120` | Releases the `hone:maintain` overlap lock if a run dies without clearing it. |
| `HONE_HEALTH_INGEST_ACTIVE_MINUTES` | `60` | How recently a raw event must have arrived for the retention and freshness checks to alarm at all. A quiet instance is not an unhealthy one. |
| `HONE_HEALTH_MAINTENANCE_MAX_AGE_MINUTES` | `150` | Alarm if `hone:maintain` has not succeeded within this long. |
| `HONE_HEALTH_RETENTION_GRACE_HOURS` | `24` | Added to the raw retention window before the retention check alarms. |
| `HONE_HEALTH_AGGREGATE_MAX_AGE_HOURS` | `6` | Alarm if the newest aggregate bucket is older than this. |
| `NIGHTWATCH_DEPLOY` | *(none)* | The deploy dimension for this environment. |
| `BUILT_FOR_CLOUD_CREDENTIAL_GUARD` | `bfc` | The auth guard credentials resolve through. Leave it alone. |

**Telemetry shares the application's own database by default.** With every `HONE_DB_*` variable unset,
Hone's `hone` connection resolves to your default connection — fork, deploy, one Postgres, nothing to
configure. Set any one of `HONE_DB_URL`, `HONE_DB_HOST`, `HONE_DB_PORT`, `HONE_DB_DATABASE`,
`HONE_DB_USERNAME` or `HONE_DB_PASSWORD` to move telemetry onto a separate Postgres database; whatever
you leave unset is inherited from the app connection, so overriding only `HONE_DB_DATABASE` moves
telemetry to another database on the same server. Both connections must be Postgres.

On Laravel Cloud, remember that `DB_*` is injected by Cloud, so you set none of it yourself.

### A source app

| Variable | Default | What it does |
| --- | --- | --- |
| `HONE_URL` | *(none)* | Your Hone server's ingest URL, ending in `/ingest`. |
| `HONE_TOKEN` | *(none)* | The app's `hone.ingest` credential. |
| `NIGHTWATCH_ENABLED` | Nightwatch's own default | Nightwatch's collection switch. `hone:install` sets it to `true` if it is not already truthy. |
| `NIGHTWATCH_DEPLOY` | *(none)* | The deploy identifier, usually a commit SHA. |
| `HONE_BUFFER` | `500` | Records held in memory before the oldest are dropped. Hone drops rather than posting mid-request. |
| `HONE_CONNECT_TIMEOUT` | `0.5` | Connect timeout in seconds. |
| `HONE_TIMEOUT` | `0.5` | Request timeout in seconds. |

The client turns itself on when **both** `HONE_URL` and `HONE_TOKEN` are set — there is no separate
enable flag. Set exactly one of them and it stays off and logs
`Hone is half-configured: set both HONE_URL and HONE_TOKEN, or neither.` A non-HTTPS `HONE_URL` also
logs a warning, because the credential would travel in plaintext.

---

## Credentials

Credentials are managed by [`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud)
(v0.16.0 is the version this app locks). They live in a `credentials` table; bearer secrets are stored
as SHA-256 digests, so a lost credential is rotated, never recovered.

All of these commands **require `--local`**, which means "act directly on the database of the machine
running this command". They never reach out to Laravel Cloud on their own; to act on a deployed
environment, run them through `cloud command:run <env> --cmd "…"`.

```shell
php artisan bfc:credential:list --local
php artisan bfc:credential:rotate <id> --local
php artisan bfc:credential:revoke <id> --local
```

`rotate` mints the replacement before retiring the old one and gives bearer credentials a one-hour
grace window, so you can deploy the new value without dropping telemetry.

There is no environment-variable fallback on the server: if a credential is not in the table, the
request is refused.

---

## Compatibility and upgrades

With many independently-deployed source apps and one server you control, version skew is normal. The
envelope only ever grows within a major version — new fields are optional, existing ones are never
removed or repurposed — so a newer server parses every older envelope. The one dangerous direction is a
sender running ahead of the server, and that fails loudly with a `422` telling you to upgrade Hone.
Nightwatch's own record bodies are stored as opaque `jsonb`, so a change in their shape costs a rollup
a field and never breaks ingest.

**Always upgrade the Hone server first, run its migrations, then update the clients.** A source app can
check where it stands at any time:

```shell
php artisan hone:update
```

It reads `{HONE_URL}/capabilities` (stripping a trailing `/ingest`) and tells you whether your client's
envelope major is inside the server's supported range.

---

## Troubleshooting

**`401` on `/ingest` with a credential that should work.** The credential is missing, revoked, expired,
or has the wrong purpose. Only an `installation`-subject bearer credential whose purpose is
`consumption` is accepted. Check with `php artisan bfc:credential:list --local`.

**`401` on `/mcp` with the ingest credential.** Expected — purposes are not interchangeable. Mint a
second credential with `--purpose=mcp`.

**`422` on `/ingest`.** Authentication passed; the envelope did not. The response body says why, for
example `Envelope is missing a numeric "envelope_version".` or
`Envelope v2 is newer than this Hone server (max v1). Upgrade your Hone app.`

**No telemetry arrives at all from a source app.** The client only activates when **both** `HONE_URL`
and `HONE_TOKEN` are set. With one of them missing it logs `Hone is half-configured: set both HONE_URL
and HONE_TOKEN, or neither.` and sends nothing.

**`202` but no rows in `raw_events`.** The batch was accepted and queued. Something has to drain the
queue — a worker on the connection named by `HONE_QUEUE_CONNECTION`. Locally, `php artisan queue:work
redis --once` is the quickest way to confirm.

**Rows in `raw_events` but MCP tools return nothing.** Most tools read the daily aggregates, not raw
rows. Run `php artisan hone:rollup` (the scheduler does this hourly via `hone:maintain`), then check
`aggregate_freshness` in any tool's response.

**`hone:health` exits non-zero.** Read its table, or `php artisan hone:health --json`. `maintenance`
means the hourly run has stopped succeeding; `retention` means raw events are older than they should
be, which usually follows from the first; `aggregate_freshness` means the newest daily bucket is stale.

**A gap in aggregates after an outage.** `php artisan hone:backfill <from> <to>` rebuilds an explicit
UTC date range one day at a time and resumes from its own checkpoint. `--restart` ignores the
checkpoint and starts the range again.

**The app name in Hone is wrong.** It comes from the credential's installation ref, not from the source
app's config. Mint a new credential with the ref you want.

**Tests fail to connect.** The suites use fixed database names on `127.0.0.1:5432` as user `root`:
`hone_app_test` for the app suite and `hone_server_test` for the `hone-server` package suite.

---

## Repository layout

This is a monorepo. Three packages live under `packages/` and are split read-only to their own
repositories and published to Packagist:

| Package | Installed in | Role |
| --- | --- | --- |
| [`artisan-build/hone-contracts`](https://github.com/artisan-build/hone-contracts) | both packages | The versioned wire envelope. The one place compatibility lives. |
| [`artisan-build/hone-client`](https://github.com/artisan-build/hone-client) | your monitored apps | The send side: transport replacement, batching, POST, install and update commands. |
| [`artisan-build/hone-server`](https://github.com/artisan-build/hone-server) | the Hone app | The receive side: ingest, storage, rollups, prune, MCP server. |

The Hone app at this repository's root is a slim Laravel shell that wires `hone-server` together with
Built for Cloud's credential handling. There is deliberately no Hone-specific business logic in it.

**Contributing.** Issues and pull requests are disabled on the three split repositories, the same way
Laravel's own `illuminate/*` splits work. All development happens here. See
[`SECURITY.md`](SECURITY.md) for private vulnerability disclosure — Hone sits on an ingest path.

Hone is written for how [Artisan Build](https://artisan.build) uses it. Bugs get fixed.
Client-specific features stay in client forks and are not backfilled into the open-source release.

---

## Privacy

Hone is meant to be safe to hand to an LLM when your source apps use Nightwatch's normal redaction
configuration, which runs **before** anything is sent to Hone. Query bindings are not part of Hone's
normalized query key and Hone adds no second capture path for them. MCP tools summarise by normalized
keys — route, SQL shape, exception class and location, log level, user id, cache `store:type` — rather
than returning raw request bodies or arbitrary event payloads.

## License

Hone is open-source software licensed under the [MIT license](LICENSE).
