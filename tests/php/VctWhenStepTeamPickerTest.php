<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Vct\Wizard\WhenStep;

/**
 * #4024 — the VCT wizard offers the teams you can actually plan for.
 *
 * Step 1 built its picker from `current_user_can('tt_edit_settings') ?
 * get_teams() : get_teams_for_coach()`. The Head of Development holds
 * neither that WordPress capability nor a coach assignment, so the select
 * contained its placeholder and nothing else, `required` kept Next
 * disabled, and the whole designer was unreachable for the one persona
 * whose job is planning across the academy. #1942 replaced that phantom
 * `tt_edit_settings` gate everywhere else; this step was missed.
 *
 * The picker and `validate()` now ask the same question — may this user
 * create a VCT session for this team — so the select can no longer offer a
 * team the submit refuses, which was the second half of the bug.
 */
final class VctWhenStepTeamPickerTest extends WP_UnitTestCase {

    private string $p    = '';
    private int $mine    = 0;
    private int $theirs  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->mine   = $this->team( 'Designer Mine' );
        $this->theirs = $this->team( 'Designer Theirs' );
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    /** A head coach with a person row and a scope on one team. */
    private function coachOn( int $team_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Designer',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        // `club_id` matters: get_teams_for_coach() joins the scope row to
        // the team on it, so a row without one narrows to nothing.
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id'    => 1,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    /** The Head of Development: global VCT scope, no `tt_edit_settings`. */
    private function headOfDevelopment(): int {
        $uid = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    /** The rendered markup of step 1 for this user. */
    private function renderFor( int $user_id ): string {
        wp_set_current_user( $user_id );
        ob_start();
        ( new WhenStep() )->render( [] );
        $html = (string) ob_get_clean();
        wp_set_current_user( 0 );
        return $html;
    }

    // -----------------------------------------------------------------

    /**
     * The reproduction. An academy-wide planner with global VCT scope and
     * no `tt_edit_settings` must see every team.
     */
    public function test_an_academy_wide_planner_without_tt_edit_settings_sees_every_team(): void {
        $uid = $this->headOfDevelopment();

        $this->assertFalse(
            user_can( $uid, 'tt_edit_settings' ),
            'fixture sanity: the persona must NOT hold the capability the old gate asked for'
        );
        $this->assertTrue(
            AuthorizationService::canPlanForTeam( $uid, $this->mine, 'create_delete' ),
            'fixture sanity: they may plan, so an empty picker is the picker\'s fault'
        );

        $html = $this->renderFor( $uid );

        $this->assertStringContainsString( 'Designer Mine', $html );
        $this->assertStringContainsString( 'Designer Theirs', $html );
        $this->assertStringContainsString( 'value="' . $this->mine . '"', $html );
    }

    public function test_a_coach_sees_only_the_team_they_hold(): void {
        $uid = $this->coachOn( $this->mine );

        $html = $this->renderFor( $uid );

        $this->assertStringContainsString( 'Designer Mine', $html, 'their own squad is offered' );
        $this->assertStringNotContainsString( 'Designer Theirs', $html, 'and nobody else\'s' );
    }

    /**
     * The picker and the submit must agree. Any option the select offers
     * has to survive `validate()`.
     */
    public function test_every_offered_team_survives_validation(): void {
        foreach ( [ $this->coachOn( $this->mine ), $this->headOfDevelopment() ] as $uid ) {
            $html = $this->renderFor( $uid );

            preg_match_all( '/<option value="(\d+)"/', $html, $m );
            $offered = array_map( 'intval', $m[1] );
            $this->assertNotSame( [], $offered, 'a user who can plan must be offered something' );

            wp_set_current_user( $uid );
            foreach ( $offered as $team_id ) {
                $this->assertTrue(
                    AuthorizationService::canPlanForTeam( $uid, $team_id, 'create_delete' ),
                    "team {$team_id} was offered to user {$uid} but the submit would refuse it"
                );
            }
            wp_set_current_user( 0 );
        }
    }

    /**
     * A reader with no VCT scope gets an honest dead end rather than a
     * silent one: the select is empty and the step says which access is
     * missing.
     */
    public function test_a_user_with_no_vct_scope_is_told_why_the_picker_is_empty(): void {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $html = $this->renderFor( $uid );

        $this->assertStringNotContainsString( 'Designer Mine', $html );
        $this->assertStringContainsString( 'tt-notice', $html, 'the step explains the empty select' );
    }
}
