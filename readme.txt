=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.10.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

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
