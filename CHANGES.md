# TalentTrack v3.0.0 — Capability refactor + Migration UX + Frontend rebuild

**Status: IN PROGRESS (slice 3 of 5 shipped).** Full v3.0.0 ships when all 5 slices land. See "Roadmap" at the end.

## Summary of v3.0.0 as a whole

A major-version release that rebuilds three fundamentals:

1. **Migration UX** — admin-triggered migrations via a button instead of deactivate/reactivate, with automatic version tracking. *(Complete in slice 1.)*
2. **Capability refactor** — every `tt_manage_*` / `tt_evaluate_*` cap split into `tt_view_*` + `tt_edit_*` pairs. The Read-Only Observer role becomes meaningful across the entire plugin. *(Scaffolding in slice 1, call-site audit in slice 2 — now complete.)*
3. **Frontend fully rebuilt** — the tile grid from v2.21 now has real destinations. Every tile maps to a dedicated focused view. No more tab navigation. *(Slices 3-5.)*

## Slice 3 (this snapshot) — Me-group frontend views

### What changed

The v2.21 tile landing page promised destinations that didn't exist — tapping "My goals" or "My evaluations" dropped you into a tab-heavy dashboard that ignored your tile choice. Slice 3 gives the Me-group tiles real destinations:

- **My card** → `FrontendOverviewView` (FIFA card + custom fields + recent-history radar + print button)
- **My team** → `FrontendMyTeamView` (own card + team podium + teammate roster, names-only)
- **My evaluations** → `FrontendMyEvaluationsView` (table of evaluations, most-recent first, with rating pills and match context)
- **My sessions** → `FrontendMySessionsView` (attendance log, color-coded by status)
- **My goals** → `FrontendMyGoalsView` (goal cards with status badges and due dates)
- **My profile** → `FrontendMyProfileView` — **new view**, didn't exist in v2.21. Read-friendly personal details with a link to edit WordPress account settings

All six views extend a new shared `FrontendViewBase` which handles:
- Idempotent asset enqueueing (player-card CSS + frontend-mobile CSS)
- Consistent page header with back button + title
- No tab bars — each view is one focused page

### Tile-slug routing

`DashboardShortcode::render()` now dispatches Me-group slugs (`overview`, `my-team`, `evaluations`, `sessions`, `goals`, `profile`) to the focused view classes via a new `dispatchMeView()` helper. The dispatch only fires for users with a linked player record (player-context routing). Coaches who are also players get the Me views when they hit a Me slug; when they hit a Coaching slug (same `evaluations` / `sessions` / `goals` words but with coach context) they still fall through to the legacy CoachDashboardView (slice 4 fixes that).

### Legacy PlayerDashboardView still present

Kept in place for now. When slice 4 removes the similar CoachDashboardView fallback, the Me-dispatch table above handles all player routes exclusively. `PlayerDashboardView` class will be **deleted in slice 4** along with CoachDashboardView.

### Files in slice 3

New:
- `src/Shared/Frontend/FrontendViewBase.php` — shared abstract base with asset + header helpers
- `src/Shared/Frontend/FrontendOverviewView.php` — My card
- `src/Shared/Frontend/FrontendMyTeamView.php` — My team
- `src/Shared/Frontend/FrontendMyEvaluationsView.php` — My evaluations
- `src/Shared/Frontend/FrontendMySessionsView.php` — My sessions
- `src/Shared/Frontend/FrontendMyGoalsView.php` — My goals
- `src/Shared/Frontend/FrontendMyProfileView.php` — My profile (new view, didn't exist in v2.21)

Modified:
- `src/Shared/Frontend/DashboardShortcode.php` — new `dispatchMeView()` helper, Me-slug routing
- `docs/player-dashboard.md` — updated for v3 tile-based frontend
- `languages/talenttrack-nl_NL.po` + `.mo` — ~17 new strings

### What's shippable at slice 3

- Me-group tiles **work end-to-end**. Pure-player users see proper focused sub-pages, back button returns to tile landing.
- Coach/admin users who are also players: see Me-group views when they navigate there.
- Coach/admin users in coaching-context still use legacy CoachDashboardView. No regression, but that's slice 4's work.
- Observer role: continues to see analytics tiles (slice 5 will give those real destinations too).

### What's NOT in slice 3

- Coaching-group frontend views (slice 4)
- Analytics-group frontend views (slice 5)
- Row-action UI hiding for observers on admin list pages (slice 2b polish, folded into slice 4/5 as we're already touching frontend)

---



### The churn

Slice 1 introduced 15 granular capabilities (8 view + 7 edit) and aliased the 3 legacy caps (`tt_manage_players`, `tt_evaluate_players`, `tt_manage_settings`) via a `user_has_cap` filter so nothing broke. Slice 2 is the mechanical rewrite: every `current_user_can()` / `user_can()` call site across the plugin (~40 call sites across ~18 files) now uses granular caps directly, and every menu registration + tile entry uses view caps for display gating.

### What changed in slice 2

**Controller-level write gates → edit caps.** Every save, delete, and bulk-action handler now checks the appropriate `tt_edit_*` capability:

- `PlayersPage` save/delete → `tt_edit_players`
- `TeamsPage` save/delete → `tt_edit_teams`
- `GoalsPage`, `SessionsPage` handlers → `tt_edit_goals` / `tt_edit_sessions`
- `ConfigurationPage` 4 save/delete handlers → `tt_edit_settings`
- `FrontendAjax` handle_save_evaluation → `tt_edit_evaluations`, handle_save_session → `tt_edit_sessions`, handle_save_goal / handle_update_goal_status / handle_delete_goal → `tt_edit_goals`, plus the head-dev bypass in handle_save_evaluation → `tt_edit_settings`
- `EvaluationsRestController` POST/DELETE → `tt_edit_evaluations`
- `PlayersRestController` POST/DELETE → `tt_edit_players`
- `BulkActionsHelper::capForEntity` now returns the correct `tt_edit_<entity>` per entity type
- `BulkActionsHelper` `$can_hard_delete` + `delete_permanent` guard → `tt_edit_settings`
- `DragReorder` AJAX handler → `tt_edit_settings`
- `SchemaStatus::handleRun` → `tt_edit_settings`

**Menu visibility + page CAP constants → view caps.** Every list page, tile entry, and `CAP` constant used for page-display gating now uses `tt_view_*`:

- `Menu::register()` — 15+ `add_submenu_page(...)` calls updated with per-entity view caps (tt-teams → `tt_view_teams`, tt-players → `tt_view_players`, tt-people → `tt_view_people`, tt-evaluations → `tt_view_evaluations`, tt-sessions → `tt_view_sessions`, tt-goals → `tt_view_goals`, tt-config/tt-custom-fields/tt-eval-categories/tt-category-weights/tt-usage-stats/tt-roles/tt-functional-roles/tt-roles-debug → `tt_view_settings`)
- `Menu::dashboard()` — 5 stat card `'cap'` entries + 13 tile `'cap'` entries updated to per-entity view caps
- `Menu::addSeparator()` — People/Performance/Analytics/Configuration/Access Control separators → view caps
- `PeoplePage::CAP` → `tt_view_people`
- `CustomFieldsPage::CAP`, `EvalCategoriesPage::CAP`, `CategoryWeightsPage::CAP`, `UsageStatsPage::CAP`, `UsageStatsDetailsPage::CAP`, `RolesPage::CAP`, `FunctionalRolesPage::CAP`, `DebugPage::CAP` → `tt_view_settings`
- `MenuExtension` both call sites → `tt_view_settings`
- `MigrationsPage` — line 26 display → `tt_view_settings`, lines 210/229 action handlers → `tt_edit_settings`

**Print + role-resolution.** `PrintRouter` bypass and coach fallback → `tt_view_*` (printing is a view action). `UsageStatsDetailsPage` + `UsageTracker` role-categorization `user_can()` calls → `tt_edit_*` so the "is admin / is coach" determination preserves its original semantics (real admin = can edit settings, real coach = can edit evaluations).

**Dashboard routing.** `DashboardShortcode::render()` and `FrontendTileGrid::render()` now use edit caps for `$is_admin` / `$is_coach` role routing. The observer correctly takes neither branch (doesn't have edit caps) and falls through to the `tt_view_reports` branch → analytics-only frontend view.

### Impact: Observer role now works end-to-end in the admin

Before slice 2 the observer role was granted 8 `tt_view_*` caps but the plugin still checked legacy cap names at most call sites. Via the alias filter, checks like `current_user_can('tt_manage_players')` would fail for observer (correctly — they don't have edit caps) but the failure would hide the entire Players page, not just the Edit/Delete buttons.

After slice 2 the admin respects the split:

- **Observer logs into admin** → sees the TalentTrack menu with every group and sub-page the viewer caps allow (all of them)
- **Opens Teams page** → sees the list of teams
- **Opens Players page** → sees the list of players
- **Clicks a player** → sees the detail / edit form (view caps also gate the edit form, since observer still needs to look)
- **Tries to save** → controller-level `tt_edit_players` check kicks in, `wp_die('Unauthorized')`
- **Tries to use a bulk action** → `BulkActionsHelper::capForEntity()` returns `tt_edit_<entity>`, check fails, error notice shown
- **Archive/delete links in the list** — will be finalized in slice 2b polish (below)

### What's NOT in slice 2

**UI-level hiding of write controls.** Archive/delete links, "Add New" buttons, and Edit links in list pages are mostly cap-gated (they use `current_user_can` in their render), but a handful may not be. Slice 2 got the controllers — every attempt to save/delete is now securely blocked. Slice 2b polish (a short follow-up within slice 3) audits the render-level links/buttons to hide them from observers for UX polish, not security.

Security: **No write action is possible for an observer.** Every write handler has been updated. UI hiding is aesthetic — cleaner for observers — not a security concern.

## Files in slice 2

Modified (~18 files):
- `src/Core/Activator.php` (no slice 2 changes; kept for reference)
- `src/Shared/Admin/Menu.php` — menu caps + tile caps refactor
- `src/Shared/Admin/MenuExtension.php` — `tt_view_settings`
- `src/Shared/Admin/BulkActionsHelper.php` — `capForEntity` + hard-delete gate
- `src/Shared/Admin/DragReorder.php` — `tt_edit_settings`
- `src/Shared/Admin/SchemaStatus.php` — `tt_edit_settings`
- `src/Shared/Frontend/DashboardShortcode.php` — `$is_admin` / `$is_coach`
- `src/Shared/Frontend/FrontendTileGrid.php` — same
- `src/Shared/Frontend/FrontendAjax.php` — 6 AJAX handlers
- `src/Modules/Players/Admin/PlayersPage.php` — 2 write handlers
- `src/Modules/Teams/Admin/TeamsPage.php` — 2 write handlers
- `src/Modules/Goals/Admin/GoalsPage.php` — 2 handlers
- `src/Modules/Sessions/Admin/SessionsPage.php` — 2 handlers
- `src/Modules/People/Admin/PeoplePage.php` — CAP constant
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — 4 handlers
- `src/Modules/Configuration/Admin/CustomFieldsPage.php` — CAP constant
- `src/Modules/Configuration/Admin/MigrationsPage.php` — display vs action gates
- `src/Modules/Evaluations/Admin/EvalCategoriesPage.php` — CAP constant
- `src/Modules/Evaluations/Admin/CategoryWeightsPage.php` — CAP constant
- `src/Modules/Stats/Admin/UsageStatsPage.php` — CAP constant
- `src/Modules/Stats/Admin/UsageStatsDetailsPage.php` — CAP + role check
- `src/Modules/Stats/PrintRouter.php` — bypass + coach check
- `src/Modules/Authorization/Admin/RolesPage.php` — CAP constant
- `src/Modules/Authorization/Admin/FunctionalRolesPage.php` — CAP constant
- `src/Modules/Authorization/Admin/DebugPage.php` — CAP constant
- `src/Infrastructure/REST/EvaluationsRestController.php` — 2 handlers
- `src/Infrastructure/REST/PlayersRestController.php` — 2 handlers
- `src/Infrastructure/Usage/UsageTracker.php` — role categorization

## What's shippable at slice 2

- **Yes** — every pre-existing feature works (alias layer preserves behaviour for anything that might have slipped through the audit)
- **Yes** — the observer role now meaningfully navigates the admin without accidentally hiding pages they should see
- **Yes** — write attempts by observers are securely blocked at the controller level everywhere
- **No regressions** expected for existing roles (Coach, Admin, Scout, Staff) — they hold both view and edit caps, so checks either way pass

## Roadmap for the rest of v3.0.0

- Slice 3: 6 Me-group frontend views (FrontendOverviewView, FrontendMyTeamView, FrontendMyEvaluationsView, FrontendMySessionsView, FrontendMyGoalsView, FrontendMyProfileView) + delete PlayerDashboardView
- Slice 4: 6 Coaching-group frontend views + delete CoachDashboardView
- Slice 5: 2 Analytics-group frontend views (FrontendRateCardView, FrontendComparisonView) + final v3.0.0 ZIP

## Verify slice 2

### Observer role end-to-end
1. WP Users → add user → assign Read-Only Observer role. Log in as that user.
2. Visit wp-admin. TalentTrack menu is fully visible with every sub-page accessible.
3. Navigate to Players. See the list. Click any player. Edit form loads read-only.
4. Change a field and click Save. **"Unauthorized" error** (cap check at the controller).
5. Navigate to Configuration. See the tabs. Try to save a config change. **Unauthorized error.**
6. Navigate to Access Control → Permission Debug. See the debug UI.
7. Navigate to Usage Statistics. See the KPIs (view-only, no hidden content).

### Coach role regression
8. Log in as a Coach. All previous evaluation/session/goal functionality works unchanged.
9. Coach does NOT see Configuration, Custom Fields, Evaluation Categories, Category Weights, Usage Statistics — correct, they have no `tt_view_settings`.
10. Coach DOES see Rate Cards, Player Comparison, Reports, Players list, Teams list, People list, Evaluations, Sessions, Goals.

### Admin role regression
11. Log in as a full Administrator (WP admin). All pages visible, all actions allowed. No regression.

## Design notes (slice 2)

- **Why edit caps for routing variables like `$is_admin`.** The old code meant "can manage settings = is admin for routing purposes." Observer has `tt_view_settings` which is not the same as being a routing admin. Using `tt_edit_settings` preserves the original semantics: routing as admin requires the ability to actually do admin things.
- **Why keep the legacy alias filter even after rewriting all call sites.** Third-party code or Club Admin custom logic might check legacy cap names. Alias filter stays through v3.x lifetime as a compatibility guarantee; removal is a consideration for v4+.
- **Why no UI-level hiding of write buttons yet.** Doing it right requires touching the render path of every list page's row-actions + Add-New buttons — a further ~15 files. Security is already covered at the controller level (most important). UI polish follows in slice 2b / slice 3 as we're already touching the render paths anyway.


## Summary of v3.0.0 as a whole

A major-version release that rebuilds three fundamentals:

1. **Migration UX** — admin-triggered migrations via a button instead of deactivate/reactivate, with automatic version tracking.
2. **Capability refactor** — every `tt_manage_*` / `tt_evaluate_*` cap split into `tt_view_*` + `tt_edit_*` pairs. The Read-Only Observer role becomes meaningful across the entire plugin.
3. **Frontend fully rebuilt** — the tile grid from v2.21 now has real destinations. Every tile maps to a dedicated focused view. No more tab navigation.

## Slice 1 (this snapshot) — Migration UX + Capability scaffolding

### Migration UX

Activating / updating TalentTrack used to require deactivate + reactivate to trigger migrations, which was easy to forget. No longer.

**Automatic pending detection.** `Activator::runMigrations()` now stores `TT_VERSION` in the `tt_installed_version` option on every successful run. On every admin page load, TalentTrack compares the stored value to the running `TT_VERSION`. Mismatch = pending migration.

**Admin notice.** When pending, a yellow banner at the top of every admin page: *"TalentTrack schema needs updating. Plugin version 3.0.0 is loaded but installed schema is 2.22.0."* with a **Run migrations now** button. One click, done.

**Plugins-page action link.** Next to the TalentTrack row on the WordPress Plugins page, a **Run Migrations** link is always present (not only when pending) for manual re-runs — useful if you suspect a prior run partially failed.

**Shared idempotent routine.** `Activator::runMigrations()` is callable from both the activation hook and the new admin-post handler. Every step inside (schema ensure, seed data, cap grants, self-healing) was already idempotent; the refactor just surfaces it as a first-class admin action.

**Result notice.** After clicking "Run now", you're redirected back with a green success banner or a red error banner (with the error message). No silent failures.

### Capability refactor scaffolding

The existing 4 capabilities (`tt_manage_players`, `tt_evaluate_players`, `tt_manage_settings`, `tt_view_reports`) were binary — each grant included both view AND write rights. This made proper read-only experiences impossible: a Read-Only Observer could be given `tt_view_reports` but nothing to see teams, players, or evaluations without also granting write access.

**New granular caps.** Eight view caps and seven edit caps:

| Area         | View                    | Edit                     |
|--------------|-------------------------|--------------------------|
| Teams        | `tt_view_teams`         | `tt_edit_teams`          |
| Players      | `tt_view_players`       | `tt_edit_players`        |
| People       | `tt_view_people`        | `tt_edit_people`         |
| Evaluations  | `tt_view_evaluations`   | `tt_edit_evaluations`    |
| Sessions     | `tt_view_sessions`      | `tt_edit_sessions`       |
| Goals        | `tt_view_goals`         | `tt_edit_goals`          |
| Settings     | `tt_view_settings`      | `tt_edit_settings`       |
| Reports      | `tt_view_reports`       | *(no edit companion)*    |

**Role updates.** Every pre-built role now has granular caps. The Observer role becomes meaningful: full view access across every area, zero edit caps.

**Soft alias layer.** The legacy caps still work — a `user_has_cap` filter resolves them via the new granular caps under the hood. `tt_manage_players` is granted when a user has both `tt_view_players` AND `tt_edit_players`. This lets all existing ~60-80 `current_user_can()` call sites continue to work unchanged in slice 1. Slice 2 migrates them to granular caps.

**Observer correctly fails legacy checks.** Because observers have view-only caps, a check for `tt_manage_players` (= view + edit required) fails for them, which is the correct behaviour. Admins who relied on legacy cap names will see the exact same behaviour as before; new read-only scenarios now work properly.

### Files in slice 1

New:
- `src/Shared/Admin/SchemaStatus.php` — migration admin notice + Plugins-page action link + admin-post handler
- `src/Infrastructure/Security/CapabilityAliases.php` — legacy cap → new cap resolution via `user_has_cap` filter
- `docs/migrations.md` — new wiki topic

Modified:
- `talenttrack.php` — version 3.0.0, added `TT_PATH` + `TT_FILE` constant aliases
- `src/Core/Activator.php` — `activate()` wraps new idempotent `runMigrations()`; `runMigrations()` stores `tt_installed_version` on success
- `src/Core/Kernel.php` — registers CapabilityAliases filter at the top of `boot()`
- `src/Infrastructure/Security/RolesService.php` — rewritten with granular VIEW_CAPS + EDIT_CAPS + LEGACY_CAPS class constants; all 8 roles updated; `ensureCapabilities()` grants full inventory to administrator
- `src/Shared/Admin/Menu.php` — wires `SchemaStatus::init()` and result-notice listener
- `src/Modules/Documentation/HelpTopics.php` — registers new migrations topic
- `docs/access-control.md` — rewritten for the new cap matrix
- `languages/talenttrack-nl_NL.po` + `.mo` — ~13 new strings

## What's shippable at slice 1

- **Yes** — the plugin loads, all pre-existing functionality works because legacy caps are aliased
- **Yes** — new migration notice and buttons work
- **Yes** — new roles install on first activation after upgrade
- **No regressions** expected — aliases preserve all pre-v3 behaviour

## What's NOT yet in v3.0.0 (slices 2-5)

- **Slice 2: Capability call-site audit** — ~60-80 `current_user_can()` calls rewritten to granular caps so read-only observer is blocked from writes via cap checks (not just UI hiding). Currently the soft alias handles this transparently.
- **Slice 3: Me-group frontend views** — 6 focused sub-page classes (Overview, My Team, My Evaluations, My Sessions, My Goals, My Profile). Replaces `PlayerDashboardView` tab UI.
- **Slice 4: Coaching-group frontend views** — 6 focused sub-page classes (Teams, Players, Evaluations, Sessions, Goals, Podium). Replaces `CoachDashboardView` tab UI.
- **Slice 5: Analytics-group frontend views** — 2 focused sub-page classes (Rate Card, Comparison) so Read-Only Observer has meaningful frontend experience.

Each slice ships as a new snapshot; final v3.0.0 ships after slice 5 lands.

## Install (slice 1 snapshot)

Extract `talenttrack-v3_0_0-alpha1.zip`. Move `talenttrack-v3.0.0-alpha1/` contents into your `talenttrack/` folder. Deactivate + reactivate (one-time, for the initial migration).

After reactivation: Plugins page has a new "Run Migrations" link next to the TalentTrack row.

On any subsequent code update (e.g. when slice 2 ships), you'll see the admin notice with "Run migrations now" — click it, no deactivate needed.

## Verify

### Migration UX
1. Deactivate + reactivate plugin once after install. Migration runs; `tt_installed_version` option is now `3.0.0`.
2. Plugins page: see "Run Migrations" link next to the TalentTrack row.
3. Click it — redirects with success banner ("TalentTrack migrations completed successfully").

### Capability refactor
4. Create a new user with the Read-Only Observer role. Log in as them.
5. Frontend dashboard: observer sees the Analytics tile group (Rate cards, Player comparison) as they did in v2.21.
6. No regressions: existing Coach, Admin, Scout, Staff users continue working exactly as before.
7. Check `wp user list --field=ID` in WP-CLI, then `wp user get <id> --field=caps` — observer has the full `tt_view_*` set and no `tt_edit_*` caps.

### Admin notice
8. Simulate an outdated state: via phpMyAdmin, set `wp_options.tt_installed_version` to `'2.22.0'`. Reload any admin page. Yellow banner appears with "Run migrations now" button.
9. Click it. Banner disappears, success notice shown, option resets to `3.0.0`.

## Roadmap for the rest of v3.0.0

- Slice 2: capability call-site audit (rewrite all `current_user_can()` calls)
- Slice 3: 6 Me-group frontend views + delete PlayerDashboardView
- Slice 4: 6 Coaching-group frontend views + delete CoachDashboardView
- Slice 5: 2 Analytics-group frontend views (FrontendRateCardView, FrontendComparisonView) + final v3.0.0 ZIP

## Design notes

- **Why aliases instead of rewriting call sites in slice 1.** 60-80 sites to rewrite is a lot of regression risk concentrated in one slice. Aliases make the new cap system active and observer-correct immediately, with no call-site churn. Slice 2 does the mechanical rewrite cleanly.
- **Why the `tt_installed_version` check instead of a version diff table.** Simple state > clever state. One option, one comparison. If migration fails we know exactly what to do.
- **Why SchemaStatus admin notice is persistent not dismissible.** Dismissible notices get dismissed and forgotten. A pending migration is something you want to act on; the banner stays until you click the button.
- **Why "Run Migrations" action link is always shown, not only when pending.** Manual re-run is a recovery path, and having it always available means admins can test the flow before a real upgrade situation happens.
