---
name: multi-agent-build
description: "Use this skill to orchestrate building a planned, multi-PR feature with a coordinated subagent loop: one agent implements each PR, independent agents review and judge it, and THIS session is the coordinator that verifies the gate, arbitrates findings, and hands each PR off as a GitHub PR. Trigger when the user has a multi-step/multi-PR implementation plan (e.g. a planning scratchpad) and asks to build it via the multi-agent / coordinated workflow, 'orchestrate this', 'have the agents build it', or 'run the loop'. Each PR runs implement -> gate (composer ready + touched-package suites) -> independent quality review (Codex) + acceptance judge (Claude) -> coordinator arbitration -> PR handoff, with a 3-attempt cap and a human merge gate between PRs. Do NOT use for single-file edits, quick fixes, refactors, or anything the user wants done directly in this session — this skill spawns multiple subagents and burns real tokens; it is only for substantial, plan-driven feature builds the user has explicitly opted into. Requires Solo (for spawning/awaiting agents) and the Hone monorepo gate (composer ready + per-package pest/pint)."
license: MIT
metadata:
  author: artisan-build
  origin: "Adapted for the Hone monorepo from the ourcves multi-agent-build skill (proven on the deployed-repo discovery feature, run 1). Tweaks: monorepo package gate, per-package worktree install, read-only split handoff."
---

# Multi-Agent Build (Hone monorepo)

Orchestrate a planned, multi-PR feature build. **You are the coordinator** — you do NOT write the
feature code. You decompose the plan, spawn subagents to implement and review each PR, verify the
hard gate yourself, arbitrate findings, and hand each PR to the human as a GitHub PR.

**Bias: quality over speed.** Sequential is the default; only parallelize PRs that are genuinely
independent (different surfaces, no shared files). The whole point is that independent agents catch
what a single pass — or the deterministic gate alone — misses.

**Hone is a monorepo.** Most PRs touch one of `packages/hone-contracts`, `packages/hone-client`,
`packages/hone-server`, or the slim Hone app at the root. The gate and worktree steps below have
monorepo-specific rules — read them, they differ from a single-app project.

## When to use / not use

- **Use** when the user has a real implementation plan (the Hone build plan lives in Solo scratchpad
  **#38** of project `hone`, with the Phase 0–6 todo backlog) and explicitly opts into the
  multi-agent / coordinated build.
- **Do NOT use** for single-file edits, quick fixes, refactors, or work the user wants you to do
  inline. This spawns multiple subagents and is for substantial feature builds only.

## Roles & runtimes (deliberate model/harness decorrelation)

| Role | Who | How to run |
| --- | --- | --- |
| **Coordinator** | This Claude session | Decomposes PRs, writes acceptance criteria + critical test assertions, spawns/awaits agents, verifies the gate, arbitrates, writes the implementation report, keeps the run-log. |
| **Implementer** | OpenCode (Solo `agent_tool_id 2`) | Persistent Solo agent in the PR's worktree. Implements + writes tests until the gate is green. |
| **Quality reviewer** | Codex (`codex exec`, GPT-5.x) | One-shot, NOT a persistent Solo agent. Different CLI harness than OpenCode. |
| **Acceptance judge** | Claude (Solo `agent_tool_id 3`) | Judges strictly against the acceptance criteria; must read REAL test output, never vibe-judge. |

Different model lineages touch every PR so reviewers don't share the implementer's blind spots. The
coordinator (Claude) reads all the code when writing the report — a fourth, holistic pass.

**Spawn mechanics:** `spawn_agent(agent_tool_id)` → prepend the returned `agent_instructions` →
`send_input(process_id, input)`. Await with `timer_fire_when_idle_any([pid], max_wait_ms, body)`
(the idle-timer wakes this coordinator session — the reliable hands-off mechanism). Capture output
with `get_process_output`. Solo project scope is `hone` (id 8).

**Codex invocation (it won't run as a persistent Solo agent — the saved `codex --yolo` command is an
invalid flag and self-exits; `codex exec` reads the key from the ENV not auth.json):**
```
cd <worktree> && OPENAI_API_KEY="$(jq -r '.OPENAI_API_KEY' ~/.codex/auth.json)" \
  codex exec --skip-git-repo-check - < /tmp/<brief>.txt > /tmp/<review>.md 2>&1
```
Run from the Bash tool with the sandbox disabled (Codex needs network). Its default `sandbox: read-only`
is correct for a reviewer. The output file echoes the brief first — read the LAST `=== REVIEW START ===`
block for the real verdict.

## Worktree setup (per PR) — DO THIS RIGHT, it bit us in run 1

Each PR gets its own git worktree off the latest `origin/main`, with its **own real `composer install`**:
```
git worktree add <path> -b <branch> origin/main
cd <path> && composer install --no-interaction   # + copy .env, touch database/database.sqlite
```
**Never symlink (or `cp -R`) `vendor/` from the main checkout.** A symlinked vendor makes Composer's
`installed.php` resolve the project root to the MAIN checkout, so framework-boot/MCP tests load the
wrong `routes/console.php` (whatever branch main is on) and explode. A `cp -R` drags stale Pest cache
("TestCase already uses" duplicate-binding). A real `composer install` is the only reliable provisioning.

**Monorepo: each touched package needs its OWN `composer install` too.** The packages are
standalone-testable with their own `vendor/` (the app's root vendor does NOT autoload a package's
`tests/` namespace). For every package the PR touches:
```
(cd <path>/packages/hone-<pkg> && composer install --no-interaction)
```
The vendor-symlink warning applies at the package level as well — let each package resolve its own.

For **read-only reviewers**, don't rely on cwd at all — give absolute paths and `git -C <worktree>`.
Only the IMPLEMENTER (OpenCode) needs to be *in* the worktree, and only OpenCode honors the
`extra_args=["<path>"]` positional to set its cwd (Codex treats it as a one-shot prompt; Claude treats
it as an initial prompt and lands confused — so reviewers get absolute paths instead).

## The hard gate (objective, non-negotiable)

The Hone gate has two parts, both must be green:

1. **App gate — `composer ready`** at the repo root (ide-helper → rector → pint → phpstan → full Pest
   suite → `composer audit`). Always runs, even for package-only PRs, because the app autoloads the
   packages via path repository and must still boot and pass.
2. **Touched-package gate** — for EACH package the PR changed, inside `packages/hone-<pkg>`:
   `composer lint:test` (pint --test) **and** `composer test` (pest). The convenience
   `composer packages:check` at the root runs install + lint + test across ALL packages; use it when a
   PR spans several, otherwise run the touched package directly (faster).

Rules:

- **You verify the gate yourself, on the COMMITTED SHA, in a clean tree** — never trust the implementer's
  "it passed." Run the REAL `composer ready`, not a bare `phpstan`/`test` shortcut: `ready` runs
  ide-helper FIRST, which regenerates model docblocks so phpstan can resolve types after model/schema
  changes; bare phpstan against a stale committed `_ide_helper_models.php` gives FALSE errors.
- The gate is a **precondition to review** — don't spawn reviewers until BOTH parts are green. A red gate
  bounces to the implementer and does NOT consume an attempt.
- The LLM judge is **additive on top of** the gate, never a substitute. The judge must inspect real test output.
- If a tracked generated file (`_ide_helper_models.php`) is stale, regenerate + commit it as part of the PR.
- **The read-only split is NOT part of the gate.** PRs target the monorepo `main`. Pushing each package
  to its `artisan-build/hone-<pkg>` mirror is a post-merge step (see Split handoff), never gated per-PR.

## The per-PR loop

```
1. PLAN      You decompose the PR into tasks + write acceptance criteria AND the critical
             acceptance-test assertions up front (TDD-leaning — so the implementer can't teach to a weak test).
2. BUILD     Spawn OpenCode in the PR worktree. Brief it fully (tasks, ACs, locked assertions, the gate
             INCLUDING the touched-package suites, "keep git status clean — only intended files", commit
             message footer, do NOT push/PR/merge). It loops on its own until the gate is green.
3. GATE      You re-verify `composer ready` AND each touched package's `composer test`/`lint:test` on the
             committed SHA in a clean tree. Red -> back to BUILD. Also confirm the working tree is clean
             (no uncommitted churn the "green" depended on).
4. REVIEW    Green -> launch Codex (quality/security/perf/standards, via codex exec) AND a Claude judge
             (acceptance), independent + blind to each other, both against the committed diff.
5. ARBITRATE You apply the severity rubric (below) and decide. Verify any blocking finding in the code
             yourself before acting on it.
6. REWORK    Blocking issue(s) -> send a tight rework brief to the SAME implementer (it's warm). Back to
             GATE. attempts < 3. For a trivial coordinator-verifiable fix, verify by inspection — do NOT
             re-run a full review round.
7. PASS      Gate green + no blocking -> read the full diff, write the implementation report, push the
             branch, open the GitHub PR (against artisan-build/hone) with the report AS the body.
             HUMAN GATE: the user merges before the next dependent PR begins.
```

**Bail:** after 3 attempts still blocking → stop, do not touch the next PR, write a **standalone
scratchpad** (branch, per-round diffs, surviving findings, what each attempt tried, a hypothesis about
which planning assumption broke) and ask the user for help. A bail means the feature's PLAN, not just the
code, needs revisiting.

## Severity rubric — YOU own the call

LLM reviewers always find *something*; without discipline the loop never terminates.

- **Reviewer `[BLOCKING]`/`[ADVISORY]` tags are advisory INPUT, not verdicts.** You decide true severity by
  weighing **real-world likelihood and impact**, not theoretical correctness.
- **BLOCKING** = a failing gate, a security/credential issue, an unmet acceptance criterion, a missing/weak
  test for required behavior, or data-integrity that can actually occur. Only blocking findings trigger a
  rework round and consume an attempt.
- **ADVISORY** = nits, edge cases that can't occur in practice, low-impact robustness. **Surface these in
  the PR body, do NOT spend a cycle.** The user will say "quick-fix now" (you apply it directly), "open an
  issue", or "leave it".
- Do NOT escalate a low-likelihood/low-impact issue to blocking just because a reviewer flagged it
  (run-1 miss: burned the final attempt on a `%2F`-in-a-path edge the agent would never emit). Do NOT
  over-cycle: verify trivial changes by inspection instead of a fresh full review.

**Attempts:** initial implementation = attempt 1; each blocking-driven rework = +1; max 3. A red gate the
implementer is still resolving does NOT burn an attempt — only a *reviewed* round with surviving blocking
findings does. Infrastructure failures you caused (bad worktree setup, etc.) do NOT count against the budget.

## Artifact contract

- **One run-log scratchpad** for the whole feature (Solo project `hone`) — append at every transition
  (spawn, gate result, findings, arbitration, attempt count, PR link). This is the user's live window;
  they can interrupt anytime. The standing build plan + findings live in scratchpad **#38**; the run-log
  is a separate, append-only scratchpad.
- **One Solo todo per PR** — body holds tasks + acceptance criteria; encode the PR dependency DAG with
  `todo_add_blocker`; status tracks loop state; mark complete only after the user merges. (The Phase 0–6
  todos already exist; sub-PRs hang off the relevant phase.)
- **The PR is the handoff.** On PASS, push + open the GitHub PR with the implementation report as the body,
  so the user sees report + diff on one screen. Non-blocking advisories are discrete items in that body.

## Split handoff (post-merge, monorepo-specific)

After the user merges a PR that changed a package, mirror that package to its read-only split so Packagist
sees it. This is NOT gated and NOT part of the per-PR loop — do it only when asked or as an explicit
release step:
```
git subtree split --prefix=packages/hone-<pkg> -b split-<pkg>
git push https://github.com/artisan-build/hone-<pkg>.git split-<pkg>:main
git branch -D split-<pkg>
```
Splits are read-only (issues/PRs disabled). `hone-contracts` and `hone-client` exist;
`hone-server`'s split is created when that package first ships. Eventually this becomes CI on push to
`main`.

## Implementation report (the PR body)

Read the full diff and write: **what shipped** vs the plan (1–2 lines); **deserves your attention**
(anything complex, fragile, or a deviation forced by an in-flight discovery — and why); **findings
disposition** (blocking found + how fixed; advisories deferred, listed for the user's call); **gate
evidence** (`composer ready` clean + which package suites passed; which ACs the judge confirmed);
**risk / next**. If a slice's core behavior isn't automatically testable (e.g. the Octane rebind path),
flag prominently that it needs manual verification.

## Hard-won rules (run 1 — do not relearn these)

1. **Verify the gate on the committed SHA in a clean tree, with the real `composer ready` + touched-package
   suites.** Implementers leave uncommitted churn and report false greens; bare phpstan gives false reds on
   stale ide-helper; the app gate alone never runs the package tests.
2. **Per-worktree real `composer install` — at the root AND inside every touched package.** Never a
   symlinked/copied vendor at either level.
3. **You own severity.** Weigh likelihood; surface non-blocking at handoff; don't over-escalate or over-cycle.
4. **Decorrelate harnesses** — OpenCode implements, Codex reviews, Claude judges. Don't collapse them.
5. **Idle-timers for hands-off resumption** — `timer_fire_when_idle_any` wakes you when an agent finishes.
6. The judge must read **real test output** and confirm the critical assertions genuinely exist (not just green).
7. **The envelope is the compatibility surface.** For any PR touching `hone-contracts`, the judge confirms
   the additive-within-major rule held (no field removed/repurposed) and round-trip + tolerance tests exist.
