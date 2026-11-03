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

Every frontend spec loads `/?tt_view=<slug>`, which only renders the
dashboard when the dashboard page is the site's static front page. A fresh
wp-env site has no such page, so run this once after `wp-env:start` (CI does
it in `e2e.yml`); without it the frontend specs skip themselves and the
mobile gate fails as blind:

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/talenttrack wp eval-file tests/e2e/setup/dashboard-front-page.php
```

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

Four. The desktop project and the phone projects do not overlap:

| project | viewport | runs | blocking |
| --- | --- | --- | --- |
| `chromium` | Desktop Chrome | every spec except `mobile-viewport.spec.js` | yes |
| `phone-320` | 320x844, touch on | `mobile-viewport.spec.js` only | not yet |
| `phone-360` | 360x800, touch on | `mobile-viewport.spec.js` only | not yet |
| `iphone-13` | 390x844, touch on | `mobile-viewport.spec.js` only | not yet |

The three phone projects share every setting but the viewport
(`phoneProject()` in `playwright.config.js`). 360 is the base width the CSS
is written for, 390 the common iPhone, and 320 the narrowest phone still in
use.

Locally: `npm run test:e2e` for the desktop suite, `npm run test:e2e:mobile`
for the viewport gate at all three widths.

**The mobile gate runs in Dutch; the desktop suite in English.** The pilot
academies use TalentTrack in Dutch, and Dutch labels are often much longer
than English ones ("Geboortedatum" for "DOB"). A 320px overflow caused by a
Dutch label cannot fail an English walk. In CI, after the desktop suite, the
step *Switch the tests instance to Dutch for the mobile gate* in `e2e.yml`
does three things, and is `continue-on-error` so a failed download or compile
cannot turn the required check red (the gate's Dutch check then fails instead):

1. Folds the pending fragments into the checkout's copy of the catalogue
   (`php tools/consolidate-translations.php --keep`, run in the wp-env
   container like every PHP process in the job).
2. Compiles `languages/talenttrack-nl_NL.mo` with `msgfmt`. It is never
   committed.
3. Installs and activates the nl_NL core language pack (WordPress refuses a
   `WPLANG` it has no language files for) and sets the admin's locale to
   `nl_NL`.

To run the gate locally, do the same before `npm run test:e2e:mobile`, then
delete the `.mo` and restore the `.po` afterwards
(`git checkout -- languages/talenttrack-nl_NL.po`). The spec checks that the
player profile shows "Voet" in its facts strip, and fails with a pointer here
when it doesn't.

### The mobile viewport gate (#2813)

`mobile-viewport.spec.js` walks every surface a phone can actually reach —
read from `config/mobile_surfaces.php`, skipping `desktop_only`, since a
phone visitor is intercepted before those render — and asserts three things
per surface, plus two more on `native` list surfaces:

**Redirects.** A surface is measured only if the page still shows the
`tt_view` the walk asked for once it has loaded. A surface that lands
somewhere else is reported as redirected and not measured, so a finding is
never filed under the wrong page's key. Redirects the gate's account always
hits are listed in `EXPECTED_REDIRECTS` in the spec, each with its target
and a reason. Today that is `mfa-prompt`, which sends an admin with no
pending challenge to the dashboard home. An unlisted redirect fails the
gate.

**The dashboard home** has no `tt_view`, so the slug walk never reaches it.
It is measured at every width under the key `dashboard#home`, and like a
record page it may not be skipped.

**Record pages.** A slug alone reaches lists and landing pages, never a
record. `RECORD_SURFACES` in the spec lists record pages the walk opens by
id. Today that is the player profile: in `beforeAll` the spec creates a player
over REST (a long name, every key fact filled in), walks
`?tt_view=players&id=<id>` at each width under the baseline key
`players#record`, and deletes the player in `afterAll`. The CI instance has
no seed data, so the record is the spec's own. A record that cannot be made
or opened fails the spec rather than being skipped. Add a team or an
activity the same way.

1. No horizontal overflow (`scrollWidth <= clientWidth`). The finding names
   the widest box that extends the page (`helpers/overflow.js`). A box inside
   a horizontal scroll strip, such as the player tabs, is never named: the
   strip scrolls on purpose and cannot widen the page.
   `overflow-offender.spec.js` holds the fixtures.
2. No visible interactive element under 48px in either dimension.
3. No table wider than the viewport, on `native` surfaces only. Elsewhere a
   table scrolling inside its own container is the intended compromise. The
   finding names each table by its classes, its first two header texts and
   its nearest `tt-` container, for example
   `table.tt-pd-table (Wanneer, Wie) in div.tt-pd-table-wrap 342px wide`.

**When it measures.** `FrontendListTable` fills its body over REST after
first paint. On a surface with a list the spec waits for the loading row to
go (up to five seconds, `waitForListHydration()`) before any check runs, so
a row's links and buttons are always part of what is measured rather than
depending on how fast the REST call returned.

**What counts as visible** in check 2: the element has a box larger than
1px in both dimensions and `checkVisibility()` says a person can see it.
That leaves out three things that are not tap targets: `display: none`
elements, visually hidden inputs clipped to a pixel and driven by a visible
button (`.tt-media-file-input`), and anything hidden by an ancestor — the
items of a closed `<details>` menu keep a box and their own `display`, and
used to be reported.

An element whose computed `pointer-events` is `none` is left out as well: a
tap passes through it to whatever is underneath. In a list whose rows are
links, a link on the row's title line is made inert that way below 768px so
the tap opens the row, and the people list does the same for the e-mail
address, the goals list for the goal title. Those links are text on a
phone, not targets.

On `native` surfaces it also checks list density, because a list can pass
all three and still be unusable on a phone. Both findings are reported per
`FrontendListTable` on the page:

4. `row-height` — a data row (or card) taller than 120px
   (`MAX_ROW_HEIGHT`).
5. `first-row-low` — the first data row starts more than 400px down the
   page (`MAX_FIRST_ROW_TOP`), pushed there by filters and chrome.

A list with no data rows — empty, errored, or still loading after five
seconds — has no density to judge and is not measured. The run prints which
surfaces had rows and how many, so "no density findings" can be told apart
from "nothing was looked at". wp-env's data decides that, and it is thin:
when the checks were added only `players` and `teams` had rows. A list that
is empty in CI is not covered by these two checks.

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
finding kinds per surface, in one block per width (`widths.320`,
`widths.360`, `widths.390`), each entry `{ kinds, reason }` — anything not
listed fails, and so does an entry with no reason. It is seeded from
CI's own output, not from the audit (which measured a seeded local install
rather than wp-env). Every run prints the current offender list in that
file's exact shape; paste it in to recalibrate. Below it the run prints each
finding in detail, so an entry can be traced to the element that earns it.

**One entry is not a to-do.** `teams: row-height` is accepted by design: the
teams list shows cards, not table rows, and a team card of about 171px is
the intended size. The 120px limit is a limit for rows. Keep the entry; do
not shrink the cards to clear it.

**Blindness check.** The spec fails when more than a handful of surfaces
render no `.tt-dashboard`, or when it measures less than 60% of the
phone-reachable list. Both mean the walk is skipping rather than measuring —
which is how the gate once stayed green for months while measuring nothing.

Until only the accepted entry is left the step is `continue-on-error` in
`e2e.yml`. Flip it to blocking when the last real allowance goes. The spec also reports any
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
