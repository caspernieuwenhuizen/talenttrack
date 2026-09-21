<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #3978 — the status verdict and the potential band are staff-only.
 *
 * `GET /players/{id}/status` and `/potential` gate on `player_status`. The
 * default seed no longer grants it to the `parent` or `player` persona, and
 * migration 0290 removes the default rows from existing installs while
 * leaving an academy's own grant alone.
 *
 * Every refusal sits beside a grant on the same player.
 */
final class PlayerStatusStaffOnlyTest extends WP_UnitTestCase {

    private int $teamA   = 0;
    private int $playerA = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Status A', 'age_group' => 'U14' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Status',
            'last_name'     => 'Alpha',
            'team_id'       => $this->teamA,
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        $this->playerA = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->playerA, 'the fixture must write the player' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_parent_is_refused_the_status_and_potential_a_head_coach_reads(): void {
        $coach = $this->makeHeadCoach( $this->teamA );
        wp_set_current_user( $coach );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/status" ), 'the head coach reads the verdict' );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/potential" ), 'the head coach reads the potential' );

        $parent = $this->makeUser( 'tt_parent', 'parent' );
        $this->linkGuardian( $parent, $this->playerA );
        wp_set_current_user( $parent );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/evaluations" ), 'the parent still reads their child\'s evaluations' );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerA}/status" ) );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerA}/potential" ) );
    }

    public function test_a_player_is_refused_their_own_status_and_potential(): void {
        $uid = $this->makeUser( 'tt_player', 'player' );
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $uid ], [ 'id' => $this->playerA ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $uid );

        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/evaluations" ), 'the player still reads their own evaluations' );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerA}/status" ) );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerA}/potential" ) );
    }

    public function test_the_seed_grants_player_status_to_no_family_persona(): void {
        $seed = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $this->assertIsArray( $seed );
        $found = [];
        foreach ( self::rows( $seed ) as $row ) {
            if ( ( $row['entity'] ?? '' ) === 'player_status' && in_array( $row['persona'] ?? '', [ 'parent', 'player' ], true ) ) {
                $found[] = $row['persona'];
            }
        }
        $this->assertSame( [], $found );
    }

    public function test_the_migration_removes_default_family_rows_and_keeps_an_academy_grant(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';
        $wpdb->query( "DELETE FROM {$table} WHERE entity = 'player_status' AND persona IN ('parent', 'player')" );

        $ok = $wpdb->insert( $table, [ 'persona' => 'parent', 'entity' => 'player_status', 'activity' => 'read', 'scope_kind' => 'player', 'module_class' => 'TT\\Modules\\Players\\PlayersModule', 'is_default' => 1 ] );
        $this->assertNotFalse( $ok, 'the default row must be written: ' . $wpdb->last_error );
        $ok = $wpdb->insert( $table, [ 'persona' => 'player', 'entity' => 'player_status', 'activity' => 'read', 'scope_kind' => 'self', 'module_class' => 'TT\\Modules\\Players\\PlayersModule', 'is_default' => 0 ] );
        $this->assertNotFalse( $ok, 'the academy row must be written: ' . $wpdb->last_error );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0290_player_status_staff_only.php';
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE entity = 'player_status' AND persona = 'parent'" ) );
        $this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE entity = 'player_status' AND persona = 'player' AND is_default = 0" ), 'an academy\'s own grant is left alone' );
        $this->assertGreaterThan( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE entity = 'player_status' AND persona NOT IN ('parent', 'player')" ), 'staff rows are untouched' );
    }

    /**
     * The seed file's rows, whatever the nesting it returns them in.
     *
     * @param array<mixed> $seed
     * @return list<array<string,mixed>>
     */
    private static function rows( array $seed ): array {
        $out = [];
        foreach ( $seed as $v ) {
            if ( is_array( $v ) && isset( $v['persona'], $v['entity'] ) ) {
                $out[] = $v;
            } elseif ( is_array( $v ) ) {
                $out = array_merge( $out, self::rows( $v ) );
            }
        }
        return $out;
    }

    private function status( string $route ): int {
        return (int) rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
    }

    private function makeUser( string $wp_role, string $expected_persona ): int {
        $uid = self::factory()->user->create( [ 'role' => $wp_role ] );
        $this->assertContains( $expected_persona, PersonaResolver::personasFor( $uid ), "{$wp_role} must resolve to {$expected_persona}" );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function linkGuardian( int $user_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $player_id,
            'parent_user_id' => $user_id,
        ] );
        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canViewPlayer( $user_id, $player_id ), 'the guardian link must resolve, or the refusal is vacuous' );
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id, 'the fixture must write the person' );
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        return $uid;
    }
}
