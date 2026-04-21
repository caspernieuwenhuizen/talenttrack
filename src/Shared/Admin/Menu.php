<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Configuration\Admin\ConfigurationPage;
use TT\Modules\Configuration\Admin\CustomFieldsPage;
use TT\Modules\Documentation\Admin\DocumentationPage;
use TT\Modules\Evaluations\Admin\CategoryWeightsPage;
use TT\Modules\Evaluations\Admin\EvalCategoriesPage;
use TT\Modules\Evaluations\Admin\EvaluationsPage;
use TT\Modules\Goals\Admin\GoalsPage;
use TT\Modules\Players\Admin\PlayersPage;
use TT\Modules\Reports\Admin\ReportsPage;
use TT\Modules\Sessions\Admin\SessionsPage;
use TT\Modules\Stats\Admin\PlayerRateCardsPage;
use TT\Modules\Teams\Admin\TeamsPage;
use TT\Shared\Admin\BulkActionsHelper;

/**
 * Menu — registers the top-level TalentTrack admin menu plus subpages.
 *
 * v2.11.0: added Custom Fields submenu (TalentTrack → Custom Fields).
 * v2.6.0: enqueues admin-sortable.js on TT admin pages. (Originally for
 * the old CustomFieldsTab drag-sort UI; still used by OptionSetEditor.)
 */
class Menu {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        // v2.17.0: wire bulk-action post handler + admin JS.
        BulkActionsHelper::init();
    }

    public static function register(): void {
        // v2.17.0 — admin menu overhaul. WordPress has no native grouped
        // submenus, so we fake it with CSS-styled separator entries. The
        // separators are functional submenu pages whose callback redirects
        // back to the dashboard (in case someone clicks one anyway) and
        // whose rendered <li> is styled via the admin_head CSS emitted in
        // injectMenuCss() below to look like a heading row, not a link.
        //
        // Group order (top → bottom):
        //   Dashboard
        //   ── People ──
        //     Teams, Players
        //   ── Performance ──
        //     Evaluations, Sessions, Goals
        //   ── Analytics ──
        //     Reports, Player Rate Cards, Usage Statistics
        //   ── Configuration ── (admin-only)
        //     Configuration, Custom Fields, Evaluation Categories,
        //     Category Weights, Archived items
        //   Help & Docs

        add_menu_page( __( 'TalentTrack', 'talenttrack' ), __( 'TalentTrack', 'talenttrack' ), 'read', 'talenttrack', [ __CLASS__, 'dashboard' ], 'dashicons-groups', 26 );
        add_submenu_page( 'talenttrack', __( 'Dashboard', 'talenttrack' ), __( 'Dashboard', 'talenttrack' ), 'read', 'talenttrack', [ __CLASS__, 'dashboard' ] );

        self::addSeparator( 'tt-sep-people', __( 'People', 'talenttrack' ), 'tt_manage_players' );
        add_submenu_page( 'talenttrack', __( 'Teams', 'talenttrack' ), __( 'Teams', 'talenttrack' ), 'tt_manage_players', 'tt-teams', [ TeamsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Players', 'talenttrack' ), __( 'Players', 'talenttrack' ), 'tt_manage_players', 'tt-players', [ PlayersPage::class, 'render_page' ] );

        self::addSeparator( 'tt-sep-performance', __( 'Performance', 'talenttrack' ), 'tt_evaluate_players' );
        add_submenu_page( 'talenttrack', __( 'Evaluations', 'talenttrack' ), __( 'Evaluations', 'talenttrack' ), 'tt_evaluate_players', 'tt-evaluations', [ EvaluationsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Sessions', 'talenttrack' ), __( 'Sessions', 'talenttrack' ), 'tt_evaluate_players', 'tt-sessions', [ SessionsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Goals', 'talenttrack' ), __( 'Goals', 'talenttrack' ), 'tt_evaluate_players', 'tt-goals', [ GoalsPage::class, 'render_page' ] );

        self::addSeparator( 'tt-sep-analytics', __( 'Analytics', 'talenttrack' ), 'tt_view_reports' );
        add_submenu_page( 'talenttrack', __( 'Reports', 'talenttrack' ), __( 'Reports', 'talenttrack' ), 'tt_view_reports', 'tt-reports', [ ReportsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Player Rate Cards', 'talenttrack' ), __( 'Player Rate Cards', 'talenttrack' ), 'tt_view_reports', 'tt-rate-cards', [ PlayerRateCardsPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Usage Statistics', 'talenttrack' ), __( 'Usage Statistics', 'talenttrack' ), 'tt_manage_settings', 'tt-usage-stats', [ \TT\Modules\Stats\Admin\UsageStatsPage::class, 'render' ] );

        self::addSeparator( 'tt-sep-config', __( 'Configuration', 'talenttrack' ), 'tt_manage_settings' );
        add_submenu_page( 'talenttrack', __( 'Configuration', 'talenttrack' ), __( 'Configuration', 'talenttrack' ), 'tt_manage_settings', 'tt-config', [ ConfigurationPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Custom Fields', 'talenttrack' ), __( 'Custom Fields', 'talenttrack' ), 'tt_manage_settings', 'tt-custom-fields', [ CustomFieldsPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Evaluation Categories', 'talenttrack' ), __( 'Evaluation Categories', 'talenttrack' ), 'tt_manage_settings', 'tt-eval-categories', [ EvalCategoriesPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Category Weights', 'talenttrack' ), __( 'Category Weights', 'talenttrack' ), 'tt_manage_settings', 'tt-category-weights', [ CategoryWeightsPage::class, 'render' ] );

        add_submenu_page( 'talenttrack', __( 'Help & Docs', 'talenttrack' ), __( 'Help & Docs', 'talenttrack' ), 'read', 'tt-docs', [ DocumentationPage::class, 'render_page' ] );

        add_action( 'admin_head', [ __CLASS__, 'injectMenuCss' ] );
    }

    /**
     * Add a fake-separator submenu row. It's a real submenu entry (so
     * WordPress renders it in the right place), but the slug begins with
     * "tt-sep-" and the CSS in injectMenuCss() styles it as a non-clickable
     * heading. The callback redirects to the dashboard as a fallback if
     * someone manages to click it anyway (e.g. keyboard navigation).
     */
    private static function addSeparator( string $slug, string $label, string $cap ): void {
        add_submenu_page(
            'talenttrack',
            $label,
            '<span class="tt-menu-separator-label">' . esc_html( $label ) . '</span>',
            $cap,
            $slug,
            function() { wp_safe_redirect( admin_url( 'admin.php?page=talenttrack' ) ); exit; }
        );
    }

    /**
     * CSS that styles tt-sep-* entries as non-clickable heading rows.
     */
    public static function injectMenuCss(): void {
        ?>
        <style>
        #adminmenu .wp-submenu a[href*="page=tt-sep-"] {
            pointer-events: none;
            cursor: default !important;
            padding: 14px 12px 4px !important;
            color: #8a9099 !important;
            font-size: 10px !important;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 600;
            border-top: 1px solid rgba(255,255,255,0.08);
            margin-top: 4px;
        }
        #adminmenu .wp-submenu a[href*="page=tt-sep-"]:hover,
        #adminmenu .wp-submenu a[href*="page=tt-sep-"]:focus {
            color: #8a9099 !important;
            background: transparent !important;
        }
        /* The first separator in a menu doesn't need the top border */
        #adminmenu .wp-submenu li:first-child a[href*="page=tt-sep-"] {
            border-top: none;
            margin-top: 0;
        }
        #adminmenu .wp-submenu a[href*="page=tt-sep-"] .tt-menu-separator-label {
            pointer-events: none;
        }
        </style>
        <?php
    }

    public static function dashboard(): void {
        global $wpdb; $p = $wpdb->prefix;

        // v2.18.0 — dashboard overhaul. Stats section (5 overview cards,
        // clickable) + tiles section (mirrors the menu groups, each tile
        // = one menu entry, cap-gated to respect user role).

        // Stats for the Overview section
        $stats = [
            [
                'label'    => __( 'Players', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_players WHERE status='active' AND archived_at IS NULL" ),
                'icon'     => 'dashicons-admin-users',
                'url'      => admin_url( 'admin.php?page=tt-players' ),
                'cap'      => 'tt_manage_players',
                'gradient' => 'linear-gradient(135deg, #1d7874 0%, #35a29f 100%)',
            ],
            [
                'label'    => __( 'Teams', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_teams WHERE archived_at IS NULL" ),
                'icon'     => 'dashicons-shield',
                'url'      => admin_url( 'admin.php?page=tt-teams' ),
                'cap'      => 'tt_manage_players',
                'gradient' => 'linear-gradient(135deg, #2271b1 0%, #5aa0d6 100%)',
            ],
            [
                'label'    => __( 'Evaluations', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_evaluations WHERE archived_at IS NULL" ),
                'icon'     => 'dashicons-chart-bar',
                'url'      => admin_url( 'admin.php?page=tt-evaluations' ),
                'cap'      => 'tt_evaluate_players',
                'gradient' => 'linear-gradient(135deg, #7c3a9e 0%, #b56cd8 100%)',
            ],
            [
                'label'    => __( 'Sessions', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_sessions WHERE archived_at IS NULL" ),
                'icon'     => 'dashicons-calendar-alt',
                'url'      => admin_url( 'admin.php?page=tt-sessions' ),
                'cap'      => 'tt_evaluate_players',
                'gradient' => 'linear-gradient(135deg, #c9962a 0%, #f7c85a 100%)',
            ],
            [
                'label'    => __( 'Goals', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_goals WHERE archived_at IS NULL" ),
                'icon'     => 'dashicons-flag',
                'url'      => admin_url( 'admin.php?page=tt-goals' ),
                'cap'      => 'tt_evaluate_players',
                'gradient' => 'linear-gradient(135deg, #b32d2e 0%, #e05858 100%)',
            ],
        ];

        // Grouped tiles section — mirrors the menu structure. Per decision
        // D-x: primary entities appear both in Overview (top) AND in their
        // corresponding Performance/People tiles here.
        $groups = [
            [
                'id'     => 'people',
                'label'  => __( 'People', 'talenttrack' ),
                'accent' => '#1d7874',
                'tiles'  => [
                    [ 'label' => __( 'Teams', 'talenttrack' ),   'icon' => 'dashicons-shield',       'url' => admin_url( 'admin.php?page=tt-teams' ),   'cap' => 'tt_manage_players', 'desc' => __( 'Manage teams, staff assignments, and age groups.', 'talenttrack' ) ],
                    [ 'label' => __( 'Players', 'talenttrack' ), 'icon' => 'dashicons-admin-users', 'url' => admin_url( 'admin.php?page=tt-players' ), 'cap' => 'tt_manage_players', 'desc' => __( 'Player roster, positions, photos, guardian info.', 'talenttrack' ) ],
                    [ 'label' => __( 'People', 'talenttrack' ),  'icon' => 'dashicons-groups',      'url' => admin_url( 'admin.php?page=tt-people' ),  'cap' => 'tt_manage_players', 'desc' => __( 'Coaches, assistants, medical staff, volunteers.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'performance',
                'label'  => __( 'Performance', 'talenttrack' ),
                'accent' => '#7c3a9e',
                'tiles'  => [
                    [ 'label' => __( 'Evaluations', 'talenttrack' ), 'icon' => 'dashicons-chart-bar',     'url' => admin_url( 'admin.php?page=tt-evaluations' ), 'cap' => 'tt_evaluate_players', 'desc' => __( 'Rate players across training and match sessions.', 'talenttrack' ) ],
                    [ 'label' => __( 'Sessions', 'talenttrack' ),    'icon' => 'dashicons-calendar-alt', 'url' => admin_url( 'admin.php?page=tt-sessions' ),    'cap' => 'tt_evaluate_players', 'desc' => __( 'Record training sessions and attendance.', 'talenttrack' ) ],
                    [ 'label' => __( 'Goals', 'talenttrack' ),       'icon' => 'dashicons-flag',         'url' => admin_url( 'admin.php?page=tt-goals' ),       'cap' => 'tt_evaluate_players', 'desc' => __( 'Set and track development goals per player.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'analytics',
                'label'  => __( 'Analytics', 'talenttrack' ),
                'accent' => '#2271b1',
                'tiles'  => [
                    [ 'label' => __( 'Reports', 'talenttrack' ),           'icon' => 'dashicons-media-document', 'url' => admin_url( 'admin.php?page=tt-reports' ),      'cap' => 'tt_view_reports',    'desc' => __( 'Saved report presets and exports.', 'talenttrack' ) ],
                    [ 'label' => __( 'Player Rate Cards', 'talenttrack' ), 'icon' => 'dashicons-id-alt',         'url' => admin_url( 'admin.php?page=tt-rate-cards' ),   'cap' => 'tt_view_reports',    'desc' => __( 'Per-player rate cards with trends and charts.', 'talenttrack' ) ],
                    [ 'label' => __( 'Usage Statistics', 'talenttrack' ),  'icon' => 'dashicons-chart-line',     'url' => admin_url( 'admin.php?page=tt-usage-stats' ),  'cap' => 'tt_manage_settings', 'desc' => __( 'Logins, active users, most-visited pages.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'configuration',
                'label'  => __( 'Configuration', 'talenttrack' ),
                'accent' => '#555',
                'tiles'  => [
                    [ 'label' => __( 'Configuration', 'talenttrack' ),        'icon' => 'dashicons-admin-settings',  'url' => admin_url( 'admin.php?page=tt-config' ),             'cap' => 'tt_manage_settings', 'desc' => __( 'Academy name, logo, rating scale, colors.', 'talenttrack' ) ],
                    [ 'label' => __( 'Custom Fields', 'talenttrack' ),        'icon' => 'dashicons-editor-table',    'url' => admin_url( 'admin.php?page=tt-custom-fields' ),      'cap' => 'tt_manage_settings', 'desc' => __( 'Add club-specific fields to any entity.', 'talenttrack' ) ],
                    [ 'label' => __( 'Evaluation Categories', 'talenttrack' ),'icon' => 'dashicons-category',        'url' => admin_url( 'admin.php?page=tt-eval-categories' ),    'cap' => 'tt_manage_settings', 'desc' => __( 'Main + subcategories used in evaluations.', 'talenttrack' ) ],
                    [ 'label' => __( 'Category Weights', 'talenttrack' ),     'icon' => 'dashicons-chart-pie',       'url' => admin_url( 'admin.php?page=tt-category-weights' ),   'cap' => 'tt_manage_settings', 'desc' => __( 'Per-age-group weighting for overall ratings.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'help',
                'label'  => __( 'Help', 'talenttrack' ),
                'accent' => '#888',
                'tiles'  => [
                    [ 'label' => __( 'Help & Docs', 'talenttrack' ), 'icon' => 'dashicons-book', 'url' => admin_url( 'admin.php?page=tt-docs' ), 'cap' => 'read', 'desc' => __( 'How to use TalentTrack.', 'talenttrack' ) ],
                ],
            ],
        ];
        ?>

        <style>
        /* Scoped dashboard styles — v2.18.0 */
        .tt-dash-page { max-width: 1400px; }
        .tt-dash-page h1 { margin-bottom: 4px; }
        .tt-dash-intro { color: #666; margin-bottom: 24px; max-width: 760px; }

        .tt-dash-section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #8a9099;
            margin: 28px 0 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .tt-dash-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #dcdcde;
        }

        /* Stat cards (Overview section) */
        .tt-dash-overview {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 14px;
        }
        .tt-dash-stat {
            position: relative;
            display: block;
            background: #fff;
            border-radius: 10px;
            padding: 18px 20px;
            text-decoration: none;
            color: #1a1d21;
            overflow: hidden;
            transition: transform 220ms cubic-bezier(0.2, 0.8, 0.2, 1),
                        box-shadow 220ms ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            isolation: isolate;
        }
        .tt-dash-stat:hover, .tt-dash-stat:focus {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
            color: #1a1d21;
        }
        .tt-dash-stat-bg {
            position: absolute;
            top: 0; right: 0;
            width: 70%;
            height: 100%;
            opacity: 0.12;
            z-index: 0;
            pointer-events: none;
        }
        .tt-dash-stat-icon {
            position: relative;
            z-index: 1;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            color: #fff;
        }
        .tt-dash-stat-icon .dashicons {
            font-size: 22px;
            width: 22px;
            height: 22px;
            line-height: 22px;
        }
        .tt-dash-stat-count {
            position: relative;
            z-index: 1;
            font-size: 32px;
            font-weight: 700;
            line-height: 1.1;
        }
        .tt-dash-stat-label {
            position: relative;
            z-index: 1;
            color: #555;
            font-size: 13px;
            margin-top: 2px;
        }

        /* Tile groups */
        .tt-dash-group {
            margin-bottom: 8px;
        }
        .tt-dash-tiles {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }
        .tt-dash-tile {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: #fff;
            border: 1px solid #e5e7ea;
            border-radius: 8px;
            padding: 14px 16px;
            text-decoration: none;
            color: #1a1d21;
            transition: transform 200ms cubic-bezier(0.2, 0.8, 0.2, 1),
                        box-shadow 200ms ease,
                        border-color 200ms ease;
        }
        .tt-dash-tile:hover, .tt-dash-tile:focus {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            color: #1a1d21;
        }
        .tt-dash-tile-icon {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .tt-dash-tile-icon .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
            line-height: 20px;
        }
        .tt-dash-tile-body { flex: 1; min-width: 0; }
        .tt-dash-tile-label {
            font-weight: 600;
            font-size: 14px;
            margin: 0 0 3px;
            color: #1a1d21;
        }
        .tt-dash-tile-desc {
            color: #666;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
        }

        @media (max-width: 640px) {
            .tt-dash-overview { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
            .tt-dash-stat-count { font-size: 24px; }
            .tt-dash-tiles { grid-template-columns: 1fr; }
        }
        </style>

        <div class="wrap tt-dash-page">
            <h1><?php esc_html_e( 'TalentTrack Dashboard', 'talenttrack' ); ?></h1>
            <p class="tt-dash-intro">
                <?php esc_html_e( 'Your club at a glance. Tap any card or tile to jump straight into that section.', 'talenttrack' ); ?>
            </p>

            <!-- Overview stats -->
            <?php
            $visible_stats = array_filter( $stats, function ( $s ) { return current_user_can( $s['cap'] ); } );
            if ( ! empty( $visible_stats ) ) :
                ?>
                <div class="tt-dash-section-label">
                    <span><?php esc_html_e( 'Overview', 'talenttrack' ); ?></span>
                </div>
                <div class="tt-dash-overview">
                    <?php foreach ( $visible_stats as $s ) : ?>
                        <a class="tt-dash-stat" href="<?php echo esc_url( $s['url'] ); ?>">
                            <span class="tt-dash-stat-bg" style="background:<?php echo esc_attr( $s['gradient'] ); ?>;"></span>
                            <span class="tt-dash-stat-icon" style="background:<?php echo esc_attr( $s['gradient'] ); ?>;">
                                <span class="dashicons <?php echo esc_attr( $s['icon'] ); ?>"></span>
                            </span>
                            <div class="tt-dash-stat-count"><?php echo esc_html( (string) $s['count'] ); ?></div>
                            <div class="tt-dash-stat-label"><?php echo esc_html( $s['label'] ); ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Grouped tiles -->
            <?php foreach ( $groups as $group ) :
                $visible_tiles = array_filter( $group['tiles'], function ( $t ) { return current_user_can( $t['cap'] ); } );
                if ( empty( $visible_tiles ) ) continue;
                ?>
                <div class="tt-dash-group">
                    <div class="tt-dash-section-label">
                        <span><?php echo esc_html( $group['label'] ); ?></span>
                    </div>
                    <div class="tt-dash-tiles">
                        <?php foreach ( $visible_tiles as $tile ) :
                            // Tint the tile icon by the group's accent color, with
                            // a subtle gradient towards white for visual depth.
                            $icon_bg = sprintf( 'linear-gradient(135deg, %s 0%%, %s 100%%)',
                                $group['accent'],
                                self::lighten( $group['accent'], 25 )
                            );
                            ?>
                            <a class="tt-dash-tile" href="<?php echo esc_url( $tile['url'] ); ?>">
                                <span class="tt-dash-tile-icon" style="background:<?php echo esc_attr( $icon_bg ); ?>;">
                                    <span class="dashicons <?php echo esc_attr( $tile['icon'] ); ?>"></span>
                                </span>
                                <div class="tt-dash-tile-body">
                                    <div class="tt-dash-tile-label"><?php echo esc_html( $tile['label'] ); ?></div>
                                    <p class="tt-dash-tile-desc"><?php echo esc_html( $tile['desc'] ); ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Lighten a hex color by a given percentage (0-100). Used to build
     * subtle gradients for tile icons.
     */
    private static function lighten( string $hex, int $percent ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) return '#' . $hex;
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $pct = max( 0, min( 100, $percent ) ) / 100;
        $r = min( 255, (int) round( $r + ( 255 - $r ) * $pct ) );
        $g = min( 255, (int) round( $g + ( 255 - $g ) * $pct ) );
        $b = min( 255, (int) round( $b + ( 255 - $b ) * $pct ) );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, 'talenttrack' ) === false && strpos( $hook, 'tt-' ) === false ) return;
        wp_enqueue_style( 'tt-admin', TT_PLUGIN_URL . 'assets/css/admin.css', [], TT_VERSION );
        wp_enqueue_script( 'tt-admin', TT_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], TT_VERSION, true );

        // Register (not auto-enqueue) the sortable script. The CustomFieldsTab
        // and OptionSetEditor call wp_enqueue_script('tt-admin-sortable') on
        // demand — this registration makes that call effective.
        wp_register_script(
            'tt-admin-sortable',
            TT_PLUGIN_URL . 'assets/js/admin-sortable.js',
            [],
            TT_VERSION,
            true
        );
    }
}
