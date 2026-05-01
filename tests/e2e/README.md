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

## Roadmap (v2+)

- Add Firefox + WebKit projects.
- Parallel workers (move to `fullyParallel: true` once tests are isolated).
- Programmatic auth helper (skip the login form for non-login tests).
- Demo-data fixture loaded once per worker via `globalSetup`.
- Coverage targets per the #12 master list (player CRUD, team CRUD,
  evaluation create + rate, activity create + attendance, goal lifecycle,
  PDP capture, persona dashboard editor, lookups CRUD).
