<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LabelTranslator — convert internal status/priority codes into translated
 * human-readable labels.
 *
 * Replaces the ucwords(str_replace('_',' ',$value)) pattern used in v2.3.0
 * and earlier, which was untranslatable.
 *
 * Falls back to the humanised raw code if an unknown value is passed —
 * never breaks rendering, just loses the translation for that case.
 */
class LabelTranslator {

    public static function goalStatus( string $code ): string {
        switch ( $code ) {
            case 'pending':      return __( 'Pending', 'talenttrack' );
            case 'in_progress':  return __( 'In Progress', 'talenttrack' );
            case 'completed':    return __( 'Completed', 'talenttrack' );
            case 'on_hold':      return __( 'On Hold', 'talenttrack' );
            case 'cancelled':    return __( 'Cancelled', 'talenttrack' );
            default:             return self::humanise( $code );
        }
    }

    public static function goalPriority( string $code ): string {
        switch ( strtolower( $code ) ) {
            case 'low':    return __( 'Low', 'talenttrack' );
            case 'medium': return __( 'Medium', 'talenttrack' );
            case 'high':   return __( 'High', 'talenttrack' );
            default:       return self::humanise( $code );
        }
    }

    public static function playerStatus( string $code ): string {
        switch ( $code ) {
            case 'active':   return __( 'Active', 'talenttrack' );
            case 'inactive': return __( 'Inactive', 'talenttrack' );
            case 'trial':    return __( 'Trial', 'talenttrack' );
            case 'released': return __( 'Released', 'talenttrack' );
            case 'deleted':  return __( 'Deleted', 'talenttrack' );
            default:         return self::humanise( $code );
        }
    }

    public static function attendanceStatus( string $name ): string {
        switch ( $name ) {
            case 'Present': return __( 'Present', 'talenttrack' );
            case 'Absent':  return __( 'Absent', 'talenttrack' );
            case 'Late':    return __( 'Late', 'talenttrack' );
            case 'Injured': return __( 'Injured', 'talenttrack' );
            case 'Excused': return __( 'Excused', 'talenttrack' );
            default:        return $name;
        }
    }

    /**
     * Fallback: "in_progress" → "In Progress".
     */
    private static function humanise( string $code ): string {
        return ucwords( str_replace( [ '_', '-' ], ' ', $code ) );
    }
}
