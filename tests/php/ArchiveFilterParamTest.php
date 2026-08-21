<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Exercises\ExercisesRepository;
use TT\Modules\Holidays\Repositories\HolidaysRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * #2625 — `filter[archived]` is the archive-state param on every list
 * endpoint; `filter[status]` survives one release as a deprecated alias
 * on the four endpoints that used to spell it that way.
 *
 * The regression these tests exist to prevent is the tempting one: making
 * the alias shared. `filter[status]` is a genuine domain filter on players
 * (`tt_players.status` — trial / released / inactive) and on goals, so a
 * global "status also means archive" rule would silently swallow those.
 * The last two tests pin that down.
 */
final class ArchiveFilterParamTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return array{0:int,1:mixed} */
    private function call( string $route, array $params = [] ): array {
        $request = new WP_REST_Request( 'GET', $route );
        foreach ( $params as $k => $v ) $request->set_param( $k, $v );
        $response = rest_get_server()->dispatch( $request );
        $envelope = $response->get_data();

        return [
            $response->get_status(),
            is_array( $envelope ) ? ( $envelope['data'] ?? null ) : $envelope,
        ];
    }

    /** Stamp archived_at directly — the point here is the read path. */
    private function archiveRow( string $table, int $id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . $table,
            [ 'archived_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,int>
     */
    private function ids( array $rows ): array {
        return array_map( static fn( $r ) => (int) ( is_array( $r ) ? $r['id'] : $r->id ), $rows );
    }

    // --- Exercises ------------------------------------------------------

    public function test_exercises_honour_both_the_canonical_and_legacy_key(): void {
        $repo = new ExercisesRepository();
        $live = $repo->create( [ 'name' => 'Rondo 5v2', 'visibility' => 'club', 'duration_minutes' => 20 ] );
        $gone = $repo->create( [ 'name' => 'Afgedankte vorm', 'visibility' => 'club', 'duration_minutes' => 20 ] );
        $this->archiveRow( 'tt_vct_exercises', $gone );

        $route = '/talenttrack/v1/exercises';

        [ , $default ] = $this->call( $route, [ 'browse' => 1, 'per_page' => 50 ] );
        $this->assertContains( $live, $this->ids( $default['rows'] ), 'the default view is active-only' );
        $this->assertNotContains( $gone, $this->ids( $default['rows'] ) );

        [ , $canonical ] = $this->call( $route, [ 'browse' => 1, 'per_page' => 50, 'filter' => [ 'archived' => 'archived' ] ] );
        $this->assertContains( $gone, $this->ids( $canonical['rows'] ) );

        [ , $legacy ] = $this->call( $route, [ 'browse' => 1, 'per_page' => 50, 'filter' => [ 'status' => 'archived' ] ] );
        $this->assertSame(
            $this->ids( $canonical['rows'] ),
            $this->ids( $legacy['rows'] ),
            'the deprecated filter[status] alias resolves to the same rows'
        );
    }

    public function test_the_canonical_key_wins_when_both_are_present(): void {
        $repo = new ExercisesRepository();
        $live = $repo->create( [ 'name' => 'Levende vorm', 'visibility' => 'club', 'duration_minutes' => 20 ] );
        $gone = $repo->create( [ 'name' => 'Gearchiveerde vorm', 'visibility' => 'club', 'duration_minutes' => 20 ] );
        $this->archiveRow( 'tt_vct_exercises', $gone );

        [ , $data ] = $this->call( '/talenttrack/v1/exercises', [
            'browse'   => 1,
            'per_page' => 50,
            'filter'   => [ 'archived' => 'active', 'status' => 'archived' ],
        ] );

        $ids = $this->ids( $data['rows'] );
        $this->assertContains( $live, $ids, 'filter[archived] decides' );
        $this->assertNotContains( $gone, $ids, 'the deprecated alias must not override the canonical key' );
    }

    // --- Holidays -------------------------------------------------------

    public function test_holidays_honour_both_keys(): void {
        $repo = new HolidaysRepository();
        $live = $repo->create( [ 'name' => 'Kerstvakantie', 'start_date' => '2026-12-21', 'end_date' => '2027-01-04' ] );
        $gone = $repo->create( [ 'name' => 'Oude vakantie',  'start_date' => '2025-12-21', 'end_date' => '2026-01-04' ] );
        $this->archiveRow( 'tt_holidays', $gone );

        $route = '/talenttrack/v1/holidays';

        [ , $default ] = $this->call( $route );
        $this->assertNotContains( $gone, $this->ids( $default['rows'] ) );

        [ , $canonical ] = $this->call( $route, [ 'filter' => [ 'archived' => 'archived' ] ] );
        [ , $legacy ]    = $this->call( $route, [ 'filter' => [ 'status'   => 'archived' ] ] );

        $this->assertContains( $gone, $this->ids( $canonical['rows'] ) );
        $this->assertSame( $this->ids( $canonical['rows'] ), $this->ids( $legacy['rows'] ) );
        $this->assertNotContains( $live, $this->ids( $canonical['rows'] ) );
    }

    // --- Tournaments ----------------------------------------------------

    private function makeTournament( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'uuid'       => wp_generate_uuid4(),
            'club_id'    => CurrentClub::id(),
            'name'       => $name,
            'start_date' => '2026-05-01',
            'end_date'   => '2026-05-02',
            'team_id'    => 1,
            'created_by' => get_current_user_id(),
        ] );
        return (int) $wpdb->insert_id;
    }

    public function test_tournaments_honour_both_keys(): void {
        $live = $this->makeTournament( 'Paastoernooi' );
        $gone = $this->makeTournament( 'Toernooi van vorig jaar' );
        $this->archiveRow( 'tt_tournaments', $gone );

        $route = '/talenttrack/v1/tournaments';

        [ , $default ] = $this->call( $route, [ 'per_page' => 50 ] );
        $this->assertContains( $live, $this->ids( $default['rows'] ) );
        $this->assertNotContains( $gone, $this->ids( $default['rows'] ) );

        [ , $canonical ] = $this->call( $route, [ 'per_page' => 50, 'filter' => [ 'archived' => 'archived' ] ] );
        [ , $legacy ]    = $this->call( $route, [ 'per_page' => 50, 'filter' => [ 'status'   => 'archived' ] ] );

        $this->assertContains( $gone, $this->ids( $canonical['rows'] ) );
        $this->assertSame( $this->ids( $canonical['rows'] ), $this->ids( $legacy['rows'] ) );
    }

    // --- Training plans -------------------------------------------------

    public function test_training_plans_honour_both_keys(): void {
        $repo = new TrainingPlansRepository();
        $live = $repo->create( [ 'title' => 'Weekplan 12' ] );
        $gone = $repo->create( [ 'title' => 'Weekplan 1' ] );
        $this->archiveRow( 'tt_training_plans', $gone );

        $route = '/talenttrack/v1/training-plans';

        [ , $default ] = $this->call( $route, [ 'per_page' => 50 ] );
        $this->assertContains( $live, $this->ids( $default['rows'] ) );
        $this->assertNotContains( $gone, $this->ids( $default['rows'] ) );

        [ , $canonical ] = $this->call( $route, [ 'per_page' => 50, 'filter' => [ 'archived' => 'archived' ] ] );
        [ , $legacy ]    = $this->call( $route, [ 'per_page' => 50, 'filter' => [ 'status'   => 'archived' ] ] );

        $this->assertContains( $gone, $this->ids( $canonical['rows'] ) );
        $this->assertSame( $this->ids( $canonical['rows'] ), $this->ids( $legacy['rows'] ) );
    }

    public function test_training_plans_still_accept_all(): void {
        $repo = new TrainingPlansRepository();
        $live = $repo->create( [ 'title' => 'Actief plan' ] );
        $gone = $repo->create( [ 'title' => 'Gearchiveerd plan' ] );
        $this->archiveRow( 'tt_training_plans', $gone );

        [ , $data ] = $this->call( '/talenttrack/v1/training-plans', [
            'per_page' => 50,
            'filter'   => [ 'archived' => 'all' ],
        ] );

        $ids = $this->ids( $data['rows'] );
        $this->assertContains( $live, $ids );
        $this->assertContains( $gone, $ids, 'the value vocabulary is unchanged by the key rename' );
    }

    // --- The alias must stay local -------------------------------------

    private function makePlayer( string $last, string $status ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'first_name' => 'Speler',
            'last_name'  => $last,
            'status'     => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * The whole reason the alias is implemented inline in four controllers
     * rather than in a shared helper: here `filter[status]` selects on
     * `tt_players.status`, and must keep doing so. A player on trial is not
     * an archived player.
     */
    public function test_players_filter_status_is_still_a_domain_filter(): void {
        $active = $this->makePlayer( 'Actief', 'active' );
        $trial  = $this->makePlayer( 'Proef',  'trial' );

        [ , $data ] = $this->call( '/talenttrack/v1/players', [
            'per_page' => 50,
            'filter'   => [ 'status' => 'trial' ],
        ] );

        $ids = $this->ids( $data['rows'] );
        $this->assertContains( $trial, $ids, 'filter[status] still selects on tt_players.status' );
        $this->assertNotContains( $active, $ids );
    }

    /**
     * And the two are independent: a trial player who is archived is
     * excluded by the archive filter, not by the domain one.
     */
    public function test_players_archive_and_domain_filters_are_independent(): void {
        $trial_live = $this->makePlayer( 'Proef leeft', 'trial' );
        $trial_gone = $this->makePlayer( 'Proef weg',   'trial' );
        $this->archiveRow( 'tt_players', $trial_gone );

        [ , $data ] = $this->call( '/talenttrack/v1/players', [
            'per_page' => 50,
            'filter'   => [ 'status' => 'trial' ],
        ] );

        $ids = $this->ids( $data['rows'] );
        $this->assertContains( $trial_live, $ids );
        $this->assertNotContains( $trial_gone, $ids, 'the default archive state still applies alongside a domain filter' );
    }
}
