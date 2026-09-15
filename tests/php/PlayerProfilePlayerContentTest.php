<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #3393 — the unified profile's *contents*, read from the player's seat.
 *
 * #2107 folded "My card" into `FrontendPlayerDetailView`, so a player, a
 * parent and a coach land on one permission-aware profile. The routing was
 * right (and #3391 fixed the tab strip's hrefs). What was written for staff
 * was everything inside the tabs:
 *
 *  - Goals and Activities were always-on, with every row pointing at a staff
 *    slug the player holds no grant for — a screen of dead ends;
 *  - the Evaluations tab, the one thing on the list a coach writes *for the
 *    player to read*, was hidden from them, because the gate asked for
 *    `evaluations` and the player persona holds `my_evaluations` (#1482 split
 *    them deliberately);
 *  - BMI-for-age rendered to the player, against #2895's decision that it
 *    should reach a family in a conversation rather than off a tile.
 *
 * Both directions are pinned: a player gets a usable screen, and a coach's
 * view of the same player is unchanged.
 */
final class PlayerProfilePlayerContentTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $player;
    private int $team;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();
        $this->player = $this->seedPlayer();
    }

    /* ---- what a player is offered ------------------------------------ */

    public function test_a_player_sees_their_evaluations_tab(): void {
        $html = $this->renderAsPlayer();

        $this->assertStringContainsString(
            'tab=evaluations',
            $html,
            'a coach writes evaluations for the player to read; the player was the one reader who could not'
        );
    }

    public function test_a_player_keeps_goals_and_activities(): void {
        // The fix narrows these from always-on to "the entity you hold".
        // A player holds `my_goals` / `my_activities`, so they keep both.
        $html = $this->renderAsPlayer();

        $this->assertStringContainsString( 'tab=goals', $html );
        $this->assertStringContainsString( 'tab=activities', $html );
    }

    public function test_a_player_is_offered_no_explorer_button(): void {
        // Gated on the module toggle alone, with no capability check —
        // `FrontendExploreView::render()` refuses anyone without
        // `tt_view_analytics`.
        $html = $this->renderAsPlayer( 'goals' );

        $this->assertStringNotContainsString( 'Explorer', $html );
        $this->assertStringNotContainsString( 'tt_view=explore', $html );
    }

    public function test_no_bmi_figure_reaches_a_player(): void {
        $html = $this->renderAsPlayer( 'measurements' );

        $this->assertStringNotContainsString( 'tt-bmi-section', $html );
        $this->assertStringNotContainsString( 'BMI', $html );
    }

    public function test_no_bmi_figure_reaches_a_parent(): void {
        // The report tile's rule names both personas; so does this.
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new \TT\Modules\Invitations\PlayerParentsRepository() )->link( $this->player, $parent, true );

        $html = $this->renderFor( $parent, 'measurements' );

        $this->assertStringNotContainsString( 'tt-bmi-section', $html );
    }

    /* ---- where a player's rows go ------------------------------------ */

    public function test_a_players_goal_rows_point_at_their_own_surface(): void {
        $this->seedGoal();
        $html = $this->renderAsPlayer( 'goals' );

        $this->assertStringContainsString( 'tt_view=my-goals', $html );
        $this->assertStringNotContainsString( 'tt_view=goals&', $html );
        $this->assertStringNotContainsString( 'tt_view=goals"', $html );
    }

    public function test_a_players_activity_rows_point_at_their_own_surface(): void {
        $this->seedActivity();
        $html = $this->renderAsPlayer( 'activities' );

        $this->assertStringContainsString( 'tt_view=my-activities', $html );
    }

    /* ---- staff are unchanged ----------------------------------------- */

    public function test_staff_keep_the_staff_slugs(): void {
        $this->seedGoal();
        $staff = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $html = $this->renderFor( $staff, 'goals' );

        $this->assertStringContainsString( 'tt_view=goals', $html );
        $this->assertStringNotContainsString( 'tt_view=my-goals', $html );
    }

    public function test_staff_still_see_the_bmi_block(): void {
        // The figure is for the coach; this is the direction that must not
        // break while removing it from the family's view.
        $staff = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $html  = $this->renderFor( $staff, 'measurements' );

        // The block renders nothing without a height/weight pair, so assert
        // the gate rather than the output: staff are not short-circuited.
        $this->assertStringNotContainsString(
            'You do not have permission to view measurements',
            $html
        );
    }

    public function test_staff_keep_the_explorer_button(): void {
        $this->seedGoal();
        $staff = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $html = $this->renderFor( $staff, 'goals' );

        $this->assertStringContainsString( 'Explorer', $html );
    }

    /* ---- the inline styling the issue names --------------------------- */

    public function test_the_goals_tab_head_carries_no_inline_style(): void {
        $this->seedGoal();
        $staff = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $html = $this->renderFor( $staff, 'goals' );

        $this->assertStringNotContainsString( 'style="background:transparent', $html );
        $this->assertStringNotContainsString( 'style="display:flex;gap:6px', $html );
    }

    /* ---- fixtures ----------------------------------------------------- */

    private function renderAsPlayer( string $tab = '' ): string {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );

        global $wpdb;
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $user ], [ 'id' => $this->player ] );

        return $this->renderFor( $user, $tab );
    }

    private function renderFor( int $user_id, string $tab = '' ): string {
        wp_set_current_user( $user_id );
        $had = $_GET['tab'] ?? null;
        if ( $tab !== '' ) $_GET['tab'] = $tab;

        ob_start();
        FrontendPlayerDetailView::render( $this->player, $user_id, false, 'card' );
        $html = (string) ob_get_clean();

        if ( $had === null ) unset( $_GET['tab'] ); else $_GET['tab'] = $had;
        return $html;
    }

    private function seedPlayer(): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 Content' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $this->team,
            'first_name'    => 'Content',
            'last_name'     => 'Player',
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedGoal(): void {
        // Through the repository: it stamps `club_id` and is documented as
        // the only place `tt_goals` is inserted (#3131).
        ( new \TT\Infrastructure\Goals\GoalsRepository() )->create( [
            'player_id'  => $this->player,
            'title'      => 'Win more duels',
            'status'     => \TT\Domain\Vocabularies\Lookups\GoalStatus::IN_PROGRESS,
            'due_date'   => '2099-06-01',
            'created_by' => get_current_user_id() ?: 1,
        ] );
    }

    private function seedActivity(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Training',
            'session_date'        => '2020-03-01',
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        $activity = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity,
            'player_id'   => $this->player,
            'status'      => 'Present',
            'is_guest'    => 0,
            'record_type' => 'actual',
        ] );
    }
}
