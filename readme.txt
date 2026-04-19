=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.6.0 — Sprint 1b part 1 (custom fields foundation) =
* Added polymorphic custom-field system: two new tables (`tt_custom_fields`, `tt_custom_values`) scoped by entity_type. Designed to support custom fields for players now, and other entities (teams, sessions, goals) in future releases without further migrations.
* New migration 0003 creates the tables.
* New Configuration tab: **Player Custom Fields**. Admins can create, edit, deactivate, reactivate, and drag-to-reorder custom fields. Supported types: text, number, select, checkbox, date. Select-type fields use a reusable `OptionSetEditor` UI with add/remove/reorder options.
* Generic primitives shipped for reuse:
  - `CustomFieldsRepository`, `CustomValuesRepository` — polymorphic CRUD.
  - `CustomFieldValidator` — per-type validation with structured errors.
  - `CustomFieldRenderer` — renders inputs per type (for forms) + display helpers (for read-only views).
  - `OptionSetEditor` — reusable managed-option-list UI block.
  - `admin-sortable.js` — vanilla drag-reorder for any list.
* **No user-facing changes yet beyond the new admin tab.** Fields do not appear on the Player form yet — that integration ships in v2.6.1.

= 2.5.1 — Sprint 1a polish =
* BUGFIX: non-administrator logout works. Whitelisted admin-post.php in FrontendAccessControl.
* User-menu dropdown in dashboard header: Edit Profile + Log out.

= 2.5.0 — Sprint 1a (frontend-first application) =
* Frontend-first: wp-admin gated for non-admins; admin bar hidden; wp-login.php redirects home.
* LogoutHandler + login redirects to dashboard page.

= 2.4.1 — i18n completion pass =
= 2.4.0 — Sprint 0 Phase 4 (full i18n) =
= 2.3.0 — Sprint 0 Phase 3 (observability & governance) =
= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
= 2.0.1 =
= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =
= 1.0.0 =
* Initial release.
