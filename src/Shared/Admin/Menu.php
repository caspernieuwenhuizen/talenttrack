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
        $stats = [
            [ __( 'Players', 'talenttrack' ),     (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_players WHERE status='active'" ), 'dashicons-admin-users' ],
            [ __( 'Teams', 'talenttrack' ),       (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_teams" ), 'dashicons-shield' ],
            [ __( 'Evaluations', 'talenttrack' ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_evaluations" ), 'dashicons-chart-bar' ],
            [ __( 'Sessions', 'talenttrack' ),    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_sessions" ), 'dashicons-calendar-alt' ],
            [ __( 'Goals', 'talenttrack' ),       (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_goals" ), 'dashicons-flag' ],
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TalentTrack Dashboard', 'talenttrack' ); ?></h1>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-top:20px;">
            <?php foreach ( $stats as $s ) : ?>
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;text-align:center;">
                    <span class="dashicons <?php echo esc_attr( $s[2] ); ?>" style="font-size:32px;width:32px;height:32px;color:#0b3d2e;"></span>
                    <h2 style="margin:8px 0 0;"><?php echo esc_html( (string) $s[1] ); ?></h2>
                    <p style="color:#646970;margin:4px 0 0;"><?php echo esc_html( (string) $s[0] ); ?></p>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
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
