=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.4.0
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
* Frontend login form on the dashboard shortcode
* Role-based frontend dashboard (Player / Coach / Admin views)
* White-label branding
* REST API at `/wp-json/talenttrack/v1/` with standard envelope
* Versioned database migrations
* Logging, audit trail, feature toggles, environment-aware behaviour
* **Full WordPress internationalization — Dutch (nl_NL) translation included**
* Automatic updates via GitHub Releases

**Source & issues:** https://github.com/caspernieuwenhuizen/talenttrack

== Changelog ==

= 2.4.0 — Sprint 0 Phase 4 (full i18n) =
* All user-facing strings now translatable via the `talenttrack` text domain.
* JavaScript strings localized via `wp_localize_script` — no hardcoded English in `assets/js/public.js`.
* Status / priority / attendance labels now pass through a translation map instead of raw `ucwords()` fallback.
* New `LabelTranslator` utility class in `src/Infrastructure/Query/`.
* Added `languages/talenttrack.pot` template file for translators.
* **Dutch translation included** (`talenttrack-nl_NL.po` + compiled `.mo`). Automatically active when WordPress site language is set to Nederlands.
* This release completes Sprint 0.

= 2.3.0 — Sprint 0 Phase 3 (observability & governance) =
* Added Logger, EnvironmentService, FeatureToggleService, AuditService.
* New `tt_audit_log` table (migration 0002).
* Configuration → Feature Toggles and Configuration → Audit Log admin tabs.

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

== Frequently Asked Questions ==

= How do I switch to Dutch? =
Go to **Settings → General → Site Language** in WordPress and choose **Nederlands**. TalentTrack picks up the Dutch translation automatically.

= How do I add another language? =
Copy `languages/talenttrack.pot` to `languages/talenttrack-{locale}.po` (e.g. `talenttrack-de_DE.po` for German), translate the `msgstr` lines, then compile to `.mo` using any `.po` editor (Poedit, Loco Translate, or `msgfmt` on the command line). Place the resulting `.mo` file in the `languages/` folder.
