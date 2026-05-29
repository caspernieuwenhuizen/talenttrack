<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * TeamRosterStatsCsvExporter (#865) — one row per player for a team,
 * with date-bounded attendance count, total minutes played, and the
 * mean of their main-category evaluation averages.
 *
 * Use case: "Give me everything about team JO13: roster, position,
 * jersey, attendance, minutes, evaluation average".
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/team_roster_stats?format=csv|xlsx`
 *   filters:
 *     `team_id`   (required)
 *     `date_from` (Y-m-d, default 1 year ago)
 *     `date_to`   (Y-m-d, default today)
 *
 * Cap: `tt_view_players`.
 */
final class TeamRosterStatsCsvExporter implements ExporterInterface {

    public function key(): string { return 'team_roster_stats'; }

    public function label(): string { return __( 'Team roster + season stats', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'csv', 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_view_players'; }

    public function availableColumns(): array {
        return [
            'player_id'           => __( 'Player ID',           'talenttrack' ),
            'first_name'          => __( 'First name',          'talenttrack' ),
            'last_name'           => __( 'Last name',           'talenttrack' ),
            'date_of_birth'       => __( 'Date of birth',       'talenttrack' ),
            'jersey_number'       => __( 'Jersey number',       'talenttrack' ),
            'preferred_foot'      => __( 'Preferred foot',      'talenttrack' ),
            'preferred_positions' => __( 'Preferred positions', 'talenttrack' ),
            'team'                => __( 'Team',                'talenttrack' ),
            'status'              => __( 'Status',              'talenttrack' ),
            'attendance_count'    => __( 'Attendance count',    'talenttrack' ),
            'minutes_played'      => __( 'Minutes played',      'talenttrack' ),
            'average_rating'      => __( 'Average rating',      'talenttrack' ),
        ];
    }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id <= 0 ) return null;  // team is required.

        $date_from = isset( $raw['date_from'] ) ? (string) $raw['date_from'] : '';
        $date_to   = isset( $raw['date_to'] )   ? (string) $raw['date_to']   : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $date_from = ( new \DateTime( '-1 year', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $date_to = ( new \DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( $date_from > $date_to ) {
            [ $date_from, $date_to ] = [ $date_to, $date_from ];
        }

        return [
            'team_id'   => $team_id,
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $club_id   = (int) $request->clubId;
        $team_id   = (int) ( $request->filters['team_id'] ?? 0 );
        $date_from = (string) ( $request->filters['date_from'] ?? '' );
        $date_to   = (string) ( $request->filters['date_to']   ?? '' );

        $roster = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name, pl.date_of_birth,
                    pl.jersey_number, pl.preferred_foot, pl.preferred_positions,
                    pl.status,
                    t.name AS team_name,
                    (SELECT COUNT(*) FROM {$p}tt_attendance att
                       INNER JOIN {$p}tt_activities a ON a.id = att.activity_id AND a.club_id = att.club_id
                      WHERE att.player_id = pl.id
                        AND att.club_id  = pl.club_id
                        AND att.status   = 'present'
                        AND att.record_type = 'actual'
                        AND a.plan_state = 'completed'
                        AND a.session_date BETWEEN %s AND %s
                    ) AS attendance_count,
                    (SELECT COALESCE(SUM(att.minutes_played), 0) FROM {$p}tt_attendance att
                       INNER JOIN {$p}tt_activities a ON a.id = att.activity_id AND a.club_id = att.club_id
                      WHERE att.player_id = pl.id
                        AND att.club_id  = pl.club_id
                        AND att.record_type = 'actual'
                        AND a.plan_state = 'completed'
                        AND a.session_date BETWEEN %s AND %s
                    ) AS minutes_total,
                    (SELECT ROUND(AVG(er.rating), 2) FROM {$p}tt_eval_ratings er
                       INNER JOIN {$p}tt_evaluations e ON e.id = er.evaluation_id AND e.archived_at IS NULL
                      WHERE e.player_id = pl.id
                        AND e.eval_date BETWEEN %s AND %s
                    ) AS rating_avg
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
              WHERE pl.club_id = %d AND pl.team_id = %d
              ORDER BY pl.last_name ASC, pl.first_name ASC",
            $date_from, $date_to,
            $date_from, $date_to,
            $date_from, $date_to,
            $club_id,
            $team_id
        ) );
        $roster = is_array( $roster ) ? $roster : [];

        $headers = [
            __( 'Player ID',          'talenttrack' ),
            __( 'First name',         'talenttrack' ),
            __( 'Last name',          'talenttrack' ),
            __( 'Date of birth',      'talenttrack' ),
            __( 'Jersey number',      'talenttrack' ),
            __( 'Preferred foot',     'talenttrack' ),
            __( 'Preferred positions','talenttrack' ),
            __( 'Team',               'talenttrack' ),
            __( 'Status',             'talenttrack' ),
            __( 'Attendance count',   'talenttrack' ),
            __( 'Minutes played',     'talenttrack' ),
            __( 'Average rating',     'talenttrack' ),
        ];

        $rows = [];
        foreach ( $roster as $r ) {
            $rows[] = [
                (int)    $r->id,
                (string) ( $r->first_name ?? '' ),
                (string) ( $r->last_name ?? '' ),
                (string) ( $r->date_of_birth ?? '' ),
                $r->jersey_number !== null ? (int) $r->jersey_number : '',
                (string) ( $r->preferred_foot ?? '' ),
                (string) ( $r->preferred_positions ?? '' ),
                (string) ( $r->team_name ?? '' ),
                (string) ( $r->status ?? '' ),
                (int)    ( $r->attendance_count ?? 0 ),
                (int)    ( $r->minutes_total ?? 0 ),
                $r->rating_avg !== null ? (float) $r->rating_avg : '',
            ];
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }
}
