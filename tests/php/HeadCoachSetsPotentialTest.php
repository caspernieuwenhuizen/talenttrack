<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\PlayerStatusModule;

/**
 * #3967 — a head coach sets potential for their own squads, and only those.
 *
 * Every grant is paired with the refusal next to it. A test that only
 * asserted "the coach may set it on their own player" would pass with the
 * scope thrown away, and one that only asserted the refusal would pass
 * with the grant never landing.
 */
final class HeadCoachSetsPotentialTest extends WP_UnitTestCase {

    private int $club;
    private int $own_player   = 0;
    private int $other_player = 0;
    private int $head         = 0;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        PlayerStatusModule::ensureCapabilities();
        FeatureRegistry::setEnabled( 'potential_rating', true );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $own_team   = $this->insertTeam( 'U15 own' );
        $other_team = $this->insertTeam( 'U15 other' );

        $this->own_player   = $this->insertPlayer( $own_team );
        $this->other_player = $this->insertPlayer( $other_team );

        $this->head = self::factory()->user->create( [ 'role' => 'tt_head_coach' ] );
        $this->assignHeadCoach( $own_team, $this->head );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_head_coach_may_set_potential_on_their_own_squad_only(): void {
        $this->assertTrue(
            PlayerStatusModule::potentialCaptureAvailableFor( $this->own_player, $this->head ),
            'a head coach sets potential for the players they coach'
        );
        $this->assertFalse(
            PlayerStatusModule::potentialCaptureAvailableFor( $this->other_player, $this->head ),
            'team scope: another squad\'s player is not theirs to judge'
        );
    }

    public function test_the_rest_write_follows_the_same_line(): void {
        wp_set_current_user( $this->head );

        $this->assertContains(
            $this->postPotential( $this->own_player ),
            [ 200, 201 ],
            'the head coach\'s write onto their own player is accepted'
        );
        $this->assertSame(
            403,
            $this->postPotential( $this->other_player ),
            'and refused one squad over'
        );
    }

    public function test_an_assistant_coach_still_does_not_set_potential(): void {
        $assistant = self::factory()->user->create( [ 'role' => 'tt_assistant_coach' ] );
        AuthorizationService::flushCache();

        $this->assertFalse( PlayerStatusModule::potentialCaptureAvailable( $assistant ) );
    }

    public function test_switching_the_feature_off_still_wins(): void {
        FeatureRegistry::setEnabled( 'potential_rating', false );

        $this->assertFalse(
            PlayerStatusModule::potentialCaptureAvailableFor( $this->own_player, $this->head ),
            'the per-player grant must not reopen a feature the academy switched off'
        );

        FeatureRegistry::setEnabled( 'potential_rating', true );
    }

    private function postPotential( int $player_id ): int {
        $request = new WP_REST_Request( 'POST', "/talenttrack/v1/players/{$player_id}/potential" );
        $request->set_param( 'potential_band', 'semi_pro' );
        return (int) rest_do_request( $request )->get_status();
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    /** No date of birth: a missing field is not evidence of being too young. */
    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Potential',
            'last_name'  => 'Subject',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** Head-coach assignment through `tt_team_people` (#1315). */
    private function assignHeadCoach( int $team_id, int $user_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            'head_coach'
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => 'head_coach',
                'label'    => 'Head Coach',
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'wp_user_id' => $user_id,
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
        ] );
    }
}
