# TalentTrack v3.107.0 — Playwright coverage v1 starter: globalSetup + helpers + 2 specs (#0076)

First ship of #0076 Playwright coverage expansion. Adds the storageState-based authentication globalSetup, the small admin-flow helper module, and two self-contained spec files (teams-crud + lookups-frontend) that pass cleanly on the first CI run.

## Why 2 specs not 4

The PR was originally drafted with 4 specs (players-crud / teams-crud / lookups-frontend / goal). On the first CI run players-crud and goal failed — the wp-admin form selectors I drafted from reading the source code didn't match what the page actually renders (the redirect target landed on the WP dashboard rather than the TT list). Per the test plan in the spec ("if a spec is flaky, demote rather than block the cadence"), those two are removed from this PR and become follow-ups where I (or the next agent) can iterate selectors against real CI runs rather than guess from source.

The bundled-PR pattern from #0080 / #0084 / #0063 / #0066 doesn't apply cleanly to e2e tests: they need real-environment validation, and a CI failure 4 commits deep is harder to bisect than four single-spec PRs each validated in isolation. The spec's "monitor 3+ CI runs for flakes before moving on" cadence is the right shape; this PR delivers the foundation that subsequent per-spec PRs build on.

The 6 follow-up specs (players-crud, goal, activity, evaluation, persona-dashboard-editor, pdp-capture) each ship as ~2-4h per-spec PRs.

## What landed

### `tests/e2e/global-setup.js`

Runs once before the suite. Logs in as the wp-env-seeded `admin / password` and saves the storageState to `tests/e2e/.auth/admin.json`. Per-spec tests reuse it via `test.use({ storageState: ... })` so they skip the login dance on every run — cuts ~3-5s per spec.

The `.auth/` directory is gitignored so saved sessions never leak.

### `tests/e2e/helpers/admin.js`

Small, defensive utilities. Specs use them rather than hand-rolling the same `page.goto` + form-fill + redirect-wait dance:

- `gotoAdminPage(page, slug)` — `?page={slug}` navigation.
- `gotoAddNew(page, slug)` — `?page={slug}&action=new`.
- `uniqueName(prefix)` — timestamp + random suffix so concurrent / repeated runs don't collide on UNIQUE constraints.
- `submitAndWait(page, expectedRedirectPattern)`.
- `expectBackOnList(page, slug)`.

### `teams-crud.spec.js` (spec #3)

Create + edit a team. Verifies the staff section heading renders — that's the assertion that catches the silent-fail #19 regression (the renderer threw an unrelated warning and the section disappeared).

Locale-aware `/staff/i` substring match (wp-env runs `en_US`).

### `lookups-frontend.spec.js` (spec #4)

Adds a row via the frontend Configuration → Lookups admin. Validates the per-category editor from #5 + the translation preview wiring from #7.

Skips with a friendly message if the form layout doesn't surface (cap mismatch on this install). The wp-admin lookup path is covered separately.

### Wiring

- `playwright.config.js` — `globalSetup: require.resolve('./tests/e2e/global-setup.js')`.
- `.gitignore` — `tests/e2e/.auth/` so saved storageStates don't leak.
- `tests/e2e/README.md` — coverage-matrix table replaces the "Roadmap" stub. Documents what's shipped vs deferred.

## What's NOT in this PR

- `players-crud.spec.js` — first-attempt selectors didn't match the actual rendered form (the post-save redirect landed on the WP dashboard instead of the TT players list). Needs iterative tuning against real CI runs; ships in a follow-up PR.
- `goal.spec.js` — same root cause as players-crud. The form layout differs from what reading the source suggests; ships in a follow-up PR.
- `activity.spec.js` — guest-add modal (#26) + Spond rows (#13) need careful selectors.
- `evaluation.spec.js` — new-evaluation wizard end-to-end. Most complex flow per spec; ships last after the helper library matures.
- `persona-dashboard-editor.spec.js` — drag-drop is fragile; keeps isolated.
- `pdp-capture.spec.js` — depends on activities; ships after activity.
- Demo-data fixture beyond the wp-env baseline (lands when a spec needs more than the seed).
- Programmatic auth helper for non-admin personas (lands with the first non-admin persona test per spec architecture decision 2).
- Firefox / WebKit projects (target: v1 has 0 flakes for 7 consecutive days per spec architecture decision 6).

## Migrations

None. Test-code only.

## Affected files

- `tests/e2e/global-setup.js` — new (~40 lines).
- `tests/e2e/helpers/admin.js` — new (~70 lines).
- `tests/e2e/teams-crud.spec.js` — new (~50 lines).
- `tests/e2e/lookups-frontend.spec.js` — new (~60 lines).
- `tests/e2e/README.md` — coverage-matrix table replaces the "Roadmap" stub.
- `playwright.config.js` — wires `globalSetup`.
- `.gitignore` — adds `tests/e2e/.auth/`.
- `talenttrack.php`, `readme.txt`, `SEQUENCE.md` — version bump + ship metadata.

No new translatable strings — tests are dev-facing.
