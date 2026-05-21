<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * KpiSnapshotXlsxExporter (#865) — point-in-time KPI snapshot for board
 * meetings / quarterly reviews.
 *
 * Single sheet, one row per KPI:
 *   - Active players
 *   - Total players (incl. archived / trial)
 *   - Active teams
 *   - Activities in period
 *   - Evaluations in period
 *   - Attendance: present rows / total
 *   - Goals: active / completed / total
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/kpi_snapshot?format=xlsx`
 *   filters:
 *     `date_from` (Y-m-d, default first day of current month)
 *     `date_to`   (Y-m-d, default today)
 *
 * Cap: `tt_view_reports`.
 */
final class KpiSnapshotXlsxExporter implements ExporterInterface {

    public function key(): string { return 'kpi_snapshot'; }

    public function label(): string { return __( 'KPI snapshot', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_view_reports'; }

    public function validateFilters( array $raw ): ?array {
        $date_from = isset( $raw['date_from'] ) ? (string) $raw['date_from'] : '';
        $date_to   = isset( $raw['date_to'] )   ? (string) $raw['date_to']   : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $date_from = ( new \DateTime( 'first day of this month', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $date_to = ( new \DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( $date_from > $date_to ) {
            [ $date_from, $date_to ] = [ $date_to, $date_from ];
        }
        return [ 'date_from' => $date_from, 'date_to' => $date_to ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $club_id   = (int) $request->clubId;
        $date_from = (string) ( $request->filters['date_from'] ?? '' );
        $date_to   = (string) ( $request->filters['date_to']   ?? '' );

        $active_players = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players WHERE club_id = %d AND status = 'active'",
            $club_id
        ) );
        $total_players = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players WHERE club_id = %d",
            $club_id
        ) );
        $active_teams = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_teams WHERE club_id = %d AND ( archived_at IS NULL OR archived_at = '0000-00-00 00:00:00' )",
            $club_id
        ) );
        $activities_in_period = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_activities WHERE club_id = %d AND session_date BETWEEN %s AND %s",
            $club_id, $date_from, $date_to
        ) );
        $evaluations_in_period = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_evaluations e
                INNER JOIN {$p}tt_players pl ON pl.id = e.player_id
                WHERE pl.club_id = %d AND e.archived_at IS NULL
                  AND e.eval_date BETWEEN %s AND %s",
            $club_id, $date_from, $date_to
        ) );
        $attendance_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_attendance att
                INNER JOIN {$p}tt_activities a ON a.id = att.activity_id AND a.club_id = att.club_id
                WHERE att.club_id = %d
                  AND att.record_type = 'actual'
                  AND a.plan_state = 'completed'
                  AND a.session_date BETWEEN %s AND %s",
            $club_id, $date_from, $date_to
        ) );
        $attendance_present = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_attendance att
                INNER JOIN {$p}tt_activities a ON a.id = att.activity_id AND a.club_id = att.club_id
                WHERE att.club_id = %d
                  AND att.record_type = 'actual'
                  AND a.plan_state = 'completed'
                  AND a.session_date BETWEEN %s AND %s
                  AND att.status = 'present'",
            $club_id, $date_from, $date_to
        ) );
        $attendance_pct = $attendance_total > 0
            ? round( ( $attendance_present / $attendance_total ) * 100, 1 )
            : '';

        $goals_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE club_id = %d",
            $club_id
        ) );
        $goals_active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE club_id = %d AND status IN ('pending', 'in_progress')",
            $club_id
        ) );
        $goals_completed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE club_id = %d AND status = 'completed'",
            $club_id
        ) );

        $headers = [
            __( 'Metric', 'talenttrack' ),
            __( 'Value',  'talenttrack' ),
        ];

        $rows = [
            [ __( 'Snapshot range from', 'talenttrack' ), $date_from ],
            [ __( 'Snapshot range to',   'talenttrack' ), $date_to ],
            [ __( 'Generated at',        'talenttrack' ), gmdate( 'Y-m-d H:i:s' ) . ' UTC' ],
            [ __( 'Active players',      'talenttrack' ), $active_players ],
            [ __( 'Total players',       'talenttrack' ), $total_players ],
            [ __( 'Active teams',        'talenttrack' ), $active_teams ],
            [ __( 'Activities in period','talenttrack' ), $activities_in_period ],
            [ __( 'Evaluations in period','talenttrack' ), $evaluations_in_period ],
            [ __( 'Attendance rows in period', 'talenttrack' ), $attendance_total ],
            [ __( 'Attendance present', 'talenttrack' ), $attendance_present ],
            [ __( 'Attendance present %', 'talenttrack' ), $attendance_pct ],
            [ __( 'Goals — total',      'talenttrack' ), $goals_total ],
            [ __( 'Goals — active',     'talenttrack' ), $goals_active ],
            [ __( 'Goals — completed',  'talenttrack' ), $goals_completed ],
        ];

        return [ 'headers' => $headers, 'rows' => $rows ];
    }
}
