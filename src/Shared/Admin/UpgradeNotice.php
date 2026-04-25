<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UpgradeNotice — one-time admin notice announcing the v3.12.0 (#0019
 * Sprint 6) frontend-first migration.
 *
 * Tells admins that the wp-admin TalentTrack tools have moved to the
 * frontend, links to the new dashboard, and explains how to re-enable
 * legacy menus if preferred. Per-user dismissible via a meta key so
 * each admin sees the notice exactly once.
 *
 * Cap gate: `tt_access_frontend_admin` (granted to administrator +
 * tt_head_dev). Other TalentTrack roles never see it.
 *
 * Versioned by `META_KEY` so a future epic-equivalent change can ship
 * a fresh notice without re-displaying this one.
 */
class UpgradeNotice {

    private const META_KEY      = '_tt_upgrade_notice_v3_12_0_dismissed';
    private const ACTION_NONCE  = 'tt_upgrade_notice_dismiss';
    private const QUERY_TRIGGER = 'tt_upgrade_notice_dismiss';

    public static function init(): void {
        add_action( 'admin_notices',  [ __CLASS__, 'maybe_render' ] );
        add_action( 'admin_init',     [ __CLASS__, 'maybe_handle_dismiss' ] );
    }

    public static function maybe_render(): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) return;
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;
        if ( get_user_meta( $user_id, self::META_KEY, true ) ) return;

        $frontend_url = home_url( '/' );
        $admin_config = admin_url( 'admin.php?page=tt-config' );
        $dismiss_url  = wp_nonce_url(
            add_query_arg( self::QUERY_TRIGGER, '1' ),
            self::ACTION_NONCE,
            'tt_nonce'
        );

        ?>
        <div class="notice notice-info is-dismissible" style="border-left-color:#0b3d2e;">
            <h3 style="margin:0.5em 0 0.25em;">
                <?php esc_html_e( 'TalentTrack admin tools have moved to the frontend', 'talenttrack' ); ?>
            </h3>
            <p style="margin:0.25em 0; max-width:760px;">
                <?php esc_html_e( "We've migrated all TalentTrack admin tools to the frontend, behind a new Administration tile group. Legacy wp-admin menu entries are hidden by default. Direct URLs to legacy pages keep working as an emergency fallback.", 'talenttrack' ); ?>
            </p>
            <p style="margin:0.25em 0; max-width:760px;">
                <?php
                printf(
                    /* translators: %s: anchor opening + closing tags around "Settings → TalentTrack Configuration" */
                    esc_html__( 'If you prefer the legacy menus, visit %s and tick "Show legacy wp-admin menus" — that field is also available on the frontend Configuration view.', 'talenttrack' ),
                    '<a href="' . esc_url( $admin_config ) . '"><strong>' . esc_html__( 'TalentTrack → Configuration', 'talenttrack' ) . '</strong></a>'
                );
                ?>
            </p>
            <p style="margin:0.5em 0 1em;">
                <a class="button button-primary" href="<?php echo esc_url( $frontend_url ); ?>">
                    <?php esc_html_e( 'Open the frontend dashboard', 'talenttrack' ); ?>
                </a>
                <a class="button" href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:6px;">
                    <?php esc_html_e( 'Got it, dismiss', 'talenttrack' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Intercept the explicit dismiss link. The standard `is-dismissible`
     * close button on the notice element only hides it for the page
     * load — it doesn't persist. Using our own dismiss URL gives us a
     * nonce + a per-user meta write.
     */
    public static function maybe_handle_dismiss(): void {
        if ( empty( $_GET[ self::QUERY_TRIGGER ] ) ) return;
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) return;
        if ( ! check_admin_referer( self::ACTION_NONCE, 'tt_nonce' ) ) return;

        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            update_user_meta( $user_id, self::META_KEY, time() );
        }
        wp_safe_redirect( remove_query_arg( [ self::QUERY_TRIGGER, 'tt_nonce', '_wp_http_referer' ] ) );
        exit;
    }
}
