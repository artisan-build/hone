"""post_merge_churn — does a merged PR's file set settle, or keep churning?

A deterministic git-history miner. For each merged pull request on a repository's
default branch it measures whether the files the merge touched settle down to a
maintenance baseline after merge, or keep getting edited (and how much of that
post-merge editing is bugfixing). Pure and reproducible: same repo + same args
produce byte-identical output. No network, no LLM, no wall clock — the "now"
anchor is an explicit required argument.

Algorithm
---------
1. Walk first-parent history of ``--ref`` (the default branch). A merged PR is a
   merge commit whose subject matches ``MERGE_PR_RE`` (``Merge pull request #N
   from ORG/branch``). Capture PR number, merge sha, merge date, source branch.
2. **File set** of a PR = files changed by the merge itself:
   ``git diff --numstat --no-renames <merge>^1 <merge>``. ``--no-renames`` keeps
   paths plain (renames become delete+add) so the paths are usable as pathspecs.
   Binary files (numstat ``- -``) are counted but contribute 0 lines.
3. **Post-merge churn**: non-merge commits in ``<merge>..<ref>`` (everything on
   the branch *after* the merge — this excludes the PR's own commits and the
   merge itself) that touch any file in the set, bucketed by week for
   ``--window-weeks`` (default 12) after the merge date.
4. **Baseline**: non-merge commits touching the same file set in the
   ``--baseline-weeks`` (default 12) before the merge, walked from ``<merge>^1``
   so the PR's own branch commits are excluded. ``baseline_rate_per_week`` =
   baseline commit count / baseline weeks. Normalizes for legitimately hot files.
5. **Intent split**: each post-merge commit is classified bugfix vs. other by
   matching its subject against ``BUGFIX_RE``. Emits per-week and total counts.
6. **Settling metric**: ``settled_week`` = the first post-merge week W such that
   every observed week from W to the end of the window has weekly edits
   ``<=`` the baseline rate. ``null`` (``settled = false``) if it never settles
   within the observed window. Raw ``weekly_edits`` / ``weekly_bugfix_edits``
   arrays are emitted so a skill can recompute alternative thresholds.

Truncation: a PR whose full ``--window-weeks`` window extends past ``--now`` is
flagged ``window_truncated = true`` (and only its observed weeks are scored) —
never dropped.

Output (written to ``--out``, default ``data/<repo-basename>/``)
---------------------------------------------------------------
``post_merge_churn.json`` — array of per-PR records, sorted by (merge_date,
pr_number) ascending. Per-record schema (the contract consumed by skills; also
documented in ``skills/README.md``):

    pr_number              int     PR number from the merge subject
    merge_sha              str     full merge commit sha
    merge_date             str     committer date of the merge, YYYY-MM-DD
    source_branch          str     branch the PR merged from ("" if unparsed)
    file_count             int     number of files in the merge's file set
    files                  [str]   the file set (plain paths)
    lines_added            int     sum of added lines across the file set
    lines_removed          int     sum of removed lines across the file set
    binary_files           int     files with binary (numstat "- -") changes
    window_weeks           int     post-merge window size used
    baseline_weeks         int     baseline window size used
    now                    str     the --now anchor, YYYY-MM-DD
    window_end             str     merge_date + window_weeks*7 days, YYYY-MM-DD
    window_truncated       bool    true if window_end is after now
    observed_weeks         int     weeks actually scored (== window_weeks unless
                                   truncated)
    baseline_commit_count  int     non-merge commits to the file set in baseline
    baseline_rate_per_week float   baseline_commit_count / baseline_weeks
    post_merge_commit_count int    non-merge commits to the file set in window
    post_merge_bugfix_count int    of those, classified bugfix
    post_merge_bugfix_share float|null  bugfix_count / commit_count (null if 0)
    weekly_edits           [int]   length observed_weeks; commits per week
    weekly_bugfix_edits    [int]   length observed_weeks; bugfix commits per week
    settled_week           int|null first week after which edits stay <= baseline
    settled                bool    settled_week is not null

``post_merge_churn.csv`` — one flat row per PR with the scalar fields (the
``files``, ``weekly_edits`` and ``weekly_bugfix_edits`` arrays are omitted; the
weekly arrays are summarized by ``observed_weeks`` and the counts).
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import subprocess
from collections.abc import Iterable, Sequence
from dataclasses import asdict, dataclass
from datetime import date, timedelta
from pathlib import Path

# A merge commit GitHub creates for a squash-less "Merge pull request" lands with
# this subject. Capture the PR number and the source branch.
MERGE_PR_RE = re.compile(r"^Merge pull request #(\d+) from (\S+)")

# Heuristic for classifying a post-merge commit as corrective work (a bugfix)
# rather than feature/refactor iteration. Word-boundary matched, case-insensitive,
# against the commit subject. Sustained post-merge churn that is mostly bugfix is
# the "didn't stick the landing" signal; sustained feature churn is healthy.
BUGFIX_RE = re.compile(
    r"\b(?:fix|fixes|fixed|fixing|bug|bugfix|hotfix|patch|patches|patched|"
    r"regression|revert|reverts|reverted)\b",
    re.IGNORECASE,
)

_DAYS_PER_WEEK = 7
# ASCII unit separator — safe field delimiter for `git log --pretty` output, as it
# cannot appear in a sha, ISO date, or commit subject.
_SEP = "\x1f"


@dataclass(frozen=True)
class PrChurnRecord:
    pr_number: int
    merge_sha: str
    merge_date: str
    source_branch: str
    file_count: int
    files: list[str]
    lines_added: int
    lines_removed: int
    binary_files: int
    window_weeks: int
    baseline_weeks: int
    now: str
    window_end: str
    window_truncated: bool
    observed_weeks: int
    baseline_commit_count: int
    baseline_rate_per_week: float
    post_merge_commit_count: int
    post_merge_bugfix_count: int
    post_merge_bugfix_share: float | None
    weekly_edits: list[int]
    weekly_bugfix_edits: list[int]
    settled_week: int | None
    settled: bool


@dataclass(frozen=True)
class _Commit:
    sha: str
    day: date
    subject: str


@dataclass(frozen=True)
class _Merge:
    pr_number: int
    sha: str
    day: date
    source_branch: str


def _git(repo: Path, *args: str) -> str:
    """Run a read-only git command in `repo` and return stdout (text)."""
    result = subprocess.run(
        ["git", "-C", str(repo), "-c", "core.quotePath=false", *args],
        check=True,
        capture_output=True,
        text=True,
    )
    return result.stdout


def _parse_day(value: str) -> date:
    """Parse a YYYY-MM-DD git short date into a date."""
    return date.fromisoformat(value.strip())


def resolve_ref(repo: Path, ref: str | None) -> str:
    """Resolve which ref to walk. Explicit --ref wins; else the remote's default
    branch (origin/HEAD); else local HEAD."""
    if ref:
        return ref
    try:
        out = _git(repo, "symbolic-ref", "--short", "refs/remotes/origin/HEAD").strip()
        if out:
            return out
    except subprocess.CalledProcessError:
        pass
    return "HEAD"


def list_merges(repo: Path, ref: str) -> list[_Merge]:
    """First-parent merge commits on `ref` that look like GitHub PR merges."""
    out = _git(
        repo,
        "log",
        "--first-parent",
        "--merges",
        "--date=short",
        f"--pretty=format:%H{_SEP}%cd{_SEP}%s",
        ref,
    )
    merges: list[_Merge] = []
    for line in out.splitlines():
        if not line:
            continue
        sha, day_str, subject = line.split(_SEP, 2)
        m = MERGE_PR_RE.match(subject)
        if not m:
            continue
        merges.append(
            _Merge(
                pr_number=int(m.group(1)),
                sha=sha,
                day=_parse_day(day_str),
                source_branch=m.group(2),
            )
        )
    return merges


def merge_file_set(repo: Path, merge_sha: str) -> tuple[list[str], int, int, int]:
    """Files changed by the merge itself, plus summed added/removed lines and a
    binary-file count. Uses --no-renames so paths are plain and pathspec-usable."""
    out = _git(
        repo,
        "diff",
        "--numstat",
        "--no-renames",
        f"{merge_sha}^1",
        merge_sha,
    )
    files: list[str] = []
    added = 0
    removed = 0
    binary = 0
    for line in out.splitlines():
        if not line:
            continue
        add_str, del_str, path = line.split("\t", 2)
        files.append(path)
        if add_str == "-" or del_str == "-":
            binary += 1
            continue
        added += int(add_str)
        removed += int(del_str)
    return files, added, removed, binary


def _log_commits(repo: Path, range_args: Sequence[str], files: Sequence[str]) -> list[_Commit]:
    """Non-merge commits matching `range_args` that touch any path in `files`."""
    if not files:
        return []
    out = _git(
        repo,
        "log",
        "--no-merges",
        "--date=short",
        f"--pretty=format:%H{_SEP}%cd{_SEP}%s",
        *range_args,
        "--",
        *files,
    )
    commits: list[_Commit] = []
    for line in out.splitlines():
        if not line:
            continue
        sha, day_str, subject = line.split(_SEP, 2)
        commits.append(_Commit(sha=sha, day=_parse_day(day_str), subject=subject))
    return commits


def post_merge_commits(repo: Path, merge_sha: str, ref: str, files: Sequence[str]) -> list[_Commit]:
    """Commits after the merge (``<merge>..<ref>``) touching the file set. The
    range excludes the PR's own commits and the merge commit by construction."""
    return _log_commits(repo, [f"{merge_sha}..{ref}"], files)


def baseline_commits(
    repo: Path,
    merge_sha: str,
    merge_day: date,
    baseline_weeks: int,
    files: Sequence[str],
) -> list[_Commit]:
    """Commits touching the file set in the baseline window before the merge,
    walked from ``<merge>^1`` so the PR's own branch commits are excluded."""
    start = merge_day - timedelta(days=baseline_weeks * _DAYS_PER_WEEK)
    return _log_commits(
        repo,
        [
            f"--since={start.isoformat()}",
            f"--until={merge_day.isoformat()}",
            f"{merge_sha}^1",
        ],
        files,
    )


def _bucket_weeks(
    commits: Iterable[_Commit], merge_day: date, observed_weeks: int
) -> tuple[list[int], list[int]]:
    """Bucket commits into [0, observed_weeks) by whole weeks since the merge.
    Commits dated before the merge, or beyond the observed window, are dropped.
    Returns (weekly_edits, weekly_bugfix_edits)."""
    weekly = [0] * observed_weeks
    weekly_bugfix = [0] * observed_weeks
    for c in commits:
        delta_days = (c.day - merge_day).days
        if delta_days < 0:
            continue
        week = delta_days // _DAYS_PER_WEEK
        if week >= observed_weeks:
            continue
        weekly[week] += 1
        if BUGFIX_RE.search(c.subject):
            weekly_bugfix[week] += 1
    return weekly, weekly_bugfix


def _settled_week(weekly_edits: Sequence[int], baseline_rate: float) -> int | None:
    """First week W after which every week's edits stay <= the baseline rate for
    the rest of the observed window. None if it never settles."""
    n = len(weekly_edits)
    settled: int | None = None
    # Scan from the last observed week backward: a week is the settle point if it
    # and all later weeks are at-or-below baseline.
    for w in range(n - 1, -1, -1):
        if weekly_edits[w] <= baseline_rate:
            settled = w
        else:
            break
    return settled


def build_record(
    repo: Path,
    ref: str,
    merge: _Merge,
    now: date,
    window_weeks: int,
    baseline_weeks: int,
) -> PrChurnRecord:
    files, added, removed, binary = merge_file_set(repo, merge.sha)

    window_end = merge.day + timedelta(days=window_weeks * _DAYS_PER_WEEK)
    window_truncated = window_end > now
    # Observed weeks: the full window unless `now` cuts it short.
    days_to_now = (now - merge.day).days
    if window_truncated:
        observed_weeks = max(0, min(window_weeks, -(-days_to_now // _DAYS_PER_WEEK)))
    else:
        observed_weeks = window_weeks

    post = post_merge_commits(repo, merge.sha, ref, files)
    weekly_edits, weekly_bugfix = _bucket_weeks(post, merge.day, observed_weeks)
    post_count = sum(weekly_edits)
    bugfix_count = sum(weekly_bugfix)
    bugfix_share = (bugfix_count / post_count) if post_count else None

    base = baseline_commits(repo, merge.sha, merge.day, baseline_weeks, files)
    baseline_count = len(base)
    baseline_rate = baseline_count / baseline_weeks if baseline_weeks else 0.0

    settled_week = _settled_week(weekly_edits, baseline_rate)

    return PrChurnRecord(
        pr_number=merge.pr_number,
        merge_sha=merge.sha,
        merge_date=merge.day.isoformat(),
        source_branch=merge.source_branch,
        file_count=len(files),
        files=files,
        lines_added=added,
        lines_removed=removed,
        binary_files=binary,
        window_weeks=window_weeks,
        baseline_weeks=baseline_weeks,
        now=now.isoformat(),
        window_end=window_end.isoformat(),
        window_truncated=window_truncated,
        observed_weeks=observed_weeks,
        baseline_commit_count=baseline_count,
        baseline_rate_per_week=baseline_rate,
        post_merge_commit_count=post_count,
        post_merge_bugfix_count=bugfix_count,
        post_merge_bugfix_share=bugfix_share,
        weekly_edits=weekly_edits,
        weekly_bugfix_edits=weekly_bugfix,
        settled_week=settled_week,
        settled=settled_week is not None,
    )


def mine(
    repo: Path,
    now: date,
    ref: str | None = None,
    window_weeks: int = 12,
    baseline_weeks: int = 12,
    since: date | None = None,
    until: date | None = None,
) -> list[PrChurnRecord]:
    """Mine post-merge churn for every PR merge on `ref` (bounded by since/until
    on the merge date). Returns records sorted by (merge_date, pr_number)."""
    resolved = resolve_ref(repo, ref)
    merges = list_merges(repo, resolved)
    records: list[PrChurnRecord] = []
    for merge in merges:
        if since is not None and merge.day < since:
            continue
        if until is not None and merge.day > until:
            continue
        records.append(build_record(repo, resolved, merge, now, window_weeks, baseline_weeks))
    records.sort(key=lambda r: (r.merge_date, r.pr_number))
    return records


_CSV_FIELDS = [
    "pr_number",
    "merge_sha",
    "merge_date",
    "source_branch",
    "file_count",
    "lines_added",
    "lines_removed",
    "binary_files",
    "window_weeks",
    "baseline_weeks",
    "now",
    "window_end",
    "window_truncated",
    "observed_weeks",
    "baseline_commit_count",
    "baseline_rate_per_week",
    "post_merge_commit_count",
    "post_merge_bugfix_count",
    "post_merge_bugfix_share",
    "settled_week",
    "settled",
]


def write_outputs(records: Sequence[PrChurnRecord], out_dir: Path) -> tuple[Path, Path]:
    """Write the JSON array and flat CSV summary. Returns the two paths."""
    out_dir.mkdir(parents=True, exist_ok=True)
    json_path = out_dir / "post_merge_churn.json"
    csv_path = out_dir / "post_merge_churn.csv"

    json_path.write_text(
        json.dumps([asdict(r) for r in records], indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

    with csv_path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=_CSV_FIELDS)
        writer.writeheader()
        for r in records:
            row = asdict(r)
            writer.writerow({k: row[k] for k in _CSV_FIELDS})

    return json_path, csv_path


def _parse_args(argv: Sequence[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="python -m settle.miners.post_merge_churn",
        description="Mine per-PR post-merge churn / settling from git history.",
    )
    parser.add_argument("--repo", required=True, type=Path, help="Path to the target git repo.")
    parser.add_argument(
        "--out",
        type=Path,
        default=None,
        help="Output dir (default: data/<repo-basename>/).",
    )
    parser.add_argument(
        "--now",
        required=True,
        type=_parse_day,
        help="Analysis anchor (YYYY-MM-DD). Windows past this are flagged truncated.",
    )
    parser.add_argument(
        "--ref",
        default=None,
        help="Branch/ref to walk (default: origin's default branch, else HEAD).",
    )
    parser.add_argument("--window-weeks", type=int, default=12, help="Post-merge window (weeks).")
    parser.add_argument("--baseline-weeks", type=int, default=12, help="Baseline window (weeks).")
    parser.add_argument(
        "--since", type=_parse_day, default=None, help="Only PRs merged on/after this date."
    )
    parser.add_argument(
        "--until", type=_parse_day, default=None, help="Only PRs merged on/before this date."
    )
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = _parse_args(argv)
    repo: Path = args.repo
    out_dir: Path = args.out or Path("data") / repo.resolve().name

    records = mine(
        repo=repo,
        now=args.now,
        ref=args.ref,
        window_weeks=args.window_weeks,
        baseline_weeks=args.baseline_weeks,
        since=args.since,
        until=args.until,
    )
    json_path, csv_path = write_outputs(records, out_dir)

    unsettled = sum(1 for r in records if not r.settled)
    print(f"Processed {len(records)} PR merges from {repo} ({unsettled} unsettled).")
    print(f"Wrote {json_path}")
    print(f"Wrote {csv_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
