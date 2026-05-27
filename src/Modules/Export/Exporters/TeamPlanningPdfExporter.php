<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * TeamPlanningPdfExporter (#947) — printable per-team schedule.
 *
 * One row per `tt_activities` row filtered by team_id + date range,
 * joined to the activity-type lookup and the team name. Renders as a
 * single-table A4 portrait PDF a coach can print for hand-outs /
 * parent meetings / club-noticeboard duty.
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/team_planning?format=pdf`
 *   filters:
 *     `team_id`   (required, > 0 — per-team exporter, no cross-team mode)
 *     `date_from` (Y-m-d, default planner's "today")
 *     `date_to`   (Y-m-d, default 28 days out — matches the planner's
 *                  default visible range on week/month view)
 *
 * Cap: `tt_view_activities` (matches TeamActivitiesCsvExporter).
 */
final class TeamPlanningPdfExporter implements ExporterInterface {

    public function key(): string { return 'team_planning'; }

    public function label(): string { return __( 'Team planning (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id <= 0 ) return null;

        $date_from = isset( $raw['date_from'] ) ? (string) $raw['date_from'] : '';
        $date_to   = isset( $raw['date_to'] )   ? (string) $raw['date_to']   : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $date_from = ( new \DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $date_to = ( new \DateTime( '+28 days', wp_timezone() ) )->format( 'Y-m-d' );
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
        $team_id   = (int) ( $request->filters['team_id']   ?? 0 );
        $date_from = (string) ( $request->filters['date_from'] ?? '' );
        $date_to   = (string) ( $request->filters['date_to']   ?? '' );

        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_teams WHERE id = %d AND club_id = %d LIMIT 1",
            $team_id, $club_id
        ) );
        $team_name = $team ? (string) $team->name : '';

        $rows_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.session_date, a.title, a.location, a.activity_type_key,
                    a.opponent, a.home_away, a.kickoff_time
                FROM {$p}tt_activities a
                WHERE a.club_id = %d AND a.team_id = %d
                  AND a.session_date BETWEEN %s AND %s
                ORDER BY a.session_date ASC, a.kickoff_time ASC, a.id ASC",
            $club_id, $team_id, $date_from, $date_to
        ) );
        $rows_raw = is_array( $rows_raw ) ? $rows_raw : [];

        $html = self::renderHtml( $team_name, $date_from, $date_to, $rows_raw );

        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
        ];
    }

    /**
     * @param object[] $rows
     */
    private static function renderHtml( string $team_name, string $date_from, string $date_to, array $rows ): string {
        $css = '@page { size: A4 portrait; margin: 14mm; }'
             . 'body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; color: #1a1d21; line-height: 1.4; margin: 0; }'
             . 'h1 { font-size: 22pt; margin: 0 0 1mm; }'
             . '.range { font-size: 12pt; color: #5b6e75; margin-bottom: 5mm; }'
             . 'table.plan { width: 100%; border-collapse: collapse; }'
             . 'table.plan th { background: #f4f4f4; text-align: left; padding: 2mm 3mm; font-weight: 600; font-size: 10pt; border-bottom: 1px solid #c5c8cc; }'
             . 'table.plan td { padding: 2mm 3mm; border-bottom: 1px solid #f0f2f4; vertical-align: top; }'
             . 'table.plan tr:nth-child(even) td { background: #fafbfc; }'
             . '.cell-date { width: 18mm; font-weight: 600; }'
             . '.cell-day { width: 10mm; color: #5b6e75; }'
             . '.cell-time { width: 14mm; }'
             . '.cell-type { width: 22mm; color: #5b6e75; font-size: 10pt; }'
             . '.footer { margin-top: 8mm; font-size: 9pt; color: #5b6e75; }';

        $header = '<div><h1>' . esc_html( $team_name !== '' ? $team_name : __( 'Team planning', 'talenttrack' ) ) . '</h1>'
                . '<div class="range">' . esc_html( sprintf(
                    /* translators: 1: from-date, 2: to-date */
                    __( 'Schedule: %1$s – %2$s', 'talenttrack' ),
                    $date_from,
                    $date_to
                ) ) . '</div></div>';

        if ( $rows === [] ) {
            $body = '<p><em>' . esc_html__( 'No activities scheduled in this range.', 'talenttrack' ) . '</em></p>';
        } else {
            $body = '<table class="plan"><thead><tr>'
                . '<th class="cell-date">' . esc_html__( 'Date',     'talenttrack' ) . '</th>'
                . '<th class="cell-day">'  . esc_html__( 'Day',      'talenttrack' ) . '</th>'
                . '<th class="cell-time">' . esc_html__( 'Time',     'talenttrack' ) . '</th>'
                . '<th class="cell-type">' . esc_html__( 'Type',     'talenttrack' ) . '</th>'
                . '<th>'                   . esc_html__( 'Title',    'talenttrack' ) . '</th>'
                . '<th>'                   . esc_html__( 'Opponent', 'talenttrack' ) . '</th>'
                . '<th>'                   . esc_html__( 'Location', 'talenttrack' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $rows as $r ) {
                $date     = (string) ( $r->session_date ?? '' );
                $day      = $date !== '' ? wp_date( 'D', strtotime( $date ) ) : '';
                $time     = (string) ( $r->kickoff_time ?? '' );
                $type_raw = (string) ( $r->activity_type_key ?? '' );
                $type     = $type_raw !== '' ? ucfirst( str_replace( '_', ' ', $type_raw ) ) : '';
                $title    = (string) ( $r->title ?? '' );
                $opponent = (string) ( $r->opponent ?? '' );
                $location = (string) ( $r->location ?? '' );
                $body .= '<tr>'
                    . '<td class="cell-date">' . esc_html( $date )     . '</td>'
                    . '<td class="cell-day">'  . esc_html( $day )      . '</td>'
                    . '<td class="cell-time">' . esc_html( $time )     . '</td>'
                    . '<td class="cell-type">' . esc_html( $type )     . '</td>'
                    . '<td>'                   . esc_html( $title )    . '</td>'
                    . '<td>'                   . esc_html( $opponent ) . '</td>'
                    . '<td>'                   . esc_html( $location ) . '</td>'
                    . '</tr>';
            }
            $body .= '</tbody></table>';
        }

        $footer = '<div class="footer">'
            . esc_html( sprintf(
                /* translators: %s: ISO datetime when the export was generated */
                __( 'Generated %s', 'talenttrack' ),
                ( new \DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d H:i' )
            ) )
            . '</div>';

        return '<!doctype html><html><head><meta charset="UTF-8">'
            . '<title>' . esc_html( $team_name ) . '</title>'
            . '<style>' . $css . '</style></head><body>'
            . $header
            . $body
            . $footer
            . '</body></html>';
    }
}
