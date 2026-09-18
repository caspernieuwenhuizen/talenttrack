<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Dates\TTDate;

/**
 * SupportGrantBanner (#3501) — what the club sees while support has access.
 *
 * **Not dismissable, by design.** Visibility is the entire safeguard: the
 * decision was that a grant needs no approval step from the club, and that
 * trade only holds while the club can see who has access, why, and when it
 * ends. A banner somebody can click away is a banner that is not there, and a
 * grant a club cannot see is indistinguishable from a backdoor.
 *
 * It therefore has no dismiss control at all, unlike {@see BroadcastBanner},
 * whose notices are ordinary operator messages.
 *
 * Rendered above every frontend view for every signed-in staff user
 * (`tt_dashboard_before_body`) and as a wp-admin notice. The frontend is the
 * one that matters: a frontend-only academy never opens wp-admin, so a notice
 * only there would reach nobody — the #3432 blind spot.
 */
final class SupportGrantBanner {

    public static function init(): void {
        // Above the broadcast banner (6): access to the club's data outranks
        // an operator notice about a maintenance window.
        add_action( 'tt_dashboard_before_body', [ self::class, 'renderFrontend' ], 4 );
        add_action( 'admin_notices', [ self::class, 'renderAdmin' ] );
    }

    public static function renderFrontend(): void {
        if ( get_current_user_id() <= 0 ) return;

        $grants = SupportGrants::live();
        if ( $grants === [] ) return;

        wp_enqueue_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], TT_VERSION );
        wp_enqueue_style( 'tt-support-grant-banner', TT_PLUGIN_URL . 'assets/css/support-grant-banner.css', [ 'tt-tokens' ], TT_VERSION );

        echo self::html( $grants ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes.
    }

    public static function renderAdmin(): void {
        if ( get_current_user_id() <= 0 ) return;

        foreach ( SupportGrants::live() as $grant ) {
            echo '<div class="notice notice-warning"><p>' . esc_html( self::sentence( $grant ) ) . '</p></div>';
        }
    }

    /**
     * @param list<array{id:int, operator:string, reason:string, expires_at:string}> $grants
     */
    public static function html( array $grants ): string {
        $out = '';
        foreach ( $grants as $grant ) {
            $out .= '<div class="tt-support-grant" role="status">'
                . '<p class="tt-support-grant__body">' . esc_html( self::sentence( $grant ) ) . '</p>'
                . '</div>';
        }
        return $out;
    }

    /**
     * One grant, in a sentence: who, why, and when it ends — in the club's own
     * timezone, because "ends 10:00" has to mean their ten o'clock.
     *
     * A grant with no reason still names the operator and the end time. The
     * club is owed all three, and saying "no reason was given" is more useful
     * than hiding the fact that somebody has access.
     *
     * @param array{id:int, operator:string, reason:string, expires_at:string} $grant
     */
    public static function sentence( array $grant ): string {
        $ends = TTDate::dateTimeFromGmt( $grant['expires_at'] );

        if ( $grant['reason'] === '' ) {
            return sprintf(
                /* translators: 1: the support operator's name, 2: local date and time the access ends. */
                __( 'Support access is active: %1$s — no reason was given. Ends %2$s.', 'talenttrack' ),
                $grant['operator'],
                $ends
            );
        }

        return sprintf(
            /* translators: 1: the support operator's name, 2: why they were given access, 3: local date and time it ends. */
            __( 'Support access is active: %1$s — %2$s. Ends %3$s.', 'talenttrack' ),
            $grant['operator'],
            $grant['reason'],
            $ends
        );
    }
}
