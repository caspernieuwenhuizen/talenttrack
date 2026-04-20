=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.9.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.9.0 — Sprint 1F: Roles as data + admin UI =
* NEW: tt_roles, tt_role_permissions, tt_user_role_scopes tables. Authorization decisions are now data-driven; role-to-permission mapping lives in the database.
* NEW: 9 system roles seeded on activation (club_admin, head_of_development, head_coach, assistant_coach, manager, physio, scout, parent, player) with a full permission matrix using the `<domain>.<action>` naming convention.
* NEW: TalentTrack → Roles & Permissions admin page. Browse every role, see its permission matrix and current assignments.
* NEW: "Role assignments" section on every Person edit page. Grant/revoke roles with optional scope (global / team / player / person) and start/end dates. Scope options restrict based on role type (head_coach can only be team-scoped, etc.).
* NEW: TalentTrack → Permission Debug diagnostic. Pick any WordPress user, see every resolved scope with source attribution (data-driven assignment, legacy bridge, or derived) plus the flattened permission set.
* NEW: Hooks for future extensibility — tt_role_granted, tt_role_revoked, tt_auth_resolve_permissions filter.
* CHANGED: AuthorizationService internals rewritten to evaluate permissions from tt_user_role_scopes + legacy bridge. Public API is unchanged; every pilot site from Sprint 1E keeps working without modification.
* Architectural: legacy bridge ensures zero data migration. Existing tt_team_people assignments and tt_teams.head_coach_id values continue to auto-grant equivalent permissions on the fly. Admins can progressively migrate to explicit role grants via the UI.

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
