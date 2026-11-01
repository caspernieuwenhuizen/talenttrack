<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #4565 — six more `ActivitiesRepository` reads are club-scoped (CLAUDE.md
 * §4): each returns nothing for another club's row and finds it under its
 * own club. The club is switched through `tt_current_club_id`, as
 * `ActivityFindByIdClubScopeTest` does.
 */
final class ActivitiesRepositoryClubScopeTest extends WP_UnitTestCase {

    /** @var callable */
    private $club;

    private int $current = 1;

    public function set_up(): void {
        parent::set_up();
        $this->club = function () { return $this->current; };
        add_filter( 'tt_current_club_id', $this->club );
    }

    public function tear_down(): void {
        remove_filter( 'tt_current_club_id', $this->club );
        parent::tear_down();
    }

    public function test_the_tournament_match_count_counts_its_own_clubs_matches_only(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id' => 1, 'uuid' => wp_generate_uuid4(), 'name' => 'Cup', 'start_date' => '2026-10-31', 'team_id' => 1, 'created_by' => 1,
        ] );
        $tournament = (int) $wpdb->insert_id;
        foreach ( [ 1, 2 ] as $club ) {
            $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
                'tournament_id' => $tournament, 'club_id' => $club, 'sequence' => $club, 'substitution_windows' => '[]',
            ] );
        }

        $method = new \ReflectionMethod( ActivitiesRepository::class, 'hydrateTournament' );
        $method->setAccessible( true );
        $row = $method->invoke( null, $tournament, 1 );
        $this->assertNotNull( $row );
        $this->assertSame( 1, $row->match_count, 'a club-2 match row under the same tournament id is not counted' );
        $this->assertNull( $method->invoke( null, $tournament, 2 ), 'the tournament is not club 2\'s' );
    }

    public function test_the_roster_attendance_is_the_current_clubs(): void {
        [ $activity ] = $this->clubTwoActivityWithAttendance();
        $repo = new ActivitiesRepository();

        $this->current = 1;
        $this->assertSame( [], $repo->listRosterAttendance( $activity ) );
        $this->assertSame( [], $repo->listRosterAttendance( $activity, false, 'actual' ) );

        $this->current = 2;
        $this->assertCount( 1, $repo->listRosterAttendance( $activity ) );
        $this->assertCount( 1, $repo->listRosterAttendance( $activity, false, 'actual' ) );
    }

    public function test_a_players_activity_list_is_the_current_clubs(): void {
        [ $activity, $player ] = $this->clubTwoActivityWithAttendance();
        $repo = new ActivitiesRepository();

        $this->current = 1;
        $this->assertSame( [], $repo->listForPlayer( $player, 25 ) );

        $this->current = 2;
        $rows = $repo->listForPlayer( $player, 25 );
        $this->assertCount( 1, $rows );
        $this->assertSame( $activity, (int) $rows[0]->id );
    }

    public function test_a_players_recent_completed_activities_are_the_current_clubs(): void {
        [ $activity, $player ] = $this->clubTwoActivityWithAttendance();
        $repo = new ActivitiesRepository();

        $this->current = 1;
        $this->assertSame( [], $repo->listRecentCompletedForPlayer( $player, 10 ) );

        $this->current = 2;
        $rows = $repo->listRecentCompletedForPlayer( $player, 10 );
        $this->assertCount( 1, $rows );
        $this->assertSame( $activity, (int) $rows[0]->id );
    }

    public function test_the_attendance_map_is_the_current_clubs(): void {
        [ $activity, $player ] = $this->clubTwoActivityWithAttendance();
        $repo = new ActivitiesRepository();

        $this->current = 1;
        $this->assertSame( [], $repo->attendanceMapByPlayer( $activity ) );

        $this->current = 2;
        $this->assertArrayHasKey( $player, $repo->attendanceMapByPlayer( $activity ) );
    }

    public function test_the_linked_player_is_the_current_clubs(): void {
        global $wpdb;
        $user = self::factory()->user->create();
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 2, 'team_id' => 0, 'first_name' => 'Club', 'last_name' => 'Twee', 'status' => 'active', 'wp_user_id' => $user,
        ] );
        $player = (int) $wpdb->insert_id;
        $repo   = new ActivitiesRepository();

        $this->current = 1;
        $this->assertSame( 0, $repo->linkedPlayerIdForUser( $user ) );

        $this->current = 2;
        $this->assertSame( $player, $repo->linkedPlayerIdForUser( $user ) );
    }

    /**
     * A completed club-2 activity with one recorded attendance row for a
     * club-2 player.
     *
     * @return array{0:int, 1:int} activity id, player id
     */
    private function clubTwoActivityWithAttendance(): array {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 2, 'team_id' => 0, 'first_name' => 'Club', 'last_name' => 'Twee', 'status' => 'active',
        ] );
        $player = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 2, 'team_id' => 0, 'title' => 'Club 2 training', 'session_date' => '2026-10-20',
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $activity = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id' => 2, 'activity_id' => $activity, 'player_id' => $player, 'status' => 'Present', 'record_type' => 'actual',
        ] );
        return [ $activity, $player ];
    }
}
