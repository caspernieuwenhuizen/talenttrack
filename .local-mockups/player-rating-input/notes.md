# Player rating input — design exploration

Surface: the "Overall rating" field on `PostGameEvaluationForm` — the
deadline-bound post-match nudge a coach gets per player. Range 5.0–10.0,
step 0.5 (Dutch school-grade convention).

Today the field is `<input type="number" inputmode="decimal" step="0.5">`.
That requires: tap field → numeric keyboard pops → tap "7" → tap "." →
tap "5" → dismiss keyboard. On a sideline phone, especially gloved,
this is hostile.

## The four variants

| | Taps to set | Glanceable? | Discoverable? | One-handed thumb |
| --- | --- | --- | --- | --- |
| A · Chip grid    | **1** | ✓ all values visible | ✓ obvious | ✓ |
| B · Stepper      | 2–6 (avg ~3 from default 7.0)  | only current value | ✓ familiar from score | ✓ best |
| C · Slider       | 1 drag | ✓ with readout | partial — "is this snapping?" | partial — wide hand travel |
| D · Two-stage    | **2** | ✓ both stages visible | ✓ explicit | ✓ |

## Recommendation: **Variant A (chip grid)** as primary

Reasoning:

- **One tap to a final value.** No drag, no held button, no second-stage
  confirmation. Coach taps "7.5" and is done.
- **All 11 values on screen** at 360px without scrolling — 3 rows × 4
  columns (one filler cell). Glanceable: the coach sees the whole scale
  before committing, which matches how grades are mentally compared
  ("better than the 6.5 I gave last week").
- **Halves are visually deprioritized** (lighter weight, smaller font) so
  the eye lands on the whole numbers first. The half-step affordance is
  there for precision but doesn't dominate.
- **Tap targets ≥48px** — chips are `min-height: 48px`, gap 8px, so spacing
  meets the touch-target rule in CLAUDE.md §2.
- **No keyboard ever pops up.** The numeric keyboard on iOS / Android is
  the biggest single source of friction on the current field; this kills
  it.

## Strong fallback: **Variant B (stepper)** when screen budget shrinks

- The score-stepper pattern is already in match-execution; coaches
  already know it.
- Adds the **preset row** ("Below par 6 · Average 7 · Good 8 · Excellent 9")
  so a coach who isn't fine-tuning can skip straight to a common value.
- Lower information density — works if the rating box has to share a
  row with another field on a wide form.

## When variants C and D lose

- **C (slider):** drag precision on a 5-value range is overkill;
  worse than chips because the thumb travel demands a second hand or a
  big thumb stretch. Native `<input type="range">` on iOS Safari also
  has flaky `step="0.5"` rendering — visible thumb position doesn't
  always snap.
- **D (two-stage):** requires 2 taps for every rating, even whole
  numbers. The .0/.5 row also burns the same vertical space as the
  full chip grid would. Only wins if the design needs to suppress
  half-values for a power-user mode where everyone rates in whole
  numbers — but that's not a real constraint here.

## Open design questions

- Should the chip grid pre-select **7.0** (Dutch school "voldoende"
  baseline) so the user can submit without picking, or stay blank to
  force a deliberate choice? Pre-selecting feels honest to the
  post-match "good enough" workflow; leaving blank forces the
  reflective pause. Lean **blank** to avoid bias-toward-default — but
  test with one coach on a sideline phone first.
- Configurable `rating_min` / `rating_max` is in `tt_config`. Today
  the defaults are 5–10. If a club configures 1–10 (or 0–10), the
  chip grid grows to 19 (or 21) values — still fits in 360px as
  4-col × 5-row but the half-values get cramped. Worth a follow-up:
  hide half-step chips when the range exceeds ~12 cells, fall back
  to stepper.
- The same input is needed in **PlayerSelfEvaluationForm** (player
  rates themselves). The chip pattern should behave identically there
  — consistent muscle memory between coach-of-player and
  player-of-self forms.

## Port mechanics

If A wins, the production port is:

1. New component `\TT\Shared\Frontend\Components\RatingChips::render( $name, $value, $opts )`
   in `src/Shared/Frontend/Components/RatingChips.php`. Takes `name`,
   `value` (nullable for blank), `min`, `max`, `step`, returns the
   chip-grid HTML with a hidden `<input>` for form submission.
2. Drop into `PostGameEvaluationForm::render()` (replace lines 51–59)
   and `PlayerSelfEvaluationForm` at the analogous spot.
3. CSS for `.tt-rating-chip` joins
   `assets/css/frontend-evaluation.css` (or a new
   `assets/css/components/rating-chips.css` enqueued where forms render).
4. JS handler (≈40 lines) in
   `assets/js/components/rating-chips.js`: tap a chip → set hidden
   input value, toggle `aria-pressed`. No external deps.
5. Server-side validation in `validate()` already accepts decimals and
   range — no change.
6. **REST**: there is no REST surface for this form yet (it's a
   workflow-task response), but if/when one is added, the payload is
   `{ overall_rating: 7.5 }` — same wire shape, the component is
   purely UI.

## Files referenced

- [`src/Modules/Workflow/Forms/PostGameEvaluationForm.php`](../../src/Modules/Workflow/Forms/PostGameEvaluationForm.php) — current rendered input
- [`src/Modules/Workflow/Forms/PlayerSelfEvaluationForm.php`](../../src/Modules/Workflow/Forms/PlayerSelfEvaluationForm.php) — same pattern, player-side
- [`.local-mockups/match-execution/index.html`](../match-execution/index.html) — design tokens + score stepper reference
