<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\Exporters\PlayerReportPdfExporter;
use TT\Shared\Frontend\FrontendStandardReportsView;

/**
 * #4637 — before the season marked current has started there is no "season
 * so far". The default used to run backwards, from the season's future start
 * to today, and the player report threw on it: a fatal on screen, a 500 for
 * the PDF. Now a report asks the reader to pick a period, the PDF export
 * answers 422 with the same words, and a typed window that ends before it
 * starts is a form error.
 *
 * The caller is a `tt_club_admin`; see `PlayerReportTest` for why.
 */
final class ReportNoPeriodTest extends WP_UnitTestCase {

    private const PICK = 'Pick a period to see this report.';
    private const BACKWARDS = 'The start date must be on or before the end date.';

    private int $team   = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] ) );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Period U14', 'age_group' => 'U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team,
            'first_name' => 'Period', 'last_name' => 'Player', 'status' => 'active', 'wp_user_id' => null,
        ] );
        $this->player = (int) $wpdb->insert_id;

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        $_GET = [];
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- the default ------------------------------------------------------

    public function test_a_season_that_has_not_started_gives_no_default(): void {
        $this->currentSeasonStarting( '+30 days' );

        $this->assertNull( ReportFilters::seasonDefaultWindow() );
    }

    public function test_a_season_that_has_started_still_gives_the_season_so_far(): void {
        $start = $this->currentSeasonStarting( '-30 days' );

        $this->assertSame( [ 'from' => $start, 'to' => gmdate( 'Y-m-d' ) ], ReportFilters::seasonDefaultWindow() );
    }

    // ---- the player report -------------------------------------------------

    public function test_the_player_report_without_dates_asks_for_a_period(): void {
        $this->currentSeasonStarting( '+30 days' );

        $html = $this->render( 'player-report', [ 'player_id' => (string) $this->player ] );

        $this->assertStringContainsString( self::PICK, $html );
        $this->assertStringContainsString( 'data-tt-filterbar', $html, 'the filter bar is there to pick one with' );
        $this->assertStringNotContainsString( 'data-tt-player-report', $html, 'no report over a window that cannot hold anything' );
    }

    public function test_the_player_report_with_a_picked_window_renders_before_the_season(): void {
        $this->currentSeasonStarting( '+30 days' );

        $html = $this->render( 'player-report', [ 'player_id' => (string) $this->player, 'from' => '2020-03-01', 'to' => '2020-03-31' ] );

        $this->assertStringContainsString( 'data-tt-player-report', $html );
        $this->assertStringNotContainsString( self::PICK, $html );
    }

    public function test_a_from_without_a_to_runs_up_to_today_before_the_season(): void {
        $this->currentSeasonStarting( '+30 days' );

        $html = $this->render( 'player-report', [ 'player_id' => (string) $this->player, 'from' => '2020-03-01' ] );

        $this->assertStringContainsString( 'data-tt-player-report', $html );
        $this->assertStringNotContainsString( self::PICK, $html );
        $this->assertStringContainsString( 'value="' . gmdate( 'Y-m-d' ) . '"', $html, 'the bar carries today as the end' );
    }

    public function test_a_backwards_window_on_the_player_report_is_a_form_error(): void {
        $html = $this->render( 'player-report', [ 'player_id' => (string) $this->player, 'from' => '2020-03-31', 'to' => '2020-03-01' ] );

        $this->assertStringContainsString( self::BACKWARDS, $html );
        $this->assertStringNotContainsString( 'data-tt-player-report', $html );
    }

    public function test_the_pdf_export_without_a_window_answers_422(): void {
        $this->currentSeasonStarting( '+30 days' );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/exports/player_report_pdf' );
        $request->set_query_params( [ 'format' => 'pdf', 'player_id' => (string) $this->player ] );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 422, $response->get_status() );
        $error = $response->as_error();
        $this->assertNotNull( $error );
        $this->assertSame( 'no_period', $error->get_error_code() );
        $this->assertSame( self::PICK, $error->get_error_message() );
    }

    public function test_the_pdf_exporter_refuses_no_window_with_no_period(): void {
        $this->currentSeasonStarting( '+30 days' );

        try {
            ( new PlayerReportPdfExporter() )->validateFilters( [ 'player_id' => $this->player ] );
            $this->fail( 'No window and no season so far must not reach the composer.' );
        } catch ( ExportException $e ) {
            $this->assertSame( 'no_period', $e->errorKey );
        }

        $clean = ( new PlayerReportPdfExporter() )->validateFilters( [ 'player_id' => $this->player, 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertIsArray( $clean, 'a picked window still prints' );
    }

    // ---- another report on the same default --------------------------------

    public function test_the_squad_evaluation_summary_without_dates_asks_for_a_period(): void {
        $this->currentSeasonStarting( '+30 days' );

        $html = $this->render( 'team-squad-evaluation-summary', [ 'team_id' => (string) $this->team ] );

        $this->assertStringContainsString( self::PICK, $html );
        $this->assertStringContainsString( 'data-tt-filterbar', $html );
        $this->assertStringNotContainsString( 'Squad average rating', $html, 'no KPI strip over an empty window' );
    }

    public function test_a_backwards_window_on_the_squad_evaluation_summary_is_a_form_error(): void {
        $html = $this->render( 'team-squad-evaluation-summary', [ 'team_id' => (string) $this->team, 'from' => '2020-03-31', 'to' => '2020-03-01' ] );

        $this->assertStringContainsString( self::BACKWARDS, $html );
        $this->assertStringNotContainsString( 'Squad average rating', $html );
    }

    public function test_the_squad_evaluation_summary_opens_as_before_once_the_season_has_started(): void {
        $this->currentSeasonStarting( '-30 days' );

        $html = $this->render( 'team-squad-evaluation-summary', [ 'team_id' => (string) $this->team ] );

        $this->assertStringContainsString( 'Squad average rating', $html );
        $this->assertStringNotContainsString( self::PICK, $html );
    }

    // ---- fixtures -----------------------------------------------------------

    /** Make a season current that starts `$offset` from today; returns its start. */
    private function currentSeasonStarting( string $offset ): string {
        global $wpdb;
        $start = gmdate( 'Y-m-d', (int) strtotime( $offset ) );
        $wpdb->update( "{$wpdb->prefix}tt_seasons", [ 'is_current' => 0 ], [ 'club_id' => CurrentClub::id() ] );
        $wpdb->insert( "{$wpdb->prefix}tt_seasons", [
            'club_id'    => CurrentClub::id(),
            'name'       => 'Period season',
            'start_date' => $start,
            'end_date'   => gmdate( 'Y-m-d', (int) strtotime( $offset . ' +300 days' ) ),
            'is_current' => 1,
        ] );
        return $start;
    }

    /** @param array<string,string> $get */
    private function render( string $slug, array $get ): string {
        $_GET = array_merge( [ 'tt_view' => 'standard-report', 'slug' => $slug ], $get );
        ob_start();
        FrontendStandardReportsView::render( get_current_user_id(), false );
        return (string) ob_get_clean();
    }
}
