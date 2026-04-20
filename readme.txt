=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.7.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.7.0 — Sprint 1D: People/Staff domain =
* NEW: People module. TalentTrack → People menu item lists anyone the system tracks — staff, parents, scouts, external people. A person isn't intrinsically staff; they become staff for a given team through a team assignment.
* NEW: Team-staff assignments. Team edit page now has a Staff section where you can assign multiple people to a team with per-team roles (head coach, assistant coach, manager, physio, other). Multiple people per role allowed.
* NEW: tt_people and tt_team_people tables, added to the authoritative schema via Activator::ensureSchema() and dbDelta (same pattern as v2.6.6).
* NEW: Hooks tt_person_created and tt_person_assigned_to_team for future integrations.
* KEPT: tt_teams.head_coach_id is deprecated but still read during getTeamStaff() so existing legacy head coach data still displays. New assignments write to tt_team_people only; the legacy column is not updated on save.
* FIXED: MigrationRunner::scanMigrationFiles() now filters out index.php and any files not matching the NNNN_name.php migration convention. Removes the phantom "index" row from the Migrations admin page.
* Manual cleanup recommended after upgrade: delete database/migrations/0004_schema_reconciliation.php via FTP since the Activator handles that schema directly now. Safe if left in place (will be marked applied).

= 2.6.7 — Fix PHP parse error + bundle v2.6.6 =
= 2.6.6 — Schema reconciliation via Activator =
= 2.6.5 — [failed lint] Migration loader via eval() =
= 2.6.4 — [failed lint] Migration loader hardening =
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
