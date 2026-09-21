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
    private int $own_team     = 0;

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

        $this->own_team = $this->insertTeam( 'U15 own' );
        $other_team     = $this->insertTeam( 'U15 other' );

        $this->own_player   = $this->insertPlayer( $this->own_team );
        $this->other_player = $this->insertPlayer( $other_team );

        $this->head = $this->makeCoach( $this->own_team, true );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_head_coach_may_set_potential_on_their_own_squad_only(): void {
        // Each half on its own first, so a failure names which one refused.
        $this->assertTrue(
            PlayerStatusModule::potentialCaptureAvailable( $this->head ),
            'the head_coach persona must hold player_potential: change'
        );
        $this->assertTrue(
            AuthorizationService::canEditPlayer( $this->head, $this->own_player ),
            'the fixture must give the coach their own squad, or the grant below is vacuous'
        );
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
        $assistant = $this->makeCoach( $this->own_team, false );

        // The fixture has to be real: the user resolves to the assistant
        // persona alone, so the refusal below is about that persona.
        $this->assertSame(
            [ 'assistant_coach' ],
            \TT\Modules\Authorization\PersonaResolver::personasFor( $assistant )
        );
        $this->assertFalse(
            PlayerStatusModule::potentialCaptureAvailable( $assistant ),
            'assistant coaches are operational; potential is a development judgement (#1060)'
        );
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

    /**
     * A coach on one team, the way production builds one.
     *
     * Coaches are the `tt_coach` WordPress role; `PersonaResolver` splits
     * that into head_coach / assistant_coach from `tt_team_people.is_head_coach`.
     * The team-scoped `tt_user_role_scopes` row is what both
     * `MatrixGate`'s team check and `canEditPlayer()` read for reach.
     */
    private function makeCoach( int $team_id, bool $head ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $head ? 'head_coach' : 'assistant_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the coach auth role must be seeded' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => $head ? 'Head' : 'Assistant',
            'last_name'  => 'Coach',
            'role_type'  => $head ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $this->assertNotFalse( $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => $head ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $head ? 1 : 0,
        ] ), 'the team assignment is what the persona split reads' );

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
