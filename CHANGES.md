# TalentTrack v3.92.5 — Pilot batch PR 3: branding logo, my-activities, activity detail, PDP redirect + signed green

PR 3 of 3 — closes the operator's 10-item pilot batch. Five fixes in one ship.

## Fix 1 — Branding "Choose logo" button did nothing

`FrontendConfigurationView::renderConfigJs( true )` rendered an inline `<script>` that guarded the entire IIFE on `wp.media` being ready at script-execution time:

```js
if (typeof wp !== 'undefined' && wp.media) {
    var pickBtn = document.getElementById('tt-cfg-logo-pick');
    if (pickBtn) pickBtn.addEventListener('click', ...);
}
```

Inline `<script>` runs at parse time. `wp_enqueue_media()` registers media-views.js for footer enqueueing; on Dutch installs the page-load order had the inline script execute before the media script, so `wp.media` was undefined → entire block skipped → click listener never bound → button silently did nothing.

**Fix**: moved the readiness check inside the click handler. The button always responds; if `wp.media` isn't loaded at click time (would be unusual at this point in the lifecycle) we `console.warn` and return.

## Fix 2 — `?tt_view=my-activities` table styling didn't match other lists

`FrontendMyActivitiesView::renderTable()` rendered a custom `<table class="tt-table tt-table-sortable">`. Admin-side and most coach-side lists use `FrontendListTable::render()` which emits `<div class="tt-list-table-wrap"><table class="tt-list-table-table">…`. Different chrome — different fonts, padding, hover treatment, zebra striping.

**Fix**: switched the rendered classes to `tt-list-table-table` inside `tt-list-table-wrap`. Visual alignment without porting the data layer to REST. A full migration to `FrontendListTable::render()` requires a per-player scope on `/activities` REST + handling guest_player_id; tracked as a follow-up.

## Fix 3 — Activity display page visually unappealing

`FrontendMyActivitiesView::renderDetail()` was a flat `<dl class="tt-profile-dl">` with dt/dd pairs for date / team / opponent / location / attendance / notes. No card chrome, no badge pills, no visual grouping.

**Fix**: mirrored the goal-detail pattern. New structure:

```html
<article class="tt-activity-detail">
  <p class="tt-activity-detail-meta">
    <span class="tt-due">Date: 2026-04-12</span>
    <span class="tt-meta-chip">Team: <strong>U13-1</strong></span>
    <span class="tt-meta-chip">Opponent: <strong>SV Capelle</strong></span>
    <span class="tt-status-badge">Match</span>
    <span class="tt-status-badge tt-status-completed">Your attendance: Present</span>
  </p>
  <section class="tt-activity-detail-body">
    <h3>Notes from your coach</h3>
    <p>…</p>
  </section>
</article>
```

New CSS in `assets/css/public.css`: `.tt-activity-detail` (white card with border + radius), `.tt-activity-detail-meta` (flex row, divider underneath), `.tt-meta-chip` (subtle inline chip), `.tt-activity-detail-body`. Attendance status badge picks up the per-status colour override (Fix 5).

## Fix 4 — PDP conversation save/sign should land back on file

Two new public.js redirect modes added to the existing `data-redirect-after-save` ladder:

- `data-redirect-after-save="reload"` — `window.location.reload()` after the success toast. Used by the player-side reflection + ack forms on `?tt_view=my-pdp` where the form lives on the same page as the data it edits; reloading re-renders the page with the new state.
- `data-redirect-after-save-url="<URL>"` — explicit URL redirect. Used by the coach-side conversation form on `?tt_view=pdp&id=N&conv=M` to land on `?tt_view=pdp&id=N` (the parent file view).

Both wait for the success toast before navigating (1.2s) so the operator sees confirmation before the page changes.

## Fix 5 — PDP "signed" status should be green

Two layers of fix:

- **Inline ack/signoff notices**: replaced `style="color:#2c8a2c"` on `<p>` tags with semantic classes `tt-pdp-acked` (player + parent ack on `FrontendMyPdpView`) and `tt-pdp-signed-off` (coach signoff on `FrontendPdpManageView`). Both read `var(--tt-color-success, #16a34a)` so the Theme & fonts surface can re-theme via the existing success-color picker.
- **Status pill override**: the conversations-table status pill (`tt-status-badge.tt-status-completed`) was showing the default `--tt-primary` (brand dark green) for ALL statuses, making "signed off" indistinguishable from "scheduled" / "held". Added per-status background overrides:
  - `.tt-status-completed` / `.tt-status-signed_off` → `--tt-color-success` (success green)
  - `.tt-status-scheduled` → `--tt-color-info` (blue)
  - `.tt-status-in-progress` / `.tt-status-in_progress` → `--tt-color-warning` (amber)

# TalentTrack v3.92.4 — Eval wizard: Mark all present + non-admin save no longer silently 403'd

PR 2 of 3 from the operator's pilot batch. Three related fixes on the new-evaluation wizard.

## Fix 1 — "Mark all present" button on the Attendance step

Operator: clicking attendance individually for every player on every activity gets old quickly. The row default was already `present`, but coaches who had started clicking individual rows had no fast way to reset.

Added a "Mark all present" button above the attendance table. Pure JS toggle scoped to `[data-tt-mark-all-present]` — selects every `input[type=radio][name^="attendance["][value="present"]` and sets `checked=true`. No server-side change, no nonce; it's UI state that the form submit picks up via the existing radio names.

## Fix 2 — Single-player evaluation save: "one or multiple rows failed to save"

Operator clicked Submit on a single-player evaluation; got "one or multiple rows failed to save" and the wizard buttons stopped responding.

### Root cause

`Modules\Wizards\Evaluation\EvaluationRowRestController::register()` registered the per-row endpoint with:

```php
'permission_callback' => static fn(): bool => is_user_logged_in() && current_user_can( 'tt_create_evaluations' ),
```

`tt_create_evaluations` is **not granted to any TT role**. It's only used as a tile-visibility gate by `ActionCardWidget` (which renders the "+ New evaluation" CTA on the dashboard). Every save through the JS overlay 403'd for non-admin coaches. Admins (with `manage_options`) bypassed the cap check via the WP role-cap roll-up — which is why the bug never surfaced in admin testing.

The single-player observation is a clue, not the cause: 1-of-1 rows fail = "one or multiple rows failed" prompt fires. Multi-player saves had the same bug but the failure ratio (e.g. 5/5 rows fail) read as "all rows failed" which sounds different.

### Fix

Cap changed to `tt_edit_evaluations` — the canonical write cap, same one `EvaluationsRestController::create_eval` uses. Coaches with the matrix `evaluations:c[team]` grant or the legacy cap directly can now save through the JS overlay.

## Fix 3 — Buttons frozen after a failed save

Operator: after the failure prompt fired, the wizard buttons stopped responding. Couldn't retry, couldn't go back, couldn't cancel — only refresh worked.

### Root cause

`assets/js/wizard-eval-review.js`'s failure branch:

```js
if (failed > 0) {
    setStatus(payload.i18n_failed || 'One or more rows failed.');
    return;
}
```

It set the status text and returned without re-enabling the form buttons that `disableActions()` had disabled at the start of the save loop. The form was permanently frozen.

### Fix

Added `enableActions()` (mirror of `disableActions()`) and called it on the failure branch. Failure message also updated from "Refresh to retry" → "Try again or go back to fix the input." — the buttons are now actually reachable.

# TalentTrack v3.92.3 — Quick wins: activity picker dedup, goal-detail back-button, docs drawer, my-card print icon

Four small polish fixes sliced out of the operator's 10-item pilot batch as the fast cluster (PR 1 of 3). Larger eval-wizard + branding-page work ships next.

## Fix 1 — Activity picker showed the same activity twice

`Modules\Wizards\Evaluation\ActivityPickerStep::recentRateableActivities()` returned duplicate rows when a coach held multiple functional-role rows on one team. The IN-branch sub-SELECT (`SELECT tp.team_id FROM tt_team_people tp ...`) returns one row per FR-on-team, and on certain MySQL plans the optimiser materialises that as a join rather than a set membership — multiplying the outer activity row.

Added `GROUP BY a.id, a.title, a.session_date, a.activity_type_key, t.name` defensively. Doesn't change semantics; collapses any row-multiplication regardless of which OR branch fires.

## Fix 2 — Goal detail showed two "← Back to dashboard" buttons

`FrontendMyGoalsView::renderGoalDetail()` called `FrontendBackButton::render( $back_url )` explicitly and then `renderHeader()` which renders the back button again as its built-in fallback. Removed the explicit call. v3.92.2's breadcrumb sweep also touched this view (added a breadcrumb chain); the two changes compose cleanly — breadcrumb at top, no duplicate back button.

## Fix 3 — "How does this work?" docs link opens drawer instead of new tab

The goal detail page's docs link used `<a target="_blank">` to the docs surface — opens a new browser tab and loses the operator's place. Swapped for `HelpDrawer::button( 'conversational-goals', __( 'How does this work?', 'talenttrack' ) )`. The drawer DOM + `docs-drawer.js` shipped with #0016 Part B; this is just a single component swap. Right-side drawer animates in over the current page; close button preserves the operator's scroll position.

## Fix 4 — My-card print button: subtle icon top-right

`FrontendOverviewView::renderMyCard()` had a full-width "Print report" `tt-btn-secondary` button at the bottom of the side column. Repositioned to the card area with `position: absolute; top: 8px; right: 8px;`, replaced with a 32×32 print SVG icon, and given a hover-only background to keep visual weight low. `title` + `aria-label` carry "Print report" for accessibility. The print URL itself is unchanged (`?tt_print=N` opens the report in a new tab via `Stats\PrintRouter`).

## Renumbering

v3.92.2 → v3.92.3 in PR after the breadcrumb-sweep PR landed mid-CI.

# TalentTrack v3.92.2 — Breadcrumb sweep across detail / manage / form views

Operator on the pilot install: *"the breadcrumb trail implemented when visiting player page needs to be implemented everywhere, it is really nice and useful."* This release rolls the same component out to every detail / manage / setup view in the dashboard. Mechanical sweep across 25 view files.

## Why

The `FrontendBreadcrumbs` component shipped in #0077 (Spring 2026) and v3.92.0 added it to the player file. The operator confirmed it's a clear UX win and asked for it everywhere. Outside the player file, the dashboard relied on `FrontendBackButton::render()` — a single back link rather than a trail. On nested surfaces (Activity edit reached from the Activities list reached from the Dashboard) the user only saw a "← Back" button without the chain context.

## What changed

### Helper additions to `FrontendBreadcrumbs`

```php
public static function fromDashboard( string $current_label, ?array $intermediate = null ): void;
public static function viewCrumb( string $slug, string $label, array $extra_args = [] ): array;
```

`fromDashboard` constructs the Dashboard crumb (URL via `RecordLink::dashboardUrl()`), appends caller-supplied intermediate crumbs, then the un-linked current-page crumb — three-line one-liner per view. `viewCrumb` builds an intermediate `?tt_view=<slug>` crumb without inline URL construction. Together they keep caller code to one to four lines per view.

### Sweep target

Every view that previously called `FrontendBackButton::render()` near the top of `render()`:

- Manage / list views: 2-level chain, action / id state expanded — `Dashboard / Activities`, `Dashboard / Activities / New activity`, `Dashboard / Activities / Edit activity`, etc.
- Detail views: 3-level chain — `Dashboard / Players / [name]`, `Dashboard / Teams / [team name]`, `Dashboard / People / [person name]`, `Dashboard / My tasks / [task title]`.
- Sub-routes from a parent: `Dashboard / Application KPIs / Usage detail` (formerly the page rendered with no chain), `Dashboard / Configuration / Branding` (formerly the page rendered with `Branding` as the only header).
- Action-aware: `Dashboard / Functional roles / New assignment`, `Dashboard / Configuration / Theme & fonts`, `Dashboard / Goals / New goal`.
- Player-scoped sub-routes: `Dashboard / Players / [name] / Capture behaviour & potential`, `Dashboard / Players / [name] / Journey of [name]`, `Dashboard / People / [name] / Compose email to [name]`.

### Files touched (25)

- `src/Shared/Frontend/Components/FrontendBreadcrumbs.php` — new `fromDashboard` + `viewCrumb` helpers.
- `src/Shared/Frontend/FrontendActivitiesManageView.php` — action-aware chain (list / new / edit / detail).
- `src/Shared/Frontend/FrontendGoalsManageView.php` — same shape.
- `src/Shared/Frontend/FrontendMyActivitiesView.php` — list + detail chain.
- `src/Shared/Frontend/FrontendMyGoalsView.php` — list + detail chain.
- `src/Shared/Frontend/FrontendEvalCategoriesView.php` — list + new + edit.
- `src/Shared/Frontend/FrontendCustomFieldsView.php` — list + new + edit.
- `src/Shared/Frontend/FrontendConfigurationView.php` — `?config_sub=…` aware.
- `src/Shared/Frontend/FrontendRolesView.php` — single level.
- `src/Shared/Frontend/FrontendFunctionalRolesView.php` — tab + action aware.
- `src/Shared/Frontend/FrontendMigrationsView.php` — single level.
- `src/Shared/Frontend/FrontendUsageStatsView.php` — single level.
- `src/Shared/Frontend/FrontendUsageStatsDetailsView.php` — nested under Application KPIs.
- `src/Shared/Frontend/FrontendAuditLogView.php` — single level.
- `src/Shared/Frontend/FrontendMailComposeView.php` — nested under People → person.
- `src/Shared/Frontend/FrontendMySettingsView.php` — single level.
- `src/Shared/Frontend/FrontendPlayersCsvImportView.php` — nested under Players.
- `src/Shared/Frontend/FrontendPlayerStatusCaptureView.php` — nested under Players → player.
- `src/Shared/Frontend/FrontendReportsLauncherView.php` — single level.
- `src/Shared/Frontend/FrontendTeamDetailView.php` — replaces existing back button with breadcrumb.
- `src/Shared/Frontend/FrontendPersonDetailView.php` — replaces existing back button with breadcrumb.
- `src/Modules/Workflow/Frontend/FrontendTaskDetailView.php` — nested under My tasks.
- `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — list + new + detail.
- `src/Modules/Journey/Frontend/FrontendJourneyView.php` — branches on slug (my-journey vs player-journey).
- `src/Modules/Journey/Frontend/FrontendCohortTransitionsView.php` — single level.
- `src/Modules/CustomCss/Frontend/FrontendCustomCssView.php` — single level.

### What is *not* in this PR

- **Player file UX redesign.** Empty-state CTAs, hero card visual polish, "create your first ..." guidance. Tracked separately as #0082 (shaped concurrently with this release).
- **Idea-pipeline views** (`IdeasBoardView`, `IdeasRefineView`, `IdeaSubmitView`, `IdeasApprovalView`, `TracksView`) — left untouched. They're the operator's plugin-development backlog surface, not a customer-facing path; one back button is enough.
- **Methodology / Trial / Scout views** that already had bespoke navigation chrome — left untouched until the operator reports they want the breadcrumb there too.
- **Legacy `FrontendBackButton::render()` calls in error branches** ("no permission" / "not found" / "module disabled"). These are interruption notices, not navigation context; the back button is the right affordance there.

### Translations

No new translatable strings — every breadcrumb crumb reuses an existing tile / page msgid. The `Player file of %s` and `Idea pipeline` strings shipped in v3.92.0; nothing new is introduced here.

### Affected files

- 25 view files (above).
- `talenttrack.php` + `readme.txt` — version bump 3.92.1 → 3.92.2.
- `CHANGES.md` + `SEQUENCE.md` — release notes.
