"""rework_rate — are we rewriting code we just shipped? (line-level churn)

A deterministic git-history miner. For every non-merge commit on a repository's
default branch it splits the commit's changed lines into **new** output vs.
**rework** — lines that overwrite or delete code introduced only a few weeks
earlier. This is the GitClear-style short-window churn signal: net-new lines are
progress; lines rewriting recently-shipped lines are the team correcting itself.

It is the cleaner, less-confounded companion to ``post_merge_churn``: it is keyed
on the *age of the code being overwritten* (via ``git blame``), so it is not
confused by new-area development or near-zero per-PR baselines.

Pure and reproducible: same repo + same args produce byte-identical output. No
network, no LLM, no wall clock — there is no "now" anchor at all because every
classification compares two commit dates that already live in the history.

Algorithm
---------
1. Walk ``--ref`` (the default branch) for non-merge commits, bounded inclusively
   by ``--since`` / ``--until`` on the committer date (``YYYY-MM-DD``). Each
   commit's month bucket is the ``YYYY-MM`` of its committer date.
2. For each commit ``C`` with parent ``P`` (the single parent of a non-merge
   commit; the empty tree for a root commit), diff ``P..C`` with
   ``--unified=0 --no-renames``. ``--no-renames`` keeps paths plain (a rename is
   a delete+add); ``-C``/``-M`` move detection is off for v1.
3. Each hunk header ``@@ -a,b +c,d @@`` means ``b`` lines removed from ``P``
   starting at line ``a``, and ``d`` lines added in ``C``. Binary files emit no
   hunks and so contribute nothing (text files only, by construction).
4. **Rework** is decided on the *removed* side. ``git blame`` the parent ``P`` at
   the removed line ranges (one batched, range-limited blame per file — far
   cheaper than a full-file blame) and read each removed line's introducing
   commit date straight from the porcelain ``committer-time``/``committer-tz``.
   A removed line is *recent* if it was introduced ``[0, churn_window_days)``
   days before ``C`` (same-day counts; exactly ``churn_window_days`` ago does
   not). Let ``recent`` be the count of recent removed lines in a hunk.
5. Per hunk we attribute:
       reworked_lines += recent                  # recent code this hunk overwrites/deletes
       new_lines      += max(0, d - recent)      # added lines beyond replacing recent code
   Consequences of this rule (documented because the additions-vs-modifications
   split is a judgement call):
     - Pure addition (``b == 0``): ``recent == 0`` → all ``d`` added lines are new.
     - 1-for-1 rewrite of a recent line: ``d == recent == 1`` → 1 rework, 0 new.
     - Pure deletion of ``recent`` recent lines (``d == 0``): ``recent`` rework, 0 new.
     - Editing *old* code (introduced before the window, or replacing more lines
       than were recent): the removed old lines count as neither; the surviving
       added lines (``d - recent``) count as new. "Lines added on top of old /
       nonexistent code are new_lines."

Both dates use the committer date (``--date=short`` for ``C``; porcelain
``committer-time`` + ``committer-tz`` for each blamed line, converted to the
commit's local wall-clock day) so the two sides are compared on the same basis
and the result is timezone-stable.

Output (written to ``--out``, default ``data/<repo-basename>/``)
---------------------------------------------------------------
``rework_rate.json`` — array of monthly buckets, sorted by ``month`` ascending.
This monthly series is the headline: it lets a skill chart rework_rate climbing
over time (pre-AI vs. AI-era). Per-bucket schema (also in ``skills/README.md``):

    month                 str        bucket month, ``YYYY-MM``
    commits               int        non-merge commits in the bucket
    new_lines             int        summed new (net-new output) lines
    reworked_lines        int        summed reworked (recently-shipped) lines
    rework_rate           float|null reworked_lines / (new_lines + reworked_lines);
                                     null when the denominator is 0
    excluded_file_changes int        per-commit file changes skipped as generated/vendored
    excluded_lines        int        their added+removed line count (transparency)

``rework_rate.csv`` — the same buckets, one flat row each.

Exclusion: generated/vendored files (lockfiles, minified bundles, ``vendor/`` /
``node_modules/`` / ``dist/`` / ``build/``, snapshots, and any path the repo's
``.gitattributes`` marks linguist-generated/-vendored) are left out of
``new_lines`` / ``reworked_lines`` so a single regenerated ``package-lock.json``
or bundle can't swamp a month. The shared rule lives in :mod:`settle.excludes`
(``DEFAULT_EXCLUDE_GLOBS``); ``--exclude-glob`` adds patterns, ``--no-default-excludes``
drops the built-ins, ``--no-gitattributes`` ignores attributes. The omitted churn
is surfaced in ``excluded_file_changes`` / ``excluded_lines`` rather than hidden.

Performance: cost is dominated by ``git blame`` (one range-limited blame per
changed file that has removals, per commit). The git subprocesses release the
GIL while running, so ``--jobs`` (default 8) classifies commits across a thread
pool for a near-linear speedup; the output is identical regardless of ``--jobs``.
ALWAYS bound a large repo with ``--since`` — a full unbounded history is mined
only with a loud stderr warning, never silently.
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import subprocess
import sys
import time
from collections.abc import Sequence
from concurrent.futures import ThreadPoolExecutor
from dataclasses import asdict, dataclass, field
from datetime import UTC, date, datetime, timedelta
from pathlib import Path

from settle.excludes import ExcludeMatcher, build_matcher

# The empty tree sha — used as the "parent" of a root commit so its diff is an
# all-additions diff (no removed lines, hence no rework and no blame).
_EMPTY_TREE = "4b825dc642cb6eb9a060e54bf8d69288fbee4904"

# ASCII unit separator — safe field delimiter for `git log --pretty` output; it
# cannot appear in a sha, ISO date, or parent list.
_SEP = "\x1f"

# Unified-diff hunk header: @@ -a[,b] +c[,d] @@. Group b/d default to 1 when omitted.
_HUNK_RE = re.compile(r"^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@")


@dataclass(frozen=True)
class MonthBucket:
    month: str
    commits: int
    new_lines: int
    reworked_lines: int
    rework_rate: float | None
    excluded_file_changes: int
    excluded_lines: int


@dataclass(frozen=True)
class _Commit:
    sha: str
    parent: str | None
    day: date


@dataclass
class _FileDiff:
    parent_path: str | None = None  # old path (for blame); None for added files
    child_path: str | None = None  # new path; None for deleted files
    # (a, b, d): removed start line in parent, removed count, added count.
    hunks: list[tuple[int, int, int]] = field(default_factory=list)

    @property
    def exclude_path(self) -> str | None:
        """The canonical path to test for exclusion (new path, else old path)."""
        return self.child_path if self.child_path is not None else self.parent_path


# On a partial/blobless clone (``git clone --filter=blob:none``) every uncached blob a
# ``diff``/``blame`` needs is fetched lazily from the promisor remote; a transient fetch
# failure makes the git call exit non-zero. A bounded retry with exponential backoff
# absorbs these flakes instead of aborting the whole run. The backoff only sleeps (it
# never alters command output), so OUTPUT stays byte-identical/deterministic — only
# wall-clock latency varies. After the attempts are exhausted the final error is raised
# so callers can decide to skip that one object rather than crash.
_GIT_MAX_ATTEMPTS = 3
_GIT_BACKOFF_BASE_SECONDS = 0.5


def _git(repo: Path, *args: str, attempts: int = _GIT_MAX_ATTEMPTS) -> str:
    """Run a read-only git command in `repo` and return stdout (text).

    Retries up to `attempts` times with exponential backoff so a transient promisor
    blob fetch on a partial clone does not abort the run; re-raises the final
    CalledProcessError if every attempt fails. `attempts=1` disables retrying (used for
    calls whose failure is an expected control-flow signal, e.g. resolve_ref's probe)."""
    cmd = ["git", "-C", str(repo), "-c", "core.quotePath=false", *args]
    last_exc: subprocess.CalledProcessError | None = None
    for attempt in range(1, attempts + 1):
        try:
            result = subprocess.run(cmd, check=True, capture_output=True, text=True)
            return result.stdout
        except subprocess.CalledProcessError as exc:
            last_exc = exc
            if attempt < attempts:
                time.sleep(_GIT_BACKOFF_BASE_SECONDS * (2 ** (attempt - 1)))
    assert last_exc is not None  # the loop body runs at least once (attempts >= 1)
    raise last_exc


def _parse_day(value: str) -> date:
    """Parse a YYYY-MM-DD git short date into a date."""
    return date.fromisoformat(value.strip())


def resolve_ref(repo: Path, ref: str | None) -> str:
    """Resolve which ref to walk. Explicit --ref wins; else the remote's default
    branch (origin/HEAD); else local HEAD."""
    if ref:
        return ref
    try:
        out = _git(repo, "symbolic-ref", "--short", "refs/remotes/origin/HEAD", attempts=1).strip()
        if out:
            return out
    except subprocess.CalledProcessError:
        pass
    return "HEAD"


def list_commits(
    repo: Path,
    ref: str,
    since: date | None = None,
    until: date | None = None,
) -> list[_Commit]:
    """Non-merge commits on `ref`, inclusively bounded by since/until on the
    committer day (filtered in Python for exact, timezone-stable day semantics)."""
    out = _git(
        repo,
        "log",
        "--no-merges",
        "--no-color",
        "--date=short",
        f"--pretty=format:%H{_SEP}%P{_SEP}%cd",
        ref,
    )
    commits: list[_Commit] = []
    for line in out.splitlines():
        if not line:
            continue
        sha, parents, day_str = line.split(_SEP, 2)
        day = _parse_day(day_str)
        if since is not None and day < since:
            continue
        if until is not None and day > until:
            continue
        first_parent = parents.split(" ")[0] if parents.strip() else None
        commits.append(_Commit(sha=sha, parent=first_parent, day=day))
    return commits


def _parse_diff_hunks(diff: str) -> list[_FileDiff]:
    """Parse a `git diff --unified=0` into per-file (parent_path, hunks)."""
    files: list[_FileDiff] = []
    cur: _FileDiff | None = None
    for line in diff.split("\n"):
        if line.startswith("diff --git "):
            cur = _FileDiff()
            files.append(cur)
        elif cur is None:
            continue
        elif line.startswith("--- ") and not cur.hunks:
            # The "--- a/path" old-file header only appears before the first hunk;
            # once hunks start, a "--- ..." line is a removed line of content
            # (a "-"-prefixed body line that itself began with "--").
            p = line[4:].strip()
            cur.parent_path = None if p == "/dev/null" else (p[2:] if p.startswith("a/") else p)
        elif line.startswith("+++ ") and not cur.hunks:
            # The "+++ b/path" new-file header — same pre-hunk gate as "--- ".
            p = line[4:].strip()
            cur.child_path = None if p == "/dev/null" else (p[2:] if p.startswith("b/") else p)
        elif line.startswith("@@ "):
            m = _HUNK_RE.match(line)
            if not m:
                continue
            a = int(m.group(1))
            b = int(m.group(2)) if m.group(2) is not None else 1
            d = int(m.group(4)) if m.group(4) is not None else 1
            cur.hunks.append((a, b, d))
    return files


def _tz_seconds(tz: str) -> int:
    """Convert a git porcelain tz string (+HHMM / -HHMM) to a signed offset in seconds."""
    sign = -1 if tz.startswith("-") else 1
    digits = tz.lstrip("+-")
    hours = int(digits[:2])
    minutes = int(digits[2:4])
    return sign * (hours * 3600 + minutes * 60)


def _epoch_local_date(epoch: int, tz_seconds: int) -> date:
    """Local wall-clock date for a committer-time epoch + tz offset (matches %cd)."""
    return (datetime.fromtimestamp(epoch, tz=UTC) + timedelta(seconds=tz_seconds)).date()


def _is_sha(token: str) -> bool:
    if len(token) != 40:
        return False
    try:
        int(token, 16)
    except ValueError:
        return False
    return True


def blame_line_days(
    repo: Path,
    rev: str,
    path: str,
    ranges: Sequence[tuple[int, int]],
) -> dict[int, date]:
    """Blame `path` at `rev` over the given (start, end) line ranges and return a
    map of parent-file line number -> the introducing commit's committer day.

    One range-limited porcelain blame per file (not a full-file blame). The
    introducing date is read straight from the porcelain output, so no follow-up
    per-line lookups are needed."""
    args = ["blame", "--porcelain", rev]
    for start, end in ranges:
        args += ["-L", f"{start},{end}"]
    args += ["--", path]
    out = _git(repo, *args)

    result: dict[int, date] = {}
    sha_epoch: dict[str, int] = {}
    sha_tz: dict[str, int] = {}
    cur_sha: str | None = None
    cur_line: int | None = None

    for line in out.split("\n"):
        if not line:
            continue
        if line[0] == "\t":
            # Content line: cur_sha/cur_line are now fully known.
            if cur_sha is not None and cur_line is not None:
                epoch = sha_epoch.get(cur_sha)
                if epoch is not None:
                    result[cur_line] = _epoch_local_date(epoch, sha_tz.get(cur_sha, 0))
            continue
        head = line.split(" ")
        if _is_sha(head[0]) and len(head) >= 3:
            cur_sha = head[0]
            cur_line = int(head[2])  # line number in the blamed (parent) file
        elif line.startswith("committer-time ") and cur_sha is not None:
            sha_epoch[cur_sha] = int(line.split(" ", 1)[1])
        elif line.startswith("committer-tz ") and cur_sha is not None:
            sha_tz[cur_sha] = _tz_seconds(line.split(" ", 1)[1])
    return result


def classify_commit(
    repo: Path,
    commit: _Commit,
    churn_window_days: int,
    matcher: ExcludeMatcher,
) -> tuple[int, int, int, int]:
    """Return (new_lines, reworked_lines, excluded_file_changes, excluded_lines)
    for a single commit. Generated/vendored files (per `matcher`) are skipped from
    the line counts and instead tallied into the excluded_* totals so the omission
    is transparent (see module docstring)."""
    parent = commit.parent or _EMPTY_TREE
    diff = _git(repo, "diff", "--unified=0", "--no-color", "--no-renames", parent, commit.sha)

    new_lines = 0
    reworked_lines = 0
    excluded_file_changes = 0
    excluded_lines = 0
    for f in _parse_diff_hunks(diff):
        path = f.exclude_path
        if path is not None and matcher.is_excluded(path):
            excluded_file_changes += 1
            excluded_lines += sum(b + d for (_a, b, d) in f.hunks)
            continue

        ranges = [(a, a + b - 1) for (a, b, _d) in f.hunks if b > 0]
        line_days: dict[int, date] = {}
        # Blame only when there are removed lines and the file existed in the parent.
        if ranges and f.parent_path is not None and commit.parent is not None:
            line_days = blame_line_days(repo, parent, f.parent_path, ranges)

        for a, b, d in f.hunks:
            recent = 0
            for ln in range(a, a + b):
                intro = line_days.get(ln)
                if intro is not None:
                    delta = (commit.day - intro).days
                    if 0 <= delta < churn_window_days:
                        recent += 1
            reworked_lines += recent
            new_lines += max(0, d - recent)
    return new_lines, reworked_lines, excluded_file_changes, excluded_lines


def mine(
    repo: Path,
    ref: str | None = None,
    churn_window_days: int = 21,
    since: date | None = None,
    until: date | None = None,
    jobs: int = 1,
    matcher: ExcludeMatcher | None = None,
    exclude_globs: Sequence[str] = (),
    use_default_excludes: bool = True,
    use_gitattributes: bool = True,
) -> list[MonthBucket]:
    """Mine monthly rework buckets for non-merge commits on `ref` (bounded by
    since/until on the committer date). Returns buckets sorted by month.

    Generated/vendored files are excluded from the line counts via an
    :class:`~settle.excludes.ExcludeMatcher`. Pass one explicitly as ``matcher``,
    or let ``mine`` build one from ``exclude_globs`` / ``use_default_excludes`` /
    ``use_gitattributes``. The omitted churn is reported in each bucket's
    ``excluded_file_changes`` / ``excluded_lines`` so the exclusion is transparent.

    ``jobs`` > 1 classifies commits across a thread pool. Each commit's cost is
    dominated by ``git`` subprocesses (which release the GIL while running), so
    threads parallelize the I/O-bound work; the output is unaffected by ``jobs``
    because per-month sums are order-independent."""
    resolved = resolve_ref(repo, ref)
    if matcher is None:
        matcher = build_matcher(
            repo,
            resolved,
            extra_globs=exclude_globs,
            use_defaults=use_default_excludes,
            use_gitattributes=use_gitattributes,
        )
    commits = list_commits(repo, resolved, since, until)

    if jobs > 1 and commits:
        with ThreadPoolExecutor(max_workers=jobs) as pool:
            classed = list(
                pool.map(lambda c: classify_commit(repo, c, churn_window_days, matcher), commits)
            )
    else:
        classed = [classify_commit(repo, c, churn_window_days, matcher) for c in commits]

    # month -> [commits, new_lines, reworked_lines, excluded_file_changes, excluded_lines]
    months: dict[str, list[int]] = {}
    for commit, (new_lines, reworked_lines, excl_changes, excl_lines) in zip(
        commits, classed, strict=True
    ):
        month = f"{commit.day.year:04d}-{commit.day.month:02d}"
        acc = months.setdefault(month, [0, 0, 0, 0, 0])
        acc[0] += 1
        acc[1] += new_lines
        acc[2] += reworked_lines
        acc[3] += excl_changes
        acc[4] += excl_lines

    buckets: list[MonthBucket] = []
    for month in sorted(months):
        count, new_lines, reworked_lines, excl_changes, excl_lines = months[month]
        denom = new_lines + reworked_lines
        rate = (reworked_lines / denom) if denom else None
        buckets.append(
            MonthBucket(
                month=month,
                commits=count,
                new_lines=new_lines,
                reworked_lines=reworked_lines,
                rework_rate=rate,
                excluded_file_changes=excl_changes,
                excluded_lines=excl_lines,
            )
        )
    return buckets


_CSV_FIELDS = [
    "month",
    "commits",
    "new_lines",
    "reworked_lines",
    "rework_rate",
    "excluded_file_changes",
    "excluded_lines",
]


def write_outputs(buckets: Sequence[MonthBucket], out_dir: Path) -> tuple[Path, Path]:
    """Write the JSON array and flat CSV. Returns the two paths."""
    out_dir.mkdir(parents=True, exist_ok=True)
    json_path = out_dir / "rework_rate.json"
    csv_path = out_dir / "rework_rate.csv"

    json_path.write_text(
        json.dumps([asdict(b) for b in buckets], indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

    with csv_path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=_CSV_FIELDS)
        writer.writeheader()
        for b in buckets:
            writer.writerow(asdict(b))

    return json_path, csv_path


def _parse_args(argv: Sequence[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="python -m settle.miners.rework_rate",
        description="Mine monthly line-level rework rate (short-window churn) from git history.",
    )
    parser.add_argument("--repo", required=True, type=Path, help="Path to the target git repo.")
    parser.add_argument(
        "--out",
        type=Path,
        default=None,
        help="Output dir (default: data/<repo-basename>/).",
    )
    parser.add_argument(
        "--ref",
        default="origin/main",
        help="Branch/ref to walk (default: origin/main).",
    )
    parser.add_argument(
        "--churn-window-days",
        type=int,
        default=21,
        help="A removed line is rework if introduced within this many days (default 21).",
    )
    parser.add_argument(
        "--since",
        type=_parse_day,
        default=None,
        help="Only commits on/after this date (YYYY-MM-DD).",
    )
    parser.add_argument(
        "--until",
        type=_parse_day,
        default=None,
        help="Only commits on/before this date (YYYY-MM-DD).",
    )
    parser.add_argument(
        "--jobs",
        type=int,
        default=8,
        help="Parallel git workers (threads). Output is identical regardless (default 8).",
    )
    parser.add_argument(
        "--exclude-glob",
        action="append",
        default=[],
        metavar="GLOB",
        dest="exclude_globs",
        help="Extra generated/vendored exclusion glob (repeatable; added to defaults).",
    )
    parser.add_argument(
        "--no-default-excludes",
        action="store_false",
        dest="use_default_excludes",
        help="Do not apply the built-in DEFAULT_EXCLUDE_GLOBS (only --exclude-glob, if any).",
    )
    parser.add_argument(
        "--no-gitattributes",
        action="store_false",
        dest="use_gitattributes",
        help="Do not honor .gitattributes linguist-generated / linguist-vendored.",
    )
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = _parse_args(argv)
    repo: Path = args.repo
    out_dir: Path = args.out or Path("data") / repo.resolve().name

    if args.since is None and args.until is None:
        print(
            f"WARNING: no --since/--until given; mining the FULL history of {args.ref}. "
            "git blame per changed file per commit is slow on large repos — bound with "
            "--since (e.g. --since 2024-01-01).",
            file=sys.stderr,
        )

    resolved = resolve_ref(repo, args.ref)
    matcher = build_matcher(
        repo,
        resolved,
        extra_globs=args.exclude_globs,
        use_defaults=args.use_default_excludes,
        use_gitattributes=args.use_gitattributes,
    )
    print(f"Excluding generated/vendored files: {matcher.describe()}.", file=sys.stderr)

    buckets = mine(
        repo=repo,
        ref=resolved,
        churn_window_days=args.churn_window_days,
        since=args.since,
        until=args.until,
        jobs=args.jobs,
        matcher=matcher,
    )
    json_path, csv_path = write_outputs(buckets, out_dir)

    total_commits = sum(b.commits for b in buckets)
    excluded_changes = sum(b.excluded_file_changes for b in buckets)
    excluded_lines = sum(b.excluded_lines for b in buckets)
    print(
        f"Processed {total_commits} non-merge commits from {repo} "
        f"into {len(buckets)} monthly buckets; excluded {excluded_changes} generated/vendored "
        f"file-change(s) totaling {excluded_lines} changed line(s).",
        file=sys.stderr,
    )
    print(f"Wrote {json_path}")
    print(f"Wrote {csv_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
