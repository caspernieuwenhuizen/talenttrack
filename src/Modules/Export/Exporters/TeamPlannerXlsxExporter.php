<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * TeamPlannerXlsxExporter (#1269) — week-by-week styled grid xlsx
 * mirroring `FrontendTeamPlannerView`'s online layout.
 *
 * Consumed by the team-planner page's "Export XLSX" button (which used
 * to call `team_activities` — the flat accounting exporter). This one
 * is the coach-facing snapshot of the calendar the user is looking at.
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/team_planner?format=xlsx`
 *   filters:
 *     `team_id`   (required)
 *     `date_from` (Y-m-d, default 1 month ago, snaps to the Monday before)
 *     `date_to`   (Y-m-d, default 6 weeks after date_from)
 *
 * Cap: `tt_view_activities`.
 *
 * Output: a two-sheet workbook.
 *
 *   Sheet 1 "Planner" — A–G = Monday → Sunday. Per-week row trio:
 *     1. merged week-header (A–G).
 *     2. day-of-week + date header row.
 *     3. merged day-cell with stacked activity card text (title bold /
 *        time window / location / principle codes / status pill colour
 *        as the cell's fill).
 *
 *   Sheet 2 "Principles — last 8 weeks" — two-column table (code +
 *   hit count) over the same 8-week / `activity_status_key='completed'`
 *   filter the online view uses.
 *
 * The XlsxRenderer's `styled_sheets` payload shape (PR1 of this issue,
 * v4.20.58) handles all the cell-style + merge + column-width
 * mechanics; this exporter just declares the data + style refs.
 */
final class TeamPlannerXlsxExporter implements ExporterInterface {

    public function key(): string { return 'team_planner'; }

    public function label(): string { return __( 'Team planner (week-by-week)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function availableColumns(): array {
        return [
            'planner_grid' => __( 'Planner grid', 'talenttrack' ),
            'principles'   => __( 'Principles trained (last 8 weeks)', 'talenttrack' ),
        ];
    }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id <= 0 ) return null;

        $date_from = isset( $raw['date_from'] ) ? (string) $raw['date_from'] : '';
        $date_to   = isset( $raw['date_to'] )   ? (string) $raw['date_to']   : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $date_from = ( new \DateTime( '-1 month', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $date_to = ( new \DateTime( $date_from . ' +6 weeks', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( $date_from > $date_to ) {
            [ $date_from, $date_to ] = [ $date_to, $date_from ];
        }
        // Snap date_from to the Monday on/before so the grid columns
        // always start at Monday — matches the online view.
        $date_from = self::snapToMonday( $date_from );

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

        $team_name = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$p}tt_teams WHERE id = %d AND club_id = %d",
            $team_id, $club_id
        ) );

        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date, start_time, end_time, location,
                    activity_status_key, activity_type_key
               FROM {$p}tt_activities
              WHERE team_id = %d AND club_id = %d
                AND session_date BETWEEN %s AND %s
                AND activity_status_key <> 'cancelled'
                AND ( archived_at IS NULL OR archived_at = '' )
              ORDER BY session_date ASC, start_time ASC, id ASC",
            $team_id, $club_id, $date_from, $date_to
        ) );
        $activities = is_array( $activities ) ? $activities : [];

        $principle_map = self::principleCodesByActivity( array_map( static fn( $a ) => (int) $a->id, $activities ) );

        $today = ( new \DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );

        // Sheet 1 — week-by-week grid.
        $sheet1_rows  = [];
        $sheet1_merges = [];
        $sheet1_styles = self::sheet1Styles();

        $sheet1_rows[] = [
            [ 'v' => sprintf(
                /* translators: 1: team name, 2: date_from, 3: date_to */
                __( 'Planner — %1$s — %2$s to %3$s', 'talenttrack' ),
                $team_name !== '' ? $team_name : '#' . $team_id,
                $date_from,
                $date_to
            ), 'style' => 'title' ],
        ];
        $sheet1_merges[] = 'A1:G1';
        // Blank spacer row.
        $sheet1_rows[] = [ '' ];

        $by_date = [];
        foreach ( $activities as $a ) {
            $by_date[ (string) $a->session_date ][] = $a;
        }

        $cursor      = $date_from;
        $row_pointer = 3; // 1=title, 2=spacer, 3=first content row
        $safety = 200; // bound the loop in case of bad input
        while ( $cursor <= $date_to && $safety-- > 0 ) {
            $week_end = ( new \DateTime( $cursor . ' +6 days', wp_timezone() ) )->format( 'Y-m-d' );
            // Row 1: week header
            $sheet1_rows[] = [
                [ 'v' => sprintf(
                    /* translators: 1: start of week, 2: end of week */
                    __( 'Week of %1$s — %2$s', 'talenttrack' ),
                    self::niceDate( $cursor ),
                    self::niceDate( $week_end )
                ), 'style' => 'week_header' ],
            ];
            $sheet1_merges[] = 'A' . $row_pointer . ':G' . $row_pointer;
            $row_pointer++;

            // Row 2: day-of-week + date column headers (Mon → Sun).
            $dow_row = [];
            $day_cursor = $cursor;
            for ( $i = 0; $i < 7; $i++ ) {
                $dow_row[] = [
                    'v'     => self::niceDayLabel( $day_cursor ),
                    'style' => $day_cursor === $today ? 'day_header_today' : 'day_header',
                ];
                $day_cursor = ( new \DateTime( $day_cursor . ' +1 day', wp_timezone() ) )->format( 'Y-m-d' );
            }
            $sheet1_rows[] = $dow_row;
            $row_pointer++;

            // Row 3: day cells — one merged cell per day-of-week, stacking
            // activity card text. Each card = 5 lines separated by \n.
            $card_row    = [];
            $day_cursor  = $cursor;
            $max_card_lines = 5; // height multiplier per card to set row height later
            $cards_in_row = 0;
            for ( $i = 0; $i < 7; $i++ ) {
                $items = $by_date[ $day_cursor ] ?? [];
                $cards_in_row = max( $cards_in_row, count( $items ) );
                $card_text = '';
                $cell_style = $day_cursor === $today ? 'day_cell_today' : 'day_cell';
                foreach ( $items as $idx => $a ) {
                    if ( $idx > 0 ) $card_text .= "\n\n";
                    $card_text .= self::cardText( $a, $principle_map[ (int) $a->id ] ?? [] );
                    // Status colour wins over today's border on cell fill;
                    // pick the *first* card's status colour as the row's
                    // fill (simple precedence, matches online "topmost
                    // card paints the cell" behaviour).
                    if ( $idx === 0 ) {
                        $status_style = self::cellStyleForStatus( (string) $a->activity_status_key );
                        if ( $status_style !== '' ) {
                            $cell_style = $day_cursor === $today ? $status_style . '_today' : $status_style;
                        }
                    }
                }
                $card_row[] = [
                    'v'     => $card_text,
                    'style' => $cell_style,
                ];
                $day_cursor = ( new \DateTime( $day_cursor . ' +1 day', wp_timezone() ) )->format( 'Y-m-d' );
            }
            $sheet1_rows[] = $card_row;
            $row_pointer++;

            // Spacer row between weeks for readability.
            $sheet1_rows[] = [ '' ];
            $row_pointer++;

            $cursor = ( new \DateTime( $cursor . ' +7 days', wp_timezone() ) )->format( 'Y-m-d' );
        }

        // Sheet 2 — principles last 8 weeks.
        $principle_rows = self::principleCoverage8Weeks( $team_id, $club_id );

        return [
            'styled_sheets' => [
                __( 'Planner', 'talenttrack' ) => [
                    'rows'       => $sheet1_rows,
                    'merges'     => $sheet1_merges,
                    'col_widths' => [
                        'A' => 22, 'B' => 22, 'C' => 22, 'D' => 22,
                        'E' => 22, 'F' => 22, 'G' => 22,
                    ],
                    'styles'     => $sheet1_styles,
                ],
                __( 'Principles 8w', 'talenttrack' ) => [
                    'rows'       => $principle_rows,
                    'merges'     => [],
                    'col_widths' => [ 'A' => 14, 'B' => 12 ],
                    'styles'     => [
                        'th' => [
                            'font' => [ 'bold' => true ],
                            'fill' => [ 'color' => 'F0F3F2' ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param int[] $activity_ids
     * @return array<int, string[]> activity_id => list of principle codes
     */
    private static function principleCodesByActivity( array $activity_ids ): array {
        if ( empty( $activity_ids ) ) return [];
        global $wpdb;
        $p = $wpdb->prefix;
        $in = implode( ',', array_map( 'intval', $activity_ids ) );
        $rows = $wpdb->get_results(
            "SELECT ap.activity_id, pr.code
               FROM {$p}tt_activity_principles ap
               JOIN {$p}tt_principles pr ON pr.id = ap.principle_id
              WHERE ap.activity_id IN ($in)
              ORDER BY ap.sort_order ASC, ap.id ASC"
        );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->activity_id ][] = (string) $r->code;
        }
        return $out;
    }

    /**
     * 8-week principle coverage matches FrontendTeamPlannerView's
     * "Principles trained" widget.
     *
     * @return array<int, array<int, mixed>>
     */
    private static function principleCoverage8Weeks( int $team_id, int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $cutoff = ( new \DateTime( '-8 weeks', wp_timezone() ) )->format( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pr.code, COUNT(DISTINCT ap.activity_id) AS hits
               FROM {$p}tt_principles pr
               JOIN {$p}tt_activity_principles ap ON ap.principle_id = pr.id
               JOIN {$p}tt_activities a ON a.id = ap.activity_id
              WHERE a.team_id = %d AND a.club_id = %d
                AND a.session_date >= %s
                AND a.activity_status_key = 'completed'
              GROUP BY pr.id, pr.code
              ORDER BY hits DESC, pr.code ASC
              LIMIT 30",
            $team_id, $club_id, $cutoff
        ) );

        $out = [];
        $out[] = [
            [ 'v' => __( 'Principle code', 'talenttrack' ), 'style' => 'th' ],
            [ 'v' => __( 'Hit count', 'talenttrack' ),      'style' => 'th' ],
        ];
        foreach ( (array) $rows as $r ) {
            $out[] = [ (string) $r->code, (int) $r->hits ];
        }
        if ( count( $out ) === 1 ) {
            $out[] = [ __( 'No principles trained in the last 8 weeks.', 'talenttrack' ), '' ];
        }
        return $out;
    }

    private static function cardText( object $a, array $principle_codes ): string {
        $type_key = (string) ( $a->activity_type_key ?? '' );
        $type     = $type_key !== '' ? LabelTranslator::activityType( $type_key ) : '';
        if ( $type === null || $type === '' ) {
            $type = $type_key !== '' ? ucfirst( str_replace( '_', ' ', $type_key ) ) : '';
        }

        $title = trim( (string) ( $a->title ?? '' ) );
        $line1 = $title !== '' ? $title : $type;
        if ( $line1 === '' ) $line1 = __( 'Activity', 'talenttrack' );

        $start = (string) ( $a->start_time ?? '' );
        $end   = (string) ( $a->end_time ?? '' );
        $time  = '';
        if ( $start !== '' ) {
            $time = substr( $start, 0, 5 );
            if ( $end !== '' ) $time .= '–' . substr( $end, 0, 5 );
        }

        $loc       = trim( (string) ( $a->location ?? '' ) );
        $principles = '';
        if ( ! empty( $principle_codes ) ) {
            $shown = array_slice( $principle_codes, 0, 4 );
            $principles = implode( ', ', $shown );
            if ( count( $principle_codes ) > count( $shown ) ) {
                $principles .= ' +' . ( count( $principle_codes ) - count( $shown ) );
            }
        }

        $lines = [ $line1 ];
        if ( $time !== '' )       $lines[] = $time;
        if ( $loc !== '' )        $lines[] = $loc;
        if ( $principles !== '' ) $lines[] = $principles;
        if ( $type !== '' && $line1 !== $type ) $lines[] = $type;
        return implode( "\n", $lines );
    }

    /**
     * Status → style-key prefix. Returns '' for the default (planned),
     * which leaves the day-cell with its default fill.
     */
    private static function cellStyleForStatus( string $status_key ): string {
        switch ( $status_key ) {
            case 'completed':   return 'day_cell_completed';
            case 'in_progress': return 'day_cell_in_progress';
            case 'postponed':   return 'day_cell_postponed';
        }
        return '';
    }

    /**
     * Style map for sheet 1. Style keys are referenced from per-cell
     * `'style' => '<key>'` declarations.
     *
     * @return array<string, array<string,mixed>>
     */
    private static function sheet1Styles(): array {
        $today_border = [
            'all' => [ 'style' => 'medium', 'color' => '1D7874' ],
        ];
        return [
            'title' => [
                'font'      => [ 'bold' => true, 'size' => 14 ],
                'alignment' => [ 'horizontal' => 'center', 'vertical' => 'center' ],
            ],
            'week_header' => [
                'font'      => [ 'bold' => true, 'size' => 11, 'color' => '1D7874' ],
                'fill'      => [ 'color' => 'F0F3F2' ],
                'alignment' => [ 'horizontal' => 'left', 'vertical' => 'center' ],
            ],
            'day_header' => [
                'font'      => [ 'bold' => true, 'size' => 10 ],
                'fill'      => [ 'color' => 'F7F8FA' ],
                'alignment' => [ 'horizontal' => 'center', 'vertical' => 'center' ],
                'borders'   => [ 'all' => [ 'style' => 'thin', 'color' => 'D6DADD' ] ],
            ],
            'day_header_today' => [
                'font'      => [ 'bold' => true, 'size' => 10, 'color' => '1D7874' ],
                'fill'      => [ 'color' => 'E8F5E9' ],
                'alignment' => [ 'horizontal' => 'center', 'vertical' => 'center' ],
                'borders'   => $today_border,
            ],
            'day_cell' => [
                'alignment' => [ 'vertical' => 'top', 'wrap' => true ],
                'borders'   => [ 'all' => [ 'style' => 'thin', 'color' => 'D6DADD' ] ],
            ],
            'day_cell_today' => [
                'alignment' => [ 'vertical' => 'top', 'wrap' => true ],
                'borders'   => $today_border,
            ],
            'day_cell_completed' => [
                'fill'      => [ 'color' => 'CFE7DA' ],
                'alignment' => [ 'vertical' => 'top', 'wrap' => true ],
                'borders'   => [ 'all' => [ 'style' => 'thin', 'color' => 'D6DADD' ] ],
            ],
            'day_cell_completed_today' => [
                'fill'      => [ 'color' => 'CFE7DA' ],
                'alignment' => [ 'vertical' => 'top', 'wrap' => true ],
                'borders'   => $today_border,
            ],
            'day_cell_in_progress' => [
                'fill'      => [ 'color' => 'FFF4D4' ],
                'alignment' => [ 'vertical' => 'top', 'wrap' => true ],
                'borders'   => [ 'all' => [ 'style' => 'thin', 'color' => 'D6DADD' ] ],
            ],
            'day_cell_in_progress_today' => [
                'fill'      => [ 'color' => 'FFF4D4' ],
                'alignment' => [ 'vertical' => 'top', 'wrap' => true ],
                'borders'   => $today_border,
            ],
            'day_cell_postponed' => [
                'fill'      => [ 'color' => 'FDECEA' ],
                'alignment' => [ 'vertical' => 'top', 'wrap' => true ],
                'borders'   => [ 'all' => [ 'style' => 'thin', 'color' => 'D6DADD' ] ],
            ],
            'day_cell_postponed_today' => [
                'fill'      => [ 'color' => 'FDECEA' ],
                'alignment' => [ 'vertical' => 'top', 'wrap' => true ],
                'borders'   => $today_border,
            ],
        ];
    }

    private static function niceDate( string $ymd ): string {
        $ts = strtotime( $ymd );
        return $ts !== false ? (string) date_i18n( get_option( 'date_format', 'Y-m-d' ), $ts ) : $ymd;
    }

    private static function niceDayLabel( string $ymd ): string {
        $ts = strtotime( $ymd );
        if ( $ts === false ) return $ymd;
        // Short day name + numeric day, e.g. "Ma 12" / "Mon 12".
        return (string) date_i18n( 'D j', $ts );
    }

    private static function snapToMonday( string $ymd ): string {
        $ts = strtotime( $ymd );
        if ( $ts === false ) return $ymd;
        // 1 = Monday, 7 = Sunday in ISO. Snap to the Monday of the
        // same ISO week (the day in $ymd or earlier).
        $iso_day = (int) date( 'N', $ts );
        if ( $iso_day === 1 ) return $ymd;
        return (string) date( 'Y-m-d', strtotime( $ymd . ' -' . ( $iso_day - 1 ) . ' days' ) );
    }
}
