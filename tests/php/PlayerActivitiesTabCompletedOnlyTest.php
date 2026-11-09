<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #4968 — the player profile's Activities tab shows what happened: completed
 * activities dated today or earlier, for staff, a parent and the player
 * alike. Upcoming trainings, a past activity whose plan state was never
 * recorded and a completed row dated in the future stay off it, no
 * "Planned" pill can appear, and the badge counts exactly what the tab lists.
 */
final class PlayerActivitiesTabCompletedOnlyTest extends WP_UnitTestCase {

    private int $club       = 0;
    private int $team       = 0;
    private int $player     = 0;
    private int $player_uid = 0;
    private int $parent_uid = 0;
    private int $staff_uid  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO13-4968' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->player_uid = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Jip', 'last_name' => 'Ruis',
            'wp_user_id' => $this->player_uid, 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->parent_uid = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $this->parent_uid, 'is_primary' => 1,
        ] );
        $this->staff_uid = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $past   = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
        $future = gmdate( 'Y-m-d', strtotime( '+7 days' ) );
        $this->activity( 'Played 4968', $past, 'completed', 'actual' );
        $this->activity( 'Upcoming planned 4968', $future, 'planned', 'expected' );
        $this->activity( 'Upcoming scheduled 4968', $future, 'scheduled', 'expected' );
        $this->activity( 'Never recorded 4968', $past, 'planned', 'expected' );
        $this->activity( 'Completed ahead 4968', $future, 'completed', 'actual' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_reader_sees_only_the_completed_past_activity(): void {
        $readers = [
            'staff'  => [ $this->staff_uid, [ 'is_family' => false, 'is_self' => false, 'player_id' => $this->player ] ],
            'parent' => [ $this->parent_uid, [ 'is_family' => true, 'is_self' => false, 'player_id' => $this->player ] ],
            'player' => [ $this->player_uid, [ 'is_family' => true, 'is_self' => true, 'player_id' => $this->player ] ],
        ];
        foreach ( $readers as $label => [ $uid, $viewer ] ) {
            $html = $this->renderTab( $uid, $viewer );
            $this->assertStringContainsString( 'Played 4968', $html, "{$label}: the completed activity" );
            foreach ( [ 'Upcoming planned 4968', 'Upcoming scheduled 4968', 'Never recorded 4968', 'Completed ahead 4968' ] as $hidden ) {
                $this->assertStringNotContainsString( $hidden, $html, "{$label}: {$hidden} is not on the tab" );
            }
            $this->assertStringNotContainsString( 'data-status="planned"', $html, "{$label}: no Planned pill" );
            $this->assertStringContainsString( 'Recent · 1', $html, "{$label}: one row" );
        }
    }

    public function test_the_badge_counts_what_the_tab_lists(): void {
        $this->assertSame( 1, (int) PlayerFileCounts::for( $this->player )['activities'] );
    }

    /** @param array{is_family: bool, is_self: bool, player_id: int} $viewer */
    private function renderTab( int $user_id, array $viewer ): string {
        global $wpdb;
        wp_set_current_user( $user_id );
        $player = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d", $this->player ) );
        $method = new ReflectionMethod( FrontendPlayerDetailView::class, 'renderActivitiesTab' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $this->player, $player, $viewer );
        return (string) ob_get_clean();
    }

    private function activity( string $title, string $date, string $plan_state, string $record_type ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => $title,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'plan_state'          => $plan_state,
            'activity_status_key' => $plan_state === 'completed' ? 'completed' : 'planned',
        ] );
        $wpdb->insert( "{$p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => (int) $wpdb->insert_id,
            'player_id'   => $this->player,
            'status'      => 'present',
            'is_guest'    => 0,
            'record_type' => $record_type,
        ] );
    }
}
