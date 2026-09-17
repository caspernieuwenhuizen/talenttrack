<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BroadcastBanner (#3499) — operator broadcasts, in the product.
 *
 * Rendered above every frontend view for every logged-in persona
 * (`tt_dashboard_before_body`) and as a wp-admin notice. The frontend is
 * the one that matters: a frontend-only academy never opens wp-admin, so a
 * notice only there would reach nobody (the #3432 blind spot).
 *
 * Dismissing is a plain form posting to `admin-post.php`, so it works
 * without script, on the frontend and in wp-admin alike; the same action
 * is available to other front ends through `BroadcastsRestController`.
 */
final class BroadcastBanner {

    public const DISMISS_ACTION = 'tt_dismiss_broadcast';

    public static function init(): void {
        // After the subscription banner (5), above flash messages and alerts.
        add_action( 'tt_dashboard_before_body', [ self::class, 'renderFrontend' ], 6 );
        add_action( 'admin_notices', [ self::class, 'renderAdmin' ] );
        add_action( 'admin_post_' . self::DISMISS_ACTION, [ self::class, 'handleDismiss' ] );
    }

    public static function renderFrontend(): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;
        $broadcasts = Broadcasts::forUser( $user_id );
        if ( $broadcasts === [] ) return;

        wp_enqueue_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], TT_VERSION );
        wp_enqueue_style( 'tt-broadcast-banner', TT_PLUGIN_URL . 'assets/css/broadcast-banner.css', [ 'tt-tokens' ], TT_VERSION );

        echo self::html( $broadcasts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes.
    }

    public static function renderAdmin(): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;

        foreach ( Broadcasts::forUser( $user_id ) as $b ) {
            $class = $b['severity'] === Broadcasts::SEVERITY_WARNING ? 'notice-warning' : 'notice-info';
            echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $b['body'] ) . '</p>';
            if ( $b['dismissable'] ) {
                echo self::dismissForm( $b['id'], 'button button-small' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dismissForm() escapes.
            }
            echo '</div>';
        }
    }

    /**
     * @param list<array{id:int, body:string, severity:string, dismissable:bool, ends_at:string}> $broadcasts
     */
    public static function html( array $broadcasts ): string {
        $out = '';
        foreach ( $broadcasts as $b ) {
            $out .= '<div class="tt-broadcast tt-broadcast--' . esc_attr( $b['severity'] ) . '" role="status">'
                . '<p class="tt-broadcast__body">' . esc_html( $b['body'] ) . '</p>';
            if ( $b['dismissable'] ) {
                $out .= self::dismissForm( $b['id'], 'tt-broadcast__dismiss' );
            }
            $out .= '</div>';
        }
        return $out;
    }

    private static function dismissForm( int $id, string $button_class ): string {
        return '<form class="tt-broadcast__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
            . wp_nonce_field( self::DISMISS_ACTION, 'tt_nonce', true, false )
            . '<input type="hidden" name="action" value="' . esc_attr( self::DISMISS_ACTION ) . '">'
            . '<input type="hidden" name="broadcast_id" value="' . (int) $id . '">'
            . '<button type="submit" class="' . esc_attr( $button_class ) . '">' . esc_html_x( 'Dismiss', 'hide an operator broadcast', 'talenttrack' ) . '</button>'
            . '</form>';
    }

    public static function handleDismiss(): void {
        if ( ! is_user_logged_in() ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( self::DISMISS_ACTION, 'tt_nonce' );

        $id = isset( $_POST['broadcast_id'] ) ? absint( $_POST['broadcast_id'] ) : 0;
        Broadcasts::dismiss( get_current_user_id(), $id );

        $back = wp_get_referer();
        wp_safe_redirect( $back !== false ? $back : home_url( '/' ) );
        exit;
    }
}
