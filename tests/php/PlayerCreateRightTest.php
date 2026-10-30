<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Wizards\Player\NewPlayerWizard;

/**
 * #4292 — adding a player follows the matrix's create right.
 *
 * Creation and the CSV import were wired to `tt_edit_players`, the change
 * right every head coach holds for their own squad, so a head coach could
 * register players the matrix never granted and saw the player-cap nudge
 * as a data error. `players: create_delete` is seeded to the head of
 * development and the academy admin only; every create path now asks it,
 * and correcting a squad player's record stays where it was.
 */
final class PlayerCreateRightTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Create-right U14', 'age_group' => 'U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $club,
            'first_name' => 'Squad',
            'last_name'  => 'Createright',
            'team_id'    => $this->team,
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_head_of_development_and_the_academy_admin_may_add_a_player(): void {
        foreach ( [ 'tt_head_dev', 'tt_club_admin' ] as $role ) {
            $uid = self::factory()->user->create( [ 'role' => $role ] );
            $this->assertTrue( AuthorizationService::canCreatePlayer( $uid ), $role . ' holds players: create_delete' );
        }
    }

    public function test_a_head_coach_may_not_add_a_player_and_still_edits_their_squad(): void {
        $coach = $this->makeHeadCoach( $this->team );

        $this->assertTrue(
            AuthorizationService::userCanOrMatrix( $coach, 'tt_edit_players' ),
            'the head coach holds the edit right, or the refusal below proves nothing'
        );
        $this->assertFalse( AuthorizationService::canCreatePlayer( $coach ) );
        $this->assertTrue( AuthorizationService::canEditPlayer( $coach, $this->player ) );
    }

    public function test_post_players_and_the_import_refuse_a_head_coach_and_put_on_their_squad_does_not(): void {
        $coach = $this->makeHeadCoach( $this->team );
        wp_set_current_user( $coach );

        $post = new WP_REST_Request( 'POST', '/talenttrack/v1/players' );
        $post->set_body_params( [ 'first_name' => 'Coach', 'last_name' => 'Made', 'team_id' => $this->team ] );
        $this->assertSame( 403, rest_do_request( $post )->get_status() );

        $import = new WP_REST_Request( 'POST', '/talenttrack/v1/players/import' );
        $this->assertSame( 403, rest_do_request( $import )->get_status() );

        $put = new WP_REST_Request( 'PUT', '/talenttrack/v1/players/' . $this->player );
        $put->set_body_params( [ 'jersey_number' => 7 ] );
        $this->assertSame( 200, rest_do_request( $put )->get_status() );
    }

    public function test_post_players_admits_the_head_of_development(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_head_dev' ] ) );

        $post = new WP_REST_Request( 'POST', '/talenttrack/v1/players' );
        $post->set_body_params( [ 'first_name' => 'Hod', 'last_name' => 'Made', 'team_id' => $this->team ] );
        $this->assertNotSame( 403, rest_do_request( $post )->get_status(), 'the create right is admitted' );
    }

    public function test_the_new_player_wizard_asks_the_create_right(): void {
        $this->assertSame( 'tt_manage_players', ( new NewPlayerWizard() )->requiredCap() );
    }

    public function test_the_wizard_and_the_dashboard_card_refuse_a_head_coach_and_admit_the_hod(): void {
        $coach = $this->makeHeadCoach( $this->team );
        $hod   = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        // The card and the empty-state CTA ask the plain capability; the
        // legacy alias maps `tt_manage_players` to view + edit, which the
        // head coach holds, so this is the answer that must still be no.
        $this->assertFalse( user_can( $coach, 'tt_manage_players' ), 'the Add player card hides for a head coach' );
        $this->assertTrue( user_can( $hod, 'tt_manage_players' ), 'and shows for the HoD' );

        $this->assertNotNull( \TT\Shared\Wizards\WizardRegistry::find( 'new-player' ), 'the wizard is registered, or the two answers below mean nothing' );
        $this->assertTrue( \TT\Shared\Wizards\WizardRegistry::isEnabled( 'new-player' ) );
        $this->assertFalse( \TT\Shared\Wizards\WizardRegistry::isAvailable( 'new-player', $coach ), 'the wizard refuses a head coach before the first step' );
        $this->assertTrue( \TT\Shared\Wizards\WizardRegistry::isAvailable( 'new-player', $hod ), 'and opens for the HoD' );
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
