<?php
namespace TT\Modules\Threads\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Threads\Domain\ThreadTypeAdapter;

/**
 * GoalThreadAdapter — translates `thread_id` to a tt_goals row and
 * resolves the goal-specific permission + participant graph (#0028).
 *
 * Participants:
 *   - The goal's player (if linked to a WP user via tt_players.wp_user_id).
 *   - The coach who owns the goal (goals.created_by, falling back to
 *     the head coach of the player's team via QueryHelpers::coach_owns_player).
 *   - Linked parent users — resolved from the canonical tt_player_parents
 *     pivot (#1993), so thread membership and the dashboard child switcher
 *     agree on who is a parent (guardian_email is no longer a live link).
 *   - Plus anyone the authorization matrix grants `goals` read at
 *     global scope — admins, Head of Development (not auto-pinged on
 *     new messages).
 *
 * Reading and posting part ways on the academy-wide rung: a global
 * goals reader follows the conversation, but only writes in it when
 * they also hold `tt_edit_goals`. Participants (owning coach, goal
 * author, the player, linked parents) read and post as before.
 * private_to_coach messages are filtered at the repository level for
 * non-coach viewers.
 */
final class GoalThreadAdapter implements ThreadTypeAdapter {

    public function findEntity( int $thread_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_goals WHERE id = %d AND club_id = %d",
            $thread_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /** @return list<int> */
    public function participantUserIds( int $thread_id ): array {
        $goal = $this->findEntity( $thread_id );
        if ( ! $goal ) return [];

        $ids = [];
        $player = QueryHelpers::get_player( (int) $goal->player_id );
        if ( $player && (int) $player->wp_user_id > 0 ) {
            $ids[] = (int) $player->wp_user_id;
        }

        $author = (int) ( $goal->created_by ?? 0 );
        if ( $author > 0 ) $ids[] = $author;

        // #1993 — parent users from the canonical tt_player_parents pivot.
        foreach ( ( new \TT\Modules\Invitations\PlayerParentsRepository() )->parentsForPlayer( (int) $goal->player_id ) as $parent_uid ) {
            if ( $parent_uid > 0 ) $ids[] = (int) $parent_uid;
        }

        return array_values( array_unique( array_filter( $ids, static fn( $i ): bool => $i > 0 ) ) );
    }

    public function canRead( int $user_id, int $thread_id ): bool {
        if ( $user_id <= 0 ) return false;
        $goal = $this->findEntity( $thread_id );
        if ( ! $goal ) return false;

        // Academy-wide reader — admin, Head of Development, anyone the
        // matrix grants `goals` read at global scope. #3720: this rung
        // used to test `tt_view_settings`, a capability the
        // head_of_development persona is granted nowhere, so the HoD
        // was locked out of every goal conversation.
        if ( QueryHelpers::user_has_global_entity_read( $user_id, 'goals' ) ) return true;

        return $this->isParticipant( $user_id, $goal );
    }

    public function canPost( int $user_id, int $thread_id ): bool {
        if ( $user_id <= 0 ) return false;
        $goal = $this->findEntity( $thread_id );
        if ( ! $goal ) return false;

        // Participants write in their own conversation regardless of
        // the academy-wide grant.
        if ( $this->isParticipant( $user_id, $goal ) ) return true;

        // #3720 — an academy-wide goals *reader* follows the
        // conversation read-only; writing in it needs the change right.
        return QueryHelpers::user_has_global_entity_read( $user_id, 'goals' )
            && user_can( $user_id, 'tt_edit_goals' );
    }

    /**
     * The goal's own people: the coach owning the player, the goal's
     * author, the player themselves and their linked parents.
     */
    private function isParticipant( int $user_id, object $goal ): bool {
        // Coach owning the player.
        if ( QueryHelpers::coach_owns_player( $user_id, (int) $goal->player_id ) ) return true;

        // Goal author.
        if ( $user_id === (int) ( $goal->created_by ?? 0 ) ) return true;

        // Player whose goal it is.
        $player = QueryHelpers::get_player( (int) $goal->player_id );
        if ( $player && (int) $player->wp_user_id === $user_id ) return true;

        // #1993 — parent linked via the canonical tt_player_parents pivot.
        $parents = ( new \TT\Modules\Invitations\PlayerParentsRepository() )->parentsForPlayer( (int) $goal->player_id );
        return in_array( $user_id, $parents, true );
    }

    public function entityLabel( int $thread_id ): string {
        $goal = $this->findEntity( $thread_id );
        if ( ! $goal ) return '';
        $player = QueryHelpers::get_player( (int) $goal->player_id );
        $player_name = $player
            ? trim( (string) $player->first_name . ' ' . (string) $player->last_name )
            : '';
        $title = (string) ( $goal->title ?? __( 'Goal', 'talenttrack' ) );
        if ( $player_name === '' ) return $title;
        return sprintf(
            /* translators: 1: player full name, 2: goal title */
            __( "%1\$s's goal: %2\$s", 'talenttrack' ),
            $player_name,
            $title
        );
    }
}
