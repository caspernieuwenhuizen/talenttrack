=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.8.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.8.0 — Sprint 1E: Authorization Core =
* NEW: AuthorizationService — central, entity-scoped authorization layer.
* NEW: Team-scoped player access. Coaches assigned to a team (via tt_team_people) can now view and evaluate players of that team without needing a global capability.
* NEW: Hooks and filters for extending authorization decisions: tt_auth_check action, tt_auth_can_view_player / tt_auth_can_edit_player / tt_auth_can_evaluate_player / tt_auth_can_manage_team / tt_auth_can_assign_staff filters, plus generic tt_auth_check_result.
* NEW: Per-request caches for user→person_id, user→team_roles, and decision results. Automatically invalidated on staff-assignment writes.
* CHANGED: REST endpoints GET/PUT /players/{id} now use AuthorizationService. GET /players list endpoint now filters by viewability.
* CHANGED: Admin handlers for Evaluations, Players, Teams, and staff assignment now use entity-scoped auth checks for edit/update actions. Destructive deletes remain capability-gated.
* Architectural: this is Sprint 1E, the foundation for a 3-sprint RBAC arc. Sprint 1F will migrate the hardcoded rules to data-driven tables (tt_roles, tt_role_permissions, tt_user_role_scopes). Sprint 1G adds admin UI for managing those roles. Public API of AuthorizationService is the stable contract; internals change in 1F without breaking callers.

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
