# changelog.d — release-note snippets

This folder decouples **content work** from **release plumbing** so several
implementation agents can drain `ready-for-dev` issues in parallel without
colliding on `CHANGES.md` / `readme.txt` / the version constant.

## The rule

When working in parallel-drain / batched-release mode, an implementation
PR does **not** bump the version, and does **not** edit `CHANGES.md` or
`readme.txt`. Instead it drops **one new file** here:

```
changelog.d/<issue>-<short-slug>.md
```

A brand-new file per issue never conflicts with another agent's file, so
the changelog stops being a merge-collision surface.

## Format

Three rules, all enforced by `tools/check-changelog-snippets.php` (the
`changelog-snippet-check` gate runs it on every PR):

1. **The first non-empty line must be an `# ` heading.** It becomes the
   changelog entry's title, verbatim. Reference the issue with
   `(#<number>)` in it so the release note carries a link.
2. **The `Bump:` line must come after the heading**, never on the first
   line, and at most once. `patch` | `minor` | `major`; omit it for
   `patch`. It is stripped from the printed changelog.
3. **There must be a body** below those two — otherwise the release
   prints the title twice.

Rule 2 is the one that bites. The release script takes the first
non-empty line as the title and only *then* looks for the marker, so a
snippet that opens with `Bump: minor` produces an entry literally titled
"Bump: minor" *and* falls back to a patch bump. Seven of the nine
snippets in the v4.108.0 batch were malformed this way (#3043), which is
why it is checked rather than described.

```markdown
# Weekly planner PDF: ISO week number in the badge (#1730)

Bump: patch

The weekly planner PDF's top-left badge now shows the ISO week number
instead of the academy initials when no logo is configured. Logo installs
are unchanged. CSS + a small markup tweak only — no data or query changes.
```

Set `Bump: minor` for a new feature epic, `Bump: major` for an
operator-breaking change (SemVer per `CLAUDE.md` §9); omit it for ordinary
fixes + small enhancements. Keep the prose in the same voice as existing
`CHANGES.md` entries: what changed, why, and any trade-off. No version
number — the release step stamps it.

## Releasing the batch

The release agent runs, once, for the whole batch:

```
pwsh tools/release.ps1            # auto-detects the next version
pwsh tools/release.ps1 4.46.0     # or force a specific version
```

The version is computed from the current `talenttrack.php` version plus
the highest `Bump:` declared across the snippets (default patch); pass an
explicit version to override. That consolidates every snippet here into
`CHANGES.md` + `readme.txt`, bumps both version lines in `talenttrack.php`
and the `readme.txt` `Stable tag`, deletes the consumed snippets, and
(with `-Commit`) commits
the result. Pushing that version bump to `main` triggers
`.github/workflows/auto-release.yml`, which recompiles `.mo` from `.po`
and publishes the GitHub release. Do **not** compile `.mo` or create a tag
by hand — CI owns both.
