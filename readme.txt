=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.3.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Description ==

TalentTrack is a WordPress plugin that gives soccer academies the structure to track every aspect of a player's development, make data-driven decisions, and communicate progress across coaches, players, and administrators.

**What's included:**

* Full CRUD for players, teams, training sessions, evaluations, goals, attendance
* Configurable evaluation categories and types (with per-type match-details flag)
* Radar/spider chart visualizations per player and per evaluation
* Configurable reports: progress, comparison, team averages
* Frontend login — `[talenttrack_dashboard]` shortcode shows a branded login form
* Role-based frontend dashboard (Player / Coach / Admin views)
* White-label branding
* REST API at `/wp-json/talenttrack/v1/` with `{success, data, errors}` envelope
* Versioned database migrations
* **Logging, audit trail, feature toggles, environment-aware behaviour**
* Automatic updates via GitHub Releases

**Source & issues:** https://github.com/caspernieuwenhuizen/talenttrack

== Changelog ==

= 2.3.0 — Sprint 0 Phase 3 (observability & governance) =
* Added central Logger service (debug / info / warning / error) writing to WordPress error log. Debug messages suppressed in production.
* Added EnvironmentService honoring WP_ENVIRONMENT_TYPE (production / staging / development / local).
* Added FeatureToggleService — boolean feature flags backed by config, with admin UI under Configuration → Feature Toggles.
* Added AuditService + new tt_audit_log table (migration 0002). Records player/evaluation/team/session/goal/config changes + successful logins.
* New Configuration → Audit Log admin tab with filters by action, entity type, and user.
* Audit recording can be toggled off globally via Configuration → Feature Toggles → "Audit log".
* All new services injected via the container; module code unchanged.

= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
* BREAKING: All REST responses use standard `{success, data, errors}` envelope.
* Added RestResponse factory and BaseController abstract.
* Proper HTTP status codes on REST endpoints.

= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
* Database migration system with tt_migrations tracking table.
* Backward-compatible with existing installs.
* Activator slimmed to delegate schema to versioned migrations.

= 2.0.1 =
* URL fix for GitHub repository pointers.

= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =
* Code reorganized to /src/ with Core, Modules, Domain, Infrastructure, Shared.
* Composer PSR-4 autoload with fallback.
* ModuleInterface, ModuleRegistry, Container.
* 3 new roles: tt_club_admin, tt_scout, tt_parent.
* Frontend login form on dashboard shortcode.

= 1.0.0 =
* Initial release.
