=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.5
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.6.5 — Migration loader uses eval() for guaranteed fresh execution =
* FIXED: Diagnostic output from v2.6.4 confirmed that the Kernel's boot-time MigrationRunner::run() call was including the migration file during page load. When the admin page then tried to run it again, PHP's include tracking returned int(1) instead of re-executing, so the file's return value (the migration object) was never seen. v2.6.5 reads the file contents via file_get_contents() and evaluates via eval(), which gives a fresh execution scope every call regardless of prior include history.

= 2.6.4 — Migration loader hardening =
= 2.6.3 — Migrations admin page =
= 2.6.2 — Schema reconciliation + fail-loud saves =
= 2.6.1 — Sprint 1b part 2 (custom fields integration) =
= 2.6.0 — Sprint 1b part 1 (custom fields foundation) =
= 2.5.x — Sprint 1a (frontend-first application) =
= 2.4.x — Sprint 0 Phase 4 (i18n) =
= 2.3.0 — Sprint 0 Phase 3 (observability) =
= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
= 2.0.x — Sprint 0 Phase 1 (architectural foundation) =
= 1.0.0 =
* Initial release.
