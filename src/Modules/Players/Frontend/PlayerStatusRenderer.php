<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\StatusVerdict;

/**
 * PlayerStatusRenderer (#0057 Sprint 4) — small render helpers for the
 * traffic-light dot, pill, and breakdown panel.
 *
 * Designed to be a discrete, labelled component so #0060 (persona
 * dashboard templates) can re-position it without rewriting markup.
 * The wrapping element always carries `class="tt-player-status-panel"`
 * for that purpose.
 */
final class PlayerStatusRenderer {

    /**
     * v3.110.65 — enqueue the traffic-light CSS. Idempotent. Callers
     * that emit `dot()` / `pill()` / `panel()` markup must invoke this
     * (or arrange for the stylesheet to be loaded another way) — the
     * renderer otherwise emits a `<span class="tt-status-dot ...">`
     * that has no visible width / height / color without the CSS, so
     * the user sees nothing.
     *
     * Only the wp-admin Team Players panel was enqueueing this until
     * now; the frontend Team detail view's roster Status column
     * called `dot()` blind to the missing CSS, which is what the
     * v3.110.65 user report surfaced ("Status column is not showing
     * anything").
     */
    public static function enqueueStyles(): void {
        wp_enqueue_style(
            'tt-player-status',
            plugins_url( 'assets/css/player-status.css', TT_PLUGIN_FILE ),
            [],
            (string) ( defined( 'TT_VERSION' ) ? TT_VERSION : '1' )
        );
    }

    /**
     * The dot for a colour alone — every input present, as far as this
     * helper knows. Callers that hold the verdict should use
     * {@see self::dotFor()} instead, which can tell partial from full.
     */
    public static function dot( string $color, bool $tappable = false ): string {
        return self::dotMarkup( $color, $tappable, true, '' );
    }

    /**
     * The dot for a verdict (#3413) — carries whether it was computed on
     * everything the methodology asks for.
     *
     * A squad table is a comparison, and the comparison was not
     * like-for-like: a player with no potential row and one with a potential
     * row are scored on different evidence and rendered as the same circle.
     * A partial dot gets a hollow centre and says so in its accessible name,
     * so the difference survives both a glance and a screen reader —
     * CLAUDE.md §2 rules out carrying meaning in hue alone.
     */
    public static function dotFor( StatusVerdict $verdict, bool $tappable = false ): string {
        return self::dotMarkup(
            $verdict->color,
            $tappable,
            $verdict->isComplete(),
            $verdict->coverageNote()
        );
    }

    private static function dotMarkup( string $color, bool $tappable, bool $complete, string $note ): string {
        $classes = [ 'tt-status-dot', self::colorClass( $color ) ];
        if ( $tappable )   $classes[] = 'tt-status-tappable';
        if ( ! $complete ) $classes[] = 'tt-status-partial';

        // Two already-translated sentences joined by punctuation. A msgid
        // of "%1$s — %2$s" would be a translatable em dash and nothing else.
        $label = self::labelFor( $color );
        if ( $note !== '' ) $label .= ' — ' . $note;

        return sprintf(
            '<span class="%s" aria-label="%s" title="%s"></span>',
            esc_attr( implode( ' ', $classes ) ),
            esc_attr( $label ),
            esc_attr( $label )
        );
    }

    public static function pill( string $color, string $label = '' ): string {
        $color_class = self::colorClass( $color );
        $text        = $label !== '' ? $label : self::labelFor( $color );
        return sprintf(
            '<span class="tt-status-pill %s">%s</span>',
            esc_attr( $color_class ),
            esc_html( $text )
        );
    }

    public static function panel( StatusVerdict $verdict, bool $show_breakdown = true ): string {
        $out  = '<section class="tt-player-status-panel" data-tt-player-status="1">';
        $out .= '  <div class="tt-status-panel__hero">';
        $out .= self::dotFor( $verdict );
        $out .= '    <strong>' . esc_html( self::labelFor( $verdict->color ) ) . '</strong>';
        if ( $show_breakdown && $verdict->score !== null ) {
            $out .= '    <span style="color:#5b6e75;font-size:12px;">' . esc_html( sprintf( '%s%%', $verdict->score ) ) . '</span>';
        }
        $out .= '  </div>';

        if ( $show_breakdown ) {
            $out .= '  <ul class="tt-status-panel__breakdown">';
            foreach ( $verdict->inputs as $key => $row ) {
                if ( $row['score'] === null ) continue;
                $out .= sprintf(
                    '    <li>%s: <strong>%s</strong> <small>(weight %d%%)</small></li>',
                    esc_html( ucfirst( $key ) ),
                    esc_html( (string) $row['score'] ),
                    (int) $row['weight']
                );
            }
            if ( ! empty( $verdict->reasons ) ) {
                $out .= '    <li style="margin-top:6px;color:#92400e;">' . esc_html( implode( ' · ', $verdict->reasons ) ) . '</li>';
            }
            $out .= '  </ul>';
        }
        $out .= '</section>';
        return $out;
    }

    private static function colorClass( string $color ): string {
        switch ( $color ) {
            case StatusVerdict::COLOR_GREEN:   return 'tt-status-green';
            case StatusVerdict::COLOR_AMBER:   return 'tt-status-amber';
            case StatusVerdict::COLOR_RED:     return 'tt-status-red';
            default:                           return 'tt-status-unknown';
        }
    }

    private static function labelFor( string $color ): string {
        switch ( $color ) {
            case StatusVerdict::COLOR_GREEN:   return __( 'On track',   'talenttrack' );
            case StatusVerdict::COLOR_AMBER:   return __( 'Extra attention', 'talenttrack' );
            case StatusVerdict::COLOR_RED:     return __( 'Critical',   'talenttrack' );
            default:                           return __( 'Building first picture', 'talenttrack' );
        }
    }
}
