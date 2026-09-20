<?php
namespace TT\Infrastructure\Recipients;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamStaffLookup — who works with this team (#3811).
 *
 * The sibling of `TeamHeadCoachLookup`, and it exists because that class
 * answers a narrower question than most callers actually have. Every
 * staff-directed send in the product resolved its recipients one of three
 * ways — club administrators, the subject of the record, or the head coach
 * — and none of them reached the team manager, the assistant coach or the
 * physio. A team manager could be opted in to nineteen message types and
 * receive none of them, because nothing in the codebase could name them.
 *
 * This is that missing answer, in one place, so the next message type does
 * not invent a fourth way of guessing.
 *
 * RESOLUTION
 *
 * Assignments in `tt_team_people`, joined out to each person's WP account.
 * A person with no WP account is absent rather than present with a zero —
 * callers distinguish "nobody to tell" from "user 0" — and so is an
 * assignment whose `end_date` has passed, because a coach who left in
 * August should not be reading a register reminder in November.
 *
 * ROLE FILTERING
 *
 * `forTeams()` with no role filter returns everyone assigned to the team.
 * That is the literal answer to "who is the staff of this team" and is what
 * a roster surface wants.
 *
 * Notifications want {@see self::RUNS_THE_TEAM} instead. A kit manager and a
 * physio are team staff in every sense, but a nudge that an attendance
 * register is still open is not addressed to them, and a notification that
 * reaches people who cannot act on it trains everyone to ignore the channel.
 * The distinction is deliberate and belongs here rather than being re-derived
 * at each call site.
 *
 * BATCHING
 *
 * `forTeams()` is the real implementation and answers any number of teams in
 * one query, for the reason its sibling gives: the alerts sweep runs across
 * every team in the academy, so a per-team query turns one sweep into
 * hundreds. `forTeam()` delegates.
 */
final class TeamStaffLookup {

    /**
     * The functional roles that run a team's calendar and register.
     *
     * These are the people a schedule change or an unclosed register is
     * addressed to: the head coach who set it, the assistant who takes it
     * when they cannot, and the team manager whose job is precisely to know
     * that the fixture moved. `physio`, `kit_manager` and `other` are team
     * staff and are excluded here on purpose — see the class docblock.
     *
     * @var list<string>
     */
    public const RUNS_THE_TEAM = [ 'head_coach', 'assistant_coach', 'manager' ];

    /**
     * WP user ids of each team's staff, keyed by team id, in one query.
     *
     * Teams with no resolvable staff are absent from the result rather than
     * present with an empty list, matching `TeamHeadCoachLookup::forTeams()`.
     *
     * @param list<int>    $team_ids
     * @param list<string> $role_keys Functional-role keys to narrow to;
     *                                empty means every assigned role.
     * @return array<int, list<int>> team_id => wp_user_id[]
     */
    public static function forTeams( array $team_ids, array $role_keys = [] ): array {
        global $wpdb;

        $team_ids = array_values( array_unique( array_filter( array_map( 'intval', $team_ids ) ) ) );
        if ( empty( $team_ids ) ) return [];

        $p     = $wpdb->prefix;
        $list  = implode( ',', $team_ids );
        $today = current_time( 'Y-m-d' );

        $where  = [ "tp.team_id IN ({$list})", 'pe.wp_user_id > 0' ];
        $params = [];

        // An assignment that has ended is not an assignment. `start_date` is
        // honoured the same way: a coach who starts in January is not on the
        // list in November.
        $where[]  = '( tp.start_date IS NULL OR tp.start_date <= %s )';
        $params[] = $today;
        $where[]  = '( tp.end_date IS NULL OR tp.end_date >= %s )';
        $params[] = $today;

        $role_keys = array_values( array_unique( array_filter( array_map( 'strval', $role_keys ) ) ) );
        if ( ! empty( $role_keys ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $role_keys ), '%s' ) );
            $where[]      = "fr.role_key IN ({$placeholders})";
            $params       = array_merge( $params, $role_keys );
        }

        $sql = "SELECT tp.team_id, pe.wp_user_id
                  FROM {$p}tt_team_people tp
            INNER JOIN {$p}tt_people pe ON tp.person_id = pe.id
             LEFT JOIN {$p}tt_functional_roles fr ON tp.functional_role_id = fr.id
                 WHERE " . implode( ' AND ', $where );

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $team_id = (int) $row->team_id;
            $user_id = (int) $row->wp_user_id;
            if ( $team_id <= 0 || $user_id <= 0 ) continue;
            // One person can hold two jobs on the same team; they are still
            // one recipient.
            $out[ $team_id ][ $user_id ] = true;
        }

        return array_map(
            static fn ( array $ids ): array => array_map( 'intval', array_keys( $ids ) ),
            $out
        );
    }

    /**
     * WP user ids of one team's staff, or an empty list when none resolves.
     *
     * @param list<string> $role_keys
     * @return list<int>
     */
    public static function forTeam( int $team_id, array $role_keys = [] ): array {
        if ( $team_id <= 0 ) return [];

        $found = self::forTeams( [ $team_id ], $role_keys );

        return $found[ $team_id ] ?? [];
    }
}
