<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\Definitions\GoalPastTargetDateAlert;
use TT\Modules\Alerts\Definitions\PdpNoConversationAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * #2636 instalment 2 — the Goals and PDP definitions.
 *
 * The negative cases carry most of the weight. Two of them are the ones
 * that would make this pair untrustworthy on day one: a goal somebody
 * already completed still being called overdue, and a PDP cycle counting
 * its *scheduled* conversations as conversations that happened — which
 * would make the alert unreachable, because a cycle is created with all of
 * its conversation rows already written.
 */
final class AlertsGoalPdpDefinitionsTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $head;

    /** @var ConfigService */
    private $config;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p      = $wpdb->prefix;
        $this->head   = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->config = new ConfigService();

        // A season seeded by another test or by the migration run would make
        // the PDP definition fire on files this test never created. DELETE,
        // not TRUNCATE: TRUNCATE forces an implicit commit and would break
        // the transaction WP_UnitTestCase rolls back between tests.
        $wpdb->query( "DELETE FROM {$this->p}tt_seasons" );
    }

    // -- goals.past_target_date ------------------------------------------

    public function test_overdue_open_goal_alerts_its_author_and_the_head_coach(): void {
        $author = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );
        $goal   = $this->insertGoal( $player, $author, $this->daysAgo( 10 ), 'in_progress' );

        $out = ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 2, $out );
        $recipients = array_map( static fn( $o ) => $o->recipientUserId, $out );
        sort( $recipients );
        $expected = [ $author, $this->head ];
        sort( $expected );
        $this->assertSame( $expected, $recipients );

        $this->assertSame( 'goal', $out[0]->subjectType );
        $this->assertSame( $goal, $out[0]->subjectId );
        $this->assertSame( $player, $out[0]->playerId );
        $this->assertNotSame( '', $out[0]->title() );
    }

    public function test_completed_goal_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 10 ), 'completed' );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_cancelled_goal_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 10 ), 'cancelled' );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * `status` has picked up spellings over the years — `pending_approval`
     * from #0058, display-cased strings from older imports. The predicate
     * is "not closed", never an allowlist of open values, so an unfamiliar
     * status is still treated as open rather than silently ignored.
     */
    public function test_unfamiliar_open_status_still_counts_as_open(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 10 ), 'In Progress' );

        $this->assertCount( 1, ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_goal_still_in_the_future_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAhead( 10 ), 'in_progress' );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * A goal reviewed on Monday for a Sunday deadline is normal practice.
     * An alert on Sunday evening would be wrong more often than right.
     */
    public function test_goal_inside_the_grace_period_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 1 ), 'in_progress' );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_goal_beyond_the_lookback_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 500 ), 'in_progress' );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_goal_without_a_target_date_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player,
            'title'      => 'Undated goal',
            'status'     => 'in_progress',
            'created_by' => $this->head,
        ] );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_goal_produces_nothing(): void {
        global $wpdb;
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $goal = $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 10 ), 'in_progress' );
        $wpdb->update( "{$this->p}tt_goals", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $goal ] );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_trashed_goal_produces_nothing(): void {
        global $wpdb;
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $goal = $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 10 ), 'in_progress' );
        $wpdb->update( "{$this->p}tt_goals", [ 'trashed_at' => current_time( 'mysql' ) ], [ 'id' => $goal ] );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_goal_of_an_archived_player_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );
        $this->insertGoal( $player, $this->head, $this->daysAgo( 10 ), 'in_progress' );
        $wpdb->update( "{$this->p}tt_players", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $player ] );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_goal_grace_period_comes_from_config_not_from_code(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 1 ), 'in_progress' );

        $this->assertSame( [], ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( GoalPastTargetDateAlert::CONFIG_KEY_GRACE_DAYS, '1' );

        $this->assertCount( 1, ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_goal_severity_ages_up_after_a_month(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertGoal( $this->insertPlayer( $team ), $this->head, $this->daysAgo( 40 ), 'in_progress' );

        $out = ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::URGENT, $out[0]->severity );
    }

    // -- pdp.no_conversation_this_cycle ----------------------------------

    public function test_open_cycle_with_no_held_conversation_alerts_owner_and_head_coach(): void {
        $owner  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $season = $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );
        $file   = $this->insertPdpFile( $player, $season, $owner, 'open', $this->daysAgo( 60 ) );
        // Scheduled but never held — the cycle's conversations are written
        // up front, so this must not count.
        $this->insertConversation( $file, 1, $this->daysAhead( 5 ), null );

        $out = ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 2, $out );
        $recipients = array_map( static fn( $o ) => $o->recipientUserId, $out );
        sort( $recipients );
        $expected = [ $owner, $this->head ];
        sort( $expected );
        $this->assertSame( $expected, $recipients );

        $this->assertSame( 'pdp_file', $out[0]->subjectType );
        $this->assertSame( $file, $out[0]->subjectId );
        $this->assertSame( $player, $out[0]->playerId );
    }

    public function test_cycle_with_a_held_conversation_produces_nothing(): void {
        $season = $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $file   = $this->insertPdpFile( $this->insertPlayer( $team ), $season, $this->head, 'open', $this->daysAgo( 60 ) );
        $this->insertConversation( $file, 1, $this->daysAgo( 10 ), $this->daysAgo( 10 ) );

        $this->assertSame( [], ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_cycle_opened_recently_produces_nothing(): void {
        $season = $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPdpFile( $this->insertPlayer( $team ), $season, $this->head, 'open', $this->daysAgo( 5 ) );

        $this->assertSame( [], ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_completed_cycle_produces_nothing(): void {
        $season = $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPdpFile( $this->insertPlayer( $team ), $season, $this->head, 'completed', $this->daysAgo( 60 ) );

        $this->assertSame( [], ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_cycle_produces_nothing(): void {
        global $wpdb;
        $season = $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $file   = $this->insertPdpFile( $this->insertPlayer( $team ), $season, $this->head, 'open', $this->daysAgo( 60 ) );
        $wpdb->update( "{$this->p}tt_pdp_files", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $file ] );

        $this->assertSame( [], ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * Last season's untouched cycle is history, not a gap anyone can still
     * close. Alerting on it would be asking for a conversation that can no
     * longer happen.
     */
    public function test_cycle_from_a_past_season_produces_nothing(): void {
        $season = $this->insertSeason( 'Last season', $this->daysAgo( 700 ), $this->daysAgo( 400 ), 0 );
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPdpFile( $this->insertPlayer( $team ), $season, $this->head, 'open', $this->daysAgo( 600 ) );

        $this->assertSame( [], ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_pdp_threshold_comes_from_config_not_from_code(): void {
        $season = $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPdpFile( $this->insertPlayer( $team ), $season, $this->head, 'open', $this->daysAgo( 20 ) );

        $this->assertSame( [], ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( PdpNoConversationAlert::CONFIG_KEY_DAYS, '14' );

        $this->assertCount( 1, ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_pdp_severity_ages_up_at_twice_the_threshold(): void {
        $season = $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPdpFile( $this->insertPlayer( $team ), $season, $this->head, 'open', $this->daysAgo( 200 ) );

        $out = ( new PdpNoConversationAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::URGENT, $out[0]->severity );
    }

    // -- fixtures --------------------------------------------------------

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function daysAhead( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) + $n * DAY_IN_SECONDS );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U16 alerts' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $team_id,
            'first_name'  => 'Alert',
            'last_name'   => 'Fixture',
            'status'      => 'active',
            'date_joined' => $this->daysAgo( 400 ),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertGoal( int $player_id, int $created_by, string $due_date, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'title'      => 'Improve weak foot',
            'status'     => $status,
            'due_date'   => $due_date,
            'created_by' => $created_by,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertCurrentSeason(): int {
        return $this->insertSeason( 'This season', $this->daysAgo( 120 ), $this->daysAhead( 120 ), 1 );
    }

    private function insertSeason( string $name, string $start, string $end, int $is_current ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_seasons", [
            'club_id'    => $this->club,
            'name'       => $name,
            'start_date' => $start,
            'end_date'   => $end,
            'is_current' => $is_current,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPdpFile( int $player_id, int $season_id, int $owner, string $status, string $created_on ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_pdp_files", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'season_id'      => $season_id,
            'owner_coach_id' => $owner,
            'cycle_size'     => 3,
            'status'         => $status,
            'created_at'     => $created_on . ' 09:00:00',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertConversation( int $file_id, int $sequence, ?string $scheduled, ?string $conducted ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id'      => $this->club,
            'pdp_file_id'  => $file_id,
            'sequence'     => $sequence,
            'template_key' => 'start',
            'scheduled_at' => $scheduled === null ? null : $scheduled . ' 09:00:00',
            'conducted_at' => $conducted === null ? null : $conducted . ' 09:00:00',
        ] );
    }

    /**
     * Head-coach assignment through `tt_team_people`, the single source of
     * truth since #1315 retired `tt_teams.head_coach_id`.
     */
    private function assignHeadCoach( int $team_id, int $user_id ): void {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            'head_coach'
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => 'head_coach',
                'label'    => 'Head Coach',
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'wp_user_id' => $user_id,
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
        ] );
    }
}
