<!-- type: feat -->

# Player "My evaluations" view — visual polish

## Status

**Done.** Shipped in v3.18.0 alongside #0004. The shared `RatingPillComponent` was introduced here and is reused by the My card tile.

## Problem

The player-facing "My evaluations" view (`src/Shared/Frontend/FrontendMyEvaluationsView.php`) is functionally correct but visually poor. Three specific complaints from real players:

1. **The overall score is buried.** Players care primarily about "what's my overall rating?" — it's the headline of any evaluation for them. Today it's just another cell in a dense table, visually indistinguishable from individual category ratings.
2. **Main categories and subcategories look identical.** The evaluation structure is hierarchical (e.g. Technical → Passing, Dribbling, First touch), but the rendered view gives no visual weight difference between the two levels. A player can't scan the view and see the structure.
3. **The table wraps badly on mobile.** Narrow screens force ugly wrapping and horizontal scrolling. Since most players will view this on a phone, this is the biggest practical problem.

The cumulative effect is that a player looks at their evaluations page and sees noise, not progress. That undermines the whole purpose of showing them their own ratings.

Who feels it: every player who visits their dashboard. Plus parents who read it over their kid's shoulder.

## Proposal

A visual redesign of the evaluations-listing experience for players, plus a matching update to the rating pills on the player's Overview dashboard so the two surfaces feel like one coherent product. Four specific changes:

1. **Overall-score circular badge** — large, branded, prominently placed (left on desktop, top on mobile) for each evaluation row/card. This is what the player sees first.
2. **Collapsed-by-default hierarchy** — main categories render inline with their aggregated score; subcategories are hidden behind a click-to-expand control per evaluation. Players see the structure at a glance and can drill in if they want detail.
3. **Responsive layout** — table above 640px, card-stack below. Mobile users get a clean scroll of evaluation cards; desktop users keep the scannable table.
4. **Overview rating-pill consistency** — the rating pills on `FrontendOverviewView` adopt the same visual treatment as the circular badges so both surfaces feel coherent.

## Scope

### FrontendMyEvaluationsView rebuild

Surface: `src/Shared/Frontend/FrontendMyEvaluationsView.php`.

Structure per evaluation (desktop ≥640px):

- Table row, two columns:
  - **Left column**: large circular badge with the overall score (e.g. "4.2"), brand color, ~60px diameter. Below the badge: the evaluation date in small text.
  - **Right column**: main categories rendered as pill-style chips ("Technical: 4.3", "Tactical: 3.9", etc.), each with subtle color-coding (green/yellow/red tier-based). Below that: a "Show detail" link that expands subcategory breakdown for this row.
- Expanded detail (when "Show detail" clicked): each main category's subcategories listed with individual ratings, indented/smaller. Click again to collapse.

Structure per evaluation (mobile <640px):

- Card, full-width:
  - **Top**: large circular badge (same as desktop left). Positioned at the top-center of the card.
  - **Below**: main categories as pill chips, wrapping naturally.
  - **Bottom**: "Show detail" link that reveals subcategories.
- Cards stack vertically in reverse-chronological order (newest first).

Reuses `RatingInputComponent` display conventions from #0019 Sprint 1 where applicable (it's primarily an input component, but the color-coding and tier thresholds are shared).

### FrontendOverviewView rating-pill update

Surface: `src/Shared/Frontend/FrontendOverviewView.php`.

The existing rating pills get the same color-coding and tier-threshold treatment as the main-category pills in MyEvaluations. If the pills are already visually present, this is a CSS-only change. If they're rendering via a different component, refactor to share the pill rendering with MyEvaluations.

### Shared pill component

New: `src/Shared/Frontend/Components/RatingPillComponent.php`.

- Takes a numeric rating and optional context.
- Outputs a pill with color tier (green/yellow/red), accessible text, tooltip with full value.
- Used by both MyEvaluations (main-category pills) and Overview (existing pills, retrofitted).

### Tier thresholds

Clear, documented:
- **Green (strong)**: rating ≥ 4.0
- **Yellow (developing)**: 2.5 ≤ rating < 4.0
- **Red (needs attention)**: rating < 2.5

These are hardcoded defaults for v1. If clubs later want configurable thresholds, that's a separate idea.

### Accessibility

- Circular badge has a visually-hidden label ("Overall rating: 4.2 out of 5").
- Pills have aria-labels with full meaning ("Technical, rating 4.3 out of 5, developing").
- "Show detail" control is a proper `<button>` with aria-expanded.
- Color is never the only indicator — text always present.

## Out of scope

- **Non-player views** of evaluations (coach view, HoD view). Those use different templates and should be reviewed separately if needed.
- **Configurable tier thresholds.** Hardcoded for v1; configurable is future work.
- **Trend lines or sparklines.** Decided against during shaping — keeps the view clean and focused on the overall number.
- **Filtering or date-range selection** within the view. Players see reverse-chronological list; that's enough for v1.
- **Export to PDF** for players. That's #0014's territory (report generator for players / parents / scouts).
- **Styling of the evaluation detail pages.** Only the list/overview-level treatments are in scope; clicking through to an individual evaluation detail page is not part of this spec.

## Acceptance criteria

### Visual

- [ ] On desktop, each evaluation row shows a large circular badge with the overall score prominently placed on the left.
- [ ] Main-category pills use the three-tier color system consistently.
- [ ] Subcategories are hidden by default; a "Show detail" control expands them.
- [ ] On mobile (tested at 375px and 414px), evaluations render as stacked cards with the circular badge at the top.
- [ ] No horizontal scrolling on any mobile viewport.

### Overview pill consistency

- [ ] Rating pills on FrontendOverviewView use the same color-coding and typography as the main-category pills on MyEvaluations.
- [ ] The shared RatingPillComponent is used by both views.

### Accessibility

- [ ] Screen reader announces each evaluation's overall rating clearly.
- [ ] Keyboard navigation reaches and toggles "Show detail" controls.
- [ ] Color is never the only indicator of rating tier.

### No regression

- [ ] All evaluations that previously displayed still display.
- [ ] The data shown is unchanged — this is visual-only.
- [ ] No performance regression (measured via page load time).
- [ ] Existing tests (if any) pass.

## Notes

### Timing — MUST come after #0019 Sprint 1

Per shaping decision: this spec is deferred until after #0019 Sprint 1 lands. Reason:

- Sprint 1 introduces the frontend-admin CSS scaffold and shared form components.
- Building this feature against the pre-Sprint-1 CSS means rework when the scaffold lands.
- Delay to the player-visible fix is ~30 hours (Sprint 1's duration), not months. Worth it for consistency.

When Sprint 1 is complete, this spec becomes pickable. Sprint 1's new CSS variables, typography scale, and color tokens should be used directly. The RatingPillComponent should live under `src/Shared/Frontend/Components/` alongside Sprint 1's other components.

### Sizing

~5–7 hours. Breakdown:

- RatingPillComponent (shared): ~1 hour
- MyEvaluations rebuild (desktop + mobile layouts): ~3 hours
- Overview pills refactor to use shared component: ~1 hour
- Accessibility pass + testing: ~1 hour
- Buffer: ~1 hour

### Touches

- `src/Shared/Frontend/FrontendMyEvaluationsView.php` (modify)
- `src/Shared/Frontend/FrontendOverviewView.php` (modify — pills section)
- `src/Shared/Frontend/Components/RatingPillComponent.php` (new)
- CSS: either `assets/css/frontend-admin.css` (adding rules) or a dedicated component CSS under `assets/css/components/rating-pill.css` consistent with Sprint 1's convention

### Depends on

**#0019 Sprint 1** (CSS scaffold and component conventions). Do not start before Sprint 1 is complete.

### Blocks

Nothing.

### Relation to other ideas

- **#0014** (player profile rebuild + report generator) — will restyle the profile surface, which is adjacent. #0014 should consume the RatingPillComponent introduced here.
- **#0004** (My card technical errors — needs triage) — is the sibling issue to this one, on the "My card" tile. Spec this one first; may inform #0004's shaping.
