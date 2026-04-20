=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.9.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

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
