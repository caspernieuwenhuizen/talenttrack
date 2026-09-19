<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerDates (#3590) — a player's date of birth and join date, on the way
 * in and on the way out.
 *
 * Both columns are nullable `DATE`. Writing an empty string to one, which is
 * what a form or API call that left the date out used to produce, stores the
 * MySQL zero date `0000-00-00` in non-strict mode. That value is truthy, so
 * it went back out over REST as a date, and every age or age-group
 * calculation got a bogus one. "Not recorded" is NULL, in and out.
 */
final class PlayerDates {

    private const ZERO_DATE = '0000-00-00';

    /**
     * The value to store: null for blank or the zero date, otherwise the
     * trimmed input, which the caller validates with `isValid()`.
     *
     * @param mixed $raw
     */
    public static function fromInput( $raw ): ?string {
        if ( $raw === null || ! is_scalar( $raw ) ) return null;
        $value = trim( sanitize_text_field( (string) $raw ) );
        if ( $value === '' || strpos( $value, self::ZERO_DATE ) === 0 ) return null;
        return $value;
    }

    /** A real `Y-m-d` calendar date. Null (not recorded) is valid. */
    public static function isValid( ?string $value ): bool {
        if ( $value === null ) return true;
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /**
     * The value to return: null for a column that holds nothing, or the zero
     * date an older write left behind.
     *
     * @param mixed $stored
     */
    public static function forOutput( $stored ): ?string {
        if ( $stored === null || ! is_scalar( $stored ) ) return null;
        $value = trim( (string) $stored );
        if ( $value === '' || strpos( $value, self::ZERO_DATE ) === 0 ) return null;
        return $value;
    }
}
