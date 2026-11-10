<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #4990 — the player profile's Activities tab counts an activity as
 * completed by its status (`activity_status_key`), the rule of the activity
 * page and every report, not by `plan_state`. A row the planner never
 * created defaults `plan_state` to completed, so the tab showed it as done
 * while its own page did not.
 */
final class PlayerActivitiesTabStatusTest extends WP_UnitTestCase {

    private int $club   = 0;
    private int $team   = 0;
    private int $player = 0;
    private int $staff  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO15-4990' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Sem', 'last_name' => 'Status',
            'status' => 'active', 'wp_user_id' => null,
        ] );
        $this->player = (int) $wpdb->insert_id;
        $this->staff  = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $past   = gmdate( 'Y-m-d', strtotime( '-5 days' ) );
        $future = gmdate( 'Y-m-d', strtotime( '+5 days' ) );
        // Status and plan state agree.
        $this->activity( 'Done 4990', $past, 'completed', 'completed' );
        // Status completed, plan state never moved on: it happened.
        $this->activity( 'Done by status 4990', $past, 'completed', 'scheduled' );
        // No planner entry: plan state defaulted to completed, status says
        // planned. It did not happen.
        $this->activity( 'Plan state only 4990', $past, 'planned', 'completed' );
        $this->activity( 'Cancelled 4990', $past, 'cancelled', 'completed' );
        // Completed by status but dated ahead.
        $this->activity( 'Completed ahead 4990', $future, 'completed', 'completed' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_tab_shows_an_activity_by_its_status_whatever_its_plan_state(): void {
        $html = $this->renderTab();
        $this->assertStringContainsString( 'Done 4990', $html );
        $this->assertStringContainsString( 'Done by status 4990', $html, 'completed by status, though its plan state is scheduled' );
        $this->assertStringNotContainsString( 'Plan state only 4990', $html, 'a planned activity whose plan state defaulted to completed is not on the tab' );
        $this->assertStringNotContainsString( 'Cancelled 4990', $html );
        $this->assertStringNotContainsString( 'Completed ahead 4990', $html, 'nor one dated in the future' );
    }

    public function test_the_badge_counts_the_activities_with_that_status_in_the_same_window(): void {
        global $wpdb;
        $by_status = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT a.id) FROM {$wpdb->prefix}tt_activities a
               JOIN {$wpdb->prefix}tt_attendance att ON att.activity_id = a.id
              WHERE att.player_id = %d AND a.activity_status_key = 'completed' AND a.session_date <= CURDATE() AND a.archived_at IS NULL",
            $this->player
        ) );
        $this->assertSame( 2, $by_status, 'precondition: two completed activities in the window' );
        $this->assertSame( $by_status, (int) PlayerFileCounts::for( $this->player )['activities'], 'the badge counts them' );

        $listed = ( new ActivitiesRepository() )->listForPlayer( $this->player, 25, 'DESC', [ 'record_types' => null, 'completed_only' => true ] );
        $this->assertCount( $by_status, $listed, 'and the tab lists the same' );
    }

    private function renderTab(): string {
        global $wpdb;
        wp_set_current_user( $this->staff );
        $player = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d", $this->player ) );
        $method = new ReflectionMethod( FrontendPlayerDetailView::class, 'renderActivitiesTab' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $this->player, $player, [ 'is_family' => false, 'is_self' => false, 'player_id' => $this->player ] );
        return (string) ob_get_clean();
    }

    private function activity( string $title, string $date, string $status, string $plan_state ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => $title,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'plan_state'          => $plan_state,
            'activity_status_key' => $status,
        ] );
        $wpdb->insert( "{$p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => (int) $wpdb->insert_id,
            'player_id'   => $this->player,
            'status'      => 'present',
            'is_guest'    => 0,
            'record_type' => 'actual',
        ] );
    }
}
