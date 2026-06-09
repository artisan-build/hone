# hone-contracts

The versioned **wire envelope** shared between [`hone-client`](https://github.com/artisan-build/hone-client)
(the send side) and `hone-server` (the receive side) of [Hone](https://github.com/artisan-build/hone).

This package is tiny and deliberately so. It is the **single place Hone's wire compatibility
lives** — the DTOs that cross the network, their (de)serialization, and the
`ENVELOPE_VERSION` constant. Both other packages depend on it with a version range.

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/hone`](https://github.com/artisan-build/hone) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

## Why a separate package

Across N independently-deployed senders and one self-hosted receiver, **version skew is the
normal state, not an error.** You cannot keep them in lockstep, so the wire protocol is
built to tolerate skew, and the contract that defines it lives in exactly one place that
both sides pin.

## The compatibility rules

1. **The envelope carries its own version.** Every payload includes `ENVELOPE_VERSION`.
2. **Additive within a major.** New fields are optional and added; existing fields are never
   removed or repurposed. That one rule is what makes a newer server able to parse every
   older envelope, and an older server able to ignore fields it doesn't recognize.
3. **The inner records are opaque.** The envelope wraps Nightwatch record bodies but never
   interprets them. Only the thin envelope (app id, deploy, batch metadata) is
   version-sensitive. A change in Nightwatch's payload shape is invisible to this contract.
4. **A major bump is a deliberate act.** Crossing an envelope major can only happen through
   an explicit `composer require`, never a routine `composer update` — `hone-client` pins a
   caret constraint (`^X`) on this package.

## The envelope (shape)

The envelope wraps a batch of opaque Nightwatch records with the minimum metadata the server
needs to route, tag, and version-check them:

| Field | Meaning |
| --- | --- |
| `envelope_version` | The `ENVELOPE_VERSION` the sender was built against. |
| `app` | The source application id (one Hone environment may receive many apps). |
| `deploy` | The deploy identifier — `NIGHTWATCH_DEPLOY`, typically the commit SHA. |
| `sent_at` | When the batch was assembled. |
| `records` | The opaque Nightwatch records; each carries its own type discriminator. |

## Installation

```bash
composer require artisan-build/hone-contracts
```

You usually don't install this directly — it arrives as a dependency of `hone-client` or
`hone-server`.

## License

MIT. See [LICENSE](LICENSE).
