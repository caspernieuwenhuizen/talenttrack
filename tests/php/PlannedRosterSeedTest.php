<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\REST\ActivitiesRestController;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Services\PlannedRosterSeeder;

/**
 * #3800 — a team activity starts with its team expected.
 *
 * Seeding the planned roster used to be the activity wizard's job alone.
 * `POST /activities` wrote no expected rows, so an activity created over
 * REST began with a planned roster of 0 and nobody to tick off at the
 * side of the pitch. Coaches were opening the edit screen and saving it
 * to force a roster — which looked like a repair and was actually the
 * first seed.
 *
 * The idempotency cases carry the weight here. A seeder that re-ran would
 * resurrect players a coach had deliberately removed, which is a worse
 * bug than the empty roster it replaces.
 */
final class PlannedRosterSeedTest extends WP_UnitTestCase {

    private int $teamId = 0;
    /** @var list<int> */
    private array $playerIds = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Seed Team' ] );
        $this->teamId = (int) $wpdb->insert_id;

        foreach ( [ 'Alpha', 'Bravo', 'Charlie' ] as $last ) {
            $wpdb->insert( $wpdb->prefix . 'tt_players', [
                'club_id'    => 1,
                'team_id'    => $this->teamId,
                'first_name' => 'Seed',
                'last_name'  => $last,
                'status'     => 'active',
            ] );
            $this->playerIds[] = (int) $wpdb->insert_id;
        }

        // An inactive player on the same team — must never be seeded.
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $this->teamId,
            'first_name' => 'Seed',
            'last_name'  => 'Released',
            'status'     => 'released',
        ] );
    }

    private function makeActivity( ?int $team_id, string $type = 'training' ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => $team_id ?? 0,
            'title'             => 'Seeded',
            'session_date'      => '2026-05-01',
            'activity_type_key' => $type,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return list<int> player ids on the activity's expected rows */
    private function expectedPlayerIds( int $activity_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT player_id FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND record_type = 'expected' AND is_guest = 0
              ORDER BY player_id ASC",
            $activity_id
        ) );
        return array_values( array_map( 'intval', (array) $rows ) );
    }

    // -----------------------------------------------------------------
    // the seeder itself
    // -----------------------------------------------------------------

    public function test_seeds_the_active_squad(): void {
        $activity = $this->makeActivity( $this->teamId );

        $this->assertSame( 3, PlannedRosterSeeder::seed( $activity, $this->teamId ) );

        $expected = $this->playerIds;
        sort( $expected );
        $this->assertSame( $expected, $this->expectedPlayerIds( $activity ), 'the three active players are expected' );
    }

    public function test_an_inactive_player_is_not_seeded(): void {
        $activity = $this->makeActivity( $this->teamId );
        PlannedRosterSeeder::seed( $activity, $this->teamId );

        $this->assertCount( 3, $this->expectedPlayerIds( $activity ), 'the released player must not appear' );
    }

    public function test_an_activity_with_no_team_is_left_alone(): void {
        $activity = $this->makeActivity( null );

        $this->assertSame( 0, PlannedRosterSeeder::seed( $activity, 0 ) );
        $this->assertSame( [], $this->expectedPlayerIds( $activity ) );
    }

    public function test_seeding_twice_changes_nothing(): void {
        $activity = $this->makeActivity( $this->teamId );

        PlannedRosterSeeder::seed( $activity, $this->teamId );
        $first = $this->expectedPlayerIds( $activity );

        $this->assertSame( 0, PlannedRosterSeeder::seed( $activity, $this->teamId ), 'the second run is a no-op' );
        $this->assertSame( $first, $this->expectedPlayerIds( $activity ) );
    }

    /**
     * The one that matters most: a coach who trims the squad must not get
     * the removed player back the next time anything touches the activity.
     */
    public function test_a_trimmed_roster_is_not_refilled(): void {
        global $wpdb;
        $activity = $this->makeActivity( $this->teamId );
        PlannedRosterSeeder::seed( $activity, $this->teamId );

        $dropped = $this->playerIds[0];
        $wpdb->delete( $wpdb->prefix . 'tt_attendance', [
            'activity_id' => $activity,
            'player_id'   => $dropped,
            'record_type' => 'expected',
        ] );

        $this->assertSame( 0, PlannedRosterSeeder::seed( $activity, $this->teamId ) );
        $this->assertNotContains(
            $dropped,
            $this->expectedPlayerIds( $activity ),
            'a deliberately removed player must stay removed'
        );
    }

    public function test_a_tournament_day_is_seeded_like_anything_else(): void {
        $activity = $this->makeActivity( $this->teamId, 'tournament' );

        $this->assertSame( 3, PlannedRosterSeeder::seed( $activity, $this->teamId ) );
        $this->assertCount( 3, $this->expectedPlayerIds( $activity ), 'a tournament day has a register too' );
    }

    // -----------------------------------------------------------------
    // through the create route — the reported case
    // -----------------------------------------------------------------

    public function test_rest_create_without_a_planned_payload_seeds_the_team(): void {
        $r = new \WP_REST_Request();
        foreach ( [
            'team_id'           => $this->teamId,
            'title'             => 'Created over REST',
            'session_date'      => '2026-05-02',
            'activity_type_key' => 'training',
        ] as $k => $v ) {
            $r->set_param( $k, $v );
        }

        $res  = ActivitiesRestController::create_session( $r );
        $body = (array) $res->get_data();
        $id   = (int) ( $body['data']['id'] ?? $body['data']['activity_id'] ?? 0 );

        $this->assertGreaterThan( 0, $id, 'the activity was created' );
        $this->assertCount(
            3,
            $this->expectedPlayerIds( $id ),
            'the reported case: an activity created over REST now has a planned roster'
        );
    }

    public function test_rest_create_with_a_planned_payload_is_honoured_as_given(): void {
        $only = $this->playerIds[1];

        $r = new \WP_REST_Request();
        foreach ( [
            'team_id'           => $this->teamId,
            'title'             => 'Explicit squad',
            'session_date'      => '2026-05-03',
            'activity_type_key' => 'training',
            'planned'           => [ (string) $only => [ 'status' => '' ] ],
        ] as $k => $v ) {
            $r->set_param( $k, $v );
        }

        $res  = ActivitiesRestController::create_session( $r );
        $body = (array) $res->get_data();
        $id   = (int) ( $body['data']['id'] ?? $body['data']['activity_id'] ?? 0 );

        $this->assertGreaterThan( 0, $id );
        $this->assertSame(
            [ $only ],
            $this->expectedPlayerIds( $id ),
            'a caller who said who is expected must not have the whole team added'
        );
    }
}
