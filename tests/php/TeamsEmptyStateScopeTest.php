<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendTeamsManageView;

/**
 * #4029 — an empty teams list says why it is empty.
 *
 * There was one empty-state card, #1362's fresh-install guide: "No teams
 * yet … Create your first team to build the academy structure." It rendered
 * whenever the list came back empty, whatever the reason. The list is scoped
 * to the viewer's own assignments, so a coach nobody had assigned to a team
 * opened "My teams" and was told the academy had no teams and invited to
 * build one — while four teams sat there. In the pilot the developer's coach
 * account read zero teams for five days before anyone worked out that the
 * answer was a missing assignment, not a missing team.
 *
 * Three states, and all three are asserted here, because the danger in a fix
 * like this is over-reach: the fresh-install card is right for an
 * administrator on day one and must survive.
 */
final class TeamsEmptyStateScopeTest extends WP_UnitTestCase {

    private string $p = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        // The state under test is "the academy has teams and this viewer
        // still sees none", so start from a clean table and add teams
        // deliberately.
        $wpdb->query( "DELETE FROM {$this->p}tt_teams" );
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    /** A coach with a staff record and no team assignment at all. */
    private function unassignedCoach(): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Unassigned',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    /** A staff account nobody ever put through the Staff section. */
    private function coachWithNoPersonRecord(): int {
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function renderFor( int $user_id, bool $is_admin = false ): string {
        wp_set_current_user( $user_id );
        ob_start();
        FrontendTeamsManageView::render( $user_id, $is_admin );
        $html = (string) ob_get_clean();
        wp_set_current_user( 0 );
        return $html;
    }

    // -----------------------------------------------------------------

    public function test_a_scoped_coach_with_no_assignment_is_told_they_are_not_linked(): void {
        $this->team( 'U12' );
        $this->team( 'U17' );
        $uid = $this->unassignedCoach();

        $this->assertFalse(
            QueryHelpers::user_has_global_entity_read( $uid, 'team' ),
            'fixture sanity: this viewer is scoped, which is what makes the list empty'
        );
        $this->assertSame( [], QueryHelpers::get_teams_for_coach( $uid ), 'and holds no assignment' );

        $html = $this->renderFor( $uid );

        $this->assertStringContainsString( 'not linked to a team', $html );
        $this->assertStringNotContainsString(
            'Create your first team',
            $html,
            'creating a team is not the fix, and offering it sends them further from the one that is'
        );
        $this->assertStringNotContainsString( 'No teams yet', $html );
    }

    /**
     * The other half of the scoped state: an account with no staff record
     * needs a different thing done to it, so it says a different thing.
     */
    public function test_a_coach_with_no_staff_record_is_told_that_too(): void {
        $this->team( 'U12' );
        $uid = $this->coachWithNoPersonRecord();

        $this->assertSame( 0, QueryHelpers::person_id_for_user( $uid ), 'fixture sanity' );

        $html = $this->renderFor( $uid );

        $this->assertStringContainsString( 'not linked to a team', $html );
        $this->assertStringContainsString( 'staff record', $html, 'the part an administrator needs to hear' );
        $this->assertStringNotContainsString( 'Create your first team', $html );
    }

    /**
     * The regression guard. #1362's guided card is right for the person
     * setting the academy up, and a fix that removed it would trade one
     * bad first day for another.
     */
    public function test_an_unscoped_admin_on_an_empty_install_still_gets_the_guided_card(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->assertTrue( QueryHelpers::user_has_global_entity_read( $uid, 'team' ), 'fixture sanity' );

        $html = $this->renderFor( $uid, true );

        $this->assertStringContainsString( 'No teams yet', $html );
        $this->assertStringContainsString( 'Create your first team', $html );
        $this->assertStringNotContainsString( 'not linked to a team', $html );
    }

    /** The heading does not branch — the admin's full list lives here too. */
    public function test_the_heading_stays_teams_in_every_state(): void {
        $this->team( 'U12' );

        foreach ( [ $this->unassignedCoach(), self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $uid ) {
            $this->assertStringContainsString(
                'Teams',
                $this->renderFor( $uid ),
                'the tile already says "My teams"; the page says Teams'
            );
        }
    }
}
