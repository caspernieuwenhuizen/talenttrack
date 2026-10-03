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
 * ## Pages are laid out, not divided (#4132)
 *
 * A page count used to be the height divided by the page. That holds while
 * everything splits cleanly, and it does not: a table row, a test's stat strip
 * or the landscape footer moves whole to the next sheet when it does not fit
 * at the bottom of one, and the player table repeats its header on every sheet
 * it runs onto. So each section is a list of the pieces DomPDF keeps together,
 * and `paginate()` lays them out the way DomPDF does. The one-pager also keeps a
 * small margin short of the page, so a composition the meter calls full errs
 * on the side of "does not fit" rather than printing a second page.
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

    /** Printable width, mm. */
    private const PORTRAIT_WIDTH_MM  = 186.0;
    private const LANDSCAPE_WIDTH_MM = 273.0;

    /**
     * #4132 — what the one-pager keeps short of the page. Measured: a
     * one-pager the old estimate called exactly full printed a second page.
     * Keeping this much in hand makes a close call read as "does not fit".
     */
    private const SHEET_MARGIN_MM = 1.5;

    /** The empty state's sentence: its 8 mm top margin and one 10 pt line. */
    private const EMPTY_MM = 12.6;

    /**
     * Block heights, mm. `row` is per row / item; `section_base` covers a
     * section's heading and spacing. Measured on DomPDF's output of
     * `TeamMonthlyReportDocument` (smallest paper that still renders one
     * page, block by block, at 10 and 20 players); a table row is its CSS
     * height plus its bottom border.
     */
    private const MM = [
        'letterhead'      => 24.5,
        'coverage'        => 11.2,
        'kpi'             => 25.0,
        // The tiles are a landscape page wide, so their labels do not wrap.
        'kpi_wide'        => 21.9,
        // #4096 — every section opens with the numbered header band.
        'status'          => 22.2,
        'section_base'    => 11.0,
        'bar_row'         => 3.82,
        'attention_item'  => 17.2,
        'change_row'      => 4.6,
        // #4069 — the record as tiles; results beside the ranked scorers,
        // the section as tall as the taller of the two. Each list carries its
        // sub-heading, and the results their H/A legend (`results_base`); the
        // scorers also their column heads, totals row and reconciliation line.
        'match_record'    => 17.7,
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
        'roster_head'     => 5.8,
        'roster_row'      => 5.5,
        'roster_row_mini' => 4.9,
        'roster_row_wide' => 4.9,
        'notes'           => 50.2,
        'quality_row'     => 4.6,
        // #4144 — the footer table as DomPDF prints it with the notes strip
        // the tallest: the strip, its section margin and the cell padding.
        'matrix_footer'   => 42.7,
        // #4133 — attendance and minutes share at the summary level: one
        // strip under the header.
        'level_strip'     => 14.0,
        // #4134 — evaluations: the strip, the category table with its
        // sub-heading and key, the movers side by side, and the grid.
        // #4144 — re-measured piece by piece on every layout: the strip is
        // 15.8 mm tall and the movers 7.35 mm plus 5.25 mm a row, so a sheet
        // that breaks between them breaks where DomPDF does.
        'eval_strip'      => 15.8,
        'eval_cat_base'   => 10.0,
        'eval_cat_row'    => 5.85,
        'eval_sub_row'    => 5.1,
        'eval_movers'     => 7.35,
        'eval_mover_row'  => 5.25,
        'eval_grid_base'  => 17.6,
        'eval_grid_head'  => 4.8,
        'eval_grid_row'   => 5.3,
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
     * The matrix footer's "what changed" strip: its heading and spacing, its
     * section margin and the cell padding (#4144, measured 11.6). The footer
     * is as tall as its tallest strip, and the notes strip sets the floor
     * (`matrix_footer`).
     */
    private const STRIP_BASE_MM = 11.6;

    /**
     * #4144 — the margin under the last section above the landscape footer.
     * The footer table sits below it, so it needs this much more room than
     * its own height to fit on the sheet: DomPDF moved a 62.2 mm footer to a
     * third sheet with 62.9 mm left under the data-quality section.
     */
    private const FOOTER_GAP_MM = 3.0;

    /** Rows the matrix footer's "what changed" strip prints. */
    public const STRIP_CHANGES = 6;

    /**
     * #4118 — a snapshot's note under a section (#3517): its margin and
     * padding, and a line of 7.5pt text. Measured on DomPDF: one line costs
     * 10.0 mm, ten lines 46.6 mm. The base is a millimetre over, so a close
     * call estimates a page too many rather than printing one more than shown.
     */
    private const NOTE_BASE_MM = 7.0;
    private const NOTE_LINE_MM = 4.1;

    /**
     * Characters of a note per printed line at full portrait width (about 116
     * measured over Dutch prose, rounded down like the others).
     */
    private const NOTE_CHARS_PER_LINE = 112;

    /**
     * The same on the landscape matrix, by how many strips share its footer;
     * a section above the footer is one strip wide (about 164, 79, 51 and 44
     * measured).
     *
     * @var array<int,int>
     */
    private const NOTE_STRIP_CHARS_PER_LINE = [ 1 => 158, 2 => 76, 3 => 48, 4 => 42 ];

    /**
     * #4095 — the landscape matrix prints its tests as lines in a footer strip
     * a third of the page wide, so it holds this many tests. The rest are
     * named on the page, never dropped without a word.
     */
    public const MATRIX_MAX_TESTS = 3;

    /** The sections the landscape matrix prints side by side in its footer. */
    public const MATRIX_FOOTER = [ 'matches', 'changes', 'tests', 'notes' ];

    public static function isValid( string $layout ): bool {
        return in_array( $layout, self::ALL, true );
    }

    /**
     * #4133 — can this layout print this section at the Details level?
     *
     * The one place the answer lives (#4095): the composition panel disables
     * Details where a layout cannot print it and says why, the estimate
     * measures the level printed, and the paper prints it — all from here.
     *
     * - Tests and evaluations need the pack for their player tables.
     * - Attendance and minutes share are columns of the landscape matrix's
     *   player table, not sections of their own, so there is no level to pick.
     * - Matches print at both levels everywhere; the landscape strip already
     *   drops the squads, whatever was asked.
     */
    public static function supportsDetails( string $layout, string $block ): bool {
        switch ( $block ) {
            case TeamMonthlyReportBlock::TESTS:
            case TeamMonthlyReportBlock::EVALUATIONS:
                return $layout === self::PACK;
            case TeamMonthlyReportBlock::ATTENDANCE:
            case TeamMonthlyReportBlock::MINUTES:
                return $layout !== self::MATRIX;
        }
        return true;
    }

    /** The level this layout prints a section at: the one asked for, or the summary. */
    public static function levelFor( string $layout, string $block, string $level ): string {
        return $level === SectionLevel::DETAILS && ! self::supportsDetails( $layout, $block ) ? SectionLevel::SUMMARY : $level;
    }

    /** Why this layout cannot print a section's Details, for the panel. Empty when it can. */
    public static function detailsReason( string $layout, string $block ): string {
        if ( self::supportsDetails( $layout, $block ) ) return '';
        if ( in_array( $block, [ TeamMonthlyReportBlock::ATTENDANCE, TeamMonthlyReportBlock::MINUTES ], true ) ) {
            return __( 'Landscape prints it as columns of the player-by-player table', 'talenttrack' );
        }
        return $layout === self::MATRIX
            ? __( 'Needs the pack (up to four pages). Landscape prints the summary.', 'talenttrack' )
            : __( 'Needs the pack (up to four pages). The one-pager prints the summary.', 'talenttrack' );
    }

    /**
     * #4095 — can this layout print this tests `show` value? A player table
     * (readings, change, or both) is the tests section's Details.
     */
    public static function supportsTestsShow( string $layout, string $show ): bool {
        return ! TestsBlockOptions::showsPlayers( $show ) || self::supportsDetails( $layout, TeamMonthlyReportBlock::TESTS );
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
        return self::supportsTestsShow( $layout, $show ) ? '' : self::detailsReason( $layout, TeamMonthlyReportBlock::TESTS );
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
        $tests['show'] = self::testsShowFor( $layout, TestsBlockOptions::storedShow( $tests['show'] ?? null ) );

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
     * #4133 — every section as this layout prints it: tests reduced as
     * above, and the evaluations section at the level the layout can hold,
     * without the grid a summary does not print. Applied before `fit()`
     * measures and before the document renders.
     *
     * @param array<string,array<string,mixed>> $data
     * @return array<string,array<string,mixed>>
     */
    public static function forLayout( array $data, string $layout ): array {
        if ( isset( $data['tests'] ) ) {
            $data['tests'] = self::testsForLayout( $data['tests'], $layout );
        }
        if ( isset( $data['evaluations'] ) ) {
            $level = self::levelFor( $layout, TeamMonthlyReportBlock::EVALUATIONS, (string) ( $data['evaluations']['level'] ?? SectionLevel::SUMMARY ) );
            $data['evaluations']['level'] = $level;
            if ( $level !== SectionLevel::DETAILS ) {
                unset( $data['evaluations']['grid'] );
                if ( isset( $data['evaluations']['categories'] ) && is_array( $data['evaluations']['categories'] ) ) {
                    foreach ( array_keys( $data['evaluations']['categories'] ) as $i ) {
                        if ( is_array( $data['evaluations']['categories'][ $i ] ) ) unset( $data['evaluations']['categories'][ $i ]['subcategories'] );
                    }
                }
                $data['evaluations']['sub'] = false;
            }
        }
        return $data;
    }

    /**
     * #4134 — the width, mm, one category column of the evaluations grid
     * gets, so its header is cut to fit rather than wrapped.
     */
    public static function gridCategoryWidth( int $categories, string $layout ): float {
        $width = $layout === self::MATRIX ? self::LANDSCAPE_WIDTH_MM : self::PORTRAIT_WIDTH_MM;
        // #, player, average, evaluations and last date, with their padding.
        return max( 8.0, ( $width - 95.0 ) / max( 1, $categories ) );
    }

    /**
     * #4096 — the order a layout prints its sections in. The pack moves the
     * roster onto its own page ahead of the meeting pages; the landscape
     * matrix prints the roster as the page and gathers matches, what changed,
     * tests and notes in a footer strip. #4134 — evaluations follow the
     * squad status: development first, then attendance and minutes.
     *
     * @return list<string>
     */
    public static function printOrder( string $layout ): array {
        switch ( $layout ) {
            case self::PACK:
                return [ 'coverage', 'kpi', 'status', 'evaluations', 'attendance', 'minutes', 'roster', 'matches', 'attention', 'changes', 'tests', 'notes', 'quality' ];
            case self::MATRIX:
                return array_merge( self::matrixBody(), self::MATRIX_FOOTER );
        }
        return array_values( array_diff( TeamMonthlyReportBlock::ALL, [ TeamMonthlyReportBlock::LETTERHEAD ] ) );
    }

    /**
     * The sections the landscape matrix prints above its footer strip, in
     * order.
     *
     * @return list<string>
     */
    public static function matrixBody(): array {
        return [ 'coverage', 'kpi', 'status', 'evaluations', 'roster', 'attention', 'quality' ];
    }

    /**
     * #4096 — each ticked section's number, following the sections this
     * layout prints in the order it prints them, so "section 6" is the same
     * section on screen and on paper. #4134 — the pack can move the
     * evaluations to page 2, so where the page groups are known the numbers
     * follow them.
     *
     * @param list<string>       $selected
     * @param list<list<string>> $groups the page groups `fit()` returned.
     * @return array<string,int>
     */
    public static function sectionNumbers( array $selected, string $layout, array $groups = [] ): array {
        $order = $groups !== [] ? array_merge( ...$groups ) : self::printOrder( $layout );
        if ( $layout === self::MATRIX ) $order = self::printOrder( $layout );

        $out = [];
        $n   = 0;
        foreach ( $order as $block ) {
            if ( isset( $out[ $block ] ) ) continue;
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
        // #4097 — the landscape matrix prints attendance and minutes share as
        // columns of its roster, not as sections. Now that the screen shows
        // what prints, the panel has to say so.
        if ( $layout === self::MATRIX && in_array( $block, [ TeamMonthlyReportBlock::ATTENDANCE, TeamMonthlyReportBlock::MINUTES ], true ) ) return 'in_roster';
        return 'full';
    }

    /**
     * Does this composition fit this layout, and at what cost?
     *
     * `groups` (#4092) is the sections each printed page group holds, in
     * print order: one group on the single-sheet layouts, up to four on the
     * pack. The PDF lays its pages out from it, so the estimate and the paper
     * cannot split the report differently. `group_pages` (#4097) is how many
     * sheets each group prints on, so the screen can say where it lands.
     *
     * `$notes` (#4118) are a snapshot's section notes, which print under
     * their sections; a live report has none.
     *
     * @param array{data:array<string,array<string,mixed>>} $report a `TeamMonthlyReport::forTeam()` payload.
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     * @return array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>, groups:list<list<string>>, group_pages:list<int>}
     */
    public static function fit( array $report, string $layout, array $notes = [] ): array {
        $layout  = self::isValid( $layout ) ? $layout : self::DEFAULT;
        if ( self::isEmpty( $report['data'] ) ) {
            return self::fitEmpty( $layout );
        }
        // #4095, #4133 — measure what this layout prints, not what was asked for.
        $data    = self::forLayout( $report['data'], $layout );
        $note_mm = self::noteHeights( $notes, $data, $layout );

        if ( $layout === self::PACK ) {
            return self::fitPack( $data, $note_mm );
        }

        $capacity = $layout === self::MATRIX ? self::LANDSCAPE_MM : self::PORTRAIT_MM;
        $degraded = [];

        $sheet = self::singleSheet( $data, $layout, $degraded, $note_mm, $capacity );
        if ( $sheet['pages'] > 1 && $layout === self::ONE_PAGER ) {
            $degraded[] = self::ELIDE_RANKED;
            $sheet      = self::singleSheet( $data, $layout, $degraded, $note_mm, $capacity );
        }
        if ( $sheet['pages'] > 1 && $layout === self::ONE_PAGER ) {
            $degraded[] = self::TRIM_ATTENTION;
            $sheet      = self::singleSheet( $data, $layout, $degraded, $note_mm, $capacity );
        }

        return [
            'pages'       => $sheet['pages'],
            'max_pages'   => 1,
            'fits'        => $sheet['pages'] === 1,
            'fill'        => [ (int) round( $sheet['mm'] / $capacity * 100 ) ],
            'degraded'    => $degraded,
            'groups'      => self::singleSheetGroups( $data, $layout ),
            'group_pages' => [ $sheet['pages'] ],
        ];
    }

    /**
     * A window with no trainings or matches prints the letterhead and one
     * sentence, on one sheet, whatever the layout and the sections ticked.
     * The estimate and the document both read this one predicate, so the
     * meter and the paper cannot disagree about the empty state.
     *
     * @param array<string,array<string,mixed>> $data a report payload's `data`.
     */
    public static function isEmpty( array $data ): bool {
        return (int) ( ( $data['letterhead'] ?? [] )['activity_count'] ?? 0 ) === 0;
    }

    /**
     * The empty-state sheet: the letterhead and its one sentence.
     *
     * @return array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>, groups:list<list<string>>, group_pages:list<int>}
     */
    private static function fitEmpty( string $layout ): array {
        $capacity = $layout === self::MATRIX ? self::LANDSCAPE_MM : self::PORTRAIT_MM;
        return [
            'pages'       => 1,
            'max_pages'   => $layout === self::PACK ? self::maxPages( self::PACK ) : 1,
            'fits'        => true,
            'fill'        => [ (int) round( ( self::MM['letterhead'] + self::EMPTY_MM ) / $capacity * 100 ) ],
            'degraded'    => [],
            'groups'      => [ [ 'letterhead' ] ],
            'group_pages' => [ 1 ],
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $data
     * @param array<string,float>               $note_mm
     * @return array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>, groups:list<list<string>>, group_pages:list<int>}
     */
    private static function fitPack( array $data, array $note_mm = [] ): array {
        $none = [];

        // #4092 — the pack prints everything that was ticked and grows to a
        // fourth page rather than shortening anything. Page 3 holds the
        // meeting's matches, agenda and changes; the tests, the ruled lines
        // and the data-quality list stay under them while they fit, and
        // otherwise move, whole, to a page 4 of their own. A page that still
        // runs over flows onto a further sheet, as the roster always has, and
        // the meter says so.
        $page_1 = [ 'letterhead', 'coverage', 'kpi', 'status', 'attendance', 'minutes' ];
        $groups = [ $page_1, [ 'roster' ] ];

        // #4134 — the evaluations follow the squad status on page 1 when they
        // fit there; otherwise they sit with the player table on page 2, and
        // take a page of their own when neither holds them. Never cut (#4092).
        if ( isset( $data['evaluations'] ) ) {
            $evals = self::sum( $data, [ 'evaluations' ], self::PACK, $none, $note_mm );
            $with  = [ 'letterhead', 'coverage', 'kpi', 'status', 'evaluations', 'attendance', 'minutes' ];
            if ( self::groupPages( $data, $with, $note_mm ) === 1 ) {
                $groups = [ $with, [ 'roster' ] ];
            } elseif ( self::groupPages( $data, [ 'evaluations', 'roster' ], $note_mm ) === 1 || self::sum( $data, [ 'roster' ], self::PACK, $none, $note_mm ) <= 0.0 ) {
                $groups = [ $page_1, [ 'evaluations', 'roster' ] ];
            } elseif ( $evals > 0.0 ) {
                $groups = [ $page_1, [ 'evaluations' ], [ 'roster' ] ];
            }
        }

        $meeting = [ 'matches', 'attention', 'changes' ];
        $closing = [ 'tests', 'notes', 'quality' ];

        $page_3 = self::sum( $data, $meeting, self::PACK, $none, $note_mm );
        if ( $page_3 <= 0.0 || self::groupPages( $data, array_merge( $meeting, $closing ), $note_mm ) === 1 ) {
            $groups[] = array_merge( $meeting, $closing );
        } else {
            $groups[] = $meeting;
            $groups[] = $closing;
        }

        $fill        = [];
        $fits        = true;
        $pages       = 0;
        $printed     = [];
        $group_pages = [];
        foreach ( $groups as $group ) {
            $mm = self::sum( $data, $group, self::PACK, $none, $note_mm );
            if ( $mm <= 0.0 ) continue; // a page with nothing selected on it is not printed
            $sheets        = self::groupPages( $data, $group, $note_mm );
            $pages        += $sheets;
            $group_pages[] = $sheets;
            $fill[]        = (int) round( $mm / self::PORTRAIT_MM * 100 );
            $printed[]     = array_values( array_filter( $group, static fn( string $b ): bool => isset( $data[ $b ] ) ) );
            if ( $sheets > 1 ) $fits = false;
        }

        return [
            'pages'       => max( 1, $pages ),
            'max_pages'   => self::maxPages( self::PACK ),
            'fits'        => $fits && $pages <= self::maxPages( self::PACK ),
            'fill'        => $fill,
            'degraded'    => [],
            'groups'      => $printed,
            'group_pages' => $group_pages !== [] ? $group_pages : [ 1 ],
        ];
    }

    /**
     * The sheets one pack page group prints on, laid out piece by piece.
     *
     * @param array<string,array<string,mixed>> $data
     * @param list<string>                      $group
     * @param array<string,float>               $note_mm
     */
    private static function groupPages( array $data, array $group, array $note_mm ): int {
        $pieces = [];
        foreach ( $group as $block ) {
            if ( ! isset( $data[ $block ] ) ) continue;
            $pieces = array_merge( $pieces, self::pieces( $block, $data[ $block ], self::PACK, [] ) );
            if ( ( $note_mm[ $block ] ?? 0.0 ) > 0.0 ) $pieces[] = [ $note_mm[ $block ], 0.0 ];
        }
        return $pieces === [] ? 0 : self::paginate( $pieces, self::PORTRAIT_MM );
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
     * A single-sheet layout's height and the sheets it prints on. The
     * landscape matrix's footer strip is one table row, so it moves whole to
     * a further sheet when it does not fit under the player table (#4132).
     *
     * @param array<string,array<string,mixed>> $data
     * @param list<string>                      $degraded
     * @param array<string,float>               $note_mm
     * @return array{mm:float, pages:int}
     */
    private static function singleSheet( array $data, string $layout, array $degraded, array $note_mm, float $capacity ): array {
        $order  = $layout === self::MATRIX ? array_merge( [ 'letterhead' ], self::matrixBody() ) : TeamMonthlyReportBlock::ALL;
        $pieces = [];
        $mm     = 0.0;
        foreach ( $order as $block ) {
            if ( ! isset( $data[ $block ] ) ) continue;
            $block_pieces = self::pieces( $block, $data[ $block ], $layout, $degraded );
            $note         = $note_mm[ $block ] ?? 0.0;
            if ( $note > 0.0 ) $block_pieces[] = [ $note, 0.0 ];
            foreach ( $block_pieces as $piece ) $mm += $piece[0];
            $pieces = array_merge( $pieces, $block_pieces );
        }
        if ( $layout === self::MATRIX ) {
            $footer = self::matrixFooterHeight( $data, $note_mm );
            if ( $footer > 0.0 ) {
                $footer  += $pieces === [] ? 0.0 : self::FOOTER_GAP_MM;
                $pieces[] = [ $footer, 0.0 ];
                $mm      += $footer;
            }
        }
        // #4132 — the one-pager keeps a margin in hand on every sheet: within
        // it of full, it reads as not fitting rather than risk one more sheet.
        $usable = $layout === self::ONE_PAGER ? $capacity - self::SHEET_MARGIN_MM : $capacity;
        return [ 'mm' => $mm, 'pages' => $pieces === [] ? 1 : self::paginate( $pieces, $usable ) ];
    }

    /**
     * #4132 — lay pieces out the way DomPDF does: a piece that does not fit in
     * what is left of a sheet starts the next one, the space under it is lost,
     * and a table whose header repeats pays for it again on the new sheet. A
     * piece taller than a sheet runs across sheets.
     *
     * @param list<array{0:float,1:float}> $pieces height, and the header a new sheet repeats before it.
     */
    private static function paginate( array $pieces, float $capacity ): int {
        $pages = 1;
        $used  = 0.0;
        foreach ( $pieces as [ $height, $repeat ] ) {
            if ( $used > 0.0 && $used + $height > $capacity + 0.01 ) {
                $pages++;
                $used = $repeat;
            }
            $used += $height;
            while ( $used > $capacity + 0.01 ) {
                $pages++;
                $used -= $capacity;
            }
        }
        return $pages;
    }

    /**
     * The landscape matrix's footer: as tall as its tallest strip. The notes
     * set the floor, and a "what changed" strip with long entries can outgrow
     * it; so can a strip with a snapshot's note under it (#4118).
     *
     * @param array<string,array<string,mixed>> $data
     * @param array<string,float>               $note_mm
     */
    private static function matrixFooterHeight( array $data, array $note_mm ): float {
        $in_footer = array_values( array_intersect( self::MATRIX_FOOTER, array_map( 'strval', array_keys( $data ) ) ) );
        $strips    = count( $in_footer );

        $footer = 0.0;
        if ( array_intersect( [ 'changes', 'tests', 'notes' ], $in_footer ) !== [] ) {
            $footer = max( self::MM['matrix_footer'], isset( $data['changes'] ) ? self::changesStripHeight( $data['changes'], $strips ) : 0.0 );
        }

        foreach ( $in_footer as $block ) {
            $note = $note_mm[ $block ] ?? 0.0;
            if ( $note <= 0.0 ) continue;
            $content = $block === 'changes'
                ? self::changesStripHeight( $data['changes'], $strips )
                : ( $block === 'notes' ? self::MM['matrix_footer'] : self::STRIP_CONTENT_MM );
            $footer = max( $footer, $content + $note );
        }
        return $footer;
    }

    /**
     * #4118 — the matches or tests strip of the landscape footer, above a note
     * under it. Measured on DomPDF with three tests, and with four results and
     * six scorers; a strip with less in it is estimated a little tall.
     */
    private const STRIP_CONTENT_MM = 38.0;

    /**
     * @param array<string,array<string,mixed>> $data
     * @param list<string>                      $blocks
     * @param list<string>                      $degraded
     * @param array<string,float>               $note_mm #4118 the snapshot note under each section.
     */
    private static function sum( array $data, array $blocks, string $layout, array $degraded, array $note_mm = [] ): float {
        $mm = 0.0;
        foreach ( $blocks as $block ) {
            if ( ! isset( $data[ $block ] ) ) continue;
            $mm += self::blockHeight( $block, $data[ $block ], $layout, $degraded );
            // The matrix footer's notes are counted with the footer.
            if ( $layout !== self::MATRIX || ! in_array( $block, self::MATRIX_FOOTER, true ) ) {
                $mm += $note_mm[ $block ] ?? 0.0;
            }
        }
        return $mm;
    }

    /**
     * #4118 — how much each snapshot note adds under its section, in mm. A
     * note prints whole and wraps, like the other written text (#3970); a line
     * break in it starts a new line. Only sections the report prints count.
     *
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     * @param array<string,array<string,mixed>>                               $data
     * @return array<string,float>
     */
    private static function noteHeights( array $notes, array $data, string $layout ): array {
        $strips = count( array_intersect( self::MATRIX_FOOTER, array_map( 'strval', array_keys( $data ) ) ) );
        $out    = [];
        foreach ( $notes as $block => $note ) {
            $block = (string) $block;
            if ( $block === TeamMonthlyReportBlock::LETTERHEAD || ! isset( $data[ $block ] ) ) continue;
            $body = str_replace( [ "\r\n", "\r" ], "\n", trim( $note['body'] ) );
            if ( $body === '' ) continue;

            if ( $layout !== self::MATRIX ) {
                $per_line = self::NOTE_CHARS_PER_LINE;
            } elseif ( in_array( $block, self::MATRIX_FOOTER, true ) ) {
                $per_line = self::NOTE_STRIP_CHARS_PER_LINE[ max( 1, min( 4, $strips ) ) ];
            } else {
                $per_line = self::NOTE_STRIP_CHARS_PER_LINE[1];
            }

            $lines = 0;
            foreach ( explode( "\n", $body ) as $paragraph ) {
                $lines += self::lines( mb_strlen( $paragraph ), $per_line );
            }
            $out[ $block ] = self::NOTE_BASE_MM + $lines * self::NOTE_LINE_MM;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $block_data
     * @param list<string>        $degraded
     */
    private static function blockHeight( string $block, array $block_data, string $layout, array $degraded ): float {
        $mm = 0.0;
        foreach ( self::pieces( $block, $block_data, $layout, $degraded ) as $piece ) $mm += $piece[0];
        return $mm;
    }

    /**
     * #4132 — a section as the pieces DomPDF keeps together, in print order:
     * each a height and the header a new sheet repeats before it. A table
     * breaks between its rows, so a list is its heading and then a piece per
     * row; a one-row table (a strip, two columns side by side) is one piece.
     *
     * @param array<string,mixed> $block_data
     * @param list<string>        $degraded
     * @return list<array{0:float,1:float}>
     */
    private static function pieces( string $block, array $block_data, string $layout, array $degraded ): array {
        $base = self::MM['section_base'];
        switch ( $block ) {
            case 'letterhead': return [ [ self::MM['letterhead'], 0.0 ] ];
            case 'coverage':   return [ [ self::MM['coverage'], 0.0 ] ];
            case 'kpi':        return [ [ self::MM[ $layout === self::MATRIX ? 'kpi_wide' : 'kpi' ], 0.0 ] ];
            case 'status':     return [ [ self::MM['status'], 0.0 ] ];
            case 'notes':      return $layout === self::MATRIX ? [] : [ [ self::MM['notes'], 0.0 ] ];

            case 'evaluations':
                return self::evaluationsPieces( $block_data );

            case 'attendance':
            case 'minutes':
                if ( $layout === self::MATRIX ) return [];
                if ( ( $block_data['level'] ?? '' ) === SectionLevel::SUMMARY ) {
                    return [ [ $base + self::MM['level_strip'], 0.0 ] ];
                }
                $rows = self::count( $block_data, 'rows' );
                if ( in_array( self::ELIDE_RANKED, $degraded, true ) ) {
                    $rows = self::elidedRows( $rows );
                }
                return self::rowsAfter( $base, $rows, self::MM['bar_row'] );

            case 'attention':
                $list = self::listOf( $block_data, 'items' );
                // #4144 — nobody flagged still prints a line saying so.
                if ( $list === [] ) return [ [ $base + self::MM['match_note'], 0.0 ] ];
                $omitted = (int) ( $block_data['omitted'] ?? 0 );
                if ( in_array( self::TRIM_ATTENTION, $degraded, true ) ) {
                    $omitted += max( 0, count( $list ) - self::ATTENTION_KEEP );
                    $list     = array_slice( $list, 0, self::ATTENTION_KEEP );
                }
                // The item's fixed height holds two lines of reasons; each
                // further line adds its own.
                $cpl    = self::CHARS_PER_LINE[ $layout === self::MATRIX ? 'attention_wide' : 'attention' ];
                $pieces = [ [ $base, 0.0 ] ];
                foreach ( $list as $item ) {
                    $more     = is_array( $item ) ? max( 0, self::lines( self::attentionFactsLength( $item ), $cpl ) - 2 ) * self::WRAP_LINE_SMALL_MM : 0.0;
                    $pieces[] = [ self::MM['attention_item'] + $more, 0.0 ];
                }
                // #4144 — and "…and N more on the online report" once trimmed.
                if ( $omitted > 0 ) $pieces[] = [ self::MM['match_note'], 0.0 ];
                return $pieces;

            case 'changes':
                if ( $layout === self::MATRIX ) return [];
                // #4144 — so does a month without changes.
                if ( self::listOf( $block_data, 'events' ) === [] ) return [ [ $base + self::MM['match_note'], 0.0 ] ];
                $pieces = [ [ $base, 0.0 ] ];
                foreach ( self::listOf( $block_data, 'events' ) as $event ) {
                    $pieces[] = [ self::lines( self::changeLength( $event ), self::CHARS_PER_LINE['change'] ) * self::MM['change_row'], 0.0 ];
                }
                return $pieces;

            case 'tests':
                return $layout === self::MATRIX ? [] : self::testsPieces( $block_data, $layout );

            case 'matches':
                return $layout === self::MATRIX ? [] : self::matchesPieces( $block_data );

            case 'roster':
                $row = $layout === self::ONE_PAGER
                    ? self::MM['roster_row_mini']
                    : ( $layout === self::MATRIX ? self::MM['roster_row_wide'] : self::MM['roster_row'] );
                // The header is a `thead`, repeated on every sheet it runs onto.
                $pieces = [ [ $base + self::MM['roster_head'], 0.0 ] ];
                for ( $i = 0, $n = self::count( $block_data, 'rows' ); $i < $n; $i++ ) {
                    $pieces[] = [ $row, self::MM['roster_head'] ];
                }
                return $pieces;

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
                return [ [ $base + ( self::qualityLines( $block_data ) + $extra ) * self::MM['quality_row'], 0.0 ] ];
        }
        return [];
    }

    /**
     * A heading and then a piece per row.
     *
     * @return list<array{0:float,1:float}>
     */
    private static function rowsAfter( float $head, int $rows, float $row, float $repeat = 0.0 ): array {
        $pieces = [ [ $head, 0.0 ] ];
        for ( $i = 0; $i < $rows; $i++ ) $pieces[] = [ $row, $repeat ];
        return $pieces;
    }

    /**
     * #4134 — the evaluations section: the strip, the category table (a row
     * per category and, when asked for, per subcategory), the movers side by
     * side, and at the Details level the grid, a row per player plus the
     * squad row, its header repeated on a further sheet.
     *
     * @param array<string,mixed> $e
     * @return list<array{0:float,1:float}>
     */
    private static function evaluationsPieces( array $e ): array {
        $base = self::MM['section_base'];
        if ( (int) ( $e['evaluations'] ?? 0 ) === 0 ) return [ [ $base + self::MM['match_note'], 0.0 ] ];

        $pieces = [ [ $base + self::MM['eval_strip'], 0.0 ] ];
        $cats   = self::listOf( $e, 'categories' );
        if ( $cats !== [] ) {
            $pieces[] = [ self::MM['eval_cat_base'], 0.0 ];
            foreach ( $cats as $cat ) {
                $pieces[] = [ self::MM['eval_cat_row'], 0.0 ];
                foreach ( is_array( $cat ) ? self::listOf( $cat, 'subcategories' ) : [] as $sub ) {
                    $pieces[] = [ self::MM['eval_sub_row'], 0.0 ];
                }
            }
        }
        $movers   = max( 1, count( self::listOf( $e, 'rising' ) ), count( self::listOf( $e, 'falling' ) ) );
        $pieces[] = [ self::MM['eval_movers'] + $movers * self::MM['eval_mover_row'], 0.0 ];

        $grid = is_array( $e['grid'] ?? null ) ? $e['grid'] : null;
        if ( ( $e['level'] ?? '' ) === SectionLevel::DETAILS && $grid !== null ) {
            $pieces[] = [ self::MM['eval_grid_base'], 0.0 ];
            $rows     = count( self::listOf( $grid, 'rows' ) ) + 1;
            for ( $i = 0; $i < $rows; $i++ ) $pieces[] = [ self::MM['eval_grid_row'], self::MM['eval_grid_head'] ];
        }
        return $pieces;
    }

    /**
     * Characters of a match's squad line per printed line (#4069): beside the
     * scorers the results column is half the page, without them the whole.
     * Measured on DomPDF at 6.5pt and rounded down.
     */
    private const SQUAD_CHARS_BESIDE = 48;
    private const SQUAD_CHARS_FULL   = 110;

    /**
     * The match section (#4069): the heading and the record tiles, then the
     * results and the scorers. Side by side they are one table row, kept
     * together as tall as the taller; the results alone break between rows.
     *
     * @param array<string,mixed> $block_data
     * @return list<array{0:float,1:float}>
     */
    private static function matchesPieces( array $block_data ): array {
        $base     = self::MM['section_base'];
        $shows    = is_array( $block_data['shows'] ?? null ) ? $block_data['shows'] : [];
        $fixtures = self::listOf( $block_data, 'fixtures' );
        if ( $fixtures === [] ) return [ [ $base + self::MM['match_note'], 0.0 ] ];

        $record = is_array( $block_data['record'] ?? null ) ? $block_data['record'] : [];
        $head   = $base;
        if ( ! empty( $shows['record'] ) ) {
            $head += self::MM['match_record'];
            if ( (int) ( $record['without_score'] ?? 0 ) > 0 ) $head += self::MM['match_note'];
        }
        $tail = (int) ( $block_data['tournaments_excluded'] ?? 0 ) > 0 ? self::MM['match_note'] : 0.0;

        $beside = ! empty( $shows['scorers'] );
        $rows   = [];
        foreach ( $fixtures as $fixture ) {
            $row = self::MM['match_row'];
            if ( ! empty( $shows['squads'] ) ) {
                // Each squad wraps under its match, so this is the part the
                // one-pager trips on — which is the point of the option
                // defaulting off.
                $squad = self::listOf( is_array( $fixture ) ? $fixture : [], 'squad' );
                if ( $squad !== [] ) {
                    $chars = 0;
                    foreach ( $squad as $player ) {
                        $chars += mb_strlen( is_array( $player ) ? (string) ( $player['name'] ?? '' ) : '' ) + 7;
                    }
                    $row += self::MM['squad_base'] + self::lines( $chars, $beside ? self::SQUAD_CHARS_BESIDE : self::SQUAD_CHARS_FULL ) * self::MM['squad_line'];
                }
            }
            $rows[] = $row;
        }

        if ( $beside ) {
            $results = self::MM['results_base'] + array_sum( $rows );
            $scorers = self::MM['scorers_base'] + self::count( $block_data, 'scorers' ) * self::MM['scorer_row'];
            $pieces  = [ [ $head, 0.0 ], [ max( $results, $scorers ), 0.0 ] ];
        } else {
            $pieces = [ [ $head + self::MM['results_base'], 0.0 ] ];
            foreach ( $rows as $row ) $pieces[] = [ $row, 0.0 ];
        }
        if ( $tail > 0.0 ) $pieces[] = [ $tail, 0.0 ];
        return $pieces;
    }

    /**
     * The tests section (#4069): per test, its card — the heading and the
     * stat strip, kept together — then its ranked readings row by row. A
     * round frozen in a snapshot before the cards existed prints as lines,
     * and is counted as such.
     *
     * @param array<string,mixed> $block_data
     * @return list<array{0:float,1:float}>
     */
    private static function testsPieces( array $block_data, string $layout ): array {
        $base   = self::MM['section_base'];
        $rounds = self::listOf( $block_data, 'rounds' );
        if ( $rounds === [] ) return [ [ $base + self::MM['match_note'], 0.0 ] ];

        $show       = self::testsShowFor( $layout, TestsBlockOptions::storedShow( $block_data['show'] ?? null ) );
        $with_table = TestsBlockOptions::showsPlayers( $show );

        $pieces = [ [ $base, 0.0 ] ];
        foreach ( $rounds as $round ) {
            if ( ! is_array( $round ) ) continue;
            $readings = self::count( $round, 'readings' );
            if ( ! empty( $round['empty'] ) ) {
                $pieces[] = [ self::MM['test_empty'], 0.0 ];
            } elseif ( ! is_array( $round['average'] ?? null ) ) {
                $pieces[] = [ self::MM['test_round'] + ( $with_table && $readings > 0 ? self::MM['roster_head'] + $readings * self::MM['test_reading'] : 0.0 ), 0.0 ];
            } elseif ( $with_table && $readings > 0 ) {
                $pieces[] = [ self::MM['test_card_table'], 0.0 ];
                for ( $i = 0; $i < $readings; $i++ ) $pieces[] = [ self::MM['test_reading'], 0.0 ];
            } else {
                $pieces[] = [ self::MM['test_card'], 0.0 ];
            }
        }
        // #4093 — the "no target" explanation, once per section.
        if ( TeamMonthlyReport::testsHaveTargetlessRound( $block_data ) ) $pieces[] = [ self::MM['match_note'], 0.0 ];
        return $pieces;
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
