<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #4296 — the staff who write records read the vocabulary those records
 * point at, over REST.
 *
 * `GET lookups[/{type}]` was gated on `tt_view_settings`, the settings
 * roll-up no coach or staff persona holds, so the head coach who records
 * an injury could not read `injury_type`. `GET methodology/principles`
 * used the edit gate. D-15 settles who reads lookups: the head coach,
 * assistant coach, staff and scout, beside the head of development and
 * the academy admin; parents and players read labels inside their own
 * records. Writes keep their gates, which is what the 403s below pin.
 */
final class StaffReadLookupsTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Lookups U15', 'age_group' => 'U15' ] );
        $this->team = (int) $wpdb->insert_id;

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the closed list ────────────────────────────────────────────────

    public function test_the_seed_grants_lookups_read_to_exactly_the_d15_personas(): void {
        $rows     = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $personas = [];
        foreach ( $rows as $row ) {
            if ( ( $row['entity'] ?? '' ) === 'lookups' && ( $row['activity'] ?? '' ) === 'read' ) {
                $personas[] = (string) $row['persona'];
            }
        }
        sort( $personas );

        $this->assertSame(
            [ 'academy_admin', 'assistant_coach', 'head_coach', 'head_of_development', 'scout', 'staff' ],
            $personas,
            'parents, players, the team manager and the observer read labels inside records, not the route'
        );
    }

    // ── reads ──────────────────────────────────────────────────────────

    public function test_both_coach_personas_read_injury_lookups_and_the_principles(): void {
        foreach ( [ true, false ] as $head ) {
            wp_set_current_user( $this->makeCoach( $head ) );
            $who = $head ? 'head coach' : 'assistant coach';

            $this->assertSame( 200, $this->status( 'GET', '/lookups' ), $who . ' GET lookups' );
            foreach ( [ 'injury_type', 'body_part', 'injury_severity' ] as $type ) {
                $this->assertSame( 200, $this->status( 'GET', '/lookups/' . $type ), $who . ' GET lookups/' . $type );
            }
            $this->assertSame( 200, $this->status( 'GET', '/methodology/principles' ), $who . ' GET methodology/principles' );
        }
    }

    public function test_staff_and_scout_read_lookups(): void {
        foreach ( [ 'tt_staff' => 'staff', 'tt_scout' => 'scout' ] as $role => $persona ) {
            $uid = self::factory()->user->create( [ 'role' => $role ] );
            $this->assertContains( $persona, PersonaResolver::personasFor( $uid ), 'the fixture must resolve to ' . $persona );
            wp_set_current_user( $uid );

            $this->assertSame( 200, $this->status( 'GET', '/lookups/body_part' ), $persona . ' GET lookups/body_part' );
        }
    }

    public function test_a_player_and_a_parent_do_not_read_the_route(): void {
        foreach ( [ 'tt_player', 'tt_parent' ] as $role ) {
            wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
            $this->assertSame( 403, $this->status( 'GET', '/lookups/injury_type' ), $role . ' GET lookups/injury_type' );
        }
    }

    // ── writes keep their gates ────────────────────────────────────────

    public function test_a_head_coach_still_cannot_write_a_lookup_or_a_principle(): void {
        wp_set_current_user( $this->makeCoach( true ) );

        $this->assertSame( 403, $this->status( 'POST', '/lookups/injury_type', [ 'name' => 'Coach-made' ] ) );
        $this->assertSame( 403, $this->status( 'POST', '/methodology/principles', [ 'code' => 'X1' ] ) );
    }

    // ── the top-up ─────────────────────────────────────────────────────

    public function test_the_migration_gives_an_existing_install_the_same_rows(): void {
        global $wpdb;
        $table    = "{$wpdb->prefix}tt_authorization_matrix";
        $personas = [ 'assistant_coach', 'head_coach', 'scout', 'staff' ];

        foreach ( $personas as $persona ) {
            $wpdb->delete( $table, [ 'persona' => $persona, 'entity' => 'lookups' ] );
        }

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0300_authorization_seed_staff_read_lookups.php';
        $migration->up();
        // Twice, because a re-run must add nothing.
        $migration->up();

        $rows = $wpdb->get_results(
            "SELECT persona, activity, scope_kind FROM {$table}
              WHERE entity = 'lookups' AND persona IN ( 'assistant_coach', 'head_coach', 'scout', 'staff' )
              ORDER BY persona",
            ARRAY_A
        );

        $expected = [];
        foreach ( $personas as $persona ) {
            $expected[] = [ 'persona' => $persona, 'activity' => 'read', 'scope_kind' => 'global' ];
        }
        $this->assertSame( $expected, $rows );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @param array<string,mixed> $body */
    private function status( string $method, string $route, array $body = [] ): int {
        $request = new WP_REST_Request( $method, '/talenttrack/v1' . $route );
        if ( $body ) {
            $request->set_body_params( $body );
        }
        return rest_do_request( $request )->get_status();
    }

    private function makeCoach( bool $is_head_coach ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => $is_head_coach ? 'Hoofd' : 'Assistent',
            'last_name'  => 'Trainer',
            'role_type'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $this->team,
            'person_id'     => $person_id,
            'role_in_team'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $is_head_coach ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->team,
        ] );

        $this->assertContains( $is_head_coach ? 'head_coach' : 'assistant_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        return $uid;
    }
}
