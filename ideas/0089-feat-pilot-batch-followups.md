<!-- type: feat -->

# Pilot batch follow-ups — items deferred from v3.104.2

A May 2026 pilot-feedback round surfaced ~25 items. v3.104.2 shipped 8 mechanical fixes (trial-cases gate, preferred-foot translation, edit-save redirects, eval wizard rateable filter, head_coach role label, FIFA-card self-bypass, editable academy start date, generic dropdown-dependency JS).

These remaining items need their own focused work. Listed here so they're tracked rather than lost in the chat thread.

## Bugs awaiting investigation

**F1 — Player-file evaluations tab badge > 0 but list empty.** `PlayerFileCounts::for()` and `FrontendPlayerDetailView::renderEvaluationsTab` queries don't fully agree (badge counts something the tab query filters out, or vice versa). Investigation: align WHERE clauses; add a smoke test that the badge always matches `count(rows-rendered)`.

**F2 — My evaluations page shows none of the scores entered through the new-eval wizard.** Trace `RateActorsStep` → final-step submit → `EvaluationsRestController::create_eval` → `tt_eval_ratings` insert. Probable cause: wizard's review-step submit isn't actually persisting the ratings, or the FrontendMyEvaluationsView query joins on a stricter scope than the wizard writes.

**F3 — My evaluations / My goals / My PDP detail pages render as a basic bullet+link list.** Bring them up to the `tt-activity-detail` chrome (v3.92.5) — wrapper card + meta-row badges + body section. CSS-only changes plus a few render method updates.

**F4 — Goal save error: "Goal does no longer exist" after admin creates one in the wizard.** Wizard's success redirect may carry a stale id, or the post-create lookup doesn't see the row through the demo-mode / club-scope filter. Find the error string in PHP, trace the redirect path.

**F6 — Double activity row on My activities.** Likely already fixed in v3.92.7; if it reproduces in current main, re-investigate (probably a JOIN duplication when a player has both `player_id` and `guest_player_id` attendance rows for the same activity).

**F7 — New-PDP wizard from player profile starts with team-selection.** Should consume `?player_id=N` from URL and skip the team step. Either the PDP wizard doesn't exist yet (creation is a flat form) or it does and the step needs a `notApplicableFor()` gate.

**F5 — Cannot delete an evaluation.** REST DELETE exists; UI doesn't expose it. Add a Delete action button (cap-gated `tt_edit_evaluations`) on the evaluation row in `FrontendEvaluationsManageView` and on the player-file evaluations tab.

## Polish that needs proper scoping

**R2 — LookupPill always-translate sweep.** The pill component already translates correctly when called. The user-visible inconsistency is that some surfaces emit raw lookup keys without going through the pill. Audit every surface that renders a value sourced from `tt_lookups` and route through `LookupPill::render(...)` (or `LookupTranslator::name(...)` where a pill is too heavy). Targets to start: `CoachDashboardView` ("Foot:" line), `FrontendComparisonView` ("Foot" column), `FrontendPlayerDetailView::renderProfileTab` ("Preferred foot" dd), `FrontendOverviewView` (preferred-foot inline label).

**A1 — Breadcrumb trail = navigation history, not static chain.** User wants "back where I came from" instead of the static Dashboard → Section → Detail chain. Two options:

- Cheap: add a `back_url` parameter to `FrontendBreadcrumbs::fromDashboard()` that prepends a "← Back" crumb derived from `wp_get_referer()` when same-origin. Caller opt-in.
- Full: a per-user back-stack stored in transient, popped on each navigation. More invasive.

Pick the cheap path first; revisit if it doesn't satisfy.

**A3 — New-evaluation wizard subcategories.** The schema (`tt_eval_categories.parent_id`) and the seed (~21 subcategories under 4 mains) already exist. RateActorsStep's quick-rate grid only renders main categories — extend to render subcategories nested under each main (collapsible group, indented row, or two-tier grid). Persist subcategory ratings the same way as main ones.

**A4 — Team-overview HoD widget.** Net-new widget. Backend `team_id` config (single-team selector); rendered table with columns First/Last/Status/PDP status/Average attendance. Register in `WidgetRegistry`. Persist as a slot on the HoD persona-template default.

**A5 — Detail pages broad visual refresh.** Player profile tab (dt/dd visual differentiation — uppercase muted dt, larger weighted dd). Goal detail / My-goals detail to align with `tt-activity-detail` chrome (parallel to F3). Evaluations detail likewise. CSS-led, ~3-5 view files updated.

## KPI / widget data investigation (separate PR per scope)

**K1-K5** — HoD KPI strip empty, KPI cards generally empty, Upcoming-activities widget empty, Scout `scout_report` widget no-op, Assigned-players grid says "scout setting authorization" with no clear next step. All six HoD KPI sources are registered (`CoreKpis::register()`); investigate each `compute()` to find the one(s) returning null. Surface user-friendly empty-state copy where the answer is genuinely "no data yet" vs a real bug.

**A7 — Upgrade-to-Pro CTA discoverability.** The user is on Standard and doesn't see how to upgrade further. Sales-funnel UX: where in the product does "→ upgrade to Pro" surface today? Where should it? Defer to a focused design pass.

## Triage hint

If shipping in batches:

- **Batch A (mechanical, low-risk):** F5 eval delete UI, F6 verify, R2 sweep — ~2h.
- **Batch B (UI polish):** A5 detail pages, F3 my-* detail chrome — CSS-led, ~3-4h.
- **Batch C (architectural):** A1 breadcrumb back-url helper, F7 PDP wizard skip — ~2-3h.
- **Batch D (feature):** A3 subcategories, A4 team-overview widget — ~6-10h.
- **Batch E (data/render bugs):** F1 evaluations tab, F2 my-evals scores — needs reproduction with real data; ~3-5h.
- **Batch F (KPI/widget):** K1-K5 — investigation, then per-KPI fix; ~3-6h.
- **A7 upgrade CTA:** its own design pass.
