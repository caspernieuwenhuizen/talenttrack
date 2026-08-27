<?php
namespace TT\Infrastructure\Recipients;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamHeadCoachLookup — who is the head coach of this team (#2719).
 *
 * Both notification engines need this answer and, until this class, both
 * computed it with their own copy of the same four-table join:
 * `Workflow\Resolvers\TeamHeadCoachResolver` for task assignees, and
 * `headCoachesByTeam()` on two Alerts base classes for alert recipients.
 * Three copies of one query.
 *
 * That is a correctness risk rather than untidiness. A wrong answer here
 * routes a named minor's data to somebody who should not see it, which is
 * a `CLAUDE.md` §1 privacy boundary failure. One implementation is one
 * place to get it right, and one place to fix when the team-membership
 * model next changes — which it has already done once. #1315 retired the
 * legacy `tt_teams.head_coach_id` column, and the Workflow resolver's
 * docblock still carries the scar of that column having been read as a
 * `tt_people.id` in one place and a `wp_users.ID` in every other.
 *
 * RESOLUTION
 *
 * The `head_coach` functional-role assignment in `tt_team_people`, joined
 * out to the person's WP account. `tt_team_people` has been the single
 * source of truth since #1315.
 *
 * Where a team somehow carries two head-coach assignments, the lowest
 * `tt_team_people.id` wins. Both previous implementations already did
 * this — the resolver through `ORDER BY tp.id ASC LIMIT 1`, the alerts
 * through `MIN(tp2.id)` — and they had to agree, because an alert
 * occurrence that flips between recipients from sweep to sweep
 * duplicates itself under two dedupe keys.
 *
 * BATCHING
 *
 * `forTeams()` is the real implementation and answers any number of teams
 * in one query; `forTeam()` delegates to it. That direction matters: the
 * alerts sweep runs across every team in the academy, and a per-team
 * query there would turn one sweep into hundreds. The single-team caller
 * pays nothing for the batching, but the batching caller cannot be
 * rebuilt out of the single-team one without regressing.
 */
final class TeamHeadCoachLookup {

    /**
     * WP user id of each team's head coach, keyed by team id, in one query.
     *
     * Teams with no head coach, or whose head coach has no WP account, are
     * absent from the result rather than present with a zero — callers
     * distinguish "nobody to tell" from "user 0".
     *
     * @param list<int> $team_ids
     * @return array<int,int> team_id => wp_user_id
     */
    public static function forTeams( array $team_ids ): array {
        global $wpdb;

        $team_ids = array_values( array_unique( array_filter( array_map( 'intval', $team_ids ) ) ) );
        if ( empty( $team_ids ) ) return [];

        $p    = $wpdb->prefix;
        $list = implode( ',', $team_ids );

        $rows = $wpdb->get_results(
            "SELECT tp.team_id, pe.wp_user_id
               FROM {$p}tt_team_people tp
         INNER JOIN {$p}tt_functional_roles fr ON tp.functional_role_id = fr.id
         INNER JOIN {$p}tt_people pe ON tp.person_id = pe.id
              WHERE tp.team_id IN ({$list})
                AND fr.role_key = 'head_coach'
                AND pe.wp_user_id > 0
                AND tp.id IN (
                    SELECT MIN(tp2.id)
                      FROM {$p}tt_team_people tp2
                INNER JOIN {$p}tt_functional_roles fr2 ON tp2.functional_role_id = fr2.id
                     WHERE tp2.team_id IN ({$list})
                       AND fr2.role_key = 'head_coach'
                  GROUP BY tp2.team_id
                )"
        );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row->team_id ] = (int) $row->wp_user_id;
        }

        return $out;
    }

    /**
     * WP user id of one team's head coach, or null when none resolves.
     *
     * @return int|null
     */
    public static function forTeam( int $team_id ): ?int {
        if ( $team_id <= 0 ) return null;

        $found = self::forTeams( [ $team_id ] );

        return $found[ $team_id ] ?? null;
    }
}
