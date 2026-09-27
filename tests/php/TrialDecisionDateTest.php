<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\TrialCaseGenerator;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Rest\TrialsRestController;

/**
 * #4110 — a trial decision is dated when it was made.
 *
 * `recordDecision()` always stamped now, so the seeded demo trials, which
 * ended months before the demo was built, put `trial_ended` and `signed` on
 * the day of generation: after the player had already joined the roster.
 * The method takes an optional decision date now; only the demo generator
 * passes one. Live decisions, and anything sent over REST, stay dated now.
 */
final class TrialDecisionDateTest extends WP_UnitTestCase {

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_without_a_date_the_decision_is_dated_now(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        $before = time();
        $this->assertTrue( ( new TrialCasesRepository() )->recordDecision( $case_id, TrialCaseDecision::ADMIT, $this->user_id, 'Offered a place.' ) );

        $stamped = strtotime( (string) $this->caseColumn( $case_id, 'decision_made_at' ) . ' UTC' );
        $this->assertGreaterThanOrEqual( $before - 5, $stamped );
        $this->assertLessThanOrEqual( time() + 5, $stamped );
    }

    public function test_with_a_date_the_decision_and_its_journey_events_carry_it(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        $this->assertTrue( ( new TrialCasesRepository() )->recordDecision(
            $case_id, TrialCaseDecision::ADMIT, $this->user_id, 'Offered a place.', null, null, [], '2025-03-14 00:00:00'
        ) );

        $this->assertSame( '2025-03-14 00:00:00', (string) $this->caseColumn( $case_id, 'decision_made_at' ) );
        $this->assertSame( '2025-03-14', substr( $this->eventDate( $player_id, JourneyEventType::TRIAL_ENDED ), 0, 10 ) );
        $this->assertSame( '2025-03-14', substr( $this->eventDate( $player_id, JourneyEventType::SIGNED ), 0, 10 ) );
    }

    public function test_an_unreadable_date_falls_back_to_now(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new TrialCasesRepository() )->recordDecision(
            $case_id, TrialCaseDecision::ADMIT, $this->user_id, 'Offered a place.', null, null, [], 'not a date'
        );

        $stamped = strtotime( (string) $this->caseColumn( $case_id, 'decision_made_at' ) . ' UTC' );
        $this->assertGreaterThanOrEqual( time() - 60, $stamped );
    }

    /** The REST route takes no decision date: one sent in the body is refused and nothing is recorded. */
    public function test_rest_does_not_accept_a_decision_date(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        $response = TrialsRestController::record_decision( $this->decisionRequest( $case_id, [ 'decided_at' => '2020-01-01 00:00:00' ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( '', (string) $this->caseColumn( $case_id, 'decision' ) );
    }

    public function test_rest_decision_is_dated_now(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        $response = TrialsRestController::record_decision( $this->decisionRequest( $case_id, [] ) );

        $this->assertSame( 200, $response->get_status() );
        $stamped = strtotime( (string) $this->caseColumn( $case_id, 'decision_made_at' ) . ' UTC' );
        $this->assertGreaterThanOrEqual( time() - 60, $stamped );
    }

    /** Every seeded historical trial reads trial ended → signed → joined, in that order. */
    public function test_seeded_trials_are_dated_on_their_end_date(): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'demo-' . uniqid(), 'name' => 'Standard' ] );
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Demo U13', 'age_group' => 'U13' ] );
        $team_id = (int) $wpdb->insert_id;

        $roster = [];
        for ( $i = 1; $i <= 6; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $club, 'team_id' => $team_id, 'first_name' => 'Vaste', 'last_name' => "Speler {$i}",
                'status' => 'active', 'jersey_number' => $i, 'date_joined' => '2024-01-03',
            ] );
            $roster[] = (object) [ 'id' => (int) $wpdb->insert_id, 'team_id' => $team_id, 'date_joined' => '2024-01-03' ];
        }

        ( new TrialCaseGenerator( new DemoBatchRegistry( 'test-4110' ), $roster, [ (object) [ 'id' => $team_id, 'age_group' => 'U13' ] ], [ 'hjo' => $this->user_id ], 'en_US' ) )->generate();

        $cases = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player_id, end_date, decision_made_at FROM {$p}tt_trial_cases
              WHERE club_id = %d AND decision = %s",
            $club, TrialCaseDecision::ADMIT
        ) );
        $this->assertNotEmpty( $cases, 'the generator decided no trial' );

        foreach ( (array) $cases as $case ) {
            $row = (array) $case;
            $end = (string) $row['end_date'];
            $this->assertSame( $end, substr( (string) $row['decision_made_at'], 0, 10 ), 'decision not dated on the trial end date' );

            $pid = (int) $row['player_id'];
            foreach ( [ JourneyEventType::TRIAL_ENDED, JourneyEventType::SIGNED ] as $type ) {
                $date = substr( $this->eventDate( $pid, $type ), 0, 10 );
                $this->assertSame( $end, $date, $type . ' not dated on the trial end date' );
                $this->assertLessThan( '2024-01-03', $date, $type . ' dated after the player joined' );
            }
        }
    }

    /** @param array<string,mixed> $extra */
    private function decisionRequest( int $case_id, array $extra ): \WP_REST_Request {
        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $case_id . '/decision' );
        $request->set_param( 'id', $case_id );
        $request->set_body( (string) wp_json_encode( [
            'decision' => TrialCaseDecision::ADMIT,
            'notes'    => 'Thirty characters of justification, at least.',
        ] + $extra ) );
        $request->set_header( 'content-type', 'application/json' );
        return $request;
    }

    private function makeCase( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_trial_cases', [
            'club_id'    => (int) CurrentClub::id(),
            'player_id'  => $player_id,
            'track_id'   => 0,
            'start_date' => '2025-02-01',
            'end_date'   => '2025-03-14',
            'status'     => TrialCasesRepository::STATUS_OPEN,
            'uuid'       => wp_generate_uuid4(),
            'created_by' => $this->user_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeTrialPlayer(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Trial',
            'last_name'     => 'Dated',
            'date_of_birth' => '2012-01-01',
            'status'        => PlayerStatus::TRIAL,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return mixed */
    private function caseColumn( int $case_id, string $column ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d", $case_id ), ARRAY_A );
        return is_array( $row ) ? ( $row[ $column ] ?? null ) : null;
    }

    private function eventDate( int $player_id, string $type ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT event_date FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s ORDER BY id DESC LIMIT 1",
            $player_id, $type
        ) );
    }
}
