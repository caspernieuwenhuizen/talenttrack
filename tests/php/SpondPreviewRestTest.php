<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2284 — dry-run Spond integration monitor preview route.
 *
 * The monitor fetches Spond LIVE and diffs each event against the stored
 * activity WITHOUT writing anything, so an operator can diagnose why a
 * printed activity differs from Spond. This test covers the two things
 * that don't need a live Spond call: that the route is registered with
 * the right method + the tt_edit_teams permission gate, and that hitting
 * it for a team with no spond_group_id returns the "no group linked"
 * envelope (ok:false inside a 200) rather than fataling.
 */
final class SpondPreviewRestTest extends WP_UnitTestCase {

    private const ROUTE = '/talenttrack/v1/teams/(?P<id>\d+)/spond/preview';

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

        // A team with NO Spond group linked.
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => 1,
            'name'    => 'Unlinked team',
        ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_filter( 'pre_http_request', [ $this, 'fakeSpond' ], 10 );
        parent::tear_down();
    }

    public function test_preview_route_is_registered_with_post_and_permission_callback(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( self::ROUTE, $routes );

        $methods  = [];
        $has_perm = false;
        foreach ( $routes[ self::ROUTE ] as $endpoint ) {
            $methods = array_merge( $methods, array_keys( (array) $endpoint['methods'] ) );
            if ( ! empty( $endpoint['permission_callback'] ) ) {
                $has_perm = true;
            }
        }

        $this->assertContains( 'POST', $methods, 'preview route accepts POST' );
        $this->assertTrue( $has_perm, 'preview route declares a permission_callback (not __return_true)' );
    }

    public function test_preview_denied_without_edit_teams_capability(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/teams/' . $this->team_id . '/spond/preview' );
        $res = rest_do_request( $req );

        // A logged-in user lacking the cap is Forbidden (403); 401 is for
        // an unauthenticated request.
        $this->assertSame( 403, $res->get_status(), 'a user without tt_edit_teams is rejected' );
    }

    public function test_team_with_no_group_returns_no_group_envelope(): void {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/teams/' . $this->team_id . '/spond/preview' );
        $res = rest_do_request( $req );

        // 200 envelope with ok:false — not a fatal, not an HTTP error.
        $this->assertSame( 200, $res->get_status() );

        $data = $res->get_data();
        $this->assertTrue( (bool) ( $data['success'] ?? false ) );
        $this->assertFalse( (bool) ( $data['data']['ok'] ?? true ), 'ok is false for an unlinked team' );
        $this->assertSame( 'no_group_linked', $data['data']['error_code'] ?? '' );
    }

    // #4562 — the preview diffs the times a sync would write, so a match
    // whose end the sync filled in is not shown as losing it. The import
    // and the preview run end to end, with Spond answered through
    // `pre_http_request`, as SpondLateKickoffEndTest does.

    public function test_a_match_with_the_fallback_end_shows_no_end_time_change(): void {
        $team = $this->linkedTeam();
        $this->feed = [ $this->event( 'spond-ev-4562-a', 'JO13-1 - vv Hedel', true, '11:00', '' ) ];
        $this->syncAndAssertEnd( $team, 'spond-ev-4562-a', '12:45:00' );

        $event = $this->previewEvent( $team, 'spond-ev-4562-a' );
        $this->assertSame( [], $this->changed( $event, 'end_time' ), 'a sync would write the same fallback end' );
        $this->assertSame( '12:45:00', $event['end_time'], 'the payload shows the end the sync would write' );
    }

    public function test_a_match_whose_spond_end_changed_still_shows_the_change(): void {
        $team = $this->linkedTeam();
        $this->feed = [ $this->event( 'spond-ev-4562-b', 'JO13-1 - vv Hedel', true, '11:00', '' ) ];
        $this->syncAndAssertEnd( $team, 'spond-ev-4562-b', '12:45:00' );

        $this->feed = [ $this->event( 'spond-ev-4562-b', 'JO13-1 - vv Hedel', true, '11:00', '13:30' ) ];
        $event = $this->previewEvent( $team, 'spond-ev-4562-b' );
        $this->assertSame( [ [ 'field' => 'end_time', 'from' => '12:45', 'to' => '13:30' ] ], $this->changed( $event, 'end_time' ) );
        $this->assertSame( '13:30:00', $event['end_time'] );
    }

    public function test_a_training_without_a_spond_end_still_shows_the_end_cleared(): void {
        $team = $this->linkedTeam();
        $this->feed = [ $this->event( 'spond-ev-4562-c', 'Training', false, '18:00', '19:30' ) ];
        $this->syncAndAssertEnd( $team, 'spond-ev-4562-c', '19:30:00' );

        $this->feed = [ $this->event( 'spond-ev-4562-c', 'Training', false, '18:00', '' ) ];
        $event = $this->previewEvent( $team, 'spond-ev-4562-c' );
        $this->assertSame( [ [ 'field' => 'end_time', 'from' => '19:30', 'to' => '' ] ], $this->changed( $event, 'end_time' ), 'a training gets no fallback end' );
        $this->assertSame( '', $event['end_time'] );
    }

    public function test_a_locked_group_is_never_listed_as_changing(): void {
        $team = $this->linkedTeam();
        $this->feed = [ $this->event( 'spond-ev-4562-d', 'JO13-1 - vv Hedel', true, '11:00', '' ) ];
        $this->syncAndAssertEnd( $team, 'spond-ev-4562-d', '12:45:00' );

        // A coach edited the times in TalentTrack: the sync leaves them alone.
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tt_activities",
            [ 'sync_locked_fields' => (string) wp_json_encode( [ 'times' ] ) ],
            [ 'external_id' => 'spond-ev-4562-d' ]
        );

        $this->feed = [ $this->event( 'spond-ev-4562-d', 'JO13-1 - vv Hedel (verplaatst)', true, '11:30', '13:30' ) ];
        $event = $this->previewEvent( $team, 'spond-ev-4562-d' );
        $fields = array_column( (array) $event['changes'], 'field' );
        $this->assertSame( [ 'title' ], $fields, 'the locked times are not listed; the unlocked title still is' );

        $wpdb->update(
            "{$wpdb->prefix}tt_activities",
            [ 'sync_locked_fields' => (string) wp_json_encode( [ 'times', 'title' ] ) ],
            [ 'external_id' => 'spond-ev-4562-d' ]
        );
        $this->assertSame( [], $this->previewEvent( $team, 'spond-ev-4562-d' )['changes'], 'nothing the sync would write' );
    }

    public function test_the_fallback_end_has_one_implementation(): void {
        $this->assertTrue( method_exists( \TT\Modules\Spond\SpondSync::class, 'matchEndFallback' ) );
        $this->assertFalse( method_exists( \TT\Infrastructure\REST\SpondRestController::class, 'matchEndFallback' ), 'the preview reuses the sync\'s' );
        $cols = \TT\Modules\Spond\SpondSync::timeColumns( 'match', '22:30:00', '', '' );
        $this->assertSame( '00:15:00', $cols['end_time'] );
    }

    /** @var list<array<string,mixed>> the events the fake Spond feed returns */
    private array $feed = [];

    private string $day = '';

    private function linkedTeam(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => 1, 'name' => 'JO13-1', 'spond_group_id' => 'grp-4562',
        ] );
        // Read before the credentials save, which writes a row of its own.
        $team_id = (int) $wpdb->insert_id;
        \TT\Modules\Spond\CredentialsManager::save( 'coach@example.test', 'secret' );
        $this->day = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
        add_filter( 'pre_http_request', [ $this, 'fakeSpond' ], 10, 3 );
        return $team_id;
    }

    /** @return array<string,mixed> */
    private function event( string $uid, string $heading, bool $match, string $start, string $end ): array {
        $day = $this->day !== '' ? $this->day : gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
        $event = [
            'id'             => $uid,
            'heading'        => $heading,
            'startTimestamp' => $day . 'T' . $start . ':00.000Z',
            'matchEvent'     => $match,
        ];
        if ( $end !== '' ) $event['endTimestamp'] = $day . 'T' . $end . ':00.000Z';
        return $event;
    }

    private function syncAndAssertEnd( int $team, string $uid, string $end ): void {
        $summary = \TT\Modules\Spond\SpondSync::syncTeam( $team );
        $this->assertSame( 'ok', $summary['status'] ?? '', (string) ( $summary['last_message'] ?? '' ) );
        global $wpdb;
        $stored = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT end_time FROM {$wpdb->prefix}tt_activities WHERE external_id = %s", $uid
        ) );
        $this->assertSame( $end, $stored, 'the sync stored this end' );
    }

    /** @return array<string,mixed> */
    private function previewEvent( int $team, string $uid ): array {
        $res = rest_do_request( new WP_REST_Request( 'POST', '/talenttrack/v1/teams/' . $team . '/spond/preview' ) );
        $this->assertSame( 200, $res->get_status() );
        $data = (array) ( ( (array) $res->get_data() )['data'] ?? [] );
        $this->assertTrue( (bool) ( $data['ok'] ?? false ), 'the preview fetched the feed' );
        foreach ( (array) ( $data['events'] ?? [] ) as $event ) {
            if ( ( $event['uid'] ?? '' ) === $uid ) {
                $this->assertSame( 'update', $event['status'] );
                return (array) $event;
            }
        }
        $this->fail( 'no preview event for ' . $uid );
    }

    /**
     * @param array<string,mixed> $event
     * @return list<array<string,string>>
     */
    private function changed( array $event, string $field ): array {
        return array_values( array_filter(
            (array) ( $event['changes'] ?? [] ),
            static fn( $c ): bool => ( $c['field'] ?? '' ) === $field
        ) );
    }

    /**
     * @param mixed               $pre
     * @param array<string,mixed> $args
     * @return mixed
     */
    public function fakeSpond( $pre, $args, string $url ) {
        if ( strpos( $url, '/auth2/login' ) !== false ) {
            return $this->response( [ 'accessToken' => [ 'token' => 'test-token' ] ] );
        }
        if ( strpos( $url, '/sponds/' ) !== false ) return $this->response( $this->feed );
        return $pre;
    }

    /**
     * @param mixed $body
     * @return array<string,mixed>
     */
    private function response( $body ): array {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode( $body ),
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }
}
