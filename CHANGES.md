# TalentTrack v3.110.184 — Team Blueprint editor enhancements + Team Chemistry "Save as blueprint" flavour picker

## Pilot asks

Chat 2026-05-20:

> The blueprint functionality should have the option to click a player in the starting 11 and switch that player with another player. Similar to the team chemistry functionality. Also, I should be able to add a player as second best option and even a third. Also, a toggle to hide the chemistry information should be available. It should also have a save and save as option.

Plus:

> when clicking save in team chemistry I should be able to select what type of blueprint it should save; it now auto saves as match day.

Picker layout — pilot explicitly said *"show all"*: three tier sections visible at once, no segmented control.

## Blueprint editor changes

### 1. Tap-to-swap picker (three-tier "show all")

Tap any pitch slot → bottom-sheet with three stacked sections (Primary / Secondary / Tertiary). Each section shows its current assignment + full roster + Clear-this-tier button. Tap roster row → assigns. Saves via `PUT /blueprints/{id}/assignment` and reloads. Drag-and-drop stays as a power-user alternative.

### 2. Hide-chemistry toggle

Button above the pitch toggles a `tt-bp-chem-hidden` body class. CSS hides the chemistry headline card + every `.tt-chem-link` SVG line. State persists in `sessionStorage` keyed by blueprint id.

### 3. Save + Save As

- **Save** → returns to the team's blueprints list with `?tt_saved=1` (auto-save was already on; this is "done editing" navigation).
- **Save As…** → prompts for a new name → `POST /blueprints/{id}/clone` → redirects into the clone's editor.

## Backend

- **`TeamBlueprintsRepository::cloneBlueprint( int $source_id, string $new_name, int $created_by ): int`** — duplicates blueprint row + every assignment row to a fresh draft.
- **`POST /talenttrack/v1/blueprints/{id}/clone`** — new REST route, body `{ "name": "..." }`, cap-gated on `can_manage`, returns `{ "id": <new_id> }`.

## Team Chemistry "Save as blueprint" picks flavour

Was: two sequential dialogs + hardcoded `flavour: 'match_day'`. Now: single modal asks **both** blueprint name AND flavour (radio: *Match-day lineup* / *Squad plan — 3 tiers per slot*). Chemistry sandbox now unblocks squad-plan creation directly — no wizard detour.

## Files touched

- `src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php`
- `src/Modules/TeamDevelopment/Repositories/TeamBlueprintsRepository.php`
- `src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php`
- `src/Modules/TeamDevelopment/Frontend/FrontendTeamChemistryView.php`
- `assets/js/frontend-team-blueprint.js`
- `assets/css/frontend-team-blueprint.css`
- `assets/js/frontend-team-chemistry.js`
- `assets/css/frontend-team-chemistry.css`
- `talenttrack.php` + `readme.txt` + `CHANGES.md`

## No schema / REST shape break / auth change

- `tt_team_blueprint_assignments.tier` already supports primary / secondary / tertiary (this ship surfaces it on match-day blueprints, which previously showed only primary).
- `PUT /blueprints/{id}/assignment` already takes `tier` — picker just calls it.
- `POST /blueprints/{id}/clone` is additive.
- All write paths gate on `tt_manage_team_chemistry`.

## Test plan

- [ ] Tap a pitch slot → picker opens; assign players to all three tiers
- [ ] *Clear this tier* removes that assignment
- [ ] Drag-drop still works (regression)
- [ ] "Hide chemistry" toggles chemistry headline + link lines; sessionStorage persists across reload
- [ ] "Save" returns to blueprints list with `?tt_saved=1`
- [ ] "Save as…" prompts → clone exists in list, opens with same assignments
- [ ] On Team Chemistry board: "Save as blueprint" → flavour radio + name input → both honoured

---

# TalentTrack v3.110.183 — Persona dashboard demo-mode filter — 14 surfaces now apply `apply_demo_scope` (closes #781)

## Why

Follow-up to v3.110.179's evaluations-list fix (#779). The pilot reported the evaluations list rendered empty while `MyTeamAvgRating` (a dashboard widget) aggregated over the same evaluations and showed a value. v3.110.179 fixed the list query (a non-existent column reference). The audit it prompted revealed a second, structural problem: most PersonaDashboard surfaces never call `QueryHelpers::apply_demo_scope`, so they bypass the install's demo-mode filter entirely.

When demo mode is ON the list page filters to `id IN (SELECT entity_id FROM tt_demo_tags ...)` while a leaking widget returns everything. When demo mode is OFF the asymmetry reverses — list excludes tagged rows, widget includes them.

## What

Fourteen PersonaDashboard files now call `apply_demo_scope` against the relevant entity type. Pattern in each: add `$scope = QueryHelpers::apply_demo_scope( '<alias>', '<entity>' );` near the query, concatenate `{$scope}` into the WHERE (or into the LEFT-JOIN ON clause when null-row preservation matters).

**Evaluations (5 files):**

- `src/Modules/PersonaDashboard/Kpis/NewEvaluationsThisWeek.php`
- `src/Modules/PersonaDashboard/Kpis/MyEvaluationsThisWeek.php`
- `src/Modules/PersonaDashboard/Kpis/EvaluationsThisMonth.php`
- `src/Modules/PersonaDashboard/Kpis/MyTeamAvgRating.php` — the widget that prompted #779
- `src/Modules/PersonaDashboard/Widgets/ChildSwitcherWithRecapWidget.php`

**Goals (4 files):**

- `src/Modules/PersonaDashboard/Kpis/GoalsByPrincipleKpi.php`
- `src/Modules/PersonaDashboard/Kpis/GoalCompletionPct.php`
- `src/Modules/PersonaDashboard/TableSources/GoalsByPrincipleSource.php` — scope placed in the LEFT-JOIN ON clause so principles with zero matching goals still surface
- `src/Modules/PersonaDashboard/Widgets/RecentCommentsWidget.php`

**Activities (5 files):**

- `src/Modules/PersonaDashboard/Kpis/AttendancePctRolling.php`
- `src/Modules/PersonaDashboard/Kpis/MyTeamAttendancePct.php`
- `src/Modules/PersonaDashboard/Widgets/TodayUpNextHeroWidget.php`
- `src/Modules/PersonaDashboard/Widgets/TeamRosterTableWidget.php` — scopes the attendance JOIN's activity table
- `src/Modules/PersonaDashboard/TableSources/UpcomingActivitiesSource.php`
- `src/Modules/PersonaDashboard/Repositories/UpcomingActivityRepository.php` — consumed by `MarkAttendanceHeroWidget`

## What stays unchanged

- Schema unchanged. Zero migrations.
- Behaviour on installs that have **no `tt_demo_tags` rows** is identical: `apply_demo_scope` returns an empty string when demo mode is "neutral" or the tags table is empty, so the new `{$scope}` interpolations collapse to nothing.
- Coach-team scoping, capability gating, club-id filters — all unchanged. Demo-scope is a separate axis layered on top.

## Out of scope

- `tt_trials`, `tt_scouting_visits`, `tt_prospects`, `tt_workflow_*` — not in `DemoMode::tagIfActive`'s recognised entity types. If they ever need demo-scoping, the recognition list needs to grow first (separate design call).
- Already-correct surfaces (`MiniPlayerListWidget`, `ActivePlayersTotal`) — untouched.

## Test plan

- [ ] Pilot install with demo mode ON: every KPI / widget / table source on the dashboard reads from the same demo-tagged subset the list pages display.
- [ ] `MyTeamAvgRating` no longer aggregates over untagged real data.
- [ ] `UpcomingActivityRepository::nextForCoach` does not surface untagged activities.
- [ ] On a demo-OFF install with zero `tt_demo_tags` rows, every widget renders unchanged.
- [ ] `GoalsByPrincipleSource` still surfaces principles with zero matching goals (LEFT-JOIN null preservation maintained — scope is in the ON clause, not the WHERE).

---

# TalentTrack v3.110.182 — Wizard post-submit redirect 404 round 3: `currentDashboardUrl()` helper used by every wizard `submit()` handler (#782 follow-up)

## Pilot report

Chat 2026-05-20, after v3.110.180:

> The new blueprint wizard still leads to a 404. Not sure why

## Why v3.110.180 wasn't enough

v3.110.180 fixed `FrontendWizardView::wizardStepUrl()` — the helper that builds the **step-to-step transition** URL. But it didn't address the wizard's own `submit()` handler, which builds its own `redirect_url` via `WizardEntryPoint::dashboardBaseUrl()` — the same brittle resolution chain v3.110.172 (#766) tried to bypass:

```php
// In NewTeamBlueprintWizard's ReviewStep::submit():
return [ 'redirect_url' => add_query_arg(
    [ 'tt_view' => 'team-blueprints', 'id' => $id ],
    WizardEntryPoint::dashboardBaseUrl()    // ← still vulnerable
) ];
```

So step 1 → step 2 worked after v3.110.180. But step 2's Create button → `submit()` → built a redirect URL via the brittle chain → 404 on the pilot install.

## Same bug class, third repeat

| Ship | What was fixed | What was missed |
| --- | --- | --- |
| v3.110.172 | `transitionOrSubmit()` redirect target | The `esc_url_raw` approach turned out to be brittle |
| v3.110.180 | `wizardStepUrl()` rewritten to `home_url(REQUEST_URI path)` | Wizard `submit()` handlers still used `dashboardBaseUrl()` directly |
| **v3.110.182** | All wizard `submit()` handlers use new `currentDashboardUrl()` helper | — |

## The fix

New helper `WizardEntryPoint::currentDashboardUrl()`:

```php
public static function currentDashboardUrl(): string {
    $path = '/';
    if ( isset( $_SERVER['REQUEST_URI'] ) ) {
        $raw   = wp_unslash( (string) $_SERVER['REQUEST_URI'] );
        $q_pos = strpos( $raw, '?' );
        $path  = $q_pos === false ? $raw : substr( $raw, 0, $q_pos );
        if ( $path === '' ) $path = '/';
    }
    return home_url( $path );
}
```

Same robust pattern as v3.110.180's `wizardStepUrl()`. The wizard is being rendered from a page that by definition hosts the dashboard shortcode (the user just submitted a form on that page), so `home_url(REQUEST_URI path)` is guaranteed to land on a routable URL — `wp_safe_redirect`'s host whitelist passes by construction.

## Files touched

Nine wizard call sites updated across seven wizards:

- `src/Modules/Wizards/TeamBlueprint/ReviewStep.php`
- `src/Modules/Tournaments/Wizard/ReviewStep.php`
- `src/Modules/Wizards/Goal/DetailsStep.php`
- `src/Modules/Wizards/Evaluation/ReviewStep.php` (3 occurrences)
- `src/Modules/Wizards/Activity/ReviewStep.php` (2 occurrences)
- `src/Modules/Wizards/Activity/NewActivityWizard.php`
- `src/Modules/Wizards/MarkAttendance/RateConfirmStep.php` (2 occurrences)
- `src/Modules/Wizards/Team/ReviewStep.php`
- `src/Modules/Wizards/Player/ReviewStep.php` (2 occurrences)

Plus the post-submit fallback when a wizard returns no `redirect_url`:

- `src/Shared/Frontend/FrontendWizardView.php` (line 419)

New helper:

- `src/Shared/Wizards/WizardEntryPoint.php` — `currentDashboardUrl()` method added

## Why not change `dashboardBaseUrl()` itself

It has legitimate non-web-request callers (REST controllers building URLs for email links, admin pages building redirect targets, scheduled-job runners) where `$_SERVER['REQUEST_URI']` either doesn't exist or doesn't point at a dashboard-hosting page. Two helpers, clearly separated:

- `dashboardBaseUrl()` — outside-the-request URL building, uses the config + discovery chain
- `currentDashboardUrl()` — inside-the-request redirects, uses REQUEST_URI path

## Test plan

- [ ] Click "+ New blueprint" → fill Setup → Next → Review → Create → lands on editor at `?tt_view=team-blueprints&id=N`, NOT 404
- [ ] Click "+ New tournament" → walk all 5 steps → Create → lands on tournament detail
- [ ] Click "+ New activity" → walk steps → Create → lands on activities list
- [ ] Other wizards (new-player, new-team, new-goal, new-evaluation, mark-attendance) — submit redirects work
- [ ] Wizard Cancel + Save-as-draft branches unchanged (still use `dashboardBaseUrl()`); no regression there

---

# TalentTrack v3.110.180 — Wizard step-to-step redirect made robust (closes #782)

## Pilot report

Chat 2026-05-20:

> when using the new tournament wizard I get a page not found after clicking next after entering name and dates

After I pointed at the v3.110.172 fix (#766) that previously addressed the same symptom for the team-blueprint wizard:

> which is actually also not fixed still

Both wizards still 404'ing on step-1 → step-2 on the pilot's Strato install.

## Why v3.110.172 wasn't enough

`wizardStepUrl()` used `esc_url_raw( REQUEST_URI )` + `remove_query_arg`, returning a relative URL. Two latent issues on the pilot's setup:

1. **`esc_url_raw` may mangle REQUEST_URI** under proxy / SSL-termination / unusual server configurations.
2. **Relative URLs can fail `wp_safe_redirect`** under certain configurations — `wp_validate_redirect`'s host whitelist silently bounces them to `admin_url()`, which reads as "page not found" for users without wp-admin access.

## Fix

Three changes:

1. **Drop `esc_url_raw`** — extract REQUEST_URI's path with `strpos`/`substr` on `?`. Unambiguous.
2. **Wrap via `home_url($path)`** — fully-qualified URL on the site's canonical host + scheme; `wp_safe_redirect`'s whitelist passes by construction.
3. **Drop `dashboardBaseUrl()` fallback on happy path** — the form just POSTed, so REQUEST_URI is by definition a path that routes.

```php
private static function wizardStepUrl( string $slug ): string {
    $path = '/';
    if ( isset( $_SERVER['REQUEST_URI'] ) ) {
        $raw   = wp_unslash( (string) $_SERVER['REQUEST_URI'] );
        $q_pos = strpos( $raw, '?' );
        $path  = $q_pos === false ? $raw : substr( $raw, 0, $q_pos );
        if ( $path === '' ) $path = '/';
    }
    return add_query_arg(
        [ 'tt_view' => 'wizard', 'slug' => $slug ],
        home_url( $path )
    );
}
```

## Why this should finally close it

The redirect URL is guaranteed to be on the site's canonical host (from `home_url`), on the path the user just POSTed to (from REQUEST_URI), with the wizard's routing args. All three by construction.

## Same fix applies to the Back button

`wizardStepUrl()` is shared between `transitionOrSubmit()` (Next) and the Back-button branch. Both routes now equally robust.

## Files touched

- `src/Shared/Frontend/FrontendWizardView.php` — `wizardStepUrl()` rewritten.
- `talenttrack.php` — 3.110.179 → 3.110.180.
- `readme.txt` — Stable tag + changelog entry.
- `CHANGES.md` — this file.

No DB migration, no REST shape change, no view-class change, no new i18n strings, no auth change.

## Test plan

- [ ] Click "+ New tournament" → fill BasicsStep → Next → lands on FormationStep, NOT 404
- [ ] Click "+ New blueprint" → step-1 → step-2 works (regression check for #766)
- [ ] Other wizards (mark-attendance, new-evaluation, new-player, new-team, new-goal) — step transitions work
- [ ] Back button in any multi-step wizard — no 404 on Back either

---

# TalentTrack v3.110.179 — Evaluations list empty while data exists — drop bad `tt_lookups.label` column reference from three SELECTs (closes #779)

## Symptom

Pilot 2026-05-20, admin user on a demo-mode install: the evaluations list page (`?tt_view=evaluations`) renders an empty table while the `MyTeamAvgRating` dashboard widget aggregates over the same evaluations and shows a value. Twelve evaluations in the database, zero on the page.

## Diagnosis

The REST endpoint `/wp-json/talenttrack/v1/evaluations` returns:

```json
{ "success": true, "data": { "rows": [], "total": 12, "page": 1, "per_page": 25 }, "errors": [] }
```

`total = 12` and `rows = []` can only happen when the **list SELECT errors silently** while the **COUNT** query succeeds — `$wpdb->get_results()` returns `[]` on SQL error.

Running the controller's list SELECT against the pilot DB:

> `#1054 - Unknown column 'et.label' in 'SELECT'`

The COUNT query doesn't join `tt_lookups`, which is why `total = 12` survives. The list SELECT joins `tt_lookups et ON et.id = e.eval_type_id` and references `et.label`. That column does not exist on `tt_lookups`.

Original schema (migration 0001) defines: `id`, `lookup_type`, `name`, `description`, `meta`, `sort_order`, `created_at`, `updated_at`. No `label` column. No migration anywhere adds one.

## Root cause

Three files reference the non-existent `tt_lookups.label` column. All introduced in v3.110.107 / .104 / .78 and silently breaking ever since:

- [`src/Infrastructure/REST/EvaluationsRestController.php:180`](src/Infrastructure/REST/EvaluationsRestController.php#L180) — `et.label AS eval_type_label` — breaks the evaluations list page.
- [`src/Shared/Frontend/FrontendEvaluationsView.php:217`](src/Shared/Frontend/FrontendEvaluationsView.php#L217) — same — breaks the evaluation detail page's Type row.
- [`src/Modules/PersonaDashboard/Repositories/UpcomingActivityRepository.php:111`](src/Modules/PersonaDashboard/Repositories/UpcomingActivityRepository.php#L111) — `SELECT name, label, meta` — breaks the `MarkAttendanceHeroWidget`'s activity-type label.

`LookupTranslator::name()` already accepts a lookup `id` and resolves the localised label via the `tt_translations` table (the post-v3.110.30 source of truth, after migration 0087 dropped the legacy `tt_lookups.translations` JSON column). It does not need `label`.

## Fix

Each of the three SELECTs drops `label` and adds `id`. Each consumer passes `id` into the lookup object handed to `LookupTranslator::name()`, which:

1. Reads `id` to look up a translation row in `tt_translations` for the current locale.
2. Falls back to `__($name, 'talenttrack')` when no translation row exists.

Zero schema change. Zero behaviour change for `LookupTranslator` callers. The displayed label flow is identical to what was intended — it just no longer routes through a column that doesn't exist.

## Why not add `label` to `tt_lookups`

v3.110.22 / v3.110.30 explicitly moved lookup-row translation data out of `tt_lookups` and into the dedicated `tt_translations` table. Migration 0087 dropped the legacy `tt_lookups.translations` JSON column. Reintroducing a `label` column to `tt_lookups` would contradict that design and re-fragment the localisation storage. Fixing the three callers is the change that aligns with the codebase's current localisation strategy.

## Out of scope (filed separately)

A related audit surfaced 29 PersonaDashboard widgets / KPIs / repositories that query entity tables (evaluations, goals, activities, players, teams, people) but don't apply `apply_demo_scope`. The widget that prompted #779 (`MyTeamAvgRating`) is one of them — it leaks un-tagged rows past the demo filter the list page applies. That's a different bug (visibility model, not schema mismatch) and will land in a follow-up.

## Files

- `src/Infrastructure/REST/EvaluationsRestController.php` — drop `et.label`, add `et.id AS eval_type_lookup_id`, pass id into translator object
- `src/Shared/Frontend/FrontendEvaluationsView.php` — same pattern in `renderDetail()`'s eval-type Type row
- `src/Modules/PersonaDashboard/Repositories/UpcomingActivityRepository.php` — same pattern in `activityTypeLabel()`'s lookup query
- `talenttrack.php` + `readme.txt` — version bump

---

# TalentTrack v3.110.177 — "My team attendance %" KPI excludes planned / cancelled activities from both compute + deep-link (closes #775)

## Pilot follow-up

After v3.110.175 (`#771` — KPI deep-links honour their compute window):

> but does it also take into account other scope context; for example only completed activities because planned and cancelled activties do not count towards the number?

Sharp catch. The v3.110.175 fix made the deep-link honour the 28-day window but left activity-state scope unaddressed.

## What was still wrong

- `MyTeamAttendancePct::compute()` counts rows in `tt_attendance`. Planned activities (no attendance rows yet) didn't contribute in practice, but **cancelled-after-attendance-marked** activities could still slip into the denominator.
- `MyTeamAttendancePct::linkUrl()` from v3.110.175 added `filter[date_from]` + `filter[date_to]` but no `plan_state` filter, so the destination activities list showed **every** activity in the 28-day window — including planned, draft, scheduled, and cancelled — even though they don't contribute to the KPI.

The number on the card and the row count of the destination list could disagree, and the pilot's coach mental model ("only activities that actually happened count") wasn't reflected in either place.

## Fix — symmetric on both sides

New constant in `MyTeamAttendancePct.php`:

```php
private const ACTIVITY_STATES_COUNTING = [ 'completed', 'in_progress' ];
```

### `compute()` adds the state predicate

The SQL now also constrains `act.plan_state IN ('completed', 'in_progress')`. Any attendance row attached to a `planned` / `draft` / `scheduled` / `cancelled` activity is excluded from the KPI's denominator. Covers the cancelled-after-attendance-marked edge case.

### `linkUrl()` adds the same filter to the destination

The activities REST endpoint already parses comma-separated values on `filter[plan_state]` via its `$allowed = [ 'draft', 'scheduled', 'in_progress', 'completed', 'cancelled' ]` whitelist. No REST shape change needed.

## Why this is architecturally correct

Both `compute()` and `linkUrl()` consume the SAME `ACTIVITY_STATES_COUNTING` constant — drift between the KPI's number and the filtered list is structurally impossible. Same single-source-of-truth principle as:

- v3.110.173 — bool-returning dispatchers (switch case IS the registration; no second allowlist to drift)
- v3.110.175 — `WINDOW_DAYS` constant (shared between compute and linkUrl)

Now there are two shared constants in `MyTeamAttendancePct`:

- `WINDOW_DAYS = 28` — the rolling window
- `ACTIVITY_STATES_COUNTING = [ 'completed', 'in_progress' ]` — the scope

Both used by both methods. The KPI's universe and the destination filter's universe are guaranteed to match.

## Why `completed` AND `in_progress` (not just `completed`)

The pilot said "only completed", but `in_progress` is a meaningful subset of completed-in-spirit:

- `in_progress` activities have attendance rows being marked DURING the session itself. Those rows reflect real presence calls. Excluding them under-counts the recent attendance signal during a session that just happened.
- `completed` activities are the typical case: session over, attendance finalised.

Both excluded by the new gate: `draft`, `scheduled`, `planned` (pre-attendance), `cancelled` (post-decision to not run).

## What's NOT affected

- `MyTeamAvgRating` — evaluations have no `plan_state` concept. The existing `e.archived_at IS NULL` filter is already the equivalent gate.
- Academy-wide KPIs (`AttendancePctRolling`, etc.) — out of scope for this fix.

## Files touched

- `src/Modules/PersonaDashboard/Kpis/MyTeamAttendancePct.php` — new constant, compute SQL adds `AND plan_state IN (…)`, linkUrl adds `filter[plan_state]=…`.
- `talenttrack.php` — 3.110.176 → 3.110.177.
- `readme.txt` — Stable tag + changelog entry.
- `CHANGES.md` — this file.

No DB migration, no REST shape change, no new i18n strings, no auth change, no UI change.

## Test plan

- [ ] On the coach dashboard, "My team attendance %" KPI displays a percentage. Note it.
- [ ] Click the KPI → activities list opens with `date_from`, `date_to`, AND `plan_state` filters all pre-set.
- [ ] List shows ONLY activities with `plan_state` in `completed` or `in_progress`. Zero planned, draft, scheduled, or cancelled rows visible.
- [ ] Mentally compute attendance from visible rows → matches the KPI percentage.
- [ ] Edge case: if any cancelled activity in the 28-day window had attendance rows on it, the KPI percentage decreased after this ship (those rows are no longer in the denominator).
- [ ] Regression check: "My team avg rating" still works as before (no plan_state filter applied).
- [ ] Regression check: clicking other KPIs still routes correctly with their own filters.
