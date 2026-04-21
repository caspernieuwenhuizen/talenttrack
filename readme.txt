=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.22.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.22.0 — Hierarchical Back Button + Help Wiki =
* FIXED: Back button no longer ping-pongs. Previously the v2.19 referer-based back button would return you to your edit form when clicked twice (because the target page's referer was the page you just came from). Rewrote to use an explicit parent-page hierarchy map. Clicking back now always walks one level closer to the dashboard; repeated clicks reliably reach home.
* NEW: Breadcrumb UI above the back link on every admin page. Shows the trail from Dashboard down to the current page. Each segment is clickable — tap any ancestor to jump directly there.
* NEW: Help & Docs is now a markdown-based wiki. 18 topic files authored (getting-started, teams-players, people-staff, evaluations, eval-categories-weights, sessions, goals, reports, rate-cards, player-comparison, usage-statistics, configuration-branding, custom-fields, bulk-actions, printing-pdf, player-dashboard, coach-dashboard, access-control). Two-pane layout with sticky TOC sidebar + content pane. Client-side search filters topics by title and summary. Wiki breadcrumb "Help › Group › Topic" on each topic page.
* NEW: "? Help on this topic" contextual links on 13 admin pages (Players, Teams, Evaluations, Sessions, Goals, People, Reports, Rate Cards, Player Comparison, Evaluation Categories, Category Weights, Custom Fields, Configuration, Usage Statistics). Each links to the relevant wiki topic.
* COMMITMENT: Going forward, every sprint that touches a feature also updates the relevant help topic(s) in the same ZIP. CHANGES.md will note which topics were updated.
* INTERNAL: New BackNavigator class with hierarchical parent map. New Markdown renderer (minimal, Composer-free). New HelpTopics registry. All existing BackButton::render() call sites continue to work unchanged (legacy fallback_url parameter preserved for back-compat, now silently ignored in favor of the parent map).

= 2.21.0 — Tile-Based Frontend + Read-Only Observer Role =
* NEW: Tile-based frontend landing page. The [talenttrack_dashboard] shortcode now opens onto a role-gated tile grid (Me / Coaching / Analytics / Administration) with greeting, section labels, colored icon tiles, hover lift, and full mobile responsiveness. Tapping a tile drills into the existing PlayerDashboardView/CoachDashboardView — no break in existing tab navigation.
* NEW: "← Back to dashboard" link at the top of every tile sub-view, via the new FrontendBackButton helper. Fixed destination (shortcode page sans query params) — more reliable than HTTP referer on frontend.
* NEW: tt_readonly_observer role — "Read-Only Observer". Has `read` + `tt_view_reports` only. Sees the Analytics tile group (Rate cards, Player comparison) plus all rate card / report pages, but CANNOT save evaluations, edit players, create sessions, set goals, or change configuration. Use for assistant coaches in training, board members, external auditors, or parent-liaisons needing extra viewing rights.
* INTERNAL: Tile visibility driven entirely by WordPress capabilities — the same tile set automatically respects the observer role. Deep capability refactor (splitting tt_manage_* into tt_view_* + tt_edit_* pairs) queued for v2.22.0.
* No schema changes. No migrations. Existing ?tt_view bookmarks continue to work and skip the tile landing transparently.

= 2.20.0 — Player Comparison + Access Control Tiles + Reports Tile Launcher =
* NEW: Player Comparison admin page under Analytics. Side-by-side comparison of up to 4 players with cross-team support. Shows FIFA cards, basic facts, headline numbers, main category averages, overlay radar chart, overlay trend chart. Mixed-age-group comparisons get an inline notice about weighted overall ratings.
* NEW: Access Control group on the dashboard and in the admin submenu. The existing Roles & Permissions, Functional Roles, and Permission Debug pages — previously orphaned at the flat bottom of the TalentTrack submenu — now sit under a proper "Access Control" separator, with matching tile group on the dashboard (red accent).
* CHANGED: Reports page redesigned as a tile launcher. Legacy combined form retained as the "Player Progress & Radar" tile. Two new first-class reports added: Team rating averages (per-team averages across main categories) and Coach activity (evaluations saved per coach, configurable 7/30/90/180/365-day window).
* CHANGED: Menu registration centralized. People and Authorization pages no longer self-register via their module boot — Menu::register() owns all TalentTrack submenu entries, keeping group ordering and separators consistent. Existing admin_post handlers unchanged.
* NEW: "? Help on this topic" placeholder links on Reports and Player Comparison pages. Wired to ?page=tt-docs&topic=<slug>; will light up once the 2.21.0 help wiki ships.
* INTERNAL: No schema changes. No migrations. New PlayerComparisonPage class; AuthorizationModule and PeopleModule registerMenu methods neutered.

= 2.19.0 — Drag-reorder Lookups + Back Button + Clickable KPIs + Compact Stat Cards =
* NEW: Drag-to-reorder on lookup tables. Positions, Age Groups, Foot Options, Goal Status, Goal Priority, Attendance Status, Evaluation Types — drag the ⋮⋮ handle to reorder. Saves via AJAX with a success toast; sort_order cells update live. Powered by SortableJS. Fixes the long-standing bug where the sort_order column existed but had no UI to set values.
* NEW: "← Back" link at the top of every edit/detail admin page (Players form + view, Teams form, Evaluations form + view, Sessions form, Goals form, People form, Custom Fields form, Evaluation Categories form). Uses HTTP referer with safe fallbacks — never takes you out of the plugin.
* NEW: Clickable KPIs on Usage Statistics. All 6 headline tiles link to event/user lists. Active-by-role bars link to role-filtered user lists. Top-pages rows link to per-page visit details. Inactive-user rows link to per-user event timelines. DAU + Evaluations charts are click-to-drill-down — click any day to see who/what. New hidden details page at tt-usage-stats-details handles all drill-down routes.
* CHANGED: Dashboard stat cards redesigned. Compact horizontal layout (~58px tall vs ~130px), icon on the left + count + "+N this week" delta pill + label stacked right. Border-left accent stripe in per-entity color replaces the heavy gradient background. Delta shows row additions in the last 7 days; green pill for positive, gray for zero.
* INTERNAL: New BackButton, DragReorder, UsageStatsDetailsPage classes. No schema changes.

= 2.18.0 — Usage Statistics + Dashboard as Workspace =
* NEW: Usage Statistics admin page (Analytics → Usage Statistics, admin-only). Tracks logins + admin page views. Headline tiles for 7/30/90-day login + active-user counts. Daily-active-users line chart (90 days). Evaluations-created-per-day bar chart (90 days, sourced from evaluations table so historical data appears immediately). Active-by-role breakdown (Admins/Coaches/Players/Other). Most-visited admin pages (top 10). Inactive-user nudge list (30+ day absence).
* NEW: 90-day rolling retention on usage events via daily WP-Cron prune job (tt_usage_prune_daily). No IP addresses or user agents captured — just user_id + event_type + optional target.
* NEW: Migration 0011 creates tt_usage_events table (idempotent). ensureSchema handles fresh installs.
* NEW: UsageTracker service with public record($user_id, $type, $target) method for future instrumentation hooks.
* CHANGED: TalentTrack Dashboard fully rewritten. Overview section with 5 clickable gradient stat cards (Players / Teams / Evaluations / Sessions / Goals), each showing active-count and linking to its list page. Grouped tile sections below mirroring the admin menu structure: People / Performance / Analytics / Configuration / Help. Every tile is navigation with icon + label + one-line description. Cap-gated — users only see tiles they can access. Hover-lift, gradient-tinted icons per group. Mobile-responsive (collapses to single column under 640px).
* CHANGED: Dashboard stat counts now filter on archived_at IS NULL (consistent with list views).
* DESIGN: Dashboard prepared as foundation for upcoming front-end admin work.

= 2.17.0 — Admin Menu Overhaul + Bulk Archive/Delete + Isolated Print =
* NEW: Admin menu grouped into logical sections (People / Performance / Analytics / Configuration) with visual separator headings between groups.
* NEW: Bulk archive and delete across Players, Teams, Evaluations, Sessions, Goals, People. Checkboxes per row, bulk action dropdown, status tabs (Active / Archived / All) with counts. Archive is reversible, Delete permanently is admin-only.
* NEW: tt_players, tt_teams, tt_evaluations, tt_sessions, tt_goals, tt_people all get archived_at + archived_by columns via migration 0010 (idempotent).
* CHANGED: Teams admin list no longer shows Head Coach column. Field still editable on the team edit form.
* CHANGED: Print report route is now fully isolated from the WP admin shell and theme chrome. New PrintRouter intercepts print requests at admin_init and template_redirect, emits a standalone HTML document with visible 🖨 Print and 📄 Download PDF buttons (no more auto-fire). Download PDF uses html2canvas + jsPDF (raster A4 portrait, charts included, ~500KB JS loaded only on the print page).
* DEFERRED: App usage statistics — grew into enough scope to deserve its own release. Slated for v2.18.0.
* INTERNAL: ArchiveRepository and BulkActionsHelper provide reusable archive/restore/delete + bulk-action UI across every list page.

= 2.16.0 — Epic 2 Sprint 2C: Neutral Tier + Printable Report + Mobile Polish =
* CHANGED: Gold/silver/bronze tiers are now podium-position awards, not rating-based. 1st place always gets a gold card regardless of absolute rating; 2nd silver; 3rd bronze. Matches how real podiums work.
* NEW: Neutral dark-navy colorway for every card outside a ranking context (own dashboard, rate card Card view, etc.). Premium feel without claiming an unearned medal. Chrome alternative included as commented CSS for one-line swap.
* NEW: Printable A4 player report. Single-page portrait layout with club header, FIFA-style card, three headline numbers, main/subcategory breakdown, trend line + radar charts, and signature footer. Triggered via "🖨 Print report" button on admin rate card page and both frontend dashboards. Auto-invokes browser print dialog; save-as-PDF works out of the box.
* NEW: Print access control — admins print any player, coaches print players on their coached teams only, players print their own report only.
* NEW: Frontend mobile responsive layer. Tabs scroll horizontally on narrow viewports, roster grid collapses to 2-col then 1-col, tables collapse to stacked mini-cards on phones, forms become touch-friendly with full-width inputs.
* NEW: Player card mobile breakpoints — all variants collapse to sm-size on phones, podium stacks vertically under 480px with correct visual order.
* NEW: Rate card page mobile behavior — filter bar, headline tiles, charts all stack on tablet; breakdown table collapses to mini-cards on phone.
* INTERNAL: PlayerCardView::renderCard() gains $tier_override parameter. renderPodium() passes explicit positional tiers. tierForRating() retained but no longer called by default paths.

= 2.15.0 — Epic 2 Sprint 2B: FIFA-style Player Cards + Team Podium =
* NEW: Collectible-card visual summary per player, tiered gold / silver / bronze by rolling-average rating (≥4.0 / ≥3.0 / <3.0). Pure CSS — metallic gradients, crystalline facet overlay, animated shine sweep, staggered entrance animations, Oswald + Manrope typography via Google Fonts. Size variants sm / md / lg.
* NEW: "Mijn team" tab on the player front-end dashboard. Shows own card centered, team top-3 podium below, teammate roster listed by name and photo only (no ratings exposed per privacy design decision).
* NEW: Top-3 podium per coached team on the coach front-end dashboard's Roster tab. Podium arranged as 2-1-3 with 1st center and elevated.
* NEW: FIFA-style card embedded on the Player Detail tab of the coach dashboard alongside the classic info block.
* NEW: Player card embedded on the Overview tab of the player front-end dashboard, right side next to existing content.
* NEW: Standard / Card view toggle on the admin rate card page (and Players edit → Rate card tab). Card view shows the large version of the tiered card centered.
* NEW: TeamStatsService::getTopPlayersForTeam() for batched ranking; ::getTeammatesOfPlayer() for roster queries.
* NEW: PlayerCardView::renderCard() + ::renderPodium() reusable across admin and front-end surfaces.
* ACCESSIBILITY: Cards use role="img" with descriptive aria-label including tier and rating. prefers-reduced-motion honored — static cards without entrance animations, shine sweep, or hover transform for motion-sensitive users.

= 2.14.0 — Epic 2 Sprint 2A: Player Rate Card =
* NEW: Player rate card — one-page summary per player. Three headline numbers (most recent / rolling average of last 5 / all-time average), per-main-category breakdown with trend arrows (improving / declining / stable), expandable subcategory accordion, trend line chart (Chart.js), radar chart overlaying last 3 evaluations, filterable by date range and evaluation type.
* NEW: TalentTrack → Player Rate Cards — top-level admin page with player picker.
* NEW: "Rate card" tab on the Players edit page, embedding the same component.
* NEW: PlayerStatsService with composable analytics methods (headline numbers, main breakdown, sub breakdown, trend series, radar snapshots) — foundation for future Epic 2 sprints (team rate cards, comparative views).
* INTERNAL: Chart.js 4.4 loaded from CDN; graceful fallback to text-only when CDN unreachable.
* FIX: seedEvalCategoriesIfEmpty() now bails if any main category already exists in any language. Prevents the duplicate-mains bug where English canonical mains would appear alongside Dutch-keyed mains after reactivation.

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
