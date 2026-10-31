<?php
namespace TT\Modules\Threads\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Recipients\TeamStaffLookup;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Threads\Domain\PlayerAnchoredThread;
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
final class PlayerThreadAdapter implements ThreadTypeAdapter, PlayerAnchoredThread {

    public function playerIdFor( int $thread_id ): int {
        return $this->findEntity( $thread_id ) ? $thread_id : 0;
    }

    public function findEntity( int $thread_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $thread_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * The functional roles on the player's team who are told about a new
     * note: the people who act on what a colleague writes about one of
     * their players. A kit manager or an "other" assignment is team staff
     * too, and is left out for the reason `TeamStaffLookup` gives.
     *
     * @var list<string>
     */
    public const NOTIFIED_STAFF_ROLES = [ 'head_coach', 'assistant_coach', 'physio', 'manager' ];

    /**
     * Who is notified of a new note on a player thread (#4426).
     *
     * The current head coach, assistant coach, physio and team manager of
     * the player's own team, each only when `canRead()` admits them. Staff
     * of another team are never candidates, whatever they can read.
     *
     * The player and the player's guardians are never returned, not even
     * when they also hold a staff job on the team: this thread is the staff
     * log about the child, not a channel to the family.
     *
     * `NotificationSubscriber::onPosted()` then drops the author, keeps
     * only those `ThreadAccess::canSeePrivate()` admits for a staff-only
     * note, and sends through Comms, which honours each recipient's own
     * notification preferences.
     *
     * @return list<int>
     */
    public function participantUserIds( int $thread_id ): array {
        $player = $this->findEntity( $thread_id );
        if ( ! $player ) return [];

        $team_id = (int) ( $player->team_id ?? 0 );
        if ( $team_id <= 0 ) return [];

        $family = $this->familyUserIds( $player, $thread_id );

        $out = [];
        foreach ( TeamStaffLookup::forTeam( $team_id, self::NOTIFIED_STAFF_ROLES ) as $uid ) {
            if ( $uid <= 0 || isset( $family[ $uid ] ) ) continue;
            if ( ! $this->canRead( $uid, $thread_id ) ) continue;
            $out[] = $uid;
        }
        return $out;
    }

    /**
     * The player's own account and every guardian ever linked to them,
     * keyed by user id. Every link counts, not only the ones that still
     * give access: this list only ever removes recipients.
     *
     * @return array<int, true>
     */
    private function familyUserIds( object $player, int $player_id ): array {
        $ids = [
            (int) ( $player->wp_user_id ?? 0 ),
            (int) ( $player->parent_user_id ?? 0 ),
        ];
        foreach ( ( new PlayerParentsRepository() )->parentsForPlayer( $player_id ) as $parent_uid ) {
            $ids[] = (int) $parent_uid;
        }

        $out = [];
        foreach ( $ids as $id ) {
            if ( $id > 0 ) $out[ $id ] = true;
        }
        return $out;
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
