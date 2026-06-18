"""excludes — reusable generated/vendored-file exclusion for line-counting miners.

Line-level metrics (rework_rate, and any future churn/volume miner) are badly
distorted by big machine-generated dumps: a single regenerated ``package-lock.json``
or a minified bundle can dwarf a month of real authoring and drown out the signal.
This module is the shared, miner-agnostic filter that decides whether a path is
generated/vendored and should be left out of line counts.

A path is excluded if EITHER:
  1. it matches one of the active **glob patterns** (the documented
     ``DEFAULT_EXCLUDE_GLOBS`` unless disabled, plus any caller-supplied globs); or
  2. the target repo's ``.gitattributes`` marks it ``linguist-generated`` or
     ``linguist-vendored`` (honored only when present; evaluated via
     ``git check-attr`` against the repo's attributes).

Determinism: the matcher is built once from the repo's tree at a ref plus its
``.gitattributes``; ``is_excluded`` is then a pure function of the path. Same repo
+ same args → same exclusions.

Glob matching semantics (kept simple and documented so the default set is auditable):
  - a pattern ending in ``/`` (e.g. ``vendor/``, ``node_modules/``, ``dist/``) matches
    any path that has that directory anywhere in its path (``a/vendor/b.php`` matches
    ``vendor/``);
  - a pattern containing a ``/`` but no trailing slash is fnmatch'd against the full
    path;
  - a bare pattern (e.g. ``*.lock``, ``*.min.js``, ``composer.lock``) is fnmatch'd
    against the path's basename.
"""

from __future__ import annotations

import subprocess
from collections.abc import Sequence
from dataclasses import dataclass
from fnmatch import fnmatch
from pathlib import Path

# Default generated/vendored exclusion globs. Auditable on purpose — every entry is
# a class of file that is machine-produced or third-party, not human-authored work.
DEFAULT_EXCLUDE_GLOBS: tuple[str, ...] = (
    # Dependency lockfiles (regenerated wholesale; enormous diffs).
    "composer.lock",
    "package-lock.json",
    "yarn.lock",
    "pnpm-lock.yaml",
    "*.lock",
    # Minified / bundled / source-map assets.
    "*.min.js",
    "*.min.css",
    "*.bundle.js",
    "*.map",
    # Vendored / dependency / build-output directories.
    "vendor/",
    "node_modules/",
    "dist/",
    "build/",
    "public/build/",
    # Common generated artifacts / snapshots.
    "*.snap",
    "*.generated.*",
    "__snapshots__/",
)

# gitattributes attributes that mark a path as not human-authored.
_LINGUIST_ATTRS = ("linguist-generated", "linguist-vendored")


def _git(repo: Path, *args: str, stdin: str | None = None) -> str:
    """Run a read-only git command in `repo` and return stdout (text)."""
    result = subprocess.run(
        ["git", "-C", str(repo), "-c", "core.quotePath=false", *args],
        check=True,
        capture_output=True,
        text=True,
        input=stdin,
    )
    return result.stdout


def _matches(path: str, pattern: str) -> bool:
    """Whether `path` matches a single exclusion glob (see module docstring)."""
    if pattern.endswith("/"):
        # Directory pattern: the dir appears anywhere along the path.
        return ("/" + pattern) in ("/" + path + "/")
    if "/" in pattern:
        return fnmatch(path, pattern)
    return fnmatch(path.rsplit("/", 1)[-1], pattern)


def _gitattributes_excluded(repo: Path, ref: str) -> frozenset[str]:
    """Paths tracked at `ref` that `.gitattributes` marks linguist-generated or
    linguist-vendored. One ``ls-tree`` + one batched ``check-attr`` call."""
    listing = _git(repo, "ls-tree", "-r", "--name-only", "-z", ref)
    paths = [p for p in listing.split("\0") if p]
    if not paths:
        return frozenset()

    out = _git(repo, "check-attr", "--stdin", "-z", *_LINGUIST_ATTRS, stdin="\0".join(paths) + "\0")
    # With -z, output is a flat NUL-separated stream of (path, attr, value) triples.
    fields = out.split("\0")
    excluded: set[str] = set()
    for i in range(0, len(fields) - 2, 3):
        path, _attr, value = fields[i], fields[i + 1], fields[i + 2]
        if value == "set":
            excluded.add(path)
    return frozenset(excluded)


@dataclass(frozen=True)
class ExcludeMatcher:
    """An immutable, deterministic path-exclusion predicate."""

    globs: tuple[str, ...]
    attr_paths: frozenset[str]

    def is_excluded(self, path: str) -> bool:
        if path in self.attr_paths:
            return True
        return any(_matches(path, pat) for pat in self.globs)

    def describe(self) -> str:
        """One-line human summary of what this matcher will exclude."""
        attr_note = f" + {len(self.attr_paths)} gitattributes path(s)" if self.attr_paths else ""
        return f"{len(self.globs)} glob pattern(s){attr_note}"


def build_matcher(
    repo: Path,
    ref: str,
    *,
    extra_globs: Sequence[str] = (),
    use_defaults: bool = True,
    use_gitattributes: bool = True,
) -> ExcludeMatcher:
    """Build an :class:`ExcludeMatcher` for `repo` at `ref`.

    ``use_defaults`` toggles ``DEFAULT_EXCLUDE_GLOBS``; ``extra_globs`` are always
    added; ``use_gitattributes`` toggles honoring linguist-generated/-vendored.
    """
    globs: list[str] = []
    if use_defaults:
        globs.extend(DEFAULT_EXCLUDE_GLOBS)
    globs.extend(extra_globs)

    attr_paths = _gitattributes_excluded(repo, ref) if use_gitattributes else frozenset()
    return ExcludeMatcher(globs=tuple(globs), attr_paths=attr_paths)
