<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertBanner (#2631, epic #2629) — the `banner` surface.
 *
 * Renders open occurrences at the top of the dashboard, above the body,
 * alongside the flash-message queue.
 *
 * **It never evaluates.** Epic decision 2: the render reads persisted rows
 * and nothing else, so adding a definition can never slow down a login. The
 * cron sweep is what keeps those rows true. If this class ever grows a call
 * into `AlertEvaluator`, that decision has been broken.
 *
 * Presentation is deliberately close to `FlashMessages`: a coach should not
 * have to learn a second visual language for "something needs your
 * attention". The difference is that a flash message is about what just
 * happened and an alert is about what is still true, which is why these
 * cannot be dismissed away permanently — dismissal lands with the
 * preference layer in #2632, and until then the only way to clear one is to
 * fix the underlying thing, which is the intended behaviour anyway.
 */
final class AlertBanner {

    /** Most occurrences rendered inline before collapsing to a count. */
    private const MAX_VISIBLE = 3;

    public static function init(): void {
        // Priority 20 puts alerts below the flash queue: "your evaluation
        // was saved" is about the action just taken and should stay at the
        // top; alerts are ambient state.
        add_action( 'tt_dashboard_before_body', [ self::class, 'render' ], 20 );
    }

    public static function render(): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;

        $repo = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return;

        $visible = $repo->openForUser( $user_id, self::MAX_VISIBLE );
        if ( empty( $visible ) ) return;

        // Counted rather than inferred from an over-fetch: a coach with
        // fifty open alerts should be told fifty, not "twenty more", which
        // is what a fetch-a-few-extra approach silently caps it at.
        $remaining = max( 0, $repo->openCountForUser( $user_id ) - count( $visible ) );

        echo '<div class="tt-alert-bar" role="region" aria-label="' . esc_attr__( 'Alerts', 'talenttrack' ) . '">';

        foreach ( $visible as $row ) {
            self::renderOne( $row );
        }

        if ( $remaining > 0 ) {
            printf(
                '<p class="tt-alert-bar-more">%s</p>',
                esc_html( sprintf(
                    /* translators: %d: number of further alerts not shown */
                    _n( '%d more alert needs your attention.', '%d more alerts need your attention.', $remaining, 'talenttrack' ),
                    $remaining
                ) )
            );
        }

        echo '</div>';
    }

    private static function renderOne( object $row ): void {
        $payload  = self::payload( $row );
        $title    = isset( $payload['title'] ) ? (string) $payload['title'] : '';
        $url      = isset( $payload['url'] ) ? (string) $payload['url'] : '';
        $severity = Severity::normalise( (string) ( $row->severity ?? '' ) );

        if ( $title === '' ) return;

        printf(
            '<div class="tt-alert tt-alert-%1$s"><span class="tt-alert-sev">%2$s</span><span class="tt-alert-text">%3$s</span>%4$s</div>',
            esc_attr( $severity ),
            esc_html( Severity::label( $severity ) ),
            esc_html( $title ),
            $url !== ''
                ? sprintf(
                    '<a class="tt-alert-cta" href="%s">%s</a>',
                    esc_url( $url ),
                    esc_html__( 'Open', 'talenttrack' )
                )
                : ''
        );
    }

    /** @return array<string,mixed> */
    private static function payload( object $row ): array {
        $raw = (string) ( $row->payload_json ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
