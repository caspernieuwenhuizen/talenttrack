<?php
namespace TT\Modules\PersonaDashboard\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UpcomingActivityRepository (#0092) — shared query for the next
 * upcoming activity scoped to a coach's teams.
 *
 * Consumed by `MarkAttendanceHeroWidget` (the new coach hero) and
 * intended to replace the duplicate inline query in
 * `TodayUpNextHeroWidget` on its next touch. Single source of truth
 * so the two heroes can't drift apart on club-scoping or ordering.
 */
final class UpcomingActivityRepository {

    /**
     * Soonest activity on or after today, ordered ASC, scoped to a
     * coach's teams when given, otherwise to the club. Returns null
     * when nothing is on the calendar.
     *
     * @param list<int> $team_ids
     */
    public static function nextForCoach( array $team_ids, int $club_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_activities';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }
        $today       = gmdate( 'Y-m-d' );
        $has_club    = self::hasClubColumn( $table );
        $club_clause = $has_club ? ' AND club_id = ' . (int) $club_id : '';

        // v3.110.73 — filter out activities the coach has already
        // processed. An activity in `completed` or `cancelled` state is
        // no longer "needs your attention next"; the hero should look
        // past it to the soonest unhandled session. Pilot symptom:
        // after marking attendance + ratings for tonight's training,
        // the hero still showed the same activity with the Mark
        // attendance CTA the next time the coach loaded the dashboard.
        $status_clause = " AND ( activity_status_key IS NULL OR activity_status_key NOT IN ('completed','cancelled') )";

        if ( ! empty( $team_ids ) ) {
            $team_ids = array_values( array_unique( array_map( 'intval', $team_ids ) ) );
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table}
                  WHERE session_date >= %s
                    AND team_id IN ({$placeholders})
                    {$club_clause}
                    {$status_clause}
                  ORDER BY session_date ASC
                  LIMIT 1",
                array_merge( [ $today ], $team_ids )
            );
            $row = $wpdb->get_row( $sql );
            return $row ?: null;
        }
        // No coached teams — fall back to any club-scoped upcoming activity.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_date >= %s {$club_clause} {$status_clause} ORDER BY session_date ASC LIMIT 1",
            $today
        ) );
        return $row ?: null;
    }

    private static function hasClubColumn( string $table ): bool {
        global $wpdb;
        return null !== $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'club_id'",
            $table
        ) );
    }

    /**
     * "Today" / "Tomorrow" / formatted date eyebrow for the hero. Pulled
     * out so MarkAttendanceHeroWidget and TodayUpNextHeroWidget format
     * the same date string the same way.
     */
    public static function eyebrowFor( string $session_date ): string {
        $today    = gmdate( 'Y-m-d' );
        $tomorrow = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
        if ( $session_date === $today )    return __( 'Today', 'talenttrack' );
        if ( $session_date === $tomorrow ) return __( 'Tomorrow', 'talenttrack' );
        $ts = strtotime( $session_date );
        if ( $ts === false ) return __( 'Up next', 'talenttrack' );
        return sprintf(
            /* translators: %s is a localized date for an upcoming activity */
            __( 'Up next · %s', 'talenttrack' ),
            (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ts )
        );
    }

    /**
     * v3.110.78 — translated activity-type label (e.g. "Training" /
     * "Wedstrijd") for the type key on a `tt_activities` row. Used by
     * `MarkAttendanceHeroWidget` to show the activity TYPE as the
     * primary hero title — the coach reads "what kind of activity is
     * next?" at a glance, regardless of the title text the operator
     * gave the row. Falls back to an empty string when the lookup
     * row is missing; callers can use the user-supplied title as the
     * fallback.
     */
    public static function activityTypeLabel( string $type_key ): string {
        if ( $type_key === '' ) return '';
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT name, label, meta FROM {$wpdb->prefix}tt_lookups
              WHERE lookup_type = 'activity_type' AND name = %s
              LIMIT 1",
            $type_key
        ) );
        if ( ! $row ) return '';
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            return (string) \TT\Infrastructure\Query\LookupTranslator::name( $row );
        }
        return (string) ( $row->label ?: $row->name );
    }

    /**
     * Team name lookup. Same trivial query both heroes need.
     */
    public static function teamName( int $team_id ): string {
        if ( $team_id <= 0 ) return '';
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $team_id
        ) );
        return $row ? (string) $row->name : '';
    }
}
