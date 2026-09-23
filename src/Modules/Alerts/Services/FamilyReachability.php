<?php
namespace TT\Modules\Alerts\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\AllTeamsScope;

/**
 * FamilyReachability (#4014) — how many of our families can we reach?
 *
 * Which player question does this answer? *Who at home hears about this
 * player?*, asked of a whole academy at once. A board member had to call
 * `teams/{id}/dossier-completeness` four times, read past every missing
 * player's name on a phone, add the columns up by hand and then ask an
 * administrator to confirm it, to arrive at "3 of 82 families reachable".
 *
 * ## Reachable is derived, and it never replaces a check
 *
 * A player is **reachable** when the club has any way to contact the family:
 * a guardian e-mail address, a guardian phone number, **or** a linked parent
 * account. That is a union, and it exists only as a count.
 *
 * `DossierCompletenessService` keeps reporting the guardian columns and the
 * parent-account link **separately**, and that is not an oversight this
 * tidies up. Its own note says why: an account is how a parent reads their
 * child's record, and the columns are how the club phones somebody on a
 * Saturday morning. A file with one and not the other is not complete. So
 * this is an additional signal layered on top of the six checks — the answer
 * to a different question ("is there any route to this family at all"), not
 * a simpler version of theirs.
 *
 * ## No family is ever named club-wide
 *
 * The club-wide answer is counts and team names. Nothing else. A club-wide
 * report naming families would be a bulk export of children's contact
 * details behind a reporting capability, which is exactly why
 * `DossierCompletenessRestController` has no route without a team in its
 * path. The drill-down to named players stays that per-team route, behind
 * the gate it already has.
 *
 * ## Population
 *
 * Every player on the books: not archived, not trashed. Deliberately the
 * same roster `DossierCompletenessService` reports on, so the club-wide
 * figure is the sum of the per-team ones and the board's hand count
 * reconciles with the screen.
 *
 * That is a wider population than `people.no_guardian_contact` raises
 * occurrences for — that alert speaks for players a message would actually
 * be sent about, so it waits for an active status or an open trial. This is
 * a census, and a census that quietly left players out would be the wrong
 * number to put in a minute.
 */
final class FamilyReachability {

    /**
     * The census, scoped to what this user may see.
     *
     * Global `people` read — a Head of Development, an academy admin, a
     * board observer — answers for the whole academy. Anyone else answers
     * for the teams their role scopes grant, and the totals cover only
     * those teams. The scope is resolved here and passed as the query's
     * IN-list, so there is no parameter a caller could widen.
     *
     * @return array{total:int, reachable:int, unreachable:int, scope:string, teams:list<array{team_id:int,team_name:string,total:int,reachable:int,unreachable:int}>}
     */
    public static function forUser( int $userId ): array {
        if ( $userId <= 0 ) return self::empty( 'none' );

        if ( AllTeamsScope::canSeeAllTeams( $userId, 'people' ) ) {
            return self::summarise( self::rows( null ), 'club' );
        }

        $team_ids = [];
        foreach ( QueryHelpers::get_teams_for_coach( $userId ) as $team ) {
            $id = (int) ( ( (array) $team )['id'] ?? 0 );
            if ( $id > 0 ) $team_ids[] = $id;
        }
        if ( $team_ids === [] ) return self::empty( 'none' );

        return self::summarise( self::rows( $team_ids ), 'teams' );
    }

    /**
     * The census for one squad, with no per-team breakdown — the shape the
     * per-team dossier report renders beside its six checks so an
     * administrator reading "0 of 21 guardian e-mail addresses" next to
     * "3 of 21 parent accounts" is told what those two facts add up to.
     *
     * Scoping is the caller's job here: this is reached through routes that
     * have already asked whether the caller may read the team.
     *
     * @return array{total:int, reachable:int, unreachable:int}
     */
    public static function forTeam( int $teamId ): array {
        if ( $teamId <= 0 ) return [ 'total' => 0, 'reachable' => 0, 'unreachable' => 0 ];

        $summary = self::summarise( self::rows( [ $teamId ] ), 'teams' );
        return [
            'total'       => $summary['total'],
            'reachable'   => $summary['reachable'],
            'unreachable' => $summary['unreachable'],
        ];
    }

    /**
     * @param list<array{team_id:int,team_name:string,total:int,reachable:int,unreachable:int}> $teams
     * @return array{total:int, reachable:int, unreachable:int, scope:string, teams:list<array{team_id:int,team_name:string,total:int,reachable:int,unreachable:int}>}
     */
    private static function summarise( array $teams, string $scope ): array {
        $total     = 0;
        $reachable = 0;
        foreach ( $teams as $team ) {
            $total     += $team['total'];
            $reachable += $team['reachable'];
        }

        return [
            'total'       => $total,
            'reachable'   => $reachable,
            'unreachable' => $total - $reachable,
            'scope'       => $scope,
            'teams'       => $teams,
        ];
    }

    /**
     * @return array{total:int, reachable:int, unreachable:int, scope:string, teams:list<array{team_id:int,team_name:string,total:int,reachable:int,unreachable:int}>}
     */
    private static function empty( string $scope ): array {
        return [ 'total' => 0, 'reachable' => 0, 'unreachable' => 0, 'scope' => $scope, 'teams' => [] ];
    }

    /**
     * One grouped read: a row per team with the squad size and how many of
     * those families are reachable by any route.
     *
     * Players with no team are grouped under `team_id` 0 rather than
     * dropped. A child nobody can reach and nobody has placed is the worst
     * case of the two, not an edge case to leave out of the total.
     *
     * @param list<int>|null $teamIds null = every team (club-wide)
     * @return list<array{team_id:int,team_name:string,total:int,reachable:int,unreachable:int}>
     */
    private static function rows( ?array $teamIds ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $narrow = '';
        if ( is_array( $teamIds ) ) {
            $ids = [];
            foreach ( $teamIds as $id ) {
                $id = (int) $id;
                if ( $id > 0 ) $ids[] = $id;
            }
            if ( $ids === [] ) return [];
            $narrow = ' AND p.team_id IN (' . implode( ',', $ids ) . ')';
        }

        // The union that makes a family reachable. `tt_player_parents` has a
        // composite key and no surrogate id, so the EXISTS selects a literal
        // — the same shape `NoGuardianContactAlert` uses for the inverse
        // condition, deliberately, so the two cannot drift apart.
        $sql = "SELECT p.team_id AS team_id,
                       COALESCE( t.name, '' ) AS team_name,
                       COUNT(*) AS total,
                       SUM(
                           CASE WHEN ( p.guardian_email IS NOT NULL AND p.guardian_email <> '' )
                                  OR ( p.guardian_phone IS NOT NULL AND p.guardian_phone <> '' )
                                  OR EXISTS (
                                        SELECT 1 FROM {$p}tt_player_parents pp
                                         WHERE pp.player_id = p.id AND pp.club_id = p.club_id
                                     )
                                THEN 1 ELSE 0 END
                       ) AS reachable
                  FROM {$p}tt_players p
             LEFT JOIN {$p}tt_teams t
                    ON t.id = p.team_id AND t.club_id = p.club_id
                 WHERE " . QueryHelpers::clubScopeWhere( 'p' ) . "
                   AND p.archived_at IS NULL
                   AND p.trashed_at IS NULL"
            . $narrow . "
              GROUP BY p.team_id, t.name
              ORDER BY t.name ASC, p.team_id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $total     = (int) ( $row['total'] ?? 0 );
            $reachable = (int) ( $row['reachable'] ?? 0 );
            $team_id   = (int) ( $row['team_id'] ?? 0 );
            $name      = trim( (string) ( $row['team_name'] ?? '' ) );

            $out[] = [
                'team_id'     => $team_id,
                'team_name'   => $name !== '' ? $name : __( 'Without a team', 'talenttrack' ),
                'total'       => $total,
                'reachable'   => $reachable,
                'unreachable' => $total - $reachable,
            ];
        }
        return $out;
    }
}
