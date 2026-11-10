<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\PlayerPosition;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * PdpFormChoice (#4978, epic #4975) — which conversation form a conversation
 * uses.
 *
 * A form is pinned on the conversation (`form_key`, `form_version`) at its
 * first write. Until then the form follows the player's primary position: a
 * goalkeeper gets the academy's goalkeeper form, everyone else, a player
 * without a position included, the outfield form. The position is read
 * through `PlayerPosition`, the column's one reader, so the choice matches
 * every other surface.
 *
 * The trainer may switch until the player's first save (owner decision 6);
 * that rule is the form route's, and `switchTarget()` says what the switch
 * would choose.
 */
final class PdpFormChoice {

    /** The position group of a player: goalkeeper or outfield. */
    public static function groupFor( int $player_id ): string {
        $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
        return $player !== null && PlayerPosition::isGoalkeeper( $player )
            ? PdpFormRegistry::GROUP_GOALKEEPER
            : PdpFormRegistry::GROUP_OUTFIELD;
    }

    /**
     * The form the conversation uses: the pinned one, or, before the first
     * write, the academy's form for the player's position group at its
     * latest version. Null when there is none (forms switched off, or the
     * pinned form no longer shipped).
     *
     * @return array{key:string, version:int, pinned:bool}|null
     */
    public static function formFor( object $conversation, int $player_id ): ?array {
        $row    = (array) $conversation;
        $pinned = (string) ( $row['form_key'] ?? '' );
        if ( $pinned !== '' ) {
            $version = (int) ( $row['form_version'] ?? 0 );
            return PdpFormRegistry::definition( $pinned, $version ) !== null
                ? [ 'key' => $pinned, 'version' => $version > 0 ? $version : PdpFormRegistry::latestVersion( $pinned ), 'pinned' => true ]
                : null;
        }

        $key = (string) ( PdpFormRegistry::formsForClub()[ self::groupFor( $player_id ) ] ?? '' );
        if ( $key === '' || PdpFormRegistry::definition( $key ) === null ) return null;
        return [ 'key' => $key, 'version' => PdpFormRegistry::latestVersion( $key ), 'pinned' => false ];
    }

    /**
     * The academy's form of the other position group, which the trainer's
     * switch moves to, or null when the academy has none.
     */
    public static function switchTarget( string $form_key ): ?string {
        $definition = PdpFormRegistry::definition( $form_key );
        if ( $definition === null ) return null;
        $other = (string) $definition['position_group'] === PdpFormRegistry::GROUP_GOALKEEPER
            ? PdpFormRegistry::GROUP_OUTFIELD
            : PdpFormRegistry::GROUP_GOALKEEPER;
        $key = (string) ( PdpFormRegistry::formsForClub()[ $other ] ?? '' );
        return $key !== '' ? $key : null;
    }
}
