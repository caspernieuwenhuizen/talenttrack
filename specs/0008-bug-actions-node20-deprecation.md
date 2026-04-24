<!-- type: bug -->

# Bump GitHub Actions to Node 24–compatible versions

## Problem

Every release workflow run currently emits deprecation warnings because `actions/checkout@v4` and `softprops/action-gh-release@v2` are running on Node.js 20, which GitHub is phasing out.

Hard deadlines from GitHub:

- **2026-06-02** — Node.js 24 becomes the default runner. Actions pinned to Node 20 are forced to Node 24 unless `ACTIONS_ALLOW_USE_UNSECURE_NODE_VERSION=true` is set (which isn't a real fix).
- **2026-09-16** — Node.js 20 is removed from runners entirely. Actions still pinned to Node 20 fail hard — release workflow breaks, no new versions can be published.

Today the symptom is cosmetic (yellow annotation on every workflow run). By June it's quietly using a forced Node 24. By September it's a hard stop.

Who feels it: only the release maintainer today, via noisy warnings. By September, every user of the plugin who relies on new versions being published.

## Proposal

Bump the two affected actions in `.github/workflows/release.yml` to their current Node 24–compatible majors:

- `actions/checkout@v4` → `actions/checkout@v5` (verify v5 is the current Node 24 major at implementation time)
- `softprops/action-gh-release@v2` → next major that declares Node 24 support (check the action's release notes — v3 at the time of writing, verify before pinning)

For each bump, before pinning:

1. Read the action's README/release notes to confirm Node 24 support is advertised.
2. Skim breaking changes between the old and new major — `softprops/action-gh-release` in particular has historically had config-shape changes between majors.
3. Adjust any inputs/config if the new major moved them.

## Scope

- `.github/workflows/release.yml` — the only workflow file in the repo.
- Both `actions/checkout@*` and `softprops/action-gh-release@*` usages (the checkout action is used in multiple jobs — PHP Syntax Lint, PHPStan, Build & Release).

## Out of scope

- Adding new CI jobs, test steps, or workflow restructuring.
- Pinning to SHAs instead of majors — keeping tag-level pinning to stay consistent with existing style.
- Any other CI hygiene (matrix testing across PHP versions, Windows runners, etc.). Separate concern.

## Acceptance criteria

- A release workflow run completes successfully with no Node 20 deprecation annotations.
- Release artefact (the built plugin zip) is produced and attached to the GitHub release exactly as before — same filename, same contents.
- The PUC update channel continues to work (end users on current versions see the new release as an available update in wp-admin).
- No regression in the PHPStan or PHP Syntax Lint jobs.

## Notes

- Low risk, mechanical change. Most likely failure mode is `softprops/action-gh-release` having a renamed input or dropped parameter between majors — read the release notes.
- Best validated by cutting a test release (e.g. a `v3.1.1-test` tag) and confirming the workflow runs clean before merging.
- Estimated effort: ~1 hour including the test release validation.
- Can be done any time before 2026-06-02 without user impact. Ideally before 2026-05-04 so the demo-day install path isn't tripping over release-workflow regressions during the demo week, but not demo-critical.
- Reference: [GitHub blog post on Node 20 deprecation](https://github.blog/changelog/2025-09-19-deprecation-of-node-20-on-github-actions-runners/).
