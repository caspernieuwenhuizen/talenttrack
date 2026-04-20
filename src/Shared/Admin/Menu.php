<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Configuration\Admin\ConfigurationPage;
use TT\Modules\Configuration\Admin\CustomFieldsPage;
use TT\Modules\Documentation\Admin\DocumentationPage;
use TT\Modules\Evaluations\Admin\EvaluationsPage;
use TT\Modules\Goals\Admin\GoalsPage;
use TT\Modules\Players\Admin\PlayersPage;
use TT\Modules\Reports\Admin\ReportsPage;
use TT\Modules\Sessions\Admin\SessionsPage;
use TT\Modules\Teams\Admin\TeamsPage;

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
    }

    public static function register(): void {
        add_menu_page( __( 'TalentTrack', 'talenttrack' ), __( 'TalentTrack', 'talenttrack' ), 'read', 'talenttrack', [ __CLASS__, 'dashboard' ], 'dashicons-groups', 26 );
        add_submenu_page( 'talenttrack', __( 'Dashboard', 'talenttrack' ), __( 'Dashboard', 'talenttrack' ), 'read', 'talenttrack', [ __CLASS__, 'dashboard' ] );
        add_submenu_page( 'talenttrack', __( 'Teams', 'talenttrack' ), __( 'Teams', 'talenttrack' ), 'tt_manage_players', 'tt-teams', [ TeamsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Players', 'talenttrack' ), __( 'Players', 'talenttrack' ), 'tt_manage_players', 'tt-players', [ PlayersPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Evaluations', 'talenttrack' ), __( 'Evaluations', 'talenttrack' ), 'tt_evaluate_players', 'tt-evaluations', [ EvaluationsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Sessions', 'talenttrack' ), __( 'Sessions', 'talenttrack' ), 'tt_evaluate_players', 'tt-sessions', [ SessionsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Goals', 'talenttrack' ), __( 'Goals', 'talenttrack' ), 'tt_evaluate_players', 'tt-goals', [ GoalsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Reports', 'talenttrack' ), __( 'Reports', 'talenttrack' ), 'tt_view_reports', 'tt-reports', [ ReportsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Configuration', 'talenttrack' ), __( 'Configuration', 'talenttrack' ), 'tt_manage_settings', 'tt-config', [ ConfigurationPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Custom Fields', 'talenttrack' ), __( 'Custom Fields', 'talenttrack' ), 'tt_manage_settings', 'tt-custom-fields', [ CustomFieldsPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Help & Docs', 'talenttrack' ), __( 'Help & Docs', 'talenttrack' ), 'read', 'tt-docs', [ DocumentationPage::class, 'render_page' ] );
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
