<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;
use TT\Modules\Export\Exporters\PlayerReportPdfExporter;

/**
 * #3874 (epic #3871) — the player report on paper.
 *
 * Pinned: the exporter is registered for PDF and refuses a reader without the
 * player; the template uses nothing DomPDF cannot print; the one-pager prints
 * what it measured and says what it left out; and the page count the panel
 * shows is the page count DomPDF prints — for a quiet player, a typical one
 * and one with a season's worth of everything.
 */
final class PlayerReportPdfTest extends WP_UnitTestCase {

    public function test_exporter_is_registered_for_pdf_only(): void {
        $exporter = ExporterRegistry::get( 'player_report_pdf' );
        $this->assertInstanceOf( PlayerReportPdfExporter::class, $exporter );
        $this->assertSame( [ 'pdf' ], $exporter->supportedFormats() );
    }

    public function test_filters_need_a_player_and_default_to_the_one_pager(): void {
        $exporter = new PlayerReportPdfExporter();
        $this->assertNull( $exporter->validateFilters( [] ) );

        $clean = $exporter->validateFilters( [ 'player_id' => '7', 'from' => '2026-08-01', 'to' => '2026-08-31', 'layout' => 'b', 'blocks' => 'tests,bogus,ratings' ] );
        $this->assertIsArray( $clean );
        $this->assertSame( 'B', $clean['layout'] );
        $this->assertSame( [ 'tests', 'ratings' ], $clean['blocks'], 'Unknown sections drop, as they do on screen.' );
        $this->assertSame( '2026-08-01', $clean['from'] );

        $default = $exporter->validateFilters( [ 'player_id' => 7 ] );
        $this->assertIsArray( $default );
        $this->assertSame( PlayerReportLayout::ONE_PAGER, $default['layout'] );
        $this->assertSame( gmdate( 'Y-m-d' ), $default['to'], 'the season so far ends today' );
    }

    public function test_a_reader_without_the_player_is_refused(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [ 'club_id' => 1, 'first_name' => 'Paper', 'last_name' => 'Player', 'status' => 'active', 'wp_user_id' => null ] );
        $player = (int) $wpdb->insert_id;
        $uid    = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $request = new ExportRequest( 'player_report_pdf', 'pdf', 1, $uid, null, [
            'player_id' => $player, 'from' => '2026-08-01', 'to' => '2026-08-31', 'layout' => 'A', 'blocks' => [],
        ] );

        try {
            ( new PlayerReportPdfExporter() )->collect( $request );
            $this->fail( 'A reader who cannot open the report must not get it on paper.' );
        } catch ( ExportException $e ) {
            $this->assertSame( 'forbidden', $e->errorKey );
        }
    }

    public function test_the_template_uses_no_layout_dompdf_cannot_print(): void {
        foreach ( PlayerReportLayout::ALL as $layout ) {
            $html = PlayerReportPdfExporter::payload( $this->report( 12 ), $layout )['html'];
            $flat = strtolower( (string) preg_replace( '/\s+/', '', $html ) );
            $this->assertStringNotContainsString( 'display:flex', $flat, "Layout {$layout} uses flexbox." );
            $this->assertStringNotContainsString( 'display:grid', $flat, "Layout {$layout} uses grid." );
            $this->assertStringContainsString( 'Confidential', $html, "Layout {$layout} lost its confidential footer." );
        }
    }

    public function test_the_one_pager_says_what_it_left_out(): void {
        $report = $this->report( 12 );
        $fit    = PlayerReportLayout::fit( $report, PlayerReportLayout::ONE_PAGER );
        $this->assertContains( PlayerReportLayout::SHORTEN_LISTS, $fit['degraded'], 'Precondition: a season of everything needs shortening.' );

        $html = PlayerReportPdfExporter::payload( $report, PlayerReportLayout::ONE_PAGER )['html'];
        $this->assertStringContainsString( 'and 7 more in this period', $html, 'the 12 matches keep 5 and say so' );
        $this->assertStringContainsString( 'conversations held', $html, 'the development plan prints as one line' );
        $this->assertStringNotContainsString( 'Match 12<', $html, 'the oldest matches are the ones left out' );
    }

    /**
     * The promise the panel makes, checked on the real DomPDF: a change to the
     * template's row heights the estimate does not follow fails here rather
     * than on a coach's printer.
     */
    public function test_the_printed_page_count_is_the_estimated_one(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }

        $cases = [
            'quiet, conversation set'   => [ $this->report( 0 ), PlayerReportBlock::DEFAULT_BLOCKS ],
            'typical, conversation set' => [ $this->report( 3 ), PlayerReportBlock::DEFAULT_BLOCKS ],
            'busy, conversation set'    => [ $this->report( 12 ), PlayerReportBlock::DEFAULT_BLOCKS ],
            'busy, everything'          => [ $this->report( 12 ), PlayerReportBlock::ALL ],
            'long notes, conversation'  => [ $this->withLongText( $this->report( 3 ) ), PlayerReportBlock::DEFAULT_BLOCKS ],
            'long notes, everything'    => [ $this->withLongText( $this->report( 5 ) ), PlayerReportBlock::ALL ],
        ];
        foreach ( $cases as $label => [ $report, $blocks ] ) {
            $report = $this->only( $report, $blocks );
            foreach ( PlayerReportLayout::ALL as $layout ) {
                $fit     = PlayerReportLayout::fit( $report, $layout );
                $payload = PlayerReportPdfExporter::payload( $report, $layout );

                $options = new \Dompdf\Options();
                $options->set( 'defaultFont', 'DejaVu Sans' );
                $options->set( 'isHtml5ParserEnabled', true );
                $dompdf = new \Dompdf\Dompdf( $options );
                $dompdf->loadHtml( $payload['html'], 'UTF-8' );
                $dompdf->setPaper( 'A4', 'portrait' );
                $dompdf->render();

                $this->assertSame( $fit['pages'], $dompdf->getCanvas()->get_page_count(), "{$label}, layout {$layout}: the panel and the paper disagree." );
            }
        }
    }

    /** On paper a sentence cut off with an ellipsis cannot be finished. */
    public function test_written_text_prints_whole_and_costs_its_lines(): void {
        $short  = $this->only( $this->report( 3 ), [ 'ratings', 'journey', 'thread_notes' ] );
        $long   = $this->only( $this->withLongText( $this->report( 3 ) ), [ 'ratings', 'journey', 'thread_notes' ] );
        $html   = PlayerReportPdfDocument::html( $long );
        $prose  = self::PROSE;

        $this->assertStringContainsString( esc_html( $prose ), $html, 'the whole note is on the page' );
        $this->assertStringNotContainsString( '…', $html, 'no field of these blocks is cut' );
        $this->assertGreaterThan(
            PlayerReportLayout::fit( $short, PlayerReportLayout::PACK )['fill'][0],
            PlayerReportLayout::fit( $long, PlayerReportLayout::PACK )['fill'][0],
            'the estimate counts the lines the text wraps to'
        );
    }

    private const PROSE = 'Onderdeel van individueel gesprek met Luuk om eens te kijken naar beelden, zijn ontwikkelingspunten en hoe hij daar zelf naar kijkt. Hij speelt scherp in de omschakeling maar laat na balverlies soms zijn man lopen.';

    /**
     * @param array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>} $report
     * @return array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>}
     */
    private function withLongText( array $report ): array {
        foreach ( [ [ 'ratings', 'evaluations', 'notes' ], [ 'journey', 'items', 'summary' ], [ 'thread_notes', 'items', 'body' ], [ 'injuries', 'items', 'notes' ], [ 'behaviour', 'items', 'notes' ], [ 'talking_points', 'items', 'evidence' ] ] as [ $block, $list, $field ] ) {
            foreach ( array_keys( $report['data'][ $block ][ $list ] ?? [] ) as $i ) {
                $report['data'][ $block ][ $list ][ $i ][ $field ] = self::PROSE;
            }
        }
        if ( is_array( $report['data']['pdp']['file'] ?? null ) ) {
            $report['data']['pdp']['last_agreed_actions'] = self::PROSE;
        }
        return $report;
    }

    public function test_the_conversation_set_of_a_typical_player_fits_one_page(): void {
        $fit = PlayerReportLayout::fit( $this->only( $this->report( 3 ), PlayerReportBlock::DEFAULT_BLOCKS ), PlayerReportLayout::ONE_PAGER );
        $this->assertTrue( $fit['fits'] );
        $this->assertSame( 1, $fit['pages'] );
    }

    /**
     * A report shaped like `PlayerReport::forPlayer()`'s payload, with `$n`
     * of everything that comes in lists.
     *
     * @return array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>}
     */
    private function report( int $n ): array {
        $rows = static fn( callable $make ): array => $n > 0 ? array_map( $make, range( 1, $n ) ) : [];

        return [
            'player_id' => 1,
            'from'      => '2026-07-01',
            'to'        => '2026-09-21',
            'blocks'    => PlayerReportBlock::ALL,
            'data'      => [
                'letterhead'     => [ 'name' => 'Paper Player', 'team_name' => 'JO14-1', 'age_group' => '', 'head_coach' => 'A. Coach', 'jersey_number' => 9, 'birth_year' => 2012 ],
                'status'         => [ 'color' => 'amber', 'missing_inputs' => [ 'potential' ] ],
                'talking_points' => [ 'items' => array_slice( $rows( static fn( int $i ): array => [ 'level' => 'amber', 'text' => 'Point ' . $i, 'evidence' => 'Because ' . $i ] ), 0, 6 ) ],
                'ratings'        => [
                    'evaluation_count' => $n,
                    'latest'           => $n > 0 ? 7.0 : null,
                    'average'          => $n > 0 ? 6.8 : null,
                    'categories'       => $n > 0 ? array_map( static fn( int $i ): array => [ 'label' => 'Category ' . $i, 'latest' => 7.0, 'average' => 6.5 ], range( 1, 4 ) ) : [],
                    'evaluations'      => $rows( static fn( int $i ): array => [ 'eval_date' => '2026-08-01', 'rating' => 7.0, 'assessor_name' => 'Coach', 'notes' => 'Evaluation ' . $i . ' notes that run long enough to be cut.' ] ),
                ],
                'attendance'     => [ 'activities' => $n * 3, 'present' => $n * 2, 'absent' => $n, 'excused' => 0, 'rate' => $n > 0 ? 66 : null ],
                'minutes'        => [ 'apps' => $n, 'minutes' => $n * 55, 'matches' => $n ],
                'goals'          => [ 'items' => $rows( static fn( int $i ): array => [ 'title' => 'Goal ' . $i, 'status' => 'in_progress', 'due_date' => '2026-10-01', 'changed_in_window' => true, 'created_in_window' => false, 'is_closed' => false ] ) ],
                'pdp'            => $n > 0
                    ? [ 'available' => true, 'file' => [ 'id' => 1 ], 'conversations' => array_map( static fn( int $i ): array => [ 'sequence' => $i, 'scheduled_at' => '2026-09-01', 'conducted_at' => $i === 1 ? '2026-09-01' : '', 'signed_off' => $i === 1 ], range( 1, 4 ) ), 'last_agreed_actions' => 'Two more sessions a week.', 'verdict' => null ]
                    : [ 'available' => true, 'file' => null, 'conversations' => [], 'verdict' => null, 'last_agreed_actions' => '' ],
                'notes'          => [ 'lines' => 6 ],
                'matches'        => [ 'items' => $rows( static fn( int $i ): array => [ 'session_date' => '2026-08-0' . ( $i % 9 + 1 ), 'title' => 'Match ' . $i, 'minutes' => 60 ] ) ],
                'tests'          => [ 'items' => $rows( static fn( int $i ): array => [ 'name' => 'Test ' . $i, 'unit' => 's', 'value' => 4.5, 'date' => '2026-08-01', 'delta' => 0.1, 'previous_date' => '2026-06-01', 'trend' => 'down' ] ) ],
                'journey'        => [ 'items' => $rows( static fn( int $i ): array => [ 'date' => '2026-08-01', 'summary' => 'Journey ' . $i ] ) ],
                'injuries'       => [ 'items' => $rows( static fn( int $i ): array => [ 'started_on' => '2026-08-01', 'actual_return' => '', 'is_open' => true, 'notes' => 'Ankle' ] ) ],
                'behaviour'      => [ 'items' => $rows( static fn( int $i ): array => [ 'rated_at' => '2026-08-01 10:00:00', 'rating' => 7.0, 'notes' => 'Fine' ] ) ],
                'potential'      => [ 'items' => $rows( static fn( int $i ): array => [ 'set_at' => '2026-08-01 10:00:00', 'potential_band' => 'high' ] ) ],
                'thread_notes'   => [ 'items' => $rows( static fn( int $i ): array => [ 'created_at' => '2026-08-01 10:00:00', 'author_name' => 'Coach', 'body' => 'Note ' . $i ] ) ],
            ],
        ];
    }

    /**
     * @param array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>} $report
     * @param list<string> $blocks
     * @return array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>}
     */
    private function only( array $report, array $blocks ): array {
        $report['blocks'] = array_values( array_intersect( PlayerReportBlock::ALL, $blocks ) );
        $report['data']   = array_intersect_key( $report['data'], array_flip( $report['blocks'] ) );
        return $report;
    }
}
