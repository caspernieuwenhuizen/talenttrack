<?php
namespace TT\Modules\PersonaDashboard\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamOverviewRepository (#0073) — per-team headline numbers for the HoD
 * landing's `team_overview_grid` widget.
 *
 * Aggregates over a date window: average evaluation rating + attendance
 * percentage. Joins are done in SQL so the widget renders 12 cards in
 * one round-trip. The repository scopes everything to the current club
 * via CurrentClub::id() — multi-tenant from day one.
 *
 * Sort modes:
 *   alphabetical    — team name ASC (default).
 *   rating_desc     — highest avg rating first; nulls last.
 *   attendance_desc — highest attendance first; nulls last.
 *   concern_first   — teams below either threshold first; rest after.
 *
 * Concern thresholds are read from `tt_config`:
 *   team_concern_rating_threshold      (default 6.0)
 *   team_concern_attendance_threshold  (default 70.0)
 */
final class TeamOverviewRepository {

    /** @return list<TeamSummary> */
    public function summariesFor( int $hod_user_id, int $days, string $sort, int $limit ): array {
        if ( $hod_user_id <= 0 || $days <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $club_id = CurrentClub::id();
        $from    = ( new \DateTimeImmutable( "-{$days} days" ) )->format( 'Y-m-d' );
        $to      = ( new \DateTimeImmutable() )->format( 'Y-m-d' );

        // Per-team headline numbers in one query. Sub-selects keep the
        // window join-driven and avoid LEFT-JOIN row multiplication.
        // v3.110.88 — read the age_group VARCHAR column directly instead
        // of joining to tt_lookups via a non-existent `t.age_group_id`.
        // The schema (Activator + migrations) carries age_group as a
        // string on tt_teams; there is no FK column. The pre-fix join
        // raised "Unknown column 't.age_group_id' in 'on clause'", which
        // made wpdb->get_results() return false → empty array → widget
        // empty state ("No teams with recent activity") on every render.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id AS team_id,
                    t.name AS team_name,
                    COALESCE(t.age_group, '') AS age_group,
                    (
                        SELECT CONCAT_WS(' ', pe.first_name, pe.last_name)
                          FROM {$p}tt_team_people tp2
                          INNER JOIN {$p}tt_people pe ON pe.id = tp2.person_id AND pe.club_id = tp2.club_id
                          LEFT  JOIN {$p}tt_functional_roles fr ON fr.id = tp2.functional_role_id AND fr.club_id = tp2.club_id
                         WHERE tp2.team_id = t.id AND tp2.club_id = t.club_id
                           AND ( fr.role_key = 'head_coach' OR tp2.role_in_team = 'head_coach' )
                         ORDER BY tp2.id ASC
                         LIMIT 1
                    ) AS head_coach_name,
                    (
                        SELECT AVG(r.value)
                          FROM {$p}tt_eval_ratings r
                          INNER JOIN {$p}tt_evaluations e ON e.id = r.evaluation_id AND e.club_id = r.club_id
                          INNER JOIN {$p}tt_players pl ON pl.id = e.player_id AND pl.club_id = e.club_id
                         WHERE pl.team_id = t.id
                           AND e.club_id = t.club_id
                           AND e.eval_date >= %s
                           AND e.eval_date <= %s
                    ) AS avg_rating,
                    (
                        SELECT
                            CASE WHEN SUM( CASE WHEN att.status IN ('Present','Absent') THEN 1 ELSE 0 END ) > 0
                                 THEN ROUND( SUM( CASE WHEN att.status = 'Present' THEN 1 ELSE 0 END )
                                             / SUM( CASE WHEN att.status IN ('Present','Absent') THEN 1 ELSE 0 END ) * 100, 1 )
                                 ELSE NULL
                            END
                          FROM {$p}tt_attendance att
                          INNER JOIN {$p}tt_activities act ON act.id = att.activity_id AND act.club_id = att.club_id
                         WHERE act.team_id = t.id
                           AND att.club_id = t.club_id
                           AND att.is_guest = 0
                           AND act.session_date >= %s
                           AND act.session_date <= %s
                    ) AS attendance_pct,
                    (
                        SELECT COUNT(*)
                          FROM {$p}tt_players pl
                         WHERE pl.team_id = t.id AND pl.club_id = t.club_id AND pl.status = 'active'
                    ) AS player_count
               FROM {$p}tt_teams t
              WHERE t.club_id = %d
              ORDER BY t.name ASC",
            $from, $to, $from, $to, $club_id
        ) );

        if ( ! is_array( $rows ) || $rows === [] ) return [];

        $summaries = array_map( static function ( $r ): TeamSummary {
            return new TeamSummary(
                (int) $r->team_id,
                (string) $r->team_name,
                (string) $r->age_group,
                $r->head_coach_name !== null && $r->head_coach_name !== '' ? (string) $r->head_coach_name : null,
                $r->avg_rating !== null ? (float) $r->avg_rating : null,
                $r->attendance_pct !== null ? (float) $r->attendance_pct : null,
                (int) $r->player_count,
                0
            );
        }, $rows );

        $summaries = $this->applySort( $summaries, $sort );
        if ( $limit > 0 && count( $summaries ) > $limit ) {
            $summaries = array_slice( $summaries, 0, $limit );
        }
        return $summaries;
    }

    /**
     * @return list<array<string,mixed>>  Each row: [player_id, name, attendance_pct?, rating?, status_color]
     */
    public function teamPlayerBreakdown( int $team_id, int $days ): array {
        if ( $team_id <= 0 || $days <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $club_id = CurrentClub::id();
        $from    = ( new \DateTimeImmutable( "-{$days} days" ) )->format( 'Y-m-d' );
        $to      = ( new \DateTimeImmutable() )->format( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id AS player_id,
                    pl.first_name, pl.last_name,
                    (
                        SELECT
                            CASE WHEN SUM( CASE WHEN att.status IN ('Present','Absent') THEN 1 ELSE 0 END ) > 0
                                 THEN ROUND( SUM( CASE WHEN att.status = 'Present' THEN 1 ELSE 0 END )
                                             / SUM( CASE WHEN att.status IN ('Present','Absent') THEN 1 ELSE 0 END ) * 100, 1 )
                                 ELSE NULL
                            END
                          FROM {$p}tt_attendance att
                          INNER JOIN {$p}tt_activities act ON act.id = att.activity_id AND act.club_id = att.club_id
                         WHERE att.player_id = pl.id
                           AND att.club_id = pl.club_id
                           AND att.is_guest = 0
                           AND act.session_date >= %s
                           AND act.session_date <= %s
                    ) AS attendance_pct,
                    (
                        SELECT AVG(r.value)
                          FROM {$p}tt_eval_ratings r
                          INNER JOIN {$p}tt_evaluations e ON e.id = r.evaluation_id AND e.club_id = r.club_id
                         WHERE e.player_id = pl.id
                           AND e.club_id = pl.club_id
                           AND e.eval_date >= %s
                           AND e.eval_date <= %s
                    ) AS avg_rating
               FROM {$p}tt_players pl
              WHERE pl.team_id = %d AND pl.club_id = %d AND pl.status = 'active'
              ORDER BY pl.last_name ASC, pl.first_name ASC",
            $from, $to, $from, $to, $team_id, $club_id
        ) );

        if ( ! is_array( $rows ) || $rows === [] ) return [];

        return array_map( static function ( $r ): array {
            $name = trim( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) );
            return [
                'player_id'      => (int) $r->player_id,
                'name'           => $name !== '' ? $name : '—',
                'attendance_pct' => $r->attendance_pct !== null ? (float) $r->attendance_pct : null,
                'avg_rating'     => $r->avg_rating !== null ? (float) $r->avg_rating : null,
                'status_color'   => '', // matrix-based status pill rendering is a follow-up
            ];
        }, $rows );
    }

    /**
     * @param list<TeamSummary> $summaries
     * @return list<TeamSummary>
     */
    private function applySort( array $summaries, string $sort ): array {
        switch ( $sort ) {
            case 'rating_desc':
                usort( $summaries, static function ( TeamSummary $a, TeamSummary $b ): int {
                    if ( $a->avg_rating === null && $b->avg_rating === null ) return strcmp( $a->name, $b->name );
                    if ( $a->avg_rating === null ) return 1;
                    if ( $b->avg_rating === null ) return -1;
                    return $b->avg_rating <=> $a->avg_rating;
                } );
                break;
            case 'attendance_desc':
                usort( $summaries, static function ( TeamSummary $a, TeamSummary $b ): int {
                    if ( $a->attendance_pct === null && $b->attendance_pct === null ) return strcmp( $a->name, $b->name );
                    if ( $a->attendance_pct === null ) return 1;
                    if ( $b->attendance_pct === null ) return -1;
                    return $b->attendance_pct <=> $a->attendance_pct;
                } );
                break;
            case 'concern_first':
                $rating_threshold = (float) \TT\Infrastructure\Query\QueryHelpers::get_config(
                    'team_concern_rating_threshold', '6.0'
                );
                $att_threshold = (float) \TT\Infrastructure\Query\QueryHelpers::get_config(
                    'team_concern_attendance_threshold', '70.0'
                );
                usort( $summaries, static function ( TeamSummary $a, TeamSummary $b )
                    use ( $rating_threshold, $att_threshold ): int {
                    $a_concern = ( $a->avg_rating !== null && $a->avg_rating < $rating_threshold )
                        || ( $a->attendance_pct !== null && $a->attendance_pct < $att_threshold );
                    $b_concern = ( $b->avg_rating !== null && $b->avg_rating < $rating_threshold )
                        || ( $b->attendance_pct !== null && $b->attendance_pct < $att_threshold );
                    if ( $a_concern !== $b_concern ) return $a_concern ? -1 : 1;
                    return strcmp( $a->name, $b->name );
                } );
                break;
            case 'alphabetical':
            default:
                usort( $summaries, static fn( TeamSummary $a, TeamSummary $b ): int => strcmp( $a->name, $b->name ) );
                break;
        }
        return $summaries;
    }
}
