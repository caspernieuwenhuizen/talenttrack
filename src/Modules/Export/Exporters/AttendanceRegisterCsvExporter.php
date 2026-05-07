<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * AttendanceRegisterCsvExporter (#0063 use case 5) — attendance
 * register CSV by team + date range.
 *
 * One row per (player, activity) pair within the requested window.
 * The percent-summary view (one row per player with their period
 * percentage) lands as a separate use case if pilot operators ask —
 * the row-level export covers the HoD oversight + parent-meeting
 * spreadsheet workflow that's been requested most.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/attendance_register?format=csv`
 *   filters:
 *     `team_id`    (optional)
 *     `date_from`  (Y-m-d, default 90 days ago)
 *     `date_to`    (Y-m-d, default today)
 *
 * Cap: `tt_view_activities`.
 */
final class AttendanceRegisterCsvExporter implements ExporterInterface {

    public function key(): string { return 'attendance_register'; }

    public function label(): string { return __( 'Attendance register (CSV)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'csv' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id < 0 ) $team_id = 0;

        $date_from = isset( $raw['date_from'] ) ? (string) $raw['date_from'] : '';
        $date_to   = isset( $raw['date_to'] )   ? (string) $raw['date_to']   : '';

        if ( ! self::isValidDate( $date_from ) ) {
            $date_from = ( new \DateTime( '-90 days', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( ! self::isValidDate( $date_to ) ) {
            $date_to = ( new \DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( $date_from > $date_to ) {
            // Swap nonsensical ranges so the query still returns sensible rows.
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

        $filters   = $request->filters;
        $team_id   = (int) ( $filters['team_id']   ?? 0 );
        $date_from = (string) ( $filters['date_from'] ?? '' );
        $date_to   = (string) ( $filters['date_to']   ?? '' );

        $where  = [
            'a.club_id = %d',
            'a.session_date BETWEEN %s AND %s',
        ];
        $params = [ (int) $request->clubId, $date_from, $date_to ];

        if ( $team_id > 0 ) {
            $where[]  = 'a.team_id = %d';
            $params[] = $team_id;
        }

        $sql = "SELECT a.session_date, a.title AS activity_title,
                       t.name AS team_name,
                       pl.id AS player_id, pl.first_name, pl.last_name,
                       att.status AS attendance_status, att.notes AS attendance_notes
                  FROM {$p}tt_attendance att
                  INNER JOIN {$p}tt_activities a ON a.id = att.activity_id AND a.club_id = att.club_id
                  INNER JOIN {$p}tt_players pl   ON pl.id = att.player_id  AND pl.club_id = att.club_id
                  LEFT JOIN  {$p}tt_teams t      ON t.id = a.team_id        AND t.club_id  = a.club_id
                 WHERE " . implode( ' AND ', $where ) . "
                 ORDER BY a.session_date ASC, t.name ASC, pl.last_name ASC, pl.first_name ASC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $headers = [
            __( 'Date',         'talenttrack' ),
            __( 'Activity',     'talenttrack' ),
            __( 'Team',         'talenttrack' ),
            __( 'Player ID',    'talenttrack' ),
            __( 'First name',   'talenttrack' ),
            __( 'Last name',    'talenttrack' ),
            __( 'Status',       'talenttrack' ),
            __( 'Notes',        'talenttrack' ),
        ];

        $out_rows = [];
        foreach ( $rows as $r ) {
            $out_rows[] = [
                (string) ( $r->session_date ?? '' ),
                (string) ( $r->activity_title ?? '' ),
                (string) ( $r->team_name ?? '' ),
                (int)    $r->player_id,
                (string) ( $r->first_name ?? '' ),
                (string) ( $r->last_name ?? '' ),
                (string) ( $r->attendance_status ?? '' ),
                (string) ( $r->attendance_notes ?? '' ),
            ];
        }

        return [ 'headers' => $headers, 'rows' => $out_rows ];
    }

    private static function isValidDate( string $value ): bool {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return false;
        $dt = \DateTime::createFromFormat( 'Y-m-d', $value );
        return $dt !== false && $dt->format( 'Y-m-d' ) === $value;
    }
}
