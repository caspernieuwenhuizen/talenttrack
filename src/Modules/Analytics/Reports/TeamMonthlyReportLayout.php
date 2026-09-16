<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamMonthlyReportLayout (#3459, epic #3457) — the three paper layouts, which
 * blocks each can carry, and whether a composition fits.
 *
 * ## One estimate, two readers
 *
 * The composition panel shows a page count and a per-page fill; the PDF
 * exporter (#3460) lays the same report onto paper. Those two must agree — a
 * panel that says "fits on one page" above a PDF that prints two is worse than
 * no meter at all. So the estimate lives here, in the domain, and both call it:
 * the panel to draw the meter, the exporter to decide which rungs of the
 * degradation ladder to apply before it renders. Neither measures anything of
 * its own.
 *
 * Heights are millimetres of printable A4 at the report's type scale. They are
 * an estimate, calibrated against the mockup's measured sheets; #3460 owns
 * checking them against DomPDF's real output and adjusting the constants here,
 * where both readers pick the change up.
 *
 * ## The one-pager degrades before it fails
 *
 * In order, per the design decision:
 *
 * 1. ranked lists (attendance, minutes) keep their top 3 and bottom 4 and
 *    elide the middle — the extremes are what a meeting discusses;
 * 2. the attention list keeps its two most urgent players;
 * 3. only then does the composition not fit.
 */
final class TeamMonthlyReportLayout {

    public const ONE_PAGER = 'A';
    public const PACK      = 'B';
    public const MATRIX    = 'C';

    public const ALL     = [ self::ONE_PAGER, self::PACK, self::MATRIX ];
    public const DEFAULT = self::PACK;

    /** Degradation rung: ranked lists keep top 3 + bottom 4. */
    public const ELIDE_RANKED = 'elide_ranked_lists';

    /** Degradation rung: attention keeps its two most urgent players. */
    public const TRIM_ATTENTION = 'trim_attention';

    /** Rows a ranked list keeps once elided: top 3, bottom 4. */
    public const ELIDE_KEEP_TOP = 3;
    public const ELIDE_KEEP_BOTTOM = 4;

    /** Attention items kept once trimmed. */
    public const ATTENTION_KEEP = 2;

    /** Printable height, mm: A4 minus 12 mm margins top and bottom. */
    private const PORTRAIT_MM  = 273.0;
    private const LANDSCAPE_MM = 186.0;

    /** Block heights, mm. `row` is per row / item; `base` covers heading and padding. */
    private const MM = [
        'letterhead'      => 24.0,
        'coverage'        => 11.0,
        'kpi'             => 21.0,
        'status'          => 15.0,
        'section_base'    => 9.0,
        'bar_row'         => 4.4,
        'attention_item'  => 15.0,
        'change_row'      => 4.6,
        'test_session'    => 11.0,
        'roster_row'      => 5.2,
        'roster_row_mini' => 4.4,
        'roster_row_wide' => 6.4,
        'notes'           => 38.0,
        'quality_row'     => 4.6,
        'matrix_footer'   => 42.0,
    ];

    public static function isValid( string $layout ): bool {
        return in_array( $layout, self::ALL, true );
    }

    public static function maxPages( string $layout ): int {
        return $layout === self::PACK ? 3 : 1;
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
                'desc'  => __( 'A4 portrait, one page. A copy for everyone at the table.', 'talenttrack' ),
            ],
            self::PACK      => [
                'title' => __( 'Three-page pack', 'talenttrack' ),
                'desc'  => __( 'A4 portrait, up to three pages. Room for the player-by-player table.', 'talenttrack' ),
            ],
            self::MATRIX    => [
                'title' => __( 'Landscape matrix', 'talenttrack' ),
                'desc'  => __( 'A4 landscape, one page. The whole squad comparable in one read.', 'talenttrack' ),
            ],
        ];
    }

    /**
     * How a block is offered on a layout: `full`, or `compressed` where the
     * one-pager carries a narrower roster rather than refusing it.
     */
    public static function availability( string $layout, string $block ): string {
        if ( $layout === self::ONE_PAGER && $block === TeamMonthlyReportBlock::ROSTER ) return 'compressed';
        return 'full';
    }

    /**
     * Does this composition fit this layout, and at what cost?
     *
     * @param array{data:array<string,array<string,mixed>>} $report a `TeamMonthlyReport::forTeam()` payload.
     * @return array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>}
     */
    public static function fit( array $report, string $layout ): array {
        $layout = self::isValid( $layout ) ? $layout : self::DEFAULT;
        $data   = $report['data'];

        if ( $layout === self::PACK ) {
            return self::fitPack( $data );
        }

        $capacity = $layout === self::MATRIX ? self::LANDSCAPE_MM : self::PORTRAIT_MM;
        $degraded = [];

        $height = self::singleSheetHeight( $data, $layout, $degraded );
        if ( $height > $capacity && $layout === self::ONE_PAGER ) {
            $degraded[] = self::ELIDE_RANKED;
            $height     = self::singleSheetHeight( $data, $layout, $degraded );
        }
        if ( $height > $capacity && $layout === self::ONE_PAGER ) {
            $degraded[] = self::TRIM_ATTENTION;
            $height     = self::singleSheetHeight( $data, $layout, $degraded );
        }

        $fill = (int) round( $height / $capacity * 100 );
        return [
            'pages'     => $height > $capacity ? (int) ceil( $height / $capacity ) : 1,
            'max_pages' => 1,
            'fits'      => $height <= $capacity,
            'fill'      => [ $fill ],
            'degraded'  => $degraded,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $data
     * @return array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>}
     */
    private static function fitPack( array $data ): array {
        $none   = [];
        $page_1 = self::sum( $data, [ 'letterhead', 'coverage', 'kpi', 'status', 'attendance', 'minutes' ], self::PACK, $none );
        $page_2 = self::sum( $data, [ 'roster' ], self::PACK, $none );
        $page_3 = self::sum( $data, [ 'attention', 'changes', 'tests', 'notes', 'quality' ], self::PACK, $none );

        $fill  = [];
        $fits  = true;
        $pages = 0;
        foreach ( [ $page_1, $page_2, $page_3 ] as $mm ) {
            if ( $mm <= 0.0 ) continue; // a page with nothing selected on it is not printed
            $pages += (int) max( 1, ceil( $mm / self::PORTRAIT_MM ) );
            $fill[] = (int) round( $mm / self::PORTRAIT_MM * 100 );
            if ( $mm > self::PORTRAIT_MM ) $fits = false;
        }

        return [
            'pages'     => max( 1, $pages ),
            'max_pages' => 3,
            'fits'      => $fits,
            'fill'      => $fill,
            'degraded'  => [],
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $data
     * @param list<string>                      $degraded
     */
    private static function singleSheetHeight( array $data, string $layout, array $degraded ): float {
        if ( $layout === self::MATRIX ) {
            $mm = self::sum( $data, [ 'letterhead', 'coverage', 'kpi', 'status', 'roster' ], $layout, $degraded );
            foreach ( [ 'changes', 'tests', 'notes' ] as $footer_block ) {
                if ( isset( $data[ $footer_block ] ) ) {
                    return $mm + self::MM['matrix_footer'] + self::sum( $data, [ 'attention', 'quality' ], $layout, $degraded );
                }
            }
            return $mm + self::sum( $data, [ 'attention', 'quality' ], $layout, $degraded );
        }
        return self::sum( $data, TeamMonthlyReportBlock::ALL, $layout, $degraded );
    }

    /**
     * @param array<string,array<string,mixed>> $data
     * @param list<string>                      $blocks
     * @param list<string>                      $degraded
     */
    private static function sum( array $data, array $blocks, string $layout, array $degraded ): float {
        $mm = 0.0;
        foreach ( $blocks as $block ) {
            if ( ! isset( $data[ $block ] ) ) continue;
            $mm += self::blockHeight( $block, $data[ $block ], $layout, $degraded );
        }
        return $mm;
    }

    /**
     * @param array<string,mixed> $block_data
     * @param list<string>        $degraded
     */
    private static function blockHeight( string $block, array $block_data, string $layout, array $degraded ): float {
        $base = self::MM['section_base'];
        switch ( $block ) {
            case 'letterhead': return self::MM['letterhead'];
            case 'coverage':   return self::MM['coverage'];
            case 'kpi':        return self::MM['kpi'];
            case 'status':     return self::MM['status'];
            case 'notes':      return $layout === self::MATRIX ? 0.0 : self::MM['notes'];

            case 'attendance':
            case 'minutes':
                $rows = self::count( $block_data, 'rows' );
                if ( in_array( self::ELIDE_RANKED, $degraded, true ) ) {
                    $rows = self::elidedRows( $rows );
                }
                return $base + $rows * self::MM['bar_row'];

            case 'attention':
                $items = self::count( $block_data, 'items' );
                if ( in_array( self::TRIM_ATTENTION, $degraded, true ) ) {
                    $items = min( $items, self::ATTENTION_KEEP );
                }
                return $base + $items * self::MM['attention_item'];

            case 'changes':
                return $layout === self::MATRIX ? 0.0 : $base + self::count( $block_data, 'events' ) * self::MM['change_row'];

            case 'tests':
                return $layout === self::MATRIX ? 0.0 : $base + self::count( $block_data, 'sessions' ) * self::MM['test_session'];

            case 'roster':
                $row = $layout === self::ONE_PAGER
                    ? self::MM['roster_row_mini']
                    : ( $layout === self::MATRIX ? self::MM['roster_row_wide'] : self::MM['roster_row'] );
                return $base + self::count( $block_data, 'rows' ) * $row;

            case 'quality':
                $lines = count( self::listOf( $block_data, 'activities_without_register' ) )
                    + count( self::listOf( $block_data, 'players_not_evaluated' ) )
                    + count( self::listOf( $block_data, 'players_with_incomplete_status' ) )
                    + 1;
                return $base + min( $lines, 8 ) * self::MM['quality_row'];
        }
        return 0.0;
    }

    /** How many rows an elided ranked list prints: the kept ends plus one "…" row. */
    public static function elidedRows( int $rows ): int {
        $keep = self::ELIDE_KEEP_TOP + self::ELIDE_KEEP_BOTTOM;
        return $rows > $keep + 1 ? $keep + 1 : $rows;
    }

    /** @param array<string,mixed> $data */
    private static function count( array $data, string $key ): int {
        return count( self::listOf( $data, $key ) );
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
