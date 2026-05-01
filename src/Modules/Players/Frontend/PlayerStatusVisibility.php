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
 */
final class PlayerStatusVisibility {

    public const TOGGLE_KEY = 'player_status_visible_to_player_parent';

    public static function dotVisibleTo( int $user_id ): bool {
        if ( $user_id <= 0 ) return true; // unknown user — let the cap layer decide later

        $personas = PersonaResolver::personasFor( $user_id );
        $is_family = in_array( 'player', (array) $personas, true )
                  || in_array( 'parent', (array) $personas, true );

        if ( ! $is_family ) return true; // staff always see the dot

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
