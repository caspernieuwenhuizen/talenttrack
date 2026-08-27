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

## Projects

Two, and they do not overlap:

| project | viewport | runs | blocking |
| --- | --- | --- | --- |
| `chromium` | Desktop Chrome | every spec except `mobile-viewport.spec.js` | yes |
| `iphone-13` | 390x844, touch on | `mobile-viewport.spec.js` only | not yet |

Locally: `npm run test:e2e` for the desktop suite, `npm run test:e2e:mobile`
for the viewport gate.

### The mobile viewport gate (#2813)

`mobile-viewport.spec.js` walks every surface a phone can actually reach —
read from `config/mobile_surfaces.php`, skipping `desktop_only`, since a
phone visitor is intercepted before those render — and asserts three things
per surface:

1. No horizontal overflow (`scrollWidth <= clientWidth`).
2. No visible interactive element under 48px in either dimension.
3. No table wider than the viewport, on `native` surfaces only. Elsewhere a
   table scrolling inside its own container is the intended compromise.

Two settings in `playwright.config.js` are load-bearing rather than
cosmetic, and both are spelled out instead of spread from
`devices['iPhone 13']` so a Playwright upgrade cannot move a regression
gate's goalposts:

- **`hasTouch: true`.** The 48px floor lives behind
  `@media (pointer: coarse)`. Without touch the run measures desktop
  density and passes every tap-target assertion for the wrong reason.
- **An iPhone user agent.** `MobileDetector::isPhone()` reads the UA
  server-side to decide what a phone gets. Without it the gate measures the
  desktop render at a narrow width, which is not the same thing.

The spec also hides `#wpadminbar`, which declares `min-width: 600px` and
would otherwise fake ~210px of overflow on every surface in every run.

**Baseline.** The audit found 2,213 defect rows; a gate that fails on all of
them is a gate somebody turns off. `mobile-baseline.json` lists allowed
finding kinds per surface — anything not listed fails. It ships **empty**,
because the audit measured a seeded local install rather than wp-env, and
transcribing those rows would grant exemptions nobody verified against what
CI sees. The first run prints the real offender list in that file's exact
shape; paste it in and the gate is calibrated.

Until the baseline is empty the step is `continue-on-error` in `e2e.yml`.
Flip it to blocking when the last allowance goes. The spec also reports any
baseline entry that has *stopped* offending — that is the line to delete,
and a stale allowance is where the next real regression hides.

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
