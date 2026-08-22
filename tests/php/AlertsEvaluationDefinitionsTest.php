<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\Definitions\EvaluationNotSharedAlert;
use TT\Modules\Alerts\Definitions\EvaluationWindowClosingAlert;
use TT\Modules\Alerts\Definitions\PlayerNotEvaluatedAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Analytics\EvalWindowsRepository;

/**
 * #2636 instalment 1 — the three Evaluations definitions.
 *
 * The negative cases carry most of the weight, exactly as they do in
 * `AlertsActivityDefinitionsTest`. A definition that fires on the right
 * rows but ALSO on some wrong ones is worse than one that never fires: an
 * alert nobody believes gets muted, and the ones that matter go with it.
 *
 * Three wrongnesses are specifically pinned here because each of them would
 * be a first-week complaint: the trialist who arrived on Tuesday being
 * "overdue an evaluation", a closed window still nagging about a gap nobody
 * can fill any more, and the whole evaluation archive lighting up the day
 * the feedback alert ships.
 */
final class AlertsEvaluationDefinitionsTest extends WP_UnitTestCase {

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

        // Windows are club config, and a leftover set from another test
        // would make the window definition fire on rows this one never
        // seeded. Start every test from "no windows configured".
        ( new EvalWindowsRepository() )->save( [] );
    }

    // -- evaluations.player_not_evaluated --------------------------------

    public function test_long_unevaluated_player_alerts_the_team_head_coach(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );

        $out = ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->head, $out[0]->recipientUserId );
        $this->assertSame( 'player', $out[0]->subjectType );
        $this->assertSame( $player, $out[0]->subjectId );
        $this->assertSame( $player, $out[0]->playerId );
        $this->assertNotSame( '', $out[0]->title() );
    }

    public function test_recently_evaluated_player_produces_nothing(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 3 ), 'Well done' );

        $this->assertSame( [], ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * The trialist who arrived on Tuesday is not overdue an evaluation.
     * Firing on them is how a coach learns to ignore the whole feature.
     */
    public function test_player_who_just_joined_produces_nothing(): void {
        $team = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->daysAgo( 3 ) );

        $this->assertSame( [], ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_player_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $wpdb->update( "{$this->p}tt_players", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $player ] );

        $this->assertSame( [], ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_trashed_player_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $wpdb->update( "{$this->p}tt_players", [ 'trashed_at' => current_time( 'mysql' ) ], [ 'id' => $player ] );

        $this->assertSame( [], ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_released_player_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $wpdb->update( "{$this->p}tt_players", [ 'status' => 'released' ], [ 'id' => $player ] );

        $this->assertSame( [], ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * No team means no head coach means nobody to tell. Those players are
     * the subject of `dataquality.player_without_team`, not this one.
     */
    public function test_player_without_a_team_produces_nothing(): void {
        $this->insertPlayer( 0, $this->daysAgo( 300 ) );

        $this->assertSame( [], ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_threshold_comes_from_config_not_from_code(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 21 ), 'Well done' );

        // Three weeks is inside the eight-week default.
        $this->assertSame( [], ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( PlayerNotEvaluatedAlert::CONFIG_KEY_WEEKS, '2' );

        $this->assertCount( 1, ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_severity_ages_up_at_twice_the_threshold(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 70 ), 'Well done' );

        $fresh = ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::ATTENTION, $fresh[0]->severity );

        global $wpdb;
        $wpdb->query( "DELETE FROM {$this->p}tt_evaluations" );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 200 ), 'Well done' );

        $stale = ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::URGENT, $stale[0]->severity );
    }

    // -- evaluations.window_closing --------------------------------------

    public function test_no_configured_windows_produces_nothing(): void {
        $team = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->daysAgo( 300 ) );

        $this->assertSame( [], ( new EvaluationWindowClosingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_uncovered_player_alerts_when_the_window_is_about_to_close(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->saveWindow( 'Autumn review', $this->daysAgo( 30 ), $this->daysAhead( 2 ) );

        $out = ( new EvaluationWindowClosingAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->head, $out[0]->recipientUserId );
        $this->assertSame( $player, $out[0]->playerId );
        $this->assertStringContainsString( 'Autumn review', $out[0]->title() );
    }

    public function test_player_covered_inside_the_window_produces_nothing(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->saveWindow( 'Autumn review', $this->daysAgo( 30 ), $this->daysAhead( 2 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 5 ), 'Well done' );

        $this->assertSame( [], ( new EvaluationWindowClosingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * An evaluation recorded before the window opened does not cover it.
     * Getting this wrong would silently under-report every gap.
     */
    public function test_evaluation_before_the_window_does_not_cover_it(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->saveWindow( 'Autumn review', $this->daysAgo( 30 ), $this->daysAhead( 2 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 60 ), 'Well done' );

        $this->assertCount( 1, ( new EvaluationWindowClosingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_window_beyond_the_lead_time_produces_nothing(): void {
        $team = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->saveWindow( 'Autumn review', $this->daysAgo( 30 ), $this->daysAhead( 40 ) );

        $this->assertSame( [], ( new EvaluationWindowClosingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * A window that has already closed is not closing. Nothing can be done
     * about the gap any more, and an alert nobody can act on is noise.
     */
    public function test_window_that_already_closed_produces_nothing(): void {
        $team = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->saveWindow( 'Autumn review', $this->daysAgo( 30 ), $this->daysAgo( 1 ) );

        $this->assertSame( [], ( new EvaluationWindowClosingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_lead_time_comes_from_config_not_from_code(): void {
        $team = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->saveWindow( 'Autumn review', $this->daysAgo( 30 ), $this->daysAhead( 10 ) );

        $this->assertSame( [], ( new EvaluationWindowClosingAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( EvaluationWindowClosingAlert::CONFIG_KEY_LEAD_DAYS, '14' );

        $this->assertCount( 1, ( new EvaluationWindowClosingAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // -- evaluations.saved_not_shared ------------------------------------

    public function test_evaluation_without_player_feedback_alerts_author_and_head_coach(): void {
        $author = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $eval   = $this->insertEvaluation( $player, $author, $this->daysAgo( 14 ), '' );

        $out = ( new EvaluationNotSharedAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 2, $out );
        $recipients = array_map( static fn( $o ) => $o->recipientUserId, $out );
        sort( $recipients );
        $expected = [ $author, $this->head ];
        sort( $expected );
        $this->assertSame( $expected, $recipients );

        $this->assertSame( 'evaluation', $out[0]->subjectType );
        $this->assertSame( $eval, $out[0]->subjectId );
        $this->assertSame( $player, $out[0]->playerId );
    }

    public function test_evaluation_with_player_feedback_produces_nothing(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 14 ), 'Keep working on your first touch.' );

        $this->assertSame( [], ( new EvaluationNotSharedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_evaluation_inside_the_grace_period_produces_nothing(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 2 ), '' );

        $this->assertSame( [], ( new EvaluationNotSharedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * The reason the lookback exists: without it the first sweep after this
     * ships would return every feedback-less evaluation the academy ever
     * recorded, blow the evaluator's occurrence ceiling and take the rest of
     * the definition's results down with it.
     */
    public function test_evaluation_older_than_the_lookback_produces_nothing(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 800 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 400 ), '' );

        $this->assertSame( [], ( new EvaluationNotSharedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_evaluation_of_an_archived_player_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 14 ), '' );
        $wpdb->update( "{$this->p}tt_players", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $player ] );

        $this->assertSame( [], ( new EvaluationNotSharedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_evaluation_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $eval   = $this->insertEvaluation( $player, $this->head, $this->daysAgo( 14 ), '' );
        $wpdb->update( "{$this->p}tt_evaluations", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $eval ] );

        $this->assertSame( [], ( new EvaluationNotSharedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_scope_narrows_the_evaluation_query(): void {
        $team   = $this->insertTeam( 'U15 alerts' );
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->daysAgo( 300 ) );
        $first  = $this->insertEvaluation( $player, $this->head, $this->daysAgo( 14 ), '' );
        $this->insertEvaluation( $player, $this->head, $this->daysAgo( 20 ), '' );

        $all = ( new EvaluationNotSharedAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertCount( 2, $all );

        $narrowed = ( new EvaluationNotSharedAlert() )->evaluate(
            new AlertContext( $this->club, 'evaluation', [ $first ] )
        );
        $this->assertCount( 1, $narrowed );
        $this->assertSame( $first, $narrowed[0]->subjectId );
    }

    // -- fixtures --------------------------------------------------------

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function daysAhead( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) + $n * DAY_IN_SECONDS );
    }

    private function saveWindow( string $name, string $start, string $end ): void {
        ( new EvalWindowsRepository() )->save( [
            [ 'name' => $name, 'start' => $start, 'end' => $end ],
        ] );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * `date_joined` is load-bearing: it is what the staleness of a
     * never-evaluated player is measured from, so every fixture states it
     * rather than letting `created_at` (always "now" in a test) decide.
     */
    private function insertPlayer( int $team_id, string $date_joined ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $team_id,
            'first_name'  => 'Alert',
            'last_name'   => 'Fixture',
            'status'      => 'active',
            'date_joined' => $date_joined,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertEvaluation( int $player_id, int $coach_id, string $date, string $feedback ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'         => $this->club,
            'player_id'       => $player_id,
            'coach_id'        => $coach_id,
            'eval_date'       => $date,
            'player_feedback' => $feedback,
        ] );
        return (int) $wpdb->insert_id;
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
