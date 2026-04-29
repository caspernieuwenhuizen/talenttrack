# TalentTrack v3.48.0 — Demo-readiness round 2

Continues the v3.46.0 hotfix bundle with six more user-reported fixes. v3.47.0 (parallel agent) shipped activity polish + cohort fix + wizard config UX in between.

## Fixes

### Monetization gate honours module-disabled state

`src/Shared/Frontend/FrontendComparisonView.php`, `FrontendRateCardView.php`, `FrontendPlayersCsvImportView.php` — three frontend views had `class_exists('\\TT\\Modules\\License\\LicenseGate')` as the gate, which is always true because PHP autoload makes the class loadable regardless of whether the module booted. Added a `\TT\Core\ModuleRegistry::isEnabled('TT\\Modules\\License\\LicenseModule')` guard before the gate fires. When the License module is toggled off in the Modules admin, tier checks are skipped and the feature renders unconditionally.

### Parents see the Me-group dashboard

`src/Shared/CoreSurfaceRegistration.php` — added `is_player_or_parent_cb` callback (`is_player_cb($uid) || user_can($uid, 'tt_parent')`). Six Me-group tiles updated to use it: My card, My team, My evaluations, My activities, My goals, My journey. My PDP already had a parent branch. My profile stays player-only because the view expects a player record. The `parent` matrix scope (in `config/authorization_seed.php`) already grants parents read access to their child's data, so the tile views resolve correctly.

### Workflow cadence + deadline relabelled

`src/Modules/Workflow/Frontend/FrontendWorkflowConfigView.php` — "Cadence" → "How often (cron)" with an inline `?` help tooltip explaining the cron-expression format and the "leave placeholder unchanged" hint. "Deadline offset" → "Deadline (days)" with a help tooltip. Page intro paragraph rewritten in plainer language ("Turn templates on or off, and override how often they run + how long users have to act").

### Journey filter bar collapsed

`src/Modules/Journey/Frontend/FrontendJourneyView.php` — three primary chips (`evaluation_completed` / `injury_started` / `trial_ended`) stay visible; the rest of the event types are wrapped in a `<details>` element labelled "More filters (N)". Auto-opens if any secondary filter is currently active. Reset and Filter buttons unchanged.

### Trial cases create form has a proper desktop layout

`assets/css/frontend-admin.css` — new CSS block under `.tt-dashboard .tt-trial-create-form`. 2-column grid at 768px+ (Player + Track on row 1, Start + End on row 2), full-width staff fieldset + notes + actions. Inputs styled at 48px min-height with `font-size: 1rem` (no iOS auto-zoom). Mobile stays single-column.

### Roles & rights vs Functional roles labelling

Already merged ahead of this release in PR #115. Rolled into the v3.48.0 release for changelog continuity. "Roles & Permissions" admin menu → "Roles & rights"; intro paragraphs rewritten on both pages with cross-links pointing at the other surface; tile description tightened.

## Translations

7 new NL strings: cadence + deadline labels and tooltips, journey "More filters (%d)".

## What's deferred to v3.49.0

Three larger items still queued — they each need real new code rather than tweaks:

- **Trial player inline-create flow** (#1, Option A): embed a player-create mini-form inside the trial-case create UI so users don't need a pre-existing player record.
- **`StaffPickerComponent`** (#3): mirror `PlayerSearchPickerComponent` for picking staff, replacing plain `<select>` user dropdowns in 3 places (trial case staff assignment, new-team wizard staff step, etc.).
- **Configuration tile sub-page** (#8): the frontend Configuration tile currently links out to wp-admin; should open a sub-tile grid mirroring the wp-admin Configuration submenu (lookups, branding, toggles, backups, etc.).

These are real product features, not config tweaks. Each is 1-3h compressed.

## Acceptance criteria (manually verified)

- [ ] Disabling License module hides upgrade nudges on Player comparison, Rate cards, CSV import.
- [ ] Parent role sees the Me group on dashboard with their child's data.
- [ ] Workflow templates config shows "How often (cron)" + "Deadline (days)" labels + help tooltips.
- [ ] Player journey shows 3 primary chips + "More filters (N)" toggle.
- [ ] Trial cases create form renders 2-column on desktop, single-column on mobile.
- [ ] Roles & rights admin menu reads "Roles & rights" + has cross-links.

---

# TalentTrack v3.47.0 — Activity status + source, colour pills, cohort tile fix, wizard config UX

Five small asks bundled together. Each lands on the activity surface or polishes an admin UX paper-cut.

## Activity model — status + source

Two new columns on `tt_activities`, both lookup-driven and admin-extensible:

- `activity_status_key VARCHAR(50) NOT NULL DEFAULT 'planned'` — lifecycle (planned / completed / cancelled). Surfaces as a form field on the admin and frontend create/edit views; flips from `planned` (the default) to `completed` once the activity has happened, or to `cancelled` if it didn't go ahead.
- `activity_source_key VARCHAR(50) NOT NULL DEFAULT 'manual'` — who or what created the activity (manual / spond / generated). Set automatically: REST and admin paths set `manual`; the demo-data generator sets `generated`; the future Spond integration will set `spond`. Not exposed on the form.

Both come with a matching `tt_lookups` seed (`activity_status` and `activity_source`) carrying `meta.color` for pill rendering, `meta.is_locked = 1` so the seeded rows can't be deleted, and Dutch translations.

## Activity types — seed extended + filter dropdown unwired from hardcode

- New seeded types: **`tournament`** (orange) and **`meeting`** (purple). Idempotent — re-running the migration leaves any admin renames alone.
- Existing `training` / `game` / `other` get `meta.color` backfilled (teal / blue / grey) so the new type-pill renderer always has a colour.
- `ActivitiesPage` (admin) filter dropdown was hardcoded to `[game, training, other]` — admin-added types were invisible on the filter even though the form dropdown was already lookup-driven from #0050. Filter now reads from `QueryHelpers::get_lookups('activity_type')` like the form does, with translated labels via `LookupTranslator::name()`.

## Type pill in lists

A new shared helper `LookupPill::render( lookup_type, name )` returns a colour-coded inline `<span class="tt-pill">` using the lookup row's `meta.color` (falling back to neutral grey when absent). Used by:

- The admin activities list — replaces the plain-text `renderTypeBadge()` output. The Game subtype + Other free-text label still render alongside the pill.
- The frontend activities list — new `Type` column. The REST list response carries an `activity_type_pill_html` field with the server-rendered pill; the column uses a new `'render' => 'html'` mode on `FrontendListTable` (assets/js/components/frontend-list-table.js) that emits the value verbatim.

## Wizard config UX — text input → checkbox grid

`FrontendWizardsAdminView` (`?tt_view=wizards-admin`) used to ask admins to type `'all'` / `'off'` / a comma-separated list of slugs into a free-text field, with the available slugs hidden behind a `<details>` panel. It's now a checkbox grid:

- One tickable card per registered wizard (label + slug + 48px tap target).
- An **Enable all wizards** master toggle at the top syncs every checkbox.
- On save, the form serialises back to the existing storage shape (`'all'` when every wizard is checked, `'off'` when none are, comma-separated slug list otherwise) so `WizardRegistry::isEnabled()` is unchanged. Cosmetic on storage, real on UX.

## Cohort transitions tile — two bugs fixed

The HoD-facing **Cohort transitions** tile (added in v3.44.0 with the #0053 player-journey epic) was throwing critical errors when filters were applied. Two distinct bugs:

- **Bug A — array-vs-object access (white screen).** `FrontendCohortTransitionsView` accessed `$row['first_name']`, `$row['player_id']`, `$row['event_date']`, `$row['summary']` array-style — but `PlayerEventsRepository::cohortByType()` calls `wpdb->get_results()` without the `ARRAY_A` flag and returns `list<object>`. PHP fatal `Cannot use object of type stdClass as array` on every result row. Switched the four sites to object syntax (`$row->first_name`, etc.).
- **Bug B — parameter order on team_id filter.** `cohortByType()` built `$params` as `[event_type, from, to, club_id]`, then *appended* `team_id` before `array_merge( $params, $allowed_visibilities )`. Final order: `[event_type, from, to, club_id, team_id, vis…]`. But the SQL placeholder order is `[event_type, from, to, club_id, vis…, team_id]` — so when the team filter was applied, parameters bound to the wrong placeholders. Restructured: `array_merge( [base], $allowed_visibilities, $extra_param )` so visibilities sit in the middle and team_id is appended last.

Both bugs were pre-existing back to v3.44.0 — the v3.45.1 sweep didn't introduce them.

## ActivitiesPage `club_id` scoping (gap closure)

The v3.45.1 SaaS-readiness sweep added `club_id` filtering across 114 PHP files, but its source-side audit regex (`bin/audit-tenancy-source.sh`) only matches `$wpdb->prefix . 'tt_xxx'` and `$p . 'tt_xxx'` concatenation, not the `{$p}tt_xxx` interpolation style used by `ActivitiesPage`. The audit passed; the file slipped through. This release adds the filter in the form load, save (insert + update), delete, and attendance writes — all queries that were modified for the activity-status work anyway.

## Documentation

- `docs/activities.md` + `docs/nl_NL/activities.md` — new "Status and source" section, updated steps to mention the five seeded types and the new pill.
- `docs/wizards.md` + `docs/nl_NL/wizards.md` — replaced the "type a comma-separated list of slugs" instructions with the checkbox-grid description.

## Translations

Four new `nl_NL` msgstrs added — every new user-facing string is translated.

## SEQUENCE.md

New `v3.47.0-bundle` row under Done.

---

# TalentTrack v3.46.0 — Demo-readiness hotfix bundle (auth + wizards + tiles)

A small bundle of fixes surfaced during the user's demo-install review. Each item is a real bug or UX regression visible to actual users.

## Fixes

### Authorization

- **`config/authorization_seed.php`** — removed the `methodology` row from the `player` and `parent` personas. The matrix grid was leaking methodology read-access to those personas, so the Methodology tile rendered for them. Coaches + admins still see it as before. Backfill migration not required because the matrix is data; existing installs pick up the change after the seed re-applies on the next boot. (User report #15.)
- **`src/Infrastructure/Security/RolesService.php`** — added `tt_access_frontend_admin` to the `tt_club_admin` role definition. Previously only `administrator` and `tt_head_dev` could see Configuration / Migrations / Audit log / Wizards admin tiles; club admins are obvious owners of those surfaces.
- **`src/Modules/Development/DevelopmentModule.php`** — removed `tt_readonly_observer` from `$submit_roles` for `tt_submit_idea`. The role's name implied read-only; granting an authoring cap was a semantic contradiction. Idempotent removal added for installs that already granted the cap.

### Wizards

- **`src/Shared/Wizards/WizardEntryPoint.php`** — new `dashboardBaseUrl()` helper that resolves the current dashboard's URL via `$_SERVER['REQUEST_URI']` minus the routing query args, mirroring `DashboardShortcode::shortcodeBaseUrl()`. Replaces `home_url('/')` everywhere wizard URLs are constructed (5 sites: `WizardEntryPoint::urlFor`, `FrontendWizardView`'s redirect after step submit + after final submit + cancel + help-sidebar link, and the four wizard `submit()` redirects in `Wizards/{Player,Team,Evaluation,Goal}/*`). Wizards now work on installs where the dashboard shortcode lives on `/some/sub/page/` instead of the front page.
- **`src/Shared/Frontend/FrontendWizardView.php`** — Cancel button no longer dumps the user on `?tt_view=wizard` with no `slug` (which renders "Wizard not found"). Cancel now redirects to the dashboard tile-landing.

### Visuals

- **`assets/css/frontend-admin.css`** — added `.tt-dashboard .tt-mye-detail[hidden] { display: none }` to fix the same UA-specificity bug as v3.28.2's guest-add modal: `display: flex` was overriding the HTML `hidden` attribute, so the player evaluation rating breakdown was visible without clicking **Show detail**. The toggle now works correctly.
- **`src/Shared/CoreSurfaceRegistration.php`** — Trial cases tile icon changed from `players` (visual collision with the People → Players tile) to `track`. Trial tracks editor icon changed from `lookup` (no matching SVG, blank render) to `categories`. **My journey** tile reordered to first in the Me group (was last). **My sessions** tile renamed to **My activities** to match the post-#0035 vocabulary.

### Translations

- **`languages/talenttrack-nl_NL.po`** — added `Training sessions and games you've attended.` → `Trainingen en wedstrijden die je hebt bijgewoond.` for the new My activities description. All other relabeled strings (`My activities`, `My journey`, etc.) were already translated.

## Out of scope (queued for a v3.47.0 follow-up)

The user's demo review listed 18 items. This release ships the cheap-and-clear fixes. The following are larger and need separate work:

- **Monetization tier gating** — disabling the License module doesn't release tier-locked modules. Needs investigation in `LicenseModule` to ensure tier checks short-circuit when the module is disabled.
- **Workflow "cadence" field unclear** — needs UX rewrite + inline help, not just translation.
- **Trial cases form ugly on desktop** — CSS layout pass on `FrontendTrialsManageView` create form.
- **Trial player creation flow** — Option A (inline mini-wizard inside trial-case form) requires non-trivial form composition; deferred.
- **Configuration tile-landing sub-page** — needs design pass to mirror the wp-admin sidebar.
- **Player journey filter declutter** — collapse multi-filter bar into a "Filter" toggle drawer.
- **Staff picker like player picker** — new `StaffPickerComponent`. Worth its own spec.
- **Full NL translation sweep** for journey + recent feature strings.
- **Authorization parents have only "My PDP"** — needs product call on which surfaces parents should access.

These will land in a v3.47.0 hotfix bundle after design questions are resolved.

## Acceptance criteria (manually verified)

- [ ] Player + parent users no longer see the Methodology tile.
- [ ] Wizards work on a non-front-page dashboard.
- [ ] Wizard Cancel button returns to the tile-landing.
- [ ] Player evaluation page hides rating breakdown until Show detail is clicked.
- [ ] Club Admin can see Configuration / Audit log / Wizards admin tiles.
- [ ] Read-only Observer cannot see Submit an idea tile.
- [ ] Trial cases + Trial tracks tiles render with distinct icons.
- [ ] My journey tile is first in the Me group; My activities is the renamed tile.

---

# TalentTrack v3.45.1 — SaaS-readiness baseline (PR-A follow-up): repository sweep (#0052)

Closes the "Repository sweep deferred" caveat carried into v3.45.0. Mechanical follow-up only — no schema change, no behaviour change today, no new user-facing strings, no docs update. The sweep adds the `club_id` filter to every read and the `club_id` value to every write across 114 PHP files, so adding a real SaaS auth resolver later only touches `CurrentClub`, not 100+ query sites.

## Files touched

114 PHP files across:

- `src/Infrastructure/REST/` — Players, Teams, Activities, People, Goals, EvalCategories, FunctionalRoles, CustomFields controllers.
- `src/Infrastructure/` — Archive, Audit, Authorization, CustomFields, Evaluations, Journey, People, Query, Security, Usage repositories + services.
- `src/Modules/*/Repositories/` — Authorization, Evaluations, Goals, Invitations, Journey, Methodology (10 repos), Pdp (5 repos), Reports, TeamDevelopment, Trials (6 repos), Translations (3 repos), Workflow (4 repos).
- `src/Modules/*/Admin/` — Authorization (Roles, RoleGrant, FunctionalRoles), Configuration, Goals, Players, Teams, Workflow, Stats admin pages.
- `src/Modules/DemoData/` — DemoBatchRegistry + 7 generators (Activity, Evaluation, Goal, People, Player, Team, User) + DemoDataCleaner + DemoGenerator.
- `src/Modules/Wizards/` — Evaluation (PlayerStep, TypeStep), Goal (DetailsStep, LinkStep, PlayerStep), Player (ReviewStep, RosterDetailsStep, TrialDetailsStep), Team (BasicsStep, ReviewStep) — all four wizards' steps now scope on `club_id`.
- `src/Modules/Workflow/` — CronDispatcher, CronHealthNotice, three form classes, three frontend views (MyTasks, TasksDashboard, WorkflowConfig), four template classes (PlayerSelfEvaluation, PostGameEvaluation, QuarterlyGoalSetting, QuarterlyHoDReview).
- `src/Modules/Pdp/` — NativeCalendarWriter, SeasonCarryover, plus the five PDP repositories above.
- `src/Modules/Translations/` — TranslationLayer + the three cache repositories.
- `src/Shared/Frontend/` — FrontendPeopleManageView, FrontendTrialCaseView, FrontendTrialsManageView, FrontendUsageStatsDetailsView.

## Pattern

Reads pick up `AND club_id = %d` in their `WHERE` clause with `CurrentClub::id()` appended to the prepared params. Writes either spread `[ 'club_id' => CurrentClub::id() ]` into the `$wpdb->insert` data array or add `'club_id' => CurrentClub::id()` into the `$wpdb->update` WHERE array. Joined queries scope both sides (`LEFT JOIN ... AND b.club_id = a.club_id`).

The shared `QueryHelpers::clubScopeWhere()` / `clubScopeInsertColumn()` helpers added in v3.45.0 are exercised here; some call sites use them directly, others inline the equivalent prepared fragment for readability inside larger query builders.

## CI

New `bin/audit-tenancy-source.sh` static check — for every PHP file under `src/` that mentions a tenant-scoped `tt_*` table (matched against the same table list in `bin/audit-tenancy.php` and migration `0038_tenancy_scaffold.php`), the file must also reference `club_id` or `CurrentClub::`. Allowlist covers the legitimate exceptions (`Activator.php` install-time existence checks, `MigrationRunner.php` schema introspection). Exit `0` on pass, `1` on fail with `::error file=...` GitHub-Actions-friendly output. Currently passes — every src/ file is in scope.

## Known gaps still deferred

These were already deferred in v3.45.0 and remain deferred:

- **Wizard analytics counters** in wp_options (`tt_wizard_*_<slug>` dynamic keys) — separate refactor.
- **`tt_user_id` resolver** — documented intent in `docs/access-control.md` § Deferred.
- **wp_usermeta tenant-scoped keys** — documented gap; not load-bearing today.

## SEQUENCE.md

`#0052 (PR-A)` row updated — drops the "Repository sweep deferred" caveat. New `#0052 (PR-A sweep)` row added under Done at v3.45.1.

---

# TalentTrack v3.45.0 — SaaS-readiness baseline (PR-A): tenancy scaffold + tt_config reshape (#0052)

First of three independently-shippable PRs that bring the existing schema into compliance with `CLAUDE.md` § 3. PR-A is the only one that touches schema, so it ships solo per AGENTS.md and unblocks PR-B (REST gap closure + auth portability) and PR-C (assets + cron + OpenAPI).

## What this release does NOT do

- Change runtime behaviour. Every existing row carries `club_id = 1`, every existing read returns the same single tenant. The scaffold is invisible at runtime today.
- Sweep every repository to add `WHERE club_id = N` to read-side queries. Deferred to PR-B + module-by-module follow-ups (see § Known gaps below). The audit script flags data-integrity violations; the read-side gap is documented in `docs/architecture.md`.
- Build the `tt_user_id` resolver. Documented intent only.
- Multi-tenant the WP plugin. Single-tenant after this release; `CurrentClub::id()` returns `1`.

## Schema

Two new migrations.

### `0038_tenancy_scaffold.php`

Adds `club_id INT UNSIGNED NOT NULL DEFAULT 1` to ~50 tenant-scoped `tt_*` tables. Idempotent — already-shipped tables (#0017 trial cases, #0053 journey events) are skipped via `SHOW COLUMNS`. A best-effort `idx_club_id` index is added per table; failures (innodb 64-index ceiling, duplicate name on fresh install) are swallowed because the column is what matters.

Adds `uuid VARCHAR(36) UNIQUE` to the five root entities — `tt_players`, `tt_teams`, `tt_evaluations`, `tt_activities`, `tt_goals` — with `UNIQUE INDEX uniq_uuid`. Backfills existing rows in 500-row batches via `wp_generate_uuid4()` so a 5,000-row table doesn't lock the DB. Already-set rows are skipped on the WHERE clause.

The list of tables is enumerated in the migration's `tablesNeedingClubId()` method. ~50 tables once subordinate / leaf tables are counted; the spec's "~25 tenant-scoped" rounds the conceptual top-level count.

### `0039_tt_config_tenancy.php`

Reshapes `tt_config`:

- Adds `club_id INT UNSIGNED NOT NULL DEFAULT 1` column.
- Drops `PRIMARY KEY (config_key)`.
- Adds new `PRIMARY KEY (club_id, config_key)`.

Existing rows pick up `club_id = 1` from the column default. Code already filters by `config_key`; this migration is invisible at runtime today, but `(club_id, config_key)` is the natural per-tenant scope when SaaS migration lands.

Tenant-scoped `wp_options` migrated into `tt_config`:

- `tt_trial_acceptance_club_address`
- `tt_trial_acceptance_response_days`
- `tt_trial_admittance_include_acceptance_slip`

Each option's value is copied via `INSERT IGNORE` keyed by `(1, <option_name>)`. The `wp_options` row is left in place so a rollback is trivial; cleanup of the wp_options rows is a follow-up.

Install-global options stay in wp_options:
- `tt_installed_version` (which version of plugin schema is installed).
- `tt_wizard_started_*` / `_completed_*` / `_skipped_*` (analytics counters; per-club analytics is a separate refactor — documented gap).

The Activator's `CREATE TABLE tt_config` statement was also updated to ship the composite primary key on fresh installs.

## Tenancy infrastructure

New `TT\Infrastructure\Tenancy\CurrentClub::id()` returns the active club. Today: always `1`. Filterable via `apply_filters( 'tt_current_club_id', 1 )` so a future SaaS auth backend (session / JWT / subdomain) hooks in without touching this class. Single-source chokepoint.

Two new helpers on `QueryHelpers`:

- `QueryHelpers::clubScopeWhere( ?string $alias = '' )` — returns `"club_id = N"` (or `"alias.club_id = N"`) as a SQL fragment. Inline into existing query builders.
- `QueryHelpers::clubScopeInsertColumn()` — returns `[ 'club_id' => N ]`. Spread into `$wpdb->insert` data arrays.

Today both call `CurrentClub::id()` which returns `1`; tomorrow they pick up the active tenant for free.

## ConfigService update

`ConfigService::get()` and `ConfigService::set()` now filter by `CurrentClub::id()`:

```php
SELECT config_value FROM tt_config WHERE club_id = %d AND config_key = %s
```

`replace()` includes `club_id` in the data array. The internal cache is namespaced per-club (`<club_id>:<config_key>`) so multiple clubs in the same request (test or future SaaS) don't return stale reads.

## Call-site refactors

Five direct `tt_config` reads outside `ConfigService` were switched to `QueryHelpers::get_config()` / `set_config()` so the per-tenant scope works automatically:

- `Activator::seedDashboardPageIfMissing()` — direct SELECT now scoped to `club_id = 1`.
- `DemoDataPage::defaultClubName()` — `academy_name` read.
- `TeamGenerator::clubName()` — `academy_name` read.
- `SeasonCarryover::resolveClubCycleDefault()` — `pdp_cycle_default` read.
- `FrontendWorkflowConfigView::loadMinorsPolicy()` / `saveMinorsPolicy()` — minors policy read + write.
- `PlayerOrParentResolver::loadPolicy()` — minors policy read.

The three trial-letter `wp_options` call sites (`LetterTemplateEngine::responseDeadlineFor()` / `acceptanceSlipEnabled()`, `FrontendTrialLetterTemplatesEditorView::renderSettings()` + `handlePost()`) now read + write via `QueryHelpers::get_config()` / `set_config()` against `tt_config`.

## Verification

`bin/audit-tenancy.php` ships with the plugin. One-shot script verifying:

1. Every tenant-scoped table has a populated `club_id` column.
2. Every root entity has a populated, unique `uuid` column.
3. `tt_config` has the composite `(club_id, config_key)` primary key.

Run via `wp eval-file wp-content/plugins/talenttrack/bin/audit-tenancy.php`. Exit `0` on success, `1` on failure with per-table report.

## Known gaps (deferred — documented)

These are intentionally not addressed in PR-A and don't block PR-B/C from starting:

- **Repository read-side filter sweep.** Most repositories under `src/Modules/*/Repositories/` execute SQL like `SELECT ... FROM tt_xxx WHERE id = %d` without a `club_id` filter. Today this is correct (one tenant, all rows have `club_id=1`); a second tenant would leak. Mechanical sweep happens in PR-B + module-by-module follow-ups before SaaS go-live. The audit script catches data-integrity violations; the read-side gap is documented in `docs/architecture.md` § Known SaaS-readiness gaps.
- **Wizard analytics counters.** `tt_wizard_*_<slug>` rows in wp_options use dynamic keys; per-club analytics is a separate refactor.
- **`tt_user_id` resolver.** Documented intent in `docs/access-control.md` § Deferred.

## Documentation

- `docs/architecture.md` — new "SaaS-readiness scaffold (#0052 PR-A)" section with the contract + the known-gaps list.
- `docs/migrations.md` — new "SaaS-readiness audit script" section explaining `bin/audit-tenancy.php`.
- `docs/access-control.md` — new "Capabilities are the contract" section codifying the auth-portability rule + deferred-resolver note.

## SEQUENCE.md

`#0052 (PR-A)` moves from Ready → Done. PR-B + PR-C remain in Ready, both unblocked by this release.

---

# TalentTrack v3.44.0 — Player journey: chronological events spine + injuries + cohort transitions (#0053 epic)

Closes the #0053 Player journey epic. Brings the player-centricity principle codified in `CLAUDE.md` § 1 from aspirational to enforceable: every player now has a chronological journey that's queryable, filterable, and visibility-scoped. The journey is a read-side aggregate — Evaluations, Goals, PDP, Players, Trials all keep their own UIs and own their data; this release subscribes to those modules' hooks and projects events into a new spine.

## Schema

Migration `0037_player_journey.php` adds two tables:

- **`tt_player_events`** — the journey spine. One row per event with `uuid`, `club_id` (per CLAUDE.md § 3 SaaS-readiness), `event_type`, `event_date`, `summary`, JSON `payload`, four-level `visibility`, and `superseded_by_event_id` for soft-correct. The `uk_natural (source_module, source_entity_type, source_entity_id, event_type)` unique key makes re-emission a no-op.
- **`tt_player_injuries`** — minimal injury record (started / expected / actual return + body-part / severity / type lookups + notes). Carries `club_id`. Three lookups (`injury_type`, `body_part`, `injury_severity`) seeded.

A fourth lookup `journey_event_type` carries the 14 v1 event types (joined_academy / trial_started / trial_ended / signed / released / graduated / team_changed / age_group_promoted / position_changed / injury_started / injury_ended / evaluation_completed / pdp_verdict_recorded / note_added) with `meta.icon` / `meta.color` / `meta.severity` / `meta.default_visibility` / `meta.group`. Per-club editable.

One-shot backfill: every existing evaluation → `evaluation_completed`, every signed-off PDP verdict → `pdp_verdict_recorded`, every goal → `goal_set`, every player.date_joined → `joined_academy`, every non-draft trial case → `trial_started` (+ `trial_ended` where decided). Idempotent — re-running adds nothing.

Skipped backfills (current-state-only sources): team changes, position changes, injuries, pre-migration `signed`. Documented as known gaps; from-now-on tracking only.

## Module + emission

New `TT\Modules\Journey\JourneyModule` registered in `config/modules.php`. Boot hooks `JourneyEventSubscriber::init()` against existing module hooks:

- `tt_evaluation_saved` (existing) → emit `evaluation_completed`
- `tt_goal_saved` (new — fired from `GoalsRestController::create_goal`) → emit `goal_set`
- `tt_pdp_verdict_signed_off` (new — fired from `PdpVerdictsRepository::upsertForFile` on transition to signed-off) → emit `pdp_verdict_recorded`
- `tt_player_created` (new — fired from `PlayersRestController::create_player`) → emit `joined_academy`
- `tt_player_save_diff` (new — fired from `PlayersRestController::update_player` with old + new) → diff-based emission of `team_changed`, `age_group_promoted`, `position_changed`, `signed`, `released`, `graduated`
- `tt_trial_started` (new — fired from `TrialsRestController::create_case`) → emit `trial_started`
- `tt_trial_decision_recorded` (new — fired from `TrialsRestController::record_decision`) → emit `trial_ended` (+ `signed` on admit, `released` on deny_final)

Plus injury repository emissions: `injury_started` on insert, `injury_ended` on `actual_return` set.

`EventEmitter::emit()` is idempotent via `uk_natural`; defensive payload validation with `payload_valid=0` for schema drift instead of rejection. `do_action( 'tt_player_event_emitted', $event_id, $event_type, $player_id )` fires after every successful emit for downstream subscribers.

`EventTypeRegistry` loads taxonomy from `tt_lookups` (cached per request) plus per-type payload schemas defined in PHP.

## REST

New `PlayerJourneyRestController` registered under `talenttrack/v1`:

- `GET /players/{id}/timeline` — cursor-paginated, default 12-month window, server-side visibility filtering, returns `hidden_count` for honest UI placeholders.
- `GET /players/{id}/transitions` — milestone-severity events only.
- `POST /players/{id}/events` — manual events (e.g. `note_added`).
- `PUT /player-events/{id}` — soft-correct (creates replacement event, marks original superseded).
- `GET /journey/event-types` — taxonomy with rendering meta.
- `GET /journey/cohort-transitions` — HoD cohort queries with date + team filters.
- `GET/POST /players/{id}/injuries`, `PUT/DELETE /player-injuries/{id}` — injury CRUD.

All declare `permission_callback` against capabilities. Per-row visibility filtered against the viewer's caps via `PlayerEventsRepository::visibilitiesForUser`. Coaches without `tt_view_player_medical` see medical events as `hidden_count` placeholders, never as raw data.

## Capabilities

Two new caps in `RolesService::JOURNEY_CAPS`:

- `tt_view_player_medical` — granted to `tt_head_dev`, `tt_club_admin`, `administrator`. Coaches do NOT get it by default; clubs grant per-coach via the matrix admin UI.
- `tt_view_player_safeguarding` — granted only to `tt_head_dev` + `administrator`. Reserved for sensitive entries.

Public + coaching_staff visibility levels are gated by existing caps (`tt_view_players` + `tt_edit_evaluations`).

## Frontend

Three new dispatcher slugs:

- `?tt_view=my-journey` — player-side, in the Me group. Tile registered.
- `?tt_view=player-journey&player_id=N` — coach-side, reached from the new **Journey** button on the player detail view.
- `?tt_view=cohort-transitions` — HoD-only, in Analytics group. Tile registered with `cap=tt_view_settings`.

`FrontendJourneyView` renders both player- and coach-side journeys (the only difference is which player object is passed). Two view modes (Timeline / Transitions). Filter chips by event type. "1 entry hidden" placeholders for masked rows. Default 12-month window with "Show full history" toggle.

`FrontendCohortTransitionsView` is a form-driven cohort query — pick event type + date range + optional team, drill into any player's full journey from the result rows.

Mobile-first 360px base, 48px touch targets, no hover-only interactions. Visible on the new tiles and the player-detail button via `tt_view_settings` / `is_player_cb` callbacks.

## Workflow integration

New `injury_recovery_due` template registered against the workflow engine on module boot. Migration seeds the `tt_journey_injury_logged` → `injury_recovery_due` trigger row. When `InjuryRepository::create()` succeeds with an `expected_return` set, the engine spawns a task on the player's head coach: "Confirm [player] is on track for recovery / extend / unsure." Reuses the existing `TeamHeadCoachResolver`.

The `pdp_verdict_due_for_journey` template the spec mentioned was not built — #0044 already covers PDP verdict cadence via its own workflow.

## Documentation

- New `docs/player-journey.md` (EN) + `docs/nl_NL/player-journey.md` (NL), both `<!-- audience: user -->`.
- New `HelpTopics::all()` row `player-journey` under the `performance` group.
- New "Journey events" section in `docs/architecture.md` documenting the workflow-vs-journey and audit-log-vs-journey boundaries.
- `docs/rest-api.md` resources table updated with the journey endpoints.

## Translations

NL `.po` updated with ~70 new msgids covering UI strings, lookup labels (Dutch translations seeded into `tt_lookups.translations` JSON for all 14 event types + 7 injury types + 13 body parts + 4 severities), workflow form copy, REST error messages.

## SaaS-readiness

Both new tables carry `club_id INT UNSIGNED NOT NULL DEFAULT 1`. `tt_player_events` carries `uuid CHAR(36)` (root entity in the journey domain). All new repositories filter by `club_id` even though it's a no-op today — when #0052 lands, no schema change is needed in this module.

## Cross-references

- **#0017** — trial module (shipped v3.42.0). New domain hooks `tt_trial_started` and `tt_trial_decision_recorded` fired from `TrialsRestController`.
- **#0022** — workflow engine. New `injury_recovery_due` template + seeded trigger row.
- **#0044** — PDP cycle. New `tt_pdp_verdict_signed_off` action added to `PdpVerdictsRepository`.
- **#0033** — authorization matrix. Two new `tt_view_player_medical` / `tt_view_player_safeguarding` caps.
- **#0052** — SaaS-readiness baseline. This release ships ahead of #0052 with the scaffold inline.

---
# TalentTrack v3.43.0 — Record-creation wizards: framework + four wizards (#0055 epic)

Closes the #0055 Record-creation wizards epic. All four phases bundled — framework + new-player (Phase 1), new-team + setup-wizard hook (Phase 2), new-evaluation + new-goal (Phase 3), polish + analytics (Phase 4).

The flat "+ New X" forms across Players, Teams, Evaluations, and Goals get a step-by-step alternative that branches on key answers, pre-fills sensible defaults, and renders mobile-first. Each entry point auto-routes to the wizard when enabled and falls back to the original form when disabled — flipping the toggle is one config write.

## Phase 1 — Framework + new-player wizard

Five primitives under `src/Shared/Wizards/`:

- **`WizardStepInterface`** — `slug() / label() / render() / validate() / nextStep() / submit()`. Branching happens via `nextStep()` returning a non-default slug; the framework drives the loop.
- **`WizardInterface`** — `slug() / label() / requiredCap() / steps() / firstStepSlug()`. A wizard is a collection of steps with a cap gate.
- **`WizardRegistry`** — module-registered wizards looked up by slug. `isAvailable($slug, $user)` checks both cap and `tt_wizards_enabled` config (`all` / `off` / CSV).
- **`WizardState`** — transient-backed accumulator keyed on `(user_id, wizard_slug)`. Each step's `validate()` return is merged into state. TTL = 1 hour, so abandoned sessions clean themselves up.
- **`WizardAnalytics`** — three rolled-up counters per wizard slug in `wp_options` (started / completed / per-step skip count). No new table; the cardinality is small.

Generic driver at `src/Shared/Frontend/FrontendWizardView.php` renders any wizard, validates POST, advances or submits, redirects on completion. Mobile-first inline CSS (single column, 48px targets, 16px fonts, progress strip).

**New-player wizard** with trial vs roster branching:

- Step 1 (path) — radio: roster / trial.
- Step 2 (roster) — name, DOB, team, jersey number, preferred foot.
- Step 2 (trial) — name, DOB, team, trial track, start/end dates.
- Step 3 (review) — confirm + create.

Trial path creates a real `tt_trial_cases` row via `TrialCasesRepository::create()` from #0017 — same workflow as opening a case directly. Without the Trials module, the player still gets `status='trial'` so the user can pick it up later. The wizard redirects to either the new player detail page (roster) or the new trial-case detail (trial).

## Phase 2 — New-team wizard + setup wizard hook

Three steps: basics → staff → review.

The staff step has four independent slots (head coach / assistant coach / team manager / physio), each skippable. On submit, each filled slot becomes a `tt_team_people` row pointing at the appropriate functional role (looked up via `FunctionalRolesRepository::findRoleByKey()`); if the WP user has no `tt_people` row yet, one is created from their profile.

Setup wizard hook: `OnboardingPage::renderFirstTeam()` now offers a "Use the new-team wizard instead" link when the wizard is registered and the cap is held. The link opens the wizard in a new tab and the user comes back to the onboarding flow when done.

## Phase 3 — New-evaluation + new-goal wizards

`new-evaluation` is a thin two-step (player → type) that hands off to the existing evaluation create form with `?action=new&player_id=…&eval_type_id=…&eval_date=…` pre-filled. The full eval-categories + sub-ratings + attachments form is too heavy to live in a wizard step; the wizard's job is just to land you at the right form first time.

`new-goal` is a self-contained three-step (player → methodology link → details) that writes the goal directly. The link step is polymorphic — pick a type (principle / football_action / position / value) and the candidate list refreshes on the next render. Optional `tt_goal_links` row inserted after the goal write.

## Phase 4 — Polish: admin + analytics

`FrontendWizardsAdminView` at `?tt_view=wizards-admin` (gated on `tt_edit_settings`):

- Top section: edit `tt_wizards_enabled` (text input — `all`, `off`, or CSV of registered slugs). Available slugs listed in a `<details>` block.
- Bottom section: per-wizard analytics — Started, Completed, Completion rate (%), Most-skipped step (with skip count).

Per-step skip events are recorded by `WizardAnalytics::recordSkipped()` when the user clicks the "Skip step" button. Completion rate is `completed / started`; both are integer counters in `wp_options`.

Help-topic sidebar per wizard — opens the relevant `docs/<topic>.md` in a new tab.

## Entry-point gating on existing manage views

Tiny one-line change per manage view: the existing "+ New X" button URL goes through `WizardEntryPoint::urlFor( $wizard_slug, $fallback )`, which returns the wizard URL when available + enabled, or the existing flat-form URL otherwise. Touched: `FrontendPlayersManageView`, `FrontendTeamsManageView`, `FrontendEvaluationsView`, `FrontendGoalsManageView`. Reverting per-wizard is just flipping the config.

## Default config

`tt_wizards_enabled` defaults to `'all'` — fresh installs see the wizards out of the box. Clubs that prefer the flat forms set it to `'off'`. Per-wizard opt-in via CSV list (e.g. `'new-player,new-team'`).

## Files of note

- `src/Shared/Wizards/*` — five framework primitives + `WizardEntryPoint` helper.
- `src/Shared/Frontend/FrontendWizardView.php` — generic driver.
- `src/Shared/Frontend/FrontendWizardsAdminView.php` — admin + analytics page.
- `src/Modules/Wizards/WizardsModule.php` — module bootstrap, registers four shipped wizards.
- `src/Modules/Wizards/Player/*` — `NewPlayerWizard` + 4 steps (Path, RosterDetails, TrialDetails, Review).
- `src/Modules/Wizards/Team/*` — `NewTeamWizard` + 3 steps (Basics, Staff, Review).
- `src/Modules/Wizards/Evaluation/*` — `NewEvaluationWizard` + 2 steps (Player, Type).
- `src/Modules/Wizards/Goal/*` — `NewGoalWizard` + 3 steps (Player, Link, Details).
- `src/Modules/Onboarding/Admin/OnboardingPage.php` — setup wizard hook.
- `src/Shared/Frontend/{FrontendPlayers,FrontendTeams,FrontendEvaluations,FrontendGoals}*View.php` — `WizardEntryPoint::urlFor()` swap on the new-button URLs.
- `config/modules.php` — WizardsModule registered.
- `src/Shared/CoreSurfaceRegistration.php` — Wizards admin tile + slug ownership.
- `src/Shared/Frontend/DashboardShortcode.php` — `wizard` + `wizards-admin` slug routing.
- `docs/wizards.md` + `docs/nl_NL/wizards.md` — user-facing docs.

## Out of scope (per spec)

- **Drag-drop wizard authoring** — academies can't author wizards; they're code.
- **Multi-tenant wizard customization** — covered by #0052 SaaS readiness when it lands.
- **Wizard for editing existing records** — value is at creation; editing stays as the flat form.
- **Heavy form steps inside the wizard** — evaluation wizard hands off to the existing eval form; the wizard isn't trying to absorb that weight.

---

---

# TalentTrack v3.42.0 — Trial player module: case workflow, letters, parent-meeting mode (#0017 epic)

Closes the #0017 Trial player module epic. All six sprints bundled — schema + case CRUD (Sprint 1), execution view (Sprint 2), staff input flow (Sprint 3), decision + letters (Sprint 4), parent-meeting mode (Sprint 5), track + letter template editor (Sprint 6).

The plugin previously only acknowledged trials with a player status value of `trial`. Now there's a structured workflow around it: who is trialing, on which track, with which staff, what they observed, what was decided, and what letter was sent.

## Sprint 1 — Schema + case CRUD

Migration `0036_trial_module.php` adds six tables in one go:

- **`tt_trial_cases`** — the core entity (player + track + dates + status + decision + retention metadata).
- **`tt_trial_tracks`** — track templates with default duration; three seeded (Standard / Scout / Goalkeeper).
- **`tt_trial_case_staff`** — assigned staff per case with optional role label.
- **`tt_trial_extensions`** — audit trail for extensions (justification mandatory).
- **`tt_trial_case_staff_inputs`** — per-staff submissions, draft / submitted / released states.
- **`tt_trial_letter_templates`** — per-locale custom overrides of the three letter templates.

`tt_trial_cases` includes `club_id` + `uuid` columns per the SaaS-readiness scaffold.

`FrontendTrialsManageView` ships the list + create form. The list filters by status, track, decision, and archived state. Creating a case auto-flips the player's status to `trial`.

`FrontendTrialCaseView` is the six-tab edit surface (Overview, Execution, Staff inputs, Decision, Letter, Parent meeting). The first tab — Overview — shows the summary, assigned staff, and extension history. Extending the trial requires a non-empty justification note (logged with previous_end_date / new_end_date / by-whom).

Three new capabilities (`tt_manage_trials`, `tt_submit_trial_input`, `tt_view_trial_synthesis`) declared in `RolesService::TRIAL_CAPS` and granted via `TrialsModule::ensureCapabilities()`.

## Sprint 2 — Execution view

Execution tab synthesizes everything that happened during the trial window:

- **Sessions** — `tt_activities` rows whose `activity_date` falls in `[start_date, end_date]`, joined with the player's `tt_attendance` row.
- **Evaluations** — `tt_evaluations` rows with `eval_date` in the window.
- **Goals** — `tt_goals` rows with `created_at` or `updated_at` in the window.
- **Synthesis** — rolling rating + evaluation count, computed by reusing `PlayerStatsService::getHeadlineNumbers( $player_id, [ date_from, date_to ] )` instead of adding a new method.

Nothing is duplicated. The data sits in the existing tables — the Execution tab is just smart filtering.

## Sprint 3 — Staff input flow

`FrontendTrialCaseView::renderInputsTab` plus `TrialStaffInputsRepository` deliver:

- **Per-staff input form** — assigned staff submit overall rating + free-text notes. Draft / submit transitions track separately.
- **Visibility rules** — own input always visible (draft or submitted); others' inputs visible to a coach only after the head of development clicks **Release submitted inputs to assigned staff**. Manager always sees everything.
- **Aggregation** — manager view shows side-by-side cards per submitting staff member with rating + notes.
- **Reminders** — `TrialReminderScheduler` runs daily, emails assigned staff at T-7 / T-3 / T-0 if they haven't submitted. Per-(case, user, bucket) tracking via usermeta prevents duplicate sends. Manual trigger via REST `POST /trial-reminders/run` for testing.

## Sprint 4 — Decision + letters

Decision tab on the case view:

- HoD picks one of three outcomes: **admit** / **deny_final** / **deny_encouragement**.
- Justification note required (≥ 30 characters, internal record).
- For deny-with-encouragement: a strengths summary + growth areas field. Both flow directly into the letter via `{strengths_summary}` and `{growth_areas}` substitution.
- On submit: `TrialCasesRepository::recordDecision()` writes the decision, sets `status = 'decided'`. Player status atomically transitions: admit → `active`, deny_* → `archived`.
- `TrialLetterService::generate()` immediately renders the appropriate letter via `LetterTemplateEngine`, persists to `tt_player_reports` (the table reused from #0014 Sprint 5) with `expires_at = NOW() + 2 years`. Older letter versions for the same case are revoked.

`AudienceType` (#0014 Sprint 3) extended with `TRIAL_ADMITTANCE`, `TRIAL_DENIAL_FINAL`, `TRIAL_DENIAL_ENCOURAGE`. The trial flow doesn't go through the chart-and-rating renderer path — letters render via `LetterTemplateEngine` and `DefaultLetterTemplates` directly.

`DefaultLetterTemplates` ships English + Dutch defaults for all three templates. `{player_first_name}`, `{trial_start_date}`, `{trial_end_date}`, `{club_name}`, `{head_of_development_name}`, `{current_season}`, `{next_season}`, `{strengths_summary}`, `{growth_areas}`, `{response_deadline}` all substitute. Unknown variables are left literal so missing fields are visible in the preview.

`acceptance_slip_returned_at` column on `tt_trial_cases`. When the per-club setting is on, admittance letters get a page 2 with a tear-off-style acceptance slip; HoD marks received from the Decision tab.

## Sprint 5 — Parent-meeting mode

`FrontendTrialParentMeetingView` is the sanitized fullscreen view for the conversation with parents. Allow-list rendering: photo, name + age, trial dates, decision outcome with color-coded framing, optional strengths + growth areas (only on deny-with-encouragement), buttons for view / print / email letter, fullscreen launcher.

Explicitly NOT shown: individual staff inputs, attendance %, aggregation stats, justification notes, free-text evaluator comments. The design principle is allow-list — new fields added later default to invisible.

## Sprint 6 — Track + letter template editor

`FrontendTrialTracksEditorView` — list + create + edit + archive for tracks. Seeded tracks have a locked slug but their name + description + default duration are editable. Archiving hides a track from new-case flow but doesn't break existing cases.

`FrontendTrialLetterTemplatesEditorView` — three templates × per-locale editor. HTML source textarea, side-panel variable legend, sample preview rendered via `LetterTemplateEngine` with placeholder data. Save / Reset to default. Acceptance-slip settings (toggle + response deadline + club return address) live on the same page.

`TrialLetterTemplatesRepository::getForKey()` resolves: custom row for current locale → custom row for `en_US` → shipped default for current locale → shipped default for `en_US`. Clubs that haven't customized never hit the table.

## REST surface

`/wp-json/talenttrack/v1/trial-cases/*` resource-oriented endpoints:

- `GET    /trial-cases`
- `POST   /trial-cases`
- `GET    /trial-cases/{id}`
- `PUT    /trial-cases/{id}`
- `POST   /trial-cases/{id}/extend`
- `POST   /trial-cases/{id}/decision`
- `GET    /trial-cases/{id}/staff`
- `POST   /trial-cases/{id}/staff`
- `POST   /trial-cases/{id}/inputs`
- `POST   /trial-cases/{id}/inputs/release`
- `GET    /trial-tracks`
- `POST   /trial-reminders/run`

Every endpoint declares its `permission_callback` against the cap layer; per-case visibility is enforced by `TrialCaseAccessPolicy`.

## Files of note

- `src/Modules/Trials/TrialsModule.php` — module bootstrap.
- `src/Modules/Trials/Repositories/*` — six repositories (Cases, Tracks, CaseStaff, Extensions, StaffInputs, LetterTemplates).
- `src/Modules/Trials/Letters/LetterTemplateEngine.php`, `DefaultLetterTemplates.php`, `TrialLetterService.php` — letter rendering and persistence.
- `src/Modules/Trials/Reminders/TrialReminderScheduler.php` — daily cron.
- `src/Modules/Trials/Rest/TrialsRestController.php` — REST surface.
- `src/Modules/Trials/Security/TrialCaseAccessPolicy.php` — per-case visibility decisions.
- `src/Shared/Frontend/FrontendTrialsManageView.php`, `FrontendTrialCaseView.php`, `FrontendTrialParentMeetingView.php`, `FrontendTrialTracksEditorView.php`, `FrontendTrialLetterTemplatesEditorView.php`.
- `database/migrations/0036_trial_module.php` — schema + seed.
- `src/Modules/Reports/AudienceType.php` — three new trial audiences.
- `src/Infrastructure/Security/RolesService.php` — `TRIAL_CAPS` constant + admin grant.
- `config/modules.php` — TrialsModule registered.
- `src/Shared/CoreSurfaceRegistration.php` — Trials tile group with Trial cases / Trial tracks / Letter templates tiles.
- `src/Shared/Frontend/DashboardShortcode.php` — five new view-slug routes (`trials`, `trial-case`, `trial-parent-meeting`, `trial-tracks-editor`, `trial-letter-templates-editor`).
- `docs/trials.md` + `docs/nl_NL/trials.md` — user-facing docs.

## Out of scope (not regressed)

- **Public-facing trial application form** — separate future idea.
- **Multi-academy / multi-location** — single-tenant scaffolding only (`club_id` column reserved).
- **Trial-specific evaluation dimensions** — reuses existing eval categories per shaping.
- **Auto-emailing letters to parents** — HoD downloads / mailto's manually.
- **WYSIWYG letter editor** — HTML source textarea only in v1.
- **Per-track letter overrides** — same three templates regardless of track.
- **Retracting a decision** — once recorded, decision is final. Open a new case to re-trial.

---

# TalentTrack v3.40.0 — Report generator: configurable renderer, audience wizard, scout flow (#0014 Sprints 3+4+5)

Closes the #0014 Player profile + report generator epic. Sprint 2 (v3.38.0) rebuilt the player profile; this release lands the three back-end / wizard / scout sprints in one PR.

## Sprint 3 — `ReportConfig` plumbing

Pure refactor. `PlayerReportView` was a single class that produced one report shape. Three new value objects capture every decision the wizard will eventually make:

- `ReportConfig` — audience + filters + sections + privacy + player_id + tone_variant.
- `PrivacySettings` — five flags / threshold (contact details, full DOB, photo, coach notes, min rating).
- `AudienceType` — string-backed pseudo-enum for the five audiences.

The renderer is now `PlayerReportRenderer::render( ReportConfig )`. The legacy `PlayerReportView::render( $player_id, $filters )` is a thin shim that builds a `ReportConfig::standard(…)` and feeds the renderer; behaviour for `?tt_print=N` URLs is preserved.

Section render methods are explicit: `renderHeader / renderPlayerCard / renderHeadlineSection / renderRatingsBreakdown / renderCharts / renderGoals / renderAttendance / renderSessions / renderCoachNotes / renderFooter`. Each respects `$config->sections` whitelist and the privacy flags.

## Sprint 4 — Wizard + audience templates

`FrontendReportWizardView` at `?tt_view=report-wizard&player_id=N`. Four steps on one page (single-form pattern, no JS step transitions): Audience → Scope → Sections → Privacy. "Preview report" submits, renders the report inline below the form.

Audience defaults preselected per choice via `AudienceDefaults::defaultsFor( $audience )`:

| Audience | Scope | Sections | Tone |
|---|---|---|---|
| Standard | All time | All | default |
| Parent monthly | Last month | profile / ratings / goals / attendance | warm |
| Internal detailed | All time | All | formal |
| Player personal | Last season | profile / ratings / goals | fun |
| Scout | All time | profile / ratings | formal |

Tone variants change the headline copy ("Player Report — Max" → "Max's progress" / "Max — your season highlights") and the section headings ("Main category breakdown" → "How things are going" / "Top attributes"). The Player keepsake variant skips main categories with all-time average below 3.0 — no weak-spot callouts on a player's own keepsake.

Role gating: new `tt_generate_report` cap granted to head_dev + coach. Coaches are additionally per-team-scoped — `FrontendReportWizardView::canGenerateForPlayer()` walks coached teams. Players generating their own report pass the player-id check via `QueryHelpers::get_player_for_user()`.

Entry point: a "Generate report…" button on the player rate-card detail (`renderDetail`) for any user with `tt_generate_report`.

## Sprint 5 — Scout flow

The largest sprint of the epic. Two access paths plus persistence + audit:

### Schema

`tt_player_reports` (migration `0035_player_reports.php`) — id, player_id, generated_by, audience (`scout_emailed_link` | `scout_assigned_account`), config_json, rendered_html, access_token, scout_user_id, recipient_email, cover_message, expires_at, revoked_at, first_accessed_at, access_count, created_at. Indexed on player, token, scout, expiry. Idempotent on re-run.

### Path A — Emailed one-time link

Wizard surfaces a Scout delivery block when audience = Scout (gated on `tt_generate_scout_report`). Recipient email + 7/14/30-day expiry + optional cover message. On Preview-with-send-checked: `ScoutDelivery::emailLink()` generates a 64-char token, base64-inlines photos via `PhotoInliner`, persists the rendered HTML, and `wp_mail`s the link.

`ScoutLinkRouter` intercepts `?tt_scout_token=…` on `template_redirect`, validates against `tt_player_reports`, increments access_count + sets first_accessed_at, and emits the stored HTML in a chrome-free standalone document with a confidential watermark for the recipient. Expired / revoked / unknown tokens land on a clean boundary page.

### Path B — Assigned scout account

`FrontendScoutAccessView` (HoD-only, `?tt_view=scout-access`) lists every WP user with the `tt_scout` role and lets HoD assign players to each. Assignments live in user-meta `tt_scout_player_ids` (JSON array of int) — simpler than a separate table for v1.

`FrontendScoutMyPlayersView` (`?tt_view=scout-my-players`) is the scout-side "My players" list. Each click renders the player's scout-audience report inline and writes an audit row with `audience='scout_assigned_account'`. Scout user can never see un-assigned players (capability + assignment check on every render).

### Audit + revoke

`FrontendScoutHistoryView` (`?tt_view=scout-history`) lists every persisted report — player, recipient, audience, sent date, expiry, status (Active / Expired / Revoked), access count. Revoke action on active emailed-link rows sets `revoked_at = NOW()`.

### Roles

`tt_readonly_observer` was already registered (the spec's "missing observer role" was already fixed in earlier work). `tt_scout` was already registered too. New caps added in `RolesService::REPORT_CAPS`: `tt_generate_report`, `tt_generate_scout_report`, `tt_view_scout_assignments`. Wired into the role merge so an existing install picks up the new caps on the next `runMigrations()`.

## Files of note

- `src/Modules/Reports/ReportConfig.php`, `PrivacySettings.php`, `AudienceType.php`, `AudienceDefaults.php` — value objects.
- `src/Modules/Reports/PlayerReportRenderer.php` — the configurable renderer.
- `src/Modules/Reports/ScoutReportsRepository.php`, `ScoutDelivery.php`, `ScoutLinkRouter.php`, `PhotoInliner.php` — scout flow.
- `src/Modules/Reports/Frontend/FrontendScoutAccessView.php`, `FrontendScoutHistoryView.php`, `FrontendScoutMyPlayersView.php` — scout admin + side.
- `src/Shared/Frontend/FrontendReportWizardView.php` — the wizard.
- `src/Shared/Frontend/DashboardShortcode.php` — new dispatcher entry for `report-wizard`, `scout-access`, `scout-history`, `scout-my-players`.
- `src/Modules/Stats/Admin/PlayerReportView.php` — reduced to a thin shim that delegates to the renderer.
- `database/migrations/0035_player_reports.php` — new table.
- `src/Infrastructure/Security/RolesService.php` — three new report caps + grants.

## Out of scope (not regressed)

- **PDF generation** — HTML-print stays the output, browser print dialog handles "Save as PDF". (Existing PrintRouter html2canvas+jsPDF download path retained.)
- **Bulk report generation** — one player per generation.
- **Scout messaging back to club** — one-way information flow.
- **Real-time access notifications** — audit table records every view; no push.
- **Two-factor auth for scout accounts** — uses WP's standard auth.
- **Saved wizard presets** — single-shot generation; presets deferred to a future iteration if demand emerges.

---

# TalentTrack v3.39.0 — Authorization matrix is trustworthy: scope-aware bridge + complete seed (#0033 follow-up)

After v3.35.0 + v3.36.0 + v3.37.0 the matrix was active in production but didn't behave the way the matrix admin UI suggested it should. Three latent issues compounded:

1. **The `user_has_cap` bridge always asked the matrix at `SCOPE_GLOBAL`.** Any persona whose grant was at `team` / `player` / `self` scope (head_coach, team_manager, scout, parent, player) silently failed every legacy `tt_*` cap check — tiles vanished, REST routes 403'd, admin-page entries unregistered. The matrix view kept showing the green ticks at team scope, but the runtime ignored them.
2. **Academy admin's seed was incomplete.** Six entities the legacy cap vocabulary points at were missing: `frontend_admin`, `settings`, `workflow_tasks`, `tasks_dashboard`, `workflow_templates`, `dev_ideas`. With matrix active, the WP `administrator` lost the Configuration / Migrations / Audit log / Open wp-admin tiles + every wp-admin sidebar entry that gates on `tt_view_settings` / `tt_edit_settings` / `tt_access_frontend_admin`.
3. **Two entity names were inconsistent between the cap mapper and the seed.** `tt_manage_functional_roles` mapped to entity `functional_roles` but the seed used `functional_role_assignments`. Same for `tt_manage_backups` → `backups` vs seed's `backup`. Whichever name the admin clicked in the matrix grid couldn't satisfy the cap check; the other half of the bridge looked at the other key.

This release closes all three. After updating, the matrix UI is the authoritative description of what each persona can do — **R + C + D ticked at any scope = the role has full access on that entity**, scope-permitting. No scope-aware mental gymnastics required to read the grid.

## What changed

### Scope-aware bridge — `MatrixGate::canAnyScope()`

New helper that evaluates "does the user have this access at any scope they hold an assignment for?" rather than the global-only check the bridge previously made:

- `global`  → grants regardless of any other state.
- `team`    → grants when the user has at least one assignment in `tt_user_role_scopes` (active per `start_date` / `end_date`).
- `player`  → grants when the user is a player themselves or a linked parent via `tt_player_parents`.
- `self`    → always grants for the asking user.

`LegacyCapMapper::evaluate()` and `PreviewPage::computeDiff()` both route through `canAnyScope` now, so the cap-bridge AND the migration-preview diff stop over-reporting "Revoked" for team-scoped permissions that work fine in practice. The `user_has_cap` filter callback is unchanged at the WP level — only the predicate it asks the matrix.

### Seed completion

`config/authorization_seed.php` adds the missing rows:

| Persona | Added rows |
|---|---|
| `academy_admin` | `frontend_admin [r global]`, `settings [rcd global]`, `workflow_tasks [r self]`, `tasks_dashboard [r global]`, `workflow_templates [rcd global]`, `dev_ideas [rcd global]` |
| `head_of_development` | same six |
| `head_coach` / `assistant_coach` / `team_manager` | `workflow_tasks [r self]`, `frontend_admin [r global]`, `dev_ideas [c global]` |
| `scout` | `workflow_tasks [r self]`, `frontend_admin [r global]`, `dev_ideas [c global]` |

### Entity-name alignment

- `tt_manage_functional_roles` now maps to entity `functional_role_assignments` (matches the seed).
- `tt_manage_backups` now maps to entity `backup` (matches the seed; aligns with the singular naming used for every other domain table).

### Migration

`database/migrations/0035_authorization_seed_backfill.php` — `INSERT IGNORE`s every seed row into `tt_authorization_matrix` for existing installs. Admin edits to existing rows are preserved (the unique key `(persona, entity, activity, scope_kind)` makes the insert a no-op for any row already present); only the genuinely missing rows are added. Idempotent, safe to re-run.

## Effect

For an `administrator` user:
- Configuration, Migrations, Audit log, Open wp-admin frontend tiles return.
- The full wp-admin sidebar returns (Configuration, Custom Fields, Eval Categories, Reports, Rate Cards, Roles, etc.).
- The Application KPIs tile in Analytics returns.

For a `tt_coach` (head or assistant) with team assignments:
- All coaching tiles + admin pages return because team-scoped grants now satisfy the bridge.
- The "My tasks" inbox tile shows up (workflow_tasks at self scope).
- The "Submit an idea" tile shows up.

For `tt_player` / `tt_parent` / `tt_scout` / `tt_team_manager`:
- Legacy cap checks no longer require a global-scope row that was never going to exist for these personas. Their team / player / self rows now grant the matching legacy caps directly.

The migration preview's "Revoked" column now reflects only genuine seed gaps, not scope mismatches. If the column is empty after this release, the matrix is genuinely matching the previous-cap world.

## Files

- `src/Modules/Authorization/MatrixGate.php` — new `canAnyScope()` + `userHasAnyScope()` helper.
- `src/Modules/Authorization/LegacyCapMapper.php` — bridge predicate + entity-name alignment.
- `src/Modules/Authorization/Admin/PreviewPage.php` — diff predicate aligned.
- `config/authorization_seed.php` — new entity rows for six personas.
- `database/migrations/0035_authorization_seed_backfill.php` — idempotent backfill for existing installs.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — release.

---

# TalentTrack v3.38.0 — My profile rebuild (#0014 Sprint 2)

The player-facing **My profile** view, previously functional-but-spartan (avatar + definition list + WP edit-account button), is rebuilt as a six-section dashboard. A player opening it now sees their FIFA card, their recent rating trajectory, what they're working on, what's coming up, and their account — all on one screen.

## What changed

### `FrontendMyProfileView` rewrite

Previously: ~110 lines, all inline-styled, two cards (Playing details + Account).

Now: six sections, each with a purpose-built render method, all styles in CSS:

- **Hero strip** — photo (or initials placeholder), name, team and age group, jersey number, plus the FIFA-style player card embedded via `PlayerCardView::renderCard('sm', show_tier=true)`. Same renderer the rate-card page uses, no parallel implementation.
- **Playing details** — same fields as before (team, age group, positions, foot, jersey, height, weight, DOB), wrapped in a clean definition list. Coach-maintained-by note retained.
- **Recent performance** — rolling rating average over the last 5 evaluations (matching `PlayerStatsService::getHeadlineNumbers`'s rolling window), a trend arrow (up / down / flat / insufficient), a 240×56 inline-SVG sparkline of the last 10 overall ratings, and a meta line ("Rolling average over your last N evaluations. M total recorded."). 0.15 dead-zone for trend matches the rate-card breakdown logic.
- **Active goals** — top three goals with status NOT IN (completed, cancelled), ordered by due date ascending (NULL last). Each row shows title, due date, and a priority pill. "See all N goals" link when more than three exist.
- **Upcoming** — next three team activities with `session_date >= today`, archive-aware. Date / title / location per row, "See all sessions" link to the My sessions view.
- **Account** — display name, email, "Edit account settings" button. Unchanged from before.

### CSS extraction

New file `assets/css/frontend-profile.css`. All profile-specific styles live here; consumes the spacing tokens from `frontend-admin.css`. Responsive at three breakpoints (≥960px desktop, 640–959px tablet, <640px mobile). The FIFA card scales cleanly on phones because it owns its own size variants.

### Data sources

No new tables, no new repositories. Everything uses existing services:

- `PlayerStatsService::getHeadlineNumbers` for the rolling rating.
- `EvalRatingsRepository::overallRatingsForEvaluations` for the sparkline points.
- Direct queries against `tt_goals` (with archived + status guards) for active goals.
- Direct queries against `tt_activities` (with archived + future-date guard) for upcoming.
- `PlayerCardView::renderCard` for the embedded FIFA card.
- `LookupTranslator::byTypeAndName` for the translated foot label, `LabelTranslator::goalPriority` for priority chips.

### Empty states

Every section has a deliberate empty state for newly-rostered players:

- No evaluations: "No evaluations yet — your first review will appear here once your coach completes one."
- No goals: "No active goals right now. Your coach will set new ones during the next review."
- No team: "You're not on a team yet, so there's nothing scheduled."
- No upcoming on a team with one: "Nothing on the calendar in the next few weeks."
- No photo: initials placeholder in a navy circle.

## Files of note

- `src/Shared/Frontend/FrontendMyProfileView.php` — full rewrite, signature stable.
- `assets/css/frontend-profile.css` — new.
- `docs/player-dashboard.md` + `docs/nl_NL/player-dashboard.md` — My profile section rewritten to describe the new layout.

## Out of scope (rest of the #0014 epic)

- **Sprint 3** — generalize `PlayerReportView` into a configurable renderer. Plumbing-only, no new output.
- **Sprint 4** — report wizard + three audience templates (parent monthly / internal detailed / player personal).
- **Sprint 5** — scout flow (new `tt_scout` role, emailed one-time links, persisted scout-reports table).

---

# TalentTrack v3.36.1 — Hotfix: frontend dashboard tiles disappeared after v3.36.0 (#0033 finalisation regression)

v3.36.0 introduced a regression where every frontend dashboard tile except "Open wp-admin" silently disappeared. Admins and players alike saw an empty (or near-empty) tile grid after updating.

## Cause

`TileRegistry::register()` predates this work and gates registrations on `empty($tile['slug'])` — the original Sprint 4 design used `slug` as the tile identifier. The new `CoreSurfaceRegistration` seed file uses `view_slug` (the `tt_view=` route) as the per-tile identifier and doesn't pass an explicit `slug`, so every seeded tile was silently dropped by the empty-slug check. The "Open wp-admin" tile happened to set `slug` explicitly (because it has no `view_slug`) and was the only one that survived.

## Fix

`TileRegistry::register()` now falls back to `view_slug` as the tile's unique slug when no explicit `slug` is given. One-line change. Restores every tile that was missing.

## Files

- `src/Shared/Tiles/TileRegistry.php` — slug fallback.
- `talenttrack.php`, `readme.txt`, `CHANGES.md` — release.

---

# TalentTrack v3.36.0 — #0033 finalisation: every tile + admin-menu surface comes from a registry (#0033)

Closes the long-standing #0033 Sprint 4 acceptance criterion: *"Every tile rendered on admin + frontend comes from `TileRegistry::tilesForUser()`. No tile literals remain in `Menu.php` or `FrontendTileGrid.php`."*

v3.35.0 plugged the bug (disabled modules now actually disappear from the UI) via a centralised `ModuleSurfaceMap` lookup. This release re-homes the underlying tile + menu data into proper registries that the renderers iterate, retiring `ModuleSurfaceMap` along the way.

## What changed

### Two registries are now the source of truth

- **`TileRegistry`** — extended with `module_class`, `view_slug`, `cap_callback`, `label_callback`, `color_callback`, `url_callback`, plus a new `tilesForUserGrouped()` that returns groups in registration order with all dynamic fields resolved per-user. Disabled modules' tiles are filtered out at read time. A new `registerSlugOwnership()` covers tile-less sub-views (Configuration tile-landing's children, hidden drill-downs).
- **`AdminMenuRegistry`** — new, parallel registry for the wp-admin sidebar. Manages submenu pages, separator rows, and dashboard quick-link tiles. Separators auto-hide when their group has no visible children. Dashboard tiles inherit the same module-enabled filter.

### One declarative seed file

- **`CoreSurfaceRegistration`** — single PHP class that calls `TileRegistry::register()` and `AdminMenuRegistry::register()` for every shipped frontend tile, wp-admin sidebar page, and wp-admin dashboard tile. Tagged with `module_class` so the registries' built-in `ModuleRegistry::isEnabled` filter does its work. Called from `Kernel::boot()` after `bootAll()`.

### Renderers slimmed down

- **`FrontendTileGrid::buildGroups()` deleted.** ~370 lines of tile literals gone. `render()` now reads from `TileRegistry::tilesForUserGrouped()`, computes URLs from each tile's `view_slug` against the request's base URL, and paints the markup.
- **`Menu::register()` is 6 lines.** Top-level `add_menu_page` plus `AdminMenuRegistry::applyAll()`. ~110 lines of `add_submenu_page` literals + a custom `addSubmenu()` helper + the `addSeparator()` method gone (separator emission is registry-owned now).
- **`Menu::dashboard()` tile groups deleted.** ~80 lines of grouped-tile literals gone. The dashboard now reads from `AdminMenuRegistry::dashboardTilesForUser()`. Stat cards stay in place — they couple to entity-specific COUNT/delta queries, which remain a poor fit for a generic registry.

### Friendly behaviour preserved

- Disabled-module direct-URL hits (`?tt_view=evaluations` after Evaluations is toggled off) continue to render the v3.35.0 "this section is currently unavailable" notice. The lookup is now `TileRegistry::isViewSlugDisabled()` instead of the retired `ModuleSurfaceMap::isViewSlugDisabled()`.
- Always-on modules (Auth, Configuration, Authorization) pass through unconditionally — their surfaces always render.

### `ModuleSurfaceMap` retired

`src/Core/ModuleSurfaceMap.php` deleted. Every call site now consults the corresponding registry's lookup helper. Net code change is ~−500 lines despite adding two new registries + one provider class — the literals' size dwarfed the bookkeeping.

## Files

**New**
- `src/Shared/Admin/AdminMenuRegistry.php` — submenu + dashboard-tile registry.
- `src/Shared/CoreSurfaceRegistration.php` — single declarative seed.
- `specs/0033-finalization-tile-and-menu-registries.md` — sub-spec capturing the closure work.

**Modified**
- `src/Shared/Tiles/TileRegistry.php` — module-aware filtering + `tilesForUserGrouped()` + `registerSlugOwnership()`.
- `src/Shared/Frontend/FrontendTileGrid.php` — `buildGroups()` deleted, `render()` registry-driven.
- `src/Shared/Frontend/DashboardShortcode.php` — dispatcher gate now uses `TileRegistry::isViewSlugDisabled`.
- `src/Shared/Admin/Menu.php` — `register()` collapses to `AdminMenuRegistry::applyAll()`; `dashboard()` tile groups registry-driven; `addSubmenu`/`addSeparator` helpers retired; stat-card gate routes via the new registry.
- `src/Shared/Admin/MenuExtension.php` — `tt-migrations` gate uses `AdminMenuRegistry::isAdminSlugDisabled`.
- `src/Core/Kernel.php` — calls `CoreSurfaceRegistration::register()` after module boot.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — release.

**Deleted**
- `src/Core/ModuleSurfaceMap.php` — superseded by registry lookups.

## Behaviour parity

The migration is intentionally a refactor, not a feature change. The v3.34.0 dashboard, the v3.35.0 module-disabled gating, and every existing capability check render identically. The acceptance test is "no behavioural diff" plus "no tile literals in `Menu.php` or `FrontendTileGrid.php`".

## Out of scope

- Persona-aware tile labels (`HIDDEN` marker, per-persona alt labels). The infrastructure was already in `TileRegistry` since #0033 Sprint 4; no shipped tile uses it yet, and this release doesn't add the first one.
- Dashboard stat cards. Their per-entity COUNT + delta queries make a generic registry awkward; they stay literal in `Menu::dashboard()` with the same module-enabled gate (now via `AdminMenuRegistry`).

---

# TalentTrack v3.35.0 — Module surface gating: disabled modules disappear from frontend tiles, wp-admin menu, and view dispatch (#0051)

The "Modules" admin page (Authorization → Modules) lets administrators turn non-core modules off. Until now the toggle correctly stopped the module from booting (REST routes, hooks, capabilities all went dark) but the surfaces a user actually sees — frontend tiles, wp-admin sidebar entries, wp-admin dashboard tiles — were unaware of the toggle and kept rendering. Direct URLs to disabled modules' views still resolved.

This release closes that gap. One central source of truth (`ModuleSurfaceMap`) declares which module owns which `tt_view=<slug>` and `?page=<slug>` surface; three rendering layers consult it.

## What changed

### Frontend dashboard

- `FrontendTileGrid::renderGroups()` now drops every tile whose owning module is currently disabled. The slug is parsed from the tile's URL; tiles without a `tt_view=` (e.g. "Open wp-admin") are never gated.
- `DashboardShortcode::render()` short-circuits before dispatching: a `?tt_view=<slug>` for a disabled module's view now renders a friendly **"This section is currently unavailable"** notice with a back button, rather than dispatching to a view whose backing module never booted.

### wp-admin sidebar

- `Menu::register()` routes every `add_submenu_page()` call through a new `addSubmenu()` helper that consults `ModuleSurfaceMap`. Slugs owned by a disabled module skip registration entirely — the menu item disappears AND the URL stops resolving.
- The same gate covers `MenuExtension`'s Migrations submenu (always-on owner today, kept consistent for future-proofing).

### wp-admin dashboard

- `Menu::dashboard()` filters its 5 stat cards and grouped tiles by the same lookup. Players / Teams / Evaluations / Activities / Goals stat cards hide when their module is off; group tiles do too.

### Single source of truth

`src/Core/ModuleSurfaceMap.php` is one declarative file: two arrays mapping `tt_view` slugs and `?page=` slugs to fully-qualified module class names, plus a one-prefix family for the methodology edit pages. Both lookups return `null` for surfaces that aren't gated by any single module (player-personal landings, audit log, infra). All call sites combine the lookup with `ModuleRegistry::isEnabled()` — always-on modules pass through unconditionally.

## Behaviour

- **Disable Methodology** → frontend "Methodology" tile gone, wp-admin Methodology + Voetbalhandelingen + 8 hidden edit subpages all unregistered, REST stays dark, direct URL `?page=tt-methodology` no longer resolves.
- **Disable Workflow** → "My tasks" / "Tasks dashboard" / "Workflow templates" tiles gone, dispatcher refuses those slugs with the friendly notice.
- **Disable Team Development** → "Team chemistry" tile gone, dispatcher refuses `?tt_view=team-chemistry`.
- **Always-on (Auth, Configuration, Authorization)** can't be disabled at the data layer (`ModuleRegistry::setEnabled` refuses), and even if a row got hand-edited, `isEnabled` still returns true. Their surfaces always render.

## Translations + docs

- `languages/talenttrack-nl_NL.po` — two new strings (notice title + body) translated to Dutch. Already in main from the same author since the previous release; this PR re-confirms them.
- `docs/modules.md` + `docs/nl_NL/modules.md` — "What the toggle actually does" section expanded to enumerate each affected surface and the friendly-notice behaviour.

## Out of scope

- Full `TileRegistry` migration — every tile literal moving to per-module `boot()` calls — stays a future cleanup tracked under #0033 follow-ups. The map approach buys correctness now without paying that migration tax.
- Module dependency graph (e.g. "disabling Players auto-disables Teams") — `ModulesPage` already warns that dependencies aren't enforced; that's a separate task.

## Files changed

- New: `src/Core/ModuleSurfaceMap.php`, `specs/0051-feat-module-surface-gating.md`
- Modified: `src/Shared/Frontend/FrontendTileGrid.php`, `src/Shared/Frontend/DashboardShortcode.php`, `src/Shared/Admin/Menu.php`, `src/Shared/Admin/MenuExtension.php`, `docs/modules.md`, `docs/nl_NL/modules.md`, `readme.txt` (stable tag bump 3.33 → 3.35), `talenttrack.php`, `SEQUENCE.md`.

---

# TalentTrack v3.33.0 — Activity Type is lookup-driven, with per-type workflow policy (#0050)

The Activity Type dropdown was the last entity in TalentTrack with a hardcoded set of values. This release lifts it into the same lookup-driven pattern that Game Subtype, Attendance Status, and Position already use — and goes one step further: each row picks which workflow template fires when an activity of that type is saved.

## What changed

### Configuration → Activity Types

A new tab under the Configuration tile-landing's **Lookups & reference data** group. Three rows ship seeded:

- **Training** — locked, no workflow on save.
- **Game** — locked, fires the post-game evaluation template.
- **Other** — locked, no workflow on save.

Locked means the seeded rows can't be deleted (a 🔒 badge appears, the Delete link disappears, direct-URL deletion returns 403). Admins can rename them, translate them per locale, change the workflow-template selection, and add new types alongside.

Game Subtypes also gets its own tab while we're here — it was already lookup-driven but only reachable indirectly through the activity edit form's hint.

### Per-type workflow policy

Each Activity Type row carries a **Workflow template on save** select — the dropdown lists every registered workflow template by its display name. Pick one and saving an activity of that type fans the template out per active player on the team. Pick none and saving creates no task. Adding a "Tournament" type and pointing it at the post-game evaluation template, for instance, is now a configuration change instead of a code change.

The post-game evaluation template's hardcoded `if ($type !== 'game') return [];` is gone. `expandTrigger()` reads the activity's type lookup row, pulls `meta.workflow_template_slug`, and only fans out when the slug matches its own KEY. The existing seed (`game → post_game_evaluation`) preserves the historical behaviour.

### HoD quarterly rollup

The 90-day activity volume in the Quarterly HoD Review form switched from a hardcoded Games / Trainings / Other split to a `GROUP BY activity_type_key`. Each active type appears as its own row, ordered by the lookup's sort_order, with translated labels. Admin-added types appear automatically. Orphan rows (an activity row whose key no longer matches any lookup row) get a literal-key bucket so totals reconcile.

### Activity forms

Both the wp-admin and frontend Activity create / edit forms read Type options from the lookup. Conditional Game-subtype and Other-label rows stay anchored to the seeded `game` and `other` keys, so admin-added types behave like neither (no subtype, no other-label) until per-type form policy is added.

### REST validation strict-mode

`POST /activities` and `PUT /activities/{id}` validate `activity_type_key` against the live lookup. Unknown values return HTTP 400 with `code=bad_activity_type` and an `allowed` field listing the valid names. Empty values still fall back to the seeded `training` for back-compat with old clients that omit the field. wp-admin path stays lenient (silent fallback) — direct-URL submissions there mostly come from older bookmarks, not script callers.

## Files of note

- `database/migrations/0033_activity_type_lookup.php` — new, idempotent.
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — new tabs + tiles + lookup edit form gains workflow-template field + locked-delete.
- `src/Modules/Activities/Admin/ActivitiesPage.php` — wp-admin form reads from lookup + lenient validation.
- `src/Shared/Frontend/FrontendActivitiesManageView.php` — frontend form reads from lookup.
- `src/Infrastructure/REST/ActivitiesRestController.php` — strict validation on REST.
- `src/Modules/Workflow/Templates/PostGameEvaluationTemplate.php` — per-type filter via lookup meta.
- `src/Modules/Workflow/Forms/QuarterlyHoDReviewForm.php` — per-type rollup rows.

## Out of scope (follow-up)

- **Per-type form policy** — admin-added types currently can't ask for a subtype dropdown or an other-label input. The two conditional rows stay anchored to `game` / `other` keys. A future spec could add a per-type "extra fields" config to the lookup row's meta.
- **Per-type workflow policy v2** — multiple templates per type, scheduled offsets per type, etc. Today each row picks one slug.
- **Locking via UI** — no admin-facing toggle to mark a row as `is_locked`. The flag is migration-set today; admins who want to lock a custom row would need direct DB access.

---

# TalentTrack v3.31.1 — Activity type field on frontend + demo-mode guest add fix (#0049)

Two related bugs surfaced together: the frontend activity form was missing the type field, and adding a guest in demo mode failed with a confusing "no longer exists" message.

## Bugs fixed

### Frontend activity form was missing the type dropdown

The wp-admin Activities form gained Type / Game subtype / Other label fields back in #0035 (sessions → activities rename, v3.24.0). The frontend equivalent was never updated, so the only way a coach could pick "Game" instead of the default "Training" was through wp-admin. Worse: every frontend-created activity defaulted to Training silently — so post-game evaluation tasks weren't being spawned for games created from the frontend.

The frontend form now matches the wp-admin version: Type dropdown (Training / Game / Other), conditional Game subtype dropdown when type is Game, conditional Other label input when type is Other. Game subtype options come from the `game_subtype` lookup so admins can rename or extend them in **Configuration → Game Subtypes**.

REST `extract()` now persists `activity_type_key`, `game_subtype_key`, and `other_label` from the request alongside the existing title / date / team / location / notes fields.

### "That activity no longer exists" after adding a guest in demo mode

Adding a guest to a freshly-saved activity while running in Demo mode would land on a page that read "That activity no longer exists." The activity *did* exist; the row was just being filtered out of the load query.

Root cause: the activity-create REST endpoint inserted into `tt_activities` but never tagged the row in `tt_demo_tags`. The frontend `loadSession()` applies a demo scope clause (`id IN (SELECT entity_id FROM tt_demo_tags WHERE entity_type = 'activity')` when Demo mode is ON), so freshly-created untagged rows were correctly considered "real" data and hidden from the demo viewer.

`create_session` now inserts a `tt_demo_tags` row with `batch_id='user-created'` whenever Demo mode is ON, so user-created activities behave like generator-created ones inside the demo sandbox.

## Out of scope

- Making the **Type** dropdown itself lookup-driven (admin-extensible). The storage column `activity_type_key` is enforced as one of `game` / `training` / `other` because downstream behavior switches on those values (post-game evaluation workflow only fires for `game`; HoD quarterly rollup splits Games / Trainings / Other). Lookup-driven types would need either careful key-vs-name separation or downstream behavior changes — a sprint-sized chunk, not a hotfix.

---

# TalentTrack v3.30.1 — User docs cleanup (#0048)

A small docs-only release that fixes a visible-comment bug and rewrites every user-tier documentation page in plain language.

## Bug fixed

Every documentation page rendered an HTML comment as literal text at the top — for example `<!-- audience: user -->` would appear visibly above the page title. The line-based markdown renderer fed the comment line through `esc_html` instead of recognising it as metadata. `Markdown::render()` now strips audience comments before tokenising.

## User docs rewrite

Every page tagged `audience: user` (or cross-cutting `user, admin`) was rewritten with a single principle: the reader is anyone using TalentTrack day-to-day, often a child or a parent. So the new copy:

- Drops version-history references (`v3.x`, `v3.24.0`, "since v2.21.0", …) — those belong in this CHANGES.md, not in the user-facing pages.
- Drops WordPress-specific terminology (`[talenttrack_dashboard] shortcode`, "WordPress user account", "wp-admin"). The product is just "the app" from the user's perspective.
- Drops internal technical names (database table names like `tt_players`, capability slugs like `tt_edit_evaluations`, column names like `is_guest`, controller class names, AJAX/REST plumbing).
- Drops references to features that aren't shipped or are wrong post-rename (e.g. "sessions" → "activities").

What stays: how to use each feature, what each tile does, how filters and statuses behave, what's private vs shared.

## Files touched

13 English pages and 12 Dutch pages: getting-started, coach-dashboard, player-dashboard, evaluations, activities, goals, reports, rate-cards, player-comparison, methodology, bulk-actions, printing-pdf, plus the docs index.

`docs/contributing.md` is dev-tier and stays unchanged.

---

# TalentTrack v3.29.0 — Dashboard regroup + Configuration tile-landing + i18n cleanup (#0040)

A bundled hygiene release: ten paper-cut UX + i18n issues surfaced in the 2026-04-27 review session, all small individually but mutually reinforcing. Single PR.

## What changed

### Configuration tile-landing

- Visiting `?page=tt-config` (no `?tab=` param) now renders a tile-grid landing instead of dropping straight into the first tab.
- Six groups: **Lookups & reference data**, **Branding & display**, **Authorization**, **System**, **Custom data**, **Players & bulk actions**. Each group's tiles drill into either an in-page tab (the historical 14 lookup + branding + system tabs) or an existing top-level admin page (Custom Fields, Evaluation Categories, Authorization Matrix, Modules, etc.) — one place to discover everything.
- Old `?page=tt-config&tab=<slug>` URLs still resolve. The tab strip is gone; from any tab the page-title gains a "← Configuration" back link.
- Filterable via a new `tt_config_tile_groups` filter so future modules can append tiles.

### Dashboard tile cleanup

- Pruned: Custom fields, Eval categories, Roles, and Import players tiles removed from the dashboard tile grid. Custom Fields + Eval Categories are reachable via the new Configuration landing. The Roles surface is admin-only and reached via the Matrix help icon.
- Methodology moved out of the **Performance** group into a new dedicated **Reference** group.
- "Players" tile re-labels to **My players** for non-admin users and uses a description matching the coach-team-scoped data path. Admins still see "Players" with the full academy roster.

### Players + Teams views — bulk import surfaces

- Bulk player import tile no longer in the dashboard People group.
- Players list page: an "Import from CSV" button next to "New player" (cap-gated on `tt_edit_players`).
- Teams list page: an "Import players from CSV" button next to "New team".
- Configuration tile-grid landing: a "Bulk player import" tile under the "Players & bulk actions" group.

### Activity-type filter

- Admin Activities page chip-strip ("All · Games · Trainings · Other") replaced with a `<select>` dropdown that submits on change.
- Storage column `activity_type_key` still enforces the three hardcoded keys (game / training / other); a future enhancement could move them to a lookup-driven set if academies want custom activity types.

### Attendance status translation

- The attendance dropdown in both wp-admin Activities form and the frontend Activities form now wraps the displayed name through `LabelTranslator::attendanceStatus( $name )`. The four shipped values (Present / Absent / Late / Excused) get translated via the .po file (Aanwezig / Afwezig / Te laat / Afgemeld in nl_NL). Admin-added values fall through to the literal name.

### Guest-add modal — i18n cleanup

- Modal had Dutch msgids (`__('Gast toevoegen')`, `__('Sluiten')`, …) — non-Dutch users would have seen literal Dutch. All msgids translated to English with Dutch translations in the .po.
- Two stray hardcoded Dutch strings in the activity Guests section ("Spelers van buiten de selectie." help line and "Nog geen gasten toegevoegd." empty-state) translated as well.
- The fuzzy player picker + team filter inside the modal — already shipped in v3.22.0 / #0037, so the spec line item ("confirm fuzzy is wired") was already true. No code change needed there.

### Authorization Matrix help icon

- Page title now carries a "? Help on this topic" link that deep-links to `docs/access-control.md`, matching the pattern on Configuration / Players / Activities.

### Deferred (need reproducer data)

- **#8 — Admin sees no activities, player does.** Will land once the user provides URL + DB count.
- **#12 — Workflow shipped templates not visible on the frontend.** Needs three diagnostic data points (template list state in `?tt_view=workflow-config`, contents of `tt_workflow_triggers`, state of `?tt_view=my-tasks`).

## Files of note

- `src/Modules/Configuration/Admin/ConfigurationPage.php` — tile-landing + 6-group definitions + per-tile capability filter.
- `src/Shared/Frontend/FrontendTileGrid.php` — tile prune + Reference group + conditional Players label.
- `src/Shared/Frontend/FrontendPlayersManageView.php` — "My players" header + empty state + Import from CSV button.
- `src/Shared/Frontend/FrontendTeamsManageView.php` — Import players from CSV button.
- `src/Modules/Activities/Admin/ActivitiesPage.php` — type-filter dropdown + LabelTranslator wrap on attendance.
- `src/Shared/Frontend/Components/GuestAddModal.php` — i18n cleanup.
- `src/Modules/Authorization/Admin/MatrixPage.php` — help-icon deep-link.
- `languages/talenttrack-nl_NL.po` — new Dutch msgstr block for the new strings.
- `docs/configuration-branding.md` + nl_NL — tile-grid landing documented.
- `docs/coach-dashboard.md` + nl_NL — My players + Reference group + bulk import button.

---

# TalentTrack v3.26.0 — #0033 authorization epic complete (sprints 6 / 7 / 8 / 9)

Closes the 9-sprint #0033 authorization epic that started in v3.24.0 (sprints 1+2) and continued through v3.25.0 (sprints 3+4+5). This release lands the final four sprints in a single PR.

The architecture is now in place: every `tt_*` capability check can route through a persona × entity × activity × scope matrix, modules can be toggled at runtime, the dashboard splits work tiles from setup tiles, and a multi-persona user gets a session-storage lens to view as one persona at a time. The dormant-by-default `tt_authorization_active` flag (default 0) keeps every behavior change opt-in until an admin clicks Apply on the migration preview.

## What changed

### Sprint 6 — `ConfigTabRegistry`

- New `TT\Modules\Configuration\Admin\ConfigTabRegistry` typed wrapper around the existing `tt_config_tabs` + `tt_config_tab_<key>` filter pattern (added in #0025).
- Modules call `register()` from their `boot()` instead of hooking the filter directly.
- Reads `ModuleRegistry::isEnabled()` so a disabled module's tabs disappear from the Configuration page sidebar.
- Migration of the 14 historically-hardcoded ConfigurationPage tabs is intentionally deferred — the registry coexists with the static switch block; per-module migration is opportunistic.

### Sprint 7 — New + refined roles

- New `tt_team_manager` WP role (Team Manager). Capabilities scoped to team-level coordination: read across team-scoped surfaces, edit activities, send invitations. NO `tt_evaluate_players`, no goal edit.
- New column `tt_team_people.is_head_coach`. Migration `0030` backfills `is_head_coach = 1` on the OLDEST coach assignment per team.
- `PersonaResolver::personasFor()` rewritten to split a `tt_coach` WP user into `head_coach` + `assistant_coach` personas based on the FR flag. A coach who head-coaches one team and assists another gets BOTH personas.
- Refined `tt_scout` capabilities to match the matrix-declared cross-team read intent.

### Sprint 8 — Migration preview + apply / rollback

- New `Authorization → Migration preview` admin page (administrator-only). Per-user diff: legacy capability result vs `MatrixGate::can()` result for every cap in `LegacyCapMapper::knownCaps()`. **Gained** = matrix grants something legacy didn't. **Revoked** = the dangerous column.
- Downloadable CSV.
- **Apply matrix** button flips `tt_authorization_active` from 0 to 1. The user_has_cap filter from Sprint 2 wakes up; legacy `tt_*` cap checks route through MatrixGate. **Rollback** flips it back. One-click reversibility.

### Sprint 9 — Docs + i18n

- New `docs/authorization-matrix.md` + `docs/nl_NL/authorization-matrix.md` — admin guide for the matrix editor.
- New `docs/modules.md` + `docs/nl_NL/modules.md` — per-module toggle UX, always-on modules, License pre-launch caveat, dependency graph status.
- `HelpTopics` adds `authorization-matrix` + `modules` topics in the access-control group.
- ~50 new strings translated in `nl_NL.po`.

## #0033 epic — final tally

| Sprint | Spec | Actual | Release |
| - | - | - | - |
| 1 — Schema + matrix gate read API | ~10h | (pre-bundle) | v3.24.0 |
| 2 — Migration + WP cap compat (dormant bridge) | ~8h | ~3h | v3.24.0 |
| 3+4+5 — Matrix UI + TileRegistry + Modules toggles | ~38h | ~6h | v3.25.0 |
| 6+7+8+9 — Config registry + roles + preview + docs | ~34h | ~5h | **v3.26.0** |
| **Total** | **~90h** | **~14h** | — |

**~1/6.4 ratio** — the best compression on the project to date. Single-PR sprint bundling, dormant-by-default safety net, and the CI grep gate from #0035 all hold up under the aggressive bundling.

## Deferred follow-ups (not blocking)

- Per-module migration of the 14 hardcoded ConfigurationPage tabs to `ConfigTabRegistry::register()` calls (opportunistic).
- Per-module migration of static tile literals to `TileRegistry::register()` calls (opportunistic).
- 16 persona-quickstart docs (8 personas × 2 languages).
- Per-team customization of personas (out-of-scope per spec).

The architecture is in place; the user-facing features (matrix editor, module toggles, migration preview, apply / rollback, persona switcher, work / setup dashboard split) all work.

## Ship-along

- Plugin version + readme.txt stable tag → 3.26.0.
- SEQUENCE.md flips #0033 from In progress → Done.

---


# TalentTrack v3.23.0 — Multilingual auto-translate (#0025)

Single-feature minor release. Adds an opt-in render-time translation cache for user-entered free text — goal titles, evaluation notes, session descriptions, attendance notes. **Default OFF**: no API calls, no transmission of source text until an admin opts in via Configuration → Translations and confirms the engine acts as a GDPR Article 28 sub-processor.

## What changed

### Schema (migration 0021)

- `tt_translations_cache` — render-time lookup keyed on (source_hash, source_lang, target_lang, engine).
- `tt_translations_usage` — per-month, per-engine character counters for the soft cost cap.
- `tt_translation_source_meta` — per-source-string detected language so re-saves of unchanged text dont pay for a re-detection.

### Engines

- `TranslationEngineInterface` — the contract third-party engines implement.
- `DeepLEngine` — REST adapter; auto-routes free-tier (`:fx` keys) to `api-free.deepl.com` and paid keys to `api.deepl.com`.
- `GoogleTranslateEngine` — Cloud Translation v3, hand-rolled service-account JWT exchange (no Composer dep on `google/auth`).
- `tt_translation_engine_factory` filter for slotting in additional engines without modifying core.

### Service

- `TranslationLayer::render()` — cache-first translate with short-circuits when disabled, when source = target, when the user prefers the original, when the cap is hit, or when both engines fail.
- `detectAndCache()` — wired into `GoalsRestController` + `SessionsRestController` save paths so source-meta is populated at write time, not read time.
- `invalidateSource()` — called implicitly on content change inside `detectAndCache`.
- `purgeAllCaches()` — invoked on opt-out per the GDPR posture.

### Admin surfaces

- **Configuration → Translations** tab with opt-in flow (Article 28 confirmation + credentials), engine + fallback selection, monthly character cap, threshold percentage, sub-processor disclosure block, usage-this-month table, clear-cache button. Saving with Enable=ON refuses without the subprocessor checkbox or without engine credentials.
- **CapThresholdNotice** — persistent dashboard banner. Warning tone (dismissible) at threshold; error tone (persistent, "Raise the cap →" link) at 100%.
- **UserProfilePreference** — radio on the wp-admin profile screen for `tt_translation_pref` (translated / original / side-by-side). Side-by-side renders `[translated] (original: [source])`.

### Privacy

- `TranslationsModule::registerPrivacyContent()` appends a sub-processor paragraph to the WP privacy-policy editor whenever the layer is enabled. Disabling stops appending; the cache and source-meta truncate.

### Inventory wired

- `FrontendMyGoalsView`, `FrontendMySessionsView`, `PlayerDashboardView` (goals + attendance) render through `TranslationLayer::render()`.
- `GoalsRestController` + `SessionsRestController` call `detectAndCache()` on title, description, notes for create + update.
- Other free-text surfaces (custom fields, evaluation notes) layer in cleanly via the same wrap pattern.

### Extensibility

- `ConfigurationPage` gains a `tt_config_tabs` filter + `tt_config_tab_<key>` action so future modules register tabs without editing core.

### Ship-along

- `nl_NL.po` extended with all new admin + user-pref strings.
- `docs/translations.md` (audience: admin) + `docs/nl_NL/translations.md` cover opt-in, costs, GDPR, troubleshooting.
- HelpTopics registers the new `translations` slug under the Configuration group.
- `SEQUENCE.md` flips #0025 from In progress to Done.

## Versioning note

v3.23.0 is a clean single-feature release on top of v3.22.0s multi-PR bundle. The next deployment via PUC picks it up on its 12h poll, or force-check with `wp-admin → Dashboard → Updates → Check again`.

---


# TalentTrack v3.22.0 — Workflow & tasks engine + Development management + Guest-player attendance + Documentation split + Icon system

The biggest single release since v3.12.0. Eight PRs land together: two full-blown epics (#0022 workflow & tasks engine, Phase 1 complete in 5 sprints; #0009 development management, full epic in one PR), two shipped features (#0026 guest-player attendance; #0029 documentation split with audience markers), Casper's hand-authored icon system (#0034) replacing dashicons + emoji across the dashboard, and shaping captures for three new ideas.

## #0022 — Workflow & tasks engine, Phase 1 (PRs #54, #56, #57)

The orchestration layer that turns "we should evaluate after every match" into a scheduled, visible task with a deadline.

### Engine + schema (Sprint 1, PR #54)

- Migration `0021_workflow_engine` — `tt_workflow_tasks`, `tt_workflow_triggers`, `tt_workflow_template_config`, plus `parent_user_id` on `tt_players` for the minors-policy resolver.
- Public PHP API: `WorkflowModule::engine()->dispatch( $template_key, TaskContext )`.
- Contracts under `Contracts/`: `TaskTemplateInterface`, `FormInterface`, `AssigneeResolver`.
- Resolvers under `Resolvers/`: `RoleBasedResolver`, `TeamHeadCoachResolver`, `PlayerOrParentResolver` (reads `tt_workflow_minors_assignment_policy` from `tt_config` — four values: `direct_only`, `parent_proxy`, `direct_with_parent_visibility`, `age_based` default), `LambdaResolver`.
- Four reserved capabilities: `tt_view_own_tasks`, `tt_view_tasks_dashboard`, `tt_configure_workflow_templates`, `tt_manage_workflow_templates`.

### Inbox + dispatchers + email + cron diagnostic (Sprints 2-3, PR #56)

- `?tt_view=my-tasks` — every user with `tt_view_own_tasks` sees their inbox; the dashboard tile carries an open-count badge.
- `CronDispatcher` — hourly tick; `EventDispatcher` — subscribes to `event_hook` triggers on `init` priority 20.
- `TaskMailer` — fires on `tt_workflow_task_created`, sends `wp_mail` plain-text to assignee.
- `CronHealthNotice` — wp-admin banner when scheduled tasks are 24h overdue (per-user 7-day dismiss).
- Two shipped templates: post-match coach evaluation (manual trigger v1, 72h deadline, fans out per active player to head coach) and weekly player self-evaluation (cron `0 18 * * 0`, 7-day deadline, minors-policy aware routing).

### Goal-setting chain + HoD review + dashboard + admin config (Sprints 4-5, PR #57)

- Three more templates: quarterly goal-setting (player drafts up to 3 goals; on completion auto-spawns a goal-approval task for the coach via `parent_task_id`); goal-approval (chain-spawned only); quarterly HoD review (live-data form aggregating last 90 days of evaluations / sessions / goals / on-time task completion).
- `?tt_view=tasks-dashboard` — HoD overview with per-template + per-coach completion rates and currently-overdue list (top 25, color-coded).
- `?tt_view=workflow-config` — academy admin: per-template enable/disable, cadence + deadline overrides, minors-policy switch.
- Migration `0022_workflow_default_triggers` + `0023_workflow_quarterly_triggers` seed the cron triggers.

## #0009 — Development management (PR #59)

Submit ideas from inside the dashboard. Lead-dev approves and the plugin auto-commits to the `ideas/` folder on GitHub.

- Migration `0024_development_management` — `tt_dev_ideas` + `tt_dev_tracks` + four caps (`tt_submit_idea` granted to every TalentTrack role except `tt_player` and `tt_parent`; `tt_refine_idea` + `tt_view_dev_board` to admin / head-dev / club-admin; `tt_promote_idea` admin-only).
- `GitHubPromoter` — REST API client that lists `ideas/`, `specs/`, and `specs/shipped/`, allocates the next free `NNNN`, and `PUT`s `ideas/NNNN-<type>-<slug>.md` straight to `main` via `wp_remote_*()`. Auto-retries once on 422 (race), surfaces clear errors otherwise.
- `wp-config.php` constants: `TT_GITHUB_TOKEN` (required, fine-grained PAT with `Contents: Read & write`), `TT_IDEAS_REPO` (optional override), `TT_IDEAS_BASE_BRANCH` (optional).
- Five frontend views: Submit-an-idea form, Development board (kanban), per-card Refine, Approval queue (with disabled-when-no-token state + confirm modal on promote + retry section for failed promotions), and Development tracks.
- Author notifications via `wp_mail` on `rejected` / `promoted` / `in-progress` transitions.
- Goals integration — promoting an idea to `in-progress` with a tagged player auto-spawns a row in `tt_goals` linked to that player.
- Player-facing labels collapse internal states to four buckets: *Submitted* / *In review* / *Accepted* / *Not accepted*. `promoting` and `promotion-failed` never leak.
- New "Development" tile group in the dashboard tile grid; players + parents see nothing.

## #0026 — Guest-player attendance (PR #55)

First-class support for off-roster attendance: U-younger players promoted up for injury cover, players from another club doing a trial day, off-roster guests at friendlies. Coaches record their presence without polluting team statistics or the roster.

- Migration `0020_attendance_guests` — `tt_attendance` gains `is_guest`, `guest_player_id`, `guest_name`, `guest_age`, `guest_position`, `guest_notes` (all nullable). `player_id` relaxed to NULL. Index `idx_session_guest (session_id, is_guest)`.
- REST: `POST /sessions/{id}/guests` (linked or anonymous), `PATCH /attendance/{id}`, `DELETE /attendance/{id}`. Session `PUT` wipes only roster rows (`is_guest = 0`), so guests survive an edit.
- Frontend session edit page: new **Guests** section under the roster, **+ Add guest** modal with linked / anonymous tabs. Guest rows render distinctly (italic name, "Guest" badge). Linked guests have an **Evaluate** link; anonymous guests have inline-PATCHing notes + an **Add as player** promotion that pre-fills the player-create form and re-anchors the attendance row to the new id.
- Stats isolation: session-list `attendance_count` filters `is_guest = 0` so **Att. %** reflects roster turnout only.
- Player profile: Attendance tab + **My sessions** view match on either `player_id` or `guest_player_id`; guest visits show an **(as guest)** marker.
- New `GuestAddModal` component, extended `PlayerPickerComponent` with `cross_team` + `exclude_team_id` modes, new `assets/js/components/guest-add.js`.

## #0029 — Documentation split (PR #58)

The wiki gains audience labels so users see only docs aimed at their role.

- Every `docs/*.md` and `docs/**/*.md` declares an `<!-- audience: user | admin | dev | user, admin -->` marker; CI fails the build if any doc is missing one.
- New `Documentation\AudienceResolver` filters the TOC sidebar by current user role; wp-admin docs page shows audience-tagged search + filter.
- New developer-tier docs (English-only by design): `docs/architecture.md`, `docs/contributing.md`, `docs/hooks-and-filters.md`, `docs/index.md`, `docs/rest-api.md`, `docs/theme-integration.md`.
- New "Developer" group in `HelpTopics::groups()` covering REST API reference, hooks & filters, architecture, theme integration.
- `.github/workflows/release.yml` gains a `docs-audience` job that runs on every PR.

## #0034 — Custom icon system (PR #60)

Casper's hand-authored SVG set replaces dashicons (admin) + emoji (frontend) across the dashboard tile grid and admin menu.

- New `assets/icons/<name>.svg` — 24×24 viewBox, single-path silhouettes, `fill="currentColor"` so existing tile accent colors drive the rendering.
- New `Shared\Icons\IconRenderer` helper inlines SVGs at render time with a static per-request cache.
- Both `Shared\Admin\Menu.php` and `Shared\Frontend\FrontendTileGrid.php` switch from `'icon' => 'dashicons-shield'` / `'emoji' => '🛡'` to `'icon' => 'teams'` lookups.
- Coverage is exactly the surfaces that ship today; future features add their icon at the time they ship. Dashicons stay in the codebase for WP-admin core surfaces outside our control.

## Idea backlog (PR #53)

Captured shaping decisions for three new ideas:

- **#0031** — Spond calendar integration. iCal-feed scope, poll frequency, write-back, match-vs-training detection still open.
- **#0032** — User invitation flow (player / parent / staff via shareable WhatsApp-friendly link). Trigger surfaces, token TTL, parent-role question (reuse `tt_player` or new `tt_parent`?) still open.
- **#0033** — Authorization review (8 personas × 3 activities × ~25 entities matrix). Storage model, multi-persona resolution, scope model, tile/menu rendering still open.

## Ship-along

- `languages/talenttrack-nl_NL.po` extended with all new strings across all eight PRs. `.mo` regenerated by the Translations workflow on each merge.
- `docs/workflow-engine.md`, `docs/workflow-engine-cron-setup.md`, `docs/development-management.md`, `docs/sessions.md` (extended), all with their `nl_NL` translations and audience markers.
- `HelpTopics` gains `workflow-engine`, `workflow-engine-cron-setup`, `development-management`, plus the developer-tier topics.
- `DEVOPS.md` gets a "Plugin constants in `wp-config.php`" section documenting the three #0009 constants + a link to the fine-grained PAT creation page.
- `SEQUENCE.md` flips #0026, #0022 (Phase 1), #0029, #0009, #0034 from Ready / Not started → Done; promotes #0028 from Blocked to Ready (#0022 Phase 1 unblocked it).

---


# TalentTrack v3.21.0 — Methodology framework expansion (#0027)

Major content release. The methodology module now ships as a full coaching framework — not just a principles + set-pieces + positions library, but a per-club methodology primer with phases, learning goals, factors of influence, and a football actions catalogue. Each entity supports illustrated diagrams via the WordPress media library, and the seed PNGs from the source PDF auto-attach as primary images.

## What changed

### Schema (migration 0017 + 0018 + 0019)

- Six new tables: `tt_methodology_assets` (polymorphic image attachments), `tt_methodology_framework_primers`, `tt_methodology_phases`, `tt_methodology_learning_goals`, `tt_methodology_influence_factors`, `tt_football_actions`.
- One column added: `tt_goals.linked_action_id` (sibling to `linked_principle_id`) — a goal can now link to a football action in addition to a principle.
- Migration 0018 seeds the full methodology content from the source document: vision row, 18 principles (AO/AS/OV/VS/VV/OA), 8 set pieces (4 attacking + 4 defending), 11 position cards on 1:4:2:3:1, framework primer, 8 phases (4 attacking + 4 defending), 10 learning goals, 7 influence factors, and 11 football actions.
- Migration 0019 copies the 66 PDF page PNGs into `wp-content/uploads/talenttrack-methodology/`, registers each as a WP attachment, and links each to the matching shipped entity as the primary asset.

### Admin

- New "Raamwerk" tab on the Methodology page (now the default) showing the primer body, illustrations, phases, learning goals and influence factors with Clone & Edit links.
- New visible Voetbalhandelingen page (TalentTrack → Voetbalhandelingen) listing all football actions grouped by category.
- New hidden edit pages: framework primer, phase, learning goal, influence factor, football action.
- Shared `MediaPicker` component on every methodology edit form: WordPress media library button, primary/archive/caption controls, multilingual captions.
- Goal edit gains an optional "Linked football action" select grouped by category.

### Frontend

- The Methodology view picks up the new "Raamwerk" tab as default and renders a polished primer landing: intro hero illustration, framework sections, phase cards (color-coded green for attacking, red for defending), learning goal cards with bullets, influence factor cards with sub-cards.
- Every detail view (principle / set piece / position / vision) now shows the entity primary image as a hero above the existing text + diagram.
- New Voetbalhandelingen tab grouped by category (with-ball, without-ball, supporting).

### Content

- Faithful translation of the source methodology document into the catalogue tables. Dutch is the source language with English translations on every shipped row.
- 66 PDF pages extracted as PNGs (150 DPI) and committed to `assets/methodology/seed/`. Auto-mapped to entities by migration 0019. Casper can replace any individual diagram via the admin picker; archive on the shipped one to keep your replacement.

### Migrations + ship-along

- Three new migrations (0017, 0018, 0019) — all idempotent; reruns are safe.
- `languages/talenttrack-nl_NL.po` extended with all the new methodology strings; `.mo` recompiled.
- `docs/methodology.md` + `docs/nl_NL/methodology.md` updated with the six-tab story, image upload workflow and goal→action linkage.
- `SEQUENCE.md` flips #0027 from "32h framework + content authoring TBD" to "framework + full PDF content + visuals + per-club primer + football actions, all delivered".

## Notes

- v3.20.0 was reserved for an in-flight UX polish bundle; this release jumps to v3.21.0 to avoid stepping on that release plan.
- The seed images are 23MB total (66 PNGs at 150 DPI). Acceptable one-time addition for a content release where the visuals are core to the feature.

---


# TalentTrack v3.12.0 — #0019 Sprint 6: Legacy UI toggle (closes the epic)

**Minor release.** Closes Sprint 6 of the #0019 frontend-first-admin epic and finishes the epic itself. The migration is complete: every TalentTrack admin surface now lives on the frontend, and the wp-admin menu entries for those migrated pages are hidden by default behind a per-site toggle.

~5h actual against ~8-10h spec — the existing `Menu::register()` already had the entire submenu set in one method, so the gate collapsed to a single `if`-block instead of the per-page wrapping the spec described.

## What changed

### `tt_show_legacy_menus` toggle

New config key (stored in the plugin's `tt_config` table — *not* `wp_options` — so it lives alongside every other TalentTrack setting). Default **off**. Direct URLs to legacy pages keep working regardless; the toggle only controls menu visibility.

### Menu suppression

Single chokepoint:
- `src/Shared/Admin/Menu.php` — wraps the migrated submenu block (Teams, Players, People, Evaluations, Sessions, Goals, Reports, Player Rate Cards, Player Comparison, Usage Statistics, Configuration, Custom Fields, Eval Categories, Category Weights, Roles & Permissions, Functional Roles, Permission Debug) in a single `if ( shouldShowLegacyMenus() )` gate.
- `src/Shared/Admin/MenuExtension.php` — same gate around the Migrations submenu.
- The parent **TalentTrack** menu point + **Help & Docs** submenu remain always visible. Help & Docs is the always-visible landmark for documentation; the parent links to the wp-admin Dashboard view which now carries a frontend-discovery splash.

### Toggle UI on **both** surfaces

Per Casper's session-feedback ("I expect a lot of stuff to be further improved on frontend and backend is therefore still needed"):

- **Frontend Configuration view** (`?tt_view=configuration`) — new "wp-admin menus" panel with the toggle.
- **wp-admin Configuration → Branding tab** — new "Legacy wp-admin menus" section with the same toggle.

Both write to the same `tt_config.show_legacy_menus` key. Saving from either surface flips menu visibility immediately on the next admin page load.

### wp-admin dashboard splash

When the toggle is OFF, the wp-admin TalentTrack Dashboard shows a top-of-page banner explaining the move with two buttons:
- **Open the frontend dashboard** — primary CTA, links to the public site.
- **Re-enable legacy menus (wp-admin Configuration)** — direct-URL link to `?page=tt-config` so admins who prefer the wp-admin path can flip the toggle without going to the frontend at all.

The grouped tile dashboard below still renders, so direct-URL navigation to any legacy page works.

### One-time upgrade notice

`src/Shared/Admin/UpgradeNotice.php` — admin notice on the first wp-admin load after upgrade. Cap-gated on `tt_access_frontend_admin` (administrator + tt_head_dev). Per-user dismissal via `_tt_upgrade_notice_v3_12_0_dismissed` user-meta. Versioned by the meta-key suffix so a future epic-equivalent change can ship a fresh notice without re-displaying this one.

Wired into the Kernel's `boot()` so the notice surfaces on every admin page load until each user dismisses it.

## Direct-URL fallback (always works)

Every legacy wp-admin page (`?page=tt-players`, `?page=tt-config`, etc.) keeps working when typed/bookmarked, regardless of toggle state. This is the emergency fallback if a head-dev hides menus and then needs to reach a legacy surface that hasn't been ported.

## Consistency cleanup

Did a 30-minute pass across the Sprints 2–5 frontend views. Findings:
- Headers ("New X" / "Edit X — name" / "X") consistent across all 7 manage views.
- Empty-state copy ("No X match your filters.") consistent across all `FrontendListTable` consumers; the lone exception is `FrontendCustomFieldsView` ("No custom fields defined yet.") which is intentional — it reads better when no entity-type filter is applied.
- `data-rest-path` / `data-rest-method` form attributes uniform.
- Cancel buttons present on every edit form.

No material drift; nothing that justified a refactor.

## Out of scope (intentionally deferred)

- **Removing legacy pages from the codebase entirely** — emergency fallback requirement keeps them.
- **Sprint 7 (PWA + offline + docs)** — separate sprint, future work.

## Sprint 6 — done. #0019 epic — done.

Per SEQUENCE.md, **Sprint 6 is COMPLETE and the entire #0019 frontend-first-admin epic is now closed**. Six sprints across ~73h actual run-time (well under the original ~120-150h estimate, mostly because the Sprint 4-6 wraps around existing repositories were thin).

Next epic on the queue: **#0014** (report generator rebuild) and **#0021** (audit log viewer). Sprint 7 of #0019 (PWA + offline + docs) is also on the docket as a clean-room follow-on.

# TalentTrack v3.11.0 — #0019 Sprint 5: Admin-tier surfaces on the frontend

**Minor release.** Closes Sprint 5 of the #0019 frontend-first-admin epic. Six wp-admin-only surfaces become first-class frontend tiles, gated by a new `tt_access_frontend_admin` capability. Single PR per Q8 in shaping. ~14h actual against ~28-32h spec — the existing repositories (`PeopleRepository`, `FunctionalRolesRepository`, `CustomFieldsRepository`, `EvalCategoriesRepository`, `MigrationRunner`, `UsageTracker`) carried most of the domain logic.

## New `tt_access_frontend_admin` capability

Added to `RolesService::roleDefinitions()` for `tt_head_dev` and to the `ensureCapabilities()` loop for the WP `administrator` role. Activation and existing installs both pick it up automatically (the migration runner re-runs `ensureCapabilities()` on every plugin upgrade).

## Administration tile group

The dashboard's "Administration" group used to be a single tile that punted to wp-admin. It now holds six first-class tiles plus the wp-admin link as a fallback escape hatch.

## The six surfaces

### Configuration — `?tt_view=configuration`
Branding (academy, logo, primary/secondary colors), the full #0023 theme-inheritance + curated styling fields, and rating-scale tuning. Logo upload via `wp_enqueue_media()` (Sprint 3 pattern). Saves through `POST /talenttrack/v1/config` — a new whitelisted-keys endpoint gated on `tt_edit_settings`. Lookup tables, evaluation types, feature toggles and the audit log link out to wp-admin (Q2 in shaping kept the port focused on the most-changed fields).

### Custom Fields — `?tt_view=custom-fields`
List via `FrontendListTable` with entity-type filter; create/edit/delete via separate `?action=new` / `?id=N` routes; up/down arrow reorder per the Sprint 4 pattern. Hidden `options[]` array is built from a textarea on submit so list-style fields (select, multi-select, checkbox-group) get one-option-per-line input. Delete is rejected with a `409 in_use` when stored values exist — operator is told to deactivate instead.

### Eval Categories — `?tt_view=eval-categories`
Hierarchical tree (main + sub) rendered via repository's `getTree()`. Per-level up/down arrow reorder. Delete is rejected if children exist or if any rating still references the category. Per-age-group weight editing (the v2.13.0 `CategoryWeightsPage` UI) keeps living in wp-admin — deep-link button at the top of the view.

### Roles — `?tt_view=roles`
Read-only reference panel for the eight TalentTrack roles. Read-Only Observer card is highlighted with a gold border + "often-missed" badge per the spec. Each card shows: role label + slug, plain-language description, count of users with the role (deep-link to the wp-admin Users list filtered by role), collapsible capabilities detail, "How to assign" inline note. Cap grant/revoke editing keeps living in the existing wp-admin `RolesPage` for Sprint 5 — deep-link surfaces it.

### Migrations — `?tt_view=migrations`
Read-only status. Lists applied + pending migrations. Pending migrations surface a prominent warning banner with a "Open wp-admin to run them" deep-link. Spec was emphatic about NOT exposing migration execution on the frontend (forced friction on irreversible operations is the right design); this view honors that.

### Usage Stats — `?tt_view=usage-stats`
Six headline KPI tiles (logins / active users at 7/30/90 day windows), DAU line chart, evaluations-per-day bar chart, and active-users-by-role table. Reuses Chart.js from the wp-admin page (Q7 in shaping). Drill-down detail pages (per-day user lists) link out to wp-admin.

## REST controllers

Three new + extensions to existing:

| Controller | Routes | Notes |
| --- | --- | --- |
| `ConfigRestController` | `GET/POST /config` | Whitelisted keys; `tt_edit_settings`. |
| `CustomFieldsRestController` | `GET/POST /custom-fields`, `PUT/DELETE /custom-fields/{id}`, `POST /custom-fields/{id}/move` | Wraps `CustomFieldsRepository`. Refuses delete when values exist. |
| `EvalCategoriesRestController` | `GET/POST /eval-categories`, `PUT/DELETE /eval-categories/{id}`, `POST /eval-categories/{id}/move` | Wraps `EvalCategoriesRepository`. Refuses delete with children or ratings. |

Registered in `ConfigurationModule::boot()` (Config + CustomFields) and `EvaluationsModule::boot()` (EvalCategories). Existing PeopleModule + AuthorizationModule stay unchanged from Sprint 4.

## Wired up

- `tt_access_frontend_admin` cap added to RolesService for `tt_head_dev` + administrator grant.
- New `assets/js/components/admin-reorder.js` — generic up/down arrow + delete handler used by the Eval Categories tree.
- 60+ new Dutch strings; one new TT JS i18n key.

## Sprint 5 — done

Per SEQUENCE.md, Sprint 5 is now COMPLETE. Sprint 6 (cleanup + legacy-UI toggle — removes / deprecates the now-redundant wp-admin pages behind a default-OFF toggle) is the last sprint in the epic. The #0019 frontend-first vision is essentially landed.

# TalentTrack v3.10.0 — #0019 Sprint 4: People + Functional Roles

**Minor release.** Closes Sprint 4 of the #0019 frontend-first-admin epic. Single-PR session per shaping. People (staff records) and Functional Roles (the "who does what on which team" layer) get full CRUD on the frontend. The HoD weekly workflow of assigning staff to teams now works on a phone. #0017 (Trial player module) is unblocked.

Sprint 4's Reports deliverable was deferred to #0014 entirely during shaping — this release ships People + Functional Roles only, ~12h actual against ~16-20h estimate.

## People

- **`PeopleRestController`** (new) — thin wrapper around the existing `PeopleRepository`. Sprint 2 list contract: `?search` across name/email; filter by `role_type` / `team_id` / `archived`; whitelisted `?orderby` (last_name / first_name / email / role_type); pagination + envelope. Per Q5, every row carries a `current_roles` string concatenating the person's active assignments (e.g. `Head coach @ U13 · Physio @ U15`).
- **`FrontendPeopleManageView`** at `?tt_view=people` — list / create / edit / archive (soft, sets `archived_at` + `status='inactive'`). Form has first/last name (required), email, phone, type (`role_type` lookup), optional WP-user linkage (excludes users already linked to a player). Edit mode adds a read-only "Current team assignments" section with a deep-link into the FunctionalRoles assignments view filtered by this person.
- **People tile** added to the dashboard for users with `tt_view_people` or `tt_edit_people`.

## Functional Roles

One tile, two tabs (per Q1):

### Role types tab
- `tt_manage_functional_roles`-gated. CRUD for the role-type catalogue (head coach, assistant coach, physio, manager, …).
- Reorder via **up/down arrow buttons** (per Q2 — no DragReorder; works on every viewport). `POST /functional-roles/{id}/move` swaps `sort_order` with the adjacent row.
- `role_key` is set on create and never editable (referenced by `tt_team_people.role_in_team` + the auth-role mapping). Built-in / "system" roles get a badge and can't be deleted; a role with active assignments returns `409 in_use` with the count attached for the UI to surface.

### Assignments tab
- `tt_view_people` / `tt_edit_people` gated. List view (per Q3) via `FrontendListTable` with team / role filters, `?search` across team/person/role names, sortable by team / role / person / start date, Unassign row action. "New assignment" button opens a separate `?action=new` form (per Q4) with team, role, person, optional start date.
- Reuses the existing unique-key on `tt_team_people` (team × person × role) for dupe protection — surfaces as a friendly 409 message rather than a silent failure.

## REST endpoints summary

| Route | Method | Notes |
| --- | --- | --- |
| `/people` | GET | Sprint 2 list contract. |
| `/people` | POST | Create. |
| `/people/{id}` | GET | Single. |
| `/people/{id}` | PUT | Update. |
| `/people/{id}` | DELETE | Soft-archive. |
| `/functional-roles` | GET | Role types list. |
| `/functional-roles` | POST | Create role type. |
| `/functional-roles/{id}` | PUT | Update label / description. |
| `/functional-roles/{id}` | DELETE | Delete; rejected if `is_system` or assignments reference it. |
| `/functional-roles/{id}/move` | POST | Reorder up/down. |
| `/functional-roles/assignments` | GET | Assignments list (Sprint 2 contract). |
| `/functional-roles/assignments` | POST | Create assignment. |
| `/functional-roles/assignments/{id}` | DELETE | Unassign. |

## Team edit cross-link

The team edit view from Sprint 3 gets a new **Staff** section (per Q7) — a read-only "who's on staff for this team" table grouped by role, with email, plus a "Manage team assignments" button that deep-links into `?tt_view=functional-roles&tab=assignments&filter[team_id]=<id>` for users with `tt_edit_people`. Reuses `PeopleRepository::getTeamStaff` — no new query path.

## Wired up

- `PeopleRestController` registered in `PeopleModule::boot()`.
- `FunctionalRolesRestController` registered in `AuthorizationModule::boot()`.
- New `assets/js/components/functional-roles.js` for the reorder + delete buttons.
- Two new tiles on the dashboard (gated by capability).
- 32 new Dutch strings.

## Sprint 4 — done

Per SEQUENCE.md, Sprint 4 is now COMPLETE. Sprint 5 (admin-tier frontend — Configuration, migrations, roles, custom fields, usage stats) is the next epic on the queue. #0017 (Trial player module) is also unblocked.

# TalentTrack v3.9.1 — #0019 Sprint 3 session 3.2: Teams + CSV import (closes Sprint 3)

**Patch release.** Closes Sprint 3 of the #0019 frontend-first-admin epic. Teams get a real CRUD frontend with roster management and a placeholder for the #0018 formation board; club admins get bulk CSV import for players. Sprint 4 (people + functional roles + reports) is now unblocked.

## `TeamsRestController` (new)

Built from scratch — there was no v2.x equivalent. Full CRUD plus a roster sub-resource:

| Route | Method | Notes |
| --- | --- | --- |
| `/teams` | GET | Sprint 2 contract: `?search`, `?filter[age_group\|archived]`, whitelisted `?orderby` (name / age_group / player_count), pagination. Returns `{ rows, total, page, per_page }`. Coach scoping for non-admins; demo-mode scope applied. |
| `/teams` | POST | Create. Capability: `tt_edit_teams`. |
| `/teams/{id}` | GET | Single team. |
| `/teams/{id}` | PUT | Update. Per-team auth via `AuthorizationService::canManageTeam`. |
| `/teams/{id}` | DELETE | Soft-archive (sets `archived_at` + `archived_by`). |
| `/teams/{id}/players/{player_id}` | POST | Add player to roster. |
| `/teams/{id}/players/{player_id}` | DELETE | Remove player from roster (sets `team_id = 0`; player row stays). |

Per Sprint 3 plan Q3, roster is a sub-resource — cleaner for the autocomplete-add UI and avoids re-sending the full team payload on every roster change.

## `FrontendTeamsManageView`

Three modes via query string: list (`FrontendListTable` with age-group + archived filters and Edit/Delete row actions) / `?action=new` / `?id=N`. Edit form has:

- Team name, age group, head coach (dropdown of users with `tt_edit_evaluations`), notes.
- **Roster section** — current players list with a "Remove" action + an "Add player" dropdown of unaffiliated/cross-team candidates. Per Q4 the picker is a plain dropdown, no autocomplete.
- **Formation placeholder** — a dashed-border panel with a link to `ideas/0018-epic-team-development.md`. No functional UI.

Roster ops backed by `assets/js/components/team-roster.js` — vanilla, hits the sub-resource endpoints, reloads on success.

The v3.0.0 `FrontendTeamsView` placeholder is deleted. Its podium-per-team display is dropped; coaches can still see player cards via the players surface or the rate-cards analytics tile.

## CSV import for players (sync version)

Per Q1 in the Sprint 3 shaping: the spec's 5-step async flow with per-row dupe UI is dropped in favor of a simpler sync version that covers the 80% case.

**Endpoint** — `POST /talenttrack/v1/players/import` (multipart/form-data). One endpoint backs both preview (`?dry_run=1`, default) and commit (`?dry_run=0`). Client re-uploads the file on commit; cheap for typical CSVs (≤1MB). Capability: `tt_edit_players`.

**Service** — `src/Modules/Players/PlayerCsvImporter.php`:

- `parse()` — opens the file, normalizes headers (lowercase, BOM-strip), warns on unknown columns.
- `preview()` — first 20 rows with per-row validation status (`valid` / `warning` for dupes / `error`) + dupe detection by `first_name + last_name + date_of_birth`.
- `commit()` — validates each row, applies the chosen dupe strategy (`skip` / `update` / `create`), commits row-by-row. Accept-what-worked: errors don't abort; rows 1–46 stay if row 47 fails.
- `errorRowsToCsv()` — emits a corrected-input CSV the user can download, fix, and re-upload.

**Accepted columns** (header row required, case-insensitive): `first_name` (required), `last_name` (required), `date_of_birth` (YYYY-MM-DD), `nationality`, `height_cm`, `weight_kg`, `preferred_foot`, `preferred_positions` (comma-separated), `jersey_number`, `team_id` *or* `team_name`, `date_joined`, `photo_url`, `guardian_name`, `guardian_email`, `guardian_phone`, `status`.

**View** — `FrontendPlayersCsvImportView` at the new `?tt_view=players-import` tile slug. Three steps: upload (file input + dupe-strategy radio), preview (header warnings + first 20 rows with per-row status), result (created/updated/skipped/errored counts + a "Download error rows" CTA when any row failed).

**Tile** — "Import players" appears on the dashboard tile grid for users with `tt_edit_players`.

## Wired up

- `TeamsRestController` registered in `TeamsModule::boot()`.
- `csv-import.js` and `team-roster.js` enqueued from `DashboardShortcode`.
- 6 new TT JS i18n strings localized.
- 41 new Dutch strings.

## Sprint 3 — done

Per SEQUENCE.md, Sprint 3 is now COMPLETE (~22h actual against ~22h estimate; original spec was ~30–35h before the simplifications locked in shaping). Sprint 4 (people + functional roles + reports — HoD-facing surfaces, prerequisite for #0017) is the next epic on the queue.

# TalentTrack v3.9.0 — #0019 Sprint 3 session 3.1: Players full frontend

**Minor release.** Opens Sprint 3 (Players + Teams + CSV import). Players get a real CRUD frontend on `?tt_view=players` — list with filters, create/edit/delete forms, photo upload via WP's media uploader, custom fields, and a link through to the existing rate-card view. Sprint 3 is shaped into two sessions per Casper's preference; this is the first.

## `FrontendPlayersManageView` — four routes via query string

- **`?tt_view=players`** — list view via `FrontendListTable` with five filters (team, position, preferred foot, age group, archived) + name search + sortable columns. Edit / Rate card / Delete row actions. "New player" CTA above.
- **`?tt_view=players&action=new`** — create form.
- **`?tt_view=players&id=N`** — edit form (prefilled).
- **`?tt_view=players&player_id=N`** — detail / rate-card view. Preserved unchanged for deep links from search, podium, etc. The new manage UI uses `id` (not `player_id`) so the two modes never collide.

## `PlayersRestController::list_players` — full Sprint 2 contract

The v2.x list endpoint took only `?team_id=` and returned every matching player flat. Replaced with the same query-param shape Sessions/Goals use:

- `?search=` first/last name LIKE.
- `?filter[team_id|position|preferred_foot|age_group|archived]=…` — five filters.
- `?orderby=` whitelisted to last_name / first_name / team_name / jersey_number / date_of_birth / date_joined.
- `?page=&per_page=10|25|50|100`.
- Response envelope `{ rows, total, page, per_page }`.

Row-level visibility filter via `AuthorizationService::canViewPlayer` is preserved post-fetch (page-level cap bounds the cost). Demo-mode scope applied. Position filter matches against the JSON-encoded `preferred_positions` column. Age-group filter joins through `tt_teams.age_group`.

## Photo upload via `wp_enqueue_media()`

Frontend-compatible per the epic-shaping audit. The form opens the WP media library modal, lets the user pick or upload an image, and writes the URL into a hidden `photo_url` input. A "Remove" button clears it. The modal carries some wp-admin styling but lays out cleanly — no full reset needed.

## Custom fields

Player custom fields (configured under TalentTrack → Custom Fields) render on the create/edit form via the existing `CustomFieldRenderer::input()`. Edit mode prefills from `tt_custom_values`. Save passes them through `Players_Controller::create_player` / `update_player` which already handle validation + upsert via `CustomFieldValidator` + `CustomValuesRepository`.

## Rate-card link

A "View rate card" button at the top of the edit form links to `?tt_view=players&player_id=N` (the existing detail surface) — per Q7 in the Sprint 3 shaping, no new tile.

## Wired up

- `DashboardShortcode::dispatchCoachingView` flips `players` slug to `FrontendPlayersManageView`.
- The legacy `FrontendPlayersView` placeholder is deleted; its detail-rendering code (rate card + radar + facts + custom fields display) moved into the new view's `renderDetail()`.
- 13 new Dutch strings.

## Sprint 3 shape — two sessions

Session 3.2 (next, ~12h) closes Sprint 3 with: `TeamsRestController` from scratch (no existing) + Teams full frontend with roster management + sync CSV import (spec's async per-row dupe UI dropped per Q1 in shaping; ~5h vs ~10h).

# TalentTrack v3.8.2 — #0019 Sprint 2 session 2.4: Goals full frontend (closes Sprint 2)

**Patch release.** Last session of Sprint 2. Goals join Sessions on the full-CRUD frontend track; the `?tt_view=goals` tile gets a list / create / edit / delete UI. Sprint 3 (Players + Teams) is now unblocked.

## `FrontendGoalsManageView` — three modes

Same shape as the Sessions view from v3.8.1:

- **List** — `FrontendListTable` with team / player / status / priority / due-date filters, Edit / Delete row actions, and an inline status select on every row.
- **Create** (`?tt_view=goals&action=new`) — form with `PlayerPickerComponent` + `DateInputComponent` + priority dropdown. Posts to `POST /goals`.
- **Edit** (`?tt_view=goals&id=<int>`) — form prefilled from the row; adds a status field (create defaults to `pending` server-side). Saves via `PUT /goals/{id}`.

Delete = soft-archive via `DELETE /goals/{id}`.

## `FrontendListTable` — new `inline_select` render type

Generic enough for Sprint 3+ to reuse. Configure a column with:

```php
'status' => [
    'render'      => 'inline_select',
    'options'     => $status_options,        // value => label
    'patch_path'  => 'goals/{id}/status',    // template (`{id}` etc.)
    'patch_field' => 'status',               // body key
],
```

The hydrator emits an editable `<select>` in each cell. On change, the JS `bindInlineSelects()` handler PATCHes the configured endpoint with `{ <field>: <value> }`. The dropdown disables itself during the request and shows the row-level error in the table's status line on failure (no row-level retry yet — failed PATCH leaves the user on the new value but surfaces the message).

This replaces the legacy `.tt-goal-status-select` jQuery handler from `public.js` for the goals view (the legacy handler stays for any caller that still uses the class, which is now zero after this PR).

## Lookup-driven status / priority

Status and priority filter options + the inline select options are built from `goal_status` / `goal_priority` lookups (`QueryHelpers::get_lookup_names`). A club running custom statuses (Achieved, Cancelled, On Hold, …) sees its own values without code changes.

## Wired up

- `DashboardShortcode::dispatchCoachingView` flips the `goals` slug to `FrontendGoalsManageView`.
- `FrontendGoalsView` placeholder deleted.
- `CoachForms` docblock cleaned up — `renderGoalsForm` and `renderSessionForm` are dead helpers (only `CoachDashboardView`, itself dead, references them); their removal is Sprint 6's legacy-UI scope.
- 11 new Dutch strings.

## Sprint 2 — done

Per SEQUENCE.md, Sprint 2 is now COMPLETE (~20h actual vs ~22h estimate across four sessions). Sprint 3 (Players + Teams) is next; the keystone components are all in place — `FrontendListTable` (2.2), `TeamPickerComponent` (2.3), and the new `inline_select` render type (2.4) cover the full surface area Sprint 3 needs.

# TalentTrack v3.8.1 — #0019 Sprint 2 session 2.3: Sessions full frontend

**Patch release.** Resumes Sprint 2 after the v3.8.0 styling preemption. Sessions get a real CRUD frontend: a filtered/sortable list, a create form, an edit form, and a delete (soft-archive) row action. The keystone work was done in 2.2 (FrontendListTable + REST endpoints); this session ties it all together for the Sessions entity.

## `FrontendSessionsManageView` — three modes

Driven by query params on the `?tt_view=sessions` tile:

- **List** (default) — `FrontendListTable` with team / date-range / attendance-completeness filters and Edit / Delete row actions. "New session" CTA above the table.
- **Create** (`?tt_view=sessions&action=new`) — full form rendered by the new view (CoachForms helper retired for sessions).
- **Edit** (`?tt_view=sessions&id=<int>`) — same form prefilled with the session row + attendance, hitting `PUT /sessions/{id}` instead of `POST`.

Saves use the REST endpoints from Sprint 1 / 2.1; deletes hit `DELETE /sessions/{id}` which the controller treats as a soft-archive (sets `archived_at`, doesn't drop the row).

## `TeamPickerComponent`

New shared component at `src/Shared/Frontend/Components/TeamPickerComponent.php`. Mirrors `PlayerPickerComponent` but with team-scoping (admin sees all teams; non-admin coach sees only teams they head-coach — different scoping rules from players, hence a separate component per the Sprint 2 plan Q3). Carries a `filterOptions()` static for use as a `FrontendListTable` `select` filter source. Will be reused by the Goals view in 2.4.

## Bulk attendance + mobile pagination — `assets/js/components/attendance.js`

Three concerns the new attendance UI handles:

- **"Mark all present"** sticky button at the top of the attendance list. Sets every visible row's status to `Present` in one tap. The sticky positioning means a coach scrolling 30 players keeps it within thumb reach. Spec's 80% case: most show, mark exceptions.
- **Live "X of Y marked Present" summary** updates as the coach edits.
- **Team filter** — the form pre-renders attendance rows for every team the coach has access to (so flipping the team dropdown doesn't lose state); JS hides rows for non-current teams via `data-team-id`. Status changes survive the toggle.
- **Mobile pagination at 15** — below 640px, the first 15 visible rows render; a "Show all (N)" button reveals the rest. Desktop always shows the full roster. CSS-only stacked-card reflow for the table on mobile (matching the FrontendListTable pattern).

## Wired up

- `DashboardShortcode::dispatchCoachingView` now points the `sessions` tile slug at `FrontendSessionsManageView` (v3.0.0 placeholder `FrontendSessionsView` deleted).
- `tt-attendance` script enqueued by `DashboardShortcode`.
- Two new TT i18n strings (`show_all_count`, `attendance_summary`) in the localized JS bundle.
- 11 new Dutch strings.

## Out of this PR (deferred to 2.4 / later)

- **Goals full frontend** — Sprint 2 session 2.4.
- **`CoachDashboardView` + `CoachForms::renderSessionForm` removal** — both are dead code (only self-references). Sprint 6's legacy-UI-toggle scope; left alone here.
- **Pretty-URL routing for the action / id query params** — Sprint 2 plan keeps the existing `?tt_view=...&action=...` pattern.

# TalentTrack v3.8.0 — #0023 Styling options + WP-theme inheritance

**Minor release.** Carved out during the JG4IT theme build on 25 April 2026. The Branding tab in **Configuration** gets a second section with a single global toggle to defer fonts/colors/links/buttons to the surrounding WordPress theme, plus curated Google Fonts dropdowns and six semantic color pickers — all without writing CSS or building a custom theme.

#0019 Sprint 2 (sessions/goals frontend) is paused after session 2.2 while this preempts; sessions 2.3 + 2.4 resume after v3.8.0 ships.

## What's new on the Branding tab

A second section appears below the existing Academy / Logo / Primary / Secondary fields:

| Field | Storage | Default |
| --- | --- | --- |
| Inherit WordPress theme styles (toggle) | `theme_inherit` | off |
| Display font | `font_display` | (System default) |
| Body font | `font_body` | (System default) |
| Accent / Danger / Warning / Success / Info / Focus ring colors | `color_accent` etc. | `#1e88e5` / `#b32d2e` / `#dba617` / `#00a32a` / `#2271b1` / `#1e88e5` |

Existing `primary_color` and `secondary_color` keys are preserved unchanged — installs that don't touch the new section see no visual difference.

## What "inherit" actually does

When the toggle is ON, `BrandStyles::addBodyClass` adds `tt-theme-inherit` to `<body>`. The new `/* ─── Theme inheritance ─── */` section in `assets/css/frontend-admin.css` keys off that class:

- Body text, paragraphs, table cells, form labels → inherit `font-family` + `color`.
- Headings (h1–h6) → inherit `font-family` + `color`.
- Links → inherit `color`.
- Plain submit / `button-primary` buttons → background / color / border revert to the host theme.

Intentionally *not* inherited:

- Player card tier styling (gold / silver / bronze stays locked — it's part of the product identity).
- Tile grid borders + accents.
- The `FrontendListTable` component.
- `FormSaveButton` state colors (the green "Saved" / red "Retry" pulse depends on plugin tokens).
- Spacing, layout, structural CSS.

## Curated Google Fonts

`src/Shared/Frontend/BrandFonts.php` carries the catalogue:

- **Display** (10 candidates): Barlow Condensed, Oswald, Bebas Neue, Anton, Saira Condensed, Fjalla One, Archivo Black, Teko, Big Shoulders Display, Russo One.
- **Body** (12 candidates): Inter, Manrope, Plus Jakarta Sans, DM Sans, Work Sans, IBM Plex Sans, Source Sans 3, Nunito Sans, Outfit, Sora, Merriweather, Source Serif 4.

Plus `(System default)` and `(Inherit from theme)` sentinels. When at least one dropdown picks a curated family, `BrandStyles::enqueueFonts` makes a single combined `fonts.googleapis.com/css2?family=…&family=…&display=swap` request with the weights TalentTrack actually uses. System / Inherit triggers no font request.

## Token emission — additive only

`BrandStyles::injectVars()` now emits the new tokens (`--tt-accent`, `--tt-danger`, `--tt-warning`, `--tt-success`, `--tt-info`, `--tt-focus-ring`, `--tt-font-display`, `--tt-font-body`) **only when the operator set a non-empty value**. Empty fields fall through to the defaults declared in `frontend-admin.css`. This keeps the CSS architecture clean: `BrandStyles` overrides via `:root`, but only when there's something to say.

`frontend-admin.css` gains `--tt-font-display` and `--tt-font-body` token defaults that match the legacy stack used in `public.css`, so a club that doesn't pick fonts looks exactly like before.

## Custom-theme escape hatch unchanged

Clubs running a custom theme that adds `body .tt-dashboard { ... }` overrides (the JG4IT pattern from `marketing/themes/talenttrack-demo/`) keep working — those rules win at higher specificity than `BrandStyles`'s `:root` injection. The toggle is the easier path; the override path is still there if a club wants pixel-level control.

## Ship-along

- `docs/configuration-branding.md` + `docs/nl_NL/configuration-branding.md` — full coverage of the new section, including the honest "what inherit actually does" framing (some properties cascade, others don't — buttons are best-effort).
- `languages/talenttrack-nl_NL.po` — 16 new Dutch strings.
- SEQUENCE.md — adds #0023 row at rank 6.5; #0019 Sprint 2 marked PAUSED at 2.2 with the resume context.

# TalentTrack v3.7.4 — #0019 Sprint 2 session 2: FrontendListTable component

**Patch release.** Ships the keystone of Sprint 2: the reusable `FrontendListTable` component that Sessions (2.3), Goals (2.4), Players + Teams (Sprint 3), People (Sprint 4), and admin-tier surfaces (Sprint 5) all build their list views on top of.

## `FrontendListTable` — `src/Shared/Frontend/Components/FrontendListTable.php`

Declarative API. A list view tells the component its REST path, columns, filters, and row actions; the component handles everything else.

```php
FrontendListTable::render([
    'rest_path' => 'sessions',
    'columns'   => [
        'session_date' => [ 'label' => __('Date',  'talenttrack'), 'sortable' => true ],
        'team_name'    => [ 'label' => __('Team',  'talenttrack') ],
        'attendance'   => [ 'label' => __('Att.%', 'talenttrack'), 'render' => 'percent', 'value_key' => 'attendance_pct' ],
    ],
    'filters' => [
        'team_id' => [ 'type' => 'select',     'options' => $team_options ],
        'date'    => [ 'type' => 'date_range', 'param_from' => 'date_from', 'param_to' => 'date_to' ],
    ],
    'row_actions' => [
        'edit'   => [ 'label' => __('Edit',   'talenttrack'), 'href' => '?tt_view=sessions&edit={id}' ],
        'delete' => [ 'label' => __('Delete', 'talenttrack'), 'rest_method' => 'DELETE', 'rest_path' => 'sessions/{id}', 'confirm' => __('Delete?', 'talenttrack'), 'variant' => 'danger' ],
    ],
    'default_sort' => [ 'orderby' => 'session_date', 'order' => 'desc' ],
    'empty_state'  => __('No sessions match your filters.', 'talenttrack'),
    'search'       => [ 'placeholder' => __('Search…', 'talenttrack') ],
]);
```

### Architecture

- **Server renders the shell.** Filter form, table head, footer (per-page selector, pagination scaffold), and a JSON `<script type="application/json">` block carrying the declarative config + initial state. No-JS users see the filter form and can submit it as a normal page reload — `passthroughQueryArgs()` preserves `tt_view` and any other non-list-table query params so the tile router still routes correctly.
- **JS hydrates.** [`assets/js/components/frontend-list-table.js`](assets/js/components/frontend-list-table.js) reads the embedded config + state, fetches the first page from REST, and binds all interactivity: live filtering (300ms debounce on the search box), sort header clicks, per-page selector, pagination, row-action buttons (including DELETE-style buttons that confirm + refresh on success). URL state stays in sync via `history.replaceState`.

### Filters supported in v1

- `select` — dropdown over a value→label map.
- `date_range` — two date inputs that emit `filter[<from>]` / `filter[<to>]`. Param names overridable via `param_from` / `param_to`.
- `text` — free-text input.

Adding a new filter type is a small additive change in `renderFilterControl()` + the JS `readFiltersFromForm()` helper.

### Mobile reflow — CSS-only

Below 640px the table swaps to stacked cards (one row = one card, headers become labels via `data-label`). Pure CSS — no JS branching for layout (Q7 in the Sprint 2 plan). Each `<td data-label="Date">` renders its label as a pseudo-element when the table-display switches to block.

### Row actions

Two flavours: a link (`href` template with `{id}` substitution) or a button that fires a REST request (`rest_path` + `rest_method` template + optional `confirm` text). On 200, the table refreshes itself. On error, the inline status line surfaces the server's error message — uses the `RestResponse` envelope from Sprint 1.

## Validator wiring

The existing `FrontendSessionsView::render()` (the `?tt_view=sessions` tile destination) now embeds a `FrontendListTable` configured for sessions above the existing record-session form. This proves the component end-to-end against the `GET /sessions` endpoint that v3.7.3 added. Session 2.3 will replace this view with a dedicated `FrontendSessionsManageView` and remove the temporary embed.

## Ship-along

- `assets/css/frontend-admin.css` — `~/* ─── FrontendListTable ─── */` block (filters, table, footer, mobile card reflow, status indicators).
- `tt-list-table` script enqueued from `DashboardShortcode`.
- 9 new Dutch strings.

# TalentTrack v3.7.3 — #0019 Sprint 2 session 1: REST list endpoints

**Patch release.** Opens Sprint 2 of the #0019 frontend-first-admin epic. Server-side first — adds the REST list contract that the upcoming `FrontendListTable` component (session 2.2) will consume.

## New `GET /sessions` and `GET /goals`

Both endpoints live alongside their existing create/update/delete siblings under `talenttrack/v1/`. They share an identical query-param shape so the list-table component doesn't need entity-specific knowledge:

| Param | Behaviour |
| --- | --- |
| `?search=<text>` | LIKE across the obvious columns. Sessions: `title` + `location` + team name. Goals: `title` + `description` + player first/last name. |
| `?filter[<key>]=<value>` | Entity-specific. Sessions: `team_id`, `date_from`, `date_to`, `attendance` (`complete\|partial\|none`). Goals: `team_id`, `player_id`, `status`, `priority`, `due_from`, `due_to`. |
| `?orderby=<col>` | Whitelisted server-side. Unknown columns → 400 with a `bad_orderby` error code that lists what's allowed. |
| `?order=asc\|desc` | Default depends on the column (sessions sort newest-first by date; goals soonest-first by due date). |
| `?page=<n>&per_page=10\|25\|50\|100` | Defaults `page=1`, `per_page=25`. Other `per_page` values clamp to 25. |
| `?include_archived=1` | Default hides archived rows. |

Response uses the `RestResponse` envelope — `{ success, data: { rows, total, page, per_page }, errors }`.

## Sessions list — attendance-completeness filter

`?filter[attendance]=` is computed on the fly per row from `tt_attendance` row count vs the team roster size at query time. Wraps in `HAVING` so the total row count reflects the post-filter slice. Each row in the response also carries `attendance_count`, `roster_size`, and `attendance_pct` so the eventual UI can render an "Att. %" column without a follow-up query. Lists are capped at 100/page; if performance becomes a problem we add a cached completeness column on save.

## Authorization

- New permission callbacks `can_view()` on both controllers — gated on `tt_view_sessions` / `tt_view_goals` (or the corresponding `tt_edit_*` cap). Existing create/update/delete still require `tt_edit_*`.
- Non-admin coaches only see sessions/goals for teams they head-coach — same rule the existing dashboard surfaces enforce. If a coach has zero teams, both endpoints return an empty list (no row leak).
- Demo-mode scope (`QueryHelpers::apply_demo_scope`) applied to both queries — demo data stays hidden when demo mode is off.

## Sprint 2 plan

Companion doc at [`specs/0019-sprint-2-session-plan.md`](specs/0019-sprint-2-session-plan.md) breaks the sprint into four reviewable PRs (2.1 REST endpoints — this release; 2.2 `FrontendListTable` component; 2.3 Sessions full frontend; 2.4 Goals full frontend). Seven open questions from the shaping pass were resolved in conversation and recorded in the doc.

## Ship-along

- `.po` adds one new string (`Unknown orderby column.`).
- `SEQUENCE.md` marks Sprint 2 as IN PROGRESS with a session log.

# TalentTrack v3.7.2 — #0019 Sprint 1 session 3: CSS scaffold, shared components, drafts

**Patch release.** Closes out Sprint 1 of the #0019 frontend-first-admin epic. The foundation is now complete; Sprint 2 (sessions + goals frontend) is unblocked.

## CSS scaffold — `assets/css/frontend-admin.css`

New design-token + component stylesheet enqueued alongside `public.css`. Establishes CSS custom properties for colors, spacing, type scale, radii, focus ring, and breakpoints, plus base styles for forms, buttons, tables, panels, and grid helpers. Mobile-first with breakpoints at 640px / 960px. 16px minimum input font size prevents iOS zoom-on-focus. When the brand-identity work in #0011 eventually lands, only the tokens change — every component follows automatically.

## Five shared form components

Under `src/Shared/Frontend/Components/`, each with a `render(array $args): string` method. Docblocks carry example usage so the next Sprint's forms can reuse them without reading the implementation:

- **`FormSaveButton`** — submit button with idle/saving/saved/error states. Data-attribute labels are localized server-side; `public.js` drives the `data-state` attribute through the fetch lifecycle, with a green "Saved" flash for 1.5s before reverting.
- **`PlayerPickerComponent`** — dropdown respecting team-scoping for non-admin coaches (admin = all players; coach = players on their teams). Caller can override with an explicit `players` array.
- **`DateInputComponent`** — wrapped native `<input type="date">`, default value "today" so logging a same-day session is zero-click.
- **`RatingInputComponent`** — number input bound to an evaluation category, paired with a clickable dot track (sync lives in `assets/js/components/rating.js`). Range + step come from the `rating_min` / `rating_max` / `rating_step` config so a club on a 1–10 scale just changes config.
- **`MultiSelectTagComponent`** — tag-style multi-select over a fixed option set, backed by a hidden `<select multiple>` so no-JS users still submit valid data.

## Flash messages — JS layer

`assets/js/components/flash.js` progressively enhances the server-rendered banners from v3.7.0:

- Intercepts the `×` link; fires a background GET to the dismiss URL and fades the banner out in place. No reload.
- Success banners auto-fade after 5 seconds.
- Exposes `window.ttFlash.add(type, message)` so future JS-only success paths can surface feedback without a redirect.

## localStorage drafts — `assets/js/drafts.js`

Any form with a `data-draft-key="<key>"` attribute opts in. On input, a debounced JSON snapshot goes to `localStorage['tt_draft_' + key]`. On load, if a draft exists for that key, a Restore / Discard prompt renders above the form. A successful save (detected via the `tt:form-saved` custom event that `public.js` now dispatches) clears the draft. 14-day TTL for stale drafts. Private-mode / quota-exhausted localStorage failures silently no-op.

The eval/session/goal coach forms (`CoachForms.php`) got `data-draft-key` wired in this release, so a coach who loses signal mid-entry at the pitch side won't lose what they typed.

## Small things

- `CoachForms` submit buttons migrated to `FormSaveButton::render()`. The three existing forms are the first consumers; future forms don't need to know the state-machine contract.
- `public.js` dispatches `tt:form-saved` on successful REST save so drafts (and any future consumers) can hook in without being hard-wired to the submit handler.

## Ship-along

- `.po` updated with 3 new strings (Retry, Discard, draft-restore prompt).
- SEQUENCE.md now marks #0019 Sprint 1 as **COMPLETE** — the foundation is done. Sprint 2 (sessions + goals frontend) is next.

# TalentTrack v3.7.1 — #0019 Sprint 1 session 2: client REST cutover + session 1 REST-registration fix

**Patch release.** Closes out session 2 of #0019 Sprint 1 and fixes a registration bug that shipped silently in v3.7.0.

## Session 1's REST controllers were unreachable

v3.7.0 added `Sessions_Controller` + `Goals_Controller` + an enriched `Evaluations_Controller` under `includes/REST/` with namespace `TT\REST\*`. The plugin's SPL autoloader maps `TT\\` → `src/` only, and the class that would have called their `::init()` (`includes/Core.php`) is never instantiated anywhere — it's leftover legacy scaffolding. So the new routes never registered. The live demo-install silently kept running on the old `FrontendAjax` / `includes/Frontend/Ajax` admin-ajax handlers and no one noticed until the client-side cutover forced the issue.

**Fix:** re-homed the three controllers under `src/Infrastructure/REST/` (`SessionsRestController`, `GoalsRestController`, expanded `EvaluationsRestController`) and register them via the Sessions / Goals / Evaluations modules. They now share the `RestResponse` success/error envelope with the existing `PlayersRestController` + `EvaluationsRestController` so the client gets one shape to parse.

## Client-side REST cutover

`assets/js/public.js` rewritten as vanilla JS + `fetch()`:

- jQuery is no longer a dependency of `tt-public`.
- Forms declare targets with `data-rest-path="<sub-path>"` + `data-rest-method="POST|PUT"` — hidden `action` / `nonce` inputs removed.
- REST nonce comes through `wp_localize_script('tt-public', 'TT', { rest_url, rest_nonce })` and is sent via the `X-WP-Nonce` header.
- Nested bracket names (`ratings[12]`, `att[7][status]`) expand to nested JSON objects so the controllers see the same shape the AJAX handlers used to.
- Inline goal status select → `PATCH /goals/{id}/status`. Inline goal delete → `DELETE /goals/{id}`.

## Legacy code removed

- `src/Shared/Frontend/FrontendAjax.php` — deleted. `FrontendAjax::register()` removed from `Kernel::boot()`.
- `includes/Frontend/Ajax.php` — deleted (was dead-code since the Kernel bootstrap migration, duplicate hook registration if it had ever run).
- `includes/REST/{Sessions,Goals,Evaluations}_Controller.php` — deleted (the broken session-1 files).
- `includes/Core.php` — deleted (never instantiated, only called the deleted controllers + `Frontend\Ajax::init()`).

The rest of `includes/` (`Helpers.php`, `Roles.php`, `Admin/*`, `Frontend/App.php`, `Frontend/Styles.php`, `REST/Players_Controller.php`, `REST/Config_Controller.php`, `Activator.php`) is also dead code but outside this release's scope — tracked separately.

## Ship-along

- `.po` updated with the new error-envelope strings.
- SEQUENCE.md session log amended — Session 1 caveat noted, Session 2 summary added.

# TalentTrack v3.7.0 — #0019 Sprint 1 foundation (session 1)

**Minor release.** Opens Phase 1 — the #0019 frontend-first-admin epic. Session 1 of Sprint 1 lands the server-side foundation so follow-up sessions can do the client-side cutover in focused passes. Also carries a demo-generator language fix.

## New REST API endpoints

`includes/REST/` expanded with the full set of write endpoints that the old `FrontendAjax` / `includes/Frontend/Ajax` shims covered:

- `POST /talenttrack/v1/sessions`, `PUT`/`DELETE /sessions/{id}` — `Sessions_Controller` (new). Handles attendance as a sub-resource inline on create/update. Fail-loud DB error handling.
- `POST /talenttrack/v1/goals`, `PUT`/`DELETE /goals/{id}`, `PATCH /goals/{id}/status` — `Goals_Controller` (new). PATCH matches the inline status-select flow.
- `POST /talenttrack/v1/evaluations` enriched — was running `$wpdb->insert` without checking the return; now matches FrontendAjax v2.6.2 safety net (fail-loud inserts, structured `WP_Error` with DB detail, cap upgraded from `tt_evaluate_players` to `tt_edit_evaluations`, coach-owns-player check, required-field validation).

## Flash-message scaffold

New `FlashMessages` service — user-meta-backed queue, `add`/`consume`/`peek`/`dismiss`/`render`/`init`. Dashboard shortcode renders pending messages at the top of the body. `?tt_flash_dismiss=<id>` no-JS dismiss works via `template_redirect`. Types: success/info/warning/error.

## Intentionally NOT in this release

Client-side cutover of `assets/js/public.js` + the `tt-ajax-form` handler. Keeps working via the existing AJAX endpoints until session 2. Both `FrontendAjax` classes still in place. Shared form components, CSS scaffold, localStorage drafts all land in session 3.

## Demo generator: content language actually works now

The v3.6.1 attempt routed goal titles + session title template through `__()` + `switch_to_locale()`. That only works when the compiled `.mo` is up to date — which this repo's workflow doesn't guarantee. Result: picking `nl_NL` on the Generate form silently fell back to English.

**Fix:** swap the `__()`/`switch_to_locale()` approach for first-class per-language content dictionaries embedded in the generator classes:

- `GoalGenerator::TITLES_BY_LANGUAGE` + `DESCRIPTION_SUFFIX_BY_LANGUAGE`
- `SessionGenerator::SESSION_STRINGS_BY_LANGUAGE` (title template + default location)

Both expose `supportedLanguages()` + `resolveLanguage()` statics. The **Content language** dropdown on the Generate form is now populated from `supportedLanguages()` so the operator can only pick locales where the generators actually ship content. Extending to a new language: add one key to each generator's constant array. No `.mo` recompile needed.

## Ship-along

- `.po` updated with new `WP_Error` + flash strings.
- SEQUENCE.md tracks Sprint 1 as IN PROGRESS with a session log. Session 1 ~4h actual against a ~25–30h estimate.

# TalentTrack v3.6.1 — Phase 0b bug fixes + demo-generator follow-ups

**Patch release.** Clears both remaining Phase 0b bugs (#0007, #0008) and layers four demo-generator follow-ups identified during v3.6.0 testing. Adds a new SEQUENCE.md-maintenance ship-along rule.

## Bugs fixed

- **#0007 — drag-reorder on all lookup tabs.** One parameter off in six `tab_lookup()` calls (`show_sort=false` → `true`). Positions, Foot Options, Age Groups, Goal Statuses, Goal Priorities and Attendance Statuses all get the drag handle + DragReorder script that Evaluation Types already had.
- **#0008 — `actions/checkout@v4 → @v5`.** Runs on Node 24, clears the larger Node-20-deprecation annotation on every release workflow run. `softprops/action-gh-release@v2` stays on the floating major; its annotation is informational until 2026-06-02 and the float will pick up Node 24 when softprops patches.

## Demo-generator additions

- **Subcategory rating generation.** `EvaluationGenerator` now writes ratings for every subcategory of each configured main, clustered ±0.4 around the main score. Main-level radar + trend visuals stay coherent while the detail drill-in shows plausible per-subcategory variation. Subcategory tree cached once per request.
- **Content language per demo.** New **Content language** dropdown on the Generate form (Tools → TalentTrack Demo), defaulted to the site locale, populated from `LookupTranslator::installedLocales()`. The chosen locale is threaded into `GoalGenerator` which wraps its source strings in `__()` and uses `switch_to_locale()` so generated rows land in the target language regardless of the operator's browser locale. Twelve goal titles + the description suffix translated to Dutch in the `.po`.
- **Compact admin-bar pill.** Demo-mode indicator moved to the right side of the admin bar (`parent='top-secondary'`) so it sits next to the Howdy dropdown instead of crowding the left-hand menu area. Four-letter "🎭 DEMO" instead of the previous wordier label.

## New ship-along rule

DEVOPS.md now documents a fourth standard enforced on every release:

> **SEQUENCE.md kept current in the release commit.** Every release that touches a backlog item referenced in SEQUENCE.md updates it in the release commit — showing what was done, moving phase status forward, noting estimated vs actual time. A release that leaves SEQUENCE.md stale isn't done.

SEQUENCE.md itself refreshed to mark Phase 0 + 0b both COMPLETE through v3.6.1, with an estimate-vs-actual column that starts the calibration history (#0020 estimate 24h → actual ~30h, #0007 est TBD → actual 15 min, #0008 est 4h → actual 5 min).

## Housekeeping

Removed `ideas/0007-…md` and `ideas/0008-…md` (shipped). TRIAGE.md refreshed to show post-demo priorities: dry-run → #0019 Sprint 1 → #0003.

No schema changes. No migrations.

# TalentTrack v3.6.0 — Demo-prep polish bundle

**Minor release.** Fourteen items bundled across three PRs — the demo-readiness polish pass for the 4 May 2026 showcase. Codifies three ship-along standards that apply to every future PR.

## Ship-along standards — new, enforced

DEVOPS.md now calls out three rules that apply to every feature PR going forward:

1. **Reference data is translatable + extensible by default.** No hardcoded lists of user-facing values. Go through `tt_lookups` / `__()` / `tt_config`.
2. **Translations ship in the same PR.** Any `__()` / `_e()` / `esc_html__()` change touches `nl_NL.po`.
3. **Docs ship in the same PR.** Behaviour changes touch `docs/<slug>.md` + `docs/nl_NL/<slug>.md`.

## Batch A — quick wins (PR #11)

- **Player card name wrap** — long names ellipsis-truncate on one line so every card keeps the same height. Full name exposed via `title=""` tooltip.
- **Responsive tile fonts** — `clamp()` so tile labels + descriptions shrink smoothly at narrow widths.
- **Print view "Close window"** — the print tab opens via `target=_blank`, so `history.back()` never worked after Save-as-PDF. Now a proper `window.close()` button.
- **Competition as a lookup** — new `competition_type` lookup (migration 0013) seeded with **League** and **Cup**. All three Competition form fields + the EvaluationGenerator now read from it. Translated via `__()` so Dutch installs render "Competitie" / "Beker".
- **Clickable teammate card** — new `FrontendTeammateView` renders a read-only card when a player taps a teammate on My team. Name, photo, team, age group, positions, jersey, foot, height, weight. Evaluations / goals / ratings stay private.

## Batch B — visual + chart + navigation (PR #12)

- **Radar visual rewrite** — 400×340 viewBox with a reserved 36px legend strip, axis markers 1–5, rounded polygon joins, hollow value dots with coloured borders. Labels stop clipping at narrow widths.
- **Trend + radar charts render on the frontend.** Real bug fix. `enqueueChartLibrary()` now enqueues in the footer (the head had already flushed by the time the shortcode ran); the chart-init IIFE waits for `DOMContentLoaded` before checking `window.Chart`. Charts render wherever `PlayerRateCardView::render()` lands.
- **DAU / evals-per-day "Pick a day…" picker** — detail pages no longer dead-end on "Invalid date." Each defaults to today and shows a **← Prev · date field · Next →** control. Main Usage Statistics page gets matching "Pick a day…" entry buttons next to each chart header.
- **Team players panel** — team edit page now shows the current roster below Staff Assignments (jersey, positions, foot, DoB). Each name links to the player edit page.
- **Clickable entity refs in list tables** — Players / Sessions / Goals / Evaluations list cells for the related entity now link to its edit page (cap-gated on `tt_view_*`).

## Batch C — tables + multilingual reference data (PR #13)

- **Sortable + searchable frontend tables.** New zero-dependency vanilla-JS helper at `assets/js/tt-table-tools.js`. Opt-in via class `tt-table-sortable`: adds a filter input + live row count, makes every `<th>` click-sortable with auto type detection (number / date / text), diacritic-insensitive search. Applied to **My evaluations** and **My sessions**.
- **Multilingual reference data.** New nullable `translations` TEXT column on `tt_lookups` (migration 0014) stores per-locale name + description as JSON. New `LookupTranslator` service resolves the right text for the current user's locale with fallback through the `.po` for seeded values. Every lookup edit form under Configuration gets a **Translations** block with one row per installed locale. Admin-added lookup values can now be translated without a plug-in update. Two consumer sites wired (PlayersPage + FrontendMyProfileView for `preferred_foot`); rest follow opportunistically.

## Migrations

- **0013** — seeds the `competition_type` lookup with League + Cup.
- **0014** — adds `translations` column to `tt_lookups`.

Both idempotent; no-op if the state already exists.

No capability changes.

# TalentTrack v3.5.0 — Demo staff, positions from lookup, visual progress

**Minor release.** Four improvements to the demo data generator — the People directory now actually gets populated, teams get real Staff Assignments, positions follow the configured reference data, and the generate flow shows visible progress.

## Staff actually populated via People + Staff Assignments

Previously `TeamGenerator` only set the legacy `head_coach_id` column, which left the People directory empty and the Team Staff panel with nothing to show. Now:

- New **`PeopleGenerator`** creates 28 persistent `tt_people` rows — `hjo`, `hjo2`, `scout`, `staff`, 12 coaches, 12 assistants. Coaches and assistants get Dutch first/last names drawn from the same seeds as players; their bound WP users' display names sync to match.
- **`TeamGenerator`** now creates two `tt_team_people` rows per team with proper `functional_role_id`s — one `head_coach`, one `assistant_coach`. Open a team in the admin and you'll see both staff members on the Team Staff panel. `head_coach_id` is still set in parallel for backcompat.
- People are persistent like users: `Wipe demo data` leaves them alone; only `Wipe demo users too` removes them (alongside the users, before them in order to avoid orphaned `wp_user_id` pointers).

## Positions from the backend lookup

`PlayerGenerator` no longer hard-codes `GK/CB/LB/...`. Positions are read from `tt_lookups.position` — matching the pattern already in place for age groups and foot. If your install has customized the position lookup (Dutch abbreviations, different role names), demo players now get positions from that set. Fails loudly with a clear "configure positions first" message if the lookup is empty.

## Visual progress during generation

When you click **Generate demo data**, a full-screen overlay with a spinner now appears immediately: *"Generating demo data… this usually takes 15–45 seconds. Leave this tab open."* No more staring at a frozen browser tab wondering if anything is happening. The handler also raises the PHP time limit to 300 seconds so the Large preset can't time out halfway through on shared hosts.

## Scope filter covers the People directory

`PeopleRepository::list()`, the `PeoplePage` archive-view fallback, `ArchiveRepository::counts()` (now includes `person`), and the `RoleGrantPanel` people dropdown all route through `QueryHelpers::apply_demo_scope('person')`. Demo mode ON hides real staff; demo mode OFF hides demo staff. No cross-bleed.

New `tt_demo_tags` entity types: `person` and `team_person`. No schema changes, no migrations — the tag table was designed for this from the start.

# TalentTrack v3.4.1 — Demo user name sync + status-tab scope

**Patch release.** Two more fixes from demo-dress-rehearsal testing.

## WP users show the Dutch player name

The five demo-player slot users (`tt_demo_player1` … `tt_demo_player5`) are bound via `wp_user_id` to the first five generated players. Previously their WP `display_name` / `first_name` / `last_name` stayed at the generic "Demo Player 1" slot label, so any frontend surface reading from `wp_users` showed the slot name while the TalentTrack player record showed e.g. "Daan De Jong" — and the two didn't line up.

`PlayerGenerator` now syncs first_name / last_name / display_name / nickname on the bound user to the generated player's identity on every run. `user_login` and `user_email` stay fixed to the slot so the persistence contract holds.

## Status-tab counts respect demo mode

`ArchiveRepository::counts()` — the source of the "Active (N) | Archived (N) | All (N)" tabs above every admin list — was running raw `SELECT COUNT(*)` without the scope filter. In demo mode ON, the tabs silently included real club rows alongside demo rows. Example: "Active (37)" when the list below actually rendered 36 demo players, giving a ghost player that the operator couldn't account for.

`ArchiveRepository::counts()` and `activeDependentsFor()` (the archive warning — *"18 players depend on this team"*) both now route through `QueryHelpers::apply_demo_scope()`. Counts match the list exactly.

No schema changes. No migrations.

# TalentTrack v3.4.0 — Demo generator: reference data + club name + reuse UX

**Minor release.** Four improvements to the demo data generator driven by real demo-dress-rehearsal testing.

## Use configured reference data

Previously the generator hard-coded JO8–JO19 age labels and English foot values, which didn't match most installs.

- **Age groups** now come from `tt_lookups.age_group`. Whatever the install has configured (Dutch JO-format, English U-format, a customized set, anything) is what lands in `tt_teams.age_group`. No more silent mismatch against Category Weights and other downstream consumers that key on the lookup. The generator fails loudly with a helpful pointer to **Configuration → Age Groups** if the lookup is empty.
- **Preferred foot** now comes from `tt_lookups.foot_option`. If the lookup holds Dutch labels (Rechts / Links / Beide) those are what land on the player. Uniform distribution for v1; a richer model follows the upcoming reference-data translation feature.

## Re-run UX, actually clear

The generator has always been idempotent on users, but the messaging didn't communicate that. Now:

- **Before the run:** a banner on the Generate form tells the operator explicitly what will happen. First-run shows a yellow warning (*"36 WP welcome emails will be sent"*) and keeps the domain-confirmation checkbox. Re-run shows a blue info notice (*"No new WP users will be created, no welcome emails will be sent"*) and hides the checkbox and the domain/password "required" flags.
- **After the run:** the success notice splits user stats from data counts. A re-run reads e.g. *"Data: 3 teams, 36 players, 576 evaluations, 48 sessions, 54 goals. 36 users reused (0 created)."* No more ambiguity about whether users got created this time.

## Club name per demo

New **Club name for this demo** input on the Generate form. Defaults to the stored `academy_name` so generating without touching it reproduces prior behaviour. Override with (e.g.) *"FC Groningen"* and teams become *"FC Groningen JO11"*, *"FC Groningen JO13"*, etc. Per-generate only — the Configuration setting is not mutated.

No schema changes. No migrations.

# TalentTrack v3.3.1 — Demo-mode scope filter audit

**Patch release.** Fixes a demo-blocking scope leak caught during v3.3.0 testing: with demo mode ON, the wp-admin Players page and several other surfaces still showed real club rows alongside the demo data.

v3.3.0 wired `QueryHelpers::apply_demo_scope()` into the `QueryHelpers::*` entity methods, but a number of module admin pages and shared surfaces query the tables directly via `$wpdb`, bypassing the helper. This release routes those paths through the scope filter too.

**Patched surfaces:**
- `PlayersPage`, `TeamsPage` (list + per-team count), `GoalsPage`, `SessionsPage`, `ReportsPage` (Progress / Comparison / Team Averages)
- Sidebar navigation badges (5 counts + 5 weekly deltas) — prevents inflated totals in demo mode
- `RoleGrantPanel` teams + players dropdowns in Access Control
- Frontend `[talenttrack_dashboard]` goals block
- REST `GET /evaluations` endpoint

**Known residual (not demo-blocking):** Direct URL access to edit-form detail views (e.g. `?page=tt-goals&action=edit&id=X`) still reads the raw row without the scope filter. Unreachable through normal UI flow since list views no longer surface IDs from the other side. Will tighten in post-demo cleanup.

# TalentTrack v3.3.0 — Demo data generator complete (Checkpoint 2)

**Minor release.** Completes spec #0020. A realistic Dutch academy can now be generated, scoped, and wiped end-to-end from one wp-admin page — ready for the 4 May 2026 demo.

## What's new

**Three more generators:**
- **Evaluations** — ~2 per player per week over the preset's activity window. Mix of Training and Match (Match rows include opponent, competition, home/away, result, minutes). Ratings follow six archetype trajectories (rising star, in a slump, steady solid, late bloomer, inconsistent, new arrival) stored per player from Checkpoint 1, so every demo has multiple coach-conversation stories simultaneously.
- **Sessions** — 2 training sessions per team per week with realistic attendance distributions (85% present / 10% absent / 5% late plus per-player tendencies).
- **Goals** — 1–2 goals per player across status states (60% in-progress / 20% completed / 15% pending / 5% on-hold) and priorities (20/60/20 H/M/L).

**Demo mode:**
- New `tt_demo_mode` site option (`off` | `on`). Toggle from `Tools → TalentTrack Demo`.
- `QueryHelpers::apply_demo_scope()` filters every core read path — teams, team-by-id, players, player-by-id, player-for-user, teams-for-coach, evaluation. When mode is **off** (default), demo rows are invisible everywhere in the plugin. When **on**, real club data is invisible and only demo rows appear.
- Red **🎭 DEMO MODE** indicator in the WordPress admin bar plus a banner prepended to `[talenttrack_dashboard]` output. Impossible to miss.
- Leaving demo mode requires typing `EXIT DEMO` — a safety rail against "we thought the demo was over yesterday".

**Wipe flow:**
- **Wipe demo data** (typed `WIPE`) — removes every demo-tagged row in dependency order (ratings → evaluations → attendance → sessions → goals → players → teams). The 36 persistent demo users survive.
- **Wipe demo users** (expected-domain + typed `WIPE USERS`) — removes the persistent user set. Three safety rails fire per user: domain match, not-current-user, not-last-admin.

**Admin page polish:**
- Mode status badge + toggle controls.
- Credentials-on-success display: after first generate, the 36 accounts appear in a copy-friendly textarea (shown once, via short-lived transient).
- Past batches table with created-at timestamps.

## What's outside this release (Checkpoint 3 / optional)

- Audit of direct `$wpdb->get_*()` calls inside individual module pages and REST controllers. The `QueryHelpers` wiring covers the hottest paths; module-local queries still see demo rows when mode is off. Not demo-blocking but will be tightened post-demo.
- Four-step wizard UX with async progress polling — single-screen form is sufficient for v1.
- "Send test email" button to pre-verify the demo domain.

No schema changes beyond Checkpoint 1's `tt_demo_tags`. No capability changes. No migrations.

# TalentTrack v3.2.0 — Demo data generator (Checkpoint 1)

**Minor release.** First of two ship slices for spec #0020 — the demo data generator. After install + migration, `Tools → TalentTrack Demo` can spin up a realistic Dutch academy dataset in seconds.

## What's new

- **`tt_demo_tags` table** (migration 0012) — the provenance map that tags every generated entity to a batch. No changes to existing tables.
- **Demo admin page** at `Tools → TalentTrack Demo` — single-screen form (preset, email domain, password, seed, confirmation) gated on `manage_options`. Shows current demo footprint and past batches.
- **User generator** — creates the Rich set of 36 persistent demo WP users on first run (`admin`, `hjo`, `hjo2`, `scout`, `staff`, `observer`, `parent`, `coach1`–`coach12`, `assistant1`–`assistant12`, `player1`–`player5`). Idempotent on re-run: existing slots are reused by tag, with email-based fallback reclaim for pre-tag installs.
- **Team generator** — Dutch JO-age-group teams (JO8 through JO19), head coach drawn from the `coach<N>@` slot pool. Team name shape: `<Academy Name> JOxx`.
- **Player generator** — age-appropriate Dutch players with deterministic seeding (default seed `20260504`), realistic heights/weights, jersey-number uniqueness within team, archetype tagged for the upcoming evaluation generator. `player1`–`player5` WP users get bound to the first five generated players so they can log in.
- **Seed files** under `src/Modules/DemoData/seeds/` — 100 first names, 100 last names, JO age groups, 35 Dutch opponents, W/V/G match-result notation. Plain text, easy to edit.

## Preset sizes

- **Tiny** — 1 team × 12 players / 4 weeks
- **Small** — 3 teams × 12 players / 8 weeks *(default)*
- **Medium** — 6 teams × 12 players / 16 weeks
- **Large** — 12 teams × 12 players / 36 weeks

(Week counts matter once the evaluation/session/goal generators ship in Checkpoint 2.)

## What's explicitly still coming (Checkpoint 2)

- Evaluation, session, and goal generators
- Site-wide `apply_demo_scope()` filter + `tt_demo_mode` toggle with admin-bar / frontend banner
- Wipe flow (two variants with typed confirmations and safety rails)
- Four-step wizard UX with async progress polling

## Known Checkpoint 1 limitations

- Re-running generate accumulates teams/players on each run (users stay idempotent). The wipe flow in Checkpoint 2 handles this.
- `player1`–`player5` bindings point at the newest generated player on each run; stale bindings on earlier demo players remain until wipe.

No schema changes to existing tables. No capability changes. One migration.

# TalentTrack v3.1.0 — Documentation in Dutch

**Minor release.** The in-app help/wiki now ships with full Dutch translations alongside the original English content.

## What's new

- **Locale-aware doc resolver.** `HelpTopics::filePath()` now tries `docs/<locale>/<slug>.md` first and falls back to the canonical English `docs/<slug>.md`. Locale comes from `determine_locale()`, so an individual WP user's preferred language wins over the site default. Two admins on the same site can each see docs in their own language.
- **Full Dutch translations.** All 19 help topics translated into Dutch under `docs/nl_NL/`. Terminology aligned with the existing `talenttrack-nl_NL.po` glossary (Speler, Coach, Evaluatie, Hoofd opleiding, Alleen-lezen Waarnemer, Leeftijdscategorie, Rugnummer, etc.).

## Adding another language

Drop `docs/<locale>/<slug>.md` files. No code changes required. Any topic without a translation in the active locale falls back to English automatically.

## Behind the scenes

- Groundwork-only: `ideas/0008-bug-actions-node20-deprecation.md` logged so the next-gen GitHub Actions node deprecation (2026-06-02 soft / 2026-09-16 hard) doesn't get lost.

No schema changes. No capability changes. No migrations.

# TalentTrack v3.0.2 — PUC branch + rate-limit fixes

Fixes two Plugin Update Checker issues that have been silently breaking auto-update for a long time:
- PUC was defaulting to branch `master` (which doesn't exist on this repo — default is `main`). Explicit `setBranch('main')` added.
- Unauthenticated GitHub API calls from shared hosting were hitting the 60/hour rate limit, producing HTTP 403 errors. PUC now reads an optional `TT_GITHUB_PAT` constant from wp-config.php and uses it to authenticate. For a public repo this token needs zero scopes.

To enable authenticated API calls on a site, add to wp-config.php above the `/* That's all, stop editing! */` line:

    define( 'TT_GITHUB_PAT', 'ghp_yourtokenhere' );

No schema changes. No migrations.

# TalentTrack v3.0.1 — PUC release-asset delivery fix

**Patch release.** One-line PHP change: enables `getVcsApi()->enableReleaseAssets()` on the Plugin Update Checker instance so WordPress picks up the `talenttrack.zip` asset attached to each GitHub Release (rather than the source zipball, which has the wrong folder name and silently breaks the update).

Also lands the devops foundation scaffolding (ideas/, specs/, TRIAGE.md, DEVOPS.md, DEPLOY_DEBUG.md) that's been on main since the previous PR but didn't ship in a release. Those files are docs-only and have no runtime effect.

No schema changes. No capability changes. No migrations.

# TalentTrack v3.0.0 — Capability refactor + Migration UX + Frontend rebuild

**Status: SHIPPED.** v3.0.0 is the first TalentTrack release with a fully tile-based frontend, a genuinely useful Read-Only Observer role, and a one-click migration workflow.

## Headline changes

Three foundational rebuilds, landed together as a major version:

1. **Migration UX** — no more deactivate/reactivate dance. When you update TalentTrack, an admin notice with a "Run migrations now" button appears automatically. A "Run Migrations" link is always present on the Plugins page as a manual recovery path.

2. **Capability refactor** — every write-implying cap split into view + edit pairs. 8 view caps, 7 edit caps. The Read-Only Observer role now has meaningful access across the entire plugin — see everything, change nothing.

3. **Frontend fully rebuilt tile-based** — the v2.21 tile landing page now has 14 real destinations, one focused view per tile. No tab navigation anywhere. Me, Coaching, and Analytics groups all work end-to-end for their audiences.

## What changed

### Migration UX

Activating TalentTrack used to require deactivate + reactivate to trigger migrations. Easy to forget, and the symptoms of "skipped a migration" were confusing.

**Automatic version tracking.** `Activator::runMigrations()` stores `TT_VERSION` in the `tt_installed_version` option on every successful run. On every admin page load, TalentTrack compares the stored value to the running `TT_VERSION`.

**Admin notice on mismatch.** A yellow banner: *"TalentTrack schema needs updating. Plugin version 3.0.0 is loaded but installed schema is 2.22.0."* with a **Run migrations now** button. One click, migrations complete (within a second or two for typical data), banner disappears.

**Manual trigger on Plugins page.** A **Run Migrations** link sits next to the TalentTrack row alongside Deactivate and Edit. Always available, even when no migration is pending — useful if you suspect a prior run failed partially.

**Idempotent by design.** Every step (schema ensure, seed data, cap grants, self-healing) was already idempotent; we just surfaced it as a first-class admin action. Running twice when nothing changed is a no-op.

### Capability refactor

Pre-v3, four capabilities gated everything: `tt_manage_players`, `tt_evaluate_players`, `tt_manage_settings`, `tt_view_reports`. Each was binary — grant meant both view AND write. Impossible to have a meaningful read-only experience.

**New granular capabilities:**

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

**All 8 roles updated.** Every pre-built TalentTrack role has granular caps. Notably the Read-Only Observer now has all 8 view caps and zero edit caps.

**Legacy caps still work.** A `user_has_cap` filter resolves old cap names via the new granular caps. `current_user_can('tt_manage_players')` passes when the user has both `tt_view_players` AND `tt_edit_players`. Third-party code or Club Admin custom logic continues working.

**Every call site audited.** ~40 `current_user_can()` / `user_can()` calls rewritten to granular caps. Write handlers → `tt_edit_*`. List pages, menu entries, tile entries → `tt_view_*`. Page CAP constants → view cap. Role routing (`$is_admin`, `$is_coach` in dashboard) → edit cap preserving original semantics.

**UI write controls cap-gated.** Add New buttons and Edit/Delete row-action links in the 6 admin list pages (Teams, Players, People, Evaluations, Sessions, Goals) now render only when the user holds the appropriate `tt_edit_*` cap. Observers see `—` in the action column instead of buttons that would return Unauthorized when clicked.

### Observer role works end-to-end

- **Admin**: Full menu visible. Every list, every detail view, every report. Write buttons hidden. Bulk actions silently restricted to non-destructive views (the bulk-action dropdown gates per-entity `tt_edit_*`).
- **Frontend tile grid**: Analytics group (Rate cards, Player comparison) is their entry point. Coaching group tiles appear but link to "section only available for coaches" — clean gate, not broken link.

### Frontend fully rebuilt

The v2.21 tile landing page promised destinations that didn't exist — tapping "My goals" dropped you into a tab-heavy dashboard that ignored your tile choice. v3.0.0 fixes this with 14 new focused view classes:

**Me group** (player context, 6 tiles):
- `FrontendOverviewView` — FIFA card + custom fields + recent radar + print button
- `FrontendMyTeamView` — own card + team podium + teammate roster
- `FrontendMyEvaluationsView` — evaluation list with ratings and match context
- `FrontendMySessionsView` — attendance log, color-coded by status
- `FrontendMyGoalsView` — goal cards with status badges and due dates
- `FrontendMyProfileView` — **new** read-friendly personal details + link to WP account settings

**Coaching group** (coach + admin context, 6 tiles):
- `FrontendTeamsView` — every accessible team with podium + roster
- `FrontendPlayersView` — list (grouped by team) with detail sub-view via `?player_id=N`
- `FrontendEvaluationsView` — evaluation submission form
- `FrontendSessionsView` — session recording with attendance matrix
- `FrontendGoalsView` — goal creation + current-goals management
- `FrontendPodiumView` — aggregated top-3 across all accessible teams

**Analytics group** (observer / coach / admin, 2 tiles):
- `FrontendRateCardView` — reuses admin `PlayerRateCardView::render()` with a frontend base URL
- `FrontendComparisonView` — streamlined 4-slot player comparison (cards + facts + numbers + category averages; overlay charts remain admin-only)

**Supporting classes:**
- `FrontendViewBase` — abstract base with idempotent asset enqueueing and header + back button
- `CoachForms` — shared form rendering (evaluation, session, goals) — the AJAX contract with `FrontendAjax` is unchanged

### Router simplification

`DashboardShortcode::render()` dispatches cleanly:

```
if view empty          → tile landing
elseif view in me_slugs        → dispatchMeView      (requires player link)
elseif view in coaching_slugs  → dispatchCoachingView (requires coach/admin caps)
elseif view in analytics_slugs → dispatchAnalyticsView (requires tt_view_reports)
else                            → "Unknown section"
```

No role-branch tiebreaking, no fallback paths. Missing-capability cases produce explicit "This section is only available for …" notices.

### Slug disambiguation

Me-group slugs prefixed with `my-`: `my-evaluations` / `my-sessions` / `my-goals`. Coaching-group slugs of the same entity use the plain names (`evaluations`, `sessions`, `goals`). Dual-role users (coach who is also a player) now navigate unambiguously.

### Legacy views deleted

`src/Shared/Frontend/PlayerDashboardView.php` and `src/Shared/Frontend/CoachDashboardView.php` removed from the codebase. No parallel paths, no tab UI on the frontend.

## Files new in v3.0.0

**Security + migration:**
- `src/Infrastructure/Security/CapabilityAliases.php`
- `src/Shared/Admin/SchemaStatus.php`

**Frontend views:**
- `src/Shared/Frontend/FrontendViewBase.php`
- `src/Shared/Frontend/CoachForms.php`
- `src/Shared/Frontend/FrontendOverviewView.php`
- `src/Shared/Frontend/FrontendMyTeamView.php`
- `src/Shared/Frontend/FrontendMyEvaluationsView.php`
- `src/Shared/Frontend/FrontendMySessionsView.php`
- `src/Shared/Frontend/FrontendMyGoalsView.php`
- `src/Shared/Frontend/FrontendMyProfileView.php`
- `src/Shared/Frontend/FrontendTeamsView.php`
- `src/Shared/Frontend/FrontendPlayersView.php`
- `src/Shared/Frontend/FrontendEvaluationsView.php`
- `src/Shared/Frontend/FrontendSessionsView.php`
- `src/Shared/Frontend/FrontendGoalsView.php`
- `src/Shared/Frontend/FrontendPodiumView.php`
- `src/Shared/Frontend/FrontendRateCardView.php`
- `src/Shared/Frontend/FrontendComparisonView.php`

**Wiki:**
- `docs/migrations.md`

## Files deleted in v3.0.0

- `src/Shared/Frontend/PlayerDashboardView.php`
- `src/Shared/Frontend/CoachDashboardView.php`

## Files changed in v3.0.0

Extensive — essentially every admin page (CAP constants + UI gating), every write handler (cap check rewrites), all routing code (DashboardShortcode, FrontendTileGrid), the RolesService (complete rewrite with granular caps), and 3 wiki topics (access-control, player-dashboard, coach-dashboard) refreshed.

## Upgrade

1. Deploy the v3.0.0 code (extract ZIP, replace `talenttrack/` folder contents)
2. Navigate to any admin page. If a "TalentTrack schema needs updating" notice appears (it should, on first load after upgrade), click **Run migrations now**.
3. That's it. Role caps + schema state will be current.

For future minor-version updates (3.0.1, 3.1.0, etc.) the same flow works — notice appears, one click, done.

## Verify

**Migration UX**
1. After install: the banner should clear automatically once migrations have run.
2. Plugins page: next to the TalentTrack row, a "Run Migrations" link.
3. Simulate outdated state: edit `wp_options.tt_installed_version` via phpMyAdmin to an older version, reload admin. Banner reappears. Click, success.

**Capability refactor + Observer role**
1. Create a user with the Read-Only Observer role.
2. Log in as them. Visit wp-admin. Full TalentTrack menu visible.
3. Open Teams, Players, Evaluations, etc. — lists load, detail views open.
4. Action column in every list shows `—` (no Edit/Delete links). Add New button absent.
5. Visit the frontend dashboard. Analytics group tiles visible (Rate cards, Player comparison). Tap either — picker + full content.
6. Try to access admin edit URL directly (e.g., `admin.php?page=tt-players&action=edit&id=1`). Page loads read-only. Click Save — Unauthorized error at the controller.

**Frontend Me-group (Player role)**
1. Create a user linked to a player record. Log in.
2. Frontend dashboard shows the Me tile group.
3. Tap each tile — overview, my team, my evaluations, my sessions, my goals, my profile. Each renders a focused sub-page with back button. No tab bars.

**Frontend Coaching-group (Coach role)**
1. Log in as a Coach.
2. Coaching tile group visible.
3. Tap Teams — see accessible teams with podiums + rosters.
4. Tap Players — see list; tap a card — see detail view with "← Back to players" link.
5. Tap Evaluations — submission form. Submit — AJAX success message.
6. Same for Sessions and Goals. AJAX contract unchanged from v2.x.

**Frontend Analytics (any role with `tt_view_reports`)**
1. Tap Rate cards tile. Player picker. Pick a player. Rate card renders (FIFA card, headline numbers, radar, trend).
2. Tap Player comparison tile. 4 slot pickers. Pick players, filters. Compare button → cards row + basic facts + headline numbers + main category averages.

## Design notes

- **Why this is a major version.** Frontend rebuild alone breaks bookmarks to v2.21 tiles that never worked. Cap refactor changes cap names (though alias preserves behaviour). Migration UX changes the upgrade workflow. Any of the three warranted a minor; together they're a major.
- **Why aliases instead of a hard break on legacy caps.** Ecosystem courtesy. Custom admin code in clubs, shortcodes in child themes, etc. might check legacy cap names. The alias filter is tiny (~10 lines of map_meta_cap logic), runs on every cap resolve, and adds no practical overhead. It stays through v3.x. Considering removal in v4+.
- **Why `CoachForms` instead of keeping form rendering in `CoachDashboardView`.** Legacy class was deleted. New views needed the form renderers. Extract + delete the source.
- **Why the frontend comparison view skips overlay charts.** Chart.js multi-dataset setup is ~200 lines of bespoke JS. Primary use case for comparison-on-frontend is quick review, not deep analysis. Deep analysis → admin page. Cleaner separation.
- **Why the observer tile grid doesn't hide Coaching tiles.** The tile grid already gates tiles by cap at render time (they only appear for users with the right caps). Observer has no `tt_edit_*` so Coaching tiles don't render for them. The user messaging for wrong-role access applies when a URL is directly entered.
- **Why no breaking change to AJAX actions.** `FrontendAjax` handler names (`tt_fe_save_evaluation`, etc.) are preserved. The new forms post to the same endpoints. Anyone with a stored form-submit URL or a browser extension still works.
