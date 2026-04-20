=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.6
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.6.6 — Schema reconciliation done directly via Activator =
* Removed the failed file-based migration approach for schema reconciliation (v2.6.2–v2.6.5) which hit cascading issues with PHP's include cache, closure scoping, and eval().
* Schema reconciliation is now performed directly in Activator::activate() using dbDelta (the WordPress-native, battle-tested tool for this exact job) plus explicit ALTER statements for legacy NOT NULL relaxation.
* Trigger: deactivate and reactivate the plugin once after installing v2.6.6. That one click does everything the migration system was trying (and failing) to do.
* The migration system (admin page, runner) remains in place for future use, but is bypassed for this specific schema fix. Migrations 0001-0004 are recorded as applied during activation, so the runner has nothing pending.

= 2.6.5 — [failed] Migration loader via eval() =
= 2.6.4 — [failed] Migration loader hardening =
= 2.6.3 — Migrations admin page =
= 2.6.2 — Fail-loud save handlers =
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
