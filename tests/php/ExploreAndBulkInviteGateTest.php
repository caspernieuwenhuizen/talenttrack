<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Frontend\FrontendExploreView;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Invitations\Frontend\InvitationBulkCreateHandler;

/**
 * #3161 — two holes of the same kind: a check that is not there at all,
 * rather than one that is too wide.
 *
 * `?tt_view=explore` had no capability check, and both dispatcher gates in
 * front of it fail open for a slug registered through
 * `registerSlugOwnership` alone. The bulk-invite handler read the team id
 * from the POST and never asked whose team it was, though the seed grants
 * head_coach `invitations: c [team]` — a scope it discarded.
 */
final class ExploreAndBulkInviteGateTest extends WP_UnitTestCase {

    private int $mineTeamId  = 0;
    private int $otherTeamId = 0;
    private int $coachUserId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Invite Mine' ] );
        $this->mineTeamId = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Invite Theirs' ] );
        $this->otherTeamId = (int) $wpdb->insert_id;

        $this->coachUserId = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Invite',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coachUserId,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->mineTeamId,
        ] );
    }

    public function tear_down(): void {
        unset( $_GET['kpi'], $_GET['action'] );
        parent::tear_down();
    }

    // ---------------------------------------------------------------
    // 1. the explorer
    // ---------------------------------------------------------------

    public function test_explore_refuses_without_the_analytics_capability(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $user_id );
        $this->assertFalse( current_user_can( 'tt_view_analytics' ), 'precondition' );

        ob_start();
        FrontendExploreView::render( $user_id, false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'permission', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'CLAUDE.md §5 — the refusal renders the chain' );
        $this->assertStringNotContainsString(
            'tt-explore', $html,
            'no part of the explorer surface renders for a refused viewer'
        );
    }

    public function test_explore_still_opens_for_a_holder_of_the_capability(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $user    = new \WP_User( $user_id );
        $user->add_cap( 'tt_view_analytics' );
        wp_set_current_user( $user_id );
        $this->assertTrue( current_user_can( 'tt_view_analytics' ), 'precondition' );

        ob_start();
        FrontendExploreView::render( $user_id, true );
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString( 'You do not have permission', $html );
    }

    /**
     * The gate sits ahead of the CSV and PDF branches, which stream fact
     * rows. A gate after them would guard the page and not the data.
     */
    public function test_the_gate_precedes_the_export_branches(): void {
        $method = new \ReflectionMethod( FrontendExploreView::class, 'render' );
        $source = file_get_contents(
            TT_PLUGIN_DIR . 'src/Modules/Analytics/Frontend/FrontendExploreView.php'
        );
        $lines = array_slice(
            explode( "\n", (string) $source ),
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1
        );
        $body = implode( "\n", $lines );

        $gate_at   = strpos( $body, "current_user_can( 'tt_view_analytics' )" );
        $export_at = strpos( $body, 'export_csv' );
        $this->assertIsInt( $gate_at, 'the capability gate must exist' );
        $this->assertIsInt( $export_at );
        $this->assertLessThan( $export_at, $gate_at );
    }

    // ---------------------------------------------------------------
    // 2. bulk invitations
    // ---------------------------------------------------------------

    public function test_a_coach_may_bulk_invite_only_for_a_team_they_coach(): void {
        wp_set_current_user( $this->coachUserId );

        $this->assertTrue(
            InvitationBulkCreateHandler::mayInviteForTeam( $this->coachUserId, $this->mineTeamId )
        );
        $this->assertFalse(
            InvitationBulkCreateHandler::mayInviteForTeam( $this->coachUserId, $this->otherTeamId ),
            'a forged team_id must not reach the invite loop'
        );
        $this->assertFalse(
            InvitationBulkCreateHandler::mayInviteForTeam( $this->coachUserId, 0 )
        );
        $this->assertFalse(
            InvitationBulkCreateHandler::mayInviteForTeam( 0, $this->mineTeamId )
        );
    }

    public function test_global_invite_creation_reaches_every_team(): void {
        // Head of Development holds `invitations: c [global]` and coaches
        // no team. A `tt_edit_settings` compare would lock them out (#2866);
        // the matrix question is the one that answers correctly.
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        wp_set_current_user( $hod );

        $this->assertTrue( InvitationBulkCreateHandler::mayInviteForTeam( $hod, $this->mineTeamId ) );
        $this->assertTrue( InvitationBulkCreateHandler::mayInviteForTeam( $hod, $this->otherTeamId ) );
    }

    public function test_the_handler_calls_the_scope_check_before_it_reads_a_roster(): void {
        $method = new \ReflectionMethod( InvitationBulkCreateHandler::class, 'handle' );
        $source = file_get_contents(
            TT_PLUGIN_DIR . 'src/Modules/Invitations/Frontend/InvitationBulkCreateHandler.php'
        );
        $body = implode( "\n", array_slice(
            explode( "\n", (string) $source ),
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1
        ) );

        $check_at  = strpos( $body, 'mayInviteForTeam' );
        $roster_at = strpos( $body, 'unlinkedPlayers' );
        $this->assertIsInt( $check_at, 'the scope check must exist in handle()' );
        $this->assertIsInt( $roster_at );
        $this->assertLessThan( $roster_at, $check_at );
    }
}
