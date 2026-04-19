=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.5.0
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
* Role-based frontend dashboard (Player / Coach / Admin views)
* **Frontend-first application — no WP admin required except for administrators**
* White-label branding
* REST API at `/wp-json/talenttrack/v1/` with standard envelope
* Versioned database migrations
* Logging, audit trail, feature toggles, environment-aware behaviour
* Full WordPress internationalization — Dutch (nl_NL) translation included
* Automatic updates via GitHub Releases

**Source & issues:** https://github.com/caspernieuwenhuizen/talenttrack

== Changelog ==

= 2.5.0 — Sprint 1a (frontend-first application) =
* Added FrontendAccessControl service: non-administrators are redirected away from wp-admin; admin bar hidden for non-admins; wp-login.php access gated (except logout, password reset).
* Added LogoutHandler with `admin-post.php?action=tt_logout` endpoint.
* Dashboard header now shows the logged-in user's name and a Log out button.
* Successful logins (from either the TT login form or wp-login.php) now redirect to the TalentTrack dashboard page rather than wp-admin. Administrators can still reach wp-admin via the admin bar or by explicitly requesting a wp-admin URL.
* Password reset flow preserved; after requesting a reset link, user returns to the dashboard page with a confirmation notice.

= 2.4.1 — i18n completion pass =
* Completed the v2.4.0 i18n pass with LabelTranslator coverage across CoachDashboardView, PlayersPage, GoalsPage, SessionsPage.

= 2.4.0 — Sprint 0 Phase 4 (full i18n) =
* All strings translatable; Dutch translation included.

= 2.3.0 — Sprint 0 Phase 3 (observability & governance) =
* Logger, EnvironmentService, FeatureToggleService, AuditService.

= 2.2.0 — Sprint 0 Phase 2 Part 2 (REST envelope) =
* Standard `{success, data, errors}` envelope.

= 2.1.0 — Sprint 0 Phase 2 Part 1 (migrations) =
* Database migration system.

= 2.0.1 =
* URL fix.

= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =
* Modular architecture refactor.

= 1.0.0 =
* Initial release.

== Frequently Asked Questions ==

= I'm locked out of wp-admin! =
This release redirects all non-administrator users away from wp-admin. If you've somehow lost administrator access, connect via FTP and rename `wp-content/plugins/talenttrack` to temporarily disable the plugin. Then log in as an administrator and restore the folder name.

= How do I set the TalentTrack dashboard as my WordPress homepage? =
1. Create a WordPress page called e.g. "Dashboard" containing the shortcode `[talenttrack_dashboard]`.
2. In **Settings → Reading** → **Your homepage displays** → choose "A static page" → set Homepage to your Dashboard page.
3. Visitors hitting `yoursite.com/` now see the TT login (when logged out) or the TT dashboard (when logged in).
