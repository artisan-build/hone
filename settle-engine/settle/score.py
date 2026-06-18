"""score — deterministic monthly durability score (300-850) + back-test.

A FICO-like **durability score** computed purely from git history. For each month
``T`` it emits a composite 300-850 score plus five 0-1 component sub-scores (for
explainability). Higher = more durable: the team's recent output is sticking the
landing rather than being torn up again. Pure and reproducible: same repo + same
args (including the explicit ``--now`` anchor) → byte-identical output. No network,
no LLM, no wall clock.

The point of the score is to be a *leading indicator* of future rework, so it is
built under one hard rule:

    NO LOOK-AHEAD. The score for month T is a function of ONLY signals observable
    by the end of month T — commits with committer day <= the last day of T. No
    commit dated after T may influence score(T). (Tested: score(T) is byte-identical
    whether or not commits after T exist in the repo.) Normalization uses FIXED
    constants, never data-derived maxima, so appending history cannot shift a band.

This is what makes the companion **back-test** meaningful: it correlates score(T)
(backward inputs only) against a *forward* outcome measured over the months after T
(forward rework rate), to check whether a low score actually predicts future trouble.

Components (each normalized to 0-1 where 1 = healthy), measured over a trailing
window of ``--trailing-weeks`` ending at the last day of month T:

  rework          line-level short-window churn rate over the window (rework_rate
                  logic: a removed line is rework if it was introduced < churn-window
                  days earlier — both dates are in the past). Lower rate -> higher score.
  substrate       "substrate heat": how hot the touched files already were. For each
                  commit, the count of prior commits within --heat-lookback-weeks that
                  also touched one of its files (purely backward). Hotter ground -> lower.
  scope           mean non-excluded files changed per commit in the window. Sprawling
                  changes -> lower.
  bugfix_load     fraction of window commits whose subject reads as a bugfix/hotfix.
                  More corrective work -> lower.
  test_discipline fraction of window commits that touch a test/spec file. More -> higher.

Normalization (the reference bands are documented constants in :data:`DEFAULT_BANDS`
and overridable; weights in :data:`DEFAULT_WEIGHTS`, overridable via ``--weights``):

    rework          1 - rate / rework_bad                 (rate >= rework_bad -> 0)
    substrate       1 - heat / substrate_bad              (heat >= substrate_bad -> 0)
    scope           (scope_bad - files) / (scope_bad - scope_good)
    bugfix_load     1 - frac / bugfix_bad
    test_discipline frac / test_good                      (frac >= test_good -> 1)

all clamped to [0, 1]. composite = sum(w_k * norm_k) / sum(w_k); score = round(300 +
composite * 550), so the score is always within [300, 850] for any weights. By default
only the two components the back-test found to *lead* future rework drive the composite
(``scope`` 0.7, ``rework`` 0.3); the other three are weight 0 — still computed and emitted
for diagnostics, just not moving the score (see :data:`DEFAULT_WEIGHTS`).

Because the score is pure git history, the first scan already covers months of trend, so
the **retroactive trajectory** is a first-class output (a static-attribute snapshot tool
could never show this): current/peak/trough, trailing 6- & 12-month net change, and a
simple improving/stable/declining classification — all read deterministically off the
monthly series (no smoothing, no ML).

Output (written to ``--out``, default ``data/<repo-basename>/``)
---------------------------------------------------------------
``score.json`` — array of ``{month, score, components: {rework, substrate, scope,
bugfix_load, test_discipline}, n}`` sorted by ``month`` ascending. ``components`` are
the 0-1 normalized sub-scores; ``n`` is the window's commit count. ``score.csv`` is the
same rows flattened. ``score_trajectory.json`` — the trajectory summary (always written).
``score_coverage.json`` — the coverage block (also always written, even with zero scored
months): ``scored_months``, ``backtested_months``, ``history_days``, ``current_month_excluded``,
``skipped_commits``, and a ``confidence`` band (``none`` / ``low`` / ``ok``) so a thin or
brand-new repo reports an honest "too new to score" status instead of a falsely confident
number. With ``--backtest`` it also writes ``score_backtest.json`` (overall and per-component
Spearman correlation vs. forward rework, plus a quartile summary). The schema is mirrored
in ``skills/README.md``.

Resilience: every git call goes through a bounded retry with backoff (in
:func:`settle.miners.rework_rate._git`) so a transient promisor blob fetch on a partial/
blobless clone does not abort the run; a commit whose git classification still fails is
skipped (counted in ``coverage.skipped_commits``) rather than crashing the whole scan.
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import re
import subprocess
import sys
from collections.abc import Sequence
from concurrent.futures import ThreadPoolExecutor
from dataclasses import asdict, dataclass
from datetime import date, timedelta
from pathlib import Path

from settle.excludes import ExcludeMatcher, build_matcher

# Reuse the rework_rate miner's diff parsing + blame so the rework component is the
# exact same line-level signal (and the generated/vendored exclusion is shared infra).
from settle.miners.rework_rate import (
    _EMPTY_TREE,
    _git,  # shared runner: bounded retry + backoff over transient promisor blob fetches
    _parse_diff_hunks,
    blame_line_days,
    resolve_ref,
)

_DAYS_PER_WEEK = 7
# ASCII unit separator — safe field delimiter for `git log --pretty` output.
_SEP = "\x1f"

# Component keys, in their canonical (output) order.
COMPONENTS: tuple[str, ...] = (
    "rework",
    "substrate",
    "scope",
    "bugfix_load",
    "test_discipline",
)

# Heuristic for classifying a commit subject as corrective work (a bugfix/hotfix)
# rather than feature/refactor iteration. Matches the post_merge_churn miner.
BUGFIX_RE = re.compile(
    r"\b(?:fix|fixes|fixed|fixing|bug|bugfix|hotfix|patch|patches|patched|"
    r"regression|revert|reverts|reverted)\b",
    re.IGNORECASE,
)

# Test/spec *directory* anywhere in the path (tests/, spec/, __tests__/, ...).
_TEST_DIR_RE = re.compile(r"(?:^|/)(?:tests?|specs?|__tests__)(?:/|$)", re.IGNORECASE)
# Test/spec marker in the basename, requiring a separator boundary so 'latest.py' is
# not a false positive — covers test_x.py, x_test.go, x.test.ts, x.spec.js, tests.rb.
_TEST_FILE_CI_RE = re.compile(r"(?:^|[._-])(?:test|spec)s?(?=[._-])", re.IGNORECASE)
# camelCase test classes (FooTest.php, BarSpec.scala) — case-sensitive so it keys on
# the lower->Upper transition and does not fire on 'latest.' / 'respec.'.
_TEST_FILE_CAMEL_RE = re.compile(r"(?<=[a-z])(?:Test|Spec)(?=\.)")


def is_test_path(path: str) -> bool:
    """Heuristic: does this path look like a test/spec file? True if a tests/spec
    directory appears in the path, or the basename carries a test/spec marker."""
    if _TEST_DIR_RE.search(path):
        return True
    base = path.rsplit("/", 1)[-1]
    return bool(_TEST_FILE_CI_RE.search(base) or _TEST_FILE_CAMEL_RE.search(base))


# --------------------------------------------------------------------------- #
# Normalization bands + weights (documented, overridable constants)
# --------------------------------------------------------------------------- #


@dataclass(frozen=True)
class Bands:
    """Reference anchors that map a raw component value to 0-1 (see module docstring).

    These are FIXED constants — never derived from the data being scored — so that
    appending later history cannot shift a band and retroactively change score(T)."""

    rework_bad: float = 0.35  # rework rate at/above which the rework sub-score is 0
    substrate_bad: float = 8.0  # mean prior-touch heat at/above which substrate is 0
    scope_good: float = 2.0  # mean files/commit at/below which scope is 1
    scope_bad: float = 15.0  # mean files/commit at/above which scope is 0
    bugfix_bad: float = 0.6  # bugfix fraction at/above which bugfix_load is 0
    test_good: float = 0.4  # test-touch fraction at/above which test_discipline is 1


DEFAULT_BANDS = Bands()

# Component weights for the composite. Only the two components that the back-test
# showed to be *leading* predictors of future rework drive the score; the rest are
# weight 0 (still computed and emitted in components{} for diagnostics, just not moving
# the composite). Rationale, from the camps back-test (Spearman of each component vs.
# forward 3-month rework — negative = predictive, since components are health-oriented):
#   scope            -0.54  -> dominant predictor          -> 0.7 (primary)
#   rework           -0.14  -> weak but correct sign       -> 0.3 (secondary)
#   substrate        +0.18  -> WRONG sign as a monthly lead-> 0.0 (keep as diagnostic)
#   bugfix_load      +0.23  -> WRONG sign                  -> 0.0 (keep as diagnostic)
#   test_discipline  +0.04  -> no signal                   -> 0.0 (keep as diagnostic)
# Weights are deliberately round and principled, NOT numerically fit to camps — camps is
# one data point and over-fitting it would poison validation across other orgs. Combined
# relatively (divided by their sum), so any positive weights still map into 300-850.
# Overridable via --weights "scope=0.6,rework=0.4,...". substrate stays a valuable
# *cross-sectional* landing-zone diagnostic even though it is not a monthly leading signal.
DEFAULT_WEIGHTS: dict[str, float] = {
    "rework": 0.3,
    "substrate": 0.0,
    "scope": 0.7,
    "bugfix_load": 0.0,
    "test_discipline": 0.0,
}

_SCORE_MIN = 300
_SCORE_SPAN = 550  # 300..850


@dataclass(frozen=True)
class MonthScore:
    """One scored month: composite 300-850 score, its 0-1 components, window size."""

    month: str  # YYYY-MM
    score: int
    components: dict[str, float]  # normalized 0-1 sub-scores, keyed by COMPONENTS
    n: int  # commits in the trailing window


@dataclass(frozen=True)
class _Commit:
    sha: str
    parent: str | None
    day: date
    subject: str


@dataclass(frozen=True)
class _CommitMetrics:
    """Per-commit signals, computed once over the full pass (then window-aggregated)."""

    day: date
    new_lines: int
    reworked_lines: int
    files: tuple[str, ...]  # non-excluded changed files (sorted)
    scope_files: int
    is_bugfix: bool
    touches_test: bool
    substrate_heat: int  # prior commits within the lookback sharing a file


def _parse_day(value: str) -> date:
    """Parse a YYYY-MM-DD git short date into a date."""
    return date.fromisoformat(value.strip())


def list_commits(
    repo: Path,
    ref: str,
    since: date | None = None,
    until: date | None = None,
) -> list[_Commit]:
    """Non-merge commits on `ref`, oldest-first, inclusively bounded by since/until on
    the committer day. Oldest-first ordering is what the substrate back-scan needs."""
    out = _git(
        repo,
        "log",
        "--no-merges",
        "--no-color",
        "--date=short",
        f"--pretty=format:%H{_SEP}%P{_SEP}%cd{_SEP}%s",
        ref,
    )
    commits: list[_Commit] = []
    for line in out.splitlines():
        if not line:
            continue
        sha, parents, day_str, subject = line.split(_SEP, 3)
        day = _parse_day(day_str)
        if since is not None and day < since:
            continue
        if until is not None and day > until:
            continue
        first_parent = parents.split(" ")[0] if parents.strip() else None
        commits.append(_Commit(sha=sha, parent=first_parent, day=day, subject=subject))
    commits.reverse()  # git log is newest-first; we want oldest-first
    return commits


def _classify_commit(
    repo: Path,
    commit: _Commit,
    churn_window_days: int,
    matcher: ExcludeMatcher,
) -> tuple[int, int, tuple[str, ...], bool]:
    """Return (new_lines, reworked_lines, files, touches_test) for one commit.

    Mirrors rework_rate's line attribution: blame the parent at each hunk's removed
    range; a removed line is rework if introduced within [0, churn_window_days). Files
    matching the generated/vendored exclusion are skipped from every count."""
    parent = commit.parent or _EMPTY_TREE
    diff = _git(repo, "diff", "--unified=0", "--no-color", "--no-renames", parent, commit.sha)

    new_lines = 0
    reworked_lines = 0
    files: set[str] = set()
    touches_test = False
    for f in _parse_diff_hunks(diff):
        path = f.exclude_path
        if path is None or matcher.is_excluded(path):
            continue
        files.add(path)
        if is_test_path(path):
            touches_test = True

        ranges = [(a, a + b - 1) for (a, b, _d) in f.hunks if b > 0]
        line_days: dict[int, date] = {}
        if ranges and f.parent_path is not None and commit.parent is not None:
            line_days = blame_line_days(repo, parent, f.parent_path, ranges)

        for a, b, d in f.hunks:
            recent = 0
            for ln in range(a, a + b):
                intro = line_days.get(ln)
                if intro is not None and 0 <= (commit.day - intro).days < churn_window_days:
                    recent += 1
            reworked_lines += recent
            new_lines += max(0, d - recent)
    return new_lines, reworked_lines, tuple(sorted(files)), touches_test


def _safe_classify(
    repo: Path,
    commit: _Commit,
    churn_window_days: int,
    matcher: ExcludeMatcher,
) -> tuple[int, int, tuple[str, ...], bool] | None:
    """`_classify_commit` with a resilience net: if git still fails after its bounded
    retries (e.g. an object that cannot be fetched from a partial clone's promisor
    remote), skip this one commit with a stderr warning and return None instead of
    aborting the whole run. The skip is counted and surfaced in the coverage output, so
    the degradation is visible rather than silent."""
    try:
        return _classify_commit(repo, commit, churn_window_days, matcher)
    except subprocess.CalledProcessError as exc:
        print(
            f"WARNING: skipping commit {commit.sha[:12]} — git exited {exc.returncode} "
            "after retries (likely an unfetchable object on a partial/blobless clone). "
            "This commit is excluded from the window and counted in coverage.skipped_commits.",
            file=sys.stderr,
        )
        return None


def _substrate_heat(
    commits: Sequence[_Commit],
    file_sets: Sequence[frozenset[str]],
    lookback_days: int,
) -> list[int]:
    """For each commit (oldest-first), the count of strictly-prior commits within
    `lookback_days` that touched at least one of the same files. Purely backward — it
    can never depend on a commit dated after the one being scored."""
    n = len(commits)
    heat = [0] * n
    for i in range(n):
        fi = file_sets[i]
        if not fi:
            continue
        di = commits[i].day
        count = 0
        j = i - 1
        while j >= 0:
            delta = (di - commits[j].day).days
            if delta > lookback_days:
                break  # commits are oldest-first, so all earlier j are older still
            if fi & file_sets[j]:
                count += 1
            j -= 1
        heat[i] = count
    return heat


def commit_metrics(
    repo: Path,
    commits: Sequence[_Commit],
    churn_window_days: int,
    heat_lookback_days: int,
    matcher: ExcludeMatcher,
    jobs: int = 1,
) -> tuple[list[_CommitMetrics], int]:
    """Compute per-commit metrics for `commits` (oldest-first). Each commit is blamed
    once; the result is independent of `jobs`.

    Returns ``(metrics, skipped)``. A commit whose git classification fails even after
    retries is dropped (not fatal) and counted in ``skipped``; the kept metrics stay in
    oldest-first order so substrate heat and the trailing windows remain well-defined."""
    if not commits:
        return [], 0
    if jobs > 1:
        with ThreadPoolExecutor(max_workers=jobs) as pool:
            classed = list(
                pool.map(lambda c: _safe_classify(repo, c, churn_window_days, matcher), commits)
            )
    else:
        classed = [_safe_classify(repo, c, churn_window_days, matcher) for c in commits]

    # Drop skipped commits (None) before heat/window math; keep oldest-first alignment.
    kept_commits = [c for c, r in zip(commits, classed, strict=True) if r is not None]
    kept_classed = [r for r in classed if r is not None]
    skipped = len(commits) - len(kept_commits)

    file_sets = [frozenset(files) for (_n, _r, files, _t) in kept_classed]
    heat = _substrate_heat(kept_commits, file_sets, heat_lookback_days)

    metrics: list[_CommitMetrics] = []
    for commit, classified, h in zip(kept_commits, kept_classed, heat, strict=True):
        new_lines, reworked, files, touches_test = classified
        metrics.append(
            _CommitMetrics(
                day=commit.day,
                new_lines=new_lines,
                reworked_lines=reworked,
                files=files,
                scope_files=len(files),
                is_bugfix=bool(BUGFIX_RE.search(commit.subject)),
                touches_test=touches_test,
                substrate_heat=h,
            )
        )
    return metrics, skipped


# --------------------------------------------------------------------------- #
# Month arithmetic
# --------------------------------------------------------------------------- #


def _add_months(year: int, month: int, delta: int) -> tuple[int, int]:
    idx = (year * 12 + (month - 1)) + delta
    return idx // 12, idx % 12 + 1


def _month_last_day(year: int, month: int) -> date:
    ny, nm = _add_months(year, month, 1)
    return date(ny, nm, 1) - timedelta(days=1)


def _month_str(year: int, month: int) -> str:
    return f"{year:04d}-{month:02d}"


def _clamp01(x: float) -> float:
    return 0.0 if x < 0.0 else 1.0 if x > 1.0 else x


def normalize_components(
    rework_rate: float,
    substrate_heat: float,
    scope_files: float,
    bugfix_frac: float,
    test_frac: float,
    bands: Bands = DEFAULT_BANDS,
) -> dict[str, float]:
    """Map raw component values to 0-1 sub-scores (1 = healthy). See module docstring."""
    scope_norm = (
        (bands.scope_bad - scope_files) / (bands.scope_bad - bands.scope_good)
        if bands.scope_bad != bands.scope_good
        else 0.0
    )
    return {
        "rework": _clamp01(1.0 - rework_rate / bands.rework_bad),
        "substrate": _clamp01(1.0 - substrate_heat / bands.substrate_bad),
        "scope": _clamp01(scope_norm),
        "bugfix_load": _clamp01(1.0 - bugfix_frac / bands.bugfix_bad),
        "test_discipline": _clamp01(test_frac / bands.test_good),
    }


def composite_score(components: dict[str, float], weights: dict[str, float]) -> int:
    """Combine 0-1 components into a 300-850 score using relative weights."""
    total_w = sum(weights[k] for k in COMPONENTS)
    if total_w <= 0:
        raise ValueError("weights must sum to a positive number")
    composite = sum(weights[k] * components[k] for k in COMPONENTS) / total_w
    return round(_SCORE_MIN + composite * _SCORE_SPAN)


def _aggregate_window(window: Sequence[_CommitMetrics]) -> dict[str, float]:
    """Raw component values for a set of commits (the trailing window)."""
    n = len(window)
    reworked = sum(m.reworked_lines for m in window)
    new = sum(m.new_lines for m in window)
    denom = reworked + new
    return {
        "rework_rate": (reworked / denom) if denom else 0.0,
        "substrate_heat": sum(m.substrate_heat for m in window) / n,
        "scope_files": sum(m.scope_files for m in window) / n,
        "bugfix_frac": sum(1 for m in window if m.is_bugfix) / n,
        "test_frac": sum(1 for m in window if m.touches_test) / n,
    }


def _round_components(components: dict[str, float]) -> dict[str, float]:
    """Round to 4 dp for byte-stable JSON output."""
    return {k: round(components[k], 4) for k in COMPONENTS}


def compute_metrics_pass(
    repo: Path,
    resolved_ref: str,
    *,
    trailing_weeks: int,
    churn_window_days: int,
    heat_lookback_days: int,
    since: date | None,
    until: date | None,
    jobs: int = 1,
    matcher: ExcludeMatcher | None = None,
) -> tuple[list[_CommitMetrics], int]:
    """The one expensive pass: blame every non-merge commit once and derive its metrics.

    Pulls enough pre-roll below `since` so the earliest output month's trailing window and
    the heat lookback have data. (Heat for commits right at the lower bound is mildly
    under-counted; documented, and negligible once `since` is set generously.) The score
    series and the back-test share a single call to this so the history is blamed once.

    Returns ``(metrics, skipped_commits)`` — see :func:`commit_metrics` for the skip net."""
    if matcher is None:
        matcher = build_matcher(repo, resolved_ref)
    metrics_lower: date | None = None
    if since is not None:
        metrics_lower = since - timedelta(
            days=(trailing_weeks * _DAYS_PER_WEEK) + heat_lookback_days
        )
    commits = list_commits(repo, resolved_ref, since=metrics_lower, until=until)
    return commit_metrics(repo, commits, churn_window_days, heat_lookback_days, matcher, jobs=jobs)


def score_months(
    repo: Path,
    now: date,
    ref: str | None = None,
    trailing_weeks: int = 12,
    churn_window_days: int = 21,
    heat_lookback_weeks: int = 8,
    since: date | None = None,
    until: date | None = None,
    min_commits: int = 3,
    weights: dict[str, float] | None = None,
    bands: Bands = DEFAULT_BANDS,
    jobs: int = 1,
    matcher: ExcludeMatcher | None = None,
    _metrics: Sequence[_CommitMetrics] | None = None,
) -> list[MonthScore]:
    """Score every month fully observable by `now` (its last day <= now), bounded by
    since/until on the month, that has at least `min_commits` commits in its trailing
    window. Backward inputs only — no commit after a month can affect that month's score.

    `_metrics` lets callers (and the back-test) pass a precomputed per-commit pass to
    avoid re-blaming; otherwise it is computed here over the needed history.
    """
    weights = weights or DEFAULT_WEIGHTS
    resolved = resolve_ref(repo, ref)
    heat_lookback_days = heat_lookback_weeks * _DAYS_PER_WEEK

    if _metrics is None:
        metrics, _ = compute_metrics_pass(
            repo,
            resolved,
            trailing_weeks=trailing_weeks,
            churn_window_days=churn_window_days,
            heat_lookback_days=heat_lookback_days,
            since=since,
            until=until,
            jobs=jobs,
            matcher=matcher,
        )
    else:
        metrics = list(_metrics)

    if not metrics:
        return []

    # Candidate output months: from the first commit's month through `now`'s month.
    first_day = min(m.day for m in metrics)
    since_str = _month_str(since.year, since.month) if since else None
    until_str = _month_str(until.year, until.month) if until else None

    results: list[MonthScore] = []
    y, mo = first_day.year, first_day.month
    end_y, end_mo = now.year, now.month
    while (y, mo) <= (end_y, end_mo):
        month = _month_str(y, mo)
        t_end = _month_last_day(y, mo)
        if (
            t_end <= now
            and (since_str is None or month >= since_str)
            and (until_str is None or month <= until_str)
        ):
            wl = t_end - timedelta(days=trailing_weeks * _DAYS_PER_WEEK)
            window = [m for m in metrics if wl < m.day <= t_end]
            if len(window) >= min_commits:
                raw = _aggregate_window(window)
                comps = normalize_components(
                    raw["rework_rate"],
                    raw["substrate_heat"],
                    raw["scope_files"],
                    raw["bugfix_frac"],
                    raw["test_frac"],
                    bands=bands,
                )
                results.append(
                    MonthScore(
                        month=month,
                        score=composite_score(comps, weights),
                        components=_round_components(comps),
                        n=len(window),
                    )
                )
        y, mo = _add_months(y, mo, 1)
    return results


# --------------------------------------------------------------------------- #
# Trajectory: the retroactive trend, read straight off the monthly series
# --------------------------------------------------------------------------- #

# Because the score is pure git history, the very first scan already yields months of
# trend — the headline product feature (a static-attribute snapshot tool never could).
# The trajectory is a dead-simple, deterministic read of the monthly series: no
# smoothing, no ML.

# Trailing net change (points) below which a run is called "stable" rather than
# improving/declining. 25 ≈ 5% of the 300-850 span — a round, defensible band.
TRAJECTORY_STABLE_BAND = 25
# Per-step countermove (points) tolerated while walking back a run, so a single noisy
# month doesn't end an otherwise-consistent trend. Round and deliberately small.
TRAJECTORY_STEP_TOLERANCE = 10

# Classification emitted when there are 0 or 1 scored months: a trend is undefined, but
# this is a distinct, explicit state ("the repo is too new to score a trajectory") rather
# than a real improving/stable/declining call — never present it as a settled trend.
CLASSIFICATION_TOO_YOUNG = "unscoreable_too_young"


@dataclass(frozen=True)
class _Point:
    month: str
    score: int


@dataclass(frozen=True)
class Trajectory:
    """A compact, deterministic summary of the monthly score series."""

    n_months: int
    current: _Point | None  # latest scored month
    peak: _Point | None  # highest score (earliest month on ties)
    trough: _Point | None  # lowest score (earliest month on ties)
    net_change_6m: int | None  # current - score 6 calendar months earlier
    net_change_12m: int | None  # current - score 12 calendar months earlier
    classification: str  # "improving" | "stable" | "declining" | "unscoreable_too_young"
    trend_since: str | None  # month the current run/flat region began


def _month_minus(month: str, months: int) -> str:
    y, mo = int(month[:4]), int(month[5:7])
    ny, nmo = _add_months(y, mo, -months)
    return _month_str(ny, nmo)


def _score_at_or_before(by_month: dict[str, int], target: str) -> int | None:
    """The score for `target`, or the most recent earlier month present (handles gaps).
    None if no month at or before `target` exists in the series."""
    candidates = [m for m in by_month if m <= target]
    if not candidates:
        return None
    return by_month[max(candidates)]


def summarize_trajectory(scores: Sequence[MonthScore]) -> Trajectory:
    """Read the retroactive trend off the monthly series (already sorted by month).

    - current / peak / trough: latest, max, min score (earliest month wins a tie).
    - net_change_6m / _12m: current score minus the score 6 / 12 calendar months back
      (the most recent month at-or-before that target, so gaps don't break it); None if
      no such month exists.
    - classification: from the longer available trailing net (12m, else 6m, else whole
      series). |net| <= TRAJECTORY_STABLE_BAND -> "stable"; else improving / declining.
    - trend_since: for a rising/falling run, the month it began (walking back while each
      monthly step agrees with the run direction within TRAJECTORY_STEP_TOLERANCE); for a
      stable run, the earliest trailing month still within the band of the current score.
    """
    pts = [_Point(s.month, s.score) for s in scores]
    n = len(pts)
    if n == 0:
        return Trajectory(0, None, None, None, None, None, CLASSIFICATION_TOO_YOUNG, None)

    by_month = {p.month: p.score for p in pts}
    current = pts[-1]
    peak = min((p for p in pts), key=lambda p: (-p.score, p.month))
    trough = min((p for p in pts), key=lambda p: (p.score, p.month))

    six = _score_at_or_before(by_month, _month_minus(current.month, 6))
    twelve = _score_at_or_before(by_month, _month_minus(current.month, 12))
    net6 = (current.score - six) if six is not None else None
    net12 = (current.score - twelve) if twelve is not None else None

    if n < 2:
        return Trajectory(
            n, current, peak, trough, net6, net12, CLASSIFICATION_TOO_YOUNG, current.month
        )

    ref_net = (
        net12 if net12 is not None else net6 if net6 is not None else (current.score - pts[0].score)
    )
    if ref_net > TRAJECTORY_STABLE_BAND:
        classification = "improving"
        direction = 1
    elif ref_net < -TRAJECTORY_STABLE_BAND:
        classification = "declining"
        direction = -1
    else:
        classification = "stable"
        direction = 0

    trend_since = _trend_since(pts, direction, current.score)
    return Trajectory(n, current, peak, trough, net6, net12, classification, trend_since)


def _trend_since(pts: Sequence[_Point], direction: int, current_score: int) -> str:
    """Month the current run began (see summarize_trajectory)."""
    i = len(pts) - 1
    if direction == 0:
        # Stable: walk back while every month stays within the band of the current score.
        start = i
        while (
            start - 1 >= 0 and abs(current_score - pts[start - 1].score) <= TRAJECTORY_STABLE_BAND
        ):
            start -= 1
        return pts[start].month
    # Rising/falling: walk back while each step (forward in time) agrees with direction,
    # tolerating a small countermove so one noisy month doesn't sever the run.
    start = i
    while start - 1 >= 0:
        step = pts[start].score - pts[start - 1].score  # change from prev -> this month
        if direction > 0 and step >= -TRAJECTORY_STEP_TOLERANCE:
            start -= 1
        elif direction < 0 and step <= TRAJECTORY_STEP_TOLERANCE:
            start -= 1
        else:
            break
    return pts[start].month


# --------------------------------------------------------------------------- #
# Back-test: does the score lead future rework?
# --------------------------------------------------------------------------- #


def _rank(values: Sequence[float]) -> list[float]:
    """Fractional (average-of-ties) ranks, 1-based. Deterministic."""
    order = sorted(range(len(values)), key=lambda i: values[i])
    ranks = [0.0] * len(values)
    i = 0
    while i < len(order):
        j = i
        while j + 1 < len(order) and values[order[j + 1]] == values[order[i]]:
            j += 1
        avg = (i + j) / 2.0 + 1.0  # mean of the tied 1-based positions
        for k in range(i, j + 1):
            ranks[order[k]] = avg
        i = j + 1
    return ranks


def _pearson(x: Sequence[float], y: Sequence[float]) -> float | None:
    n = len(x)
    if n < 2:
        return None
    mx = sum(x) / n
    my = sum(y) / n
    num = sum((a - mx) * (b - my) for a, b in zip(x, y, strict=True))
    dx = sum((a - mx) ** 2 for a in x)
    dy = sum((b - my) ** 2 for b in y)
    if dx == 0.0 or dy == 0.0:
        return None
    return num / math.sqrt(dx * dy)


def spearman(x: Sequence[float], y: Sequence[float]) -> float | None:
    """Spearman rank correlation: Pearson on average-tie ranks. None if < 2 points or
    a variable is constant. No scipy — deterministic by construction."""
    if len(x) != len(y) or len(x) < 2:
        return None
    return _pearson(_rank(x), _rank(y))


@dataclass(frozen=True)
class BacktestMonth:
    month: str
    score: int
    components: dict[str, float]
    forward_rework: float
    forward_n: int


@dataclass(frozen=True)
class BacktestResult:
    n_months: int
    forward_months: int
    spearman_score_vs_forward_rework: float | None
    spearman_components: dict[str, float | None]
    quartile: dict[str, float | int | None]
    months: list[BacktestMonth]


def _forward_rework(
    metrics: Sequence[_CommitMetrics], start_excl: date, end_incl: date
) -> tuple[float, int]:
    """Aggregate rework rate over commits in (start_excl, end_incl]. Returns (rate, n)."""
    window = [m for m in metrics if start_excl < m.day <= end_incl]
    reworked = sum(m.reworked_lines for m in window)
    new = sum(m.new_lines for m in window)
    denom = reworked + new
    return ((reworked / denom) if denom else 0.0), len(window)


def _forward_for_month(
    metrics: Sequence[_CommitMetrics],
    month: str,
    now: date,
    forward_months: int,
    min_commits: int,
) -> tuple[float, int] | None:
    """The forward outcome for a scored month, or None if the month is not back-testable
    (forward window not fully observable by `now`, or too few forward commits). Shared by
    the back-test and the coverage counter so they cannot disagree on what counts."""
    y, mo = int(month[:4]), int(month[5:7])
    t_end = _month_last_day(y, mo)
    fy, fmo = _add_months(y, mo, forward_months)
    fwd_end = _month_last_day(fy, fmo)
    if fwd_end > now:
        return None  # forward window not fully observable yet
    fwd_rate, fwd_n = _forward_rework(metrics, t_end, fwd_end)
    if fwd_n < min_commits:
        return None
    return fwd_rate, fwd_n


def count_backtestable_months(
    scores: Sequence[MonthScore],
    metrics: Sequence[_CommitMetrics],
    now: date,
    forward_months: int,
    min_commits: int,
) -> int:
    """How many scored months have a fully-observed forward window — i.e. the back-test's
    ``n_months``, computed without building the rows (used for the coverage block even
    when ``--backtest`` is not passed)."""
    return sum(
        1
        for ms in scores
        if _forward_for_month(metrics, ms.month, now, forward_months, min_commits) is not None
    )


def backtest(
    repo: Path,
    now: date,
    ref: str | None = None,
    trailing_weeks: int = 12,
    churn_window_days: int = 21,
    heat_lookback_weeks: int = 8,
    forward_months: int = 3,
    since: date | None = None,
    until: date | None = None,
    min_commits: int = 3,
    weights: dict[str, float] | None = None,
    bands: Bands = DEFAULT_BANDS,
    jobs: int = 1,
    _metrics: Sequence[_CommitMetrics] | None = None,
) -> BacktestResult:
    """For each scored month T with a fully-observed forward window (T+1..T+forward_months
    all <= now), pair score(T) with the forward rework rate over that window, then report
    Spearman correlations (overall + per component) and a quartile summary.

    A *negative* score↔forward-rework correlation means a low score predicts more future
    rework — i.e. the score works as a leading indicator. Components are healthy-oriented
    (high = good), so a predictive component also correlates negatively with forward rework.

    `_metrics` lets a caller pass a precomputed per-commit pass (the same one used for the
    score series) so the back-test does not re-blame the whole history a second time.
    """
    resolved = resolve_ref(repo, ref)
    heat_lookback_days = heat_lookback_weeks * _DAYS_PER_WEEK

    if _metrics is None:
        metrics, _ = compute_metrics_pass(
            repo,
            resolved,
            trailing_weeks=trailing_weeks,
            churn_window_days=churn_window_days,
            heat_lookback_days=heat_lookback_days,
            since=since,
            until=until,
            jobs=jobs,
        )
    else:
        metrics = list(_metrics)

    scored = score_months(
        repo,
        now,
        ref=resolved,
        trailing_weeks=trailing_weeks,
        churn_window_days=churn_window_days,
        heat_lookback_weeks=heat_lookback_weeks,
        since=since,
        until=until,
        min_commits=min_commits,
        weights=weights,
        bands=bands,
        _metrics=metrics,
    )

    rows: list[BacktestMonth] = []
    for ms in scored:
        forward = _forward_for_month(metrics, ms.month, now, forward_months, min_commits)
        if forward is None:
            continue
        fwd_rate, fwd_n = forward
        rows.append(
            BacktestMonth(
                month=ms.month,
                score=ms.score,
                components=ms.components,
                forward_rework=round(fwd_rate, 6),
                forward_n=fwd_n,
            )
        )

    scores = [float(r.score) for r in rows]
    forwards = [r.forward_rework for r in rows]
    comp_corr: dict[str, float | None] = {
        k: spearman([r.components[k] for r in rows], forwards) for k in COMPONENTS
    }
    quartile = _quartile_summary(rows)

    return BacktestResult(
        n_months=len(rows),
        forward_months=forward_months,
        spearman_score_vs_forward_rework=spearman(scores, forwards),
        spearman_components=comp_corr,
        quartile=quartile,
        months=rows,
    )


def _quartile_summary(rows: Sequence[BacktestMonth]) -> dict[str, float | int | None]:
    """Mean forward rework of the bottom-score quartile vs. the top-score quartile."""
    if len(rows) < 4:
        return {
            "n_per_quartile": 0,
            "bottom_mean_forward_rework": None,
            "top_mean_forward_rework": None,
            "ratio": None,
        }
    ordered = sorted(rows, key=lambda r: r.score)
    q = len(ordered) // 4
    bottom = ordered[:q]
    top = ordered[-q:]
    bm = sum(r.forward_rework for r in bottom) / len(bottom)
    tm = sum(r.forward_rework for r in top) / len(top)
    ratio = (bm / tm) if tm > 0 else None
    return {
        "n_per_quartile": q,
        "bottom_mean_forward_rework": round(bm, 6),
        "top_mean_forward_rework": round(tm, 6),
        "ratio": round(ratio, 4) if ratio is not None else None,
    }


# --------------------------------------------------------------------------- #
# Coverage + confidence: is there enough history to trust the score?
# --------------------------------------------------------------------------- #

# How sure are we that the score/trajectory mean anything for this repo?
#   none — nothing scoreable (a brand-new repo whose only history is the current,
#          not-yet-complete month). Suppress any headline score: "too new to score."
#   low  — a score exists but is provisional: fewer than OK_MIN_SCORED scored months, or
#          no month yet has a fully-observed forward window to validate against. Show the
#          number, but badge it "provisional — no validated trend."
#   ok   — enough scored months AND enough back-testable months for the trajectory and the
#          back-test correlations to be meaningful.
CONFIDENCE_NONE = "none"
CONFIDENCE_LOW = "low"
CONFIDENCE_OK = "ok"

COVERAGE_OK_MIN_SCORED_MONTHS = 6
COVERAGE_OK_MIN_BACKTESTED_MONTHS = 4


@dataclass(frozen=True)
class Coverage:
    """How much observable history backs the score, and a derived confidence band. Always
    emitted (even with zero scored months) so an empty result is a legible status, not an
    ambiguous blank that reads like a crash."""

    scored_months: int  # months in the score series
    backtested_months: int  # scored months with a fully-observed forward window
    history_days: int  # span of the commits considered, inclusive (0 if none)
    current_month_excluded: bool  # True when `now` is mid-month, so this month isn't scored
    skipped_commits: int  # commits dropped because git failed even after retries
    confidence: str  # CONFIDENCE_NONE | CONFIDENCE_LOW | CONFIDENCE_OK


def classify_confidence(scored_months: int, backtested_months: int) -> str:
    """Map coverage counts to a confidence band (see the CONFIDENCE_* docs above)."""
    if scored_months == 0:
        return CONFIDENCE_NONE
    if (
        scored_months >= COVERAGE_OK_MIN_SCORED_MONTHS
        and backtested_months >= COVERAGE_OK_MIN_BACKTESTED_MONTHS
    ):
        return CONFIDENCE_OK
    return CONFIDENCE_LOW


def _history_days(metrics: Sequence[_CommitMetrics]) -> int:
    """Inclusive day span of the commits considered (max - min day + 1); 0 if none."""
    if not metrics:
        return 0
    days = [m.day for m in metrics]
    return (max(days) - min(days)).days + 1


def build_coverage(
    scores: Sequence[MonthScore],
    metrics: Sequence[_CommitMetrics],
    now: date,
    *,
    backtested_months: int,
    skipped_commits: int,
) -> Coverage:
    """Assemble the coverage block from the scored series, the metrics pass, and `now`."""
    scored_months = len(scores)
    return Coverage(
        scored_months=scored_months,
        backtested_months=backtested_months,
        history_days=_history_days(metrics),
        current_month_excluded=now < _month_last_day(now.year, now.month),
        skipped_commits=skipped_commits,
        confidence=classify_confidence(scored_months, backtested_months),
    )


# --------------------------------------------------------------------------- #
# Output
# --------------------------------------------------------------------------- #

_CSV_FIELDS = ["month", "score", "n", *COMPONENTS]


def write_outputs(scores: Sequence[MonthScore], out_dir: Path) -> tuple[Path, Path]:
    """Write score.json (array) + score.csv (flat). Returns the two paths."""
    out_dir.mkdir(parents=True, exist_ok=True)
    json_path = out_dir / "score.json"
    csv_path = out_dir / "score.csv"

    json_path.write_text(
        json.dumps([asdict(s) for s in scores], indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

    with csv_path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=_CSV_FIELDS)
        writer.writeheader()
        for s in scores:
            row: dict[str, object] = {"month": s.month, "score": s.score, "n": s.n}
            row.update(s.components)
            writer.writerow(row)

    return json_path, csv_path


def write_trajectory(trajectory: Trajectory, now: date, out_dir: Path) -> Path:
    """Write score_trajectory.json (kept a sibling of score.json so the array schema of
    score.json stays stable). Returns the path."""
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / "score_trajectory.json"
    payload: dict[str, object] = {
        "now": now.isoformat(),
        "n_months": trajectory.n_months,
        "current": asdict(trajectory.current) if trajectory.current else None,
        "peak": asdict(trajectory.peak) if trajectory.peak else None,
        "trough": asdict(trajectory.trough) if trajectory.trough else None,
        "net_change_6m": trajectory.net_change_6m,
        "net_change_12m": trajectory.net_change_12m,
        "classification": trajectory.classification,
        "trend_since": trajectory.trend_since,
    }
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path


def write_coverage(coverage: Coverage, now: date, out_dir: Path) -> Path:
    """Write score_coverage.json (always written, including the zero-month case). Returns
    the path."""
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / "score_coverage.json"
    payload: dict[str, object] = {"now": now.isoformat(), **asdict(coverage)}
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path


def write_backtest(result: BacktestResult, out_dir: Path) -> Path:
    """Write score_backtest.json. Returns the path."""
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / "score_backtest.json"
    payload = {
        "n_months": result.n_months,
        "forward_months": result.forward_months,
        "spearman_score_vs_forward_rework": result.spearman_score_vs_forward_rework,
        "spearman_components": result.spearman_components,
        "quartile": result.quartile,
        "months": [asdict(m) for m in result.months],
    }
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #


def _parse_weights(spec: str | None) -> dict[str, float]:
    """Parse a `--weights name=val,...` override, merged over the defaults."""
    weights = dict(DEFAULT_WEIGHTS)
    if not spec:
        return weights
    for part in spec.split(","):
        part = part.strip()
        if not part:
            continue
        if "=" not in part:
            raise argparse.ArgumentTypeError(f"bad weight '{part}', expected name=value")
        name, _, value = part.partition("=")
        name = name.strip()
        if name not in DEFAULT_WEIGHTS:
            raise argparse.ArgumentTypeError(
                f"unknown component '{name}'; valid: {', '.join(COMPONENTS)}"
            )
        try:
            weights[name] = float(value)
        except ValueError as exc:
            raise argparse.ArgumentTypeError(f"bad weight value for '{name}': {value}") from exc
    return weights


def _parse_args(argv: Sequence[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="python -m settle.score",
        description="Compute a monthly durability score (300-850) from git history; "
        "--backtest validates it as a leading indicator of future rework.",
    )
    parser.add_argument("--repo", required=True, type=Path, help="Path to the target git repo.")
    parser.add_argument(
        "--out", type=Path, default=None, help="Output dir (default: data/<repo-basename>/)."
    )
    parser.add_argument(
        "--now",
        required=True,
        type=_parse_day,
        help="Analysis anchor (YYYY-MM-DD). Only months whose last day is <= now are scored.",
    )
    parser.add_argument(
        "--ref",
        default="origin/HEAD",
        help="Branch/ref to walk (default: origin/HEAD — the remote's default branch, so "
        "master/develop/trunk repos work without flags).",
    )
    parser.add_argument(
        "--trailing-weeks", type=int, default=12, help="Trailing window for score inputs (weeks)."
    )
    parser.add_argument(
        "--churn-window-days",
        type=int,
        default=21,
        help="A removed line is rework if introduced within this many days (default 21).",
    )
    parser.add_argument(
        "--heat-lookback-weeks",
        type=int,
        default=8,
        help="Substrate-heat lookback: prior churn of a commit's files (default 8).",
    )
    parser.add_argument(
        "--min-commits",
        type=int,
        default=3,
        help="Skip a month whose trailing window has fewer than this many commits (default 3).",
    )
    parser.add_argument(
        "--weights",
        type=_parse_weights,
        default=None,
        help='Weight overrides, e.g. "rework=0.4,scope=0.1" (merged over defaults).',
    )
    parser.add_argument(
        "--since", type=_parse_day, default=None, help="Only score months on/after this date."
    )
    parser.add_argument(
        "--until", type=_parse_day, default=None, help="Only score months on/before this date."
    )
    parser.add_argument(
        "--jobs",
        type=int,
        default=8,
        help="Parallel git workers (threads). Output is identical regardless (default 8).",
    )
    parser.add_argument(
        "--backtest",
        action="store_true",
        help="Also run the back-test (score vs. forward rework) and write score_backtest.json.",
    )
    parser.add_argument(
        "--forward-months",
        type=int,
        default=3,
        help="Back-test forward outcome window, in months after T (default 3).",
    )
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = _parse_args(argv)
    repo: Path = args.repo
    out_dir: Path = args.out or Path("data") / repo.resolve().name
    weights: dict[str, float] = args.weights or dict(DEFAULT_WEIGHTS)

    # Blame the history once and share that pass between the score series and the
    # back-test (the back-test would otherwise re-blame everything a second time).
    resolved = resolve_ref(repo, args.ref)
    metrics, skipped_commits = compute_metrics_pass(
        repo,
        resolved,
        trailing_weeks=args.trailing_weeks,
        churn_window_days=args.churn_window_days,
        heat_lookback_days=args.heat_lookback_weeks * _DAYS_PER_WEEK,
        since=args.since,
        until=args.until,
        jobs=args.jobs,
    )

    scores = score_months(
        repo=repo,
        now=args.now,
        ref=resolved,
        trailing_weeks=args.trailing_weeks,
        churn_window_days=args.churn_window_days,
        heat_lookback_weeks=args.heat_lookback_weeks,
        since=args.since,
        until=args.until,
        min_commits=args.min_commits,
        weights=weights,
        _metrics=metrics,
    )
    json_path, csv_path = write_outputs(scores, out_dir)
    if scores:
        lo = min(s.score for s in scores)
        hi = max(s.score for s in scores)
        print(
            f"Scored {len(scores)} month(s) for {repo} "
            f"({scores[0].month}..{scores[-1].month}; score {lo}-{hi}).",
            file=sys.stderr,
        )
    else:
        print(
            f"No months scored for {repo} — too new or too sparse "
            "(see score_coverage.json; this is a clean status, not a crash).",
            file=sys.stderr,
        )
    print(f"Wrote {json_path}")
    print(f"Wrote {csv_path}")

    trajectory = summarize_trajectory(scores)
    traj_path = write_trajectory(trajectory, args.now, out_dir)
    if trajectory.current is not None:
        peak = trajectory.peak
        trough = trajectory.trough
        bits = [f"current {trajectory.current.score} ({trajectory.current.month})"]
        if peak is not None and trough is not None:
            bits.append(f"peak {peak.score} ({peak.month})")
            bits.append(f"trough {trough.score} ({trough.month})")
        print(
            f"Trajectory: {trajectory.classification} since {trajectory.trend_since}; "
            + ", ".join(bits)
            + f"; net 6mo={trajectory.net_change_6m}, 12mo={trajectory.net_change_12m}.",
            file=sys.stderr,
        )
    print(f"Wrote {traj_path}")

    # Coverage + confidence — always written (even with zero scored months) so a thin/young
    # repo yields a legible status rather than an ambiguous empty result. backtested_months
    # is computed here (matches the back-test's n_months by construction) so the block is
    # complete whether or not --backtest was passed.
    backtested_months = count_backtestable_months(
        scores, metrics, args.now, args.forward_months, args.min_commits
    )
    coverage = build_coverage(
        scores,
        metrics,
        args.now,
        backtested_months=backtested_months,
        skipped_commits=skipped_commits,
    )
    cov_path = write_coverage(coverage, args.now, out_dir)
    status_bits = [
        f"confidence={coverage.confidence}",
        f"scored_months={coverage.scored_months}",
        f"backtested_months={coverage.backtested_months}",
        f"history_days={coverage.history_days}",
        f"current_month_excluded={coverage.current_month_excluded}",
    ]
    if coverage.skipped_commits:
        status_bits.append(f"skipped_commits={coverage.skipped_commits}")
    print("Coverage: " + ", ".join(status_bits) + ".", file=sys.stderr)
    print(f"Wrote {cov_path}")

    if args.backtest:
        result = backtest(
            repo=repo,
            now=args.now,
            ref=resolved,
            trailing_weeks=args.trailing_weeks,
            churn_window_days=args.churn_window_days,
            heat_lookback_weeks=args.heat_lookback_weeks,
            forward_months=args.forward_months,
            since=args.since,
            until=args.until,
            min_commits=args.min_commits,
            weights=weights,
            _metrics=metrics,
        )
        bt_path = write_backtest(result, out_dir)
        rho = result.spearman_score_vs_forward_rework
        print(
            f"Back-test: {result.n_months} month(s); "
            f"Spearman(score, forward {result.forward_months}mo rework) = "
            + ("n/a" if rho is None else f"{rho:+.3f}"),
            file=sys.stderr,
        )
        q = result.quartile
        if q.get("ratio") is not None:
            print(
                f"  Bottom-quartile months had {q['ratio']}x the forward rework of "
                f"top-quartile months "
                f"({q['bottom_mean_forward_rework']} vs {q['top_mean_forward_rework']}).",
                file=sys.stderr,
            )
        for k in COMPONENTS:
            cc = result.spearman_components[k]
            print(f"  Spearman({k}) = " + ("n/a" if cc is None else f"{cc:+.3f}"), file=sys.stderr)
        print(f"Wrote {bt_path}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
