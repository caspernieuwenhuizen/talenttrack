<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Shared\Frontend\FrontendActivitiesManageView;

/**
 * #3678 — the activity detail shows every time it stores.
 *
 * A coach saved presence 18:15, start 18:45 and end 19:45 on a match and
 * the detail page answered "Kick-off 18:45" and nothing else. Both losses
 * were in the facts strip: `time_of_presence` was never read by any render
 * path in the view, and `end_time` only existed inside the `18:45–19:45`
 * window string, which the Kick-off cell then cut to its first five
 * characters.
 *
 * The presence time is the one a parent plans the match day around, so a
 * value that is stored, echoed back by the edit form, and then invisible on
 * the page everybody else reads is the worst of the three states.
 */
final class ActivityDetailTimesTest extends WP_UnitTestCase {

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Ajax U17' ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- the reported case -------------------------------------------

    public function test_a_match_shows_presence_kick_off_and_end_time(): void {
        $id   = $this->createActivity( 'game', '18:45', '19:45', '18:15' );
        $html = $this->renderFacts( $id );

        $this->assertStringContainsString( 'Presence time', $html );
        $this->assertStringContainsString( '18:15', $html, 'the saved meet-up time' );
        $this->assertStringContainsString( 'Kick-off', $html );
        $this->assertStringContainsString( '18:45', $html, 'the saved start time' );
        $this->assertStringContainsString( 'End time', $html );
        $this->assertStringContainsString( '19:45', $html, 'the saved end time' );
    }

    /** The three cells read in the order the match day happens. */
    public function test_the_time_cells_run_presence_then_kick_off_then_end(): void {
        $html = $this->renderFacts( $this->createActivity( 'game', '18:45', '19:45', '18:15' ) );

        $presence = strpos( $html, '18:15' );
        $kick     = strpos( $html, '18:45' );
        $end      = strpos( $html, '19:45' );

        $this->assertIsInt( $presence );
        $this->assertIsInt( $kick );
        $this->assertIsInt( $end );
        $this->assertLessThan( $kick, $presence, 'presence comes before kick-off' );
        $this->assertLessThan( $end, $kick, 'kick-off comes before the end time' );
    }

    /** Seconds are stored; the strip reads clock time. */
    public function test_the_values_are_rendered_as_hh_mm(): void {
        $html = $this->renderFacts( $this->createActivity( 'game', '18:45', '19:45', '18:15' ) );

        $this->assertStringNotContainsString( '18:15:00', $html );
        $this->assertStringNotContainsString( '19:45:00', $html );
    }

    // ---- no empty placeholders ---------------------------------------

    public function test_a_match_without_the_optional_times_shows_neither_cell(): void {
        $html = $this->renderFacts( $this->createActivity( 'game', '18:45', '', '' ) );

        $this->assertStringContainsString( 'Kick-off', $html );
        $this->assertStringNotContainsString( 'Presence time', $html );
        $this->assertStringNotContainsString( 'End time', $html );
    }

    public function test_a_match_with_an_end_time_but_no_presence_shows_only_the_end(): void {
        $html = $this->renderFacts( $this->createActivity( 'game', '18:45', '19:45', '' ) );

        $this->assertStringNotContainsString( 'Presence time', $html );
        $this->assertStringContainsString( 'End time', $html );
        $this->assertStringContainsString( '19:45', $html );
    }

    // ---- the non-match branch ----------------------------------------

    /**
     * `$is_match` in the view is `game` / `match` alone, but the edit form
     * offers the presence row for friendlies and tournaments too, so a
     * stored meet-up time can land on a row that renders in this branch.
     */
    public function test_a_tournament_shows_its_stored_presence_time(): void {
        $html = $this->renderFacts( $this->createActivity( 'tournament', '09:30', '15:00', '09:00' ) );

        $this->assertStringContainsString( 'Presence time', $html );
        $this->assertStringContainsString( '09:00', $html );
        // This branch keeps its single Time cell; the window already
        // carries both ends, so no separate End time cell here.
        $this->assertStringContainsString( '09:30', $html );
        $this->assertStringContainsString( '15:00', $html );
    }

    public function test_a_training_with_a_stored_presence_time_shows_it(): void {
        $id = $this->createActivity( 'training', '18:00', '19:30', '' );
        global $wpdb;
        // The form hides the row for a training, so write the column
        // directly — the point is that a stored value is readable.
        $wpdb->update( $wpdb->prefix . 'tt_activities', [ 'time_of_presence' => '17:45:00' ], [ 'id' => $id ] );

        $html = $this->renderFacts( $id );
        $this->assertStringContainsString( 'Presence time', $html );
        $this->assertStringContainsString( '17:45', $html );
    }

    public function test_a_training_without_one_is_unchanged(): void {
        $html = $this->renderFacts( $this->createActivity( 'training', '18:00', '19:30', '' ) );

        $this->assertStringNotContainsString( 'Presence time', $html );
        $this->assertStringContainsString( '18:00', $html );
    }

    // ---- the stored values themselves are untouched ------------------

    public function test_the_rest_read_still_returns_the_three_stored_times(): void {
        $id = $this->createActivity( 'game', '18:45', '19:45', '18:15' );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [ 'team_id' => $this->team_id, 'per_page' => 100 ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        $found = null;
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $item ) {
            $row = (array) $item;
            if ( (int) ( $row['id'] ?? 0 ) === $id ) { $found = $row; break; }
        }
        $this->assertNotNull( $found, 'the activity is in the list read' );
        $this->assertSame( '18:45:00', (string) $found['start_time'] );
        $this->assertSame( '19:45:00', (string) $found['end_time'] );
        $this->assertSame( '18:15:00', (string) $found['time_of_presence'] );
    }

    // ---- helpers ------------------------------------------------------

    private function createActivity( string $type, string $start, string $end, string $presence ): int {
        $body = [
            'title'             => 'Fixture ' . $type . ' ' . $start,
            'session_date'      => gmdate( 'Y-m-d', (int) strtotime( '+3 days' ) ),
            'team_id'           => $this->team_id,
            'activity_type_key' => $type,
            'start_time'        => $start,
        ];
        if ( $end !== '' )      $body['end_time']         = $end;
        if ( $presence !== '' ) $body['time_of_presence'] = $presence;

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        $this->assertSame( 200, rest_do_request( $req )->get_status(), 'activity created' );

        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_activities WHERE title = %s ORDER BY id DESC LIMIT 1",
            (string) $body['title']
        ) );
        $this->assertGreaterThan( 0, $id );

        // A match type defaults its end to kick-off + 105 minutes, which
        // would make "no end time" untestable. Clear it explicitly.
        if ( $end === '' ) {
            $wpdb->update( $wpdb->prefix . 'tt_activities', [ 'end_time' => null ], [ 'id' => $id ] );
        }

        return $id;
    }

    /** Render the detail facts strip for a stored activity. */
    private function renderFacts( int $id ): string {
        $row = ( new ActivitiesRepository() )->findByIdIncludingArchived( $id );
        $this->assertNotNull( $row, 'the activity row loads' );

        $type_key = (string) ( $row->activity_type_key ?? 'training' );
        $is_match = in_array( strtolower( $type_key ), [ 'game', 'match' ], true );

        $start  = (string) ( $row->start_time ?? '' );
        $finish = (string) ( $row->end_time ?? '' );
        $window = $start !== ''
            ? substr( $start, 0, 5 ) . ( $finish !== '' ? '–' . substr( $finish, 0, 5 ) : '' )
            : '';

        $method = new ReflectionMethod( FrontendActivitiesManageView::class, 'renderDetailFacts' );
        $method->setAccessible( true );

        ob_start();
        $method->invoke(
            null,
            $row,
            $type_key,
            (string) ( $row->activity_status_key ?? 'planned' ),
            $is_match,
            $window
        );

        return (string) ob_get_clean();
    }
}
