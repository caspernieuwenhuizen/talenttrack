<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Training\Frontend\FrontendTrainingRunView;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * #3671 — the notes sheet on the sideline view before and after the
 * register is taken.
 *
 * Attendance comes first: only players marked present or late can be
 * observed. What changed is that a run with nobody on the register used
 * to render no sheet at all, and a coach at the start of a training read
 * that as the feature not existing. The sheet now always renders, and
 * before attendance it says what unlocks it and links to the register.
 */
final class TrainingRunNotesSheetTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1';

    /** @var array<string,mixed> */
    private array $get_backup = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->get_backup = $_GET;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        FeatureRegistry::setEnabled( 'attendance_grid', true );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = $this->get_backup;
        FeatureRegistry::setEnabled( 'attendance_grid', true );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    /** @return array{run_id:int, activity_id:int} */
    private function makeRun(): array {
        $plan_id = ( new TrainingPlansRepository() )->create( [
            'club_id' => 1, 'team_id' => 7, 'title' => 'Balbezit onder druk',
        ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 1, 'team_id' => 7,
            'session_date' => '2026-09-15', 'activity_type_key' => 'training',
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $run_id = ( new TrainingPlanRunsRepository() )->attach(
            (int) $plan_id,
            $activity_id,
            7,
            '2026-09-15'
        );

        return [ 'run_id' => $run_id, 'activity_id' => $activity_id ];
    }

    private function makePlayer( string $first, string $last ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => 7, 'first_name' => $first, 'last_name' => $last,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function register( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'record_type' => 'actual',
        ] );
    }

    private function renderRun( int $run_id ): string {
        $_GET['tt_view'] = 'training-run';
        $_GET['id']      = (string) $run_id;

        ob_start();
        FrontendTrainingRunView::render( get_current_user_id(), true );

        return (string) ob_get_clean();
    }

    /** The first `.tt-obs` section in the page, or '' when there is none. */
    private function sheet( string $html ): string {
        return preg_match( '#<section class="tt-obs[^"]*"[^<]*.*?</section>#s', $html, $m ) ? $m[0] : '';
    }

    // ---- before the register ----------------------------------------------

    public function test_a_run_with_no_register_still_renders_the_sheet(): void {
        $run   = $this->makeRun();
        $sheet = $this->sheet( $this->renderRun( $run['run_id'] ) );

        $this->assertNotSame( '', $sheet, 'the sheet is never silently absent' );
        $this->assertStringContainsString( 'Notes on players', $sheet );
        $this->assertStringContainsString( 'Take attendance to add notes on players.', $sheet );
        $this->assertStringNotContainsString( 'data-tt-obs-player', $sheet, 'no rows before the register' );
    }

    public function test_the_empty_sheet_links_to_this_trainings_attendance_grid(): void {
        $run   = $this->makeRun();
        $sheet = $this->sheet( $this->renderRun( $run['run_id'] ) );

        $this->assertMatchesRegularExpression( '#<a class="[^"]*tt-obs__attend[^"]*" href="([^"]+)"#', $sheet );
        preg_match( '#tt-obs__attend[^"]*" href="([^"]+)"#', $sheet, $m );
        $href = html_entity_decode( $m[1] );

        $this->assertStringContainsString( 'tt_view=attendance-grid', $href );
        $this->assertStringContainsString( 'team_id=7', $href );
        $this->assertStringContainsString( 'from=2026-09-15', $href );
        $this->assertStringContainsString( 'to=2026-09-15', $href );
    }

    public function test_without_the_grid_the_link_goes_to_the_activity(): void {
        FeatureRegistry::setEnabled( 'attendance_grid', false );

        $run   = $this->makeRun();
        $sheet = $this->sheet( $this->renderRun( $run['run_id'] ) );

        preg_match( '#tt-obs__attend[^"]*" href="([^"]+)"#', $sheet, $m );
        $this->assertNotEmpty( $m, 'a coach without the grid still gets a way to the register' );

        $href = html_entity_decode( $m[1] );
        $this->assertStringContainsString( 'tt_view=activities', $href );
        $this->assertStringContainsString( 'id=' . $run['activity_id'], $href );
    }

    // ---- after the register -----------------------------------------------

    public function test_the_players_marked_present_are_listed_with_scale_and_note(): void {
        $run   = $this->makeRun();
        $sem   = $this->makePlayer( 'Sem', 'Bakker' );
        $daan  = $this->makePlayer( 'Daan', 'Visser' );
        $finn  = $this->makePlayer( 'Finn', 'Jansen' );
        $this->register( $run['activity_id'], $sem, 'present' );
        $this->register( $run['activity_id'], $daan, 'present' );
        $this->register( $run['activity_id'], $finn, 'absent' );

        $sheet = $this->sheet( $this->renderRun( $run['run_id'] ) );

        preg_match_all( '#data-tt-obs-player="(\d+)"#', $sheet, $m );
        $listed = array_map( 'intval', $m[1] );
        sort( $listed );
        $expected = [ $sem, $daan ];
        sort( $expected );

        $this->assertSame( $expected, $listed, 'exactly the two present players' );
        $this->assertSame( 2, substr_count( $sheet, 'data-tt-obs-note' ) );
        $this->assertStringContainsString( 'data-tt-obs-value', $sheet );
        $this->assertStringNotContainsString( 'tt-obs__attend', $sheet, 'no register prompt once people are on it' );
    }

    public function test_a_note_saved_after_the_register_shows_in_the_run_observations(): void {
        $run    = $this->makeRun();
        $player = $this->makePlayer( 'Levi', 'Meijer' );
        $this->register( $run['activity_id'], $player, 'present' );

        $post = new WP_REST_Request( 'POST', self::BASE . "/training/runs/{$run['run_id']}/observations" );
        $post->set_param( 'player_id', $player );
        $post->set_param( 'note', 'Goed gescand onder druk.' );
        $this->assertSame( 201, rest_get_server()->dispatch( $post )->get_status() );

        $get      = new WP_REST_Request( 'GET', self::BASE . "/training/runs/{$run['run_id']}/observations" );
        $response = rest_get_server()->dispatch( $get );
        $this->assertSame( 200, $response->get_status() );

        $notes = wp_json_encode( $response->get_data() );
        $this->assertIsString( $notes );
        $this->assertStringContainsString( 'Goed gescand onder druk.', $notes );
    }

    // ---- the pre-start block names ----------------------------------------

    public function test_the_block_list_strings_reach_the_script(): void {
        $run = $this->makeRun();
        $this->renderRun( $run['run_id'] );

        $data = (string) wp_scripts()->get_data( 'tt-frontend-training-run', 'data' );

        $this->assertStringContainsString( '"blockLine"', $data );
        $this->assertStringContainsString( '"segLabel"', $data );
    }
}
