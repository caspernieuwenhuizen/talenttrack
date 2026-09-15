<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;

/**
 * PlayerStatusVisibility (#0071 child 4) — runtime gate on top of the
 * player_status matrix grants. The matrix continues to hold the
 * permission *intent* (every persona has a player_status read entry);
 * this helper expresses club *policy* on top of it.
 *
 * Default: family personas (player + parent) do NOT see the dot.
 * Staff always do regardless of toggle. The breakdown numerics are
 * staff-only by existing design, irrespective of this toggle.
 *
 * Three call sites consume `dotVisibleTo()`:
 *   - PlayerStatusRestController::playerStatus()   — wraps with 403 for family.
 *   - PlayerStatusRestController::teamStatuses()   — empties statuses for family.
 *   - PlayerStatusRenderer::dot|pill|panel()       — renders empty string.
 *
 * #3412 added `squadVisibleTo()` for surfaces that read a whole squad's
 * judgement rather than one child's colour. Same gate, narrowed; see the
 * method for why the narrowing is not a second rule.
 */
final class PlayerStatusVisibility {

    public const TOGGLE_KEY = 'player_status_visible_to_player_parent';

    /**
     * May this user read a **squad's** player-judgement data — every
     * player's potential band in one list, rather than one colour about
     * one child? (#3412)
     *
     * #3412 decided this inherits the dot's gate rather than inventing a
     * second rule, and asked for a finding if that gate admits somebody it
     * should not. It does: `dotVisibleTo()` opens to family personas when
     * an academy switches the toggle on, and that toggle is about a parent
     * seeing *their own child's* colour. It was never a decision to let a
     * parent read the academy's ranked judgement of every other child in
     * the squad.
     *
     * So this is the dot gate **plus** a hard family exclusion: the same
     * rule, narrowed for a surface that is more exposing, in the one class
     * that owns the question. A player or parent reaches nothing here, in
     * either toggle state.
     */
    public static function squadVisibleTo( int $user_id ): bool {
        if ( $user_id <= 0 ) return false; // an unknown caller is not staff
        if ( self::isFamily( $user_id ) ) return false;
        return self::dotVisibleTo( $user_id );
    }

    private static function isFamily( int $user_id ): bool {
        $personas = PersonaResolver::personasFor( $user_id );
        return in_array( 'player', (array) $personas, true )
            || in_array( 'parent', (array) $personas, true );
    }

    public static function dotVisibleTo( int $user_id ): bool {
        if ( $user_id <= 0 ) return true; // unknown user — let the cap layer decide later

        if ( ! self::isFamily( $user_id ) ) return true; // staff always see the dot

        $config = function_exists( 'tt_container' )
            ? tt_container()->get( 'config' )
            : null;
        if ( $config && method_exists( $config, 'getBool' ) ) {
            return (bool) $config->getBool( 'feature.' . self::TOGGLE_KEY, false );
        }

        // Fallback: read tt_config directly via QueryHelpers.
        if ( class_exists( '\TT\Infrastructure\Query\QueryHelpers' ) ) {
            $val = \TT\Infrastructure\Query\QueryHelpers::get_config( 'feature.' . self::TOGGLE_KEY, '0' );
            return $val === '1';
        }

        return false;
    }
}
