=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.3
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.6.3 — Migrations admin page + silent-skip bugfix =
* NEW: TalentTrack → Migrations admin page. Lists all migration files with their applied/pending status and a "Run" button for pending migrations. "Run All Pending" button when multiple are pending. Errors are surfaced verbatim instead of silently logged.
* NEW: Warning banner on every TalentTrack admin page when pending migrations exist, with a one-click link to the Migrations page.
* FIXED: MigrationRunner silently skipped migrations that used the v2.6.2+ simplified pattern (anonymous class with `up(\wpdb)` method, not extending the Migration base class). The runner now accepts both patterns — any object with an `up()` method is runnable. This is why v2.6.2's migration 0004 didn't auto-apply.
* FIXED: MigrationRunner now captures `$wpdb->last_error` during migration execution and reports it via the admin page, instead of relying solely on PHP exception catching.
* Migration 0004_schema_reconciliation is shipped again in this release (v2.6.2 + a fix), so sites that never got it applied will see it as pending on the Migrations page and can click Run.

= 2.6.2 — Schema reconciliation + fail-loud saves =
* Migration 0004 adds missing v2.x columns to legacy tt_evaluations, tt_attendance, tt_goals tables.
* All $wpdb->insert/update calls now check return values; failures surface to the user.

= 2.6.1 — Sprint 1b part 2 (custom fields integration) =
= 2.6.0 — Sprint 1b part 1 (custom fields foundation) =
= 2.5.1 — Sprint 1a polish =
= 2.5.0 — Sprint 1a (frontend-first application) =
= 2.4.1 — i18n completion pass =
= 2.4.0 — Sprint 0 Phase 4 (full i18n) =
= 2.3.0 — Sprint 0 Phase 3 (observability & governance) =
= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
= 2.0.1 =
= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =
= 1.0.0 =
* Initial release.
