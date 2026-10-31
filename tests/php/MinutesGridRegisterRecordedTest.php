<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Frontend\FrontendMinutesGridView;
use TT\Modules\Activities\Reports\MinutesGridQuery;

/**
 * #4247 — a match whose register was never taken says so.
 *
 * The squad is the recorded register (#3451), so a match with a saved
 * line-up and no register has every player at `squad: false`. That stays;
 * what changes is that the read carries `register_recorded`, and the grid
 * shows one notice for such a column instead of a dash per player.
 */
final class MinutesGridRegisterRecordedTest extends WP_UnitTestCase {

    private const TEAM_ID = 4772;

    /** @var string */
    private $p;

    /** @var list<int> */
    private $player_ids = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
        ( new RolesService() )->installRoles();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'id' => self::TEAM_ID, 'name' => 'U12' ] );
        foreach ( [ 4, 8 ] as $shirt ) {
            $this->player_ids[] = $this->seedPlayer( $shirt );
        }
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_match_with_a_line_up_but_no_register_reports_it(): void {
        $match = $this->seedMatch( '2026-03-21' );
        foreach ( $this->player_ids as $pid ) {
            $this->seedAttendance( $match, $pid, 'expected' );
        }

        $data = $this->minutesFor( $match );

        $this->assertFalse( $data['activity']['register_recorded'] );
        $this->assertSame( 0, $data['summary']['squad_players'] );
        foreach ( $data['players'] as $player ) {
            $this->assertFalse( $player['squad'] );
        }
    }

    public function test_a_recorded_register_reports_it_and_fills_the_squad(): void {
        $match = $this->seedMatch( '2026-03-22' );
        $this->seedAttendance( $match, $this->player_ids[0], 'actual', 50 );

        $data = $this->minutesFor( $match );

        $this->assertTrue( $data['activity']['register_recorded'] );
        $this->assertSame( 1, $data['summary']['squad_players'] );
        $this->assertSame( 50, $data['summary']['total_minutes'] );
    }

    /** A guest-only register was taken, but it puts no roster player in the squad. */
    public function test_a_guest_only_register_counts_as_recorded_without_widening_the_squad(): void {
        $match = $this->seedMatch( '2026-03-23' );
        $this->seedAttendance( $match, $this->player_ids[0], 'actual', 30, 1 );

        $data = $this->minutesFor( $match );

        $this->assertTrue( $data['activity']['register_recorded'] );
        $this->assertSame( 0, $data['summary']['squad_players'] );
    }

    public function test_the_grid_shows_one_notice_for_a_column_without_a_register(): void {
        $match = $this->seedMatch( '2026-03-24' );
        $matrix = ( new MinutesGridQuery() )->matrix( self::TEAM_ID, '2026-03-24', '2026-03-24' );

        $render = new \ReflectionMethod( FrontendMinutesGridView::class, 'renderGrid' );
        $render->setAccessible( true );
        ob_start();
        $render->invoke( null, $matrix );
        $html = (string) ob_get_clean();

        $this->assertSame( 1, substr_count( $html, 'tt-agrid-cell--noreg' ) );
        $this->assertStringContainsString( 'rowspan="2"', $html );
        $this->assertStringContainsString( 'data-activity="' . $match . '"', $html );
        $this->assertStringNotContainsString( 'tt-agrid-cell--na', $html );
    }

    /**
     * A match finalized on the live sheet goes straight to the attendance
     * grid; the completion URL would land on the finished sheet instead.
     */
    public function test_a_finalized_live_match_links_to_the_attendance_grid(): void {
        global $wpdb;
        $match = $this->seedMatch( '2026-03-25' );
        $wpdb->insert( "{$this->p}tt_match_execution", [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => 1,
            'activity_id'   => $match,
            'match_prep_id' => 0,
            'state'         => 'finalized',
        ] );

        $href = $this->recordLinkFor( $match );

        $this->assertStringContainsString( 'tt_view=attendance-grid', $href );
        $this->assertSame(
            remove_query_arg( 'tt_back', \TT\Modules\Activities\Services\ActivityGridLink::attendanceUrl( $match, false ) ),
            remove_query_arg( 'tt_back', $href )
        );
    }

    /** A paper match keeps the completion URL: the wizard's attendance step. */
    public function test_a_paper_match_links_to_the_completion_flow(): void {
        $match = $this->seedMatch( '2026-03-26' );

        $href = $this->recordLinkFor( $match );

        $this->assertStringNotContainsString( 'tt_view=match-execution', $href );
        $this->assertSame(
            remove_query_arg( 'tt_back', \TT\Modules\Activities\Services\ActivityCompletionResolver::completionUrl( $match, 'match' ) ),
            remove_query_arg( 'tt_back', $href )
        );
    }

    // ---- fixtures ----

    /** The decoded href of the column's "Record attendance" link. */
    private function recordLinkFor( int $activity_id ): string {
        global $wpdb;
        $date   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT session_date FROM {$this->p}tt_activities WHERE id = %d", $activity_id ) );
        $matrix = ( new MinutesGridQuery() )->matrix( self::TEAM_ID, $date, $date );

        $render = new \ReflectionMethod( FrontendMinutesGridView::class, 'renderGrid' );
        $render->setAccessible( true );
        ob_start();
        $render->invoke( null, $matrix );
        $html = (string) ob_get_clean();

        $this->assertSame( 1, preg_match( '#class="tt-btn tt-btn-secondary tt-agrid-noreg__link" href="([^"]+)"#', $html, $m ), 'no Record attendance link rendered' );
        return html_entity_decode( $m[1], ENT_QUOTES );
    }

    /** @return array<string,mixed> */
    private function minutesFor( int $activity_id ): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $activity_id . '/minutes' ) );
        $this->assertSame( 200, $response->get_status() );
        return (array) $response->get_data()['data'];
    }

    private function seedMatch( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => 1,
            'team_id'             => self::TEAM_ID,
            'title'               => 'U12 vs PSV ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'game',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
            'opponent'            => 'PSV',
            'home_away'           => 'home',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedPlayer( int $shirt ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => 1,
            'team_id'       => self::TEAM_ID,
            'first_name'    => 'Speler',
            'last_name'     => 'Rugnummer ' . $shirt,
            'jersey_number' => $shirt,
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedAttendance( int $activity_id, int $player_id, string $type, ?int $minutes = null, int $is_guest = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => 1,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'is_guest'       => $is_guest,
            'record_type'    => $type,
            'status'         => 'present',
            'minutes_played' => $minutes,
        ] );
    }
}
