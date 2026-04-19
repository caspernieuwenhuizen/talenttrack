=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.2
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 2.6.2 — Critical bugfix: schema reconciliation + fail-loud saves =
* FIXED: Evaluation, session, and goal saves silently failed on sites whose schema pre-dated v2.0.0. Symptom: form reported "Saved" but no row landed in the database; admin list pages showed "No X"; player dashboard tabs were empty.
* New migration 0004_schema_reconciliation adds missing v2.x columns to legacy tt_evaluations, tt_attendance, and tt_goals tables (eval_type_id, opponent, competition, match_result, home_away, minutes_played, updated_at, status, priority). Non-destructive — preserves v1.x columns and data.
* Every $wpdb->insert and $wpdb->update in admin pages, REST controllers, and frontend AJAX now checks the return value. Failures now (a) log via the structured Logger, (b) return an error to the user with the underlying DB error message, and (c) never pretend success.
* Admin form validation errors now show a red error banner with the DB error message, and the user is redirected back to the form (not dropped to the list with a false success toast).

= 2.6.1 — Sprint 1b part 2 (custom fields integration) =
* Custom fields on Admin Players form with validation.
* Custom fields visible on player dashboard Overview + coach Player Detail.
* REST API includes custom_fields in responses; POST/PUT accept them with 422 on validation failure.
* "Go to Admin" link in user menu dropdown for administrators.

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
