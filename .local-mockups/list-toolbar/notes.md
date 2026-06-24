# List filter/search toolbar — restyle (#1753)

Mockup for the shared toolbar (search + filters + sort) rendered above **every** list view by
`FrontendListTable` ([`src/Shared/Frontend/Components/FrontendListTable.php`](../../src/Shared/Frontend/Components/FrontendListTable.php), CSS `.tt-list-table-filters` in [`assets/css/frontend-admin.css`](../../assets/css/frontend-admin.css)). Same functionality — only the visual treatment changes.

Open `index.html` directly; the picker at the top toggles the five states.

## States (picker)

| State | Direction |
| --- | --- |
| **D · Register ★** | **Recommended.** Page-head (title + sub + actions) over a white filter card with uppercase micro-labels — mirrors the `06-attendance-minutes` deck slide stakeholders have seen. |
| A · Card | White card, title row + action, filters on a divided second row. |
| B · Minimal | Borderless; controls on the page background under a hairline rule. |
| C · Pill | Single rounded pill bar, each control a pill. |
| Baseline | Today's soft-grey `.tt-list-table-filters` box, for diffing. |

## Decision — D is locked (the standard for all lists)

**Ship D** as the standard chrome for **every** list view (implement once in the shared
`FrontendListTable`). It's the only direction anchored to an existing deck slide, reuses the
register card/table aesthetic so toolbar + list read as one surface, and the uppercase
micro-labels match `.tt-table th`. A/B/C are kept only for diffing.

**Chrome is standard, filters are content-driven.** D fixes the *shell* (page-head, white filter
card, micro-labels, control styling). The *filter set* still varies per list — each list declares
its own controls (Players → Team/Status/Sort; activities → date-range; etc.), rendered through the
same D shell. Don't uniformise the filters.

## Mobile-first

Base CSS targets ~360px (controls stack full-width, 48px min-height, 16px inputs to avoid iOS
zoom). The `@media (min-width:768px)` block collapses each variant to its inline desktop layout.
The sample table hides Team/Position columns below 768px. This matches CLAUDE.md §2.

## Token mapping (mockup → production)

The mockup defines deck colours as CSS variables; the port should map them onto the existing
TT custom properties rather than hardcoding hex:

| Mockup var | Value | Production role |
| --- | --- | --- |
| `--tt-primary` / `--tt-primary-deep` | `#0b3d2e` / `#07261c` | green surfaces / headings |
| `--tt-secondary` | `#e8b624` | gold primary action |
| `--tt-line` | `#e3e6e1` | hairline borders |
| `--tt-bg` | `#f4f6f3` | soft panel background |
| `--tt-muted` | `#5a636a` | sub-text / micro-labels |

## Port acceptance (for the implementation issue)

- [ ] `.tt-list-table-filters` (and `FrontendListTable` markup) restyled to the chosen state, applied across **all** lists (shared component).
- [ ] No functional regression: search, each filter type, sort, no-JS apply, status line.
- [ ] 360px = no horizontal scroll; controls ≥48px, spaced ≥8px.
- [ ] Stable `.tt-list-table-*` selectors preserved (SaaS contract).
- [ ] 360px + desktop screenshots on the PR.

## To test on device

- [ ] Confirm the D micro-labels don't feel cramped at 360px (may drop to placeholders-only on the smallest width).
- [ ] Check the gold "+ New" action contrast on the green/paper background meets 3:1.
