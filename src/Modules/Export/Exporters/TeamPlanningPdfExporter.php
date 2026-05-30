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
 * single-table A4 landscape PDF a coach can print for hand-outs /
 * parent meetings / club-noticeboard duty.
 *
 * Landscape orientation + no-wrap auto-shrink (#1000): wider page
 * suits a date-range schedule better than portrait, and every row
 * prints on a single visual line. `table-layout: fixed` + an
 * explicit `<colgroup>` budget per column lock the layout; the
 * per-column font-size is computed from the longest data cell's
 * character count vs the column budget (calibrated for DejaVu Sans
 * at 11pt) and applied via inline `style="font-size:Npt"` on each
 * `<td>` in that column. Headers stay at the original 10pt.
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/team_planning?format=pdf`
 *     filters:
 *       `team_id`   (required, > 0 — per-team exporter, no cross-team mode)
 *       `date_from` (Y-m-d, default planner's "today")
 *       `date_to`   (Y-m-d, default 28 days out — matches the planner's
 *                    default visible range on week/month view)
 *
 * Cap: `tt_view_activities` (matches TeamActivitiesCsvExporter).
 */
final class TeamPlanningPdfExporter implements ExporterInterface {

    /**
     * Per-column width budgets (mm) — landscape A4 content area is
     * ~270mm wide; the sum of these budgets is 276mm, which fits
     * inside the 14mm-margin print frame on every printer driver we've
     * seen pilot installs use.
     */
    private const COL_WIDTHS_MM = [
        'date'     => 22,
        'day'      => 12,
        'time'     => 16,
        'type'     => 26,
        'title'    => 60,
        'opponent' => 60,
        'location' => 80,
    ];

    /**
     * Font-size floor / ceiling (pt). The ceiling matches the original
     * 11pt body; the floor matches the DoD's "if a value can't fit at
     * 7pt, truncate with …" rule.
     */
    private const FONT_MAX_PT = 11;
    private const FONT_MIN_PT = 7;

    /**
     * Approximate average-glyph advance for DejaVu Sans at 11pt, in mm.
     * Calibrated empirically: 11pt DejaVu Sans on DomPDF renders an
     * average ASCII glyph at roughly 1.7mm of advance. Used to scale
     * font-size down per column so the longest cell fits the column's
     * width budget (after subtracting horizontal padding).
     */
    private const GLYPH_MM_AT_11PT = 1.7;

    /** Horizontal padding per `<td>` (mm) — 3mm left + 3mm right. */
    private const CELL_PAD_MM = 6.0;

    public function key(): string { return 'team_planning'; }

    public function label(): string { return __( 'Team planning (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    /** Non-tabular exporter — opts out of the column picker (#986). */
    public function availableColumns(): array { return []; }

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
            'options' => [ 'paper' => 'A4', 'orientation' => 'landscape' ],
        ];
    }

    /**
     * Compute the maximum character count for a column across all rows.
     *
     * @param array<int,array<string,string>> $rendered
     */
    private static function maxChars( array $rendered, string $col ): int {
        $max = 0;
        foreach ( $rendered as $row ) {
            $len = isset( $row[ $col ] ) ? strlen( $row[ $col ] ) : 0;
            if ( $len > $max ) $max = $len;
        }
        return $max;
    }

    /**
     * Resolve the font-size (pt) for a column so its longest cell fits
     * the column's width budget without wrapping. Clamps between
     * FONT_MIN_PT and FONT_MAX_PT; if even the floor overflows, the
     * CSS `text-overflow: ellipsis` truncates the cell at render time.
     */
    private static function fontSizeFor( int $max_chars, float $budget_mm ): float {
        if ( $max_chars <= 0 ) return self::FONT_MAX_PT;

        // Width that one glyph would occupy at FONT_MAX_PT, mm.
        $usable_mm = max( 0.0, $budget_mm - self::CELL_PAD_MM );
        $required_mm_at_max = $max_chars * self::GLYPH_MM_AT_11PT;
        if ( $required_mm_at_max <= $usable_mm ) {
            return self::FONT_MAX_PT;
        }

        // Scale font-size proportionally: pt scales linearly with width.
        $scaled = self::FONT_MAX_PT * ( $usable_mm / $required_mm_at_max );
        if ( $scaled < self::FONT_MIN_PT ) return self::FONT_MIN_PT;
        if ( $scaled > self::FONT_MAX_PT ) return self::FONT_MAX_PT;
        // Round to 1 decimal to keep inline styles compact.
        return round( $scaled, 1 );
    }

    /**
     * @param object[] $rows
     */
    private static function renderHtml( string $team_name, string $date_from, string $date_to, array $rows ): string {
        $css = '@page { size: A4 landscape; margin: 14mm; }'
             . 'body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; color: #1a1d21; line-height: 1.4; margin: 0; '
             . '-webkit-print-color-adjust: exact; print-color-adjust: exact; }'
             . 'h1 { font-size: 22pt; margin: 0 0 1mm; }'
             . '.range { font-size: 12pt; color: #5b6e75; margin-bottom: 5mm; }'
             . 'table.plan { width: 100%; border-collapse: collapse; table-layout: fixed; }'
             . 'table.plan th { background: #f4f4f4; text-align: left; padding: 2mm 3mm; font-weight: 600; '
             . 'font-size: 10pt; border-bottom: 1px solid #c5c8cc; white-space: nowrap; overflow: hidden; '
             . 'text-overflow: ellipsis; }'
             . 'table.plan td { padding: 2mm 3mm; border-bottom: 1px solid #f0f2f4; vertical-align: top; '
             . 'white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }'
             . 'table.plan tr:nth-child(even) td { background: #fafbfc; }'
             . '.cell-date { font-weight: 600; }'
             . '.cell-day { color: #5b6e75; }'
             . '.cell-type { color: #5b6e75; }'
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
            // Pre-render each row into a value map so the auto-shrink pass
            // can measure character counts per column without re-deriving
            // the formatted strings twice.
            $rendered = [];
            foreach ( $rows as $r ) {
                $date     = (string) ( $r->session_date ?? '' );
                $day      = $date !== '' ? (string) wp_date( 'D', strtotime( $date ) ) : '';
                $time     = (string) ( $r->kickoff_time ?? '' );
                $type_raw = (string) ( $r->activity_type_key ?? '' );
                $type     = $type_raw !== '' ? ucfirst( str_replace( '_', ' ', $type_raw ) ) : '';
                $title    = (string) ( $r->title ?? '' );
                $opponent = (string) ( $r->opponent ?? '' );
                $location = (string) ( $r->location ?? '' );
                $rendered[] = [
                    'date'     => $date,
                    'day'      => $day,
                    'time'     => $time,
                    'type'     => $type,
                    'title'    => $title,
                    'opponent' => $opponent,
                    'location' => $location,
                ];
            }

            // Compute the per-column font-size (pt) once, using the
            // column's longest cell vs its width budget. Date/Day/Time
            // are fixed-width formats (YYYY-MM-DD / Mon / HH:MM) and
            // already fit at 11pt, so the clamp returns FONT_MAX_PT for
            // them in practice — but the same code path handles them
            // uniformly with the free-text columns.
            $font_pt = [];
            foreach ( self::COL_WIDTHS_MM as $col => $width_mm ) {
                $font_pt[ $col ] = self::fontSizeFor(
                    self::maxChars( $rendered, $col ),
                    (float) $width_mm
                );
            }

            $colgroup = '<colgroup>';
            foreach ( self::COL_WIDTHS_MM as $col => $width_mm ) {
                $colgroup .= '<col style="width:' . (int) $width_mm . 'mm" />';
            }
            $colgroup .= '</colgroup>';

            $body = '<table class="plan">' . $colgroup . '<thead><tr>'
                . '<th class="cell-date">' . esc_html__( 'Date',     'talenttrack' ) . '</th>'
                . '<th class="cell-day">'  . esc_html__( 'Day',      'talenttrack' ) . '</th>'
                . '<th class="cell-time">' . esc_html__( 'Time',     'talenttrack' ) . '</th>'
                . '<th class="cell-type">' . esc_html__( 'Type',     'talenttrack' ) . '</th>'
                . '<th>'                   . esc_html__( 'Title',    'talenttrack' ) . '</th>'
                . '<th>'                   . esc_html__( 'Opponent', 'talenttrack' ) . '</th>'
                . '<th>'                   . esc_html__( 'Location', 'talenttrack' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $rendered as $row ) {
                $style_for = static function ( string $col ) use ( $font_pt ): string {
                    return 'font-size:' . $font_pt[ $col ] . 'pt';
                };
                $body .= '<tr>'
                    . '<td class="cell-date" style="' . esc_attr( $style_for( 'date' ) )     . '">' . esc_html( $row['date'] )     . '</td>'
                    . '<td class="cell-day"  style="' . esc_attr( $style_for( 'day' ) )      . '">' . esc_html( $row['day'] )      . '</td>'
                    . '<td class="cell-time" style="' . esc_attr( $style_for( 'time' ) )     . '">' . esc_html( $row['time'] )     . '</td>'
                    . '<td class="cell-type" style="' . esc_attr( $style_for( 'type' ) )     . '">' . esc_html( $row['type'] )     . '</td>'
                    . '<td             style="'       . esc_attr( $style_for( 'title' ) )    . '">' . esc_html( $row['title'] )    . '</td>'
                    . '<td             style="'       . esc_attr( $style_for( 'opponent' ) ) . '">' . esc_html( $row['opponent'] ) . '</td>'
                    . '<td             style="'       . esc_attr( $style_for( 'location' ) ) . '">' . esc_html( $row['location'] ) . '</td>'
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
