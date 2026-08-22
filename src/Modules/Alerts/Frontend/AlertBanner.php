<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\AlertPolicyResolver;
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

        // #2632 — only occurrences whose alert may use the banner surface
        // for this user. Filtering here rather than in SQL keeps the
        // precedence rules in one place (`AlertPolicyResolver`) instead of
        // half in a query; the row count per user is small enough that the
        // cost is a few array lookups.
        //
        // Over-fetched because the filter runs after the query: asking for
        // exactly MAX_VISIBLE would show fewer than that whenever the top
        // rows happen to be badge-only.
        $policy = new AlertPolicyResolver();
        $rows   = $repo->openForUser( $user_id, self::MAX_VISIBLE + 20 );

        $eligible = [];
        foreach ( $rows as $row ) {
            if ( $policy->allows( $user_id, (string) ( $row->alert_key ?? '' ), Surface::BANNER ) ) {
                $eligible[] = $row;
            }
        }
        if ( empty( $eligible ) ) return;

        $visible   = array_slice( $eligible, 0, self::MAX_VISIBLE );
        $remaining = max( 0, count( $eligible ) - count( $visible ) );

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
