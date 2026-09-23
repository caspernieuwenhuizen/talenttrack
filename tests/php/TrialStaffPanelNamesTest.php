<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Rest\TrialsRestController;
use TT\Modules\Trials\TrialsModule;

/**
 * #4028 — the trial panel comes back with names on it.
 *
 * `GET trial-cases/{id}/staff` returned the repository's `SELECT *`, so a
 * caller got `user_id` and nothing to read: a panel of numeric ids, which no
 * screen can render and no head of development can check before a decision
 * about a child. The batched name lookup was already in the same controller,
 * feeding the inputs payload.
 */
final class TrialStaffPanelNamesTest extends WP_UnitTestCase {

    private int $case_id = 0;
    private int $manager = 0;
    private int $scout   = 0;
    private int $person  = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        TrialsModule::ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'first_name' => 'Proef', 'last_name' => 'Speler', 'status' => 'trial',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'std-' . uniqid(), 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id'    => $club,
            'player_id'  => $player,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        $this->manager = self::factory()->user->create( [
            'role'         => 'administrator',
            'display_name' => 'Marco de Vries',
        ] );
        $this->scout = self::factory()->user->create( [
            'role'         => 'administrator',
            'display_name' => 'Sanne Bakker',
        ] );

        // One of the two is also entered in the club's People, the other is
        // not — a panel routinely mixes the two.
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $club,
            'first_name' => 'Marco',
            'last_name'  => 'de Vries',
            'role_type'  => 'head_of_development',
            'wp_user_id' => $this->manager,
            'status'     => 'active',
        ] );
        $this->person = (int) $wpdb->insert_id;

        $staff = new TrialCaseStaffRepository();
        $staff->assign( $this->case_id, $this->manager, 'Head of development' );
        $staff->assign( $this->case_id, $this->scout, 'Scout' );

        wp_set_current_user( $this->manager );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_panel_row_carries_a_display_name(): void {
        $rows = $this->panel();
        $this->assertCount( 2, $rows );

        foreach ( $rows as $row ) {
            $this->assertArrayHasKey( 'display_name', $row );
            $this->assertNotSame( '', (string) $row['display_name'], 'a panel row came back without a name' );
        }

        $names = array_map( static fn( array $r ): string => (string) $r['display_name'], $rows );
        $this->assertContains( 'Marco de Vries', $names );
        $this->assertContains( 'Sanne Bakker', $names );
    }

    /**
     * `person_id` resolves the account back to the club's own record where
     * there is one, and is null — not missing — where there is not.
     */
    public function test_a_panel_row_carries_its_person_id_when_one_resolves(): void {
        $by_user = [];
        foreach ( $this->panel() as $row ) {
            $by_user[ (int) $row['user_id'] ] = $row;
        }

        $this->assertSame( $this->person, $by_user[ $this->manager ]['person_id'] );
        $this->assertArrayHasKey( 'person_id', $by_user[ $this->scout ] );
        $this->assertNull( $by_user[ $this->scout ]['person_id'] );
    }

    public function test_the_row_keeps_what_it_always_carried(): void {
        $rows = $this->panel();

        foreach ( [ 'id', 'case_id', 'user_id', 'role_label', 'assigned_at' ] as $field ) {
            $this->assertArrayHasKey( $field, $rows[0], "{$field} disappeared from the panel row" );
        }
        $this->assertSame( $this->case_id, (int) $rows[0]['case_id'] );
    }

    /**
     * The arg a caller has to fill in says where its id comes from, the way
     * `ParentAccountRestController`'s `wp_user_id` does.
     */
    public function test_the_assign_arg_names_where_the_id_comes_from(): void {
        $route = '/talenttrack/v1/trial-cases/(?P<id>\d+)/staff';
        $args  = [];
        foreach ( rest_get_server()->get_routes()[ $route ] ?? [] as $handler ) {
            $args += (array) ( $handler['args'] ?? [] );
        }

        $this->assertArrayHasKey( 'user_id', $args );
        $description = (string) ( $args['user_id']['description'] ?? '' );
        $this->assertStringContainsString( 'trial-cases/{id}/staff', $description );
    }

    /** @return array<int,array<string,mixed>> */
    private function panel(): array {
        $r = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases/' . $this->case_id . '/staff' );
        $r->set_param( 'id', $this->case_id );

        $res = TrialsRestController::list_staff( $r );
        $this->assertSame( 200, $res->get_status() );

        $data = (array) $res->get_data();
        $body = (array) ( $data['data'] ?? [] );
        $out  = [];
        foreach ( (array) ( $body['staff'] ?? [] ) as $row ) {
            $out[] = (array) $row;
        }
        return $out;
    }
}
