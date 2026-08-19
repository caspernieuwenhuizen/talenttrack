# Test trends — design notes

Mockups gating **#2536** (player test timeline) and **#2537** (Test trends report).
Open `index.html` in a browser; each surface has a state picker for the measurement types.

## The governing rule

The shape of a trend follows the measurement's own definition. Two columns on
`tt_measurement_definitions` already carry everything needed:

| Column | Values | Added by |
| --- | --- | --- |
| `value_type` | `numeric`, `scale`, `passfail`, `status` | migration `0175`, `status` added in `0192` |
| `direction` | `higher`, `lower`, `neutral` | migration `0175` |

**No migration is needed for any of this.** The data model already distinguishes these cases; only
the presentation collapses them into one shape today (a sparkline for everything numeric, a
▲/▼ arrow regardless of direction).

| value_type | direction | Trend form | Verdict / ranking |
| --- | --- | --- | --- |
| numeric | higher / lower | Line chart, target band, level bands | Yes, direction-aware |
| numeric | **neutral** | **Columns per date — no chart** | **No** — factual change only |
| scale | higher / lower | Line chart clamped to `scale_min`–`scale_max` | Yes, direction-aware |
| scale | neutral | Columns per date | No |
| status | n/a | Coloured step strip (player) / matrix (report) | Distribution only, no ranking |
| passfail | n/a | Tick row + pass count (player) / pass-rate per round (report) | Count only, no ranking |
| any | any | < 2 results: explanatory line, never an empty axis frame | No |

## Why `neutral` gets columns and not a chart

Height, weight and shoe size have no better or worse. A rising line implies progress, a shaded band
implies a norm, and a "most improved" ranking implies the tallest player is performing best. All
three are false. What a coach wants from these is the series itself — which value on which date —
and that reads fastest as columns.

The change is still shown (`+6` cm) but in plain black with no chip: a fact, not a verdict.

Consequence for #2537: with `direction = neutral` the report drops the chart, the ranking strip and
the verdict column entirely. It is a different report shape, not the same report with grey styling —
worth stating in the spec so it isn't built as a CSS variation.

## Open design questions

1. **Axis orientation for `lower is better`.** The mockup keeps the natural orientation (2,10 s at
   the top, 1,90 s at the bottom), so an improving sprint line *descends*. That is honest — the
   y-axis reads like a stopwatch — but several coaches read a falling line as decline. The
   alternative is inverting the axis so "better" is always up, which makes every chart read the same
   way at the cost of an unusual axis. The mockup mitigates with an explicit line
   ("↓ lager is beter — de dalende lijn is vooruitgang"). **Decide before porting**; changing it
   afterwards silently reverses how every historical chart reads.
2. **How many lines on the report chart.** 15+ players is unreadable. The mockup draws 5 and says so
   ("5 van 15 spelers getekend · kies wie je ziet"). Options: default to the extremes (most improved,
   most declined, team average), or default to none and let the coach add players. Needs a call.
3. **`scale` with levels.** A scale test can also carry status levels. If both are configured, does
   the chart plot the number and colour the background by level, or is that too busy at 360px?
4. **Missing measurement vs. zero.** Shown as `—`, and the change is computed over the moments that
   exist. Confirm that matches how the Excel Trends sheet treats a gap, so screen and sheet agree.

## Port acceptance

- Classes are already `.tt-*` where they map to production; the mockup-only chrome (`.mockup-bar`,
  `.picker`, `.note-strip`, `.state`) is not ported.
- Charts are inline SVG with no script — they must render in the PDF export path and inside the
  50KB JS budget (#2536 decision).
- Colour never carries meaning alone: the target band is labelled, levels are named in the legend,
  and pass/fail uses ✓/✗ glyphs beside the colour.
- Every table scrolls inside its own `overflow-x` container; the page body never scrolls sideways at
  360px. The player-name column is `position: sticky` so the name stays readable while scanning
  dates.

  **Measured, not assumed.** Rendered in a 345px-wide iframe (narrower than the 360px budget):
  `viewport=345 · scrollWidth=345 · bodyScrollWidth=345`, no page-level horizontal scroll, and no
  element overflowing outside a deliberate scroll container. Note for whoever re-checks this:
  headless Edge on Windows enforces a minimum window width (~477px), so `--window-size=360` does
  **not** give a 360px layout viewport — it captures a 360px-wide crop of a wider page, which looks
  identical to an overflow bug. Measure inside an iframe, or the check lies to you.
- `.tt-rep-filter` carries `min-width: 0` and its controls `width: 100%`. Grid and flex items
  default to `min-width: auto`, so a `<select>` otherwise claims the width of its longest option and
  pushes the page wider than the viewport. Keep this on the port — it is the most common cause of
  the sideways scroll the mobile-first rule forbids.
- Dutch copy in the mockup is the intended user-facing wording. Source strings stay English through
  `__()`; the Dutch belongs in `languages/talenttrack-nl_NL.po`.
