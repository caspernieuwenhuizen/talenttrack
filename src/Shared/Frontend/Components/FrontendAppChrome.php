<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;

/**
 * FrontendAppChrome — presentational helpers for the shared frontend
 * "app chrome" (#1690): the persona chip that sits in the dashboard
 * header, a brand mark, and a reusable KPI tile.
 *
 * Pure presentation. This class composes already-authorized data
 * (the current user's own name + persona, or values handed to it by a
 * caller); it never decides eligibility or queries the database. Persona
 * resolution is delegated to PersonaResolver — the SaaS-portable identity
 * layer (CLAUDE.md §4) — so a future non-WordPress front end gets the
 * same label.
 *
 * Markup mirrors the 2026 pitch mockups (marketing/pitch/mockups/) but
 * uses `tt-`-prefixed classes per CLAUDE.md §2. Styling lives in
 * assets/css/frontend-app-chrome.css.
 */
final class FrontendAppChrome {

    /**
     * Persona key → human label. Mirrors the switcher LUT in
     * DashboardShortcode; kept here so the chip and any future consumer
     * share one source. Wrapped in __() so the Dutch .po carries them.
     *
     * @return array<string,string>
     */
    private static function personaLabels(): array {
        return [
            'player'              => __( 'Player', 'talenttrack' ),
            'parent'              => __( 'Parent', 'talenttrack' ),
            'assistant_coach'     => __( 'Assistant Coach', 'talenttrack' ),
            'head_coach'          => __( 'Head Coach', 'talenttrack' ),
            'head_of_development' => __( 'Head of Development', 'talenttrack' ),
            'scout'               => __( 'Scout', 'talenttrack' ),
            'team_manager'        => __( 'Team Manager', 'talenttrack' ),
            'academy_admin'       => __( 'Academy Admin', 'talenttrack' ),
            'readonly_observer'   => __( 'Observer', 'talenttrack' ),
        ];
    }

    /** Human label for a persona key, or '' when unknown. */
    public static function personaLabel( string $persona ): string {
        return self::personaLabels()[ $persona ] ?? '';
    }

    /**
     * Up-to-two-letter initials from a display name, for the avatar disc.
     * "John Doe" → "JD"; "Ajax" → "AJ".
     */
    public static function initials( string $name ): string {
        $name  = trim( preg_replace( '/\s+/', ' ', $name ) );
        if ( $name === '' ) return '?';
        $parts = explode( ' ', $name );
        if ( count( $parts ) >= 2 ) {
            $first = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1 ) : substr( $parts[0], 0, 1 );
            $last  = function_exists( 'mb_substr' ) ? mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) : substr( $parts[ count( $parts ) - 1 ], 0, 1 );
            return strtoupper( $first . $last );
        }
        return strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 2 ) : substr( $name, 0, 2 ) );
    }

    /**
     * Inner markup for the persona chip — an initials avatar plus the
     * user's name and resolved persona label. Designed to drop INSIDE the
     * existing `.tt-user-menu-trigger` button so the chip doubles as the
     * user-menu trigger (no extra affordance, no change to the dropdown
     * script which keys off the button class).
     *
     * Returns escaped HTML.
     */
    public static function personaChipInner( \WP_User $user ): string {
        $name = (string) $user->display_name;
        $role = '';
        if ( class_exists( PersonaResolver::class ) ) {
            $personas = PersonaResolver::effectivePersonas( (int) $user->ID );
            if ( ! empty( $personas ) ) {
                $role = self::personaLabel( (string) $personas[0] );
            }
        }

        $html  = '<span class="tt-appchrome-av" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span>';
        $html .= '<span class="tt-appchrome-who">';
        $html .= '<b>' . esc_html( $name ) . '</b>';
        if ( $role !== '' ) {
            $html .= '<span>' . esc_html( $role ) . '</span>';
        }
        $html .= '</span>';
        return $html;
    }

    /**
     * Reusable KPI tile, matching the mockup `.kpi` block. Pure string
     * builder — hand it already-computed values.
     *
     * @param array{
     *   label:string, value:string, delta?:string,
     *   trend?:string, flag?:string, href?:string
     * } $args trend ∈ up|down|flat; flag ∈ ''|red|green.
     */
    public static function kpiTile( array $args ): string {
        $label = (string) ( $args['label'] ?? '' );
        $value = (string) ( $args['value'] ?? '' );
        $delta = (string) ( $args['delta'] ?? '' );
        $trend = (string) ( $args['trend'] ?? '' );
        $flag  = (string) ( $args['flag'] ?? '' );
        $href  = (string) ( $args['href'] ?? '' );

        $tile_class = 'tt-kpi';
        if ( $flag === 'red' )   $tile_class .= ' tt-kpi--flag-red';
        if ( $flag === 'green' ) $tile_class .= ' tt-kpi--flag-green';

        $inner  = '<span class="tt-kpi__label">' . esc_html( $label ) . '</span>';
        $inner .= '<span class="tt-kpi__val">' . esc_html( $value ) . '</span>';
        if ( $delta !== '' ) {
            $dir = in_array( $trend, [ 'up', 'down', 'flat' ], true ) ? $trend : 'flat';
            $inner .= '<span class="tt-kpi__delta tt-kpi__delta--' . esc_attr( $dir ) . '">' . esc_html( $delta ) . '</span>';
        }

        if ( $href !== '' ) {
            return '<a class="' . esc_attr( $tile_class . ' tt-kpi--link' ) . '" href="' . esc_url( $href ) . '">' . $inner . '</a>';
        }
        return '<div class="' . esc_attr( $tile_class ) . '">' . $inner . '</div>';
    }
}
