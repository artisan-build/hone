---
name: configuring-hone-client
description: "Configure a Laravel app to send its laravel/nightwatch telemetry to a self-hosted Hone instance via artisan-build/hone-client. Use when enabling/installing/wiring Hone telemetry in a source app, pointing an app at a Hone ingest URL, running hone:install, or troubleshooting why telemetry isn't reaching Hone. Triggers on 'send telemetry to Hone', 'enable Hone in this app', 'install hone-client', 'configure the Hone client', 'point this app at our Hone server'."
---

# Configuring the Hone client in a source app

`artisan-build/hone-client` rebinds `laravel/nightwatch`'s transport so **this app's** telemetry is sent to
your self-hosted Hone server instead of Nightwatch's cloud. This is per-app: you need the Hone **ingest
URL** and a **token** for this app (issued on the Hone server with `php artisan hone:issue-token <app-id>`).

## Prerequisites

- PHP **^8.3**; Laravel **11, 12, or 13** (`hone-client` supports `illuminate ^11|^12|^13`).
- The Hone **ingest URL** (ends in `/ingest`, e.g. `https://<your-hone>/ingest`) and the **plaintext token**
  the Hone server printed for this app. (No `NIGHTWATCH_TOKEN` is used — Hone doesn't need one.)

## Steps

1. **Install** (both are on Packagist):
   ```sh
   composer require laravel/nightwatch artisan-build/hone-client
   ```
2. **Configure** — prefer the installer (accepts flags non-interactively):
   ```sh
   php artisan hone:install --url=https://<your-hone>/ingest --token=<plaintext-token>
   php artisan config:clear
   ```
   It writes `HONE_URL`, `HONE_TOKEN`, and a truthy `NIGHTWATCH_ENABLED=true` to `.env`, and pins
   `hone-client` to a caret major in `composer.json`. (Equivalent manual `.env`: set `HONE_URL`,
   `HONE_TOKEN`, `NIGHTWATCH_ENABLED=true`.)
3. **Verify the rebind is active** (the whole mechanism — do this, don't assume):
   ```sh
   php artisan tinker --execute="echo get_class(app(\Laravel\Nightwatch\Core::class)->ingest);"
   ```
   Expect **`ArtisanBuild\HoneClient\HoneIngest`** (Hone's transport), not Nightwatch's default.
4. **Set the deploy dimension** — at deploy time set `NIGHTWATCH_DEPLOY` to the short commit SHA
   (`echo "NIGHTWATCH_DEPLOY=$(git rev-parse --short HEAD)" >> .env`) so Hone can compare releases.
5. **Confirm receipt on the Hone side.** Generate traffic (serve a few requests / run a few `php artisan`
   commands — telemetry flushes on request/command **termination**), then query Hone over MCP: the
   `list-apps-tool` / `ingest-freshness-tool` should show this app reporting.

## How it works (so you debug the right layer)

Nightwatch instruments requests, queries, jobs, exceptions, etc. `hone-client` buffers those records and
POSTs them as a versioned envelope to `HONE_URL` with `Authorization: Bearer HONE_TOKEN`, on request/command
termination (after the response is sent). It is **fail-open**: any transport error is swallowed and the
buffer dropped — Hone never throws into or slows the host app. So the success signal on this side is
**rebind active + traffic generated + no behavior change**, and the proof is **server-side receipt**, not a
client-side log.

## Upgrading

```sh
php artisan hone:update
```
Derives the server's `/capabilities`, reports whether this client's envelope major is in the server's
supported range. Rule: upgrade the **Hone server first**, then bump clients.

## Troubleshooting "telemetry isn't arriving"

- **`HONE_URL`** must end in `/ingest`; **`NIGHTWATCH_ENABLED`** must be truthy; run `php artisan config:clear`.
- **Rebind not active** (step 3 prints Nightwatch's class): confirm both packages installed and
  `HONE_URL`+`HONE_TOKEN` are both set (the provider stays inert if only one is set, and warns).
- **Server returns 401**: this app's token isn't in the server's `HONE_APP_TOKENS` — re-issue on the
  server and redeploy it.
- Don't wait for client-side errors — Hone is silent by design. Verify the rebind here and receipt on the
  server (MCP `list-apps`/`ingest-freshness`, or the server's `raw_events`).

## Privacy

Hone is safe to feed an LLM when this app uses Nightwatch's normal **redaction** config — redaction happens
here, before records are sent. Keep it configured for sensitive fields.
