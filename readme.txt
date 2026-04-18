=== TalentTrack ===
Contributors: yourname
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Description ==

TalentTrack v2.0.0 introduces a full architectural refactor aligned with Sprint 0 engineering standards — modular enterprise architecture, service container, role expansion, and a frontend-first login experience — while keeping all existing v1.x features intact.

**What's included:**

* Full CRUD for players, teams, training sessions, evaluations, goals, attendance
* Expanded player profiles with preferred positions, foot, jersey, guardian contact, linked WP user
* Configurable evaluation categories and types (with per-type match-details flag)
* Radar/spider chart visualizations per player and per evaluation
* Configurable reports: progress, comparison, team averages
* **Frontend login** — `[talenttrack_dashboard]` shortcode shows a branded login form to logged-out visitors; no need to send users to wp-login.php
* Role-based frontend dashboard (Player / Coach / Admin views)
* White-label branding (logo, primary/secondary colors, academy name)
* REST API at `/wp-json/talenttrack/v1/`
* Automatic updates via GitHub Releases (requires Plugin Update Checker library)

**Sprint 0 architecture:**

* PSR-4 autoload (`TT\` → `src/`) via Composer with fallback for zero-dep installs
* Modular system — every feature is a Module implementing `ModuleInterface`
* Lightweight DI container (`TT\Core\Container`)
* Service-based infrastructure (`ConfigService`, `RolesService`, `QueryHelpers`)
* 7 TalentTrack roles: Head of Development, Club Admin, Coach, Scout, Staff, Player, Parent
* Central capability management
* PHPStan level 8 enforced on new code (baseline grandfathers existing code)
* GitHub Actions CI — PHP syntax lint + PHPStan + ZIP build on version tags
* Full WordPress i18n on new code (`talenttrack` text domain)

== Installation ==

1. (Optional, for GitHub auto-updates) Download the Plugin Update Checker library from https://github.com/YahnisElsts/plugin-update-checker/releases and extract into the `/plugin-update-checker/` folder.
2. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload Plugin.
3. Activate through WordPress Plugins screen.
4. Visit **TalentTrack → Configuration** to set up your academy.
5. Create a WordPress page and add the shortcode `[talenttrack_dashboard]` — logged-out visitors see the login form; logged-in users see their dashboard.

== Upgrading from v1.x ==

**IMPORTANT:** v2.0.0 restructures the codebase from `/includes/` to `/src/`. If you're upgrading an existing install:

1. **Delete the old `/includes/` folder** from your plugin installation before activating 2.0.0 (auto-update handles this).
2. Your database is preserved — no data loss. All tables, configuration, lookups, players, evaluations, goals, sessions carry over.
3. New roles (`tt_club_admin`, `tt_scout`, `tt_parent`) are added automatically; existing users keep their current roles.

== Changelog ==

= 2.0.0 — Sprint 0 Phase 1 (architectural foundation) =
* BREAKING: Namespaced code moved from `TT\` flat layout to proper Sprint 0 architecture under `/src/` (`Core`, `Modules`, `Domain`, `Infrastructure`, `Shared`).
* Added Composer support with PSR-4 autoload; zero-dependency fallback autoloader retained.
* Implemented `ModuleInterface`, `ModuleRegistry`, `Container` for modular enterprise architecture.
* New modules: Auth (login), Players, Teams, Evaluations, Sessions, Goals, Reports, Configuration, Documentation — each implementing `ModuleInterface`.
* Added 3 new roles: `tt_club_admin` (operational admin), `tt_scout` (evaluator without team ownership), `tt_parent` (read-only).
* Centralized role management via `RolesService`.
* Frontend login form — logged-out visitors on the dashboard shortcode now see a styled login form instead of a text notice.
* CI/CD: PHP lint + PHPStan level 8 added to GitHub Actions.
* Full WordPress i18n on new code with `talenttrack` text domain.

= 1.0.0 =
* Initial release

== Frequently Asked Questions ==

= How do I customize the login form? =
The login form uses the same CSS variables (`--tt-primary`, `--tt-secondary`) as the rest of the dashboard. Set your academy logo and colors in **TalentTrack → Configuration → Branding** and they'll apply automatically.

= Can I still have users log in via wp-login.php? =
Yes — the frontend login is purely additive. `/wp-login.php` continues to work for admin access.
