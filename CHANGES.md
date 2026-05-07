# TalentTrack v3.108.2 — Pilot-batch follow-up I: eval delete UI + LookupPill sweep + referer-based breadcrumb back-link + eval-tab query parity (#0089 Batch I + F1)

Follow-up to v3.108.1 covering the first wave of items deferred to `ideas/0089-feat-pilot-batch-followups.md`. The remaining items (F2 my-evaluations scores, F3 my-* detail chrome, F4 goal save error, F7 PDP wizard skip-team-step, A3 eval subcategories, A4 team-overview HoD widget, A5 broad detail-page visual refresh, A7 upgrade-to-Pro CTA, K1-K5 KPI/widget investigation) stay in `ideas/0089` for subsequent ships.

## What landed

### (1) F5 — Inline evaluation delete button

REST `DELETE /evaluations/{id}` already shipped; the UI just didn't expose it.

- New `.tt-record-delete` generic handler in `assets/js/public.js` — driven by `data-rest-path` + `data-confirm-msg` + `data-deleted-msg`. On success the closest `[data-tt-row]` / `tr` / `li` ancestor fades out + is removed; if no such ancestor exists, the page reloads. Pattern is reusable for any future record where REST DELETE exists but the UI doesn't yet surface it.
- Wired onto each row of `FrontendPlayerDetailView::renderEvaluationsTab` (cap-gated on `tt_edit_evaluations`).

### (2) R2 — LookupPill always-translate sweep

`LookupPill::render()` already translated correctly; the user complaint was that some surfaces emit raw lookup keys (e.g. `right` instead of "Rechts").

Surfaces routed through pill / translator:

- `FrontendPlayerDetailView::renderProfileTab` — preferred-foot dd now via `LookupPill::render('foot_options', ...)`.
- `CoachDashboardView::render` — Foot inline now via `LookupPill::render(...)`.
- `FrontendComparisonView` — Foot column now via `LookupTranslator::byTypeAndName(...)`.
- `FrontendOverviewView::renderMyCard` — preferred-foot inline label now via `LookupTranslator::byTypeAndName(...)` inside the existing `__('%s foot')` template.

`FrontendMyProfileView` already translated correctly.

### (3) A1 — Breadcrumb back-link helper

New `FrontendBreadcrumbs::fromDashboardWithBack()` adds a leading "← Back" crumb sourced from `wp_get_referer()` when the referer is same-origin and distinct from the current page. Cheap referer-based path per the deferral question — no per-user back-stack store. Multi-step navigation (A → B → C → click back) goes back to B, not A — same as the browser's own Back button.

Wired on:

- `FrontendMyGoalsView::renderDetail` — was the user's example: "click goal from My card → 'back' should mean My card, not the goals list".
- `FrontendMyActivitiesView::renderDetail` — same shape.

Other detail views opt in by switching `FrontendBreadcrumbs::fromDashboard()` → `fromDashboardWithBack()`.

### (4) F1 — Player-file evaluations tab badge / list parity

`PlayerFileCounts::for()` and `FrontendPlayerDetailView::renderEvaluationsTab` now both filter on `(player_id, club_id, archived_at IS NULL)`. Without the explicit `club_id` clause the badge could count rows the tab query was filtering out (or vice versa) — depending on which was the stricter scope. Both pinned to `CurrentClub::id()` so the tab and the badge always agree.

## Out of scope (still tracked in `ideas/0089-feat-pilot-batch-followups.md`)

- F2 my-evaluations scores not displaying after wizard submit
- F3 my-* detail pages chrome (bullet+link → activity-detail card)
- F4 goal save error "goal does no longer exist"
- F6 double-activity row verification (probably already fixed in v3.92.7)
- F7 PDP wizard from player profile should skip team-selection step
- A3 evaluation subcategories rendering in `RateActorsStep`
- A4 team-overview HoD widget (First/Last/Status/PDP/Attendance)
- A5 player profile + detail-page visual refresh (CSS-led)
- A7 upgrade-to-Pro CTA discoverability
- K1-K5 KPI / widget data investigation

## Affected files

- `assets/js/public.js` — generic `.tt-record-delete` handler
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — eval delete buttons + preferred-foot pill + eval-tab query club_id
- `src/Shared/Frontend/CoachDashboardView.php` — preferred-foot pill
- `src/Shared/Frontend/FrontendComparisonView.php` — Foot column translator
- `src/Shared/Frontend/FrontendOverviewView.php` — My-card preferred-foot translator
- `src/Shared/Frontend/Components/FrontendBreadcrumbs.php` — `fromDashboardWithBack()` + `sameOriginReferer()` + `class` field on render items
- `src/Shared/Frontend/FrontendMyGoalsView.php` — back-link on goal detail
- `src/Shared/Frontend/FrontendMyActivitiesView.php` — back-link on activity detail
- `src/Infrastructure/Query/PlayerFileCounts.php` — eval count gets `club_id` filter
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata

No new translatable strings — all new copy reuses existing `__()` strings ("← Back", "Delete this evaluation? This cannot be undone.", "Evaluation deleted.", "Delete evaluation").
