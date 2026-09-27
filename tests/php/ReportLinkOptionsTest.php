<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Frontend\PlayerReportPage;
use TT\Modules\Analytics\Frontend\TeamMonthlyReportPage;

/**
 * #4135 — the report links carry the per-block options through `esc_url()`.
 *
 * The options travel as JSON, and `esc_url()` strips `{`, `}` and `"`. A
 * link built from the raw JSON reached the exporter as
 * `tests:definitions:[5],show:values_trend`, which does not decode, so the
 * PDF printed the defaults: every test, summary only.
 *
 * Pinned: after the escaping the page applies and the decoding the request
 * applies, the `options` parameter decodes back to the bags it was built
 * from, for the PDF and the schedule link of both reports.
 */
final class ReportLinkOptionsTest extends WP_UnitTestCase {

    private const OPTIONS = [
        'tests'   => [ 'definitions' => [ 5, 9 ], 'show' => 'values_trend' ],
        'matches' => [ 'results' => true, 'scorers' => false ],
    ];

    private const WINDOW = [ 'from' => '2026-08-01', 'to' => '2026-08-31', 'period' => 'last_month' ];

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function test_the_team_report_pdf_link_keeps_its_options(): void {
        $url = TeamMonthlyReportPage::pdfUrl( 12, self::WINDOW, 'B', [ 'tests', 'matches' ], self::OPTIONS );
        $this->assertSame( self::OPTIONS, $this->optionsOf( $url ) );
    }

    public function test_the_player_report_pdf_link_keeps_its_options(): void {
        $url = PlayerReportPage::pdfUrl( 34, self::WINDOW, 'B', [ 'tests', 'matches' ], self::OPTIONS );
        $this->assertSame( self::OPTIONS, $this->optionsOf( $url ) );
    }

    public function test_the_team_report_schedule_link_keeps_its_options(): void {
        $url = TeamMonthlyReportPage::scheduleUrl( 12, 'B', [ 'tests', 'matches' ], self::OPTIONS );
        if ( $url === '' ) $this->markTestSkipped( 'Scheduled reports are not offered on this install.' );
        $this->assertSame( self::OPTIONS, $this->optionsOf( $url ) );
    }

    public function test_the_player_report_schedule_link_keeps_its_options(): void {
        $url = PlayerReportPage::scheduleUrl( 34, self::WINDOW, 'B', [ 'tests', 'matches' ], self::OPTIONS );
        if ( $url === '' ) $this->markTestSkipped( 'Scheduled reports are not offered on this install.' );
        $this->assertSame( self::OPTIONS, $this->optionsOf( $url ) );
    }

    /**
     * The link as the page prints it, then as the browser sends it and the
     * server reads it back.
     *
     * @return mixed
     */
    private function optionsOf( string $url ) {
        $href = html_entity_decode( esc_url( $url ) );
        parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query );
        $this->assertArrayHasKey( 'options', $query );
        return json_decode( (string) $query['options'], true );
    }
}
