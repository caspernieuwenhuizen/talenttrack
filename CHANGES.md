# TalentTrack v3.108.3 — Pilot-batch follow-up II: profile tab visual contrast + generic record-detail chrome (#0089 A5 + F3)

CSS-led follow-up to v3.108.2 covering two visual fixes from `ideas/0089`. The remaining items (F2 my-evaluations scores, F4 goal save error, F6 double-activity verification, F7 PDP wizard skip team, A3 eval subcategories, A4 team-overview HoD widget, A7 upgrade-to-Pro CTA, K1-K5 KPI/widget investigation) stay in `ideas/0089` for subsequent ships.

## What landed

### (1) A5 — Profile tab dt/dd visual contrast

User complaint: "field names and values look too much the same" on `?tt_view=players&id=N` Profile tab. Affected every surface using `.tt-profile-dl`.

- `.tt-profile-dl dt` — bumped to 11px uppercase 600-weight tracking-wide (0.06em) muted. Reads as a label.
- `.tt-profile-dl dd` — bumped to 1rem 600-weight ink (`var(--tt-profile-ink, #1a1d21)`). Reads as the value, dominant.
- First-column width grew from 130px to 140px; row-gap from 6px to 10px so the rows breathe.

Surfaces that pick this up automatically: `FrontendPlayerDetailView::renderProfileTab` (Identity + Academy columns), `FrontendTeammateView` (Playing details), `FrontendMyProfileView` (account info).

### (2) F3 — Generic record-detail card chrome

Promoted the v3.92.5 `.tt-activity-detail*` block to a generic `.tt-record-detail*` set; the existing class names stay as aliases so existing markup keeps working.

```css
.tt-record-detail        — outer card (white bg, 1px border, 10px radius, 18/20 padding)
.tt-record-detail-meta   — top meta row (badges + chips, divider)
.tt-record-detail-body   — content section (h3 + p with line-height 1.5)
```

Applied to `FrontendMyGoalsView::renderDetail` (the goal-detail page reached from the My-card hero). Previously the detail was a bare `tt-goal-detail` wrapper with no card chrome and no separator between the meta row and the description. Now it matches the activity-detail card visual language.

Other detail pages opt in by adding `tt-record-detail` to the article wrapper.

## Out of scope (still tracked in `ideas/0089-feat-pilot-batch-followups.md`)

- F2 my-evaluations scores not displaying after wizard submit
- F4 goal save error "goal does no longer exist" after admin wizard
- F6 double-activity row verification
- F7 PDP wizard from player profile should skip team-selection step
- A3 evaluation subcategories rendering in `RateActorsStep`
- A4 team-overview HoD widget (First/Last/Status/PDP/Attendance)
- A7 upgrade-to-Pro CTA discoverability
- K1-K5 KPI / widget data investigation

## Affected files

- `assets/css/frontend-profile.css` — Profile tab dt/dd contrast bump
- `assets/css/public.css` — generic `.tt-record-detail*` block + activity-detail aliases
- `src/Shared/Frontend/FrontendMyGoalsView.php` — goal detail wrapped in `tt-record-detail` card
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata

CSS-only — no new translatable strings.
