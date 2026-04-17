<?php
namespace TT\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class Menu {
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function register() {
        add_menu_page( 'TalentTrack', 'TalentTrack', 'read', 'talenttrack', [ __CLASS__, 'dashboard' ], 'dashicons-groups', 26 );
        add_submenu_page( 'talenttrack', 'Dashboard', 'Dashboard', 'read', 'talenttrack', [ __CLASS__, 'dashboard' ] );
        add_submenu_page( 'talenttrack', 'Teams', 'Teams', 'tt_manage_players', 'tt-teams', [ Teams::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', 'Players', 'Players', 'tt_manage_players', 'tt-players', [ Players::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', 'Evaluations', 'Evaluations', 'tt_evaluate_players', 'tt-evaluations', [ Evaluations::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', 'Sessions', 'Sessions', 'tt_evaluate_players', 'tt-sessions', [ Sessions::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', 'Goals', 'Goals', 'tt_evaluate_players', 'tt-goals', [ Goals::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', 'Reports', 'Reports', 'tt_evaluate_players', 'tt-reports', [ Reports::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', 'Configuration', 'Configuration', 'tt_manage_settings', 'tt-config', [ Configuration::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', 'Help & Docs', 'Help & Docs', 'read', 'tt-docs', [ Documentation::class, 'render_page' ] );
    }

    public static function dashboard() {
        global $wpdb; $p = $wpdb->prefix;
        $stats = [
            [ 'Players',     (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_players WHERE status='active'" ), 'dashicons-admin-users' ],
            [ 'Teams',       (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_teams" ), 'dashicons-shield' ],
            [ 'Evaluations', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_evaluations" ), 'dashicons-chart-bar' ],
            [ 'Sessions',    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_sessions" ), 'dashicons-calendar-alt' ],
            [ 'Goals',       (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_goals" ), 'dashicons-flag' ],
        ];
        ?>
        <div class="wrap">
            <h1>TalentTrack Dashboard</h1>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-top:20px;">
            <?php foreach ( $stats as $s ) : ?>
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;text-align:center;">
                    <span class="dashicons <?php echo esc_attr( $s[2] ); ?>" style="font-size:32px;width:32px;height:32px;color:#0b3d2e;"></span>
                    <h2 style="margin:8px 0 0;"><?php echo esc_html( $s[1] ); ?></h2>
                    <p style="color:#646970;margin:4px 0 0;"><?php echo esc_html( $s[0] ); ?></p>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public static function enqueue( $hook ) {
        if ( strpos( $hook, 'talenttrack' ) === false && strpos( $hook, 'tt-' ) === false ) return;
        wp_enqueue_style( 'tt-admin', TT_PLUGIN_URL . 'assets/css/admin.css', [], TT_VERSION );
        wp_enqueue_script( 'tt-admin', TT_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], TT_VERSION, true );
    }
}
