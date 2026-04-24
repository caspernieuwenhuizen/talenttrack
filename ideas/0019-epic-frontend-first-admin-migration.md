<!-- type: epic -->

# Frontend-first workflows — everything possible on the frontend, including admin

Raw idea:

Move the complete admin to the frontend where possible. Keep in wp-admin only what is really needed, typically done by an overall (tech/site/club) admin. The rest of the work should be doable from the frontend for future scaling.

Refined direction (from shaping chat):

When an admin logs in, they should be able to do *all* admin tasks on the frontend. wp-admin stays available as a fallback and as the home for things that genuinely belong there (WordPress core admin, filesystem/schema operations, other plugins), but no TalentTrack task should *require* wp-admin for any user persona, admin included. The question isn't "what do we hide from coaches" — it's "what, if anything, actually prevents the frontend being a complete TalentTrack surface?"

## Is anything technically preventing this?

Short answer: **no fundamental blocker.** One genuine structural constraint, a handful of things that need thoughtful porting, nothing that forces wp-admin.

### The one real structural constraint

**WordPress core's own admin tools are wp-admin.** Activating/deactivating plugins, running WordPress core updates, managing WP users at the `wp_users` level, editing `wp_options` directly — these are built into WordPress itself and TalentTrack does not and should not replicate them. If an admin needs to do that kind of work, they're in wp-admin, full stop. That's not a TalentTrack design decision; that's just what WordPress is.

Relatedly: **TalentTrack's plugin activation, deactivation, and the initial migration on first activation** run through WordPress's plugin lifecycle hooks (`register_activation_hook` etc.), which fire from wp-admin's Plugins page. The trigger point is wp-admin-bound; the *logic* can move, but the trigger can't. In practice this is fine because plugin install/activate is a once-per-site event, not a recurring task.

Everything else is portable. The list below is what required careful handling when I looked at the code, each with the technical verdict.

### Things that *seem* like blockers but aren't

**Media uploader.** `wp_enqueue_media()` is called in `PlayersPage.php` (player photo) and `ConfigurationPage.php` (club logo). Common belief: the media library is wp-admin-only. Actually: **`wp_enqueue_media()` works on the frontend too**, as long as the JS is loaded and the user has `upload_files` capability. The WooCommerce ecosystem has been doing this for years. Frontend port just needs the enqueue plus a small CSS reset because the default modal inherits wp-admin styling in a few places. Not a blocker, just attention.

**`admin-post.php` handlers (58 across the codebase).** The plugin uses `admin_post_*` hooks as its save-and-redirect pattern for form submissions from wp-admin pages. The URL `admin-post.php` is literally inside wp-admin, but the *handler* doesn't care where the form was submitted from — it's a dispatch endpoint, not a UI. Frontend forms can post to it just fine. That said, the spec already recommends retiring these in favor of REST endpoints (REST is cleaner, faster, better-tested, and works identically regardless of origin). The migration is "deprecate `admin_post_*` handlers in favor of REST" — not "rewrite because they're admin-only."

**Plugin migrations / `dbDelta` / the `MigrationsPage`.** The migration runner executes SQL, which is unrelated to where the UI lives. The page itself is wp-admin today, but the migration-runner *code* can be invoked from anywhere with the right capability. There's even an argument that a frontend "operations" surface for admins — trigger migrations, clear caches, rebuild derived data — would be nicer than wp-admin's, because it can show live progress and streaming logs more easily. Not a blocker.

**Capability checks.** `current_user_can()` works identically on frontend and admin. No difference. The existing `FrontendAccessControl` already demonstrates this.

**Nonces.** `wp_create_nonce()` / `check_admin_referer()` / `wp_verify_nonce()` work everywhere. The legacy pattern `check_admin_referer()` is poorly named (it predates the frontend) but functions identically outside wp-admin.

**Admin notices (`admin_notices`).** Used throughout for post-save feedback. Frontend has no equivalent hook out of the box, but the pattern is trivial to replicate via a transient-backed flash-message system (the plugin already does something similar — `popFormState` in `PlayersPage.php`). Replace the output layer, keep the storage layer.

**`wp_die()` error pages.** Used for capability failures. Fine on the frontend too; just renders differently. If a better UX is wanted, wrap with a frontend-appropriate error template. Not a blocker.

**`wp_list_table`.** This is the table widget used in several admin pages (probably player list, session list, etc.). It *is* wp-admin-only — it hard-depends on admin CSS and admin JS. But the component is aesthetically dated and replacing it with a modern frontend list component is the outcome you want anyway. Not a blocker; a prompt to do better.

**The admin menu (`add_menu_page` / `add_submenu_page`) and the admin bar.** Genuinely wp-admin. Not portable. But navigation on the frontend is solved by the existing `FrontendTileGrid` — new admin-level surfaces become new tiles (possibly in a new "Administration" tile group gated to admins). Different pattern, same purpose.

**Custom field definitions.** `CustomFieldsPage` lets admins define custom fields that then render across every form. Structural in nature, but nothing about defining fields requires wp-admin. The form editor can move. The rendered custom fields already work frontend-side (via `CustomFieldRenderer`).

**Usage stats / audit log viewer.** Currently wp-admin for ops convenience. Portable — these are just read-heavy dashboards. If admins want them frontend-accessible (likely yes, if they're doing everything else there), move them.

### Conclusion on the technical question

Every admin-only piece either (a) works on the frontend with minor polish (media uploader, capability/nonce APIs), (b) is a legacy pattern we were going to replace anyway (`admin-post.php`, `wp_list_table`, admin notices), or (c) belongs to WordPress core itself and isn't ours to move (plugin activation, WP user management). None of this is a blocker for "admin logs in → everything available on the frontend."

## The refined scope

With that cleared up, the epic's scope broadens from "move coach/HoD work" to **"achieve feature parity on the frontend for every TalentTrack task, for every user persona."** Three categories now:

- **Frontend-only.** Day-to-day work — evaluations, sessions, goals, team management, reports, people, trial cases, formation boards. wp-admin versions removed in Sprint 5 (with the "legacy UI" toggle for the transition window).
- **Frontend + wp-admin.** Admin-tier tasks — config, custom field definitions, migrations, role grants, usage stats, audit log, integrations, license. Primary home on the frontend (under a new gated "Administration" tile group); wp-admin versions kept because they're sometimes more convenient for a tech admin (direct URL, muscle memory) and because they're the fallback if the frontend itself is broken.
- **wp-admin only.** WordPress-core things — plugin activation, WP user account management, core updates, filesystem editor. These are WordPress's, not TalentTrack's.

The key change from the prior framing: **no TalentTrack task is wp-admin-only.** Admins who prefer wp-admin can still use it; admins who want to work entirely frontend can.

## Where we are today (audit)

The current split is already partly frontend-first. Before writing "move everything," it helps to see what's already been done.

### Already on the frontend

Read views:
- Player dashboard (overview / my team / my evaluations / my sessions / my goals / my profile) — `src/Shared/Frontend/PlayerDashboardView.php` + friends
- Coach dashboard — `CoachDashboardView.php`
- Frontend-wide tile grid, shortcode, access control

Write paths (via `FrontendAjax` → `tt_fe_save_evaluation`, `tt_fe_save_session`, `tt_fe_save_goal`, `tt_fe_update_goal_status`, `tt_fe_delete_goal`):
- Save evaluation (with ratings)
- Save session (with attendance)
- Save goal
- Update goal status
- Delete goal

So coaches can already evaluate, schedule, and set goals frontend-side. The core coaching loop is mostly migrated. That's the good news — the foundation pattern exists (`FrontendAjax` + `CoachForms` + nonce-protected handlers + per-cap checks).

### Still admin-only

Per-module, what's currently wp-admin but belongs on the frontend:

| Module | Admin page | Who uses it | Frontend parity? |
| --- | --- | --- | --- |
| Players | `PlayersPage` (list, add, edit, ratecard tab) | Coach + HoD + admin | **Yes, primary frontend** (bulk/CSV stays both) |
| Teams | `TeamsPage` | Coach + HoD + admin | **Yes, primary frontend** |
| People | `PeoplePage`, `TeamStaffPanel` | HoD + admin | **Yes, primary frontend** |
| Sessions | `SessionsPage` | Coach | **Yes, primary frontend** (already half done via AJAX) |
| Goals | `GoalsPage` | Coach + HoD | **Yes, primary frontend** (already half done) |
| Evaluations | `EvaluationsPage` | Coach | **Yes, primary frontend** (already half done) |
| Eval Categories | `EvalCategoriesPage` + `CategoryWeightsPage` | HoD / club admin | **Frontend + admin** (structural, but portable — see below) |
| Stats (Rate cards, Comparison) | `PlayerRateCardsPage`, `PlayerComparisonPage` | Coach + HoD | **Yes, primary frontend** (view already partly there) |
| Reports | `ReportsPage` | HoD | **Yes, primary frontend** |
| Configuration | `ConfigurationPage`, `CustomFieldsPage` | Club admin | **Frontend + admin** (portable; admin kept as fallback) |
| Authorization | `RolesPage`, `FunctionalRolesPage`, `RoleGrantPanel`, `DebugPage` | Club admin | **Frontend + admin** (portable; see Functional Roles note) |
| Migrations | `MigrationsPage` | Site admin | **Frontend + admin** (trigger can move; admin kept as fallback) |
| Documentation | `DocumentationPage` (help/wiki) | Everyone | **Frontend + admin** (lives both; admin was historical accident) |
| Usage Stats | `UsageStatsPage` | Club admin | **Frontend + admin** |
| Audit log viewer | (implicit, not yet a page) | Club admin | **Frontend** when built |
| Stats print views | `PlayerReportView`, `PlayerCardView` | Generates PDFs | **Already works both sides** |

### The hybrid cases worth calling out specifically

- **Player add/edit.** Frontend-side: a coach adds a player to their team or edits a kid's preferred foot. Fast, mobile. Admin-side: club admin imports 200 players from a CSV or bulk-edits contact details. Both needed. Different UIs, same backing data.
- **Evaluation categories + weights.** Changing the evaluation taxonomy is a structural decision — rare, all-or-nothing, affects every existing evaluation. That's admin-persona work even if the head of development is doing it. Keep wp-admin. A "review my category weights" read-only view on the frontend is fine.
- **Functional roles (per-player staff assignments).** Currently wp-admin only. But in practice a head-of-development assigns these all the time ("assign Jan as keeper coach for this trial case"). Should move to a lightweight frontend surface, probably triggered from inside flows that need it (trial case page from #0017, team page). The admin version stays for bulk work.

## What "frontend-first" should mean in practice

Not just "moved to a shortcode page." The bar should be:

1. **Mobile-usable.** Large tap targets. Forms that work on a phone held in muddy-hands position. Not a wp-admin form stuffed into a narrower container.
2. **Single-page-per-task.** Not seven tabs and three modals. A coach adding an evaluation should see one coherent screen.
3. **Role-appropriate.** Coaches only see their teams, players only see themselves, HoD sees everything. Scoping is enforced at the data layer, not in the UI's filter dropdowns.
4. **Works with the existing DashboardShortcode routing.** The tile-grid + subview-dispatch pattern is already there (`DashboardShortcode::dispatchMeView`, `dispatchCoachingView`, `dispatchAnalyticsView`). New frontend pages plug into this, not into parallel shortcodes.
5. **No wp-admin styling leaks.** No `$wp_admin_bar`, no `dashicons`, no `.wp-heading-inline`. A dedicated `assets/css/frontend-admin.css` — matches the pattern `assets/css/player-card.css` already established for visual isolation.

## The hard parts (not the code)

### Performance and scale

The raw idea says "for future scaling." Worth unpacking.

wp-admin pages have one concurrent user per site (the admin). Frontend pages can have *hundreds* concurrent (every coach on their phone at the same Saturday morning). The scaling problem isn't the UI — it's the database and the PHP request volume.

Key moves this epic should bake in from the start:

- **All writes go through REST, not `admin-ajax.php`.** `admin-ajax.php` is notoriously slow per-request (bootstraps much of WP). The plugin already has `includes/REST/` with `Players_Controller`, `Evaluations_Controller`, `Config_Controller`. The `FrontendAjax` class is a legacy pattern that should be **retired as part of this epic**, not extended.
- **Aggressive caching of read views** via transients, with invalidation on writes. Already partially in place for stats; extend.
- **Client-side optimistic UI.** Save-then-reconcile, not block-then-confirm. Matters on flaky pitch-side 4G.
- **Idempotency keys on writes.** A coach tapping "save" twice on an unreliable connection shouldn't create two evaluations.

### Security

wp-admin has a lot of security-by-default (admin-bar auth state, nonces in form helpers, capability checks baked into `admin-post.php`). Frontend pages don't get that for free. Every endpoint this epic adds needs:

- Explicit nonce (`wp_create_nonce` + `check_ajax_referer` / `wp_verify_nonce`)
- Explicit `current_user_can()` with the right cap — the existing capability system is already granular (`tt_view_*` / `tt_edit_*` pairs per v3.0.0), use it
- Explicit sanitization and `wp_unslash` on every input
- Rate-limit on write endpoints (simple — rolling counter in transient)

None of this is hard. All of it is easy to skip. A security audit (flagged in #0012 Part B4) should land either *just before* or *right after* this epic.

### Role-scoping everywhere

The admin pages today often show "all players" because a site admin is the one looking. The frontend equivalents must scope:

- A coach sees only players on teams they coach
- A head of development sees all teams in their scope
- A player sees only themselves
- Read-only observers see what they're allowed to see

This scoping logic already exists in `FrontendAccessControl` for the views that have been migrated. Newly migrated surfaces need to use the same pattern, not reinvent.

### Mobile reality

A wp-admin page is usable on desktop. A frontend page might be the only screen the coach ever uses. That means:

- Touch-first layouts, not mouse-first with responsive CSS bolted on
- Offline tolerance (at minimum, localStorage of partially-filled forms — lose-progress-on-network-failure is unforgivable at the pitch side)
- Camera integration where it makes sense (ties into #0016 photo-to-session)
- Big buttons, forgiving tap targets, no tiny icons

The existing frontend views are an improvement on wp-admin but still partly "desktop screens shrunk." Migrating new flows is an opportunity to do it properly.

### Discoverability and navigation

wp-admin has a left-side menu that people learn. The frontend has tiles. As more functionality moves frontend, the tile grid either grows unwieldy or needs sub-grouping. `FrontendTileGrid` already groups by Me / Coaching / Analytics / Administration — this holds for v1 but may need refinement as more tiles land. Flag for Sprint 3+.

## Migration order — what to move first and why

Not alphabetical. Sequence by value-to-effort and by risk.

1. **Sessions (finish migration)** — the AJAX write path exists, but there's no full frontend add/edit-session form yet. Coaches currently open wp-admin for this. **Single highest-leverage move.** Sessions at training-ground is the canonical use case.
2. **Goals (finish migration)** — similar story: save/update/delete via AJAX, but the HoD list/create workflow is wp-admin. Move it.
3. **Player list + profile edits** — coaches frequently update small things ("Jan lost 2 kg, adjust height, change position"). Move the single-player edit to frontend; keep the bulk/CSV/import flows in wp-admin.
4. **Teams admin** — roster management, assigning coach, setting formation (when #0018 ships). Move.
5. **People (staff) + Functional Roles** — for the trial-module workflow (#0017) where assigning staff happens all the time, a frontend view matters. Move the day-to-day assignment; keep the admin full-power view.
6. **Reports** — generating rate cards and player reports. View side is already on frontend. Move the list/filter/launch surface.
7. **Eval categories read-only** — a frontend "these are the categories being used and their weights" view so coaches can check without clicking into wp-admin. Editing stays admin.
8. **Stats: Player comparison + rate card list** — the views exist on frontend; add launcher surfaces so nobody needs to use wp-admin for these.

Each of the above ships independently, small enough to be reviewed and tested standalone. Don't combine.

## What genuinely stays wp-admin-only

A much shorter list than the prior framing suggested:

- **WordPress core admin.** Plugin list, WP core updates, WP user account management, the file editor, settings for other plugins. Not TalentTrack's.
- **Plugin activation + first-run migration trigger.** Fires via `register_activation_hook`, which is part of WordPress's plugin lifecycle. Ongoing migrations (triggered manually or post-update) can run from anywhere, but the very first install-and-activate is wp-admin-bound.
- **Emergency fallback.** If the frontend surface has a bug or a theme-conflict that blocks access, wp-admin is the way back in. That's a *feature*, not a migration target. Keep wp-admin surfaces functional even after Sprint 5 removes them from the primary navigation — they remain accessible to users with `manage_options` via direct URLs, just not advertised.

Everything else — including things that feel admin-native like migrations, role grants, custom field definitions, usage stats — ports to the frontend without a fundamental blocker.

## Architectural decisions worth locking early

### 1. REST over ajax-admin

Write endpoints for the migrated surfaces go through the existing `includes/REST/` controllers. Expand those controllers where needed rather than adding to `FrontendAjax`. Retire `FrontendAjax` over the life of this epic.

### 2. One source of truth per view

Today some views duplicate logic between `src/Modules/*/Admin/` and `src/Shared/Frontend/`. When migrating, move shared view-logic into the Module namespace and have the frontend render layer consume it. Prevents drift where the wp-admin version and the frontend version gradually diverge.

### 3. Frontend shortcode + URL routing

The existing shortcode pattern (`[talenttrack_dashboard]` + query-string view dispatch) has scaling limits. Probably fine for the life of this epic, but worth noting that as more flows move frontend, a proper router (pretty URLs, back-button support, deep-linkable states) becomes valuable. Flag as a later consideration — not blocking, but flag it.

### 4. Shared form / field components

The existing `CoachForms` class is one home for this. Extract patterns from the first few migrations into reusable components: player-picker, date-picker-wrapped, rating-input, multi-select-tag. Pays back by the third migrated page.

### 5. Progressive enhancement, not SPA

Resist the urge to make this a React/Vue app. Server-rendered HTML with sprinkled JS has served the plugin well, runs on any WordPress host without build tooling, and doesn't need its own release process. Every new frontend page should ship as progressive PHP output + targeted JS enhancements. The moment this epic becomes "port everything to a React app" it grows from a 5-sprint epic to a 20-sprint rebuild.

## Decomposition / rough sprint plan

1. **Sprint 1 — foundation.** REST endpoint expansion to cover everything `FrontendAjax` does today (goal: within this sprint, `FrontendAjax` is deprecated and flagged). Shared frontend-form components extracted. Frontend-admin CSS scaffold. Flash-message system to replace `admin_notices`. Routing/back-button polish on the existing tile grid.
2. **Sprint 2 — sessions + goals full frontend.** The two most-requested migrations. Full add/edit/delete/list for each, mobile-optimized. The old wp-admin pages stay (don't remove yet), so nothing breaks.
3. **Sprint 3 — players + teams.** Single-record edit on the frontend including the media uploader (photo upload). Bulk/CSV available on both sides. Team management (roster, head coach assignment, formation placeholder for #0018).
4. **Sprint 4 — people + functional roles + reports.** HoD-facing surfaces. Interacts with #0017 for trial-case staff assignment.
5. **Sprint 5 — admin-tier surfaces.** The new one. Configuration, custom fields editor, eval categories + weights, roles + capabilities, migrations trigger, usage stats, audit log viewer — all get frontend surfaces under a new gated "Administration" tile group, visible only to users with admin capabilities. wp-admin versions stay functional (accessible via direct URL) as fallback.
6. **Sprint 6 — cleanup + deprecation.** Remove the now-redundant wp-admin menu entries for migrated surfaces where appropriate, OR mark them deprecated and hide them behind a "legacy UI" toggle. Direct URLs still work (emergency fallback). Document the new defaults.
7. **Sprint 7+ — sweep.** Documentation viewer on frontend, any surface that fell out of earlier sprints, PWA shell (optional capstone).

This runs in parallel with other ideas that add new surfaces — #0016 (photo capture, frontend-native by design), #0017 (trial module, frontend-leaning), #0018 (team development, frontend formation board). Each of those should ship *frontend-first*; this epic is about **pulling existing admin surfaces over**, while new features get built on the new side from the start.

## Rollback story

If a migrated surface has a real problem in production — a bug only revealed at scale, a UX mistake — the wp-admin version should still work until explicitly removed in Sprint 5. "Legacy UI" toggle in settings is cheap insurance: shows the old admin pages alongside a deprecation notice, lets a club opt-in to the old flow for a few releases while the new version stabilizes.

## Open questions

- **Do we support a "headless" mode — frontend-only with no wp-admin for non-admins?** Tempting but probably not. Admin-bar visibility for a user who *can* log into wp-admin is fine; for players and most coaches, they just never see it because they don't have the cap. WP's default behavior is close to what we want — don't fight it.
- **How far do we go on offline tolerance?** Progressive — localStorage for form drafts is cheap. Full offline-with-sync is a whole separate epic. For this epic: drafts only, no sync.
- **Mobile app wrapper.** Some clubs will ask. A PWA (progressive web app) shell on top of the frontend is cheap (manifest + service worker + caching). A native app is not this epic. Flag PWA as a possible finale; native as separate.
- **Do we still ship wp-admin surfaces for migrated entities?** In Sprint 5 we should remove them. But clubs with existing muscle memory will object. The "legacy UI" toggle from the rollback section gives us the middle ground for 1-2 releases.
- **Capability names may need revisiting.** The existing `tt_view_*` / `tt_edit_*` pattern is sound, but frontend surfaces may need new verbs like `tt_access_frontend_admin`. Hold off; revisit only if needed.
- **Monetization intersection (#0011).** Frontend vs admin isn't a tier distinction — but "advanced admin tools" (CSV import, usage stats, audit log) *could* be a tier gate. Worth coordinating with #0011 so we don't paint ourselves into a corner.
- **What about the help/wiki docs — do they have a frontend viewer?** Currently wp-admin only. Low-priority migration, but clubs wanting their staff to read the wiki without wp-admin access will want it. Follow-on, not blocker.

## Interactions with other ideas in the backlog

This is the most cross-cutting epic. Worth being explicit:

- **#0009 (development management module)** — designed frontend-first from the start. No impact.
- **#0010 (multi-language)** — frontend strings need translation just as admin strings did. Same `talenttrack` text domain. No new translation scope beyond what's already captured.
- **#0011 (monetization)** — license key entry could live frontend-side (Account page), but the configuration is admin-persona. Keep admin.
- **#0012 (professionalize + remove AI fingerprints)** — affects docs and copy. Frontend migration inherits whatever copy discipline #0012 establishes. No conflict.
- **#0013 (backup/DR)** — admin-persona work. Stays admin. No impact.
- **#0014 (player profile + reports wizard)** — the report wizard is frontend-first in its spec already. Reinforces the direction of this epic.
- **#0016 (photo-to-session)** — frontend-first by design. Reinforces.
- **#0017 (trial player module)** — designed frontend-leaning. The case management page should be frontend; only letter-template editing stays admin. Confirms direction.
- **#0018 (team development)** — formation board is explicitly frontend. Reinforces.

Everything new points the same direction. That's the right moment to pull the old surfaces over.

## Touches

Pervasive. Rough inventory:

New / expanded:
- `includes/REST/` — controllers expanded to cover every write operation currently in `FrontendAjax` plus admin-tier operations (migrations trigger, role grants, config, custom field definitions, eval category management)
- `src/Shared/Frontend/` — new views: `FrontendSessionsManageView`, `FrontendGoalsManageView`, `FrontendPlayersManageView`, `FrontendTeamsManageView`, `FrontendPeopleManageView`, `FrontendReportsView`, plus admin-tier: `FrontendConfigurationView`, `FrontendMigrationsView`, `FrontendRolesView`, `FrontendCustomFieldsView`, `FrontendEvalCategoriesView`, `FrontendUsageStatsView`
- `src/Shared/Frontend/Components/` — new subfolder for reusable form components (`PlayerPickerComponent`, `DateInputComponent`, `RatingInputComponent`, `MediaUploaderComponent`, etc.)
- `src/Shared/Frontend/FlashMessages.php` — transient-backed replacement for `admin_notices`
- `assets/css/frontend-admin.css` — dedicated styling for frontend admin surfaces (including media-uploader modal reset)
- `assets/js/frontend-admin.js` — optimistic UI, idempotency keys, localStorage drafts, media uploader bootstrap

Deprecated / retained as fallback:
- `src/Shared/Frontend/FrontendAjax.php` — retire over the epic
- `admin_post_*` handlers across modules — replaced by REST
- Most `src/Modules/*/Admin/` pages remain wired up to their menu slugs (direct URLs continue to work as emergency fallback) but are removed from the primary TalentTrack admin menu in Sprint 6. The "legacy UI" toggle re-exposes them during the transition.

Stays wp-admin-only:
- Plugin lifecycle hooks in `includes/Activator.php` — activation, initial migration trigger. WordPress-bound.
- Nothing else TalentTrack-specific is wp-admin-only after this epic.

Settings page additions:
- "Legacy UI" toggle (Sprint 6) — restores deprecated admin menu entries for clubs that need the transition window
- Tile-group visibility settings for the new "Administration" group on the frontend
