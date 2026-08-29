<?php
namespace TT\Modules\Vct\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;

/**
 * AgeProfileCoverage (#2601) — tells the two "no age profile" cases
 * apart.
 *
 * The training generator refuses to draft for an age group with no
 * profile, and it is right to: the profile is what supplies the age-safe
 * intensity ceiling, and that is not something to guess at for children.
 * But "we do not plan load at this age" and "nobody has set this age up
 * yet" are completely different answers, and the coach was being given
 * one message for both.
 *
 * - **Below the modelled range** — U7-U9 today. Structured load planning
 *   deliberately does not apply at that age; the session is the coach's
 *   to shape. Not a gap, an answer, and no amount of configuring will
 *   change it.
 * - **Above it, or a hole inside it** — U15+ today. A profile can exist
 *   and does not yet. Someone holding `tt_vct_admin_config` can add one.
 *
 * ## The boundary is derived, never listed
 *
 * The floor is the lowest age group that actually has a profile on this
 * club. Hardcoding "U7, U8, U9 are below the range" would mean the day
 * an academy adds a U9 profile, the copy keeps telling its coaches that
 * U9 has no load model — the code would contradict the database. Reading
 * the floor from the profiles makes the message follow the configuration
 * without anyone remembering to update it.
 *
 * Above the floor, every uncovered age is "not set up yet", including a
 * hole in the middle of the range: if an academy has U10 and U14 and
 * deleted U12, U12 is missing rather than unmodelled.
 */
final class AgeProfileCoverage {

    /**
     * Ordinal for an age-group key, for comparison only.
     *
     * `U13` → 13. `Senior` and anything else non-numeric → PHP_INT_MAX,
     * which places it above every modelled band: a senior squad is never
     * "too young for a load model", it is simply one nobody has
     * configured.
     */
    public static function ordinal( string $age_group ): int {
        if ( preg_match( '/^[UuOo]\s*(\d{1,2})$/', trim( $age_group ), $m ) === 1 ) {
            return (int) $m[1];
        }
        return PHP_INT_MAX;
    }

    /**
     * The lowest age group that carries a profile on this club, as an
     * ordinal. Null when no profile exists at all — in which case there
     * is no floor to be below and every age group reads as "not set up".
     */
    public static function modelledFloor( ?VctAgeProfilesRepository $repo = null ): ?int {
        $repo = $repo ?? new VctAgeProfilesRepository();

        $floor = null;
        foreach ( $repo->listAll() as $profile ) {
            $ordinal = self::ordinal( (string) ( $profile['age_group'] ?? '' ) );
            if ( $ordinal === PHP_INT_MAX ) continue;
            if ( $floor === null || $ordinal < $floor ) $floor = $ordinal;
        }

        return $floor;
    }

    /**
     * True when this age group sits below every profile the club has, so
     * the absence is the deliberate position rather than a gap.
     */
    public static function isBelowModelledRange( string $age_group, ?VctAgeProfilesRepository $repo = null ): bool {
        $floor = self::modelledFloor( $repo );
        if ( $floor === null ) return false;

        return self::ordinal( $age_group ) < $floor;
    }
}
