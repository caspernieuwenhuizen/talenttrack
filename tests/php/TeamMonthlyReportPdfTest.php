<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter;

/**
 * #3460 (epic #3457) — the team monthly report on paper.
 *
 * Pinned: the template is DomPDF-safe (no flexbox, no grid); the page count
 * the composition panel promises is the page count DomPDF prints, for a
 * 20-player squad in every layout; the one-pager prints the shortened lists it
 * measured; the confidential footer is on the page; and a user who cannot read
 * the team's reports is refused rather than handed an empty PDF.
 */
final class TeamMonthlyReportPdfTest extends WP_UnitTestCase {

    public function test_exporter_is_registered_for_pdf_only(): void {
        $exporter = ExporterRegistry::get( 'team_monthly_report_pdf' );
        $this->assertInstanceOf( TeamMonthlyReportPdfExporter::class, $exporter );
        $this->assertSame( [ 'pdf' ], $exporter->supportedFormats() );
    }

    public function test_filters_need_a_team_and_fall_back_to_last_month(): void {
        $exporter = new TeamMonthlyReportPdfExporter();
        $this->assertNull( $exporter->validateFilters( [] ) );
        $this->assertNull( $exporter->validateFilters( [ 'team_id' => 4, 'period' => 'next_decade' ] ) );
        $this->assertNull( $exporter->validateFilters( [ 'team_id' => 4, 'from' => '2026-08-31', 'to' => '2026-08-01' ] ) );

        $clean = $exporter->validateFilters( [ 'team_id' => '4', 'from' => '2026-08-01', 'to' => '2026-08-31', 'layout' => 'c', 'blocks' => 'kpi,bogus,roster' ] );
        $this->assertIsArray( $clean );
        $this->assertSame( 'C', $clean['layout'] );
        $this->assertSame( [ 'kpi', 'roster' ], $clean['blocks'], 'Unknown blocks drop, as they do on screen.' );

        $default = $exporter->validateFilters( [ 'team_id' => 4 ] );
        $this->assertIsArray( $default );
        $this->assertSame( TeamMonthlyReportLayout::DEFAULT, $default['layout'] );
        $this->assertStringEndsWith( '-01', $default['from'] );
    }

    public function test_a_user_without_the_team_is_refused(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Pdf U13', 'age_group' => 'U13' ] );
        $team_id = (int) $wpdb->insert_id;
        $uid     = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $request = new ExportRequest( 'team_monthly_report_pdf', 'pdf', 1, $uid, null, [
            'team_id' => $team_id, 'from' => '2026-08-01', 'to' => '2026-08-31', 'layout' => 'A', 'blocks' => [],
        ] );

        try {
            ( new TeamMonthlyReportPdfExporter() )->collect( $request );
            $this->fail( 'A user who cannot read the team must not get a PDF.' );
        } catch ( ExportException $e ) {
            $this->assertSame( 'forbidden', $e->errorKey );
        }
    }

    public function test_the_template_uses_no_layout_dompdf_cannot_print(): void {
        foreach ( TeamMonthlyReportLayout::ALL as $layout ) {
            $html = TeamMonthlyReportPdfExporter::payload( $this->report( 20 ), $layout, 'Pdf U13' )['html'];
            $flat = strtolower( (string) preg_replace( '/\s+/', '', $html ) );
            $this->assertStringNotContainsString( 'display:flex', $flat, "Layout {$layout} uses flexbox." );
            $this->assertStringNotContainsString( 'display:grid', $flat, "Layout {$layout} uses grid." );
            $this->assertStringContainsString( 'Confidential', $html, "Layout {$layout} lost its confidential footer." );
        }
    }

    public function test_the_matrix_prints_landscape(): void {
        $this->assertSame( 'landscape', TeamMonthlyReportPdfExporter::payload( $this->report( 20 ), 'C', 'x' )['options']['orientation'] );
        $this->assertSame( 'portrait', TeamMonthlyReportPdfExporter::payload( $this->report( 20 ), 'B', 'x' )['options']['orientation'] );
    }

    public function test_the_one_pager_prints_the_lists_it_measured(): void {
        $report = $this->only( $this->report( 20 ), [ 'coverage', 'kpi', 'status', 'attendance', 'minutes', 'attention', 'notes' ] );
        $fit    = TeamMonthlyReportLayout::fit( $report, 'A' );
        $this->assertContains( TeamMonthlyReportLayout::ELIDE_RANKED, $fit['degraded'], 'Precondition: 20 players need shortening.' );

        $html = TeamMonthlyReportPdfExporter::payload( $report, 'A', 'x' )['html'];
        $this->assertStringContainsString( '13 players between', $html );
        $this->assertStringNotContainsString( 'Player 10<', $html, 'The middle of the ranked list is left out.' );
    }

    /**
     * The promise the panel makes. Rendered with the real DomPDF, so a change
     * to the template's row heights that the estimate does not follow fails
     * here rather than on a coach's printer.
     */
    public function test_the_printed_page_count_is_the_estimated_one_for_a_twenty_player_squad(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }

        $cases = [
            'one-pager, all sections'  => [ 'A', null ],
            'one-pager, the dashboard' => [ 'A', [ 'coverage', 'kpi', 'status', 'attendance', 'attention', 'quality' ] ],
            'pack, all sections'       => [ 'B', null ],
            'pack, no roster'          => [ 'B', [ 'coverage', 'kpi', 'status', 'attendance', 'minutes', 'attention', 'notes' ] ],
            'matrix, the squad'        => [ 'C', [ 'kpi', 'roster' ] ],
            'matrix, all sections'     => [ 'C', null ],
            // #3970 — written text prints in full, and the estimate counts
            // the lines it wraps to.
            'one-pager, long text'     => [ 'A', [ 'coverage', 'kpi', 'status', 'attention', 'changes', 'quality' ], true ],
            'pack, long text'          => [ 'B', null, true ],
            'matrix, long text'        => [ 'C', [ 'kpi', 'attention', 'changes', 'tests', 'notes', 'quality' ], true ],
            // #4069 — the richer matches and tests sections, 18 players and
            // three tests: the pack degrades its third page before counting.
            'pack, matches and 1 test' => [ 'B', null, false, 1 ],
            'pack, matches and 3 tests' => [ 'B', null, false, 3 ],
            'pack, no roster, 3 tests' => [ 'B', [ 'coverage', 'kpi', 'status', 'attendance', 'minutes', 'matches', 'tests' ], false, 3 ],
            'one-pager, matches, tests' => [ 'A', [ 'kpi', 'matches', 'tests' ], false, 3 ],
            // #4095 — landscape keeps three tests and names the other two.
            'matrix, five tests'       => [ 'C', null, false, 5 ],
            // #4092 — the pack grows to a fourth page instead of shortening,
            // and past it onto further sheets rather than dropping anything.
            'pack, four pages'         => [ 'B', null, false, 3, 5 ],
            'pack, page 4 runs over'   => [ 'B', null, false, 2, 15 ],
            // #4132 — the landscape matrix past one page: its footer strip
            // moves whole to the next sheet, and the player table repeats its
            // header there.
            'matrix, the squad runs over'  => [ 'C', null, false, 1, 24 ],
            'matrix, a long squad'         => [ 'C', [ 'kpi', 'roster', 'changes', 'tests', 'notes' ], false, 1, 30 ],
            // #4132 — the one-pager just under full, and just over.
            'one-pager, just under full'   => [ 'A', [ 'kpi', 'roster', 'changes', 'tests', 'notes' ], false, 1, 20 ],
            'one-pager, just over full'    => [ 'A', [ 'kpi', 'roster', 'changes', 'tests', 'notes' ], false, 1, 22 ],
        ];
        foreach ( $cases as $label => $case ) {
            [ $layout, $blocks ] = $case;
            $squad   = (int) ( $case[4] ?? 18 );
            $base    = ! empty( $case[3] ) ? $this->withMatchesAndTests( $this->report( $squad ), (int) $case[3] ) : $this->report( 20 );
            $report  = $blocks === null ? $base : $this->only( $base, $blocks );
            if ( ! empty( $case[2] ) ) $report = $this->withLongText( $report );
            $fit     = TeamMonthlyReportLayout::fit( $report, $layout );
            $payload = TeamMonthlyReportPdfExporter::payload( $report, $layout, 'Pdf U13' );

            $options = new \Dompdf\Options();
            $options->set( 'defaultFont', 'DejaVu Sans' );
            $options->set( 'isHtml5ParserEnabled', true );
            $dompdf = new \Dompdf\Dompdf( $options );
            $dompdf->loadHtml( $payload['html'], 'UTF-8' );
            $dompdf->setPaper( 'A4', $payload['options']['orientation'] );
            $dompdf->render();

            $this->assertSame( $fit['pages'], $dompdf->getCanvas()->get_page_count(), "{$label}: the panel and the paper disagree." );
        }
    }

    /**
     * #4133, #4134 — the meter counts the level each section prints: the
     * Evaluations section at Summary and at Details (with subcategories), and
     * attendance and minutes share at Summary, on every layout.
     */
    public function test_the_page_count_holds_at_both_levels(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }
        $cases = [
            [ 'B', 'summary', false, false ], [ 'B', 'details', false, false ], [ 'B', 'details', true, false ],
            [ 'B', 'details', false, true ], [ 'A', 'details', false, true ], [ 'C', 'details', false, false ],
        ];
        foreach ( $cases as [ $layout, $level, $subs, $summaries ] ) {
            $report = $this->withEvaluations( $this->withMatchesAndTests( $this->report( 18 ), 1 ), $level, $subs );
            if ( $summaries ) {
                $report['data']['attendance']['level'] = 'summary';
                $report['data']['minutes']['level']    = 'summary';
            }
            $fit     = TeamMonthlyReportLayout::fit( $report, $layout );
            $payload = TeamMonthlyReportPdfExporter::payload( $report, $layout, 'Pdf U13' );

            $options = new \Dompdf\Options();
            $options->set( 'defaultFont', 'DejaVu Sans' );
            $options->set( 'isHtml5ParserEnabled', true );
            $dompdf = new \Dompdf\Dompdf( $options );
            $dompdf->loadHtml( $payload['html'], 'UTF-8' );
            $dompdf->setPaper( 'A4', $payload['options']['orientation'] );
            $dompdf->render();

            $label = "{$layout} {$level}" . ( $subs ? ' + subcategories' : '' ) . ( $summaries ? ' + summaries' : '' );
            $this->assertSame( $fit['pages'], $dompdf->getCanvas()->get_page_count(), "{$label}: the panel and the paper disagree." );
            if ( $summaries ) {
                $this->assertStringContainsString( 'class="tstat lsum"', $payload['html'], "{$label}: attendance prints its summary" );
            }
        }
    }

    /**
     * #4133 — a payload without a level, as a snapshot taken before the choice
     * existed, prints attendance and minutes as bars, as it always did.
     */
    public function test_a_payload_without_a_level_prints_the_bars(): void {
        $report = $this->only( $this->report( 12 ), [ 'attendance', 'minutes' ] );
        $html   = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13' )['html'];

        $this->assertSame( 2, substr_count( $html, 'class="bars"' ) );
        $this->assertStringNotContainsString( 'class="tstat lsum"', $html );
    }

    /**
     * #4134 — an Evaluations section shaped like the composer's payload.
     *
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @return array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}
     */
    private function withEvaluations( array $report, string $level, bool $subs ): array {
        $players    = (int) $report['data']['letterhead']['squad_size'];
        $categories = [];
        foreach ( [ 'Technisch', 'Tactisch', 'Fysiek', 'Mentaal', 'Inzicht' ] as $c => $label ) {
            $row = [ 'category_id' => $c + 1, 'label' => $label, 'avg' => 7.2, 'delta' => 0.3, 'min' => 6.0, 'max' => 9.0, 'band_from_pct' => 25.0, 'band_to_pct' => 100.0, 'avg_pct' => 55.0, 'wide' => $c === 3 ];
            if ( $subs ) {
                $row['subcategories'] = [
                    [ 'category_id' => 100 + $c, 'label' => 'Aanname', 'avg' => 7.0, 'delta' => null, 'min' => 6.0, 'max' => 8.0, 'band_from_pct' => 25.0, 'band_to_pct' => 75.0, 'avg_pct' => 50.0, 'wide' => false ],
                    [ 'category_id' => 200 + $c, 'label' => 'Passing', 'avg' => 7.5, 'delta' => 0.5, 'min' => 6.0, 'max' => 9.0, 'band_from_pct' => 25.0, 'band_to_pct' => 100.0, 'avg_pct' => 62.0, 'wide' => false ],
                ];
            }
            $categories[] = $row;
        }
        $movers = static fn( int $sign ): array => array_map( static fn( int $i ): array => [ 'player_id' => $i, 'name' => 'Player ' . $i, 'from' => 6.4, 'to' => 6.4 + $sign * 0.8, 'delta' => $sign * 0.8 ], [ 1, 2, 3 ] );
        $e      = [
            'level' => $level, 'scale' => [ 'min' => 5.0, 'max' => 9.0, 'step' => 1.0 ], 'types' => [], 'types_counted' => [],
            'squad_avg' => 7.2, 'squad_avg_delta' => 0.2, 'evaluated' => $players - 2, 'squad' => $players,
            'missing' => [ [ 'player_id' => 1, 'name' => 'Player 1' ], [ 'player_id' => 2, 'name' => 'Player 2' ] ],
            'evaluations' => 23, 'coaches' => 3, 'by_type' => [ [ 'type_id' => 1, 'label' => 'Training', 'count' => 14 ], [ 'type_id' => 2, 'label' => 'Match', 'count' => 9 ] ],
            'rising_count' => 6, 'falling_count' => 3, 'rising' => $movers( 1 ), 'falling' => $movers( -1 ),
            'categories' => $categories, 'has_subcategories' => $subs, 'sub' => $subs,
        ];
        if ( $level === 'details' ) {
            $rows = [];
            for ( $i = 1; $i <= $players; $i++ ) {
                $cells = [];
                foreach ( $categories as $cat ) $cells[] = [ 'category_id' => $cat['category_id'], 'value' => $i <= 2 ? null : 7.0, 'tone' => 4, 'trend' => [ 'up', 'down', 'flat', '' ][ $i % 4 ] ];
                $rows[] = [ 'player_id' => $i, 'name' => 'Player ' . $i, 'jersey_number' => $i, 'evaluated' => $i > 2, 'cells' => $cells, 'avg' => $i > 2 ? 7.1 : null, 'count' => $i > 2 ? 2 : 0, 'last' => $i > 2 ? '2026-08-24' : null ];
            }
            $e['grid'] = [ 'rows' => $rows ];
        }
        $report['data']['evaluations'] = $e;
        return $report;
    }

    /**
     * #4114 — a Suspended column beside Injured, in every layout that prints
     * the roster, on paper and on the screen's sheets alike: the activities
     * the player missed suspended this window, blank when none.
     */
    public function test_the_roster_has_a_suspended_column_beside_injured(): void {
        $report = $this->only( $this->report( 20 ), [ 'roster' ] );
        foreach ( TeamMonthlyReportLayout::ALL as $layout ) {
            $prepared = TeamMonthlyReportDocument::prepare( $report, $layout );
            $paper    = TeamMonthlyReportDocument::html( $prepared['report'], $layout, 'Pdf U13', [], $prepared['fit']['groups'] );
            $screen   = TeamMonthlyReportDocument::screen( $prepared['report'], $layout, 'Pdf U13', $prepared['fit'] );

            foreach ( [ 'paper' => $paper, 'screen' => $screen ] as $out => $html ) {
                $injured   = strpos( $html, '>Injured</th>' );
                $suspended = strpos( $html, '>Suspended</th>' );
                $this->assertNotFalse( $suspended, "{$layout} {$out}: no Suspended column" );
                $this->assertGreaterThan( (int) $injured, (int) $suspended, "{$layout} {$out}: Suspended sits after Injured" );
                $this->assertSame( 1, substr_count( $html, 'data-label="Suspended">2</td>' ), "{$layout} {$out}: the suspended player's count" );
            }
        }
    }

    /**
     * #4118 — a snapshot prints each section's note under it, so the estimate
     * counts the notes. A 10-line note on Tests moves the tests to a page of
     * their own; the meter says so, and DomPDF prints that many pages.
     */
    public function test_a_snapshots_section_notes_are_in_the_page_count(): void {
        $report = $this->withLongText( $this->report( 20 ) );
        $body   = implode( "\n", array_map( static fn( int $i ): string => 'Afspraak ' . $i . ': de spelers oefenen het omschakelen na balverlies.', range( 1, 10 ) ) );
        $notes  = [ 'tests' => [ 'body' => $body, 'author' => 1, 'updated_at' => '2026-09-01 10:00:00' ] ];

        $live     = TeamMonthlyReportLayout::fit( $report, 'B' );
        $snapshot = TeamMonthlyReportLayout::fit( $report, 'B', $notes );
        $this->assertSame( 3, $live['pages'], 'Precondition: without the note the pack is three pages.' );
        $this->assertSame( 4, $snapshot['pages'], 'The note pushes the tests onto a page of their own.' );
        $this->assertSame( $live, TeamMonthlyReportLayout::fit( $report, 'B', [] ), 'A live report, without notes, is unchanged.' );

        $prepared = TeamMonthlyReportDocument::prepare( $report, 'B', $notes );
        $this->assertSame( $snapshot, $prepared['fit'] );

        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }
        $payload = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13', $notes );
        $this->assertStringContainsString( 'Afspraak 10', $payload['html'] );

        $options = new \Dompdf\Options();
        $options->set( 'defaultFont', 'DejaVu Sans' );
        $options->set( 'isHtml5ParserEnabled', true );
        $dompdf = new \Dompdf\Dompdf( $options );
        $dompdf->loadHtml( $payload['html'], 'UTF-8' );
        $dompdf->setPaper( 'A4', $payload['options']['orientation'] );
        $dompdf->render();
        $this->assertSame( $snapshot['pages'], $dompdf->getCanvas()->get_page_count(), 'the meter and the paper disagree' );
    }

    /**
     * #4097 — the web page shows what prints: for every layout and
     * composition the page-count test covers, the screen's sheets hold the
     * same sections, in the same order and the same page grouping, as the
     * PDF's pages. Names are record links on screen only; paper prints text.
     */
    public function test_the_screen_sheets_hold_what_the_pdf_pages_hold(): void {
        $cases = [
            [ 'A', null, 0, 20 ], [ 'B', null, 0, 20 ], [ 'C', null, 0, 20 ],
            [ 'A', [ 'coverage', 'kpi', 'status', 'attendance', 'attention', 'quality' ], 0, 20 ],
            [ 'B', [ 'coverage', 'kpi', 'status', 'attendance', 'minutes', 'attention', 'notes' ], 0, 20 ],
            [ 'C', [ 'kpi', 'roster' ], 0, 20 ],
            [ 'B', null, 1, 18 ], [ 'B', null, 3, 18 ], [ 'B', null, 3, 5 ], [ 'C', null, 5, 18 ],
            [ 'A', [ 'kpi', 'matches', 'tests' ], 3, 18 ],
        ];
        foreach ( $cases as $i => [ $layout, $blocks, $tests, $squad ] ) {
            $base   = $tests > 0 ? $this->withMatchesAndTests( $this->report( $squad ), $tests ) : $this->report( $squad );
            $report = $blocks === null ? $base : $this->only( $base, $blocks );

            $prepared = TeamMonthlyReportDocument::prepare( $report, $layout );
            $paper    = TeamMonthlyReportDocument::html( $prepared['report'], $layout, 'Pdf U13', [], $prepared['fit']['groups'] );
            $screen   = TeamMonthlyReportDocument::screen( $prepared['report'], $layout, 'Pdf U13', $prepared['fit'] );

            $pages  = array_slice( explode( '<div class="page', $paper ), 1 );
            $sheets = array_slice( explode( '<article class="tt-mr-sheet"', $screen ), 1 );
            $this->assertSame(
                array_map( [ $this, 'blocksIn' ], $pages ),
                array_map( [ $this, 'blocksIn' ], $sheets ),
                "case {$i} ({$layout}): the sheets and the pages differ"
            );
            $stamp = static fn( string $h ): string => (string) preg_replace( '/Generated [^<]*/', '', $h );
            $this->assertSame( $stamp( TeamMonthlyReportPdfExporter::payload( $report, $layout, 'Pdf U13' )['html'] ), $stamp( $paper ), "case {$i}: the exporter prints this document" );
            $this->assertStringNotContainsString( '<a ', $paper, 'paper carries no links' );
        }
    }

    /** @return list<string> the sections a page or sheet holds, in order. */
    private function blocksIn( string $html ): array {
        preg_match_all( '/<!--tt-block:([a-z]+)-->/', $html, $m );
        return $m[1];
    }

    /**
     * A full composition for a squad of `$players`, shaped like
     * `TeamMonthlyReport::forTeam()`'s payload.
     *
     * @return array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}
     */
    private function report( int $players ): array {
        $attendance = [];
        $minutes    = [];
        $roster     = [];
        for ( $i = 1; $i <= $players; $i++ ) {
            $name         = 'Player ' . $i;
            $attendance[] = [ 'player_id' => $i, 'name' => $name, 'present_pct' => 58 + $i * 2, 'band' => $i <= 3 ? 'red' : 'green' ];
            $minutes[]    = [ 'player_id' => $i, 'name' => $name, 'share_pct' => 18 + $i * 3.5 ];
            $roster[]     = [ 'player_id' => $i, 'name' => $name, 'jersey_number' => $i, 'status' => 'green', 'attendance_pct' => 80, 'minutes' => 540, 'share_pct' => 55.5, 'open_goals' => 2, 'injured' => $i === 5, 'suspended' => $i === 6 ? 2 : 0 ];
        }
        $attention = [];
        for ( $i = 1; $i <= 5; $i++ ) {
            $attention[] = [ 'player_id' => $i, 'name' => 'Player ' . $i, 'color' => 'red', 'attendance_pct' => 55, 'reasons' => [ 'Attendance below 70%' ] ];
        }

        return [
            'from'   => '2026-08-01',
            'to'     => '2026-08-31',
            'blocks' => TeamMonthlyReportBlock::ALL,
            'data'   => [
                'letterhead' => [ 'head_coach' => 'Head Coach', 'squad_size' => $players, 'activity_count' => 14 ],
                'coverage'   => [ 'state' => 'partial', 'completed' => 14, 'with_register' => 13, 'missing' => [ [ 'title' => 'Training', 'date' => '2026-08-04' ] ] ],
                'kpi'        => [ 'activities' => [ 'value' => 14, 'delta' => 2 ], 'attendance_pct' => [ 'value' => 84.5, 'delta' => -1.5 ], 'minutes_share_median_pct' => [ 'value' => 52, 'delta' => null ], 'evaluated' => [ 'value' => 12, 'of' => $players, 'delta' => 3 ], 'squad_rating' => [ 'value' => 6.8, 'delta' => 0.2 ], 'needs_attention' => [ 'value' => 5, 'delta' => 1 ] ],
                'status'     => [ 'counts' => [ 'green' => 12, 'amber' => 4, 'red' => 2, 'unknown' => 2 ] ],
                'attendance' => [ 'rows' => $attendance, 'team_avg_pct' => 84 ],
                'minutes'    => [ 'rows' => $minutes, 'target_pct' => 50 ],
                'attention'  => [ 'items' => $attention ],
                'changes'    => [ 'events' => [ [ 'date' => '2026-08-10', 'player_id' => 1, 'name' => 'Player 1', 'summary' => 'Injury' ], [ 'date' => '2026-08-20', 'player_id' => 2, 'name' => 'Player 2', 'summary' => 'Moved to U14' ] ], 'open_injuries' => 1 ],
                'tests'      => [ 'rounds' => [ [ 'name' => 'Sprint 30m', 'date' => '2026-08-12', 'tested' => 18, 'squad' => $players, 'improved' => [], 'declined' => [] ] ] ],
                'roster'     => [ 'rows' => $roster ],
                'notes'      => [],
                'quality'    => [ 'activities_without_register' => [ 1 ], 'matches_without_minutes' => 0, 'players_not_evaluated' => [], 'players_with_incomplete_status' => [] ],
            ],
        ];
    }

    /** #4069 — matches print as record tiles, results beside ranked scorers. */
    public function test_matches_print_tiles_results_and_ranked_scorers(): void {
        $report = $this->only( $this->withMatchesAndTests( $this->report( 18 ), 1 ), [ 'matches' ] );
        $html   = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13' )['html'];

        $this->assertStringContainsString( 'class="mrec"', $html );
        $this->assertStringContainsString( 'class="res"', $html );
        $this->assertStringContainsString( 'class="scr"', $html );
        $this->assertStringContainsString( '8 of 8 goals attributed.', $html );
    }

    /**
     * #4069 — a test is a card with a stat strip; the pack adds the ranked
     * table, the one-pager prints the strip only.
     */
    public function test_tests_print_a_stat_strip_and_the_ranked_readings(): void {
        $report = $this->only( $this->withMatchesAndTests( $this->report( 18 ), 1 ), [ 'tests' ] );

        $pack = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13' )['html'];
        $this->assertStringContainsString( 'class="tstat"', $pack );
        $this->assertStringContainsString( 'class="rd"', $pack );
        $this->assertStringContainsString( '12:54', $pack );
        $this->assertStringContainsString( 'class="avgline"', $pack );

        $one = TeamMonthlyReportPdfExporter::payload( $report, 'A', 'Pdf U13' )['html'];
        $this->assertStringContainsString( 'class="tstat"', $one );
        $this->assertStringNotContainsString( 'class="rd"', $one, 'The one-pager prints the strip only.' );
        $this->assertStringContainsString( '16:04', $one, 'With the worst reading in place of the history.' );
    }

    /**
     * #4092 — the pack prints everything ticked. Page 3 keeps every player
     * who needs a conversation; the tests move, with their full tables, to a
     * page 4 of their own.
     */
    public function test_the_pack_grows_to_a_fourth_page_instead_of_shortening(): void {
        $report = $this->withMatchesAndTests( $this->report( 5 ), 3 );
        $fit    = TeamMonthlyReportLayout::fit( $report, 'B' );

        $this->assertSame( 4, $fit['pages'] );
        $this->assertSame( 4, TeamMonthlyReportLayout::maxPages( 'B' ) );
        $this->assertSame( [], $fit['degraded'], 'The pack shortens nothing.' );
        $this->assertSame( [ 'tests', 'notes', 'quality' ], $fit['groups'][3] );

        $html  = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13' )['html'];
        $pages = explode( '<div class="page break">', $html );
        $this->assertCount( 4, $pages );
        $this->assertSame( 5, substr_count( $pages[2], 'class="a-red"' ), 'Page 3 lists all five players.' );
        $this->assertSame( 3, substr_count( $pages[3], 'class="rd"' ), 'Page 4 has all three tests with their readings.' );

        // A bigger squad runs page 4 over onto another sheet: still nothing
        // dropped, and the meter says the pack does not fit.
        $big     = $this->withMatchesAndTests( $this->report( 18 ), 3 );
        $big_fit = TeamMonthlyReportLayout::fit( $big, 'B' );
        $this->assertFalse( $big_fit['fits'] );
        $big_html = TeamMonthlyReportPdfExporter::payload( $big, 'B', 'Pdf U13' )['html'];
        $this->assertSame( 3, substr_count( $big_html, 'class="rd"' ) );
        $this->assertSame( 5, substr_count( $big_html, 'class="a-red"' ) );
    }

    /** #4092 — a pack whose third page fits keeps three pages. */
    public function test_a_pack_whose_third_page_fits_stays_three_pages(): void {
        $fit = TeamMonthlyReportLayout::fit( $this->report( 20 ), 'B' );
        $this->assertSame( 3, $fit['pages'] );
        $this->assertCount( 3, $fit['groups'] );
        $this->assertSame( [ 'attention', 'changes', 'tests', 'notes', 'quality' ], $fit['groups'][2], 'The tests stay on page 3 under the agenda.' );
    }

    /**
     * #4093 — the test header carries the target, each reading its standing
     * in the profile's words; the summary keeps the target in its header.
     */
    public function test_tests_print_the_target_and_each_readings_standing(): void {
        $report = $this->only( $this->withMatchesAndTests( $this->report( 18 ), 1 ), [ 'tests' ] );

        $pack = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13' )['html'];
        $this->assertStringContainsString( 'Target U13: ≤ 12:30', $pack );
        $this->assertStringContainsString( '<span class="vc vc-warn">just over target</span>', $pack );
        $this->assertStringContainsString( '<span class="vc vc-bad">well over target</span>', $pack );

        $one = TeamMonthlyReportPdfExporter::payload( $report, 'A', 'Pdf U13' )['html'];
        $this->assertStringContainsString( 'Target U13: ≤ 12:30', $one, 'the summary keeps the target' );
        $this->assertStringNotContainsString( 'class="vc ', $one );

        // A test without a direction: "no target", and the reason once.
        $report['data']['tests']['rounds'][0]['target']        = null;
        $report['data']['tests']['rounds'][0]['target_absent'] = true;
        $report['data']['tests']['rounds'][1]                  = $report['data']['tests']['rounds'][0];
        $none = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13' )['html'];
        $this->assertStringContainsString( 'no target', $none );
        $this->assertSame( 1, substr_count( $none, 'the reading is recorded, not judged' ) );
    }

    /** #4069 — a test with no target band for the age group has no band cell. */
    public function test_a_test_without_bands_prints_no_band_cell(): void {
        $report = $this->only( $this->withMatchesAndTests( $this->report( 18 ), 1 ), [ 'tests' ] );
        $report['data']['tests']['rounds'][0]['bands'] = null;

        $html = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13' )['html'];
        $this->assertStringNotContainsString( 'class="bandrow"', $html );
        $this->assertStringNotContainsString( 'On target (', $html );
    }

    /**
     * #4069 — the matches block with four results and six scorers, and
     * `$tests` test rounds of readings for every player, shaped like the
     * composer's payload.
     *
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @return array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}
     */
    private function withMatchesAndTests( array $report, int $tests ): array {
        $fixtures = [];
        for ( $i = 0; $i < 4; $i++ ) {
            $fixtures[] = [
                'activity_id' => $i + 1, 'date' => sprintf( '2026-08-%02d', 5 + $i * 7 ), 'title' => 'Match',
                'opponent' => "Blauw Geel '38 JO14-1", 'home_away' => $i % 2 ? 'home' : 'away',
                'team_score' => 3, 'opp_score' => 1, 'outcome' => 'W', 'squad' => [],
            ];
        }
        $scorers = [];
        for ( $i = 1; $i <= 6; $i++ ) {
            $scorers[] = [ 'player_id' => $i, 'name' => 'Player ' . $i, 'goals' => max( 0, 4 - $i ), 'assists' => $i % 3, 'rank' => $i < 4 ? $i : null ];
        }
        $report['data']['matches'] = [
            'tournaments_excluded' => 0,
            'shows'                => [ 'record' => true, 'scorers' => true, 'squads' => false ],
            'record'               => [ 'played' => 4, 'won' => 2, 'drawn' => 1, 'lost' => 1, 'goals_for' => 8, 'goals_against' => 5, 'goal_difference' => 3, 'without_score' => 0 ],
            'fixtures'             => $fixtures,
            'scorers'              => $scorers,
            'scorer_totals'        => [ 'goals' => 8, 'assists' => 4, 'goals_for' => 8 ],
        ];

        $players  = (int) $report['data']['letterhead']['squad_size'];
        $readings = [];
        for ( $i = 1; $i <= $players; $i++ ) {
            $readings[] = [
                'player_id' => $i, 'name' => 'Player ' . $i, 'value' => 11.0 + $i / 10, 'value_display' => '11:' . str_pad( (string) ( $i * 3 ), 2, '0', STR_PAD_LEFT ),
                'previous_display' => '12:10', 'delta_display' => '−9 s', 'trend' => 'up', 'first' => $i === 3, 'pb' => $i % 4 === 0,
                'flag' => [ 'ok', 'warn', 'bad' ][ $i % 3 ], 'rank' => $i, 'bar_pct' => 50 + $i * 2,
                'verdict_label' => [ 'on target', 'just over target', 'well over target' ][ $i % 3 ], 'verdict_tone' => [ 'ok', 'warn', 'bad' ][ $i % 3 ],
                'vs_avg_display' => '−0:40', 'worse_than_avg' => $i > $players / 2,
            ];
        }
        $round = [
            'definition_id' => 1, 'name' => '9 laps', 'unit' => 'min', 'unit_label' => 'mm:ss', 'is_duration' => true, 'direction' => 'lower',
            'date' => '2026-08-12', 'previous_date' => '2026-06-10', 'tested' => $players, 'squad' => $players,
            'improved' => [], 'declined' => [],
            'average'  => [ 'value' => 12.9, 'display' => '12:54', 'previous' => 13.0, 'previous_display' => '12:58', 'delta_display' => '−4 s', 'trend' => 'up' ],
            'best'     => [ 'player_id' => 1, 'name' => 'Player 1', 'value_display' => '11:18' ],
            'worst'    => [ 'player_id' => 2, 'name' => 'Player 2', 'value_display' => '16:04' ],
            'moves'    => [ 'up' => 9, 'down' => 5, 'flat' => 0, 'first' => 1 ],
            'bands'    => [ 'age_group' => 'U13', 'ok' => 8, 'warn' => 5, 'bad' => 5, 'of' => $players ],
            'target'   => '≤ 12:30', 'target_age_group' => 'U13', 'target_absent' => false,
            'history'  => [
                [ 'date' => '2026-02-10', 'value' => 13.4, 'display' => '13:24', 'pct' => 100.0 ],
                [ 'date' => '2026-04-10', 'value' => 13.2, 'display' => '13:12', 'pct' => 98.5 ],
                [ 'date' => '2026-06-10', 'value' => 13.0, 'display' => '12:58', 'pct' => 97.0 ],
                [ 'date' => '2026-08-12', 'value' => 12.9, 'display' => '12:54', 'pct' => 96.3 ],
            ],
            'readings' => $readings,
        ];
        $report['data']['tests'] = [ 'rounds' => array_fill( 0, $tests, $round ), 'show' => 'values_trend' ];

        return $report;
    }

    /** #3970 — on paper a sentence cut off with an ellipsis cannot be finished. */
    public function test_written_text_prints_whole(): void {
        $report = $this->withLongText( $this->only( $this->report( 20 ), [ 'attention', 'changes', 'quality' ] ) );
        $html   = TeamMonthlyReportPdfExporter::payload( $report, 'B', 'Pdf U13' )['html'];

        $this->assertStringContainsString( esc_html( self::PROSE ), $html, 'the whole change and the whole reason are on the page' );
        $this->assertStringContainsString( 'Firstname Lastname 12', $html, 'every player without an evaluation is named' );
    }

    private const PROSE = 'Returned from an ankle injury and trained fully this week, but still hesitant in duels and asked to keep the load down until the physio clears him for match minutes again.';

    /**
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @return array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}
     */
    private function withLongText( array $report ): array {
        if ( isset( $report['data']['changes'] ) ) {
            foreach ( array_keys( $report['data']['changes']['events'] ) as $i ) $report['data']['changes']['events'][ $i ]['summary'] = self::PROSE;
        }
        if ( isset( $report['data']['attention'] ) ) {
            foreach ( array_keys( $report['data']['attention']['items'] ) as $i ) $report['data']['attention']['items'][ $i ]['reasons'] = [ self::PROSE, self::PROSE ];
        }
        if ( isset( $report['data']['quality'] ) ) {
            $report['data']['quality']['players_not_evaluated'] = array_map( static fn( int $i ): array => [ 'player_id' => $i, 'name' => 'Firstname Lastname ' . $i ], range( 1, 12 ) );
        }
        return $report;
    }

    /**
     * The same report with only `$blocks` selected, as the composer would
     * return it: a deselected block has no data at all.
     *
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @param list<string> $blocks
     * @return array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}
     */
    private function only( array $report, array $blocks ): array {
        $keep             = array_merge( [ TeamMonthlyReportBlock::LETTERHEAD ], $blocks );
        $report['data']   = array_intersect_key( $report['data'], array_flip( $keep ) );
        $report['blocks'] = array_values( array_filter( TeamMonthlyReportBlock::ALL, static fn( string $b ): bool => in_array( $b, $keep, true ) ) );
        return $report;
    }
}
