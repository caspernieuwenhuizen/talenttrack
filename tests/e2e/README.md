# TalentTrack end-to-end tests (#12)

Playwright + wp-env. v1 ships the foundation + one smoke test (login
→ TalentTrack dashboard). Per-flow coverage builds incrementally on
top of this skeleton.

## Local quick start

Pre-requisites:
- Docker Desktop (or Colima / Lima)
- Node.js 20.x

```bash
npm install
npm run wp-env:start          # spins up WordPress + MySQL on :8889
npx playwright install chromium
npm run test:e2e              # headless run
npm run test:e2e:headed       # see what the browser does
npm run test:e2e:ui           # Playwright's interactive UI
```

The `tests` instance at `localhost:8889` auto-creates an `admin /
password` account and activates the plugin from the working tree.
Source changes are live — no rebuild between runs.

When you're done:
```bash
npm run wp-env:stop           # keep the database
npm run wp-env:clean          # nuke the database (if a test mucked it)
```

## Adding a new flow test

1. Create `tests/e2e/<flow>.spec.js`.
2. Use the global `admin / password` account; for non-admin personas,
   create them with WP-CLI inside the wp-env container:
   ```bash
   wp-env run cli wp user create coach1 coach1@example.test --role=tt_coach --user_pass=password
   ```
3. Keep tests independent — clean up created records at the end of
   each test, or use a unique slug / name per run.

## CI

`.github/workflows/e2e.yml` runs the suite on every PR. Failing
runs upload screenshots + videos + traces as artifacts.

## Coverage matrix (#0076)

| File | Flow | Status |
|---|---|---|
| `login.spec.js` | wp-admin login → TT dashboard | shipped v3.75.2 |
| `global-setup.js` | one-time admin login → saved storageState | shipped v3.107.0 |
| `helpers/admin.js` | small admin-flow utilities | shipped v3.107.0 |
| `teams-crud.spec.js` | create / edit + verify staff section renders (#19) | shipped v3.107.0 |
| `lookups-frontend.spec.js` | add a row via the frontend lookups admin (#5) | shipped v3.107.0 (skip-as-needed) |
| `players-crud.spec.js` | create / edit / archive a player | follow-up — first-attempt selectors didn't match; needs iterative tuning against CI |
| `goal.spec.js` | create + reach detail (#0070, #28) | follow-up — first-attempt selectors didn't match; needs iterative tuning against CI |
| `activity.spec.js` | create activity + record attendance + add a guest | follow-up |
| `evaluation.spec.js` | new-evaluation wizard end-to-end (#0072) | follow-up |
| `persona-dashboard-editor.spec.js` | drag a widget from palette to canvas (#11) | follow-up |
| `pdp-capture.spec.js` | capture behaviour + potential | follow-up |

## Roadmap (v2+)

- Add Firefox + WebKit projects (target: v1 has 0 flakes for 7 consecutive days).
- Parallel workers (move to `fullyParallel: true` once tests are isolated; today's lookups + lookup-types share `tt_lookups` so isolation work is needed first).
- Programmatic auth helper for non-admin personas (lands when the first non-admin spec needs it).
- Demo-data fixture loaded once per worker via `globalSetup` (today's setup only saves admin login state — adding a baseline player/team set lands when a spec needs it).
- The four follow-up specs above. Each ~2-4h per spec sequencing.
