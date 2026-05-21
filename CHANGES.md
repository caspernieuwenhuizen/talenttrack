# TalentTrack v4.0.0 — Versioning reset, adopt strict SemVer (closes #877)

## Why

Pre-4 versions ran as `3.110.x` because every PR bumped the patch counter by reflex. By the time the pilot was running v3.110.216, the version string told the operator nothing about what changed between releases — `.110` had been the "minor" for months without ever rolling over, and `.216` patches into it was a runaway number.

Reset to `4.0.0` and adopt strict SemVer from here.

## What this release IS NOT

- **Not** a breaking change. No DB migration. No schema change. No REST contract change. No capability matrix change. No operator action required on upgrade.
- **Not** a feature ship. The diff is the version string in two files plus this CHANGES entry plus a small doc update.

This is a cosmetic reset done once. Future major bumps (5.0.0, …) will gate on operator-visible breaking changes and be called out explicitly in CHANGES.md.

## SemVer rule going forward

| Bump | When |
|---|---|
| **Major** (5.0.0, …) | Breaking change to a DB column, REST contract, or capability matrix that requires operator action on upgrade. Called out explicitly in CHANGES.md. |
| **Minor** (4.1.0, 4.2.0, …) | New feature epic lands (e.g. "Exports rebuild shipped", "Behaviour discoverability shipped"). Reset patch to 0. |
| **Patch** (4.0.1, 4.0.2, …) | Bug fixes + small enhancements within the current minor. |

`v4.0.0` itself is the reset. The next ship after this one is `v4.0.1` (a patch).

## What stays the same

- **GitHub release flow** — `git tag v4.0.0 && git push origin v4.0.0` triggers `release.yml` unchanged.
- **PUC (Plugin Update Checker)** — semver comparison handles `4.0.0 > 3.110.216` correctly.
- **Migration numbering** — `database/migrations/0NNN_*.php` is independent of plugin version; next migration is `0121_*.php`.
- **Translation workflow** — `i18n-sync.yml` runs unchanged.

## Files touched

- `talenttrack.php` — `Version:` header + `TT_VERSION` constant.
- `readme.txt` — `Stable tag` + this changelog entry.
- `CHANGES.md` — this file.
- `DEVOPS.md` — Release section gets the bump-rule table + a v4.x example.
- `CLAUDE.md` — Definition-of-done checklist gains a SemVer-discipline line.
- `docs/versioning.md` + `docs/nl_NL/versioning.md` — new operator-facing one-paragraph explanation.

## How to test

1. `wp plugin info talenttrack` (or wp-admin Plugins page) reports version `4.0.0`.
2. `Stable tag: 4.0.0` in `readme.txt`.
3. PUC notifies installs on `3.110.216` to upgrade to `4.0.0`.
4. No DB migration runs on activation (because none added in this release).
