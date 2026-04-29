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

    public static function dot( string $color, bool $tappable = false ): string {
        $color_class = self::colorClass( $color );
        $extra       = $tappable ? ' tt-status-tappable' : '';
        return sprintf(
            '<span class="tt-status-dot %s%s" aria-label="%s" title="%s"></span>',
            esc_attr( $color_class ),
            esc_attr( $extra ),
            esc_attr( self::labelFor( $color ) ),
            esc_attr( self::labelFor( $color ) )
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
        $out .= self::dot( $verdict->color );
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
            case StatusVerdict::COLOR_AMBER:   return __( 'At risk',    'talenttrack' );
            case StatusVerdict::COLOR_RED:     return __( 'Critical',   'talenttrack' );
            default:                           return __( 'Building first picture', 'talenttrack' );
        }
    }
}
