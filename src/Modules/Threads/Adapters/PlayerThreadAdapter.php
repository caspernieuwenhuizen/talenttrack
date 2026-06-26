<?php
namespace TT\Modules\Threads\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Threads\Domain\ThreadTypeAdapter;

/**
 * PlayerThreadAdapter (#0085) — second registered thread type after
 * `goal`. Anchors a thread on a player record so staff can leave a
 * running log of observations the rest of the leadership community
 * needs to see (small-academy use case from the May 2026 pilot).
 *
 * Staff-only by design. Players and parents see no notes about
 * themselves / their child via this adapter — by definition. If
 * parent-staff messaging becomes a feature later, it ships as its
 * own thread type with its own visibility rules.
 *
 * Read = `tt_view_player_notes` capability (matrix-bridged) plus a
 * scope check the matrix gate handles automatically (team-scoped
 * coaches see notes on their team's players; HoD/Admin globally;
 * scouts globally for cross-team observation).
 *
 * Post = `tt_edit_player_notes`. Same scope semantics.
 */
final class PlayerThreadAdapter implements ThreadTypeAdapter {

    public function findEntity( int $thread_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $thread_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Pinged on new note posts. Includes the note author + every staff
     * member with `tt_view_player_notes` capability whose scope row
     * grants them this player's team. Implementation: the broadcast
     * fan-out runs through the notification subscriber, which already
     * scope-filters via `canRead`. Returning the empty list here means
     * "no specific @-mentions; broadcast to everyone canRead returns
     * true for". Per-user @-mention parsing is deferred to a follow-up
     * (spec §5 mention autocomplete + workflow tasks).
     *
     * @return list<int>
     */
    public function participantUserIds( int $thread_id ): array {
        return [];
    }

    public function canRead( int $user_id, int $thread_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ! $this->findEntity( $thread_id ) ) return false;

        // Capability check first; matrix bridge resolves scope. The
        // matrix seed grants no `player_notes` read to the player /
        // parent personas, so this cap check alone denies them — no
        // role-name compare needed (a coach who is also a parent of
        // an academy child must keep their coach access).
        if ( ! user_can( $user_id, 'tt_view_player_notes' ) ) return false;

        // Coach scope: must own the player's team. Matches the
        // `r[team]` grant on the seed.
        if ( ! current_user_can( 'tt_view_settings' )
             && ! user_can( $user_id, 'tt_view_settings' )
             && ! QueryHelpers::user_has_global_entity_read( $user_id, 'player_notes' )
        ) {
            return QueryHelpers::coach_owns_player( $user_id, $thread_id );
        }

        return true;
    }

    public function canPost( int $user_id, int $thread_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ! $this->findEntity( $thread_id ) ) return false;
        // The matrix seed grants no `player_notes` change to the
        // player / parent personas, so this cap check alone denies
        // them. Same scope check as canRead below.
        if ( ! user_can( $user_id, 'tt_edit_player_notes' ) ) return false;

        if ( ! current_user_can( 'tt_view_settings' )
             && ! user_can( $user_id, 'tt_view_settings' )
             && ! QueryHelpers::user_has_global_entity_read( $user_id, 'player_notes' )
        ) {
            return QueryHelpers::coach_owns_player( $user_id, $thread_id );
        }

        return true;
    }

    public function entityLabel( int $thread_id ): string {
        $player = $this->findEntity( $thread_id );
        if ( ! $player ) return '';
        $name = QueryHelpers::player_display_name( $player );
        /* translators: %s: player display name */
        return sprintf( __( 'Notes — %s', 'talenttrack' ), $name );
    }
}
