=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.13.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.13.0 — Weighted overall rating per evaluation =
* NEW: Every evaluation has a weighted overall rating — computed as the weighted mean of main category effective ratings. Weights configurable per age group via the new TalentTrack → Category Weights admin page. Equal fallback (25/25/25/25 for four mains) when no weights are configured.
* NEW: Overall rating surfaces in three places: live-preview card on the evaluation form (updates on any input change), headline card on the detail view, and a new "Overall" column on the evaluations list. All three use the same compute algorithm — what you see while editing equals what gets displayed after save.
* NEW: tt_category_weights schema + migration 0009. Weights are integer percentages that must sum to exactly 100 per age group (hard-validated client-side + server-side). "Reset to equal" link per configured age group.
* NEW: EvalRatingsRepository::overallRating() for single-evaluation compute; overallRatingsForEvaluations() for batched list display (three SQL roundtrips regardless of row count).
* INTERNAL: Skip-null behavior — partial evaluations (fewer than all 4 mains rated) produce a weighted mean over just the rated mains, with "M of N categories rated" notation on all three surfaces.

= 2.12.2 — Translation fix + live average preview + two latent bug fixes =
* FIX: Evaluation categories and subcategories now render through the translator on every surface (admin tree, evaluation form, detail view, radar chart legends). Dutch translations for all 25 seeded labels apply automatically. New EvalCategoriesRepository::displayLabel() helper centralizes the translation point.
* NEW: Live main-category average preview on the evaluation form. While a coach rates subcategories, a read-only line at the bottom of each main's subs block shows "Main category average (computed): X (from N subcategories)" and updates on every input event. Matches the server's effectiveMainRating algorithm, so what you see equals what the detail view will show after save.
* FIX: Activator::repairEvalCategoriesTableIfCorrupt() now detects three corruption signals (missing category_key column, stale tt_lookups-shape columns, blank-label rows) instead of one. Catches edge cases where dbDelta had partially repaired the table in a prior activation without clearing the garbage.
* FIX: Migration 0008's "already retargeted?" check uses the remap map's value set instead of raw ID presence in tt_eval_categories. Prevents the false-positive that hit sites where old lookup IDs coincidentally matched corrupt row IDs in the new table.

= 2.12.1 — Recovery release for 2.12.0's broken schema =
* FIX: Renamed the `key` column on `tt_eval_categories` to `category_key`. The original column name was a MySQL reserved word that dbDelta silently dropped on some hosts (Strato / MariaDB), leaving the table in a corrupt state after activation and causing migration 0008 to fail with "Unknown column 'key' in 'INSERT INTO'".
* NEW: Activator::repairEvalCategoriesTableIfCorrupt() — self-healing routine that runs on every activation. Detects the corrupt 2.12.0 table state (table exists but missing the new column), safety-checks that no ratings reference it, and drops it so ensureSchema can recreate it cleanly. No-op on healthy sites and fresh installs.
* INTERNAL: All INSERT/SELECT statements and object-property accesses referencing the column updated across Activator, migration 0008, EvalCategoriesRepository, EvalRatingsRepository, QueryHelpers, and EvalCategoriesPage.
* NOTE: No data loss on sites that hit the 2.12.0 bug — the migration's throw-before-delete safeguard prevented any changes to `tt_lookups` or `tt_eval_ratings`. On upgrade, the repair routine drops the corrupt table, ensureSchema recreates it, the seed populates the canonical rows, and migration 0008 runs cleanly against the correct schema.

= 2.12.0 — Sprint 1I: Evaluation subcategories + Evaluations custom fields =
* NEW: Evaluation categories are now hierarchical. Each of the four main categories (Technical, Tactical, Physical, Mental) can have subcategories — 21 standard ones are seeded (Short pass, Long pass, First touch, Dribbling, Shooting, Heading, Offensive positioning, Defensive positioning, Game reading, Decision making, Off-ball movement, Speed, Endurance, Strength, Agility, Coordination, Focus, Leadership, Attitude, Resilience, Coachability). Clubs can add their own, rename labels, reorder, or deactivate.
* NEW: Either/or rating UX on the evaluation form. Per main category, coaches choose to rate directly OR drill into subcategories. Single click swaps modes. Mix freely across categories on the same evaluation.
* NEW: TalentTrack → Evaluation Categories admin page — dedicated tree view. Replaces the old Configuration sub-tab. Supports add-main, add-sub-under-main, edit, activate/deactivate. System categories (marked ✓) can be renamed but not deleted.
* NEW: Custom fields on Evaluations — same mechanism as Sprint 1H's five other entities. Custom Fields admin page gains an "Evaluations" tab. Nine native slugs available for the "Insert after" dropdown (player_id, eval_type_id, eval_date, opponent, competition, match_result, home_away, minutes_played, notes).
* NEW: New table tt_eval_categories with parent_id hierarchy. Migration 0008 copies existing lookup_type='eval_category' rows into it, retargets tt_eval_ratings.category_id, seeds the 21 subcategories, and deletes the old lookup rows only if every rating successfully retargeted. Idempotent; throws if any rating orphans so nothing is silently lost.
* NEW: EvalRatingsRepository::effectiveMainRating() — compute-on-read rollup. Returns direct rating if present, else mean of subcategory ratings, else null. Exposes source ('direct'|'computed'|'none') + sub_count so display layers can show "(averaged from 3 subcategories)" where appropriate.
* INTERNAL: QueryHelpers::get_categories() and get_evaluation() rewired to the new table. A legacy-shape shim on EvalCategoriesRepository keeps existing get_categories() callers working without changes. Dutch translations for ~57 new strings.
* DEFERRED: Drag-and-drop reorder, weighted rollup, hierarchy deeper than two levels, backfill of historical evaluations with subcategory ratings.

= 2.11.0 — Sprint 1H: Custom fields framework =
* NEW: Custom fields can be defined for all five entities — Players, People, Teams, Sessions, Goals — from a new TalentTrack → Custom Fields admin page. Previously only Players had custom fields, and they lived under a Configuration sub-tab.
* NEW: Custom fields can be positioned anywhere on the edit form via an "Insert after" dropdown that lists every native field slug for the target entity (plus "at end of form"). No more fixed "Additional Fields" section at the bottom.
* NEW: Five additional field types: long text (textarea), multi-select, URL, email, phone. Joins the existing text, number, select, checkbox, date types for ten total.
* NEW: Schema migration 0007 adds tt_custom_fields.insert_after column + idx_insert_after index. Additive, non-destructive. Existing custom fields keep working (they render at the end of the form, same as before).
* NEW: Framework pieces — FormSlugContract (single source of truth for native slugs per entity), CustomFieldsSlot (form-injection point called from each module's edit page), CustomFieldValidator::persistFromPost (one-call validate + upsert for save handlers).
* FIXED: GoalsPage::handle_save() didn't capture $wpdb->insert_id on new goal creation. Pre-existing bug since v2.6.x. Now captures the new ID so post-save integrations (including the new custom-fields persistence) work on create.
* INTERNAL: Old CustomFieldsTab retired; its handlers live on the new CustomFieldsPage. Shared\Frontend\CustomFieldRenderer and Shared\Validation\CustomFieldValidator both extended for the five new field types. 42 new Dutch strings translated.
* DEFERRED: Custom fields on Evaluations (Sprint 1I / v2.12.0), drag-and-drop reorder (polish backlog), list-page filtering on custom values (polish backlog), custom values in REST API responses, audit log of custom value writes, file upload / rich text / repeater field types.

= 2.10.1 — Migration loader fix + self-healing backfill =
* FIXED: Migration 0006_functional_role_backfill was marked applied but did nothing on some hosts. Root cause: `MigrationRunner::loadMigrationFromFile()` used `eval()`, which silently ignores `use` statements and resolves class names in the global namespace. This broke the `return new class extends Migration { ... }` pattern every migration file relies on. Replaced with `include` inside a closure — proper scoping, proper namespace handling.
* FIXED: Even on working hosts, Migration 0006 didn't notice when `$wpdb->update()` returned `false` or 0 rows affected. Added explicit `%d` format hints and a throw-on-partial-failure check so the runner no longer marks partial failures as applied.
* NEW: `Activator::repairFunctionalRoleBackfill()` — self-healing routine that runs on every activation, detects any tt_team_people rows with role_in_team set but functional_role_id NULL, and fills them in directly. Catches up sites that got stuck under 2.10.0's eval-based loader.
* FIXED: Removed `0005_authorization_rbac` from the migrations-applied pre-mark list (no such migration file ever existed — the warning "applied but file missing" on the migrations admin page has been visible on every v2.9.x install). Added a one-shot cleanup to delete the orphan row.
* CHANGED: Removed the "Assignments" column from the Roles & Permissions list page. That column only counted direct grants via tt_user_role_scopes, which is almost always zero for team-scoped auth roles now that assignments arrive via functional-role mapping. The detail page remains the authoritative assignment list. The Functional Roles list page keeps its Assignments column since that count is unambiguous.

= 2.10.0 — Sprint 1G: Functional roles architecture =
* NEW: Functional roles are now separated from authorization roles. `tt_functional_roles` catalogues the jobs people hold on a team (head_coach, assistant_coach, manager, physio, other); `tt_functional_role_auth_roles` maps each to one or more authorization roles. A new `functional_role_id` column on `tt_team_people` links the assignment to the catalogue.
* NEW: TalentTrack → Functional Roles admin page, with per-role mapping editors (tick which authorization roles a functional role should grant). Enables cases like "Head Coach who also has physio-level permissions" by mapping one functional role to multiple auth roles.
* NEW: Roles & Permissions detail page now has a Source column. Direct grants (from `tt_user_role_scopes`) are revocable; indirect grants (via a functional role) are shown read-only with a link to the underlying functional role.
* NEW: `team_member` system authorization role — minimal read-only team-scoped access (`players.view`, `sessions.view`). Default mapping target for the `other` functional role.
* CHANGED: AuthorizationService resolves team-based permissions through the new functional-role mapping. The Sprint 1F legacy bridges (the hardcoded `role_in_team` map and the `tt_teams.head_coach_id` column synthesis) are no longer in the resolution path. Both columns stay in the schema for backward compatibility.
* MIGRATION: `0006_functional_role_backfill` translates every `role_in_team` value into the matching `functional_role_id` FK and promotes every non-zero `tt_teams.head_coach_id` into an explicit `tt_team_people` row, creating `tt_people` records for WP users as needed. No permission surface changes on upgrade beyond the `other` → `team_member` default noted above.
* LOCALIZATION: 31 new Dutch translations (483 msgids total). Every new UI string in the Functional Roles, Roles & Permissions, Permission Debug, Team Staff Panel, and People admin pages localizes correctly.

= 2.9.1 — Role labels localized at display time =
* FIXED: Role labels (Club Admin, Head Coach, etc.) and role descriptions were stored in the database in English and rendered raw in the Roles & Permissions UI. They now translate at display time via RolesPage::roleLabel($role_key) and RolesPage::roleDescription($role_key) helpers, so the Dutch site shows Dutch labels everywhere.
* FIXED: Permission matrix domain headers (Players, Evaluations, Teams…) used ucfirst($domain) raw. Now go through RolesPage::domainLabel($domain_key) which returns translated strings.
* FIXED: Added missing "Role assignments" translation that was present in code but not in the .po baseline.
* Translation baseline now 449 entries. All role labels, descriptions, domain names, scope labels, and source-of-permission labels localize correctly in the Roles, Role Detail, Grant Form, and Permission Debug pages.
* ARCHITECTURAL NOTE: Data in tt_roles (the .label and .description columns) stays in English. Those are stable identifiers for programmatic access. UI display always goes through the localization helpers keyed on role_key. This pattern should be applied to any future UI-facing data stored in database columns.

= 2.9.0 — Sprint 1F: Roles as data + admin UI =
= 2.8.0 — Sprint 1E: Authorization Core =
= 2.7.2 — Full Dutch translations + People save-flow consistency =
= 2.7.1 — Fix PeopleModule silent-skip =
= 2.7.0 — Sprint 1D: People/Staff domain =
= 2.6.7 — Fix PHP parse error + bundle v2.6.6 =
= 2.6.6 — Schema reconciliation via Activator =
= 2.6.3 — Migrations admin page =
= 2.6.2 — Fail-loud save handlers =
= 2.6.1 — Custom fields integration =
= 2.6.0 — Custom fields foundation =
= 2.5.x — Frontend-first application =
= 2.4.x — i18n =
= 2.3.0 — Observability =
= 2.2.0 — REST envelope =
= 2.1.0 — Migrations =
= 2.0.x — Architectural foundation =
= 1.0.0 =
* Initial release.
