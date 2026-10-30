<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ConsentRequestDate (#4267) — whether a consent request's `asked_at` is a
 * date the academy can have asked on.
 *
 * The checks were format-only, so a mistyped year was stored as given and
 * the waiting counter and the "consent awaiting" alert read it as a request
 * left unanswered for months. A date must be real (`checkdate`) and not
 * later than today in the site timezone.
 *
 * A date before the prospect's `discovered_at` is accepted on purpose:
 * `discovered_at` is the day the prospect was entered, and a scout may
 * have asked the child's club before logging them.
 *
 * One rule for every write path: the REST controller, the workflow form
 * and the repository guard all ask this.
 */
final class ConsentRequestDate {

    public const ERROR_CODE = 'invalid_asked_at';

    /**
     * Null when `$asked_at` is acceptable, otherwise the reason as a
     * `WP_Error` carrying `invalid_asked_at` and status 400.
     *
     * @param string|null $today `Y-m-d`; defaults to today in the site timezone.
     */
    public static function validate( string $asked_at, ?string $today = null ): ?\WP_Error {
        $asked_at = trim( $asked_at );
        $today    = $today ?? current_time( 'Y-m-d' );

        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $asked_at, $m ) ) {
            return self::error( __( 'The date asked must be YYYY-MM-DD.', 'talenttrack' ) );
        }
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return self::error( __( 'The date asked is not a real date.', 'talenttrack' ) );
        }
        if ( strcmp( $asked_at, $today ) > 0 ) {
            return self::error( __( 'The date asked cannot be later than today.', 'talenttrack' ) );
        }
        return null;
    }

    public static function isValid( string $asked_at, ?string $today = null ): bool {
        return self::validate( $asked_at, $today ) === null;
    }

    private static function error( string $message ): \WP_Error {
        return new \WP_Error( self::ERROR_CODE, $message, [ 'status' => 400 ] );
    }
}
