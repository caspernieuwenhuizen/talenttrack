<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Activities\Frontend\FrontendAttendanceGridView;
use TT\Modules\Activities\Frontend\FrontendMinutesGridView;
use TT\Modules\Activities\Frontend\FrontendRatingsGridView;
use TT\Modules\Activities\Services\ActivityGridLink;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * The attendance, minutes and ratings grids offer a box only where their
 * save writes. A coach who also holds the club-wide read-only observer seat
 * sees every team's grid, and another team's renders read-only. A team
 * manager whose assignment has ended, or not started, is not offered that
 * team at all, because the save would skip it.
 */
final class GridWriteScopeTest extends WP_UnitTestCase {

    private int $own_team    = 0;
    private int $other_team  = 0;
    private int $own_match   = 0;
    private int $other_match = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        $this->flush();

        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Gridscope Own U14', 'age_group' => 'U14' ] );
        $this->own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Gridscope Other U14', 'age_group' => 'U14' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $this->own_match   = $this->match( $this->own_team, $this->player( $this->own_team ) );
        $this->other_match = $this->match( $this->other_team, $this->player( $this->other_team ) );

        $wpdb->insert( "{$p}tt_eval_categories", [
            'category_key' => 'gridscope_' . $this->own_match, 'label' => 'Gridscope passing',
            'display_order' => 1, 'is_active' => 1,
        ] );
    }

    public function tear_down(): void {
        $_GET = [];
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_attendance_grid_renders_another_teams_cells_read_only(): void {
        $coach = $this->observerCoach();

        $own = $this->attendanceGrid( $coach, $this->own_team );
        $this->assertStringContainsString( 'tt-agrid-sel', $own, 'precondition: their own team is editable' );
        $this->assertStringContainsString( 'data-agrid-save', $own );

        $other = $this->attendanceGrid( $coach, $this->other_team );
        $this->assertStringContainsString( 'tt-agrid-cell--locked', $other, 'the read seat still sees the grid' );
        $this->assertStringNotContainsString( 'tt-agrid-sel', $other );
        $this->assertStringNotContainsString( 'tt-agrid__fill', $other );
        $this->assertStringNotContainsString( 'data-agrid-save', $other );
    }

    public function test_the_minutes_grid_renders_another_teams_cells_read_only(): void {
        $coach = $this->observerCoach();

        $own = $this->minutesGrid( $coach, $this->own_team );
        $this->assertStringContainsString( 'tt-agrid-min-in', $own, 'precondition: their own team is editable' );
        $this->assertStringContainsString( 'data-agrid-save', $own );

        // A match on the other team with no register yet: the read-only
        // grid explains it and offers no way to take the register.
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $this->other_team, 'title' => 'Gridscope unregistered',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-5 days' ) ),
            'activity_type_key' => 'game', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            'opponent' => 'Ajax', 'home_away' => 'home',
        ] );

        $other = $this->minutesGrid( $coach, $this->other_team );
        $this->assertStringContainsString( 'tt-agrid-cell--noreg', $other, 'precondition: the column without a register is shown' );
        $this->assertStringNotContainsString( 'tt-agrid-noreg__link', $other );
        $this->assertStringContainsString( 'tt-agrid-cell--locked', $other, 'the read seat still sees the grid' );
        $this->assertStringNotContainsString( 'tt-agrid-min-in', $other );
        $this->assertStringNotContainsString( 'tt-agrid-stat-in', $other );
        $this->assertStringNotContainsString( 'tt-agrid-score-in', $other );
        $this->assertStringNotContainsString( 'data-agrid-save', $other );
    }

    /**
     * Opened by a reader of the activity's team whom the save does not
     * cover, the scores are shown without a box. Rendered with
     * `$is_admin = false`, as the dashboard does for this reader.
     */
    public function test_the_ratings_grid_renders_another_teams_scores_read_only(): void {
        $coach = $this->observerCoach();

        $own = $this->ratingsGrid( $coach, $this->own_match );
        $this->assertStringContainsString( 'tt-rgrid-input', $own, 'precondition: their own team is editable' );
        $this->assertStringContainsString( 'tt-save-btn', $own );

        $other = $this->ratingsGrid( $coach, $this->other_match );
        $this->assertStringContainsString( 'data-tt-rgrid', $other, 'the read seat still sees the grid' );
        $this->assertStringContainsString( 'tt-rgrid-locked', $other );
        $this->assertStringNotContainsString( 'tt-rgrid-input', $other );
        $this->assertStringNotContainsString( 'tt-save-btn', $other );
    }

    public function test_a_coach_without_club_wide_read_is_refused_another_teams_ratings_grid(): void {
        $coach = $this->observerCoach( false );

        $other = $this->ratingsGrid( $coach, $this->other_match );
        $this->assertStringNotContainsString( 'data-tt-rgrid', $other );
        $this->assertStringContainsString( 'do not coach', html_entity_decode( $other, ENT_QUOTES ) );
    }

    /**
     * @dataProvider notLiveAssignments
     */
    public function test_an_assignment_that_is_not_live_does_not_list_its_team( string $start, string $end ): void {
        $manager = $this->manager( [
            [ $this->own_team, '', '' ],
            [ $this->other_team, $start, $end ],
        ] );
        $this->assertSame( [ $this->own_team ], ActivityAccess::registerTeamIds( $manager ), 'precondition: the save writes only the live team' );

        $listed = $this->attendanceGrid( $manager, $this->own_team );
        $this->assertStringContainsString( 'tt-agrid-sel', $listed, 'precondition: the live team is editable' );
        $this->assertStringNotContainsString( 'Gridscope Other U14', $listed, 'the team is not offered in the picker' );

        $direct = $this->attendanceGrid( $manager, $this->other_team );
        $this->assertStringNotContainsString( 'tt-agrid-sel', $direct, 'a direct link offers no box the save would skip' );
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function notLiveAssignments(): array {
        return [
            'ended'  => [ gmdate( 'Y-m-d', (int) strtotime( '-1 year' ) ), gmdate( 'Y-m-d', (int) strtotime( '-30 days' ) ) ],
            'future' => [ gmdate( 'Y-m-d', (int) strtotime( '+30 days' ) ), '' ],
        ];
    }

    private function attendanceGrid( int $user_id, int $team_id ): string {
        wp_set_current_user( $user_id );
        $this->flush();
        $_GET = [
            'tt_view' => 'attendance-grid', 'team_id' => (string) $team_id,
            'from' => gmdate( 'Y-m-d', (int) strtotime( '-60 days' ) ), 'to' => gmdate( 'Y-m-d' ),
        ];
        ob_start();
        FrontendAttendanceGridView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    private function minutesGrid( int $user_id, int $team_id ): string {
        wp_set_current_user( $user_id );
        $this->flush();
        $_GET = [
            'tt_view' => 'minutes-grid', 'team_id' => (string) $team_id,
            'from' => gmdate( 'Y-m-d', (int) strtotime( '-60 days' ) ), 'to' => gmdate( 'Y-m-d' ),
        ];
        ob_start();
        FrontendMinutesGridView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    private function ratingsGrid( int $user_id, int $activity_id ): string {
        wp_set_current_user( $user_id );
        $this->flush();
        $_GET = [ 'tt_view' => 'ratings-grid', 'activity_id' => (string) $activity_id ];
        ob_start();
        FrontendRatingsGridView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    /**
     * A head coach of the own team who also holds the club-wide read-only
     * observer seat, or, with `$observer = false`, only the coach.
     */
    private function observerCoach( bool $observer = true ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Gridscope', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $this->own_team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->own_team,
        ] );
        if ( $observer ) ( new \WP_User( $coach ) )->add_role( 'tt_readonly_observer' );
        $this->flush();

        $this->assertSame( $observer, AllTeamsScope::canSeeAllTeamsActivities( $coach ), 'precondition: only the seat reads every team' );
        $this->assertSame( [ $this->own_team ], ActivityAccess::writableTeamIds( $coach ), 'precondition: the save writes only their own team' );
        return $coach;
    }

    /**
     * A team manager (functional role) with one assignment per row of
     * `[ team_id, start_date, end_date ]`; an empty date is open-ended. The
     * team scope row stays open, as an assignment that ran out leaves it.
     *
     * @param list<array{0:int, 1:string, 2:string}> $assignments
     */
    private function manager( array $assignments ): int {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $uid  = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Gridscope', 'last_name' => 'Managerx',
            'role_type' => 'staff', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d", 'manager', $club
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id' => $club, 'role_key' => 'manager', 'label' => 'Manager',
                'description' => 'Created by GridWriteScopeTest.', 'is_system' => 1, 'sort_order' => 90,
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        foreach ( $assignments as [ $team_id, $start, $end ] ) {
            $row = [
                'club_id' => $club, 'team_id' => $team_id, 'person_id' => $person,
                'functional_role_id' => $role_id, 'role_in_team' => 'manager',
            ];
            if ( $start !== '' ) $row['start_date'] = $start;
            if ( $end !== '' )   $row['end_date']   = $end;
            $wpdb->insert( "{$p}tt_team_people", $row );
            $wpdb->insert( "{$p}tt_user_role_scopes", [
                'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
            ] );
        }
        $this->flush();
        return $uid;
    }

    private function player( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team_id, 'first_name' => 'Gridscope',
            'last_name' => 'Kid' . $team_id, 'status' => 'active',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }

    /** A completed match three days ago with the player on its recorded register. */
    private function match( int $team_id, int $player_id ): int {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => $club, 'team_id' => $team_id, 'title' => 'Gridscope match',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-3 days' ) ),
            'activity_type_key' => 'game', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            'opponent' => 'PSV', 'home_away' => 'home',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the match row was written' );
        $wpdb->insert( "{$p}tt_attendance", [
            'club_id' => $club, 'activity_id' => $id, 'player_id' => $player_id, 'is_guest' => 0,
            'record_type' => 'actual', 'status' => 'present', 'minutes_played' => 60,
        ] );
        return $id;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        ActivityGridLink::forget();
    }
}
