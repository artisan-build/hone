# Workflow — hone

Project profile for the `multi-agent-build` skill. The coordinator agent reads this FIRST.
hone is a **monorepo** (`packages/hone-contracts`, `packages/hone-client`, `packages/hone-server`,
plus the slim Hone app at the root).

## Phase & mode
- phase: launched
- default mode: A-autonomous
- merge_policy: merge when CI is green; no human PR code review
- merge method: `gh pr merge --squash` (after CI is green)

## CI (the merge gate for Mode A)
- status: verified
- minimum bar: testing + static analysis — MET.
- workflows/jobs:
  - `.github/workflows/tests.yml` (push + PR): `composer stan` (PHPStan) + `./vendor/bin/pest`
    (full app suite, against a Postgres service) + per-package `hone-server` `composer lint:test`/`composer test`.
  - `.github/workflows/lint.yml` (push + PR): `composer lint` (Pint).
  - `.github/workflows/release.yml` (on tags): subtree-splits each package to its read-only mirror.

## Hard gate (must be green before review; coordinator verifies on the committed SHA, clean tree)
- command: `composer ready` at the repo root (ide-helper -> rector -> pint -> phpstan -> full Pest -> `composer audit`).
- extra suites: for EACH touched package, inside `packages/hone-<pkg>`: `composer lint:test` AND `composer test`.
  Use `composer packages:check` at root when a PR spans several packages.
- monorepo: yes — packages: hone-contracts, hone-client, hone-server (+ slim root app). The app gate
  always runs even for package-only PRs (the app autoloads packages via path repository and must boot).

## Dependency install (fresh worktree)
- command: `composer install --no-interaction` at the root AND inside every touched `packages/hone-<pkg>`.
- post-install: copy `.env`, `touch database/database.sqlite`.
- NEVER symlink or `cp -R` `vendor/` (root or package level) — it makes Composer resolve the wrong
  checkout and produces phantom framework-boot/test failures. Real install only.

## Harness map (role -> runtime; decorrelate model lineages)
- implementer: OpenCode (Solo `agent_tool_id 2`) — persistent agent in the PR worktree; honors
  `extra_args=["<worktree path>"]` to set cwd.
- quality reviewer: Codex, one-shot (NOT a persistent Solo agent). The saved `codex --yolo` self-exits;
  run via Bash with sandbox disabled (Codex needs network):
  `cd <worktree> && OPENAI_API_KEY="$(jq -r '.OPENAI_API_KEY' ~/.codex/auth.json)" codex exec --skip-git-repo-check - < /tmp/<brief>.txt > /tmp/<review>.md 2>&1`
  Read the LAST `=== REVIEW START ===` block for the verdict. Give it absolute paths (`git -C <worktree>`).
- acceptance judge: Claude (Solo `agent_tool_id 3`) — judges strictly vs acceptance criteria; must read REAL test output.

## Toolchain conformance — the ride-along rule (STANDING, all projects)

Run the project's full conformance command (`composer ready`, or the stack equivalent) as part of
FINALIZING every PR, and **let whatever it changes ride along in that PR** as a single isolated commit
titled `composer ready`.

The point is that conforming to the current standard is **passive** rather than something anyone has to
remember. Tools like Rector exist to keep the codebase at the current standard continuously; if their
output only lands when someone thinks to run them, the codebase drifts and the eventual catch-up is a huge
unreviewable diff.

This is **the boy scout policy: leave things better than you found them, even when the improvement is
not strictly related to the work at hand.**

- **Applies to EVERY project — ours and clients' alike — and to every PR regardless of size.** A
  one-line CI or YAML change gets the sweep exactly like a feature branch does. The only way it comes
  off is Ed specifying that deviation explicitly at the project or client level; a client exception is
  recorded there, never inferred.
- ⚠️ **Scope-discipline language does NOT suspend this rule.** "No unrelated cleanups", "one-line
  change only", and "don't expand scope" mean *don't invent extra work* — they never mean skip the
  ride-along. An agent that reads them that way will skip the sweep and cite the brief as
  justification. If you are writing a tightly-scoped brief, say so explicitly: *"no unrelated
  cleanups, but the standing `composer ready` ride-along still applies as its own commit."*
- **Do NOT open a separate branch for these changes.** As long as the tool CONFIGURATION is unchanged, the
  unrelated changes riding along on any given PR are small.
- **The one exception:** introducing a new Rector rule, or changing `pint.json` / equivalent tool config.
  That sweep is large and deliberate, so it gets its own dedicated branch and PR.
- Keep it in its OWN commit so a reviewer can separate "the feature" from "the sweep" at a glance.

## Ship details
- branch naming: `feat/<slug>`
- PR target repo: `artisan-build/hone`
- release / split steps: handled by `release.yml` on tag (CI). Manual subtree split only if needed:
  `git subtree split --prefix=packages/hone-<pkg> -b split-<pkg>` then push to `artisan-build/hone-<pkg>.git split-<pkg>:main`.

## Plan & coordination
- plan location: Solo scratchpad #38 (Phase 0–6 build backlog + findings).
- Solo project: hone (id 8) — note: id changes if the project is ever parked/restored; resolve by name.
- run-log: a separate, append-only scratchpad per feature (NOT #38).

## Stack notes / quirks
- `composer ready` runs ide-helper FIRST (regenerates model docblocks), so phpstan resolves types after
  model/schema changes. Bare `phpstan` against a stale committed `_ide_helper_models.php` gives FALSE
  errors — regenerate + commit it as part of the PR if stale.
- Packages are standalone-testable with their own `vendor/`; the app's root vendor does NOT autoload a
  package's `tests/` namespace — install each touched package.
- `hone-contracts` is the compatibility surface: any PR touching it must hold the additive-within-major
  rule (no field removed/repurposed) and keep round-trip + tolerance tests.
