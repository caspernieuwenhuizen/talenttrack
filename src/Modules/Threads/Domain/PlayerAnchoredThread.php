<?php
namespace TT\Modules\Threads\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerAnchoredThread — a thread type whose record belongs to one player.
 *
 * Optional, next to `ThreadTypeAdapter`: a goal thread and a player-notes
 * thread each answer "whose thread is this", a blueprint thread does not.
 * The shared thread routes ask it when a guardian's access to that player
 * decides what they may do (#4129: a read-only guardian deletes nothing),
 * so the rule is asked of the player, never of the thread type.
 */
interface PlayerAnchoredThread {

    /** The `tt_players.id` the thread belongs to, or 0 when there is none. */
    public function playerIdFor( int $thread_id ): int;
}
