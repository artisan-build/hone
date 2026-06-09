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
| `artisan-build/hone-server` | read-only split | the Hone app | The receive side: ingest, storage, rollups, prune, and the MCP server. |

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
