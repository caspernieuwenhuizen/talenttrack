<?php
namespace TT\Infrastructure\Goals;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Query\GoalLifecycle;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * The per-player check a single goal follows, on every surface that opens
 * one by id: the goal detail and edit views, and the `goals/{id}` routes.
 *
 * The route and view capabilities answer "may read / change goals"; these
 * answer "may read / change THIS player's goals". A refusal is meant to be
 * answered exactly as a missing goal, so an id cannot be probed for
 * existence.
 */
final class GoalAccess {

    /**
     * The player a goal belongs to, in the current club, whatever its
     * lifecycle state. `null` when there is no such goal; `0` when the goal
     * exists but carries no player.
     */
    public static function playerIdOf( int $goal_id ): ?int {
        if ( $goal_id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT player_id FROM {$wpdb->prefix}tt_goals WHERE id = %d AND club_id = %d",
            $goal_id,
            CurrentClub::id()
        ) );
        if ( ! $row ) return null;
        return (int) ( $row->player_id ?? 0 );
    }

    /**
     * Read check: the same one an evaluation detail follows. A goal with no
     * player carries no per-player check.
     */
    public static function mayRead( int $user_id, int $player_id ): bool {
        if ( $player_id <= 0 ) return true;
        return AuthorizationService::canViewPlayer( $user_id, $player_id )
            && AuthorizationService::canReadPlayerSection( $user_id, $player_id, 'goals' )
            && AuthorizationService::parentCanViewSection( $user_id, $player_id, 'goals' );
    }

    /**
     * Change check, on top of the goals capability the route already asks:
     * the rule `POST /goals` applies to the player it writes for (a coach of
     * the player's team), plus the academy-wide goals writer the goal
     * conversation already recognises (global goals read and
     * `tt_edit_goals`, e.g. academy admin and head of development).
     */
    public static function mayChange( int $user_id, int $player_id ): bool {
        if ( $player_id <= 0 ) return true;
        if ( ! self::mayRead( $user_id, $player_id ) ) return false;

        if ( QueryHelpers::user_has_global_entity_read( $user_id, 'goals' )
            && user_can( $user_id, 'tt_edit_goals' )
        ) {
            return true;
        }

        return QueryHelpers::coach_owns_player( $user_id, $player_id );
    }

    /**
     * #5112 — the goal-approval rule, on top of `mayChange()`. A goal a
     * player set for themselves waits in `pending_approval`; only the
     * player's head coach, or an administrator, moves it out of that status
     * (approving or rejecting it). Every route that writes a goal's status
     * asks this: `PATCH /goals/{id}/status`, `PUT /goals/{id}` and the
     * wp-admin goal form.
     *
     * Statuses are compared normalised (`GoalLifecycle::normalise()`), so a
     * goal stored as `Pending Approval` follows the same rule.
     */
    public static function mayChangeStatus( int $user_id, int $player_id, string $from, string $to ): bool {
        $waiting = GoalLifecycle::normalise( GoalStatus::PENDING_APPROVAL );
        if ( GoalLifecycle::normalise( $from ) !== $waiting ) return true;
        if ( GoalLifecycle::normalise( $to ) === $waiting ) return true;
        if ( user_can( $user_id, 'manage_options' ) ) return true;
        return $player_id > 0 && AuthorizationService::isHeadCoachOfPlayer( $user_id, $player_id );
    }

    /**
     * The player and the stored status of a goal in the current club, or
     * null when there is no such goal.
     *
     * @return array{player_id:int, status:string}|null
     */
    public static function playerAndStatusOf( int $goal_id ): ?array {
        if ( $goal_id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT player_id, status FROM {$wpdb->prefix}tt_goals WHERE id = %d AND club_id = %d",
            $goal_id,
            CurrentClub::id()
        ) );
        if ( ! $row instanceof \stdClass ) return null;
        return [ 'player_id' => (int) ( $row->player_id ?? 0 ), 'status' => (string) ( $row->status ?? '' ) ];
    }
}
