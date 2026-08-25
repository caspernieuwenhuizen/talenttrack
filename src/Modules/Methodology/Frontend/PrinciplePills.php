<?php
namespace TT\Modules\Methodology\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Services\ActivityPrinciples;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PrinciplePills (#2831) — the O/A/V pill row for a set of principles.
 *
 * Lifted out of the activity detail card so match prep, the printed team
 * sheet and the PDF render the identical row. The pills are a colour
 * vocabulary a coach learns once; three surfaces drawing them three ways
 * would teach it three times.
 *
 * Linking is gated on the methodology view's own guard (§7, #2304): a
 * reader who cannot open the library still sees the principles — they are
 * development content, and hiding them would say the match is about
 * nothing — but as text rather than as a dead-end link.
 */
final class PrinciplePills {

    /**
     * The pill stylesheet. Idempotent — every surface that renders pills
     * calls it, and `wp_enqueue_style` on a handle already queued is a
     * no-op.
     */
    public static function enqueue(): void {
        wp_enqueue_style(
            'tt-principle-pills',
            TT_PLUGIN_URL . 'assets/css/components/principle-pills.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    /**
     * @param list<array{id:int, code:string, title:string, bucket:string, label:string}> $principles
     * @param bool $linked false forces plain text — the print and PDF paths,
     *                     where a link is ink rather than a destination.
     */
    public static function render( array $principles, bool $linked = true ): string {
        if ( $principles === [] ) return '';

        $can_link = $linked && CrossViewLink::allows( 'methodology' );
        $base     = RecordLink::dashboardUrl();
        $out      = '';

        foreach ( $principles as $p ) {
            $class = 'tt-act-pp tt-act-pp--' . sanitize_html_class( (string) $p['bucket'] );

            if ( $can_link ) {
                $url = add_query_arg(
                    [ 'tt_view' => 'methodology', 'mtab' => 'principles', 'pid' => (int) $p['id'] ],
                    $base
                );
                $out .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"'
                    . ' title="' . esc_attr( (string) $p['title'] ) . '">'
                    . esc_html( (string) $p['label'] ) . '</a>';
            } else {
                $out .= '<span class="' . esc_attr( $class ) . '"'
                    . ' title="' . esc_attr( (string) $p['title'] ) . '">'
                    . esc_html( (string) $p['label'] ) . '</span>';
            }
        }

        return $out;
    }

    /**
     * The whole block as match prep and the read-only sheets show it: a
     * heading, the pills, or a single line saying where principles are
     * attached when there are none.
     *
     * The empty state is not decoration. A blank space says the feature is
     * missing; a line saying the principles live on the activity says the
     * match simply has none yet, and where to add them.
     *
     * @param bool $linked        false for print / PDF
     * @param ?string $empty_url  the activity edit URL, or null to render the
     *                            empty line without a link (no edit right)
     */
    public static function renderBlock( int $activity_id, string $heading, bool $linked = true, ?string $empty_url = null ): void {
        // Methodology switched off: no block at all, not an empty state. An
        // empty state invites the coach to go and add principles on a screen
        // their academy does not have.
        if ( ! ActivityPrinciples::isAvailable() ) return;

        $principles = ActivityPrinciples::forActivity( $activity_id );

        echo '<section class="tt-mp-principles">';
        echo '<h3 class="tt-mp-principles__title">' . esc_html( $heading ) . '</h3>';

        if ( $principles === [] ) {
            echo '<p class="tt-mp-principles__empty">';
            if ( $empty_url !== null ) {
                printf(
                    /* translators: %s: link to the activity, wrapping the words "on the activity". */
                    esc_html__( 'No principles are linked to this match — add them %s.', 'talenttrack' ),
                    '<a href="' . esc_url( $empty_url ) . '">'
                        . esc_html__( 'on the activity', 'talenttrack' ) . '</a>'
                );
            } else {
                esc_html_e( 'No principles are linked to this match.', 'talenttrack' );
            }
            echo '</p>';
            echo '</section>';
            return;
        }

        echo '<div class="tt-mp-principles__pills">'
            . self::render( $principles, $linked ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in render().
            . '</div>';
        echo '</section>';
    }
}
