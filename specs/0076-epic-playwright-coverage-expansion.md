# #0076 — Playwright coverage expansion

**Type**: epic
**Status**: shaped, awaiting trigger
**Estimate**: ~30–50h across 8–10 PRs
**Depends on**: v3.75.2 (#12 foundation)

## Summary

The v3.75.2 ship landed the Playwright skeleton + one smoke test (admin login → dashboard). This spec covers the per-flow expansion: a comprehensive set of Playwright specs that exercise every major user journey, run on every PR, and catch regressions before they reach production.

## Scope (v1)

8 spec files, one per major flow. Each spec creates the records it needs, asserts the visible result, and cleans up at the end so tests are independent.

| File | Flow | Notes |
|---|---|---|
| `tests/e2e/players-crud.spec.js` | Create / edit / archive a player | Includes parent picker (#0070), photo upload skipped (separate spec) |
| `tests/e2e/teams-crud.spec.js` | Create / edit / archive a team, assign staff | Verify Staff section renders (#19 root cause) |
| `tests/e2e/evaluation.spec.js` | Run the new-evaluation wizard end-to-end | Activity-first path (#0072), each step transitions correctly |
| `tests/e2e/activity.spec.js` | Create activity + record attendance + add a guest | Guest-add modal (#26), Spond rows visible in demo (#13) |
| `tests/e2e/goal.spec.js` | Create goal → progress → complete → save → list | Detail view click-through (#0070), goal redirect after save (#28) |
| `tests/e2e/pdp-capture.spec.js` | Capture behaviour + potential | Activity tie-in (#15), recorded date visible (#17) |
| `tests/e2e/persona-dashboard-editor.spec.js` | Drag a widget from palette to canvas | Drag-drop fix (#11) |
| `tests/e2e/lookups-frontend.spec.js` | Add / edit / delete a lookup row from frontend | Per-category editor (#5), translation preview (#7) |

## Out of scope (v2)

Deferred to a follow-up spec / sprint:
- Mobile viewport runs (Pixel / iPhone emulation)
- Visual-regression screenshots (Percy / Chromatic)
- Per-persona test matrix (run the same flows as Coach, HoD, Player, Parent)
- Performance budgets (Lighthouse CI integration)
- Full FR/DE/ES locale runs for #0010

## Architecture decisions (locked)

1. **Test environment**: `@wordpress/env` Docker tests instance on `localhost:8889`. Already wired in v3.75.2's `.wp-env.json`.
2. **Authentication**: shared `admin / password` for v1; programmatic auth helper (`tests/e2e/auth.setup.js` using a saved storageState) lands when the first non-admin persona test does.
3. **Fixtures**: TalentTrack demo data generator runs once per worker via `globalSetup` so every spec has a baseline (1 club / 4 teams / 60 players / 8 evaluations etc.). Specs can mutate freely; teardown reloads the baseline.
4. **Selectors**: prefer accessible roles (`getByRole`, `getByLabel`) over CSS classes. Stable `data-testid` attributes added to TT views as needed.
5. **Concurrency**: still single-worker through v1 — flows that touch shared state (tt_lookups, tt_config) need isolation work first.
6. **Browsers**: Chromium only through v1. Firefox + WebKit projects added when v1 has 0 flakes for 7 consecutive days.

## Trigger to start

Pick this up after v3.75.2's foundation has run on at least 5 PRs without false positives, and the `tests/e2e/login.spec.js` smoke is rock-solid.

## Sequencing

Recommended PR order (each ~2–4h):

1. `globalSetup` + demo-data seed helper (foundation for fixtures).
2. `players-crud` (smallest CRUD; sets the pattern).
3. `teams-crud` (validates staff picker — #19 regression guard).
4. `lookups-frontend` (validates the new editor from #5).
5. `goal` (smallest workflow flow).
6. `activity` (introduces guest-add complexity).
7. `evaluation` (the wizard — most complex flow, ship last so the helper library has matured).
8. `persona-dashboard-editor` (drag-drop is fragile — keep isolated).
9. `pdp-capture` (depends on activities + behaviour ratings).

After each PR, monitor 3+ CI runs for flakes before moving on.

## Definition of done

- All 8 specs in `tests/e2e/`, each independently runnable.
- `npm run test:e2e` passes locally + in CI on 5 consecutive runs.
- README's roadmap list updated to mark each flow as covered.
- CI median runtime ≤ 8 minutes (single worker is the bound).
- Documentation update: `docs/architecture.md` references the e2e suite as a regression-safety net.
