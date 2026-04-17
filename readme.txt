=== TalentTrack ===
Contributors: yourname
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional player development tracking system for soccer academies — evaluations, team management, goals, attendance, and reporting.

== Description ==

TalentTrack gives soccer academies the structure to track every aspect of a player's development, make data-driven decisions, and communicate progress across coaches, players, and administrators.

**Features:**

* Full CRUD management for players, teams, training sessions, evaluations, goals, and attendance — all stored in dedicated custom database tables
* Expanded player profiles: first/last name, DOB, nationality, height, weight, preferred foot, multiple preferred positions, jersey number, guardian contact, linked WP user
* Teams with age-group and head coach assignment
* Configurable evaluation categories (Technical, Tactical, Physical, Mental, or your own)
* **Configurable evaluation types** — define your own types (Training, Match, Friendly, Trial…) and mark which require match details
* Match evaluations capture opponent, competition, result, home/away, and minutes played
* Spider/radar chart visualizations per player and per evaluation, with progression overlays
* **Full CRUD Configuration module** — every list (positions, foot options, age groups, statuses, priorities) is edited as proper rows with add/edit/delete actions
* Configurable reporting: progress over time, player comparison, team averages, development score ranking, with saveable filter presets
* Role-based frontend dashboard via `[talenttrack_dashboard]` shortcode — players see their own data, coaches manage their teams from the frontend with AJAX
* Built-in step-by-step documentation for admins, coaches, and players
* White-label branding: logo, primary/secondary colors, academy name
* REST API with full CRUD on players, evaluations, and config
* **Automatic updates via GitHub Releases** (requires Plugin Update Checker library)

== Installation ==

1. Download the Plugin Update Checker library from https://github.com/YahnisElsts/plugin-update-checker/releases and extract its contents into the `/plugin-update-checker/` folder before installing (required for GitHub auto-updates — optional for basic functionality).
2. Upload the `talenttrack` folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload Plugin.
3. Activate through the WordPress **Plugins** screen.
4. Go to **TalentTrack → Configuration** to set up your academy, lookup lists, and branding.
5. Go to **TalentTrack → Help & Docs** for step-by-step guides for every role.
6. Add `[talenttrack_dashboard]` to any page to expose the role-based frontend dashboard.

== Frequently Asked Questions ==

= How do automatic updates work? =

TalentTrack uses the Plugin Update Checker library to poll your GitHub Releases page. When you publish a new release (tagged v1.0.0, v1.1.0, etc.), WordPress will show an update notification. Make sure to edit the GitHub URL in `talenttrack.php` to point to your own repository.

= Can I extend TalentTrack? =

Yes — hooks include `tt_before_save_evaluation`, `tt_after_player_save`, `tt_modify_categories`, and `tt_dashboard_data`. To add new modules (e.g. injury tracking), create a class in `includes/` and wire it in `Core::init()`.

== Changelog ==

= 1.0.0 =
* Initial release
* GitHub-ready plugin scaffold with Plugin Update Checker integration
* Full CRUD Configuration module with polymorphic lookup table (tt_lookups)
* Configurable evaluation types with per-type match-details flag
* Teams, Players, Evaluations, Sessions, Goals, Attendance — all as custom tables
* Training vs. Match evaluation types
* Spider/radar chart visualizations
* Role-based frontend dashboard (player / coach / admin)
* Configurable reporting module with saveable presets
* Built-in user documentation for every role
* White-label branding
* REST API with full CRUD
