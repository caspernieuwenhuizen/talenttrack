<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\ProspectVisitObservationsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Prospects\Rest\ScoutingVisitsRestController;

/**
 * #3711 — a scout who saw a known prospect again had nowhere to put it.
 *
 * `tt_prospects.scouting_visit_id` holds one visit, so recording a second
 * sighting meant overwriting the first and losing the answer to "where
 * was this player found". `tt_prospect_visit_observations` is the
 * many-to-many; the column stays as the discovery pointer.
 *
 * The assertions that carry the most weight are the scope ones. These are
 * minors, and a link endpoint that takes a prospect id in the body is a
 * way to confirm a child exists unless it refuses one the caller could
 * not already see.
 */
final class ProspectVisitObservationsTest extends WP_UnitTestCase {

    private int $scoutUserId = 0;
    private int $visitA      = 0;
    private int $visitB      = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $this->scoutUserId = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $this->scoutUserId );

        $visits = new ScoutingVisitsRepository();
        $this->visitA = $visits->create( [
            'scout_user_id' => $this->scoutUserId,
            'visit_date'    => '2026-03-14',
            'location'      => 'Sportpark Nieuwland',
            'status'        => ScoutingVisitsRepository::STATUS_COMPLETED,
        ] );
        $this->visitB = $visits->create( [
            'scout_user_id' => $this->scoutUserId,
            'visit_date'    => '2026-04-18',
            'location'      => 'Sportpark De Meern',
            'status'        => ScoutingVisitsRepository::STATUS_COMPLETED,
        ] );

        ScoutingVisitsRestController::init();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function makeProspect( ?int $visit_id = null ): int {
        return ( new ProspectsRepository() )->create( [
            'first_name'            => 'Teun',
            'last_name'             => 'Van Dijk',
            'date_of_birth'         => '2013-06-01',
            'discovered_at'         => '2026-03-14',
            'discovered_by_user_id' => $this->scoutUserId,
            'scouting_visit_id'     => $visit_id,
        ] );
    }

    private function post( int $visit_id, int $prospect_id ): \WP_REST_Response {
        $r = new WP_REST_Request( 'POST', '/talenttrack/v1/scouting-visits/' . $visit_id . '/observations' );
        $r->set_param( 'id', $visit_id );
        $r->set_param( 'prospect_id', $prospect_id );
        return ScoutingVisitsRestController::create_observation( $r );
    }

    // ── the discovery link is also the first observation ───────────────

    public function test_creating_a_prospect_with_a_visit_records_the_first_observation(): void {
        $prospect = $this->makeProspect( $this->visitA );

        $observations = new ProspectVisitObservationsRepository();
        $this->assertSame( 1, $observations->countForProspect( $prospect ) );
        $this->assertNotNull( $observations->find( $prospect, $this->visitA ) );
    }

    public function test_the_visit_lists_a_prospect_created_against_it(): void {
        $prospect = $this->makeProspect( $this->visitA );

        $rows = ( new ScoutingVisitsRepository() )->prospectsForVisit( $this->visitA );
        $this->assertCount( 1, $rows );
        $this->assertSame( $prospect, (int) $rows[0]->id );
    }

    // ── linking an existing prospect ───────────────────────────────────

    public function test_an_existing_prospect_can_be_linked_to_a_second_visit(): void {
        $prospect = $this->makeProspect( $this->visitA );

        $response = $this->post( $this->visitB, $prospect );
        $this->assertSame( 200, $response->get_status() );

        $observations = new ProspectVisitObservationsRepository();
        $this->assertSame( 2, $observations->countForProspect( $prospect ) );
        $this->assertCount( 1, ( new ScoutingVisitsRepository() )->prospectsForVisit( $this->visitB ) );
    }

    public function test_the_discovery_visit_still_lists_them_after_a_re_sighting(): void {
        $prospect = $this->makeProspect( $this->visitA );
        $this->post( $this->visitB, $prospect );

        $rows = ( new ScoutingVisitsRepository() )->prospectsForVisit( $this->visitA );
        $this->assertCount(
            1,
            $rows,
            'the second sighting must not take the prospect off the visit that found them'
        );
    }

    public function test_linking_the_same_prospect_twice_creates_one_row(): void {
        $prospect = $this->makeProspect( $this->visitA );

        $first  = $this->post( $this->visitB, $prospect )->get_data();
        $second = $this->post( $this->visitB, $prospect )->get_data();

        $this->assertSame(
            (int) $first['data']['observation_id'],
            (int) $second['data']['observation_id']
        );
        $this->assertSame( 2, ( new ProspectVisitObservationsRepository() )->countForProspect( $prospect ) );
    }

    public function test_a_prospect_watched_at_three_visits_has_three_observations(): void {
        $visits   = new ScoutingVisitsRepository();
        $visitC   = $visits->create( [
            'scout_user_id' => $this->scoutUserId,
            'visit_date'    => '2026-05-02',
            'location'      => 'Sportpark Rijnstreek',
            'status'        => ScoutingVisitsRepository::STATUS_COMPLETED,
        ] );
        $prospect = $this->makeProspect( $this->visitA );

        $this->post( $this->visitB, $prospect );
        $this->post( $visitC, $prospect );

        $rows = ( new ProspectVisitObservationsRepository() )->forProspect( $prospect );
        $this->assertCount( 3, $rows );
        $this->assertSame(
            $this->visitA,
            (int) $rows[0]->scouting_visit_id,
            'earliest first, so the discovery visit stays identifiable'
        );
    }

    // ── undoing a link ─────────────────────────────────────────────────

    public function test_a_link_can_be_removed_again(): void {
        $prospect = $this->makeProspect( $this->visitA );
        $data     = $this->post( $this->visitB, $prospect )->get_data();

        $r = new WP_REST_Request( 'DELETE', '/talenttrack/v1/scouting-visits/' . $this->visitB . '/observations' );
        $r->set_param( 'id', $this->visitB );
        $r->set_param( 'observation_id', (int) $data['data']['observation_id'] );
        $response = ScoutingVisitsRestController::delete_observation( $r );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, ( new ProspectVisitObservationsRepository() )->countForProspect( $prospect ) );
    }

    public function test_an_observation_belonging_to_another_visit_is_not_deletable_through_this_one(): void {
        $prospect = $this->makeProspect( $this->visitA );
        $mine     = ( new ProspectVisitObservationsRepository() )->find( $prospect, $this->visitA );

        $r = new WP_REST_Request( 'DELETE', '/talenttrack/v1/scouting-visits/' . $this->visitB . '/observations' );
        $r->set_param( 'id', $this->visitB );
        $r->set_param( 'observation_id', (int) $mine->id );

        $this->assertSame( 404, ScoutingVisitsRestController::delete_observation( $r )->get_status() );
        $this->assertSame( 1, ( new ProspectVisitObservationsRepository() )->countForProspect( $prospect ) );
    }

    // ── scope: these are minors ────────────────────────────────────────

    public function test_a_prospect_outside_the_callers_scope_cannot_be_linked(): void {
        $prospect = $this->makeProspect( $this->visitA );

        // A coach with no team, so `ProspectScope` narrows them to their own
        // discoveries — of which this is not one.
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        wp_set_current_user( $coach );
        $this->assertFalse(
            ProspectScope::canSee( $coach, $prospect ),
            'the fixture must be out of scope or the assertion below proves nothing'
        );

        // Back to the scout's own visit, but asking as the coach.
        $response = $this->post( $this->visitA, $prospect );
        $this->assertNotSame( 200, $response->get_status() );
    }

    public function test_the_scout_who_owns_the_visit_is_inside_scope(): void {
        $prospect = $this->makeProspect();

        $this->assertTrue( ProspectScope::canSee( $this->scoutUserId, $prospect ) );
        $this->assertSame( 200, $this->post( $this->visitA, $prospect )->get_status() );
    }

    // ── the backfill ───────────────────────────────────────────────────

    public function test_the_migration_backfills_an_observation_for_every_discovery_link(): void {
        global $wpdb;

        $prospect = $this->makeProspect( $this->visitA );
        // Drop the observation the repository wrote, leaving only the
        // column — an install exactly as it looked before this migration.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_prospect_visit_observations" );
        $this->assertSame( 0, ( new ProspectVisitObservationsRepository() )->countForProspect( $prospect ) );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0279_prospect_visit_observations.php';
        $migration->up();
        // Twice, because a re-run must add nothing.
        $migration->up();

        $this->assertSame( 1, ( new ProspectVisitObservationsRepository() )->countForProspect( $prospect ) );
        $this->assertCount( 1, ( new ScoutingVisitsRepository() )->prospectsForVisit( $this->visitA ) );
    }

    // ── the new table carries the SaaS scaffold ────────────────────────

    public function test_the_table_has_club_id_and_a_unique_uuid(): void {
        global $wpdb;
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}tt_prospect_visit_observations" );

        $this->assertContains( 'club_id', $columns );
        $this->assertContains( 'uuid', $columns );

        $prospect = $this->makeProspect( $this->visitA );
        $row = ( new ProspectVisitObservationsRepository() )->find( $prospect, $this->visitA );
        $this->assertSame( 36, strlen( (string) $row->uuid ) );
        $this->assertGreaterThan( 0, (int) $row->club_id );
    }
}
