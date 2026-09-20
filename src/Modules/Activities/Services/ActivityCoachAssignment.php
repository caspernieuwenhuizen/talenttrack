<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivityCoachAssignment — who runs this activity (#3745).
 *
 * `tt_activities.coach_id` used to be stamped with `get_current_user_id()`
 * on every write path, so it meant "whoever typed the schedule" while the
 * alerts engine read it as "whoever is responsible for the register". An
 * administrator planning a season became the coach of every activity in it,
 * collected every register reminder, and `NoCoachAssignedAlert` could never
 * fire because the column was never empty.
 *
 * The answer is derived by default and overridable:
 *
 *  - **Derived.** With no coach supplied, the team's head coach — from the
 *    `head_coach` functional-role assignment — is the coach of the activity.
 *  - **Only when there is exactly one.** A team with no head coach, or with
 *    two, leaves the column empty rather than guessing. Guessing between two
 *    heads reintroduces the same class of wrong recipient, and an empty
 *    column is precisely what `NoCoachAssignedAlert` exists to surface.
 *  - **Overridable.** A submitted `coach_id` wins, validated against the
 *    actor's own team scope. The scope check is the guard, not the role
 *    name: an assistant coach may name a colleague on a team they work with,
 *    and nobody may name staff from a team they cannot see.
 *
 * The creator keeps their own column — `created_by`, stamped by
 * `ActivitiesRepository::create()` — so the two facts stop sharing a field.
 */
final class ActivityCoachAssignment {

    /**
     * The coach a new activity for this team gets when nobody picks one.
     *
     * Null when the team has no head coach, more than one, or a single head
     * coach with no WordPress account to address anything to.
     */
    public static function derivedForTeam( int $team_id ): ?int {
        if ( $team_id <= 0 ) return null;

        global $wpdb;
        $p = $wpdb->prefix;

        $found = $wpdb->get_col( $wpdb->prepare(
            "SELECT pe.wp_user_id
               FROM {$p}tt_team_people tp
         INNER JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id AND fr.club_id = tp.club_id
         INNER JOIN {$p}tt_people pe ON pe.id = tp.person_id AND pe.club_id = tp.club_id
              WHERE tp.team_id = %d AND tp.club_id = %d AND fr.role_key = 'head_coach'",
            $team_id,
            CurrentClub::id()
        ) );

        // Exactly one, or nobody. Two head coaches is not a tie to break
        // here: whichever one lost would collect somebody else's reminders.
        if ( ! is_array( $found ) || count( $found ) !== 1 ) return null;

        $user_id = (int) $found[0];

        return $user_id > 0 ? $user_id : null;
    }

    /**
     * The staff of one team who can be named as its activity coach, as
     * `wp_user_id => display name`.
     *
     * Deliberately every functional role rather than the coaching ones: a
     * team manager running a mid-week session is the person who should get
     * the register reminder for it, and a picker that cannot express that
     * sends the coach back to leaving it wrong.
     *
     * @return array<int,string>
     */
    public static function optionsForTeam( int $team_id ): array {
        if ( $team_id <= 0 ) return [];

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT pe.wp_user_id, pe.first_name, pe.last_name
               FROM {$p}tt_team_people tp
         INNER JOIN {$p}tt_people pe ON pe.id = tp.person_id AND pe.club_id = tp.club_id
              WHERE tp.team_id = %d AND tp.club_id = %d AND pe.wp_user_id > 0
           ORDER BY pe.last_name ASC, pe.first_name ASC",
            $team_id,
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
            if ( $name === '' ) {
                $user = get_userdata( (int) $row->wp_user_id );
                $name = $user ? (string) $user->display_name : '';
            }
            if ( $name === '' ) continue;
            $out[ (int) $row->wp_user_id ] = $name;
        }

        return $out;
    }

    /**
     * May this actor name this user as the coach of an activity?
     *
     * True when the candidate is staff on any team inside the actor's own
     * activities scope. An actor with a global activities read may name any
     * staff member in the club.
     */
    public static function mayAssign( int $coach_id, int $actor_id ): bool {
        if ( $coach_id <= 0 ) return false;

        $team_ids = self::teamsInScope( $actor_id );
        if ( $team_ids !== null && empty( $team_ids ) ) return false;

        global $wpdb;
        $p = $wpdb->prefix;

        // The only interpolation is an integer list built here from
        // `intval`; everything the caller supplied is a placeholder.
        $where = '';
        if ( $team_ids !== null ) {
            $where = ' AND tp.team_id IN (' . implode( ',', array_map( 'intval', $team_ids ) ) . ')';
        }

        $found = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$p}tt_team_people tp
         INNER JOIN {$p}tt_people pe ON pe.id = tp.person_id AND pe.club_id = tp.club_id
              WHERE pe.wp_user_id = %d AND tp.club_id = %d" . $where,
            $coach_id,
            CurrentClub::id()
        ) );

        return $found > 0;
    }

    /**
     * Team ids the actor may write activities for, or null for "all of them".
     *
     * @return list<int>|null
     */
    private static function teamsInScope( int $actor_id ): ?array {
        if ( $actor_id <= 0 ) return [];
        // The same rung the activities list uses to decide global-vs-coach
        // scope, so who you may name as a coach cannot disagree with whose
        // activities you can see.
        if ( QueryHelpers::user_has_global_entity_read( $actor_id, 'activities' ) ) return null;

        return array_values( array_map(
            'intval',
            array_column( QueryHelpers::get_teams_for_coach( $actor_id ), 'id' )
        ) );
    }
}
