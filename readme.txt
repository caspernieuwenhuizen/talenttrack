=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Description ==

TalentTrack is a WordPress plugin that gives soccer academies the structure to track every aspect of a player's development, make data-driven decisions, and communicate progress across coaches, players, and administrators.

**What's included:**

* Full CRUD for players, teams, training sessions, evaluations, goals, attendance
* Expanded player profiles with preferred positions, foot, jersey, guardian contact, linked WP user
* Configurable evaluation categories and types (with per-type match-details flag)
* Radar/spider chart visualizations per player and per evaluation
* Configurable reports: progress, comparison, team averages
* Frontend login — `[talenttrack_dashboard]` shortcode shows a branded login form to logged-out visitors
* Role-based frontend dashboard (Player / Coach / Admin views)
* White-label branding (logo, primary/secondary colors, academy name)
* REST API at `/wp-json/talenttrack/v1/` with standard `{success, data, errors}` envelope
* Versioned database migrations
* Automatic updates via GitHub Releases

**Source & issues:** https://github.com/caspernieuwenhuizen/talenttrack

== Installation ==

1. (Optional, for GitHub auto-updates) Download the Plugin Update Checker library from https://github.com/YahnisElsts/plugin-update-checker/releases and extract into the `/plugin-update-checker/` folder.
2. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload Plugin.
3. Activate through WordPress Plugins screen.
4. Visit **TalentTrack → Configuration** to set up your academy.
5. Create a WordPress page and add the shortcode `[talenttrack_dashboard]`.

== Changelog ==

= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
* BREAKING: All REST responses now use a standard envelope: `{success, data, errors}`.
* Added `RestResponse` factory and `BaseController` abstract to `src/Infrastructure/REST/`.
* Rewrote `PlayersRestController` and `EvaluationsRestController` to use the envelope.
* Proper HTTP status codes on REST endpoints: 200/201 on success, 400 on bad input, 404 on missing resources, 422 on validation errors.
* Domain-specific error codes (e.g. `player_not_found`, `missing_field`) for consumer-friendly handling.
* Existing frontend dashboard and WP Admin UI unaffected — this change is purely in the REST layer.

= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
* Added database migration system: new `src/Infrastructure/Database/` + `database/migrations/` folders.
* Added `tt_migrations` tracking table.
* Migration runner executes on activation and on boot (idempotent).
* Backward-compatible with existing installs — legacy schema marked as applied automatically.
* `Activator.php` slimmed down; schema now lives in versioned migration files.

= 2.0.1 =
* URL fix: Plugin URI, Author URI, and GitHub update checker all point to the correct repository.

= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =
* Namespaced code reorganized to `/src/` with Core, Modules, Domain, Infrastructure, Shared layout.
* Added Composer support with PSR-4 autoload; zero-dependency fallback autoloader retained.
* Implemented `ModuleInterface`, `ModuleRegistry`, `Container` for modular enterprise architecture.
* Added 3 new roles: `tt_club_admin`, `tt_scout`, `tt_parent`.
* Centralized role management via `RolesService`.
* Frontend login form on the dashboard shortcode.
* CI/CD: PHP lint + PHPStan level 8 added to GitHub Actions.
* WordPress i18n on new code.

= 1.0.0 =
* Initial release

== Frequently Asked Questions ==

= Does the REST envelope break anything I've already built? =
If you've written any code that calls the TalentTrack REST API directly, yes — responses now live inside a `data` field instead of at the root. No admin UI, frontend dashboard, or shortcode is affected.

= How do I customize the login form? =
The login form uses the same CSS variables (`--tt-primary`, `--tt-secondary`) as the rest of the dashboard. Set your academy logo and colors in **TalentTrack → Configuration → Branding**.
