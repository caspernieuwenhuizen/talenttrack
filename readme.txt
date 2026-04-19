=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.5.1
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
* Frontend login form on the dashboard
* Role-based frontend dashboard with user menu dropdown (profile + logout)
* Frontend-first application — no WP admin required except for administrators
* White-label branding
* REST API at `/wp-json/talenttrack/v1/` with standard envelope
* Versioned database migrations
* Logging, audit trail, feature toggles, environment-aware behaviour
* Full WordPress internationalization — Dutch translation included
* Automatic updates via GitHub Releases

**Source & issues:** https://github.com/caspernieuwenhuizen/talenttrack

== Changelog ==

= 2.5.1 — Sprint 1a polish =
* BUGFIX: non-administrator logout now works. v2.5.0 whitelisted `admin-ajax.php` in FrontendAccessControl but forgot `admin-post.php`, which broke the logout button for coaches, scouts, players, parents, and staff. Now whitelisted.
* Dashboard header now shows a proper user menu dropdown: click your name → "Edit profile" (links to WP profile page) + "Log out".
* Dropdown uses keyboard navigation (Esc closes) and click-outside-to-close.
* Added Dutch translation for "Edit profile" → "Profiel bewerken".

= 2.5.0 — Sprint 1a (frontend-first application) =
* FrontendAccessControl service: non-admins redirected from wp-admin; admin bar hidden; wp-login.php gated.
* LogoutHandler endpoint.
* Dashboard header with user name + logout button.
* All logins redirect to the dashboard page instead of wp-admin.

= 2.4.1 — i18n completion pass =

= 2.4.0 — Sprint 0 Phase 4 (full i18n) =

= 2.3.0 — Sprint 0 Phase 3 (observability & governance) =

= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =

= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =

= 2.0.1 =
* URL fix.

= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =

= 1.0.0 =
* Initial release.
