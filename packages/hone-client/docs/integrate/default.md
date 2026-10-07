# Integrate Hone into a Laravel application

## Install

Require the published client package:

```bash
composer require artisan-build/hone-client
```

The package requires PHP `^8.3` and Illuminate `^13.0`, so install it in a Laravel 13 application. Laravel auto-discovers `ArtisanBuild\HoneClient\HoneClientServiceProvider`; do not register the provider manually.

The provider merges the package configuration automatically. Publish it only when the application needs to change package defaults in a tracked config file:

```bash
php artisan vendor:publish --tag=hone-config
```

Do not run `hone:install` until the credential workflow below has placed the secret without exposing it.

## Configure

Set both `HONE_URL` and `HONE_TOKEN` to activate the Nightwatch transport rebind. If neither is set, the package is inert. If only one is set, the package remains inert and logs a warning.

| Environment key | Purpose | Source/default |
| --- | --- | --- |
| `HONE_URL` | Full HTTPS endpoint that receives telemetry envelopes, normally ending in `/ingest`. | Obtain from the customer's Hone deployment. Scalpels writes it for hosted connections. No default. |
| `HONE_TOKEN` | Bearer credential for ingest. Treat it as a secret. | Obtain through `connect_site` or issue it on the customer's Hone deployment as described below. No default. |
| `HONE_APP` | Source application identifier written into every envelope. The current Hone receiver attributes stored events to the app ID resolved from `HONE_TOKEN`. | Set to a stable source identifier. Falls back to `APP_NAME`, then `laravel`. |
| `APP_NAME` | Fallback source application identifier when `HONE_APP` is absent. | Existing Laravel application setting; fallback is `laravel`. |
| `NIGHTWATCH_DEPLOY` | Optional deploy identifier written into every envelope for release comparison. | Set at deploy time, normally to the deployed commit SHA. Defaults to `null`. |
| `HONE_BUFFER` | Maximum records held in memory. Console processes send when this limit is reached; web requests discard and count the oldest records on overflow. | `500`. |
| `HONE_FLUSH_INTERVAL` | Maximum age in seconds of the oldest console record before the next write sends the buffer. No timer or signal is installed. | `60`. |
| `HONE_CONNECT_TIMEOUT` | Web HTTP connection timeout in seconds and fallback for the console timeout when explicitly set. | `0.5`; values below `0.05` are clamped to `0.05`. |
| `HONE_TIMEOUT` | Web total HTTP request timeout in seconds and fallback for the console timeout when explicitly set. | `0.5`; values below `0.05` are clamped to `0.05`. |
| `HONE_CONSOLE_CONNECT_TIMEOUT` | Console HTTP connection timeout in seconds. | `2.0`, or explicit `HONE_CONNECT_TIMEOUT` when set. |
| `HONE_CONSOLE_TIMEOUT` | Console total HTTP request timeout in seconds. | `5.0`, or explicit `HONE_TIMEOUT` when set. |
| `NIGHTWATCH_ENABLED` | Enables Nightwatch collection. The installer preserves a truthy value or writes `true`. | Set to `true` unless the application intentionally disables telemetry. |

Use HTTPS. The client only warns for a non-HTTPS `HONE_URL`; it does not refuse to send the bearer token.

## Get a credential

For a source app hosted on Laravel Cloud or Forge, use Scalpels' `connect_site(team, host, target, provider_deployment)` with the opaque handles returned by Scalpels' site and deployment listing tools. Scalpels writes the Hone URL and credential into the target environment and never returns the plaintext credential. Follow the branch, pull request, and next steps returned by `connect_site`.

For any other host, obtain the credential from the customer's own Hone deployment. Hone has no credential UI; ask an authorized operator to run this command on that deployment:

```bash
php artisan bfc:credential:mint installation '<source-installation-ref>' --kind=bearer --purpose=consumption --name='hone-ingest-<app-id>' --local
```

Run that command inside the intended Hone deployment; `--local` prevents Built for Cloud from selecting another remote environment. The command reveals the credential once and stores only its hash. Have the operator place it directly into the source app's secret environment as `HONE_TOKEN`, or enter it into the hidden token prompt from `php artisan hone:install`. Do not pass the token through chat, commit it, include it in a command argument, or return it from a tool. Never print it while verifying the integration.

If Scalpels did not configure the source app, let the operator run the interactive installer after obtaining the URL and token:

```bash
php artisan hone:install
php artisan config:clear
```

The installer writes `HONE_URL`, `HONE_TOKEN`, and a truthy `NIGHTWATCH_ENABLED`, and pins `artisan-build/hone-client` to its installed caret major.

## Call sites

Do not add Hone calls to application code. Nightwatch instruments the application and calls Hone's replacement `Laravel\Nightwatch\Contracts\Ingest` transport. The transport accepts opaque Nightwatch records; each record is an `array<string, mixed>` with its own `t` discriminator. Every public transport method returns `void`:

| Method | Request | Effect and response |
| --- | --- | --- |
| `write(array $record)` | One opaque Nightwatch record. | Buffers the record and returns nothing. Console processes send full buffers and aged buffers on the next write. Web requests count and drop the oldest record on overflow without sending mid-request. |
| `writeNow(array $record)` | One opaque Nightwatch record. | Attempts one immediate POST without clearing buffered records; returns nothing and suppresses transport errors. |
| `ping()` | No input. | No-op; returns nothing. |
| `shouldDigest(bool $bool = true)` | Compatibility flag. | Delegates to `shouldDigestWhenBufferIsFull`; returns nothing. Web requests still do not send mid-request. |
| `shouldDigestWhenBufferIsFull(bool $bool = true)` | Full-buffer flag controlled by Nightwatch sampling. | Enables full-buffer sends in console processes; returns nothing. Web requests still do not send mid-request. |
| `digest()` | No input. | Attempts one POST of the buffered records, then clears them whether the POST succeeds or fails; returns nothing. |
| `flush()` | No input. | Clears buffered records without sending; returns nothing. |

This diagnostic-only example shows the lifecycle Nightwatch drives. Do not use it to create custom telemetry:

```php
/** @var \Laravel\Nightwatch\Core $core */
$core = app(\Laravel\Nightwatch\Core::class);
$core->ingest->write(['t' => 'query', 'sql' => 'select 1']);
$core->ingest->digest();
```

An ingest POST uses bearer authentication and this JSON request shape:

```json
{
  "envelope_version": 1,
  "app": "checkout",
  "deploy": "abc123",
  "sent_at": "2026-09-04T12:00:00+00:00",
  "records": [
    {"t": "query", "sql": "select 1"}
  ],
  "losses": {
    "overflow_dropped_records": 2,
    "failed_delivery_records": 3
  }
}
```

`losses` is optional and appears only after the client has records to report. It is cleared after the next successful POST.

The client does not expose the HTTP response shape. It checks for a successful response, discards the response, suppresses any exception, and returns `void`.

| Incumbent call or behavior | Hone equivalent |
| --- | --- |
| Nightwatch automatic Laravel instrumentation | Direct equivalent: keep Nightwatch instrumentation; Hone replaces only its ingest transport and forwards those records to the customer's Hone deployment. |
| Sentry explicit exception or message capture | No call equivalent. Hone forwards exceptions and logs that Nightwatch instruments, but exposes no explicit capture method. |
| Datadog custom metrics, spans, or traces | No equivalent. The client only forwards Nightwatch records and has no custom metric, span, or trace API. |

## Behaviour to know

- The Hone client captures nothing itself. Once installed and enabled, Nightwatch captures its supported Laravel requests, queries, jobs, exceptions, logs, and other records automatically; Hone buffers and forwards them. No Hone call-site changes are required.
- Shipping is a bounded synchronous HTTP POST when Nightwatch digests at request or command termination, after an HTTP response has been sent. There is no client daemon, disk buffer, webhook, polling flow, or completion result.
- The Hone deployment processes accepted telemetry asynchronously. Query receipt through Hone after its queue worker handles the batch; do not wait for a client callback.
- One digest makes one HTTP attempt. There are no retries. Connection failures, timeouts, non-success responses, identity lookup failures, and logger failures never fail the host application. Failed record counts ride on the next successful POST from that process.
- The in-memory buffer defaults to 500 records. Web requests drop and count older records rather than posting before termination. Console processes, including queue workers, send full buffers and send aged buffers on the next write. A process exit can still lose a buffer that has not reached either condition.
- Sampling belongs to Nightwatch configuration. The Hone client does not sample.
- Redaction must happen in Nightwatch before transmission. The Hone client adds no redaction layer.
- `HONE_URL` and `HONE_TOKEN` must both be non-empty. Removing either disables the rebind; setting only one also emits a warning.
- Run `php artisan hone:update` after a major client update. It checks the server's `/capabilities`; update the Hone deployment before updating source-app clients when envelope majors change.
- Hone provides no Nightwatch-style dashboard, deep distributed traces, long-term raw history, or alerting. Its data remains in the customer's own Hone deployment and is read through that deployment's MCP server.

## Verify

Generate fresh source-app telemetry, then verify server-side receipt rather than relying on the fail-open client log:

```bash
php artisan about
```

After the command terminates and the Hone queue worker processes the batch, call the Hone MCP tool `ingest-freshness-tool`. A working end-to-end integration returns the token's `<app-id>` with fresh ISO-8601 timestamps:

```json
{
  "apps": [
    {
      "app": "checkout",
      "latest_occurred_at": "2026-09-04T12:00:00.000000Z",
      "latest_ingested_at": "2026-09-04T12:00:01.000000Z"
    }
  ],
  "loss_counters": [
    {
      "app": "checkout",
      "deploy": "abc123",
      "overflow_dropped_records": 2,
      "failed_delivery_records": 3
    }
  ]
}
```

If the app is absent or the timestamps do not advance, first verify the transport rebind without printing configuration values:

```bash
php artisan tinker --execute='echo get_class(app(\Laravel\Nightwatch\Core::class)->ingest), PHP_EOL;'
```

The output must be `ArtisanBuild\HoneClient\HoneIngest`.

## Contribute back

If you used this default guide to migrate from a specific product and no migration guide for it
exists yet, offer to open a pull request to this repository adding
`packages/hone-client/docs/integrate/<incumbent>.md` with what you learned.
