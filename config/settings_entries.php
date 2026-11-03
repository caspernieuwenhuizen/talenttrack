<?php
/**
 * Settings entries (#4670) — every settings screen, tab and notable setting,
 * filed under one of the ten Configuration sections of
 * `config/navigation_groups.php`. Read by `TT\Shared\Configuration\SettingsRegistry`.
 *
 * `sections` maps each settings key to the question its section answers;
 * the section's name comes from `config/navigation_groups.php`.
 *
 * Each entry in `entries` has a stable `key`, a `section`, and exactly one
 * destination:
 *   - `tile`        a `kind: setup` tile slug. Its label, description,
 *                   module and visibility come from `TileRegistry`.
 *   - `sub`         a `?config_sub=` form on the Configuration page
 *                   (`args` adds query arguments, e.g. a lookup category).
 *   - `view`        a `?tt_view=` screen.
 *   - `admin_page`  a wp-admin page slug (`args` for its tab); marked as
 *                   opening wp-admin.
 * Non-tile entries carry `label`, `description`, an optional `module` and
 * one access rule: `cap` (user_can), `matrix_cap` (user_can or the
 * authorization matrix) or `check` (a named rule in SettingsRegistry), and
 * optionally a `feature` key that hides the entry while it is off. A `sub`
 * entry also needs `tt_view_settings`, which the Configuration page asks.
 * `narrow_description` replaces the description for a viewer who fails the
 * named `narrow_check`, when the screen shows them less than everything.
 * `keywords` are the extra words the filter and search match on; the Dutch
 * translation carries Dutch and English words alike.
 *
 * `not_entries` lists the settings routes that deliberately have no entry,
 * each with the reason. `tools/check-settings-entries.php` fails any other
 * settings route without one.
 *
 * @return array{sections: array<string,string>, entries: list<array<string,mixed>>, not_entries: array<string,string>}
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    'sections' => [
        'academy'                 => __( 'What is the academy called, how does it look, and how does the season run?', 'talenttrack' ),
        'players_teams'           => __( 'What do we record for each player and team?', 'talenttrack' ),
        'development_evaluations' => __( 'How do we assess and develop players?', 'talenttrack' ),
        'activities_matches'      => __( 'How do we plan and record training and matches?', 'talenttrack' ),
        'lookups'                 => __( 'Which values appear in the lists?', 'talenttrack' ),
        'people_access'           => __( 'Who may see and do what?', 'talenttrack' ),
        'messages_tasks'          => __( 'What do we send to whom, and which tasks run automatically?', 'talenttrack' ),
        'data'                    => __( 'How do we bring data in and keep it safe?', 'talenttrack' ),
        'features_plan'           => __( 'What is switched on, and what is in our plan?', 'talenttrack' ),
        'system'                  => __( 'For the administrator: logs and technical tools.', 'talenttrack' ),
    ],

    'entries' => [
        // ---- Academy ----
        [
            'key' => 'general', 'section' => 'academy', 'sub' => 'general', 'cap' => 'tt_view_settings',
            'label'       => __( 'General', 'talenttrack' ),
            'description' => __( 'Date notation, first day of the week, timezone and locale for the whole academy.', 'talenttrack' ),
            'keywords'    => _x( 'language, locale, timezone, date, week, navigation layout', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'appearance', 'section' => 'academy', 'sub' => 'appearance', 'cap' => 'tt_view_settings',
            'label'       => __( 'Appearance', 'talenttrack' ),
            'description' => __( 'Academy name, logo, all brand colours, fonts and theme inheritance — in one place.', 'talenttrack' ),
            'keywords'    => _x( 'logo, colours, colors, branding, theme, fonts, crest, academy name', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'custom-css', 'section' => 'academy', 'view' => 'custom-css', 'cap' => 'tt_admin_styling',
            'module'      => 'TT\\Modules\\CustomCss\\CustomCssModule',
            'label'       => __( 'Custom CSS', 'talenttrack' ),
            'description' => __( 'Per-club custom styling: visual + code editor, file upload, starter templates, revertable history.', 'talenttrack' ),
            'keywords'    => _x( 'css, styling, stylesheet, branding', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'seasons', 'section' => 'academy', 'view' => 'seasons', 'cap' => 'tt_edit_settings',
            'label'       => __( 'Seasons', 'talenttrack' ),
            'description' => __( 'Create, edit, delete and set the current academy season. PDP files and the carryover job are scoped to the current season.', 'talenttrack' ),
            'keywords'    => _x( 'season, year, period, current season', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'holidays', 'section' => 'academy', 'tile' => 'holidays',
            'keywords' => _x( 'holidays, break, closed, calendar, team planner', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'season-rollover', 'section' => 'academy', 'tile' => 'season-rollover',
            'keywords' => _x( 'rollover, new season, move up, promote, age group', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'default-dashboard', 'section' => 'academy', 'sub' => 'dashboard', 'cap' => 'tt_view_settings',
            'module'      => 'TT\\Modules\\PersonaDashboard\\PersonaDashboardModule',
            'label'       => __( 'Default dashboard', 'talenttrack' ),
            'description' => __( 'Choose what every user sees on the dashboard root: the persona dashboard or the classic tile grid.', 'talenttrack' ),
            'keywords'    => _x( 'dashboard, landing page, start page, home, tiles', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'persona-templates', 'section' => 'academy', 'view' => 'persona-templates', 'cap' => 'tt_edit_persona_templates',
            'feature'     => 'persona_dashboard_editor',
            'module'      => 'TT\\Modules\\PersonaDashboard\\PersonaDashboardModule',
            'label'       => __( 'Dashboard layouts', 'talenttrack' ),
            'description' => __( 'Drag-and-drop editor — tune what each persona sees on their landing page.', 'talenttrack' ),
            'keywords'    => _x( 'dashboard, layout, widgets, persona, editor', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'custom-widgets', 'section' => 'academy', 'admin_page' => 'tt-custom-widgets', 'cap' => 'tt_edit_persona_templates',
            'module'      => 'TT\\Modules\\CustomWidgets\\CustomWidgetsModule',
            'label'       => __( 'Custom widgets', 'talenttrack' ),
            'description' => __( 'Compose your own dashboard widgets — pick a data source, choose columns and filters, drop them onto a persona dashboard.', 'talenttrack' ),
            'keywords'    => _x( 'widget, chart, kpi, dashboard', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'setup', 'section' => 'academy', 'view' => 'setup', 'cap' => 'tt_edit_settings',
            'module'      => 'TT\\Modules\\Onboarding\\OnboardingModule',
            'label'       => __( 'Setup', 'talenttrack' ),
            'description' => __( 'Run or re-run first-time setup: academy basics, your first team, your admin profile, and the dashboard page.', 'talenttrack' ),
            'keywords'    => _x( 'onboarding, first run, getting started, wizard', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- Players & teams ----
        [
            'key' => 'custom-fields', 'section' => 'players_teams', 'view' => 'custom-fields', 'cap' => 'tt_view_custom_fields',
            'label'       => __( 'Custom fields', 'talenttrack' ),
            'description' => __( 'Extra fields on player and team records, for what your academy tracks beyond the standard ones.', 'talenttrack' ),
            'keywords'    => _x( 'custom fields, extra fields, player record, team record', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'profile-cards', 'section' => 'players_teams', 'sub' => 'profile-cards', 'cap' => 'tt_view_settings',
            'module'      => 'TT\\Modules\\Players\\PlayersModule',
            'label'       => __( 'Profile cards', 'talenttrack' ),
            'description' => __( 'Choose which cards the player-profile Profile tab shows academy-wide. Hide the ones you do not use (e.g. Discovery). Identity always stays.', 'talenttrack' ),
            'keywords'    => _x( 'profile, cards, player page', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'player-status-methodology', 'section' => 'players_teams', 'view' => 'player-status-methodology', 'cap' => 'tt_edit_settings',
            'module'      => 'TT\\Modules\\Players\\PlayerStatusModule',
            'label'       => __( 'Player status methodology', 'talenttrack' ),
            'description' => __( 'Weights and thresholds for the player traffic-light status, per age group.', 'talenttrack' ),
            'keywords'    => _x( 'status, traffic light, thresholds, methodology', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'functional-roles', 'section' => 'players_teams', 'tile' => 'functional-roles',
            'keywords' => _x( 'roles, coach, assistant, team manager, staff', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'trial-tracks', 'section' => 'players_teams', 'tile' => 'trial-tracks-editor',
            'keywords' => _x( 'trial, tracks, steps', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'trial-letters', 'section' => 'players_teams', 'tile' => 'trial-letter-templates-editor',
            'keywords' => _x( 'trial, letters, templates', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'players-import', 'section' => 'players_teams', 'view' => 'players-import', 'check' => 'can_create_player',
            'module'      => 'TT\\Modules\\Players\\PlayersModule',
            'label'       => __( 'Players CSV import', 'talenttrack' ),
            'description' => __( 'Bulk-import players from a spreadsheet. Map columns, choose duplicate-handling, preview before commit.', 'talenttrack' ),
            'keywords'    => _x( 'import, csv, spreadsheet, bulk, players', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- Development & evaluations ----
        [
            'key' => 'eval-categories', 'section' => 'development_evaluations', 'view' => 'eval-categories', 'cap' => 'tt_view_evaluation_categories',
            'module'      => 'TT\\Modules\\Evaluations\\EvaluationsModule',
            'label'       => __( 'Evaluation categories', 'talenttrack' ),
            'description' => __( 'The criteria coaches rate in an evaluation, and their subcategories.', 'talenttrack' ),
            'keywords'    => _x( 'evaluation, categories, criteria', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'eval-category-weights', 'section' => 'development_evaluations', 'view' => 'eval-category-weights', 'matrix_cap' => 'tt_view_category_weights',
            'module'      => 'TT\\Modules\\Evaluations\\EvaluationsModule',
            'label'       => __( 'Category weights', 'talenttrack' ),
            'description' => __( 'How much each evaluation category counts toward the overall rating, per age group.', 'talenttrack' ),
            'keywords'    => _x( 'weighting, weights, overall rating, age group', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'rating-scale', 'section' => 'development_evaluations', 'sub' => 'rating', 'cap' => 'tt_view_settings',
            'module'      => 'TT\\Modules\\Evaluations\\EvaluationsModule',
            'label'       => __( 'Rating scale', 'talenttrack' ),
            'description' => __( 'Min, max and step for evaluation ratings.', 'talenttrack' ),
            'keywords'    => _x( 'rating, scale, scores, marks', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'pdp-blocks', 'section' => 'development_evaluations', 'sub' => 'pdp-blocks', 'cap' => 'tt_view_settings',
            'module'      => 'TT\\Modules\\Pdp\\PdpModule',
            'label'       => __( 'PDP cycle blocks', 'talenttrack' ),
            'description' => __( 'Date ranges for each block in a PDP cycle, per season. Configure 2, 3 or 4 blocks with date pairs validated against the season window.', 'talenttrack' ),
            'keywords'    => _x( 'pdp, cycle, blocks, conversations', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'pdp-prep-questions', 'section' => 'development_evaluations', 'sub' => 'pdp-prep-questions', 'cap' => 'tt_view_settings',
            'module'      => 'TT\\Modules\\Pdp\\PdpModule',
            'label'       => __( 'PDP preparation questions', 'talenttrack' ),
            'description' => __( 'What a coach thinks about before each conversation in the cycle. A different set per conversation, private to the coach and the head of academy.', 'talenttrack' ),
            'keywords'    => _x( 'pdp, preparation, questions, conversation', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'measurement-tests', 'section' => 'development_evaluations', 'tile' => 'measurement-tests',
            'keywords' => _x( 'tests, measurements, target bands', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'chemistry-config', 'section' => 'development_evaluations', 'tile' => 'chemistry-config',
            'keywords' => _x( 'chemistry, team, combinations', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'methodology-vocabulary', 'section' => 'development_evaluations', 'tile' => 'methodology-vocabulary',
            'keywords' => _x( 'methodology, vocabulary, principles, terms', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- Activities & matches ----
        [
            'key' => 'match-minutes', 'section' => 'activities_matches', 'sub' => 'match-minutes', 'cap' => 'tt_view_settings',
            'module'      => 'TT\\Modules\\Activities\\ActivitiesModule',
            'label'       => __( 'Match minutes', 'talenttrack' ),
            'description' => __( 'Default match length per age category (minutes per half, total 2 x N). Prefills match prep and the match-completion minutes entry.', 'talenttrack' ),
            'keywords'    => _x( 'minutes, playing time, match length, halves', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'vct-config', 'section' => 'activities_matches', 'view' => 'vct-config', 'matrix_cap' => 'tt_vct_admin_config',
            'module'      => 'TT\\Modules\\Vct\\VctModule',
            'label'       => __( 'VCT configuration', 'talenttrack' ),
            'description' => __( 'Macro-block calendar, per-age workload envelopes and per-team training days for the Variabel Coachen-template planner — all on one screen.', 'talenttrack' ),
            'keywords'    => _x( 'vct, periodisation, workload, training days', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'spond', 'section' => 'activities_matches', 'view' => 'spond', 'cap' => 'tt_edit_teams',
            'module'      => 'TT\\Modules\\Spond\\SpondModule',
            'label'       => __( 'Spond integration', 'talenttrack' ),
            'description' => __( 'Per-team calendar sync status, "Refresh now", encrypted account credentials, and the API endpoint override.', 'talenttrack' ),
            'keywords'    => _x( 'spond, calendar, sync, integration', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'strava', 'section' => 'activities_matches', 'view' => 'strava-admin', 'cap' => 'tt_view_strava',
            'module'      => 'TT\\Modules\\Strava\\StravaModule',
            'label'       => __( 'Strava integration', 'talenttrack' ),
            'description' => __( 'Register the Strava app credentials, manage the webhook subscription, and see which players have connected their accounts.', 'talenttrack' ),
            // A team-scope reader (a head coach) opens a page with only their
            // own players' connections, so the entry says that.
            'narrow_check'       => 'strava_club_wide',
            'narrow_description' => __( 'See which players in your teams have connected their Strava accounts.', 'talenttrack' ),
            'keywords'    => _x( 'strava, running, integration', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- Lookups ----
        [
            'key' => 'lookups', 'section' => 'lookups', 'sub' => 'lookups', 'cap' => 'tt_view_lookups',
            'label'       => __( 'Lookups', 'talenttrack' ),
            'description' => __( 'Activity types, positions, age groups, goal statuses, evaluation types — every dropdown vocabulary in one place.', 'talenttrack' ),
            'keywords'    => _x( 'lookups, dropdowns, lists, values, vocabulary', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'availability', 'section' => 'lookups', 'sub' => 'lookups', 'args' => [ 'category' => 'att_statuses' ], 'cap' => 'tt_view_lookups',
            'label'       => __( 'Attendance statuses', 'talenttrack' ),
            'description' => __( 'Present / absent / excused / late. Drives the attendance KPI.', 'talenttrack' ),
            'keywords'    => _x( 'availability, attendance, present, absent, injured, excused', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'football-form', 'section' => 'lookups', 'sub' => 'football-form', 'cap' => 'tt_view_settings',
            'module'      => 'TT\\Modules\\Teams\\TeamsModule',
            'label'       => __( 'Football form', 'talenttrack' ),
            'description' => __( 'How many a side each age category plays — 6v6, 8v8, 11v11. Pre-fills a new team and decides which formations its blueprint offers.', 'talenttrack' ),
            'keywords'    => _x( 'football form, a side, 11v11, 8v8, 6v6', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'lookup-normalisation', 'section' => 'lookups', 'view' => 'lookup-normalisation', 'cap' => 'tt_edit_lookups',
            'label'       => __( 'Lookup canonical-language review', 'talenttrack' ),
            'description' => __( 'Lookup values whose internal key drifted from English. Review each suggestion and accept the rewrite, or skip.', 'talenttrack' ),
            'keywords'    => _x( 'language, translation, review, lookups', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- People & access ----
        [
            'key' => 'accounts', 'section' => 'people_access', 'tile' => 'accounts',
            'keywords' => _x( 'accounts, logins, users, parents, link', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'invitations', 'section' => 'people_access', 'tile' => 'invitations-config',
            'keywords' => _x( 'invitations, invite, email text', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'matrix', 'section' => 'people_access', 'view' => 'matrix', 'cap' => 'tt_manage_authorization',
            'module'      => 'TT\\Modules\\Authorization\\AuthorizationModule',
            'label'       => __( 'Access control matrix', 'talenttrack' ),
            'description' => __( 'Who may read and change each kind of record, per persona. The grants behind player evaluations, notes and medical fields.', 'talenttrack' ),
            'keywords'    => _x( 'permissions, rights, authorization, roles, access, matrix', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'mfa-required', 'section' => 'people_access', 'sub' => 'mfa-policy', 'cap' => 'tt_edit_settings',
            'module'      => 'TT\\Modules\\Mfa\\MfaModule',
            'label'       => __( 'Require two-step verification', 'talenttrack' ),
            'description' => __( 'Which roles must sign in with a second factor.', 'talenttrack' ),
            'keywords'    => _x( '2fa, two-factor, two-step, mfa, security, login', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- Messages & tasks ----
        [
            'key' => 'messages', 'section' => 'messages_tasks', 'sub' => 'messages', 'cap' => 'tt_edit_feature_toggles',
            'module'      => 'TT\\Modules\\Comms\\CommsModule',
            'label'       => __( 'Messages', 'talenttrack' ),
            'description' => __( 'Choose which messages your academy sends — cancellations, nudges, reminders and letters. Switching one off stops it for everyone; the message log still records that it would have gone out.', 'talenttrack' ),
            'keywords'    => _x( 'email, notifications, sender, push, messages', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'workflow-templates', 'section' => 'messages_tasks', 'tile' => 'workflow-config',
            'keywords' => _x( 'workflow, tasks, reminders, templates', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'translations', 'section' => 'messages_tasks', 'view' => 'translations', 'cap' => 'tt_view_translations',
            'module'      => 'TT\\Modules\\Translations\\TranslationsModule',
            'label'       => __( 'Translations', 'talenttrack' ),
            'description' => __( 'Auto-translation engine (DeepL / Google), monthly usage, and cache.', 'talenttrack' ),
            'keywords'    => _x( 'translation, deepl, google, language', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- Data ----
        [
            'key' => 'backups', 'section' => 'data', 'view' => 'backups', 'cap' => 'tt_manage_backups',
            'module'      => 'TT\\Modules\\Backup\\BackupModule',
            'label'       => __( 'Backups', 'talenttrack' ),
            'description' => __( 'Scheduled and on-demand database backups: download, restore, and the .ttmig data migration export and import flow.', 'talenttrack' ),
            'keywords'    => _x( 'backup, restore, migration', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'export', 'section' => 'data', 'sub' => 'export', 'cap' => 'tt_edit_settings',
            'module'      => 'TT\\Modules\\Export\\ExportModule',
            'label'       => __( 'Export configuration', 'talenttrack' ),
            'description' => __( 'Download every setting plus which modules and features are on or off, as a JSON file. Credentials are redacted; no player data.', 'talenttrack' ),
            'keywords'    => _x( 'export, json, configuration, snapshot', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'recycle-bin', 'section' => 'data', 'view' => 'recycle-bin', 'cap' => 'tt_manage_recycle_bin',
            'label'       => __( 'Recycle bin', 'talenttrack' ),
            'description' => __( 'Records staged for permanent deletion. Restore them to the archive, or delete them now. Purged after the retention window.', 'talenttrack' ),
            'keywords'    => _x( 'recycle bin, trash, deleted, restore', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'import-history', 'section' => 'data', 'tile' => 'import-history',
            'keywords' => _x( 'import, history, undo', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'media-retention', 'section' => 'data', 'tile' => 'media-retention',
            'keywords' => _x( 'media, photos, videos, retention', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- Features & plan ----
        [
            'key' => 'modules', 'section' => 'features_plan', 'view' => 'modules', 'cap' => 'tt_manage_modules',
            'label'       => __( 'Modules', 'talenttrack' ),
            'description' => __( 'Turn TalentTrack modules on or off.', 'talenttrack' ),
            'keywords'    => _x( 'modules, features, switch on, switch off', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'features', 'section' => 'features_plan', 'tile' => 'features',
            'keywords' => _x( 'features, status, what is on', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'wizards', 'section' => 'features_plan', 'tile' => 'wizards-admin',
            'keywords' => _x( 'wizards, step by step, new record', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'plan', 'section' => 'features_plan', 'tile' => 'plan',
            'keywords' => _x( 'plan, licence, subscription, restrictions, tier', 'settings search keywords', 'talenttrack' ),
        ],

        // ---- System ----
        [
            'key' => 'audit-log', 'section' => 'system', 'tile' => 'audit-log',
            'keywords' => _x( 'audit, log, changes, history', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'message-log', 'section' => 'system', 'tile' => 'messages',
            'keywords' => _x( 'messages, log, sent, email', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'data-browser', 'section' => 'system', 'tile' => 'data-browser',
            'keywords' => _x( 'data, browser, tables, raw', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'migrations', 'section' => 'system', 'tile' => 'migrations',
            'keywords' => _x( 'migrations, database, schema', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'wp-admin-menus', 'section' => 'system', 'sub' => 'menus', 'cap' => 'tt_edit_settings',
            'label'       => __( 'wp-admin menus', 'talenttrack' ),
            'description' => __( 'Show or hide the legacy wp-admin menu entries.', 'talenttrack' ),
            'keywords'    => _x( 'wp-admin, menus, wordpress', 'settings search keywords', 'talenttrack' ),
        ],
        [
            'key' => 'open-wp-admin', 'section' => 'system', 'tile' => 'open-wp-admin',
            'keywords' => _x( 'wordpress, wp-admin', 'settings search keywords', 'talenttrack' ),
        ],
    ],

    'not_entries' => [
        'sub:branding'   => 'Retired alias of the Appearance form; old deep links still land there.',
        'sub:theme'      => 'Retired alias of the Appearance form; old deep links still land there.',
        'tile:two-factor' => 'A person\'s own second factor, not an academy setting; the academy-wide rule is the mfa-required entry.',
    ],
];
