<?php
namespace TT\Modules\Tournaments;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;

/**
 * PlayerTournamentAccess (#3561, epic #3558) — may this account read one
 * player's tournament record?
 *
 * The `player_tournaments` matrix entity exists precisely because the
 * question is not the same as "may you open the rotation planner" (#3560).
 * A parent may read their own child's minutes and must never see another
 * squad's rotation board; a coach reads it for their own squads' players
 * and nobody else's. Seeded by migration 0283 at four scopes:
 *
 *   - `global` — head of development, academy admin;
 *   - `team`   — head coach, assistant coach, on their own squads;
 *   - `player` — parent, for a linked child;
 *   - `self`   — the player, for their own record.
 *
 * One class rather than a predicate on the route, because #3562 renders the
 * same decision on the player file and a tab hidden from the strip is not a
 * tab that is protected: the panel asks this again. Two copies of a
 * permission rule is how one of them ends up wrong.
 *
 * It does **not** ask `parentCanViewSection()`. That is the child's own
 * choice about what their family sees, and it is answered in the handler so
 * a parent whose child closed the section gets `section_private` rather than
 * a bare 403 they cannot interpret — the same split `players/{id}/goals`
 * uses.
 */
final class PlayerTournamentAccess {

    public const ENTITY = 'player_tournaments';

    public static function canRead( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        if ( MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) ) {
            return true;
        }

        // The player themselves. `self` scope targets the *account*, so the
        // record has to be matched separately — the matrix row says "you may
        // read your own", and this says which player that is.
        if ( self::isOwnRecord( $user_id, $player_id )
            && MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_SELF, $user_id )
        ) {
            return true;
        }

        // A linked guardian (and, where an academy grants it, a scout link).
        if ( MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $player_id ) ) {
            return true;
        }

        $team_id = self::teamOf( $player_id );
        if ( $team_id > 0
            && MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $team_id )
        ) {
            return true;
        }

        return false;
    }

    /** Is this account the player whose record is being read? */
    public static function isOwnRecord( int $user_id, int $player_id ): bool {
        global $wpdb;

        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id,
            (int) CurrentClub::id()
        ) );

        return $owner > 0 && $owner === $user_id;
    }

    /** Is this account a linked guardian of the player? */
    public static function isGuardianOf( int $user_id, int $player_id ): bool {
        return ParentChildResolver::isParentOf( $user_id, $player_id );
    }

    private static function teamOf( int $player_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id,
            (int) CurrentClub::id()
        ) );
    }
}
