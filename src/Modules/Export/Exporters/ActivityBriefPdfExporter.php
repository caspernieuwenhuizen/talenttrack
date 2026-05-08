<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * ActivityBriefPdfExporter (#0063 use case 8) — printable activity-brief PDF.
 *
 * Per user-direction shaping (2026-05-08): ship v1 without field
 * diagrams (the spec's "A4 with field diagrams" — diagrams need a
 * drills sub-entity that doesn't exist today). v1 prints the activity
 * meta + attendance roster, which covers the pitch-side "who's coming,
 * what's the plan" use case.
 *
 * Field diagrams are a deferred follow-up. The follow-up needs:
 *   - A drills sub-entity (`tt_activity_drills` with title, duration,
 *     positions, notes).
 *   - A position-grid widget shareable with the team-blueprint editor.
 *   - SVG output (DomPDF doesn't render canvas / JS).
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/activity_brief_pdf?format=pdf&activity_id=42`
 *
 * Cap: `tt_view_activities` — same gate as the on-screen activities
 * admin.
 *
 * Layout: A4 portrait, header with title + date + team + location +
 * type, notes block, attendance table with per-player status
 * (Present / Late / Absent / etc. via the seeded `attendance_status`
 * lookup), generated-date footer.
 */
final class ActivityBriefPdfExporter implements ExporterInterface {

    public function key(): string { return 'activity_brief_pdf'; }

    public function label(): string { return __( 'Activity brief (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function validateFilters( array $raw ): ?array {
        $activity_id = isset( $raw['activity_id'] ) ? (int) $raw['activity_id'] : 0;
        if ( $activity_id <= 0 ) return null;
        return [ 'activity_id' => $activity_id ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $activity_id = (int) ( $request->filters['activity_id'] ?? 0 );

        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.location, a.team_id, a.notes,
                    a.activity_type_key, a.club_id,
                    t.name AS team_name
                FROM {$p}tt_activities a
                LEFT JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                WHERE a.id = %d AND a.club_id = %d
                LIMIT 1",
            $activity_id,
            (int) $request->clubId
        ) );

        if ( ! $activity ) {
            return [
                'html'    => '<p>' . esc_html__( 'Activity not found.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ];
        }

        $roster = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id AS player_id, pl.first_name, pl.last_name, pl.jersey_number,
                    pl.preferred_positions, att.status, att.notes AS att_notes
                FROM {$p}tt_attendance att
                JOIN {$p}tt_players pl ON pl.id = att.player_id
                WHERE att.activity_id = %d AND pl.club_id = %d
                ORDER BY pl.last_name ASC, pl.first_name ASC",
            $activity_id,
            (int) $request->clubId
        ) );
        $roster = is_array( $roster ) ? $roster : [];

        $html = self::renderHtml( $activity, $roster );

        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
        ];
    }

    /**
     * @param object   $activity
     * @param object[] $roster
     */
    private static function renderHtml( object $activity, array $roster ): string {
        $css = '@page { size: A4 portrait; margin: 16mm; }'
             . 'body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; color: #1a1d21; line-height: 1.4; margin: 0; }'
             . 'h1 { font-size: 20pt; margin: 0 0 4mm; }'
             . 'h2 { font-size: 13pt; margin: 8mm 0 3mm; border-bottom: 1px solid #e5e7ea; padding-bottom: 2mm; color: #1a1d21; }'
             . '.meta { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }'
             . '.meta th { width: 35mm; text-align: left; font-weight: 600; color: #5b6e75; padding: 2mm 4mm 2mm 0; vertical-align: top; }'
             . '.meta td { padding: 2mm 0; }'
             . '.notes { background: #fafbfc; border-left: 3px solid #c5c8cc; padding: 3mm 4mm; margin: 3mm 0; white-space: pre-wrap; font-size: 10.5pt; }'
             . 'table.roster { width: 100%; border-collapse: collapse; margin-top: 3mm; }'
             . 'table.roster th { background: #f4f4f4; text-align: left; padding: 2mm 3mm; font-weight: 600; font-size: 10pt; color: #1a1d21; border-bottom: 1px solid #c5c8cc; }'
             . 'table.roster td { padding: 2mm 3mm; border-bottom: 1px solid #f0f2f4; vertical-align: top; }'
             . 'table.roster tr:nth-child(even) td { background: #fafbfc; }'
             . '.footer { margin-top: 10mm; font-size: 9pt; color: #5b6e75; text-align: right; }';

        $title       = (string) $activity->title;
        $date        = (string) $activity->session_date;
        $team_name   = (string) ( $activity->team_name ?? '' );
        $location    = (string) ( $activity->location ?? '' );
        $type        = (string) ( $activity->activity_type_key ?? '' );
        $notes       = (string) ( $activity->notes ?? '' );

        $meta_rows = [
            [ __( 'Date',     'talenttrack' ), $date ],
            [ __( 'Team',     'talenttrack' ), $team_name ],
            [ __( 'Location', 'talenttrack' ), $location ],
            [ __( 'Type',     'talenttrack' ), $type ],
        ];

        $meta_html = '<table class="meta"><tbody>';
        foreach ( $meta_rows as [ $label, $value ] ) {
            $value_text = trim( (string) $value );
            $value_html = $value_text !== '' ? esc_html( $value_text ) : '<span style="color:#9aa3a8;">—</span>';
            $meta_html .= '<tr><th>' . esc_html( (string) $label ) . '</th><td>' . $value_html . '</td></tr>';
        }
        $meta_html .= '</tbody></table>';

        $notes_html = $notes !== ''
            ? '<h2>' . esc_html__( 'Notes', 'talenttrack' ) . '</h2><div class="notes">' . esc_html( $notes ) . '</div>'
            : '';

        $roster_html = '<h2>' . esc_html__( 'Attendance roster', 'talenttrack' ) . '</h2>';
        if ( $roster === [] ) {
            $roster_html .= '<p><em>' . esc_html__( 'No attendance recorded.', 'talenttrack' ) . '</em></p>';
        } else {
            $roster_html .= '<table class="roster"><thead><tr>';
            $roster_html .= '<th>' . esc_html__( 'Jersey',   'talenttrack' ) . '</th>';
            $roster_html .= '<th>' . esc_html__( 'Player',   'talenttrack' ) . '</th>';
            $roster_html .= '<th>' . esc_html__( 'Position', 'talenttrack' ) . '</th>';
            $roster_html .= '<th>' . esc_html__( 'Status',   'talenttrack' ) . '</th>';
            $roster_html .= '<th>' . esc_html__( 'Notes',    'talenttrack' ) . '</th>';
            $roster_html .= '</tr></thead><tbody>';
            foreach ( $roster as $r ) {
                $jersey   = $r->jersey_number !== null ? (string) (int) $r->jersey_number : '';
                $name     = trim( (string) $r->first_name . ' ' . (string) $r->last_name );
                $position = self::primaryPosition( (string) ( $r->preferred_positions ?? '' ) );
                $status   = (string) ( $r->status ?? '' );
                $att_note = (string) ( $r->att_notes ?? '' );
                $roster_html .= '<tr>'
                    . '<td>' . esc_html( $jersey ) . '</td>'
                    . '<td>' . esc_html( $name ) . '</td>'
                    . '<td>' . esc_html( $position ) . '</td>'
                    . '<td>' . esc_html( $status ) . '</td>'
                    . '<td>' . esc_html( $att_note ) . '</td>'
                    . '</tr>';
            }
            $roster_html .= '</tbody></table>';
        }

        $generated = esc_html( sprintf(
            /* translators: %s = generation date */
            __( 'Generated %s', 'talenttrack' ),
            date_i18n( get_option( 'date_format' ) ?: 'Y-m-d' )
        ) );

        return '<!doctype html><html><head><meta charset="UTF-8">'
            . '<title>' . esc_html( $title ) . '</title>'
            . '<style>' . $css . '</style></head><body>'
            . '<h1>' . esc_html( $title ) . '</h1>'
            . $meta_html
            . $notes_html
            . $roster_html
            . '<p class="footer">' . $generated . '</p>'
            . '</body></html>';
    }

    private static function primaryPosition( string $positions ): string {
        if ( $positions === '' ) return '';
        $parts = explode( ',', $positions );
        return trim( (string) reset( $parts ) );
    }
}
