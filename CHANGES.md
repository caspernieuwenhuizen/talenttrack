# TalentTrack v3.92.7 — My-activities full FrontendListTable migration + LabelTranslator::activityType helper

Two follow-ups deferred from the v3.92.5 pilot batch ship together. The previous release only borrowed the visual chrome of `FrontendListTable`; this one completes the migration so `?tt_view=my-activities` runs entirely through the shared component and the activity-type pill on the detail card finally renders translated labels.

## Why this is a single PR, not two

The full migration of `?tt_view=my-activities` requires a per-player REST scope on the activities endpoint, which means the `LabelTranslator::activityType()` helper has to land at the same time — the migrated detail view calls into it inline. Splitting them would have created a dead branch with one of the two surfaces still using the v3.92.5 placeholder. Both lived in the deferred bucket together; both ship together.

## Per-player REST scope (filter[player_id])

`ActivitiesRestController::list_sessions` now accepts `filter[player_id]=N`. When set, the WHERE clause adds:

```sql
EXISTS (SELECT 1 FROM {$p}tt_attendance a
          WHERE a.activity_id = s.id
            AND a.club_id     = s.club_id
            AND ( a.player_id = %d OR a.guest_player_id = %d ))
```

so the list only returns activities the player attended (covering both first-team players via `player_id` and ad-hoc guests via `guest_player_id`).

A correlated subquery on each returned row exposes `your_attendance_status` (the player's `Present` / `Absent` / `Late` / etc. value for that activity), feeding a new `your_attendance_pill_html` field and column. Subquery only runs when `filter[player_id]` is set, so admin / coach list-views aren't paying for it.

## Permission gate — player + parent self-read

The endpoint's `can_view` previously required the broader `tt_view_activities` capability — a coach / club-admin gate. Players don't hold that cap by default, but they should be able to see their own activity history.

The gate now accepts the request:

```php
public static function can_view( ?\WP_REST_Request $r = null ): bool {
    // Cap-based access still wins.
    if ( AuthorizationService::userCanOrMatrix( $uid, 'tt_view_activities' ) ) return true;
    if ( AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_activities' ) ) return true;

    // Player / parent self-read via filter[player_id]=<their player id>.
    $filter_pid = ...;
    if ( $filter_pid <= 0 ) return false;
    // Match tt_players.wp_user_id = $uid → grant.
    // Match tt_player_parents.parent_user_id = $uid AND .player_id = $filter_pid → grant.
}
```

So a player with no admin caps can fetch *only* their own attendance via the explicit `filter[player_id]` channel; without the filter, they're denied. Parents reach the same endpoint via the linked-player join.

## FrontendListTable: static_filters

New `static_filters` config key carries permanent server-side scope without rendering a user-editable filter row:

```php
FrontendListTable::render([
    'rest_path'      => 'activities',
    'static_filters' => [ 'player_id' => (int) $player->id ],
    'columns'        => [ ... ],
    ...
]);
```

The PHP side passes `static_filters` through to the JS hydrator config. The JS `refresh()` merges them into the request `filter` map (without overriding any user-set filter of the same key) before `fetchPage()`. Same code path as the user-editable filters; no separate plumbing.

## LabelTranslator::activityType()

Covers the seeded activity-type lookup keys plus the migration-0046 game subtypes:

```php
LabelTranslator::activityType( 'training' );    // "Training"
LabelTranslator::activityType( 'match' );       // "Match"
LabelTranslator::activityType( 'team_meeting' );// "Team meeting"
LabelTranslator::activityType( 'friendly' );    // "Friendly match"
LabelTranslator::activityType( 'tournament' );  // "Tournament"
LabelTranslator::activityType( 'custom_xyz' );  // null
```

Null on unknown keys lets callers fall back to the row's typed `label` for custom types a club added post-seed.

The activity-type pill on the list still goes through `LookupPill::render('activity_type', $key)` which uses the runtime translation layer (and an editable `tt_lookups.label`). The helper is for surfaces that render the type label inline — currently the activity-detail card meta row; future surfaces are the journey-event summaries and cohort-transition timeline.

## Wired in renderDetail

`FrontendMyActivitiesView::renderDetail` previously fell back to `ucfirst(str_replace('_', ' ', $type_key))` for the type pill — a placeholder shipped with v3.92.5 because the helper didn't exist yet. Now:

```php
$type_label = LabelTranslator::activityType( $type_key );
if ( $type_label === null ) {
    $type_label = ucfirst( str_replace( '_', ' ', $type_key ) );
}
```

The fallback stays for stale demo / custom-type rows; the seeded keys read in Dutch on Dutch installs.

## What's *not* in this PR

- Other player-scope-style migrations (e.g. `?tt_view=my-goals` is already a list, but the goal-detail / evaluation-detail "my" surfaces still render via custom queries — same migration recipe applies if the operator asks).
- Backfilling `LookupPill::render` callers to use the helper directly — the pill component is fine as-is for editable lookup rows; the helper is for cases where the pill component is overkill.
- `LabelTranslator::activityType` for journey-event summaries — those still concatenate the raw key. Follow-up if the operator notices on Dutch installs.

## Translations

11 new translatable strings — `Your status`, `Search title, location, team…`, `No activities recorded for you yet.`, plus the seeded activity-type labels (`Match`, `Clinic`, `Team meeting`, `Friendly match`, `Cup match`, `League match`, `Tournament`). `Training` and `Methodology` already had `nl_NL` entries from earlier ships and are reused. All filled in `nl_NL.po`.

## Affected files

- `src/Infrastructure/REST/ActivitiesRestController.php` — `filter[player_id]` clause, per-row `your_attendance_status` subquery, player/parent self-read in `can_view`, `your_attendance_status` + `your_attendance_pill_html` in `format_row`.
- `src/Shared/Frontend/Components/FrontendListTable.php` — `static_filters` config support + `sanitizeStaticFilters`.
- `assets/js/components/frontend-list-table.js` — merge `config.static_filters` into request `filter` before fetch.
- `src/Infrastructure/Query/LabelTranslator.php` — new `activityType()` helper.
- `src/Shared/Frontend/FrontendMyActivitiesView.php` — full `FrontendListTable::render()` migration in `render()`; `LabelTranslator::activityType()` in `renderDetail()`. Removed orphaned `filtersFromQuery` / `renderFilters` / `renderTable` / `parseDate` helpers.
- `languages/talenttrack-nl_NL.po` — 11 new NL strings.
- `readme.txt`, `talenttrack.php`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

---

# TalentTrack v3.92.6 — Player file UX redesign: hero card + empty-state CTAs + tab count badges (#0082)

Pilot operator on the player file: *"need to make this much more visually appealing and we want clear CTAs to set up the player files (e.g. when no goals exist, no PDP exists, no evaluations exist or trials)."* Three threads bundled into one ship. Renumbered v3.92.4 → v3.92.6 after pilot-batch PR 2 (v3.92.4 — eval wizard fixes) and PR 3 (v3.92.5 — branding logo + activity detail polish + PDP signed-status green) landed mid-CI.

## Hero card

The hero strip on the player file used to be a 96px round photo plus a one-line muted team caption — about 104px tall, no signal of where the player is in their journey, no shortcut to recent activity. Replaced with a structured information block:

- Photo (or initials placeholder when no photo is uploaded — same dimensions, same border, no layout collapse).
- Team + age group (clickable team link).
- Status pill (`LookupPill::render('player_status', …)` so the visual register matches the rest of the dashboard).
- Age-tier badge (rounded pill, muted).
- Days-in-academy + joined-on date — read from `tt_players.date_joined`, falls back to `created_at`. When the join date is null and `created_at` < 7 days the journey block reads "Joined recently" instead of a misleadingly small day count.
- Up to three "latest record" chips: latest activity (with title + link to its detail row), latest evaluation (with date + link), latest goal (with title + link). Each chip is dropped when the corresponding record doesn't exist; the entire latest-row hides when all three are empty.

CSS Grid reflow: stacks photo above body block at 360px, sits side-by-side at ≥ 480px. Mobile-first authored. ≥ 48px touch targets throughout.

## Empty-state CTAs

Every non-Profile tab used to render a one-line italic *"No goals recorded yet."* / *"No evaluations recorded yet."* / etc. when the tab was empty. A coach landing on a fresh player file hit five empty tabs in a row with no path forward. New reusable `EmptyStateCard` component in `src/Shared/Frontend/Components/`: icon + headline + explainer + permission-aware CTA. The component decides on render whether to surface the CTA based on `current_user_can( $cta_cap )`. When the user lacks the cap, the CTA button is omitted but the headline + explainer still render.

CTA URLs per tab:

- Goals → `?tt_view=goals&action=new&player_id=N`
- Evaluations → `?tt_view=evaluations&action=new&player_id=N`
- Activities → `?tt_view=activities&action=new&team_id=<player's team>` (CTA suppressed and explainer changed to *"Assign this player to a team first"* when the player has no team)
- PDP → `?tt_view=pdp&action=new&player_id=N`
- Trials → `?tt_view=trials&action=new&player_id=N`

Visual: light-grey rounded background, 1px dashed border, 40px stroke-only icon, 16px headline, 14px explainer (max 44ch), full-width primary CTA button on mobile that becomes inline-sized at ≥ 480px. `role="status"` on the wrapper so screen readers announce the empty state as a status message.

The component is general-purpose — its first consumer is the player file but it lives next to `FrontendBreadcrumbs` / `FrontendListTable` / etc. Future use cases: every list view in the dashboard has the same shape; sweep follows when the operator asks.

## Tab count badges

New helper `Infrastructure\Query\PlayerFileCounts::for( int $player_id ): array` makes one count query per tab type (5 queries) and returns an `[ 'goals' => N, ... ]` map. The view calls it once and feeds each tab's badge state. When count is zero the badge isn't rendered and the tab gets a `tt-player-tab--empty` modifier (muted colour, still clickable). Active-tab badge inverts (filled in primary colour on white text). Operator scanning a freshly-imported player file can see Profile / Goals (12) / Evaluations (4) / Activities (38) / PDP (0, muted) / Trials (0, muted) at a glance — without clicking five times.

## Profile tab — two-column layout

The flat `<dl>` on the Profile tab restructured into Identity (DOB / position / foot / jersey / status) on the left, Academy (team + age group, age tier, date joined) on the right. Two-column at ≥ 768px, single column below. Behaviour-and-potential capture button stays at the bottom of the tab.

## Removed inline `<style>`

The legacy inline `<style>` block at the bottom of `FrontendPlayerDetailView::render()` is gone. All styles now live in `assets/css/frontend-player-detail.css`, enqueued at view level via a new `enqueueDetailCss()` helper. Mobile-first authored.

## What's *not* in this PR

- Empty-state sweep across other dashboard list views (Players list, Teams list, etc.). Component lands here first.
- Avatar upload from the hero, per-persona hero variants, skeleton loaders.

## Translations

19 new translatable strings — empty-state headlines + explainers + CTA labels across the five tabs, the journey-line phrasing (`%d day(s) in academy` plural, `Joined %s`, `Joined recently`), the three "Latest …" chip labels, and the new Identity / Academy / Date joined profile labels. All filled in `nl_NL.po`.

## Affected files

- `src/Shared/Frontend/FrontendPlayerDetailView.php` — refactored.
- `src/Infrastructure/Query/PlayerFileCounts.php` — new.
- `src/Shared/Frontend/Components/EmptyStateCard.php` — new.
- `assets/css/frontend-player-detail.css` — new.
- `languages/talenttrack-nl_NL.po` — 19 new msgids.
- `docs/teams-players.md` + `docs/nl_NL/teams-players.md` — new "Player file UX (v3.92.6)" section.
- `talenttrack.php` + `readme.txt` — version bump 3.92.5 → 3.92.6.
- `CHANGES.md` + `SEQUENCE.md` — release notes.

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
