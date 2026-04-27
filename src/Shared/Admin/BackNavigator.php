<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackNavigator — hierarchical parent-page map for the admin.
 *
 * Sprint v2.22.0. Replaces the referer-based back button (v2.19.0)
 * which had a fundamental flaw: clicking "back" on the target page
 * would return the user to the page they just came FROM, creating
 * an infinite ping-pong. Every navigation makes itself the next
 * page's referer, so you can never actually walk the trail home.
 *
 * The fix: define an explicit parent for each page + action combo.
 * Back buttons always navigate to the parent, not the referer.
 * Walking back repeatedly climbs one level at a time until you
 * reach the dashboard (home), never cycling sideways.
 *
 * Routes are keyed by the URL-identifying tuple (page slug + action)
 * and resolve to a parent tuple or null (meaning the dashboard, i.e.
 * home). The parent map IS the site hierarchy — a clean source of
 * truth that a breadcrumb UI can walk to build trails like:
 *   Dashboard › Players › Edit Player
 */
class BackNavigator {

    /** Root target = dashboard. */
    private const HOME_PAGE = 'talenttrack';

    /**
     * Parent map. Each entry is a tuple:
     *   'page-slug|action' => [ 'parent_page' => 'slug', 'parent_args' => [...], 'label' => 'Human label' ]
     *
     * When `action` is omitted from the lookup key, it matches any
     * action on that page (or no action at all). Specific action
     * keys take precedence over generic page keys.
     *
     * `parent_args` are optional query-string params appended to the
     * parent URL (e.g. for returning to a filtered list).
     *
     * `label` is the current page's name, used for breadcrumb trails.
     */
    private const MAP = [
        // Home
        'talenttrack' => [ 'parent_page' => null, 'label_key' => 'Dashboard' ],

        // People group
        'tt-teams'              => [ 'parent_page' => 'talenttrack',       'label_key' => 'Teams' ],
        'tt-teams|new'          => [ 'parent_page' => 'tt-teams',          'label_key' => 'New Team' ],
        'tt-teams|edit'         => [ 'parent_page' => 'tt-teams',          'label_key' => 'Edit Team' ],
        'tt-players'            => [ 'parent_page' => 'talenttrack',       'label_key' => 'Players' ],
        'tt-players|new'        => [ 'parent_page' => 'tt-players',        'label_key' => 'New Player' ],
        'tt-players|edit'       => [ 'parent_page' => 'tt-players',        'label_key' => 'Edit Player' ],
        'tt-players|view'       => [ 'parent_page' => 'tt-players',        'label_key' => 'View Player' ],
        'tt-people'             => [ 'parent_page' => 'talenttrack',       'label_key' => 'People' ],
        'tt-people|new'         => [ 'parent_page' => 'tt-people',         'label_key' => 'New Person' ],
        'tt-people|edit'        => [ 'parent_page' => 'tt-people',         'label_key' => 'Edit Person' ],

        // Performance group
        'tt-evaluations'        => [ 'parent_page' => 'talenttrack',       'label_key' => 'Evaluations' ],
        'tt-evaluations|new'    => [ 'parent_page' => 'tt-evaluations',    'label_key' => 'New Evaluation' ],
        'tt-evaluations|edit'   => [ 'parent_page' => 'tt-evaluations',    'label_key' => 'Edit Evaluation' ],
        'tt-evaluations|view'   => [ 'parent_page' => 'tt-evaluations',    'label_key' => 'View Evaluation' ],
        'tt-activities'           => [ 'parent_page' => 'talenttrack',       'label_key' => 'Sessions' ],
        'tt-activities|new'       => [ 'parent_page' => 'tt-activities',       'label_key' => 'New Session' ],
        'tt-activities|edit'      => [ 'parent_page' => 'tt-activities',       'label_key' => 'Edit Session' ],
        'tt-goals'              => [ 'parent_page' => 'talenttrack',       'label_key' => 'Goals' ],
        'tt-goals|new'          => [ 'parent_page' => 'tt-goals',          'label_key' => 'New Goal' ],
        'tt-goals|edit'         => [ 'parent_page' => 'tt-goals',          'label_key' => 'Edit Goal' ],

        // Analytics group
        'tt-reports'            => [ 'parent_page' => 'talenttrack',       'label_key' => 'Reports' ],
        'tt-rate-cards'         => [ 'parent_page' => 'talenttrack',       'label_key' => 'Player Rate Cards' ],
        'tt-compare'            => [ 'parent_page' => 'talenttrack',       'label_key' => 'Player Comparison' ],
        'tt-usage-stats'        => [ 'parent_page' => 'talenttrack',       'label_key' => 'Usage Statistics' ],
        'tt-usage-stats-details'=> [ 'parent_page' => 'tt-usage-stats',    'label_key' => 'Usage Detail' ],

        // Configuration group
        'tt-config'             => [ 'parent_page' => 'talenttrack',       'label_key' => 'Configuration' ],
        'tt-custom-fields'      => [ 'parent_page' => 'talenttrack',       'label_key' => 'Custom Fields' ],
        'tt-custom-fields|new'  => [ 'parent_page' => 'tt-custom-fields',  'label_key' => 'New Custom Field' ],
        'tt-custom-fields|edit' => [ 'parent_page' => 'tt-custom-fields',  'label_key' => 'Edit Custom Field' ],
        'tt-eval-categories'    => [ 'parent_page' => 'talenttrack',       'label_key' => 'Evaluation Categories' ],
        'tt-eval-categories|new' => [ 'parent_page' => 'tt-eval-categories','label_key' => 'New Evaluation Category' ],
        'tt-eval-categories|edit'=> [ 'parent_page' => 'tt-eval-categories','label_key' => 'Edit Evaluation Category' ],
        'tt-category-weights'   => [ 'parent_page' => 'talenttrack',       'label_key' => 'Category Weights' ],

        // Access Control
        'tt-roles'              => [ 'parent_page' => 'talenttrack',       'label_key' => 'Roles & Permissions' ],
        'tt-functional-roles'   => [ 'parent_page' => 'talenttrack',       'label_key' => 'Functional Roles' ],
        'tt-roles-debug'        => [ 'parent_page' => 'talenttrack',       'label_key' => 'Permission Debug' ],

        // Help
        'tt-docs'               => [ 'parent_page' => 'talenttrack',       'label_key' => 'Help & Docs' ],
    ];

    /**
     * Map of label_key to translated label. Kept separate so the MAP
     * constant can be a genuine array literal.
     *
     * @return array<string, string>
     */
    private static function labels(): array {
        return [
            'Dashboard'                => __( 'Dashboard', 'talenttrack' ),
            'Teams'                    => __( 'Teams', 'talenttrack' ),
            'New Team'                 => __( 'New Team', 'talenttrack' ),
            'Edit Team'                => __( 'Edit Team', 'talenttrack' ),
            'Players'                  => __( 'Players', 'talenttrack' ),
            'New Player'               => __( 'New Player', 'talenttrack' ),
            'Edit Player'              => __( 'Edit Player', 'talenttrack' ),
            'View Player'              => __( 'View Player', 'talenttrack' ),
            'People'                   => __( 'People', 'talenttrack' ),
            'New Person'               => __( 'New Person', 'talenttrack' ),
            'Edit Person'              => __( 'Edit Person', 'talenttrack' ),
            'Evaluations'              => __( 'Evaluations', 'talenttrack' ),
            'New Evaluation'           => __( 'New Evaluation', 'talenttrack' ),
            'Edit Evaluation'          => __( 'Edit Evaluation', 'talenttrack' ),
            'View Evaluation'          => __( 'View Evaluation', 'talenttrack' ),
            'Sessions'                 => __( 'Activities', 'talenttrack' ),
            'New Session'              => __( 'New Activity', 'talenttrack' ),
            'Edit Session'             => __( 'Edit Activity', 'talenttrack' ),
            'Goals'                    => __( 'Goals', 'talenttrack' ),
            'New Goal'                 => __( 'New Goal', 'talenttrack' ),
            'Edit Goal'                => __( 'Edit Goal', 'talenttrack' ),
            'Reports'                  => __( 'Reports', 'talenttrack' ),
            'Player Rate Cards'        => __( 'Player Rate Cards', 'talenttrack' ),
            'Player Comparison'        => __( 'Player Comparison', 'talenttrack' ),
            'Usage Statistics'         => __( 'Usage Statistics', 'talenttrack' ),
            'Usage Detail'             => __( 'Usage Detail', 'talenttrack' ),
            'Configuration'            => __( 'Configuration', 'talenttrack' ),
            'Custom Fields'            => __( 'Custom Fields', 'talenttrack' ),
            'New Custom Field'         => __( 'New Custom Field', 'talenttrack' ),
            'Edit Custom Field'        => __( 'Edit Custom Field', 'talenttrack' ),
            'Evaluation Categories'    => __( 'Evaluation Categories', 'talenttrack' ),
            'New Evaluation Category'  => __( 'New Evaluation Category', 'talenttrack' ),
            'Edit Evaluation Category' => __( 'Edit Evaluation Category', 'talenttrack' ),
            'Category Weights'         => __( 'Category Weights', 'talenttrack' ),
            'Roles & Permissions'      => __( 'Roles & Permissions', 'talenttrack' ),
            'Functional Roles'         => __( 'Functional Roles', 'talenttrack' ),
            'Permission Debug'         => __( 'Permission Debug', 'talenttrack' ),
            'Help & Docs'              => __( 'Help & Docs', 'talenttrack' ),
        ];
    }

    // Public API

    /**
     * Return the URL of the parent page for a given page + action, or
     * the dashboard URL if the current page has no defined parent or
     * IS the dashboard.
     */
    public static function parentUrl( string $page, string $action = '' ): string {
        $entry = self::lookup( $page, $action );
        if ( ! $entry || $entry['parent_page'] === null ) {
            return admin_url( 'admin.php?page=' . self::HOME_PAGE );
        }
        $args = [ 'page' => $entry['parent_page'] ];
        if ( ! empty( $entry['parent_args'] ) ) {
            $args = array_merge( $args, $entry['parent_args'] );
        }
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    /**
     * Resolve the page + action of the CURRENT request from $_GET.
     *
     * @return array{page:string, action:string}
     */
    public static function currentRoute(): array {
        $page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';
        return [ 'page' => $page, 'action' => $action ];
    }

    /**
     * Walk the map from the current page back to home, returning the
     * breadcrumb trail as an array of [ 'url' => ..., 'label' => ... ]
     * entries. The last entry is the current page; the first is home.
     *
     * @return array<int, array{url:string, label:string}>
     */
    public static function breadcrumbs( string $page, string $action = '' ): array {
        $trail = [];
        $labels = self::labels();

        // Walk upward, collecting entries.
        $current_page = $page;
        $current_action = $action;
        $guard = 0;  // safety: prevent infinite loop if misconfigured

        while ( $current_page !== '' && $guard++ < 20 ) {
            $entry = self::lookup( $current_page, $current_action );
            if ( ! $entry ) break;

            $label_key = $entry['label_key'] ?? $current_page;
            $label = $labels[ $label_key ] ?? $label_key;

            $args = [ 'page' => $current_page ];
            if ( $current_action !== '' ) $args['action'] = $current_action;
            $url = admin_url( 'admin.php?' . http_build_query( $args ) );

            array_unshift( $trail, [ 'url' => $url, 'label' => $label ] );

            if ( $entry['parent_page'] === null ) break;
            $current_page = $entry['parent_page'];
            $current_action = '';  // parent always the generic version
        }

        return $trail;
    }

    /**
     * Check whether the given route is the dashboard (no back/breadcrumb
     * needed — you're already home).
     */
    public static function isHome( string $page, string $action = '' ): bool {
        return $page === self::HOME_PAGE && $action === '';
    }

    // Lookup

    /**
     * Look up the map entry for a page + action combo. Action-specific
     * entries win over generic page entries. Returns null if no match.
     */
    private static function lookup( string $page, string $action ): ?array {
        if ( $page === '' ) return null;

        if ( $action !== '' ) {
            $key = $page . '|' . $action;
            if ( isset( self::MAP[ $key ] ) ) return self::MAP[ $key ];
        }
        if ( isset( self::MAP[ $page ] ) ) return self::MAP[ $page ];
        return null;
    }
}
