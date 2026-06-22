<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;
use TT\Infrastructure\Query\QueryHelpers;

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

    /**
     * #1631 — weekly-layout content toggles (the compose dialog's "Toon
     * per dag" + "Kop"). Defaults: everything on except notes.
     *
     * @var array<string,bool>
     */
    private const DEFAULT_FIELDS = [
        'time'       => true,
        'location'   => true,
        'duration'   => true,
        'match'      => true,
        'theme'      => true,
        'principles' => true,
        'notes'      => false,
        'restdays'   => true,
    ];

    /** @var array<string,bool> */
    private const DEFAULT_HEADER = [
        'academy_name'   => true,
        'generated_date' => true,
    ];

    /** Type-tag fill colour per activity-type key (weekly layout). */
    private const TYPE_TAG_COLORS = [
        'training'   => '#0b3d2e',
        'game'       => '#b32d2e',
        'match'      => '#b32d2e',
        'friendly'   => '#c47f17',
        'tournament' => '#7a4ea3',
        'keeper'     => '#1f6feb',
        'goalkeeper' => '#1f6feb',
    ];

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

        // #1631 — layout: `table` (default landscape, unchanged) or
        // `weekly` (branded portrait). `fields[]` / `header[]` are the
        // content toggles the compose dialog posts; absent → defaults.
        $layout = ( isset( $raw['layout'] ) && (string) $raw['layout'] === 'weekly' ) ? 'weekly' : 'table';

        $fields = self::DEFAULT_FIELDS;
        if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
            $on = array_map( 'sanitize_key', array_map( 'strval', $raw['fields'] ) );
            foreach ( $fields as $k => $_ ) {
                $fields[ $k ] = in_array( $k, $on, true );
            }
        }

        $header = self::DEFAULT_HEADER;
        if ( isset( $raw['header'] ) && is_array( $raw['header'] ) ) {
            $on = array_map( 'sanitize_key', array_map( 'strval', $raw['header'] ) );
            foreach ( $header as $k => $_ ) {
                $header[ $k ] = in_array( $k, $on, true );
            }
        }

        return [
            'team_id'   => $team_id,
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'layout'    => $layout,
            'fields'    => $fields,
            'header'    => $header,
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

        // #1631 — branded portrait weekly layout.
        if ( (string) ( $request->filters['layout'] ?? 'table' ) === 'weekly' ) {
            $fields = (array) ( $request->filters['fields'] ?? self::DEFAULT_FIELDS );
            $header = (array) ( $request->filters['header'] ?? self::DEFAULT_HEADER );

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.id, a.session_date, a.title, a.location, a.activity_type_key,
                        a.start_time, a.end_time, a.opponent, a.home_away, a.kickoff_time, a.notes
                    FROM {$p}tt_activities a
                    WHERE a.club_id = %d AND a.team_id = %d
                      AND a.session_date BETWEEN %s AND %s
                      AND a.activity_status_key <> 'cancelled'
                      AND ( a.archived_at IS NULL OR a.archived_at = '' )
                    ORDER BY a.session_date ASC, a.start_time ASC, a.kickoff_time ASC, a.id ASC",
                $club_id, $team_id, $date_from, $date_to
            ) );
            $rows = is_array( $rows ) ? $rows : [];

            $principles = ! empty( $fields['principles'] )
                ? self::principlesByActivity( array_map( static fn ( $r ): int => (int) $r->id, $rows ) )
                : [];

            $html = self::renderWeeklyHtml(
                $team_name, $date_from, $date_to, $rows, $fields, $header, self::branding(), $principles
            );
            return [
                'html'    => $html,
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ];
        }

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
     * #1631 — branding pulled from tt_config; no hardcoded academy values.
     *
     * @return array{primary:string,secondary:string,name:string,logo:string}
     */
    private static function branding(): array {
        return [
            'primary'   => (string) QueryHelpers::get_config( 'primary_color', '#0b3d2e' ),
            'secondary' => (string) QueryHelpers::get_config( 'secondary_color', '#e8b624' ),
            'name'      => (string) QueryHelpers::get_config( 'academy_name', '' ),
            'logo'      => (string) QueryHelpers::get_config( 'logo_url', '' ),
        ];
    }

    /**
     * #1631 — principle codes per activity (the planner links them via
     * tt_activity_principles, renamed from tt_session_principles in
     * migration 0146). Returns up to a handful of codes per activity.
     *
     * @param int[] $activity_ids
     * @return array<int,string[]>
     */
    private static function principlesByActivity( array $activity_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $activity_ids ), static fn ( $v ): bool => $v > 0 ) ) );
        if ( ! $ids ) return [];
        global $wpdb;
        $p     = $wpdb->prefix;
        $link  = $p . 'tt_activity_principles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $link ) ) !== $link ) {
            return [];
        }
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ap.activity_id, pr.code
               FROM {$link} ap
               JOIN {$p}tt_principles pr ON pr.id = ap.principle_id
              WHERE ap.activity_id IN ($ph)
              ORDER BY ap.sort_order ASC, ap.id ASC",
            ...$ids
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $aid = (int) $r->activity_id;
            if ( ! isset( $out[ $aid ] ) ) $out[ $aid ] = [];
            $code = (string) $r->code;
            if ( $code !== '' && count( $out[ $aid ] ) < 6 ) $out[ $aid ][] = $code;
        }
        return $out;
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

    /**
     * #1631 — branded portrait weekly layout: a vertical week with the
     * weekday strip on the left and the day's activities on the right.
     * DomPDF-friendly (table layout, no flexbox).
     *
     * @param object[]            $rows
     * @param array<string,bool>  $fields
     * @param array<string,bool>  $header
     * @param array{primary:string,secondary:string,name:string,logo:string} $branding
     * @param array<int,string[]> $principles
     */
    private static function renderWeeklyHtml(
        string $team_name, string $date_from, string $date_to, array $rows,
        array $fields, array $header, array $branding, array $principles
    ): string {
        $primary   = self::safeColor( (string) ( $branding['primary'] ?? '' ), '#0b3d2e' );
        $secondary = self::safeColor( (string) ( $branding['secondary'] ?? '' ), '#e8b624' );
        $academy   = (string) ( $branding['name'] ?? '' );
        $logo      = (string) ( $branding['logo'] ?? '' );

        $by_date = [];
        foreach ( $rows as $r ) {
            $d = (string) ( $r->session_date ?? '' );
            if ( $d !== '' ) $by_date[ $d ][] = $r;
        }

        $css = '@page { size: A4 portrait; margin: 11mm; }'
            . 'body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color:#1a1d21; margin:0; line-height:1.35; -webkit-print-color-adjust:exact; print-color-adjust:exact; }'
            . '.tt-brand { background:' . $primary . '; color:#fff; padding:5mm 6mm; }'
            . '.tt-brand-name { font-size:15pt; font-weight:bold; }'
            . '.tt-brand-sub { font-size:10pt; }'
            . '.tt-crest { display:inline-block; width:13mm; height:13mm; border-radius:50%; background:' . $secondary . '; color:' . $primary . '; text-align:center; font-weight:bold; font-size:12pt; line-height:13mm; }'
            . 'table.tt-week { width:100%; border-collapse:collapse; margin-top:4mm; }'
            . 'table.tt-week td { vertical-align:top; padding:0; }'
            . '.tt-day { width:30mm; background:' . self::tint( $primary ) . '; border-left:2.5mm solid ' . $secondary . '; padding:3mm; }'
            . '.tt-day-name { font-weight:bold; }'
            . '.tt-day-date { color:#5b6e75; font-size:9pt; }'
            . '.tt-acts { padding:3mm 4mm; border-bottom:0.3mm solid #e5e7ea; }'
            . '.tt-card + .tt-card { margin-top:2.5mm; padding-top:2.5mm; border-top:0.3mm dashed #e5e7ea; }'
            . '.tt-tag { display:inline-block; padding:0.4mm 2mm; border-radius:1.2mm; color:#fff; font-size:7.5pt; font-weight:bold; text-transform:uppercase; }'
            . '.tt-title { font-weight:bold; }'
            . '.tt-meta { color:#5b6e75; font-size:9pt; }'
            . '.tt-match { color:#b32d2e; font-size:9pt; font-weight:bold; }'
            . '.tt-princ { margin-top:1mm; }'
            . '.tt-pcode { display:inline-block; background:#f0f3f2; color:#1a1d21; border-radius:1mm; padding:0.2mm 1.5mm; font-size:7.5pt; margin-right:1mm; }'
            . '.tt-notes { color:#5b6e75; font-size:8.5pt; font-style:italic; margin-top:1mm; }'
            . '.tt-rest { color:#9aa6ab; font-style:italic; padding:3mm 4mm; border-bottom:0.3mm solid #f0f2f4; }'
            . '.tt-foot { margin-top:6mm; font-size:8pt; color:#9aa6ab; }';

        $crest = $logo !== ''
            ? '<img src="' . esc_url( $logo ) . '" style="height:13mm;" alt="" />'
            : '<span class="tt-crest">' . esc_html( self::initials( $academy !== '' ? $academy : $team_name ) ) . '</span>';

        $title_line = ( ! empty( $header['academy_name'] ) && $academy !== '' )
            ? $academy . ' · ' . $team_name
            : $team_name;
        /* translators: %s: date range, e.g. "23 Jun 2026 – 29 Jun 2026" */
        $sub = sprintf( __( 'Week %s', 'talenttrack' ), self::formatDate( $date_from ) . ' – ' . self::formatDate( $date_to ) );

        $brand = '<div class="tt-brand"><table style="width:100%;"><tr>'
            . '<td style="width:16mm; vertical-align:middle;">' . $crest . '</td>'
            . '<td style="vertical-align:middle;">'
            . '<div class="tt-brand-name">' . esc_html( $title_line !== '' ? $title_line : __( 'Team planning', 'talenttrack' ) ) . '</div>'
            . '<div class="tt-brand-sub">' . esc_html( $sub ) . '</div>'
            . '</td></tr></table></div>';

        $tz     = wp_timezone();
        $cursor = new \DateTime( $date_from . ' 00:00:00', $tz );
        $end    = new \DateTime( $date_to . ' 00:00:00', $tz );
        $body   = '<table class="tt-week">';
        $any    = false;
        $guard  = 0;
        while ( $cursor <= $end && $guard < 400 ) {
            $guard++;
            $dkey = $cursor->format( 'Y-m-d' );
            $ts   = $cursor->getTimestamp();
            $acts = $by_date[ $dkey ] ?? [];

            if ( ! $acts && empty( $fields['restdays'] ) ) {
                $cursor->modify( '+1 day' );
                continue;
            }

            $day_cell = '<td class="tt-day"><div class="tt-day-name">' . esc_html( ucfirst( (string) wp_date( 'l', $ts ) ) ) . '</div>'
                . '<div class="tt-day-date">' . esc_html( (string) wp_date( 'j M', $ts ) ) . '</div></td>';

            if ( ! $acts ) {
                $body .= '<tr>' . $day_cell . '<td class="tt-rest">' . esc_html__( 'Rest day', 'talenttrack' ) . '</td></tr>';
            } else {
                $cards = '';
                foreach ( $acts as $a ) {
                    $cards .= self::weeklyCard( $a, $fields, $principles );
                }
                $body .= '<tr>' . $day_cell . '<td class="tt-acts">' . $cards . '</td></tr>';
            }
            $any = true;
            $cursor->modify( '+1 day' );
        }
        $body .= '</table>';

        if ( ! $any ) {
            $body = '<p style="padding:4mm 6mm;"><em>' . esc_html__( 'No activities scheduled in this range.', 'talenttrack' ) . '</em></p>';
        }

        $foot = '';
        if ( ! empty( $header['generated_date'] ) ) {
            $foot = '<div class="tt-foot">' . esc_html( sprintf(
                /* translators: %s: datetime when the export was generated */
                __( 'Generated %s', 'talenttrack' ),
                ( new \DateTime( 'now', $tz ) )->format( 'Y-m-d H:i' )
            ) ) . '</div>';
        }

        return '<!doctype html><html><head><meta charset="UTF-8"><title>' . esc_html( $team_name )
            . '</title><style>' . $css . '</style></head><body>'
            . $brand . $body . $foot
            . '</body></html>';
    }

    /**
     * @param array<string,bool>  $fields
     * @param array<int,string[]> $principles
     */
    private static function weeklyCard( object $a, array $fields, array $principles ): string {
        $type_key  = (string) ( $a->activity_type_key ?? '' );
        $tag_color = self::safeColor( self::TYPE_TAG_COLORS[ $type_key ] ?? '', '#5b6e75' );
        $type_label = $type_key !== ''
            ? \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'activity_type', $type_key )
            : '';
        if ( $type_label === '' ) {
            $type_label = $type_key !== '' ? ucfirst( str_replace( '_', ' ', $type_key ) ) : __( 'Activity', 'talenttrack' );
        }

        $out  = '<div class="tt-card"><div>';
        $out .= '<span class="tt-tag" style="background:' . esc_attr( $tag_color ) . ';">' . esc_html( $type_label ) . '</span>';
        if ( ! empty( $fields['theme'] ) ) {
            $title = (string) ( $a->title ?? '' );
            if ( $title !== '' ) $out .= ' <span class="tt-title">' . esc_html( $title ) . '</span>';
        }
        $out .= '</div>';

        $meta = [];
        if ( ! empty( $fields['time'] ) ) {
            $t = self::timeRange( (string) ( $a->start_time ?? '' ), (string) ( $a->end_time ?? '' ), (string) ( $a->kickoff_time ?? '' ) );
            if ( $t !== '' ) $meta[] = $t;
        }
        if ( ! empty( $fields['duration'] ) ) {
            $dur = self::durationLabel( (string) ( $a->start_time ?? '' ), (string) ( $a->end_time ?? '' ) );
            if ( $dur !== '' ) $meta[] = $dur;
        }
        if ( ! empty( $fields['location'] ) ) {
            $loc = (string) ( $a->location ?? '' );
            if ( $loc !== '' ) $meta[] = $loc;
        }
        if ( $meta ) $out .= '<div class="tt-meta">' . esc_html( implode( ' · ', $meta ) ) . '</div>';

        if ( ! empty( $fields['match'] ) ) {
            $opp = (string) ( $a->opponent ?? '' );
            if ( $opp !== '' ) {
                $ha       = (string) ( $a->home_away ?? '' );
                $ha_label = $ha === 'home' ? __( 'Home', 'talenttrack' ) : ( $ha === 'away' ? __( 'Away', 'talenttrack' ) : '' );
                /* translators: %s: opponent name */
                $vs = sprintf( __( 'vs %s', 'talenttrack' ), $opp );
                if ( $ha_label !== '' ) $vs .= ' (' . $ha_label . ')';
                $out .= '<div class="tt-match">' . esc_html( $vs ) . '</div>';
            }
        }

        if ( ! empty( $fields['principles'] ) ) {
            $codes = $principles[ (int) ( $a->id ?? 0 ) ] ?? [];
            if ( $codes ) {
                $chips = '';
                foreach ( $codes as $c ) {
                    $chips .= '<span class="tt-pcode">' . esc_html( (string) $c ) . '</span>';
                }
                $out .= '<div class="tt-princ">' . $chips . '</div>';
            }
        }

        if ( ! empty( $fields['notes'] ) ) {
            $notes = trim( (string) ( $a->notes ?? '' ) );
            if ( $notes !== '' ) $out .= '<div class="tt-notes">' . esc_html( $notes ) . '</div>';
        }

        return $out . '</div>';
    }

    private static function safeColor( string $c, string $fallback ): string {
        return preg_match( '/^#[0-9a-fA-F]{6}$/', $c ) ? $c : $fallback;
    }

    /** Blend a hex colour 90% toward white for the day-strip tint. */
    private static function tint( string $hex ): string {
        $hex = ltrim( self::safeColor( $hex, '#0b3d2e' ), '#' );
        $r   = (int) hexdec( substr( $hex, 0, 2 ) );
        $g   = (int) hexdec( substr( $hex, 2, 2 ) );
        $b   = (int) hexdec( substr( $hex, 4, 2 ) );
        $mix = static fn ( int $v ): int => (int) round( $v + ( 255 - $v ) * 0.90 );
        return sprintf( '#%02x%02x%02x', $mix( $r ), $mix( $g ), $mix( $b ) );
    }

    private static function initials( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) return '?';
        $parts = preg_split( '/\s+/', $name ) ?: [];
        $ini   = '';
        foreach ( $parts as $w ) {
            if ( $w === '' ) continue;
            $ini .= strtoupper( mb_substr( $w, 0, 1 ) );
            if ( strlen( $ini ) >= 2 ) break;
        }
        return $ini !== '' ? $ini : strtoupper( mb_substr( $name, 0, 2 ) );
    }

    private static function formatDate( string $ymd ): string {
        $ts = strtotime( $ymd );
        return $ts ? (string) wp_date( 'j M Y', $ts ) : $ymd;
    }

    private static function timeRange( string $start, string $end, string $kickoff ): string {
        $s = ( $start !== '' && $start !== '00:00:00' ) ? substr( $start, 0, 5 ) : '';
        if ( $s === '' && $kickoff !== '' && $kickoff !== '00:00:00' ) $s = substr( $kickoff, 0, 5 );
        if ( $s === '' ) return '';
        $e = ( $end !== '' && $end !== '00:00:00' ) ? substr( $end, 0, 5 ) : '';
        return $e !== '' ? $s . '–' . $e : $s;
    }

    private static function durationLabel( string $start, string $end ): string {
        if ( $start === '' || $end === '' || $start === '00:00:00' || $end === '00:00:00' ) return '';
        $s = strtotime( '1970-01-01 ' . $start );
        $e = strtotime( '1970-01-01 ' . $end );
        if ( ! $s || ! $e || $e <= $s ) return '';
        $mins = (int) round( ( $e - $s ) / 60 );
        if ( $mins >= 60 ) {
            $h = intdiv( $mins, 60 );
            $m = $mins % 60;
            return $m > 0 ? sprintf( '%dh%02d', $h, $m ) : sprintf( '%dh', $h );
        }
        /* translators: %d: duration in minutes */
        return sprintf( __( '%d min', 'talenttrack' ), $mins );
    }
}
