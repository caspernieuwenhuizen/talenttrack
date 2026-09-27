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
 * Heights are millimetres of printable A4, measured on DomPDF's real output of
 * the PDF template. Change the template's row heights or type scale and these
 * constants move with it — `TeamMonthlyReportPdfTest` renders a 20-player squad
 * and fails when the estimate and the paper disagree on the page count.
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

    /**
     * Block heights, mm. `row` is per row / item; `section_base` covers a
     * section's heading and spacing. Measured on DomPDF's output of
     * `TeamMonthlyReportPdfDocument` (smallest paper that still renders one
     * page, block by block, at 10 and 20 players); a table row is its CSS
     * height plus its bottom border.
     */
    private const MM = [
        'letterhead'      => 24.5,
        'coverage'        => 13.2,
        'kpi'             => 25.0,
        // #4096 — every section opens with the numbered header band.
        'status'          => 21.0,
        'section_base'    => 10.0,
        'bar_row'         => 3.85,
        'attention_item'  => 17.2,
        'change_row'      => 4.6,
        // #4069 — the record as tiles; results beside the ranked scorers,
        // the section as tall as the taller of the two. Each list carries its
        // sub-heading, and the results their H/A legend (`results_base`); the
        // scorers also their column heads, totals row and reconciliation line.
        'match_record'    => 17.0,
        'match_row'       => 5.5,
        'results_base'    => 8.5,
        'scorer_row'      => 5.5,
        'scorers_base'    => 18.5,
        'match_note'      => 4.2,
        'squad_line'      => 3.6,
        'squad_base'      => 0.9,
        // #4069 — a test is a card: header and stat strip, and on the full
        // layouts the ranked readings under a taller strip that carries the
        // squad average per round, with column heads and a legend.
        'test_card'       => 25.0,
        'test_card_table' => 38.5,
        'test_reading'    => 5.0,
        'test_empty'      => 9.0,
        'test_round'      => 9.2,
        'roster_head'     => 5.5,
        'roster_row'      => 5.5,
        'roster_row_mini' => 4.7,
        'roster_row_wide' => 4.7,
        'notes'           => 49.0,
        'quality_row'     => 4.6,
        'matrix_footer'   => 42.0,
    ];

    /**
     * #3970 — written text prints whole and wraps, so it costs a line per line
     * it wraps to. Characters per line are measured on DomPDF with Dutch prose
     * in each column and rounded down, so a close call estimates a line too
     * many rather than overflowing: what changed and the data-quality line at
     * full portrait width (about 113 measured), and the 7.5pt reasons under a
     * player who needs a conversation (about 135 portrait, 197 landscape). The
     * matrix footer's strip has its own table, `STRIP_CHARS_PER_LINE`.
     */
    private const WRAP_LINE_MM       = 4.6;
    private const WRAP_LINE_SMALL_MM = 4.2;

    /** @var array<string,int> */
    private const CHARS_PER_LINE = [
        'change'            => 110,
        'attention'         => 130,
        'attention_wide'    => 190,
        'quality'           => 110,
        'quality_wide'      => 155,
    ];

    /**
     * Characters per line of the matrix footer's "what changed" strip, by how
     * many strips share the footer's width. Measured, not divided: a narrow
     * column loses more of each line to word breaks, so four strips hold far
     * less than a quarter of one (about 172, 85, 53 and 38 measured).
     *
     * @var array<int,int>
     */
    private const STRIP_CHARS_PER_LINE = [ 1 => 165, 2 => 80, 3 => 48, 4 => 35 ];

    /**
     * The matrix footer's "what changed" strip: its heading and spacing. The
     * footer is as tall as its tallest strip, and the notes strip sets the
     * floor (`matrix_footer`).
     */
    private const STRIP_BASE_MM = 11.0;

    /** Rows the matrix footer's "what changed" strip prints. */
    public const STRIP_CHANGES = 6;

    /**
     * #4095 — the landscape matrix prints its tests as lines in a footer strip
     * a third of the page wide, so it holds this many tests. The rest are
     * named on the page, never dropped without a word.
     */
    public const MATRIX_MAX_TESTS = 3;

    public static function isValid( string $layout ): bool {
        return in_array( $layout, self::ALL, true );
    }

    /**
     * #4095 — can this layout print this tests `show` value?
     *
     * The one place the answer lives: the composition panel disables what a
     * layout cannot print and says why, and the PDF prints the fallback,
     * both from here. A player table (readings, change, or both) needs the
     * pack; the one-pager and the landscape strip print the summary.
     */
    public static function supportsTestsShow( string $layout, string $show ): bool {
        return ! TestsBlockOptions::showsPlayers( $show ) || $layout === self::PACK;
    }

    /** The `show` value this layout prints: the one asked for, or the summary. */
    public static function testsShowFor( string $layout, string $show ): string {
        return self::supportsTestsShow( $layout, $show ) ? $show : TestsBlockOptions::SHOW_SUMMARY;
    }

    /** How many tests this layout prints; null when it prints every one. */
    public static function maxTests( string $layout ): ?int {
        return $layout === self::MATRIX ? self::MATRIX_MAX_TESTS : null;
    }

    /**
     * Why this layout cannot print this `show` value, for the panel. Empty
     * when it can.
     */
    public static function testsShowReason( string $layout, string $show ): string {
        if ( self::supportsTestsShow( $layout, $show ) ) return '';
        return $layout === self::MATRIX
            ? __( 'Needs the pack (up to four pages). Landscape prints the summary.', 'talenttrack' )
            : __( 'Needs the pack (up to four pages). The one-pager prints the summary.', 'talenttrack' );
    }

    /**
     * #4095 — the tests section as this layout prints it: `show` reduced to
     * what the layout can hold, and on the landscape matrix the tests past
     * `MATRIX_MAX_TESTS` taken off the page and named in `omitted`, so the
     * page can say which ones went.
     *
     * Applied before `fit()` measures and before the document renders, so
     * the estimate, the paper and the panel read the same thing.
     *
     * @param array<string,mixed> $tests the report's `tests` block.
     * @return array<string,mixed>
     */
    public static function testsForLayout( array $tests, string $layout ): array {
        $tests['show'] = self::testsShowFor( $layout, TestsBlockOptions::show( [ 'show' => $tests['show'] ?? null ] ) );

        $max    = self::maxTests( $layout );
        $rounds = self::listOf( $tests, 'rounds' );
        if ( $max !== null && count( $rounds ) > $max ) {
            $omitted = [];
            foreach ( array_slice( $rounds, $max ) as $round ) {
                $omitted[] = is_array( $round ) ? (string) ( $round['name'] ?? '' ) : '';
            }
            $tests['rounds']  = array_values( array_slice( $rounds, 0, $max ) );
            $tests['omitted'] = $omitted;
        }

        return $tests;
    }

    /**
     * #4096 — the order a layout prints its sections in. The pack moves the
     * roster onto its own page ahead of the meeting pages; the landscape
     * matrix prints the roster as the page and gathers matches, what changed,
     * tests and notes in a footer strip.
     *
     * @return list<string>
     */
    public static function printOrder( string $layout ): array {
        switch ( $layout ) {
            case self::PACK:
                return [ 'coverage', 'kpi', 'status', 'attendance', 'minutes', 'roster', 'matches', 'attention', 'changes', 'tests', 'notes', 'quality' ];
            case self::MATRIX:
                return [ 'coverage', 'kpi', 'status', 'roster', 'attention', 'quality', 'matches', 'changes', 'tests', 'notes' ];
        }
        return array_values( array_diff( TeamMonthlyReportBlock::ALL, [ TeamMonthlyReportBlock::LETTERHEAD ] ) );
    }

    /**
     * #4096 — each ticked section's number, following the sections this
     * layout prints in the order it prints them, so "section 6" is the same
     * section on screen and on paper.
     *
     * @param list<string> $selected
     * @return array<string,int>
     */
    public static function sectionNumbers( array $selected, string $layout ): array {
        $out = [];
        $n   = 0;
        foreach ( self::printOrder( $layout ) as $block ) {
            if ( ! in_array( $block, TeamMonthlyReportBlock::HEADED, true ) || ! in_array( $block, $selected, true ) ) continue;
            $out[ $block ] = ++$n;
        }
        return $out;
    }

    public static function maxPages( string $layout ): int {
        // #4092 — the pack grows to a fourth page rather than shortening.
        return $layout === self::PACK ? 4 : 1;
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
                'title' => __( 'Pack (up to four pages)', 'talenttrack' ),
                'desc'  => __( 'A4 portrait, up to four pages. Room for the player-by-player table and every test reading.', 'talenttrack' ),
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
     * `groups` (#4092) is the sections each printed page group holds, in
     * print order: one group on the single-sheet layouts, up to four on the
     * pack. The PDF lays its pages out from it, so the estimate and the paper
     * cannot split the report differently.
     *
     * @param array{data:array<string,array<string,mixed>>} $report a `TeamMonthlyReport::forTeam()` payload.
     * @return array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>, groups:list<list<string>>}
     */
    public static function fit( array $report, string $layout ): array {
        $layout = self::isValid( $layout ) ? $layout : self::DEFAULT;
        $data   = $report['data'];
        if ( isset( $data['tests'] ) ) {
            // #4095 — measure what this layout prints, not what was asked for.
            $data['tests'] = self::testsForLayout( $data['tests'], $layout );
        }

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
            'groups'    => self::singleSheetGroups( $data, $layout ),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $data
     * @return array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>, groups:list<list<string>>}
     */
    private static function fitPack( array $data ): array {
        $none = [];

        // #4092 — the pack prints everything that was ticked and grows to a
        // fourth page rather than shortening anything. Page 3 holds the
        // meeting's matches, agenda and changes; the tests, the ruled lines
        // and the data-quality list stay under them while they fit, and
        // otherwise move, whole, to a page 4 of their own. A page that still
        // runs over flows onto a further sheet, as the roster always has, and
        // the meter says so.
        $groups = [
            [ 'letterhead', 'coverage', 'kpi', 'status', 'attendance', 'minutes' ],
            [ 'roster' ],
        ];
        $meeting = [ 'matches', 'attention', 'changes' ];
        $closing = [ 'tests', 'notes', 'quality' ];

        $page_3 = self::sum( $data, $meeting, self::PACK, $none );
        $page_4 = self::sum( $data, $closing, self::PACK, $none );
        if ( $page_3 <= 0.0 || $page_3 + $page_4 <= self::PORTRAIT_MM ) {
            $groups[] = array_merge( $meeting, $closing );
        } else {
            $groups[] = $meeting;
            $groups[] = $closing;
        }

        $fill    = [];
        $fits    = true;
        $pages   = 0;
        $printed = [];
        foreach ( $groups as $group ) {
            $mm = self::sum( $data, $group, self::PACK, $none );
            if ( $mm <= 0.0 ) continue; // a page with nothing selected on it is not printed
            $pages    += (int) max( 1, ceil( $mm / self::PORTRAIT_MM ) );
            $fill[]    = (int) round( $mm / self::PORTRAIT_MM * 100 );
            $printed[] = array_values( array_filter( $group, static fn( string $b ): bool => isset( $data[ $b ] ) ) );
            if ( $mm > self::PORTRAIT_MM ) $fits = false;
        }

        return [
            'pages'     => max( 1, $pages ),
            'max_pages' => self::maxPages( self::PACK ),
            'fits'      => $fits,
            'fill'      => $fill,
            'degraded'  => [],
            'groups'    => $printed,
        ];
    }

    /**
     * The sections a single-sheet layout prints, in its print order, as its
     * one page group.
     *
     * @param array<string,array<string,mixed>> $data
     * @return list<list<string>>
     */
    private static function singleSheetGroups( array $data, string $layout ): array {
        $blocks = [];
        foreach ( array_merge( [ 'letterhead' ], self::printOrder( $layout ) ) as $block ) {
            if ( isset( $data[ $block ] ) ) $blocks[] = $block;
        }
        return [ $blocks ];
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
                    // As tall as its tallest strip: the notes set the floor, and
                    // a "what changed" strip with long entries can outgrow it.
                    $strips = count( array_intersect( [ 'matches', 'changes', 'tests', 'notes' ], array_map( 'strval', array_keys( $data ) ) ) );
                    $footer = max( self::MM['matrix_footer'], isset( $data['changes'] ) ? self::changesStripHeight( $data['changes'], $strips ) : 0.0 );
                    return $mm + $footer + self::sum( $data, [ 'attention', 'quality' ], $layout, $degraded );
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
                $list = self::listOf( $block_data, 'items' );
                if ( in_array( self::TRIM_ATTENTION, $degraded, true ) ) {
                    $items = min( $items, self::ATTENTION_KEEP );
                    $list  = array_slice( $list, 0, self::ATTENTION_KEEP );
                }
                // The item's fixed height holds two lines of reasons; each
                // further line adds its own.
                $cpl  = self::CHARS_PER_LINE[ $layout === self::MATRIX ? 'attention_wide' : 'attention' ];
                $more = 0.0;
                foreach ( $list as $item ) {
                    if ( ! is_array( $item ) ) continue;
                    $more += max( 0, self::lines( self::attentionFactsLength( $item ), $cpl ) - 2 ) * self::WRAP_LINE_SMALL_MM;
                }
                return $base + $items * self::MM['attention_item'] + $more;

            case 'changes':
                if ( $layout === self::MATRIX ) return 0.0;
                $mm = $base;
                foreach ( self::listOf( $block_data, 'events' ) as $event ) {
                    $mm += self::lines( self::changeLength( $event ), self::CHARS_PER_LINE['change'] ) * self::MM['change_row'];
                }
                return $mm;

            case 'tests':
                return $layout === self::MATRIX ? 0.0 : $base + self::testsHeight( $block_data, $layout, $degraded );

            case 'matches':
                return $layout === self::MATRIX ? 0.0 : $base + self::matchesHeight( $block_data );

            case 'roster':
                $row = $layout === self::ONE_PAGER
                    ? self::MM['roster_row_mini']
                    : ( $layout === self::MATRIX ? self::MM['roster_row_wide'] : self::MM['roster_row'] );
                return $base + self::MM['roster_head'] + self::count( $block_data, 'rows' ) * $row;

            case 'quality':
                // The players-without-an-evaluation line names every one of
                // them, so it is the line that wraps.
                $names = self::listOf( $block_data, 'players_not_evaluated' );
                $extra = 0;
                if ( $names !== [] ) {
                    $len = self::QUALITY_SENTENCE_CHARS;
                    foreach ( $names as $p ) $len += mb_strlen( is_array( $p ) ? (string) ( $p['name'] ?? '' ) : '' ) + 2;
                    $extra = self::lines( $len, self::CHARS_PER_LINE[ $layout === self::MATRIX ? 'quality_wide' : 'quality' ] ) - 1;
                }
                return $base + ( self::qualityLines( $block_data ) + $extra ) * self::MM['quality_row'];
        }
        return 0.0;
    }

    /**
     * Characters of a match's squad line per printed line (#4069): beside the
     * scorers the results column is half the page, without them the whole.
     * Measured on DomPDF at 6.5pt and rounded down.
     */
    private const SQUAD_CHARS_BESIDE = 48;
    private const SQUAD_CHARS_FULL   = 110;

    /**
     * The match section under its heading (#4069): the record tiles, then the
     * results and the scorers side by side, as tall as the taller.
     *
     * @param array<string,mixed> $block_data
     */
    private static function matchesHeight( array $block_data ): float {
        $shows    = is_array( $block_data['shows'] ?? null ) ? $block_data['shows'] : [];
        $fixtures = self::listOf( $block_data, 'fixtures' );
        if ( $fixtures === [] ) return self::MM['match_note'];

        $record = is_array( $block_data['record'] ?? null ) ? $block_data['record'] : [];
        $mm     = 0.0;
        if ( ! empty( $shows['record'] ) ) {
            $mm += self::MM['match_record'];
            if ( (int) ( $record['without_score'] ?? 0 ) > 0 ) $mm += self::MM['match_note'];
        }
        if ( (int) ( $block_data['tournaments_excluded'] ?? 0 ) > 0 ) $mm += self::MM['match_note'];

        $beside  = ! empty( $shows['scorers'] );
        $results = self::MM['results_base'] + count( $fixtures ) * self::MM['match_row'];
        if ( ! empty( $shows['squads'] ) ) {
            // Each squad wraps under its match, so this is the part the
            // one-pager trips on — which is the point of the option
            // defaulting off.
            $per_line = $beside ? self::SQUAD_CHARS_BESIDE : self::SQUAD_CHARS_FULL;
            foreach ( $fixtures as $fixture ) {
                $squad = self::listOf( is_array( $fixture ) ? $fixture : [], 'squad' );
                if ( $squad === [] ) continue;
                $chars = 0;
                foreach ( $squad as $player ) {
                    $chars += mb_strlen( is_array( $player ) ? (string) ( $player['name'] ?? '' ) : '' ) + 7;
                }
                $results += self::MM['squad_base'] + self::lines( $chars, $per_line ) * self::MM['squad_line'];
            }
        }

        $scorers = $beside
            ? self::MM['scorers_base'] + self::count( $block_data, 'scorers' ) * self::MM['scorer_row']
            : 0.0;

        return $mm + max( $results, $scorers );
    }

    /**
     * The tests section under its heading (#4069). A round frozen in a
     * snapshot before the cards existed prints as lines, and is counted as
     * such.
     *
     * @param array<string,mixed> $block_data
     * @param list<string>        $degraded
     */
    private static function testsHeight( array $block_data, string $layout, array $degraded ): float {
        $rounds = self::listOf( $block_data, 'rounds' );
        if ( $rounds === [] ) return self::MM['match_note'];

        $show       = self::testsShowFor( $layout, TestsBlockOptions::show( [ 'show' => $block_data['show'] ?? null ] ) );
        $with_table = TestsBlockOptions::showsPlayers( $show );

        $mm = 0.0;
        foreach ( $rounds as $round ) {
            if ( ! is_array( $round ) ) continue;
            $readings = self::count( $round, 'readings' );
            if ( ! empty( $round['empty'] ) ) {
                $mm += self::MM['test_empty'];
            } elseif ( ! is_array( $round['average'] ?? null ) ) {
                $mm += self::MM['test_round'] + ( $with_table && $readings > 0 ? self::MM['roster_head'] + $readings * self::MM['test_reading'] : 0.0 );
            } elseif ( $with_table && $readings > 0 ) {
                $mm += self::MM['test_card_table'] + $readings * self::MM['test_reading'];
            } else {
                $mm += self::MM['test_card'];
            }
        }
        // #4093 — the "no target" explanation, once per section.
        if ( TeamMonthlyReport::testsHaveTargetlessRound( $block_data ) ) $mm += self::MM['match_note'];
        return $mm;
    }

    /** The words around the names in "N players have no evaluation this period: …". */
    private const QUALITY_SENTENCE_CHARS = 50;

    /**
     * The printed "what changed" line: short date, name, a dash, the summary.
     *
     * @param mixed $event
     */
    private static function changeLength( $event ): int {
        if ( ! is_array( $event ) ) return 0;
        return 10 + mb_strlen( (string) ( $event['name'] ?? '' ) ) + mb_strlen( (string) ( $event['summary'] ?? '' ) );
    }

    /**
     * The printed reasons under a player who needs a conversation: their
     * attendance, then each reason, joined with a separator.
     *
     * @param array<string,mixed> $item
     */
    private static function attentionFactsLength( array $item ): int {
        $len = isset( $item['attendance_pct'] ) ? 20 : 0;
        foreach ( self::listOf( $item, 'reasons' ) as $reason ) $len += mb_strlen( (string) $reason ) + 3;
        return $len;
    }

    /**
     * The matrix footer's "what changed" strip, which prints its first
     * `STRIP_CHANGES` entries. The footer's strips share the page's width, so
     * the strip is as narrow as there are strips beside it.
     *
     * @param array<string,mixed> $block_data
     */
    private static function changesStripHeight( array $block_data, int $strips ): float {
        $per_line = self::STRIP_CHARS_PER_LINE[ max( 1, min( 4, $strips ) ) ];
        $mm       = self::STRIP_BASE_MM;
        foreach ( array_slice( self::listOf( $block_data, 'events' ), 0, self::STRIP_CHANGES ) as $event ) {
            $mm += self::lines( self::changeLength( $event ), $per_line ) * self::WRAP_LINE_MM;
        }
        return $mm;
    }

    /** Lines a text of this length wraps to, one at least. */
    private static function lines( int $chars, int $per_line ): int {
        return max( 1, (int) ceil( $chars / max( 1, $per_line ) ) );
    }

    /**
     * How many lines the data-quality block prints: one per kind of gap, or a
     * single "nothing missing" line. Public because the PDF template prints
     * exactly these lines, and the estimate must count the same thing.
     *
     * @param array<string,mixed> $block_data
     */
    public static function qualityLines( array $block_data ): int {
        $lines = 0;
        if ( self::listOf( $block_data, 'activities_without_register' ) !== [] ) $lines++;
        if ( self::listOf( $block_data, 'activities_never_closed' ) !== [] ) $lines++;
        if ( (int) ( $block_data['matches_without_minutes'] ?? 0 ) > 0 ) $lines++;
        if ( self::listOf( $block_data, 'players_not_evaluated' ) !== [] ) $lines++;
        if ( self::listOf( $block_data, 'players_with_incomplete_status' ) !== [] ) $lines++;
        return max( 1, $lines );
    }

    /**
     * Apply the rungs `fit()` chose to the report's data, so what prints is
     * what was measured.
     *
     * Elided ranked lists keep their first `ELIDE_KEEP_TOP` and last
     * `ELIDE_KEEP_BOTTOM` rows, with one marker row between recording how many
     * players were left out and the range their values spanned — "… 7 players
     * between 75% and 94% …". The attention list keeps its most urgent items,
     * which the composer already orders first.
     *
     * @param array{data:array<string,array<string,mixed>>} $report
     * @param list<string>                                  $rungs
     * @return array{data:array<string,array<string,mixed>>}
     */
    public static function degrade( array $report, array $rungs ): array {
        if ( in_array( self::ELIDE_RANKED, $rungs, true ) ) {
            foreach ( [ 'attendance' => 'present_pct', 'minutes' => 'share_pct' ] as $block => $value_key ) {
                if ( ! isset( $report['data'][ $block ] ) ) continue;
                $rows = self::listOf( $report['data'][ $block ], 'rows' );
                $report['data'][ $block ]['rows'] = self::elide( array_values( $rows ), $value_key );
            }
        }
        if ( in_array( self::TRIM_ATTENTION, $rungs, true ) && isset( $report['data']['attention'] ) ) {
            $items = self::listOf( $report['data']['attention'], 'items' );
            $report['data']['attention']['items']   = array_slice( array_values( $items ), 0, self::ATTENTION_KEEP );
            $report['data']['attention']['omitted'] = max( 0, count( $items ) - self::ATTENTION_KEEP );
        }
        return $report;
    }

    /**
     * @param list<mixed> $rows
     * @return list<mixed>
     */
    private static function elide( array $rows, string $value_key ): array {
        $keep = self::ELIDE_KEEP_TOP + self::ELIDE_KEEP_BOTTOM;
        if ( count( $rows ) <= $keep + 1 ) return $rows;

        $middle = array_slice( $rows, self::ELIDE_KEEP_TOP, count( $rows ) - $keep );
        $values = [];
        foreach ( $middle as $r ) {
            if ( is_array( $r ) && isset( $r[ $value_key ] ) && ( is_int( $r[ $value_key ] ) || is_float( $r[ $value_key ] ) ) ) {
                $values[] = (float) $r[ $value_key ];
            }
        }

        return array_merge(
            array_slice( $rows, 0, self::ELIDE_KEEP_TOP ),
            [ [
                'elided' => count( $middle ),
                'min'    => $values !== [] ? min( $values ) : null,
                'max'    => $values !== [] ? max( $values ) : null,
            ] ],
            array_slice( $rows, - self::ELIDE_KEEP_BOTTOM )
        );
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
