<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;

/**
 * #5095 — the goals-and-assists drill-down, the minutes audit and the
 * activities list's played drill count the matches the monthly report and
 * the Statistics tab count (`PlayedMatch`).
 *
 * One team, one window: a won fixture, a played fixture without a score, a
 * tournament day, a fixture dated after today marked completed and a
 * cancelled fixture. Every match carries a goal by the same player, so a
 * reader that took in a match not played would list it.
 */
final class PlayedMatchReadersTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private int $admin  = 0;
    private string $from = '';
    private string $to   = '';

    /** @var list<int> the played minutes-bearing matches */
    private array $played = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->hide_errors();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Readers U16', 'age_group' => 'U16' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Reader', 'last_name' => 'Scorer', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $today      = (string) $wpdb->get_var( 'SELECT CURDATE()' );
        $this->from = gmdate( 'Y-m-d', (int) strtotime( $today . ' -30 days' ) );
        $this->to   = gmdate( 'Y-m-d', (int) strtotime( $today . ' +30 days' ) );
        $day        = static fn( int $offset ): string => gmdate( 'Y-m-d', (int) strtotime( $today . ' ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) );

        $this->played = [
            $this->match( $day( -10 ), 'game', [ 'activity_status_key' => 'completed', 'home_score' => 2, 'away_score' => 0 ] ),
            $this->match( $day( -6 ), 'game', [] ),                                          // played, no score
            $this->match( $day( -3 ), 'tournament', [ 'activity_status_key' => 'completed' ] ),
        ];
        $this->match( $day( 7 ), 'game', [ 'activity_status_key' => 'completed' ] );         // dated after today
        $this->match( $day( -8 ), 'game', [ 'activity_status_key' => 'cancelled' ] );
        sort( $this->played );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_reader_counts_the_played_matches(): void {
        $tab     = ( new TeamMatchStatsQuery() )->forTeam( $this->team, [ 'from' => $this->from, 'to' => $this->to ] )['record'];
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team, $this->from, $this->to, [ 'minutes' ], $this->admin )['data']['minutes'];
        $played  = ( new MinutesQuery() )->matchCountsForTeam( $this->team, $this->from, $this->to )['played'];

        // Precondition: the record's two fixtures and the tournament day.
        $this->assertSame( 2, $tab['played'] );
        $this->assertSame( 3, $played );
        $this->assertSame( $tab['played'] + $tab['tournaments_excluded'], $played );
        $this->assertSame( $played, $monthly['matches_played'] );

        $drill = ( new GoalContributionQuery() )->eventsFor( $this->team, $this->player, [ 'from' => $this->from, 'to' => $this->to ] );
        $this->assertSame( $this->played, $this->sorted( array_column( $drill['matches'], 'activity_id' ) ), 'the goals drill-down' );

        $totals = ( new GoalContributionQuery() )->forTeam( $this->team, [ 'from' => $this->from, 'to' => $this->to ] );
        $this->assertSame( $played, (int) ( $totals[ $this->player ]['goals'] ?? 0 ), 'one goal a played match, none on the others' );

        $audit = ( new MinutesAuditQuery() )->matrix( $this->team, $this->from, $this->to );
        $this->assertSame( $this->played, $this->sorted( array_column( $audit['games'], 'activity_id' ) ), 'the minutes audit' );

        $list = ( new ActivitiesRepository() )->listForManageSurface( $this->team, '', $this->admin, $this->from, $this->to, false, 'active', 0, true );
        $this->assertSame( $this->played, $this->sorted( array_map( static fn( $r ): int => (int) $r->id, $list ) ), 'the activities list opened from "played"' );
    }

    /**
     * @param array<int|string,mixed> $ids
     * @return list<int>
     */
    private function sorted( array $ids ): array {
        $ids = array_map( 'intval', array_values( $ids ) );
        sort( $ids );
        return $ids;
    }

    /** @param array<string,mixed> $columns */
    private function match( string $date, string $type, array $columns ): int {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_activities", $columns + [
            'club_id' => $club, 'team_id' => $this->team, 'title' => 'Readers ' . $date, 'session_date' => $date,
            'activity_type_key' => $type, 'game_subtype_key' => 'League', 'activity_status_key' => 'planned',
            'plan_state' => ( $columns['activity_status_key'] ?? '' ) === 'cancelled' ? 'cancelled' : 'scheduled',
            'opponent' => 'VVV', 'home_away' => 'home',
        ] );
        $id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_match_execution_goal_events", [
            'event_uuid' => wp_generate_uuid4(), 'club_id' => $club, 'execution_id' => 0,
            'activity_id' => $id, 'player_id' => $this->player, 'assist_player_id' => 0,
            'half' => 1, 'minute_in_half' => 10, 'team' => 'home', 'is_own_goal' => 0,
        ] );
        return $id;
    }
}
