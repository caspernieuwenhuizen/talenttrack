<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Media\Wizard\MediaTargetStep;
use TT\Shared\Frontend\FrontendTeamsManageView;

/**
 * #3157 — two coach-reachable pickers were built from
 * `QueryHelpers::get_players()` with no argument, which returns every
 * active child in the club.
 *
 * The roster "Add player" dropdown carried a comment saying the pool was
 * deliberately unfiltered because "the entry point is what's gated". The
 * entry point was not gated: `renderForm()` had no capability check at
 * all, so `?tt_view=teams&action=edit&id=N` opened it. The media wizard's
 * target step is worse in kind rather than degree — it is the screen where
 * a coach decides whose photo gets stored.
 */
final class PlayerPickerScopeTest extends WP_UnitTestCase {

    private int $mineTeamId  = 0;
    private int $otherTeamId = 0;
    private int $coachUserId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Picker Mine' ] );
        $this->mineTeamId = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Picker Theirs' ] );
        $this->otherTeamId = (int) $wpdb->insert_id;

        $this->makePlayer( 'Bram', 'Vandenberghe', $this->mineTeamId );
        $this->makePlayer( 'Sietse', 'Roelofsma', $this->otherTeamId );

        $this->coachUserId = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Picker',
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

        wp_set_current_user( $this->coachUserId );
    }

    public function tear_down(): void {
        unset( $_GET['tt_view'], $_GET['action'], $_GET['id'] );
        parent::tear_down();
    }

    private function makePlayer( string $first, string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => $first,
            'last_name'  => $last,
            'team_id'    => $team_id,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param object[] $players */
    private function surnames( array $players ): array {
        return array_map( static fn( $p ): string => (string) $p->last_name, $players );
    }

    // ---------------------------------------------------------------
    // the helper
    // ---------------------------------------------------------------

    public function test_a_coach_is_scoped_to_their_own_teams(): void {
        $names = $this->surnames(
            QueryHelpers::get_players_in_scope( $this->coachUserId, false )
        );
        $this->assertContains( 'Vandenberghe', $names );
        $this->assertNotContains( 'Roelofsma', $names );
    }

    public function test_the_admin_flag_sees_the_whole_academy(): void {
        $names = $this->surnames(
            QueryHelpers::get_players_in_scope( $this->coachUserId, true )
        );
        $this->assertContains( 'Vandenberghe', $names );
        $this->assertContains( 'Roelofsma', $names );
    }

    public function test_global_players_read_sees_everyone_without_coaching_a_team(): void {
        // A head of development coaches no team and, since #0071, holds no
        // `tt_edit_settings` — the #2866 failure mode a raw cap compare
        // would reintroduce. Global `players` read is what carries them.
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        wp_set_current_user( $hod );

        $names = $this->surnames( QueryHelpers::get_players_in_scope( $hod, false ) );
        $this->assertContains( 'Vandenberghe', $names );
        $this->assertContains( 'Roelofsma', $names );
    }

    // ---------------------------------------------------------------
    // the two pickers
    // ---------------------------------------------------------------

    public function test_the_roster_add_dropdown_offers_only_players_in_scope(): void {
        $_GET['tt_view'] = 'teams';
        $_GET['action']  = 'edit';
        $_GET['id']      = (string) $this->mineTeamId;

        ob_start();
        FrontendTeamsManageView::render( $this->coachUserId, false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'data-tt-roster-picker', $html, 'the picker still renders' );
        $this->assertStringNotContainsString(
            'Roelofsma', $html,
            'a child on a team the viewer does not coach must not appear in the pool'
        );
    }

    public function test_the_edit_form_refuses_without_the_teams_capability(): void {
        $outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $outsider );

        $_GET['tt_view'] = 'teams';
        $_GET['action']  = 'edit';
        $_GET['id']      = (string) $this->mineTeamId;

        ob_start();
        FrontendTeamsManageView::render( $outsider, false );
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString( 'data-tt-roster-picker', $html );
        $this->assertStringNotContainsString( 'id="tt-team-form"', $html );

        // #3200 — deliberately not pinned to the word "permission" any more.
        // Two correct refusals now stack on this path, and which one a given
        // user meets depends on whether they can read the team at all:
        //
        //   #3152 refuses in render() when `AllTeamsScope::canReadTeam()`
        //         says no, before anything is loaded;
        //   #3157 refuses in renderForm() when `tt_edit_teams` says no.
        //
        // A subscriber fails the first, so they never reach the second — and
        // that order is right, because telling someone they lack edit rights
        // on a team they may not even look at would confirm the team exists.
        // Pinning the more specific sentence made this test assert which
        // guard fired rather than that the surface refused, which is not the
        // property #3157 is about.
        //
        // The property is above: no form, no roster picker. This only adds
        // that the refusal is a refusal and not an empty page.
        $this->assertMatchesRegularExpression(
            '/do not have (permission|access)/',
            $html,
            'the surface refuses in words, whichever of the two guards fired'
        );
    }

    public function test_the_media_target_step_offers_only_players_in_scope(): void {
        wp_set_current_user( $this->coachUserId );

        ob_start();
        ( new MediaTargetStep() )->render( [] );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Vandenberghe', $html );
        $this->assertStringNotContainsString(
            'Roelofsma', $html,
            'the consent decision must not begin with a list of children the coach has no relationship with'
        );
    }
}
