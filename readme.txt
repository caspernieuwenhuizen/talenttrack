=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.4.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Description ==

TalentTrack is a WordPress plugin that gives soccer academies the structure to track every aspect of a player's development, make data-driven decisions, and communicate progress across coaches, players, and administrators.

**What's included:**

* Full CRUD for players, teams, training sessions, evaluations, goals, attendance
* Configurable evaluation categories and types
* Radar/spider chart visualizations
* Configurable reports: progress, comparison, team averages
* Frontend login form
* Role-based frontend dashboard
* White-label branding
* REST API at `/wp-json/talenttrack/v1/` with standard envelope
* Versioned database migrations
* Logging, audit trail, feature toggles, environment-aware behaviour
* Full WordPress internationalization — Dutch (nl_NL) translation included
* Automatic updates via GitHub Releases

**Source & issues:** https://github.com/caspernieuwenhuizen/talenttrack

== Changelog ==

= 2.4.1 — i18n completion pass =
* Completes the v2.4.0 i18n pass. Status labels in CoachDashboardView, PlayersPage, GoalsPage, and SessionsPage now route through LabelTranslator so they translate correctly.
* Attendance status dropdowns throughout admin UI and frontend coach dashboard now show translated labels.
* Goal status and priority dropdowns show translated labels in both admin Goals page and frontend coach dashboard.
* Player status shown on player detail view now translated (Active / Inactive / Trial / Released).
* Select Photo / Use media-picker strings on Player edit form now translatable.
* Completes Sprint 0.

= 2.4.0 — Sprint 0 Phase 4 (full i18n) =
* All user-facing strings translatable via `talenttrack` text domain.
* JavaScript strings localized via `wp_localize_script`.
* LabelTranslator utility class added.
* `languages/talenttrack.pot` template + Dutch (`nl_NL.po` + `.mo`).

= 2.3.0 — Sprint 0 Phase 3 (observability & governance) =
* Logger, EnvironmentService, FeatureToggleService, AuditService.
* New `tt_audit_log` table (migration 0002).
* Configuration → Feature Toggles and Audit Log admin tabs.

= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
* BREAKING: REST responses use standard `{success, data, errors}` envelope.

= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
* Database migration system with `tt_migrations` tracking table.

= 2.0.1 =
* URL fix for GitHub repository pointers.

= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =
* Reorganized to `/src/` with modular architecture.
* 3 new roles; frontend login form.

= 1.0.0 =
* Initial release.
