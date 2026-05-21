<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * TeamActivitiesCsvExporter (#865) — one row per activity for a team,
 * with attendance count and average evaluation rating from any
 * evaluations linked to the activity (matches).
 *
 * Use case: "All training and matches for JO13 in 2025-26 season —
 * type, date, attendance count, average rating".
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/team_activities?format=csv|xlsx`
 *   filters:
 *     `team_id`   (optional)
 *     `date_from` (Y-m-d, default 1 year ago)
 *     `date_to`   (Y-m-d, default today)
 *
 * Cap: `tt_view_activities`.
 */
final class TeamActivitiesCsvExporter implements ExporterInterface {

    public function key(): string { return 'team_activities'; }

    public function label(): string { return __( 'Team activity history', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'csv', 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id < 0 ) $team_id = 0;

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

        $where  = [ 'a.club_id = %d', 'a.session_date BETWEEN %s AND %s' ];
        $params = [ $club_id, $date_from, $date_to ];
        if ( $team_id > 0 ) {
            $where[]  = 'a.team_id = %d';
            $params[] = $team_id;
        }

        $sql = "SELECT a.id, a.session_date, a.title, a.location,
                       t.name AS team_name,
                       (SELECT COUNT(*) FROM {$p}tt_attendance att
                          WHERE att.activity_id = a.id
                            AND att.club_id = a.club_id
                            AND att.status = 'present'
                            AND att.record_type = 'actual'
                       ) AS attendance_count,
                       (SELECT ROUND(AVG(er.rating), 2)
                          FROM {$p}tt_evaluations e
                          INNER JOIN {$p}tt_eval_ratings er ON er.evaluation_id = e.id
                          INNER JOIN {$p}tt_players pl ON pl.id = e.player_id
                         WHERE e.eval_date = a.session_date
                           AND pl.team_id = a.team_id
                           AND pl.club_id = a.club_id
                           AND e.archived_at IS NULL
                       ) AS rating_avg
                  FROM {$p}tt_activities a
                  LEFT JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                 WHERE " . implode( ' AND ', $where ) . "
                 ORDER BY a.session_date ASC, t.name ASC, a.id ASC";
        $rows_raw = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $rows_raw = is_array( $rows_raw ) ? $rows_raw : [];

        $headers = [
            __( 'Activity ID',     'talenttrack' ),
            __( 'Date',            'talenttrack' ),
            __( 'Title',           'talenttrack' ),
            __( 'Team',            'talenttrack' ),
            __( 'Location',        'talenttrack' ),
            __( 'Attendance',      'talenttrack' ),
            __( 'Average rating',  'talenttrack' ),
        ];

        $rows = [];
        foreach ( $rows_raw as $r ) {
            $rows[] = [
                (int)    $r->id,
                (string) ( $r->session_date ?? '' ),
                (string) ( $r->title ?? '' ),
                (string) ( $r->team_name ?? '' ),
                (string) ( $r->location ?? '' ),
                (int)    ( $r->attendance_count ?? 0 ),
                $r->rating_avg !== null ? (float) $r->rating_avg : '',
            ];
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }
}
