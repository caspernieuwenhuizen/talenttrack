# Team chemistry page — notes

Design-of-record for the chemistry-page rework filed as #1002. Ports the v4.4.0 blueprint-editor visual language (single-tier slot per position, roster sidebar with team-fit scores, click-or-drag picker) and folds in the chemistry-specific affordances the existing surface needs (score headline, link visualisation, suggested-XI / override toggle, coach pairings panel, save-as-blueprint).

## What the mockup shows

The state-picker chrome at the top toggles four states for refinement:

- **Suggested XI (default)** — engine's pick pre-populated on entry. Overrides hidden.
- **Override (try a lineup)** — accent banner visible; the one override on the pitch (`CAM · Thijs`) shows the accent border + `↺` glyph + the lighter accent background, demonstrating how overrides visually distinguish from engine picks.
- **Save as blueprint modal** — input for blueprint name + flavour (match-day / squad-plan), routed through the existing `POST /teams/{id}/blueprints` + `PUT /blueprints/{id}/assignments` (the in-repo caller already uses the v4.3.21 `ref: { kind: 'player' }` shape).

The pitch shows ~13 chemistry links via SVG between paired slots — green (strong), grey (neutral), red (mismatch). One LB → CB long line is red to demonstrate the mismatch colour; this is the visual contract that already existed pre-rework.

## Friction points addressed

| # | v1 friction | Redesign response |
|---|---|---|
| 1 | "Team chemistry page doesn't work" — pilot symptom not specified | Executor inspects + reports specifics during port; the layout rework is independent of whatever's broken on v1. If the breakage is REST-side (404 / 500 from a chemistry endpoint), filed as a separate bug. |
| 2 | v1 page (where it works) is functional but visually inconsistent with the v4.4.0 blueprint editor | Same tokens, same roster sidebar, same slot card pattern. Coach moves between blueprint editor and chemistry page without re-learning the surface. |
| 3 | Three-tier slot stack is irrelevant on the chemistry page (chemistry scores tier=primary only) | **Single slot row per position**. Cleaner, less to scan. |
| 4 | No way to add cross-team / guest / custom on the v1 chemistry page | "+ Add cross-team / guest / custom" button on the roster sidebar; mirrors the blueprint editor flow. Useful for "what if we bring in a trial player". |
| 5 | Score is buried mid-page | **Headline scoreboard** at the top — composite chemistry as the dominant card, with formation-fit / style-fit / depth / data-coverage as sub-cards. Each shows the value out of 3 and a trend hint vs last save. |
| 6 | Override commit model is fuzzy on v1 ("did my edit save?") | Explicit **Suggested / Override** segmented toggle in the toolbar + accent banner when in override mode + per-slot `↺` glyph on overridden slots. Reset-to-suggested button discards overrides. Save-as-blueprint is the only persistence path. |

## Design decisions

- **Layout** — 3-column at ≥1080px: roster (left, 280px) + pitch (centre, flex) + pairings (right, 260px). Stacks at 820px and below.
- **Tokens** — same `--tier1` / `--pitch-grass` / `--ink` / etc. as the blueprint editor + adds three chemistry-link colours (`--chem-strong`, `--chem-neutral`, `--chem-mismatch`).
- **Chemistry links rendered as SVG** behind the slot cards (z-index 1, slot cards z-index 2). Each line is a thin coloured stroke; line endpoints align with slot centre coordinates passed in from PHP at render time.
- **Slot card width 120px** (vs the blueprint editor's narrower stack-row width). Single row gives us the budget for a meaningful name pill ("Thijs" / "Bram H." per #997's first-name + last-initial rule).
- **Override visual contract**: `is-override` class flips the player pill's border + background to accent and appends a `↺` glyph. Makes coach-edits visible at a glance.
- **Roster fit-score** as a chip on each row, colour-coded (green ≥ 2.5, neutral 2.0-2.4, red < 2.0). Lets the coach scan for the strongest available pick at the relevant position type.

## Open questions for refinement

1. **Default view on entry** — Suggested XI pre-populated (mockup default) vs empty pitch coach builds from scratch. Recommend keep pre-populated; matches v3.96.0 behaviour.
2. **Override commit model** — session-only state (mockup default; survives page interactions, lost on reload) vs transient `tt_team_chemistry_sandbox` row that persists across reloads. Recommend session-only — simpler, no schema, matches the "try a lineup" mental model.
3. **Roster sidebar position** — left (mockup default, matches blueprint editor) vs right. Recommend left.
4. **Coach-marked pairings editor** — inline right panel (mockup default) vs tab behind the pitch vs separate modal. Recommend inline panel.
5. **Save-as-blueprint flow** — modal (mockup default, name + flavour) vs auto-name + open detail page. Recommend modal — explicit naming reduces "what blueprint is this?" confusion later.
6. **What's actually broken on v1** — executor inspects during port. If the breakage is unrelated to layout (e.g. REST endpoint regression), file as a separate bug rather than fold into the rework.
7. **Link rendering precision** — the mockup uses fixed SVG coordinates per slot. Production needs slot positions computed from the formation template's `slots_json` (same source the blueprint editor uses). Confirm: drive both surfaces from the same `slots_json` parser, or duplicate?

## Out of scope

- Multi-team chemistry comparison view.
- Per-player chemistry breakdown drilldown.
- Schema / REST contract changes.
- The chemistry algorithm itself (unchanged).
- The pairings management page (full CRUD lives elsewhere; this surface only adds / removes inline).

## Workflow note

This mockup is the design-of-record for the executor's port. Tokens + selectors + JS hooks port directly. The state-picker chrome strips out on production.

- New CSS file on port: `assets/css/frontend-team-chemistry.css` (replaces whatever inline styles the current view uses).
- Existing JS file `assets/js/frontend-team-chemistry.js` is already wired for the v4.3.21 `ref: { kind: 'player' }` shape — extend rather than rewrite.

Refine the 7 open questions in this file (or comment on #1002), then flip the label to `ready-for-dev`.

## Reference

- Issue: #1002.
- Blueprint editor mockup (the visual parent): `.local-mockups/blueprint-editor/index.html`.
- Existing engine: `src/Modules/TeamDevelopment/BlueprintChemistryEngine.php`.
- REST: `docs/rest-api.md` → "Team development — chemistry" row.
- v4.4.0 ship that introduced the visual language this borrows: PR #966.
