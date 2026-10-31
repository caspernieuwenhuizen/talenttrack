<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\CoachForms;

/**
 * The new-evaluation form takes a player id from the URL. It names that
 * player (name, team, position, photo) only for a reader the save would
 * accept: a coach of another team gets the picker, not the player.
 */
final class EvalFormPresetPlayerScopeTest extends WP_UnitTestCase {

    private int $own_team   = 0;
    private int $own_player = 0;
    private int $other      = 0;
    private int $coach      = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Preset Own U12', 'age_group' => 'U12' ] );
        $this->own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Preset Other U12', 'age_group' => 'U12' ] );
        $other_team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Ownpreset', 'last_name' => 'Kidx',
            'team_id' => $this->own_team, 'status' => 'active',
        ] );
        $this->own_player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Otherpreset', 'last_name' => 'Kidx',
            'team_id' => $other_team, 'status' => 'active',
        ] );
        $this->other = (int) $wpdb->insert_id;

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Preset', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $this->own_team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->own_team,
        ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_player_of_the_coachs_own_team_is_preset(): void {
        $this->assertTrue( QueryHelpers::coach_owns_player( $this->coach, $this->own_player ), 'precondition: the coach coaches this player' );

        $html = $this->form( $this->own_player );
        $this->assertStringContainsString( 'Ownpreset', $html );
        $this->assertStringContainsString( 'name="player_id" value="' . $this->own_player . '"', $html );
    }

    public function test_a_player_of_another_team_is_not_named(): void {
        $this->assertFalse( QueryHelpers::coach_owns_player( $this->coach, $this->other ), 'precondition: the coach does not coach this player' );

        $html = $this->form( $this->other );
        $this->assertStringNotContainsString( 'Otherpreset', $html );
        $this->assertStringNotContainsString( 'Preset Other U12', $html );
        $this->assertStringNotContainsString( 'value="' . $this->other . '"', $html );
        $this->assertStringContainsString( 'data-tt-eval-player-wrap', $html, 'the form opens with the picker instead' );
    }

    private function form( int $preset ): string {
        ob_start();
        CoachForms::renderEvalForm( QueryHelpers::get_teams_in_scope( $this->coach, false ), false, $preset );
        return (string) ob_get_clean();
    }
}
