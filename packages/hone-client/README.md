# hone-client

The **send side** of [Hone](https://github.com/artisan-build/hone). Install it in a Laravel
app you want to monitor. It redirects that app's own
[`laravel/nightwatch`](https://github.com/laravel/nightwatch) telemetry to your self-hosted
Hone server.

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/hone`](https://github.com/artisan-build/hone) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

## What it does

`hone-client` is thin by design — its only job is to redirect Nightwatch's output to your
Hone app.

- **Rebinds the Nightwatch ingest transport** through a service provider. Nightwatch exposes
  its ingest as a swappable object on the container-bound `Core`; `hone-client` replaces it
  with one that batches records and POSTs them over HTTPS to your Hone server. No fork of
  `laravel/nightwatch`, no daemon, no on-disk buffer.
- **Batches and POSTs** a versioned envelope to `HONE_URL`, authenticating with `HONE_TOKEN`.
- **Fails open.** If the endpoint is unreachable, records are dropped after a bounded
  in-memory buffer. Your app must never block, slow, or error because telemetry shipping
  failed. The POST happens during request termination — after the response is sent.
- **Octane-safe** — no per-request static state leakage.

It does **not** do sampling (that lives in your Nightwatch config) and it adds **no
redaction layer** (see [Privacy](#privacy)).

## Activation — by presence of config, no boolean

The rebind fires **only when both `HONE_URL` and `HONE_TOKEN` are present and non-empty.**

| `HONE_URL` | `HONE_TOKEN` | Behavior |
| --- | --- | --- |
| set | set | **Active** — telemetry ships to Hone. |
| unset | unset | Inert (the normal state for an app that isn't monitored). |
| only one set | | Inert, but **logs a warning** (half-configured). |

The kill switch is removing or commenting the `HONE_*` values.

## Installation

```bash
composer require artisan-build/hone-client
php artisan hone:install
```

`hone:install` wires the app for tracking: it sets `HONE_URL` / `HONE_TOKEN`, pins
`hone-client` at a caret constraint (`^X`), and confirms your Nightwatch setup will activate.
It is idempotent and asks for consent before touching any file.

### Setting up with a coding agent (skill)

This package ships a **`configuring-hone-client`** skill so a skill-aware coding agent (Claude
Code, etc.) can do the whole setup for you. After `composer require`, it lives at:

```
vendor/artisan-build/hone-client/skills/configuring-hone-client/SKILL.md
```

Point your agent at that file (or copy the `configuring-hone-client/` directory into your
project's `.claude/skills/`) and ask it to *"configure the Hone client."* The skill walks
through the prerequisites, `hone:install`, **verifying the Nightwatch transport rebind is
active** (the step that actually proves it's working), setting the `NIGHTWATCH_DEPLOY` deploy
dimension, confirming receipt on the Hone server over MCP, and the common
"telemetry isn't arriving" failure modes.

### Source-app environment

```dotenv
NIGHTWATCH_ENABLED=true                       # Nightwatch collects when enabled (the default)
HONE_URL=https://hone.<client>.example/ingest
HONE_TOKEN=<issued by your Hone app's registry>
NIGHTWATCH_DEPLOY=<commit sha, set at deploy>
```

You do **not** need a `NIGHTWATCH_TOKEN`. Nightwatch's collection is gated by
`NIGHTWATCH_ENABLED` (default `true`), not the token — and Hone never talks to Nightwatch's
cloud, so no real Nightwatch credential is required.

> **On Laravel Cloud:** do **not** enable the built-in Nightwatch toggle — it runs Cloud's
> managed agent and ships to Nightwatch's cloud, which conflicts with the rebind. Install
> `laravel/nightwatch` + `hone-client` and let the rebind own the transport. Set
> `NIGHTWATCH_*_SAMPLE_RATE` explicitly and high — storage is your only cost.

## Keeping in sync — `hone:update`

After any major update, run:

```bash
php artisan hone:update
```

It calls `{HONE_URL}/capabilities`, compares the `hone-contracts` major you have installed
against the majors your Hone server supports, and either gives the all-clear or tells you to
**update your Hone app first.** The canonical upgrade order is always: **update Hone, then
update your apps.**

`hone-client` also emits a one-line local nudge when the installed `hone-contracts` **major**
changes, reminding you to run `hone:update`. It's zero-network — just a reminder.

## Privacy

Hone adds **no** redaction layer; redaction belongs in your Nightwatch config, before
transmission, and `hone-client` ships its recommended defaults **off**:

- **Query bindings are never captured** — SQL arrives parameterized; bound values never enter
  the pipeline.
- **Request-body capture stays off** (the Nightwatch default).
- For apps using raw SQL literals or PII-bearing exception messages, configure Nightwatch's
  `redactQueries` / exception-message hooks.

Because stored telemetry is parameterized and pre-redacted upstream, it is safe to feed to
an LLM. That is the point.

## License

MIT. See [LICENSE](LICENSE).
