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
            'sessions' => [
                'title'   => __( 'Sessions', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Training sessions, attendance tracking.', 'talenttrack' ),
            ],
            'goals' => [
                'title'   => __( 'Goals', 'talenttrack' ),
                'group'   => 'performance',
                'summary' => __( 'Development goals per player with status and priority.', 'talenttrack' ),
            ],
            'reports' => [
                'title'   => __( 'Reports', 'talenttrack' ),
                'group'   => 'analytics',
                'summary' => __( 'The tile launcher — progress charts, team ratings, coach activity.', 'talenttrack' ),
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
            'access-control' => [
                'title'   => __( 'Access control', 'talenttrack' ),
                'group'   => 'frontend',
                'summary' => __( 'Roles, permissions, functional roles, and the Read-Only Observer.', 'talenttrack' ),
            ],
            'workflow-engine' => [
                'title'   => __( 'Workflow & tasks engine', 'talenttrack' ),
                'group'   => 'configuration',
                'summary' => __( 'Scheduled tasks landing in the inbox: post-match evals, self-evals, goal-setting, HoD reviews.', 'talenttrack' ),
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
            'developer'     => __( 'Developer', 'talenttrack' ),
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
