<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Journey\JourneyModule;
use TT\Modules\Journey\Wizards\InjuryDetailsStep;
use TT\Modules\Journey\Wizards\InjuryPlayerStep;
use TT\Modules\Journey\Wizards\InjuryWhenStep;
use TT\Modules\Journey\Wizards\NewInjuryWizard;

/**
 * #4270 — "Record injury" on a player's Injuries tab opens the wizard with
 * `player_id` in the URL. The wizard now seeds it, for a player in the
 * user's team scope only, and the player step is skipped.
 */
final class InjuryWizardPlayerSeedTest extends WP_UnitTestCase {

    private int $club       = 0;
    private int $team       = 0;
    private int $other_team = 0;
    private int $own        = 0;
    private int $elsewhere  = 0;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        JourneyModule::ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->team       = $this->insertTeam( 'JO14-1' );
        $this->other_team = $this->insertTeam( 'JO14-2' );
        $this->own        = $this->insertPlayer( $this->team, 'Sem' );
        $this->elsewhere  = $this->insertPlayer( $this->other_team, 'Daan' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_a_coach_gets_the_seed_for_a_player_on_their_team(): void {
        wp_set_current_user( $this->makeHeadCoach( $this->team ) );

        $this->assertSame(
            [ 'player_id' => $this->own ],
            ( new NewInjuryWizard() )->initialState( [ 'player_id' => (string) $this->own ] )
        );
    }

    public function test_a_player_outside_the_coachs_teams_is_ignored(): void {
        wp_set_current_user( $this->makeHeadCoach( $this->team ) );

        $wizard = new NewInjuryWizard();
        $this->assertSame( [], $wizard->initialState( [ 'player_id' => (string) $this->elsewhere ] ) );
        $this->assertSame( [], $wizard->initialState( [ 'player_id' => '999999' ] ) );
        $this->assertSame( [], $wizard->initialState( [] ), 'no player_id, no seed: step 1 as before' );
    }

    public function test_the_player_step_is_skipped_only_with_a_player(): void {
        $step = new InjuryPlayerStep();
        $this->assertTrue( $step->notApplicableFor( [ 'player_id' => $this->own ] ) );
        $this->assertFalse( $step->notApplicableFor( [] ) );
        $this->assertFalse( $step->notApplicableFor( [ 'player_id' => 0 ] ) );
    }

    public function test_the_details_step_names_the_player(): void {
        wp_set_current_user( $this->makeHeadCoach( $this->team ) );

        ob_start();
        ( new InjuryDetailsStep() )->render( [ 'player_id' => $this->own ] );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-injury-wizard-player', $html );
        $this->assertStringContainsString( 'Sem Seed', $html );
    }

    public function test_the_injury_saved_from_the_seed_is_on_the_players_record(): void {
        $coach = $this->makeHeadCoach( $this->team );
        wp_set_current_user( $coach );

        $state = ( new NewInjuryWizard() )->initialState( [ 'player_id' => (string) $this->own ] );
        $state += [ 'started_on' => '2026-10-01', 'notes' => 'Seeded from the file' ];
        $result = ( new InjuryWhenStep() )->submit( $state );
        $this->assertIsArray( $result, 'the save succeeds for the seeded player' );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->own . '/injuries' ) );

        $this->assertSame( 200, $res->get_status() );
        $data     = (array) $res->get_data();
        $injuries = (array) ( $data['data']['injuries'] ?? [] );
        $this->assertCount( 1, $injuries );
        $this->assertSame( '2026-10-01', (string) ( (array) $injuries[0] )['started_on'] );
    }

    // ---- fixtures ------------------------------------------------------

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first_name ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first_name,
            'last_name'  => 'Seed',
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    /** A head coach on one team, built the way production builds one (see InjuryOverviewAuditTest). */
    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the head coach auth role must be seeded' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }
}
