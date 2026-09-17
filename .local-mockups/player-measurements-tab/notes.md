# Metingen-tab — design notes

Mockups for the player profile's **Metingen** tab, reported as "cluttered, confusing
and inconsistent". Open `index.html`; deep-link states with `?v=A&d=full&w=phone`.

The surface is `FrontendMeasurementsView::renderBody()`, called from two places:

- `FrontendPlayerDetailView::renderMeasurementsTab()` (`src/Shared/Frontend/FrontendPlayerDetailView.php:2121`)
- the standalone view `?tt_view=measurements`

**One port, two screens.** Whatever is chosen lands on both — which is an argument for
doing it once, properly, rather than patching the tab.

---

## What is actually wrong — nine located causes

Toggle **Markeer knelpunten** on the `Vandaag` panel to see them outlined.

| # | Symptom | Cause |
| --- | --- | --- |
| 1 | Two card systems stacked | The BMI block is `frontend-bmi.css` (`--tt-radius` 8px, bare `<h3>`); the category is `frontend-measurements.css:36` (12px radius, filled header bar). Different padding, radius and heading treatment, 20px apart. |
| 2 | Prominence inverted against content | `BmiBlock::renderStanding()` prints a 2rem figure, then the sentence saying there is no percentile. The biggest element on the tab carries the least information. |
| 3 | The empty state repeats per row | `FrontendMeasurementsView.php:296` emits "Voorlopig één meting…" inside `renderTrend()`, so a player with three single-reading tests gets the line three times. |
| 4 | A never-measured test is silent | `renderTestRow()` (`:235`) prints `—` with no date and no explanation. It reads like a rendering fault, and it sits mid-list splitting the two tests that do have data. |
| 5 | Colour carries a verdict with nothing to check it against | `.tt-meas-flag-ok` / `-warn` (`frontend-measurements.css:99`) colour the value. The target is never shown and there is no legend anywhere on the tab. A coach cannot tell whether amber is 2% or 20% off. |
| 6 | Name and value ~1100px apart on desktop | `.tt-meas-row` is `grid-template-columns: 1fr auto` (`:38`) with no max width, so the value is pinned to the far edge of a full-width container. |
| 7 | Two idioms inside one card | Rows above, then a caption (`:166`) and a column table below, for tests of the same category. `renderNeutralTable()` was the right *data* decision (see `test-trends/notes.md`) given a wrong *layout* one. |
| 8 | Date notation | "juni 30, 2026" — Dutch month names in US order. Not this view's bug: `date_i18n( get_option('date_format') )` with the option left at WP's `F j, Y` default. **Adjacent fix, see below.** |
| 9 | No timeline | Every test shows its own last date. Nothing answers "when was this player last measured as a whole" or "what is overdue against its own `frequency`" — even though `frequency` is already stored and already rendered as a chip. |

Points 3, 4 and 9 are why the surface looks worst exactly when a player is new,
which is when a coach opens it most.

---

## The three directions

Every panel renders the same dataset (the values from the reported screenshot), so the
comparison is like for like. The **Data** toggle switches between *Zoals nu (1 meting)*
and *Vol seizoen* — judge both: today's design collapses into three identical apology
lines in the sparse state, and that is half the complaint.

### A · Register — recommended

One table per category, one row shape for every test.

- Value sits beside the name, not at the far edge (fixes 6).
- **Gemeten · stand** states the verdict in words next to the colour, so colour never
  carries the meaning alone (fixes 5, and matches the rule `test-trends/notes.md`
  already set for this module).
- **Doel** makes the threshold visible (fixes 5).
- Direction-less tests get the same row with `geen doel` — the separate table and its
  two-line caption disappear (fixes 7).
- BMI becomes a row tagged `afgeleid`, which is what it is: height × weight (fixes 1, 2).
- The empty state is stated once in the card footer (fixes 3), and a never-measured test
  reads `—` + a `nog niet gemeten` chip rather than a bare dash (fixes 4).
- Under 720px each row becomes two lines, three when there is a trend to show. No
  horizontal scroll.

Smallest build of the three: the component model (category card → per-test entry →
expandable history) is unchanged, so `renderTrend()`, `TrendChart` and the level palette
port across untouched.

### B · Meetmomenten

The measuring day is the spine instead of the test — closest to the journey principle in
CLAUDE.md §1, and the only direction that answers #9. The footer strip names what has
never been measured and what is past its own frequency.

Its weakness is visible in the mockup: switch to *Vol seizoen* and a season becomes 13
moments, most holding a single test. A coach following one test over time is worse off
here, so it needs the `Per moment / Per test` switch, where "Per test" is direction A.
Most expensive of the three, and it does not replace A so much as sit on top of it.

### C · Kaarten

One tile per test; value, verdict and target sit together, so the distance problem
disappears entirely. Reads well at the 6–7 tests this academy has. At 15 it becomes a
wall of boxes — the same complaint, squarer. Also the tallest on a phone by a wide
margin (compare `?v=C&w=phone&d=full` against `?v=A&w=phone&d=full`).

---

## Recommendation

**A**, with B's footer strip ("nog nooit gemeten" / "over tijd") folded into A's card
footer. That gets #9 answered without paying for the moment-spine, and leaves B on the
shelf as a later view if measuring days turn out to be how coaches think.

---

## Open decisions

1. **Does BMI stay on this tab at all?** #3278 already trimmed it to a bare figure, and
   #3393 hid it from players and parents. On an install where the growth reference does
   not cover the age group it renders a number and an apology, every time. Options: keep
   it as a row (mockup A), or drop it from the tab and leave it on `Player · BMI-for-age`.
   A row costs almost nothing and keeps the derived figure next to the two measurements
   it comes from — but it is a product call, not a layout one.
2. **Is `Doel` one column or two?** A shows `≥ 3.400 m`. Where a level-banded test has
   several thresholds, one cell cannot hold them. Fall back to the chip alone for those?
3. **Verdict wording for lower-is-better.** A prints `net boven doel` for a time above
   the target — correct but easy to misread as good. Alternatives: `net te traag`,
   or a neutral `3% boven doel`.
4. **Does the category footer belong per category or once per tab?** With one category it
   is invisible; with four, the "geen doel" explanation repeats four times — which is
   knelpunt 3 in a new costume.

## Adjacent bug — file separately

`date_format` on the local Dutch install is WP's `F j, Y`, producing "juni 30, 2026"
across the whole plugin, not just here. The fix is the option, not the code (every
call site correctly uses `date_i18n( get_option('date_format') )`). Worth an issue
against the install profile / `InstallWizard` defaults so a Dutch install lands on
`j F Y`.

## Port acceptance

- Mockup-only chrome (`.mockup-*`, `.stage*`, `.bl-*`) is not ported. The `.tt-meas-*`
  classes are.
- No new colour values: every token used exists in `assets/css/tokens.css`. The verdict
  chip uses the `*-ink` pairs added in #3447 for status colour carrying text, so it
  clears 4.5:1 — the current `.tt-meas-flag-*` values do not.
- Colour never alone: each chip carries its own words, and the sparkline colour only
  restates what the chip says.
- Targets ≥ 48px: the `Verloop` disclosure keeps the 48px summary height it has today
  in `frontend-trend-chart.css`; the mockup draws it at 32px for density, which must be
  restored on the port.
- No horizontal scroll at 360px — the register collapses to a grid rather than scrolling.
  Measure inside an iframe, not with `--window-size=360`; headless Edge on Windows
  enforces a ~477px minimum window and will lie to you.
- Both consumers must be checked: the tab **and** `?tt_view=measurements`.
