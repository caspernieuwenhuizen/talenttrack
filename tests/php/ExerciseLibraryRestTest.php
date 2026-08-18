<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Exercises\ExercisesRepository;

/**
 * #2495 — the exercise library surface: browse contract, the authoring
 * default, and the promotion gate.
 *
 * The promotion gate is the one that matters. `tt_manage_exercises` is
 * seeded `rcd global` to both coach personas as well as the head of
 * development, so gating promotion on it — even at global scope — would
 * let any coach make their own drill club-wide, which is the exact
 * decision the queue exists to route elsewhere.
 */
final class ExerciseLibraryRestTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/exercises';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** Administrators bypass every tt_* cap, so they can curate. */
    private function curator(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );
        return $id;
    }

    /**
     * A user who may author exercises but not curate the methodology —
     * the coach case the promotion gate has to refuse.
     */
    private function author(): int {
        $id   = self::factory()->user->create( [ 'role' => 'editor' ] );
        $user = get_user_by( 'id', $id );
        $user->add_cap( 'tt_manage_exercises' );
        $user->add_cap( 'tt_view_activities' );
        wp_set_current_user( $id );
        return $id;
    }

    /** @return array{0:int,1:mixed,2:string|null} */
    private function call( string $method, string $route, array $params = [] ): array {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) $request->set_param( $k, $v );
        $response = rest_get_server()->dispatch( $request );
        $envelope = $response->get_data();

        return [
            $response->get_status(),
            is_array( $envelope ) ? ( $envelope['data'] ?? null ) : $envelope,
            is_array( $envelope ) ? ( $envelope['errors'][0]['code'] ?? null ) : null,
        ];
    }

    private function makeExercise( string $name, string $visibility = 'team' ): int {
        return ( new ExercisesRepository() )->create( [
            'name'             => $name,
            'visibility'       => $visibility,
            'duration_minutes' => 20,
        ] );
    }

    public function test_routes_are_registered(): void {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey( self::BASE . '/promotion-queue', $routes );
        $this->assertArrayHasKey( self::BASE . '/(?P<id>\d+)/promote', $routes );
    }

    public function test_browse_returns_the_list_table_contract(): void {
        $this->curator();
        $this->makeExercise( 'Rondo 5v2', 'club' );

        [ $status, $data ] = $this->call( 'GET', self::BASE, [ 'browse' => 1, 'per_page' => 10 ] );

        $this->assertSame( 200, $status );
        foreach ( [ 'rows', 'total', 'page', 'per_page' ] as $key ) {
            $this->assertArrayHasKey( $key, $data, "the list table reads data.{$key}" );
        }
        $this->assertNotEmpty( $data['rows'][0]['detail_url'] );
        $this->assertArrayHasKey( 'origin_label', $data['rows'][0] );
        $this->assertArrayHasKey( 'visibility_label', $data['rows'][0] );
    }

    public function test_plain_list_keeps_its_items_shape_for_existing_callers(): void {
        $this->curator();
        $this->makeExercise( 'Passing square', 'club' );

        [ $status, $data ] = $this->call( 'GET', self::BASE );

        $this->assertSame( 200, $status );
        $this->assertArrayHasKey(
            'items',
            $data,
            'the picker and existing API consumers read items — browse is additive'
        );
    }

    public function test_browse_search_filters_rows_and_total_together(): void {
        $this->curator();
        $this->makeExercise( 'Opbouw 7v5', 'club' );
        $this->makeExercise( 'Kopduel in de zestien', 'club' );

        [ , $data ] = $this->call( 'GET', self::BASE, [ 'browse' => 1, 'search' => 'Opbouw' ] );

        $this->assertCount( 1, $data['rows'] );
        $this->assertSame( 1, $data['total'] );
    }

    public function test_new_exercise_defaults_to_team_visibility(): void {
        $this->author();

        [ $status, $data ] = $this->call( 'POST', self::BASE, [ 'name' => 'Mijn eigen vorm' ] );
        $this->assertSame( 200, $status );

        $row = ( new ExercisesRepository() )->findById( (int) $data['id'] );
        $this->assertSame(
            'team',
            $row->visibility,
            'D9 — a coach authors for their own team; the club-wide call is someone else\'s'
        );
    }

    public function test_an_author_cannot_publish_straight_to_the_club(): void {
        $this->author();

        [ , $data ] = $this->call( 'POST', self::BASE, [ 'name' => 'Sluiproute', 'visibility' => 'club' ] );

        $row = ( new ExercisesRepository() )->findById( (int) $data['id'] );
        $this->assertSame(
            'team',
            $row->visibility,
            'asking for club visibility without the curation right must be downgraded, not honoured'
        );
    }

    public function test_a_curator_may_publish_club_wide_directly(): void {
        $this->curator();

        [ , $data ] = $this->call( 'POST', self::BASE, [ 'name' => 'Clubvorm', 'visibility' => 'club' ] );

        $row = ( new ExercisesRepository() )->findById( (int) $data['id'] );
        $this->assertSame( 'club', $row->visibility );
    }

    public function test_promotion_is_refused_to_an_author(): void {
        $this->curator();
        $id = $this->makeExercise( 'Wachtrij-vorm', 'team' );

        $this->author();
        [ $status ] = $this->call( 'POST', self::BASE . '/' . $id . '/promote' );
        $this->assertSame(
            403,
            $status,
            'tt_manage_exercises is held by coaches, so it cannot be what gates promotion'
        );

        [ $status ] = $this->call( 'GET', self::BASE . '/promotion-queue' );
        $this->assertSame( 403, $status, 'the queue is hidden from anyone who cannot act on it' );
    }

    public function test_promotion_is_refused_to_an_anonymous_caller(): void {
        wp_set_current_user( 0 );

        [ $status ] = $this->call( 'POST', self::BASE . '/1/promote' );
        $this->assertContains( $status, [ 401, 403 ] );
    }

    public function test_a_curator_promotes_a_team_exercise(): void {
        $this->curator();
        $id = $this->makeExercise( 'Naar clubbreed', 'team' );

        [ , $queue ] = $this->call( 'GET', self::BASE . '/promotion-queue' );
        $this->assertContains( $id, array_column( $queue['rows'], 'id' ) );

        [ $status, $data ] = $this->call( 'POST', self::BASE . '/' . $id . '/promote' );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['promoted'] );

        $row = ( new ExercisesRepository() )->findById( $id );
        $this->assertSame( 'club', $row->visibility );

        // Promotion changes who sees it, not what it says — so no new
        // version, and the team keeps pointing at the same row.
        $this->assertSame( 1, (int) $row->version );

        [ , $queue ] = $this->call( 'GET', self::BASE . '/promotion-queue' );
        $this->assertNotContains( $id, array_column( $queue['rows'], 'id' ) );
    }

    public function test_promoting_something_already_club_wide_is_a_conflict(): void {
        $this->curator();
        $id = $this->makeExercise( 'Al clubbreed', 'club' );

        [ $status, , $err ] = $this->call( 'POST', self::BASE . '/' . $id . '/promote' );

        $this->assertSame( 409, $status );
        $this->assertSame( 'not_team_scoped', $err );
    }

    public function test_merged_vct_attributes_round_trip(): void {
        $this->curator();

        [ , $data ] = $this->call( 'POST', self::BASE, [
            'name'           => 'Met VCT-velden',
            'intensity_band' => 4,
            'players_min'    => 8,
            'players_max'    => 14,
            'age_min'        => 12,
            'age_max'        => 13,
            'tactical_theme' => 'build_up',
        ] );

        $row = ( new ExercisesRepository() )->findById( (int) $data['id'] );
        $this->assertSame( 4, (int) $row->intensity_band );
        $this->assertSame( 8, (int) $row->players_min );
        $this->assertSame( 'build_up', $row->tactical_theme );
    }

    public function test_out_of_range_attributes_are_clamped_not_stored(): void {
        $this->curator();

        [ , $data ] = $this->call( 'POST', self::BASE, [
            'name'           => 'Buiten bereik',
            'intensity_band' => 47,
            'players_min'    => 0,
        ] );

        $row = ( new ExercisesRepository() )->findById( (int) $data['id'] );
        $this->assertSame( 5, (int) $row->intensity_band, 'intensity is a 1-5 band' );
        $this->assertSame( 1, (int) $row->players_min );
    }
}
