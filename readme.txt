=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.4
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.6.4 — Migration loader hardening =
* FIXED: MigrationRunner now loads migration files through a closure-isolated include, with exception and stray-output capture. Previously the loader could fail to re-read the file's return value when the file had been included earlier in the same request, producing a misleading "file does not return a runnable migration" error.
* FIXED: Migration 0004 rewritten to use the classic Migration-base-class pattern (matching 0001-0003) and to inline its helpers, removing autoload timing from the equation. Functionally identical to v2.6.2/v2.6.3 0004 but more robust.
* Error messages in the Migrations admin page now include diagnostic information about what the migration file actually returned, with hints for common failure modes.

= 2.6.3 — Migrations admin page + silent-skip bugfix =
= 2.6.2 — Schema reconciliation + fail-loud saves =
= 2.6.1 — Sprint 1b part 2 (custom fields integration) =
= 2.6.0 — Sprint 1b part 1 (custom fields foundation) =
= 2.5.1 — Sprint 1a polish =
= 2.5.0 — Sprint 1a (frontend-first application) =
= 2.4.x — Sprint 0 Phase 4 (i18n) =
= 2.3.0 — Sprint 0 Phase 3 (observability) =
= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
= 2.0.0–2.0.1 — Sprint 0 Phase 1 (architectural foundation) =
= 1.0.0 =
* Initial release.
