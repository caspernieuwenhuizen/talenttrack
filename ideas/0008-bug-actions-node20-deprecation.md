<!-- type: bug -->

# GitHub Actions running on Node 20 — deprecation warnings

Raw idea:

Every release workflow run (v3.0.1, v3.0.2) produces annotations warning that our actions are running on Node.js 20, which GitHub is deprecating.

## Timeline from GitHub

- **2026-06-02** — Node.js 24 becomes the default. Actions pinned to Node 20 will be forced to Node 24 unless `ACTIONS_ALLOW_USE_UNSECURE_NODE_VERSION=true` is set.
- **2026-09-16** — Node.js 20 is removed from the runner entirely. Any action still pinned to it will fail hard.

Reference: https://github.blog/changelog/2025-09-19-deprecation-of-node-20-on-github-actions-runners/

## Affected actions in our workflow

From `.github/workflows/release.yml` annotations:

- `actions/checkout@v4` — used in PHP Syntax Lint, PHPStan, Build & Release jobs
- `softprops/action-gh-release@v2` — used in Build & Release job

## Fix

Bump both actions to their latest Node 24–compatible versions. As of writing, likely:

- `actions/checkout@v5` (or whatever the current major is)
- `softprops/action-gh-release@v2` → check for a newer major (v3?) that runs on Node 24

Verify by checking the action's README / release notes for "Node 24" support before pinning.

## Urgency

Not urgent today (warnings only), but becomes a blocker on **2026-06-02** if not addressed — release workflow would silently migrate to Node 24. The 2026-09-16 removal date is the hard deadline.

## Touches

.github/workflows/release.yml
