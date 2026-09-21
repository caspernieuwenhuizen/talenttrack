<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerReportLayout (#3874, epic #3871) — the two paper layouts of a player
 * report, and whether a composition fits.
 *
 * One estimate for two readers, as on the team report: the panel draws its
 * page meter from `fit()`, and the PDF exporter applies the shortening `fit()`
 * chose before it renders, so the page count a coach is shown is the page
 * count that prints.
 *
 * Heights are millimetres of printable A4, measured on DomPDF's output of
 * `PlayerReportPdfDocument`. Change a row height or the type scale there and
 * these move with it — `PlayerReportPdfTest` prints full and sparse reports
 * and fails when the estimate and the paper disagree.
 *
 * The one-pager shortens before it gives up: long lists keep their most recent
 * entries (`LIST_KEEP`), and the report says it did. Only then does a
 * composition not fit, and the panel suggests the two-page pack.
 */
final class PlayerReportLayout {

    public const ONE_PAGER = 'A';
    public const PACK      = 'B';

    public const ALL     = [ self::ONE_PAGER, self::PACK ];
    public const DEFAULT = self::ONE_PAGER;

    /** Degradation rung: long lists keep their most recent entries. */
    public const SHORTEN_LISTS = 'shorten_lists';

    /**
     * Rows a list keeps on a shortened one-pager, per block and list key.
     * The most recent first, because every list reaches the report newest
     * first; talking points are already most-urgent first.
     */
    public const LIST_KEEP = [
        'talking_points' => [ 'items' => 4 ],
        'ratings'        => [ 'evaluations' => 3, 'categories' => 6 ],
        'goals'          => [ 'items' => 4 ],
        'matches'        => [ 'items' => 5 ],
        'tests'          => [ 'items' => 6 ],
        'journey'        => [ 'items' => 4 ],
        'injuries'       => [ 'items' => 2 ],
        'behaviour'      => [ 'items' => 3 ],
        'potential'      => [ 'items' => 2 ],
        'thread_notes'   => [ 'items' => 3 ],
    ];

    /** Printable height, mm: A4 minus 12 mm margins top and bottom. */
    private const PORTRAIT_MM = 273.0;

    /**
     * What a page break costs. DomPDF moves a row that does not fit to the
     * next page whole, so each page but the last ends with up to a row of
     * empty paper that a sum of heights cannot see.
     */
    private const BREAK_SLACK_MM = 6.0;

    /**
     * Block heights, mm, measured on DomPDF's output of
     * `PlayerReportPdfDocument` by bisecting the smallest paper that keeps a
     * block on one page, at one and at several rows. `section_base` is a
     * heading and its spacing; `stats` a strip of figures, which keeps its
     * bottom margin (`stats_gap`) only when a table follows it; a talking
     * point after the first carries its separator.
     */
    private const MM = [
        'letterhead'   => 24.3,
        'section_base' => 9.4,
        'line'         => 4.6,
        'stats'        => 12.9,
        'stats_gap'    => 3.2,
        'table_head'   => 5.2,
        'table_gap'    => 2.4,
        'row'          => 4.95,
        'point_first'  => 11.3,
        'point_next'   => 12.9,
        'rule'         => 7.78,
    ];

    /**
     * Written text prints in full and wraps, so a row of it costs a line per
     * line it wraps to. Characters per line are measured on DomPDF with Dutch
     * prose in each column, then rounded down, so a close call estimates a
     * line too many rather than overflowing the page. The 7.5pt evidence line
     * under a talking point is shorter and wider-set than body text.
     */
    private const WRAP_LINE_MM       = 4.6;
    private const WRAP_LINE_SMALL_MM = 4.1;

    /** @var array<string,int> */
    public const CHARS_PER_LINE = [
        'point_text'      => 100,
        'point_evidence'  => 130,
        'eval_notes'      => 60,
        'pdp_actions'     => 115,
        'journey'         => 90,
        'injury_notes'    => 69,
        'behaviour_notes' => 79,
        'thread_notes'    => 69,
    ];

    /** Ruled lines the notes area keeps on a shortened one-pager. */
    public const NOTES_LINES_SHORT = 3;

    public static function isValid( string $layout ): bool {
        return in_array( $layout, self::ALL, true );
    }

    public static function maxPages( string $layout ): int {
        return $layout === self::PACK ? 2 : 1;
    }

    /**
     * Title and one-line description per layout, for the panel's cards.
     *
     * @return array<string,array{title:string, desc:string}>
     */
    public static function labels(): array {
        return [
            self::ONE_PAGER => [
                'title' => __( 'One-pager', 'talenttrack' ),
                'desc'  => __( 'A4, one page. What you bring to a conversation; long lists keep their most recent entries.', 'talenttrack' ),
            ],
            self::PACK      => [
                'title' => __( 'Two-page pack', 'talenttrack' ),
                'desc'  => __( 'A4, up to two pages. Room for every evaluation, match and test in the period.', 'talenttrack' ),
            ],
        ];
    }

    /**
     * Does this composition fit this layout, and at what cost?
     *
     * @param array{data:array<string,array<string,mixed>>} $report a `PlayerReport::forPlayer()` payload.
     * @return array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>}
     */
    public static function fit( array $report, string $layout ): array {
        $layout = self::isValid( $layout ) ? $layout : self::DEFAULT;
        $data   = $report['data'];
        $max    = self::maxPages( $layout );

        $degraded = [];
        $height   = self::height( $data );
        if ( $layout === self::ONE_PAGER && $height > self::PORTRAIT_MM ) {
            $degraded[] = self::SHORTEN_LISTS;
            $height     = self::height( self::degrade( $report, $degraded )['data'] );
        }

        // One page holds the full printable height; once the report breaks,
        // every page gives up the slack a break leaves behind.
        $per_page = $height <= self::PORTRAIT_MM ? self::PORTRAIT_MM : self::PORTRAIT_MM - self::BREAK_SLACK_MM;
        $pages    = (int) max( 1, ceil( $height / $per_page ) );
        $fill     = [];
        $left     = $height;
        for ( $i = 0; $i < $pages; $i++ ) {
            $fill[] = (int) round( min( $left, $per_page ) / $per_page * 100 );
            $left  -= $per_page;
        }

        return [
            'pages'     => $pages,
            'max_pages' => $max,
            'fits'      => $pages <= $max,
            'fill'      => $fill,
            'degraded'  => $degraded,
        ];
    }

    /**
     * Apply the rungs `fit()` chose, so what prints is what was measured.
     * A shortened list carries `shortened` — how many entries it left out —
     * so the printed copy can say so rather than look complete.
     *
     * @param array{data:array<string,array<string,mixed>>} $report
     * @param list<string>                                  $rungs
     * @return array{data:array<string,array<string,mixed>>}
     */
    public static function degrade( array $report, array $rungs ): array {
        if ( ! in_array( self::SHORTEN_LISTS, $rungs, true ) ) return $report;

        // Fewer ruled lines, and the development plan as one line saying where
        // the cycle stands rather than a row per conversation.
        if ( isset( $report['data'][ PlayerReportBlock::NOTES ] ) ) {
            $report['data'][ PlayerReportBlock::NOTES ]['lines'] = self::NOTES_LINES_SHORT;
        }
        if ( isset( $report['data'][ PlayerReportBlock::PDP ] ) ) {
            $report['data'][ PlayerReportBlock::PDP ]['compact'] = true;
        }

        foreach ( self::LIST_KEEP as $block => $lists ) {
            if ( ! isset( $report['data'][ $block ] ) ) continue;
            foreach ( $lists as $key => $keep ) {
                $rows = self::listOf( $report['data'][ $block ], $key );
                if ( count( $rows ) <= $keep ) continue;
                $report['data'][ $block ][ $key ] = array_slice( array_values( $rows ), 0, $keep );
                $report['data'][ $block ]['shortened'][ $key ] = count( $rows ) - $keep;
            }
        }
        return $report;
    }

    /**
     * The printed height. Attendance and playing time print side by side when
     * both are in, so the pair costs the taller of the two.
     *
     * @param array<string,array<string,mixed>> $data
     */
    private static function height( array $data ): float {
        $paired = isset( $data[ PlayerReportBlock::ATTENDANCE ], $data[ PlayerReportBlock::MINUTES ] );

        $mm = 0.0;
        foreach ( $data as $block => $d ) {
            $block = (string) $block;
            if ( $paired && $block === PlayerReportBlock::MINUTES ) continue;
            if ( $paired && $block === PlayerReportBlock::ATTENDANCE ) {
                $mm += max(
                    self::blockHeight( $block, $d ),
                    self::blockHeight( PlayerReportBlock::MINUTES, $data[ PlayerReportBlock::MINUTES ] )
                );
                continue;
            }
            $mm += self::blockHeight( $block, $d );
        }
        return $mm;
    }

    /** @param array<string,mixed> $d */
    private static function blockHeight( string $block, array $d ): float {
        $base = self::MM['section_base'];
        $line = self::MM['line'];
        $head = self::MM['table_head'];
        $row  = self::MM['row'];

        switch ( $block ) {
            case PlayerReportBlock::LETTERHEAD:
                return self::MM['letterhead'];

            case PlayerReportBlock::STATUS:
                return $base + $line * ( self::listOf( $d, 'missing_inputs' ) === [] ? 1 : 2 );

            case PlayerReportBlock::TALKING_POINTS:
                $n = count( self::listOf( $d, 'items' ) );
                $points = $n === 0 ? $line : self::MM['point_first'] + ( $n - 1 ) * self::MM['point_next'];
                foreach ( self::listOf( $d, 'items' ) as $item ) {
                    $points += self::wrapped( $item, 'text', 'point_text' )
                        + self::wrapped( $item, 'evidence', 'point_evidence', self::WRAP_LINE_SMALL_MM );
                }
                return $base + $points + self::shortenedLine( $d );

            case PlayerReportBlock::RATINGS:
                $evals = count( self::listOf( $d, 'evaluations' ) );
                if ( $evals === 0 ) return $base + $line;
                $cats = count( self::listOf( $d, 'categories' ) );
                return $base + self::MM['stats'] + self::MM['stats_gap']
                    + ( $cats > 0 ? $head + $cats * $row + self::MM['table_gap'] : 0.0 )
                    + $head + $evals * $row
                    + self::wrappedRows( $d, 'evaluations', 'notes', 'eval_notes' )
                    + self::shortenedLine( $d );

            case PlayerReportBlock::ATTENDANCE:
                return $base + ( (int) ( $d['activities'] ?? 0 ) === 0 ? $line : self::MM['stats'] );

            case PlayerReportBlock::MINUTES:
                return $base + ( (int) ( $d['apps'] ?? 0 ) === 0 && (int) ( $d['minutes'] ?? 0 ) === 0 ? $line : self::MM['stats'] );

            case PlayerReportBlock::PDP:
                if ( empty( $d['available'] ) || ! is_array( $d['file'] ?? null ) ) return $base + $line;
                $convs = count( self::listOf( $d, 'conversations' ) );
                $table = ! empty( $d['compact'] ) ? $line : $head + $convs * $row;
                $mm    = $base + ( $convs > 0 ? $table : 0.0 );
                if ( trim( (string) ( $d['last_agreed_actions'] ?? '' ) ) !== '' ) {
                    $mm += 2 * $line + self::wrapped( $d, 'last_agreed_actions', 'pdp_actions' );
                }
                if ( is_array( $d['verdict'] ?? null ) ) $mm += $line;
                return $mm;

            case PlayerReportBlock::NOTES:
                return $base + max( 1, (int) ( $d['lines'] ?? 6 ) ) * self::MM['rule'];

            case PlayerReportBlock::GOALS:
            case PlayerReportBlock::MATCHES:
            case PlayerReportBlock::TESTS:
            case PlayerReportBlock::INJURIES:
            case PlayerReportBlock::BEHAVIOUR:
            case PlayerReportBlock::POTENTIAL:
                $n = count( self::listOf( $d, 'items' ) );
                $wrap = 0.0;
                if ( $block === PlayerReportBlock::INJURIES )  $wrap = self::wrappedRows( $d, 'items', 'notes', 'injury_notes' );
                if ( $block === PlayerReportBlock::BEHAVIOUR ) $wrap = self::wrappedRows( $d, 'items', 'notes', 'behaviour_notes' );
                return $base + ( $n === 0 ? $line : $head + $n * $row ) + $wrap + self::shortenedLine( $d );

            case PlayerReportBlock::JOURNEY:
            case PlayerReportBlock::THREAD_NOTES:
                $n    = count( self::listOf( $d, 'items' ) );
                $wrap = $block === PlayerReportBlock::JOURNEY
                    ? self::wrappedRows( $d, 'items', 'summary', 'journey' )
                    : self::wrappedRows( $d, 'items', 'body', 'thread_notes' );
                return $base + ( $n === 0 ? $line : $n * $row ) + $wrap + self::shortenedLine( $d );
        }
        return 0.0;
    }

    /**
     * What a list's written field adds beyond one line per row.
     *
     * @param array<string,mixed> $d
     */
    private static function wrappedRows( array $d, string $list, string $field, string $column ): float {
        $mm = 0.0;
        foreach ( self::listOf( $d, $list ) as $row ) {
            if ( is_array( $row ) ) $mm += self::wrapped( $row, $field, $column );
        }
        return $mm;
    }

    /**
     * What one written field adds beyond its first line: the PDF prints it
     * whole, tags stripped, and wraps it in its column.
     *
     * @param mixed $row
     */
    private static function wrapped( $row, string $field, string $column, float $line_mm = self::WRAP_LINE_MM ): float {
        if ( ! is_array( $row ) ) return 0.0;
        $text = trim( wp_strip_all_tags( (string) ( $row[ $field ] ?? '' ) ) );
        if ( $text === '' ) return 0.0;
        $lines = (int) ceil( mb_strlen( $text ) / self::CHARS_PER_LINE[ $column ] );
        return max( 0, $lines - 1 ) * $line_mm;
    }

    /**
     * The "… 4 more" line a shortened list prints.
     *
     * @param array<string,mixed> $d
     */
    private static function shortenedLine( array $d ): float {
        return is_array( $d['shortened'] ?? null ) && $d['shortened'] !== [] ? self::MM['line'] : 0.0;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<mixed>
     */
    private static function listOf( array $data, string $key ): array {
        $value = $data[ $key ] ?? null;
        return is_array( $value ) ? $value : [];
    }
}
