# Security Policy

Hone sits on a telemetry ingest path and stores application instrumentation. We take
security reports seriously.

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Instead, report privately to **security@artisan.build**. Include:

- A description of the vulnerability and its impact.
- Steps to reproduce, or a proof of concept.
- The affected package(s) and version(s) — `hone-contracts`, `hone-client`, `hone-server`,
  or the Hone app itself.

You will receive an acknowledgement as soon as we are able, and we will keep you informed as
we work on a fix. We ask that you give us a reasonable window to remediate before any public
disclosure.

## Scope

This policy covers the `artisan-build/hone` monorepo and its three split packages
(`hone-contracts`, `hone-client`, `hone-server`). The upstream
[`laravel/nightwatch`](https://github.com/laravel/nightwatch) package has its own security
process; please report Nightwatch issues to the Laravel team.

## Supported versions

Hone is maintained for how [Artisan Build](https://artisan.build) uses it. Security fixes
land on the latest release line.
