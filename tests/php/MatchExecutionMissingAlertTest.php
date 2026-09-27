<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Definitions\MatchExecutionMissingAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * #4060 — a completed game with minutes but no match-execution record.
 *
 * The minutes report keeps showing 0 substitute appearances and substitutions
 * for such a game rather than inventing figures; this alert makes the missing
 * record visible. The negative cases carry most of the weight, as they do
 * for the other activity alerts: an alert that also fires on games that DO
 * have their record gets muted, and the real ones go with it.
 */
final class MatchExecutionMissingAlertTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 1;
    private int $coach = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p     = $wpdb->prefix;
        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    public function test_it_is_registered(): void {
        AlertRegistry::flush();
        $this->assertInstanceOf( MatchExecutionMissingAlert::class, AlertRegistry::find( 'activities.match_execution_missing' ) );
    }

    public function test_completed_game_with_minutes_and_no_record_alerts_its_coach(): void {
        $team = $this->insertTeam();
        $game = $this->insertGame( $team, $this->daysAgo( 5 ) );
        $this->insertMinutes( $game, $this->insertPlayer( $team ), 70 );

        $out = ( new MatchExecutionMissingAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->coach, $out[0]->recipientUserId );
        $this->assertSame( $game, $out[0]->subjectId );
        $this->assertSame( Severity::ATTENTION, $out[0]->severity );
    }

    public function test_a_game_with_its_execution_record_produces_nothing(): void {
        $team = $this->insertTeam();
        $game = $this->insertGame( $team, $this->daysAgo( 5 ) );
        $this->insertMinutes( $game, $this->insertPlayer( $team ), 70 );
        $this->insertExecution( $game );

        $this->assertSame( [], ( new MatchExecutionMissingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_a_game_inside_the_grace_period_produces_nothing(): void {
        $team = $this->insertTeam();
        $game = $this->insertGame( $team, current_time( 'Y-m-d' ) );
        $this->insertMinutes( $game, $this->insertPlayer( $team ), 70 );

        $this->assertSame( [], ( new MatchExecutionMissingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /** No minutes at all is the attendance alert's subject, not this one's. */
    public function test_a_game_without_minutes_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->insertGame( $team, $this->daysAgo( 5 ) );

        $this->assertSame( [], ( new MatchExecutionMissingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_a_game_not_yet_completed_produces_nothing(): void {
        $team = $this->insertTeam();
        $game = $this->insertGame( $team, $this->daysAgo( 5 ), 'planned' );
        $this->insertMinutes( $game, $this->insertPlayer( $team ), 70 );

        $this->assertSame( [], ( new MatchExecutionMissingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_a_training_produces_nothing(): void {
        $team = $this->insertTeam();
        $game = $this->insertGame( $team, $this->daysAgo( 5 ), 'completed', 'training' );
        $this->insertMinutes( $game, $this->insertPlayer( $team ), 70 );

        $this->assertSame( [], ( new MatchExecutionMissingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /** A tournament fixture keeps its minutes in the planner, not in match execution. */
    public function test_a_tournament_fixture_produces_nothing(): void {
        global $wpdb;
        $team = $this->insertTeam();
        $game = $this->insertGame( $team, $this->daysAgo( 5 ) );
        $this->insertMinutes( $game, $this->insertPlayer( $team ), 25 );
        $wpdb->insert( "{$this->p}tt_tournament_matches", [
            'club_id'              => $this->club,
            'tournament_id'        => 1,
            'sequence'             => 1,
            'label'                => 'Fixture 1',
            'duration_min'         => 25,
            'substitution_windows' => '[]',
            'activity_id'          => $game,
        ] );

        $this->assertSame( [], ( new MatchExecutionMissingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_severity_ages_up_after_a_fortnight(): void {
        $team = $this->insertTeam();
        $game = $this->insertGame( $team, $this->daysAgo( 20 ) );
        $this->insertMinutes( $game, $this->insertPlayer( $team ), 70 );

        $out = ( new MatchExecutionMissingAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::URGENT, $out[0]->severity );
    }

    public function test_creating_the_execution_record_resolves_an_open_occurrence(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$this->p}tt_alert_occurrences" );

        $user = new \WP_User( $this->coach );
        $user->add_cap( 'tt_edit_activities' );
        clean_user_cache( $this->coach );

        $team = $this->insertTeam();
        $game = $this->insertGame( $team, $this->daysAgo( 5 ) );
        $this->insertMinutes( $game, $this->insertPlayer( $team ), 70 );

        $alert = new MatchExecutionMissingAlert();
        $ev    = new AlertEvaluator();
        $repo  = new AlertOccurrencesRepository();

        $ev->run( $alert, new AlertContext( $this->club ) );
        $this->assertSame( 1, $repo->openCountForUser( $this->coach ), 'the game without a record raises it' );

        $this->insertExecution( $game );
        $stat = $ev->run( $alert, new AlertContext( $this->club ) );

        $this->assertSame( 1, $stat['resolved'] );
        $this->assertSame( 0, $repo->openCountForUser( $this->coach ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U14 record' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Record',
            'last_name'  => 'Fixture',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertGame( int $team_id, string $date, string $status = 'completed', string $type = 'game' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Game ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => $type,
            'plan_state'          => $status,
            'activity_status_key' => $status,
            'coach_id'            => $this->coach,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMinutes( int $activity_id, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
    }

    private function insertExecution( int $activity_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_execution", [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => $this->club,
            'activity_id'   => $activity_id,
            'match_prep_id' => 0,
            'state'         => 'finalized',
        ] );
    }
}
