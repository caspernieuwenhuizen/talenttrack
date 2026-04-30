<?php
namespace TT\Modules\Documentation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HelpTopics — registry of all wiki topic slugs with metadata.
 *
 * The actual content of each topic lives in a markdown file at
 * docs/<slug>.md (inside the plugin). This class enumerates topics,
 * groups them for the TOC sidebar, and resolves slug → title +
 * filepath + group.
 *
 * Per the v2.22.0 release discipline commitment: every sprint that
 * touches a feature must also update the relevant topic file(s) so
 * the wiki stays accurate.
 */
class HelpTopics {

    /**
     * All topics, ordered within their groups. The tuple is:
     *   slug => [ title, group_key, summary ]
     *
     * `summary` is the first line of the intro — shown in search
     * results and as a hover preview. Kept short.
     *
     * @return array<string, array{title:string, group:string, summary:string}>
     */
    public static function all(): array {
        return [
            'getting-started' => [
                'title'   => __( 'Getting started', 'talenttrack' ),
                'group'   => 'basics',
                'summary' => __( 'Welcome to TalentTrack. The essentials to get going.', 'talenttrack' ),
            ],
            'teams-players' => [
                'title'   => __( 'Teams & players', 'talenttrack' ),
                'group'   => 'basics',
                'summary' => __( 'How to create teams, add players, and assign players to teams.', 'talenttrack' ),
            ],
            'people-staff' => [
                'title'   => __( 'People (staff)', 'talenttrack' ),
                'group'   => 'basics',
                'summary' => __( 'Coaches, physios, and other staff — add them as people and link to teams.', 'talenttrack' ),
            ],
            'evaluations' => [
                'title'   => __( 'Evaluations', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Record player ratings with scores, notes, and categories.', 'talenttrack' ),
            ],
            'eval-categories-weights' => [
                'title'   => __( 'Evaluation categories & weights', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Main categories, subcategories, and how per-age-group weighting works.', 'talenttrack' ),
            ],
            'activities' => [
                'title'   => __( 'Activities', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Games, trainings, and other activities — typing, attendance, and post-game evaluations.', 'talenttrack' ),
            ],
            'goals' => [
                'title'   => __( 'Goals', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Development goals per player with status and priority.', 'talenttrack' ),
            ],
            'pdp-cycle' => [
                'title'   => __( 'Player Development Plan (PDP)', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Per-season development files, conversation cadence, end-of-season verdict.', 'talenttrack' ),
            ],
            'team-chemistry' => [
                'title'   => __( 'Team chemistry', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Formation board with auto-suggested XI, depth chart, paired-player overrides, and traceable fit scores.', 'talenttrack' ),
            ],
            'player-journey' => [
                'title'   => __( 'Player journey', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'A chronological view of every player\'s academy story — milestones, evaluations, injuries, transitions.', 'talenttrack' ),
            ],
            'reports' => [
                'title'   => __( 'Reports', 'talenttrack' ),
                'group'   => 'analytics',
                'summary' => __( 'The tile launcher — progress charts, team ratings, coach activity.', 'talenttrack' ),
            ],
            'trials' => [
                'title'   => __( 'Trial cases', 'talenttrack' ),
                'group'   => 'people',
                'summary' => __( 'Run a structured trial period: track templates, staff input, decision and the letter that goes to parents.', 'talenttrack' ),
            ],
            'wizards' => [
                'title'   => __( 'Record creation wizards', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Step-by-step forms that replace the flat create form for new players, teams, evaluations, and goals.', 'talenttrack' ),
            ],
            'rate-cards' => [
                'title'   => __( 'Player rate cards', 'talenttrack' ),
                'group'   => 'analytics',
                'summary' => __( 'Deep per-player dashboards with trends and charts.', 'talenttrack' ),
            ],
            'player-comparison' => [
                'title'   => __( 'Player comparison', 'talenttrack' ),
                'group'   => 'analytics',
                'summary' => __( 'Compare up to 4 players side-by-side, cross-team.', 'talenttrack' ),
            ],
            'usage-statistics' => [
                'title'   => __( 'Usage statistics', 'talenttrack' ),
                'group'   => 'analytics',
                'summary' => __( 'Logins, active users, DAU and evaluation trends.', 'talenttrack' ),
            ],
            'configuration-branding' => [
                'title'   => __( 'Configuration & branding', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Academy name, logo, rating scale, color palette, lookup tables.', 'talenttrack' ),
            ],
            'custom-fields' => [
                'title'   => __( 'Custom fields', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Add club-specific fields to players, teams, and evaluations.', 'talenttrack' ),
            ],
            'bulk-actions' => [
                'title'   => __( 'Bulk actions (archive & delete)', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Selecting many rows at once. Archive vs. permanent delete.', 'talenttrack' ),
            ],
            'printing-pdf' => [
                'title'   => __( 'Printing & PDF export', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Clean printable reports and browser-native PDF export.', 'talenttrack' ),
            ],
            'migrations' => [
                'title'   => __( 'Migrations & updates', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'What happens when you update the plugin, and how to run migrations manually.', 'talenttrack' ),
            ],
            'invitations' => [
                'title'   => __( 'Invitations', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Invite players, parents, and staff via shareable WhatsApp links — set passwords on first follow-through.', 'talenttrack' ),
            ],
            'player-dashboard' => [
                'title'   => __( 'Player dashboard (frontend)', 'talenttrack' ),
                'group'   => 'frontend',
                'summary' => __( 'What players see when they log into the frontend shortcode.', 'talenttrack' ),
            ],
            'coach-dashboard' => [
                'title'   => __( 'Coach dashboard (frontend)', 'talenttrack' ),
                'group'   => 'frontend',
                'summary' => __( 'What coaches see in the frontend tile grid.', 'talenttrack' ),
            ],
            'persona-dashboard' => [
                'title'   => __( 'Persona dashboards', 'talenttrack' ),
                'group'   => 'frontend',
                'summary' => __( 'Persona-aware landing pages with widget catalog, KPI catalog, role-switcher, and per-club override.', 'talenttrack' ),
            ],
            'conversational-goals' => [
                'title'   => __( 'Goals as a conversation', 'talenttrack' ),
                'group'   => 'frontend',
                'summary' => __( 'Chat-style threads on every player goal — coach, player, parent dialogue with notifications, edit window, soft-delete, and audit log.', 'talenttrack' ),
            ],
            'access-control' => [
                'title'   => __( 'Access control', 'talenttrack' ),
                'group'   => 'frontend',
                'summary' => __( 'Roles, permissions, functional roles, and the Read-Only Observer.', 'talenttrack' ),
            ],
            'authorization-matrix' => [
                'title'   => __( 'Authorization matrix', 'talenttrack' ),
                'group'   => 'frontend',
                'summary' => __( 'Persona × entity × activity × scope grid — what each persona can do, with shadow-mode preview before applying.', 'talenttrack' ),
            ],
            'modules' => [
                'title'   => __( 'Modules', 'talenttrack' ),
                'group'   => 'frontend',
                'summary' => __( 'Per-install module toggles — disable Methodology, Workflow, License, etc. without touching code.', 'talenttrack' ),
            ],
            'workflow-engine' => [
                'title'   => __( 'Workflow & tasks engine', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Scheduled tasks landing in the inbox: post-match evals, self-evals, goal-setting, HoD reviews.', 'talenttrack' ),
            ],
            'workflow-engine-cron-setup' => [
                'title'   => __( 'Workflow engine — cron setup', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'How WP-cron drives scheduled tasks on this install, and how to fix it when it stops firing.', 'talenttrack' ),
            ],
            'setup-wizard' => [
                'title'   => __( 'Setup wizard', 'talenttrack' ),
                'group'   => 'basics',
                'summary' => __( 'The first-run guided installer that hands you off into TalentTrack.', 'talenttrack' ),
            ],
            'license-and-account' => [
                'title'   => __( 'License & account', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Tier, trial state, usage caps, upgrade flow.', 'talenttrack' ),
            ],
            'backups' => [
                'title'   => __( 'Backups & disaster recovery', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Scheduled exports, partial restore, the 14-day undo window.', 'talenttrack' ),
            ],
            'methodology' => [
                'title'   => __( 'Methodology', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Football framework primer, principles, set pieces, positions, voetbalhandelingen.', 'talenttrack' ),
            ],
            // #0029 dev tier — English-only by design.
            'rest-api' => [
                'title'   => __( 'REST API reference', 'talenttrack' ),
                'group'   => 'developer',
                'summary' => __( 'Plugin REST endpoints, payload shapes and capability scopes.', 'talenttrack' ),
            ],
            'hooks-and-filters' => [
                'title'   => __( 'Hooks & filters', 'talenttrack' ),
                'group'   => 'developer',
                'summary' => __( 'Every action and filter the plugin exposes for extension.', 'talenttrack' ),
            ],
            'architecture' => [
                'title'   => __( 'Architecture', 'talenttrack' ),
                'group'   => 'developer',
                'summary' => __( 'Module pattern, Kernel boot order, capability model, design tokens.', 'talenttrack' ),
            ],
            'theme-integration' => [
                'title'   => __( 'Theme integration', 'talenttrack' ),
                'group'   => 'developer',
                'summary' => __( 'Override design tokens from a theme and the body.tt-theme-inherit contract.', 'talenttrack' ),
            ],
            'development-management' => [
                'title'   => __( 'Development management', 'talenttrack' ),
                'group'   => 'development',
                'summary' => __( 'Submit, refine, and promote ideas straight to GitHub from the dashboard.', 'talenttrack' ),
            ],
            'translations' => [
                'title'   => __( 'Auto-translation', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Opt-in DeepL / Google translation of user-entered free text.', 'talenttrack' ),
            ],
            // #0042 — youth-aware contact strategy KB.
            'install-on-iphone' => [
                'title'   => __( 'Install on iPhone', 'talenttrack' ),
                'group'   => 'mobile',
                'summary' => __( 'Add TalentTrack to your iPhone home screen via Safari and accept push notifications.', 'talenttrack' ),
            ],
            'install-on-android' => [
                'title'   => __( 'Install on Android', 'talenttrack' ),
                'group'   => 'mobile',
                'summary' => __( 'Install TalentTrack as a Chrome PWA on Android and accept push notifications.', 'talenttrack' ),
            ],
            'notifications-setup' => [
                'title'   => __( 'Notifications setup', 'talenttrack' ),
                'group'   => 'mobile',
                'summary' => __( 'Turn on push notifications, understand what TalentTrack sends, and how phone verification works.', 'talenttrack' ),
            ],
            'parent-handles-everything' => [
                'title'   => __( 'Parent contact (U8 – U10)', 'talenttrack' ),
                'group'   => 'mobile',
                'summary' => __( 'For younger players, the parent is the primary contact — invitations, tasks, and pushes flow through you.', 'talenttrack' ),
            ],
            // #0069 — register orphan docs that lived on disk but
            // weren't reachable from the in-product TOC. Dev-only
            // docs (architecture-mobile-first, contributing,
            // dev-tier-rest-port-backlog, phone-home, index) stay
            // unregistered by design.
            'custom-css' => [
                'title'   => __( 'Custom CSS', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Visual editor and hand-rolled CSS for academy-specific theming, with sanitisation and history.', 'talenttrack' ),
            ],
            'demo-data-excel' => [
                'title'   => __( 'Demo data (Excel)', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Generate or import a demo dataset for sales / training. Excel template with auto_key cross-sheet links.', 'talenttrack' ),
            ],
            'pdp-planning' => [
                'title'   => __( 'PDP planning', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Per-team-per-block matrix of planned vs conducted PDP conversations. HoD / coach surface.', 'talenttrack' ),
            ],
            'player-status' => [
                'title'   => __( 'Player status', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Traffic-light status calculation: weights, thresholds, behaviour floor, behaviour + potential capture.', 'talenttrack' ),
            ],
            'spond-integration' => [
                'title'   => __( 'Spond integration', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Read-only Spond → TalentTrack iCal sync per team.', 'talenttrack' ),
            ],
            'staff-development' => [
                'title'   => __( 'Staff development', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Personal goals + evaluations + certifications + PDP for coaches and staff.', 'talenttrack' ),
            ],
        ];
    }

    /**
     * Group labels in display order. Keys match the `group` field of
     * each topic.
     *
     * @return array<string, string>
     */
    public static function groups(): array {
        return [
            'basics'        => __( 'Basics', 'talenttrack' ),
            'performance'   => __( 'Performance', 'talenttrack' ),
            'analytics'     => __( 'Analytics', 'talenttrack' ),
            'configuration' => __( 'Configuration', 'talenttrack' ),
            'frontend'      => __( 'Frontend & access', 'talenttrack' ),
            'mobile'        => __( 'Mobile install', 'talenttrack' ),
            'developer'     => __( 'Developer', 'talenttrack' ),
            'development'   => __( 'Development', 'talenttrack' ),
        ];
    }

    /**
     * Resolve a topic slug to its markdown file path, or null if the
     * slug is unknown.
     *
     * Locale-aware: if a translation exists at docs/<locale>/<slug>.md
     * it is returned; otherwise falls back to the canonical English
     * docs/<slug>.md. Locale is read via determine_locale() so an
     * individual WP user's preferred language wins over the site locale.
     */
    public static function filePath( string $slug ): ?string {
        $topics = self::all();
        if ( ! isset( $topics[ $slug ] ) ) return null;
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        if ( $locale ) {
            $localized = TT_PATH . 'docs/' . $locale . '/' . $slug . '.md';
            if ( file_exists( $localized ) ) return $localized;
        }
        $path = TT_PATH . 'docs/' . $slug . '.md';
        return file_exists( $path ) ? $path : null;
    }

    /**
     * Default topic slug when none is requested.
     */
    public static function defaultSlug(): string {
        return 'getting-started';
    }
}
