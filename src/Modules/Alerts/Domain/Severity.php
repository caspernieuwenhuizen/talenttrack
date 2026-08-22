<?php
namespace TT\Modules\Alerts\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Severity (#2631, epic #2629) — how loud an occurrence is.
 *
 * Three levels, deliberately few. A definition computes its severity at
 * evaluate time and may raise it as an occurrence ages, which is why the
 * value is stored on the row and recomputed on every reconcile rather than
 * derived at read time.
 *
 * Severity is not the same thing as the surface it renders on. Surfaces
 * (badge / inline / banner / interrupt) are a preference-layer concern that
 * lands in #2632; severity is the definition's own statement about how
 * much the condition matters. Keeping them separate is what lets a club
 * mute a definition's banner without the definition lying about urgency.
 */
final class Severity {

    public const INFO      = 'info';
    public const ATTENTION = 'attention';
    public const URGENT    = 'urgent';

    /** @return list<string> Lowest to highest. */
    public static function all(): array {
        return [ self::INFO, self::ATTENTION, self::URGENT ];
    }

    /** Unknown values coerce to `attention` — visible, but not alarming. */
    public static function normalise( string $value ): string {
        return in_array( $value, self::all(), true ) ? $value : self::ATTENTION;
    }

    /**
     * Sort weight, highest severity first. Used by the inbox and banner so
     * the loudest occurrence leads.
     */
    public static function weight( string $value ): int {
        switch ( self::normalise( $value ) ) {
            case self::URGENT:    return 3;
            case self::ATTENTION: return 2;
            default:              return 1;
        }
    }

    public static function label( string $value ): string {
        switch ( self::normalise( $value ) ) {
            case self::URGENT:
                return __( 'Urgent', 'talenttrack' );
            case self::INFO:
                return __( 'For information', 'talenttrack' );
            default:
                return __( 'Needs attention', 'talenttrack' );
        }
    }
}
