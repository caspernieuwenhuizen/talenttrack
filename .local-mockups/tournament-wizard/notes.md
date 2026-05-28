# Tournament wizard redesign — notes

Design-of-record for the new-tournament wizard rework + a post-creation **Add match** surface that currently doesn't exist in the UI.

Pilot reports two problems with v1:

1. **Wizard does not look nice after the first step.** Step 2 (Formation) is a vertical list of bare radios. Step 3 (Squad) is a list of checkboxes with raw position chips. Step 4 (Matches) is a 2-column grid of raw `<input>` / `<select>` rows. Step 5 (Review) is a `<dl>` with no edit affordance. All look like 1990s admin forms next to the polished VCT / match-execution / blueprint-editor ships.
2. **Doesn't work when on the match creation page.** The "match creation page" is the wizard's **Matches step** (step 4). The substitution-windows comma-text input is unforgiving (typos, no validation feedback), the headline doesn't update until the row is saved, and there's no way to remove a half-filled match card. Empty rows pass `validate()` silently because the spec allows blank rows to be skipped — but this hides bad rows too.

## Friction points addressed in this mockup

| # | v1 friction | Redesign response |
|---|---|---|
| 1 | All steps ship in raw `<input>` / `<select>` chrome without cards / typography hierarchy | Every step renders inside `.card` containers with a `card-title` UPPERCASE header; the page has a clear breadcrumbs + h1 + step-title + step-desc rhythm. |
| 2 | Formation is a bare radio group | Radio-cards with a mini pitch glyph showing the actual formation shape. Checked state is a 2px accent border + tinted background. |
| 3 | Squad checkbox + position chips have no filtering | Squad-list lives in a card with a search input + live count chip ("9 of 14 selected"). Un-picked rows fade out so the picked subset is visually dominant. |
| 4 | Position chips look identical to non-position chips | Active position chips fill with the accent colour. Inactive look like outline buttons. Position-type "GK/DEF/MID/FWD" stays as the v1 spec (NOT individual position codes — per the v1 shaping decision). |
| 5 | Match step's 2-column grid feels cramped | One match per `.match-card`; head row carries `#1` sequence circle + live headline ("vs Den Helder") + Remove button. Fields below in a 2-column grid (collapses to 1 on mobile). |
| 6 | Substitution windows = comma text input | Chip editor: type a minute, press Enter or comma → chip appears. Backspace from empty input pops the last chip. Each chip's `×` removes it. Auto-sort + dedupe at submit time. |
| 7 | "Add another match" button is small + visually identical to the action buttons below | Dashed-border full-width "+ Add another match" tile, accent-coloured text. Clear primary affordance. |
| 8 | Empty row passes `validate()` silently when it should warn | Match card with a missing headline shows an inline error under the Opponent field. Empty cards (no fields filled at all) silently drop — same v1 behaviour, kept by spec. |
| 9 | Headline doesn't update until save | Headline row is live — falls back to "New match — fill in opponent below" in italic when empty; flips to "vs <opponent>" / "<label>" on input. |
| 10 | Review step is a flat `<dl>` | Card per step with an "Edit" link in the top-right that jumps the wizard back to that step. Match summary lists pretty headlines + minutes/subs in a meta line. |
| 11 | No way to add a match after creation | New post-creation **Add match** variant (last button in the state picker) — separate surface from the wizard, accessible from the planner page via "+ Add match". Same chip editor, same field grid, plus a "Position in sequence" select for inserting between existing matches. |

## State-picker chrome (mockup-only)

The bar at the top is mockup-only — strip it on port. It lets the reviewer toggle between the 5 wizard steps + the standalone Add-match surface without faking state. The progress strip below updates to mirror the picked step.

## Design decisions

- **Desktop-leaning** as the user requested (~720px+ canvas, denser fields). Mobile breakpoint at 640px stacks the field grid to 1 column. Tap targets stay ≥ 44px on every viewport.
- **Cards over flat forms**. Every grouped set of fields lives in a `.card` (white panel, line border, 22px padding). Mirrors the v4.5.0 match-prep three-column rework's card pattern.
- **Progress strip is a 5-column grid**. Each item is a labelled chip; current = accent fill, done = success fill with checkmark, pending = mute. Falls to 2 columns on narrow viewports.
- **Action bar is sticky at the bottom of the form (logical, not CSS-sticky)**. Cancel on the left; Back + Next on the right. Next becomes "Create tournament" on Review, "Add match" on the post-creation variant. Back hides on the first wizard step + on the standalone variant.
- **Chip editor is the headline new affordance**. Spec is: numeric input only; Enter / comma to add; Backspace from empty pops last; click `×` to remove. Chips auto-sort at submit time. Validation: each chip must be > 0 AND < duration_min.

## Open questions for refinement

1. **Format (7v7 / 9v9 / 11v11) field** — added to step 1 as a "Format" dropdown next to Anchor team. The v1 wizard inferred format from the formation list, but giving the coach an explicit Format → Formation cascade is cleaner. **Confirm: keep the Format field, or strip it and derive from anchor team age group?**
2. **Position-type chips** — the mockup uses GK/DEF/MID/FWD per the v1 spec. **Confirm: keep position types, or switch to specific positions (CB, LB, RB, etc.)?** Specific positions would let auto-balance match position-of-play to formation slots more precisely, but doubles the surface complexity.
3. **Formation pitch glyphs** — currently hand-drawn via absolute-positioned dots. They illustrate the shape but don't match the exact pitch SVG used elsewhere in the app (`PitchSvg::render()`). **Worth reusing PitchSvg? Or accept the small mockup-only divergence and ship simple dots?**
4. **Add-match "Position in sequence"** — useful when the operator wants to insert a match mid-tournament after some have been played. **Confirm: ship the dropdown, or always insert at end and rely on drag-to-reorder on the planner?**
5. **Anchor team's roster source** — squad step pulls from the picked team's active players. Trial players (`status='trial'`) currently render with a "trial" sub-line and unchecked by default. **Confirm: keep trial players visible but unchecked, or filter them out entirely?**
6. **Substitution windows max value** — spec says `0 < w < duration_min`. The chip-editor's hint text reads "Values must be 1–19" when duration is 20 — live update or fixed copy?

## Out of scope for this redesign

- The tournament-detail / planner view (`?tt_view=tournaments&id=N`). That surface already has a different redesign in motion; this mockup leaves it untouched.
- Drag-to-reorder matches mid-wizard. Spec is "Add at end"; reorder happens on the planner.
- Auto-balance / per-player target-minutes wizard. Lives on the planner, not in the wizard.

## Workflow notes

- This mockup is the design-of-record for the executor. Port the HTML structure + CSS rhythms exactly. The state-picker chrome strips out.
- Tokens used: `--tt-ink`, `--tt-ink-soft`, `--tt-paper`, `--tt-bg`, `--tt-line`, `--tt-accent`, `--tt-success`, `--tt-warn`, `--tt-danger`, `--tt-mute`, `--radius`, `--radius-sm`, `--tap-min`. All match the production token names.
- New CSS file on port: `assets/css/frontend-tournament-wizard.css`. New JS file: `assets/js/components/tournament-wizard.js` (chip editor + headline-live-update behaviour). The wizard framework's Save/Cancel/Back/Next chrome stays — the mockup's action bar shows the production wizard's existing button layout.
- The post-creation **Add match** surface needs a new dispatch case + a new step (likely `?tt_view=wizard&tt_wizard=new-tournament-match&tournament_id=N`). REST is already `POST /tournaments/{id}/matches`.

## Friction points NOT addressed (deferred)

- Squad's "target minutes per player" is on the planner, not the wizard. The wizard collects who's in the squad; the planner allocates minutes.
- Tournament-level toggles (e.g., "auto-publish matches", "send parents the schedule") would land on the detail view's settings tile.

## Reference

- v1 wizard files: `src/Modules/Tournaments/Wizard/{BasicsStep,FormationStep,SquadStep,MatchesStep,ReviewStep}.php`.
- REST: documented in `docs/rest-api.md` under "Tournaments (#0093)".
- Mockup workflow convention: [`README.md`](../README.md).
