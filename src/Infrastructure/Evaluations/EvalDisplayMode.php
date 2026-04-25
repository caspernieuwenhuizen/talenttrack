<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * EvalDisplayMode — resolve effective evaluation display preference.
 *
 * F5 of the post-#0019 feature sprint. The club picks a default
 * (`detailed` or `summary`); each coach can override their own
 * preference via user meta. This class is the single source of
 * truth for "what should I render".
 *
 * Modes:
 *   - `detailed` — show every subcategory rating (default).
 *   - `summary`  — show only the four main categories (Technical,
 *                  Tactical, Physical, Mental).
 */
class EvalDisplayMode {

    public const DETAILED = 'detailed';
    public const SUMMARY  = 'summary';

    private const CLUB_OPTION = 'eval_display_mode';
    private const USER_META   = 'tt_eval_display_mode';

    /**
     * Effective mode for a user. Falls back to the club default if
     * the user has no override (or a stale unsupported value).
     */
    public static function forUser( int $user_id ): string {
        if ( $user_id > 0 ) {
            $override = (string) get_user_meta( $user_id, self::USER_META, true );
            if ( in_array( $override, [ self::DETAILED, self::SUMMARY ], true ) ) {
                return $override;
            }
        }
        return self::clubDefault();
    }

    /** Club-wide default. */
    public static function clubDefault(): string {
        $value = (string) QueryHelpers::get_config( self::CLUB_OPTION, self::DETAILED );
        return in_array( $value, [ self::DETAILED, self::SUMMARY ], true ) ? $value : self::DETAILED;
    }

    /** Update the per-user override. Empty string clears the override. */
    public static function setUserOverride( int $user_id, string $mode ): void {
        if ( $user_id <= 0 ) return;
        if ( $mode === '' ) {
            delete_user_meta( $user_id, self::USER_META );
            return;
        }
        if ( ! in_array( $mode, [ self::DETAILED, self::SUMMARY ], true ) ) return;
        update_user_meta( $user_id, self::USER_META, $mode );
    }

    /**
     * Convenience: should we render subcategories at all? `true` when
     * mode is `detailed`, `false` when `summary`.
     */
    public static function showSubcategories( int $user_id ): bool {
        return self::forUser( $user_id ) === self::DETAILED;
    }
}
