# Second ingest application (operator notes)

The primary PHP application stays bound in `.cloud/config.json`. Do not replace that file with
the second application's ID. These notes describe the approved internal dual-ingest rollout;
customer provisioning stays PHP-only until the Rust runtime is generally available on Cloud.
This file contains placeholders only. It is not an executable provisioning script or permission
to create resources, deploy, activate discovery, or run production traffic or load tests.

## Application and resource boundaries

Create a second Laravel Cloud APPLICATION, conceptually `hone-ingest`, from the same
`artisan-build/hone` repository. Its `root_directory=ingest`; it is not a second PHP environment.
The Cargo package must already be present on the selected remote branch before creation.
Both `root_directory` and region are create-time-only. Create the Rust application in the same
region as the primary PHP application and its existing database/schema. Verify Cargo
auto-detection and the generated `cargo build --release` build command before
the first deploy. Stop if Cloud detects PHP instead.

Attach the EXISTING PHP production database schema to the Rust environment. Do not create a
second schema or copy tables. Cloud injects `DATABASE_URL` for Rust and `DB_*` for PHP; never
set or copy those values manually. Cloud also injects `APP_URL`; do not set it manually in
either environment. Do not sync a local `.env` file to Cloud. The Rust binary reads
`DATABASE_URL` and binds `[::]:$PORT`, with Cloud supplying `PORT`.

Rust has no managed queue, cache resource, scheduler, or Octane. Do not try to attach a managed
queue to it. Disable scale-to-zero on the Rust web instance. All management, MCP, queue work,
the scheduler, and migrations remain on PHP. Leave the PHP instance size, replicas,
hibernation, queue, and scheduler unchanged.

Optional Rust tuning already has defaults: `HONE_RUST_CRED_TTL_SECONDS=60` (`0` disables
credential caching) and `HONE_RUST_DB_POOL=10`. Any approved environment-variable overrides
must be in place before the first deploy; changes take effect only on a subsequent deploy.
Never put resource credentials in manual overrides or in this repository.

## Target selection once two applications exist

Record and independently verify this mapping before any write:

| Target | Application ID | Environment ID | Other resource |
| --- | --- | --- | --- |
| Primary PHP | `<php-application-id>` | `<php-environment-id>` | `<existing-schema-id>` |
| Rust ingest | `<rust-application-id>` | `<rust-environment-id>` | the same `<existing-schema-id>` |

Every subsequent CLI call must explicitly identify its application and environment by ID
where applicable, including deploys, resource attachments, variable changes, commands, and
logs. Never rely on the repository binding, default environment, or repository-only lookup.
An unknown environment ID in a write can target the organization's default environment.
Read back and confirm the IDs before writing. Consult the installed CLI's help for argument
positions; `environment:logs` requires the application argument as well as the environment.
Do not omit it when reading either application's logs.

## Deployment ordering and shared-schema compatibility

Normal ordering is PHP/schema owner first (migrations), Rust second. Both applications share
one schema. Rust never runs migrations.

For schema changes touching any table Rust reads or writes (at minimum `raw_events` or
`credentials`), first ship a Rust build tolerant of both the old and new schema while the old
schema is live, then deploy the PHP migration, then deploy any final Rust cleanup only after
the migration is complete. This includes Rust's credential audit and ingest counter tables.
Do not drop, rename, or make incompatible column changes while old Rust replicas may still
run. Keep the transition additive until those replicas have drained and compatibility is
verified. A successful new replica alone does not prove that old replicas are gone.

## Unadvertised observation period

1. Deploy PHP and finish its migrations, then create/deploy/probe Rust unadvertised. Keep PHP
   `HONE_INGEST_URL` unset throughout this rollout. `/capabilities` must still advertise the
   named PHP ingest route. No client configuration changes are part of this work.
2. Check Rust's same-origin `/up` directly, without authentication or redirects; require
   exact HTTP 200. `hone:health` checks only the currently advertised target, so it continues
   to probe PHP while Rust is unadvertised. A healthy `/up` is reachability, not persistence
   parity or a database-health assertion.
3. Use synthetic envelopes only, under a dedicated synthetic ingest credential managed
   through the existing PHP credential API. Handle the secret outside the repository and
   outside disk, for example in the operator's macOS Keychain and process memory. Never use
   a real source-app token, a shell argument/history entry, a temporary credential file, or
   Cloud command output to carry the secret.
4. Verify the persisted parity rows for the synthetic installation, including the expected
   normalized values and counters. An HTTP 202 alone is insufficient. Remove the synthetic
   rows after verification using that installation's identifiers; do not touch real rows.
5. Observe synthetic probes for one day. Leave PHP `HONE_INGEST_URL` unset afterward too:
   advertising Rust requires a separately authorized activation after the observation period.
   Do not use this runbook to switch real traffic or conduct load tests.

## Scheduled health output

The existing hourly PHP schedule is unchanged: `hone:maintain`, then `hone:health`, each with
its own overlap lock. Health now includes `checks.ingest_target` alongside maintenance,
retention, and aggregate freshness. It makes one command-only unauthenticated GET to the
advertised origin's `/up`, with redirects disabled, and requires exact HTTP 200. Invalid
absolute HTTP(S) URLs, userinfo, connection failures/timeouts, and every other status alarm
and cause a nonzero command exit. MCP reporting and client delivery do not run this probe.

The optional PHP overrides are `HONE_HEALTH_INGEST_CONNECT_TIMEOUT` (default `1` second) and
`HONE_HEALTH_INGEST_TIMEOUT` (default `3` seconds total). Both clamp to at least `0.05` seconds
so zero cannot disable the timeout. Output includes only the sanitized `/up` URL and status;
it omits credentials, queries, fragments, response bodies, and exception messages.

Nonzero scheduled exits are visible in scheduler logs. No monitor or pager consumes them.
