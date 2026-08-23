<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\MatchAnalysis\Alerts\MatchAnalysisMissingAlert;

/**
 * #2724 — "match played, no analysis".
 *
 * The negative cases carry the weight, as they do for every alert: one that
 * fires on the right rows AND some wrong ones gets muted, and the ones that
 * mattered go with it. Here the wrong rows are specific and known —
 * yesterday's match, a fortnight-old match, a tournament, and a match
 * nobody recorded attendance for.
 */
final class MatchAnalysisMissingAlertTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $coach;

    /** @var int */
    private $team;

    /** @var int */
    private $player;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p     = $wpdb->prefix;
        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 alerts' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Alert',
            'last_name'  => 'Fixture',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    // ---- fixtures ---------------------------------------------------------

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function match( int $days_ago, string $type = 'game', bool $with_attendance = true ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $this->team,
            'title'             => 'Ajax U15',
            'session_date'      => $this->daysAgo( $days_ago ),
            'activity_type_key' => $type,
            'plan_state'        => 'completed',
            'coach_id'          => $this->coach,
        ] );
        $activity_id = (int) $wpdb->insert_id;

        if ( $with_attendance ) {
            $wpdb->insert( "{$this->p}tt_attendance", [
                'club_id'     => $this->club,
                'activity_id' => $activity_id,
                'player_id'   => $this->player,
                'status'      => 'present',
                'record_type' => 'actual',
                'is_guest'    => 0,
            ] );
        }

        return $activity_id;
    }

    private function writeAnalysis( int $activity_id ): void {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_match_analyses", [
            'uuid'        => wp_generate_uuid4(),
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'summary'     => 'Written up.',
            'status'      => 'final',
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ] );
    }

    /** @return list<\TT\Modules\Alerts\Domain\AlertOccurrence> */
    private function evaluate(): array {
        return ( new MatchAnalysisMissingAlert() )->evaluate( new AlertContext( $this->club ) );
    }

    // ---- fires ------------------------------------------------------------

    public function test_a_match_played_five_days_ago_with_no_analysis_alerts_its_coach(): void {
        $this->match( 5 );

        $out = $this->evaluate();

        $this->assertCount( 1, $out );
        $this->assertSame( $this->coach, $out[0]->recipientUserId );
        $this->assertSame( 'activity', $out[0]->subjectType );
        $this->assertNotSame( '', $out[0]->title() );
    }

    /**
     * A prompt, not a problem with the data — so the badge, and never the
     * banner that interrupts what the coach came to do.
     */
    public function test_it_is_a_quiet_badge_level_nudge(): void {
        $alert = new MatchAnalysisMissingAlert();

        $this->assertSame( [ Surface::BADGE ], $alert->defaultSurfaces() );
        $this->assertSame( Severity::INFO, $alert->defaultSeverity() );
    }

    /**
     * The fix is on the analysis screen, so that is where the alert points.
     */
    public function test_it_links_to_the_analysis_not_the_activity(): void {
        $activity_id = $this->match( 5 );

        $out = $this->evaluate();

        $this->assertStringContainsString( 'tt_view=match-analysis', $out[0]->payload['url'] ?? '' );
        $this->assertStringContainsString( 'activity_id=' . $activity_id, $out[0]->payload['url'] ?? '' );
    }

    // ---- stays quiet ------------------------------------------------------

    public function test_yesterdays_match_is_left_alone(): void {
        $this->match( 1 );

        $this->assertSame( [], $this->evaluate(), 'nagging at the whistle teaches people to ignore the badge' );
    }

    public function test_a_match_older_than_the_window_stops_alerting(): void {
        $this->match( 20 );

        $this->assertSame( [], $this->evaluate(), 'after a fortnight the memory is gone and the nudge is only guilt' );
    }

    public function test_a_match_with_an_analysis_produces_nothing(): void {
        $activity_id = $this->match( 5 );
        $this->writeAnalysis( $activity_id );

        $this->assertSame( [], $this->evaluate(), 'writing the analysis is what resolves it — there is nothing to dismiss' );
    }

    public function test_a_tournament_is_not_asked_for_an_analysis_it_cannot_have(): void {
        $this->match( 5, 'tournament' );

        $this->assertSame( [], $this->evaluate() );
    }

    public function test_a_training_is_not_asked_for_a_match_analysis(): void {
        $this->match( 5, 'training' );

        $this->assertSame( [], $this->evaluate() );
    }

    /**
     * That academy has a bigger gap and is already getting the attendance
     * alert; two nudges about one match is how an inbox becomes noise.
     */
    public function test_a_match_with_no_attendance_recorded_is_left_to_the_attendance_alert(): void {
        $this->match( 5, 'game', false );

        $this->assertSame( [], $this->evaluate() );
    }

    /**
     * Re-running the sweep over unchanged data must return the same set —
     * the evaluator reconciles against it, and a definition that answered
     * differently on the second run would resolve and re-create the same
     * occurrence every hour.
     */
    public function test_evaluating_twice_returns_the_same_set(): void {
        $this->match( 5 );

        $first  = $this->evaluate();
        $second = $this->evaluate();

        $this->assertCount( 1, $first );
        $this->assertCount( 1, $second );
        $this->assertSame( $first[0]->subjectId, $second[0]->subjectId );
    }
}
