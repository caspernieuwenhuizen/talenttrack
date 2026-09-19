<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoMode;
use TT\Modules\DemoData\Generators\TrialCaseGenerator;

/**
 * #3648 — a seeded trial assessment was submitted on a day that has been.
 *
 * The generator stamped every staff input with the case's end date. An open
 * case ends in the future, so the demo shipped assessments submitted next
 * week: the case read as already assessed, a panel member's own screen
 * showed a submission time that had not happened, and the trial-input
 * reminder never fired because it skips inputs that carry a `submitted_at`.
 */
final class DemoTrialInputSubmissionTest extends WP_UnitTestCase {

    /** @var object[] */
    private array $roster = [];

    /** @var object[] */
    private array $teams = [];

    private int $user = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'demo-' . uniqid(), 'name' => 'Standard' ] );

        foreach ( [ 'U11', 'U13' ] as $age ) {
            $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Demo ' . $age, 'age_group' => $age ] );
            $team_id       = (int) $wpdb->insert_id;
            $this->teams[] = (object) [ 'id' => $team_id, 'age_group' => $age ];

            for ( $i = 1; $i <= 6; $i++ ) {
                $wpdb->insert( "{$p}tt_players", [
                    'club_id' => $club, 'team_id' => $team_id, 'first_name' => 'Vaste', 'last_name' => "Speler {$i}",
                    'status' => 'active', 'jersey_number' => $i, 'date_joined' => '2024-01-03',
                ] );
                $this->roster[] = (object) [ 'id' => (int) $wpdb->insert_id, 'team_id' => $team_id, 'date_joined' => '2024-01-03' ];
            }
        }

        // A panel of four, so every case carries two or three assessments
        // and the generated population is large enough to assert on.
        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $users      = [ 'hjo' => $this->user ];
        foreach ( [ 'hjo2', 'scout', 'staff' ] as $slot ) {
            $users[ $slot ] = self::factory()->user->create( [ 'role' => 'administrator' ] );
        }

        wp_set_current_user( $this->user );
        ( new TrialCaseGenerator( new DemoBatchRegistry( 'test-batch-3648' ), $this->roster, $this->teams, $users, 'en_US' ) )->generate();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_no_seeded_assessment_was_submitted_in_the_future(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_trial_case_staff_inputs';

        $this->assertGreaterThan( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'the generator seeded no staff inputs' );
        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE submitted_at IS NOT NULL AND submitted_at > UTC_TIMESTAMP()" ),
            'a demo assessment was submitted in the future'
        );
    }

    /**
     * The invariant the end-date stamp broke: an assessment was written
     * during the trial and before now. A decided case keeps its end-date
     * stamp — its end date has been — and an open one, whose end date has
     * not, gets a recent time instead.
     */
    public function test_every_seeded_assessment_falls_inside_its_case_and_in_the_past(): void {
        $now  = gmdate( 'Y-m-d H:i:s' );
        $rows = $this->submittedInputs();
        $this->assertNotEmpty( $rows );

        foreach ( $rows as $row ) {
            $at = (string) $row['submitted_at'];
            $this->assertGreaterThanOrEqual( $row['start_date'] . ' 00:00:00', $at, 'submitted before the trial started' );
            $this->assertLessThanOrEqual( $now, $at, 'submitted in the future' );

            if ( (string) $row['decision'] !== '' ) {
                $this->assertLessThanOrEqual( $row['end_date'] . ' 23:59:59', $at, 'a decided case was assessed after it ended' );
            } else {
                // An open case ends in the future, so the only stamp that
                // can satisfy the assertion above is the recent one.
                $this->assertGreaterThanOrEqual(
                    gmdate( 'Y-m-d H:i:s', time() - ( 80 * HOUR_IN_SECONDS ) ),
                    $at,
                    'an open case was not assessed recently'
                );
            }
        }
    }

    /**
     * The reported symptom, through the route that showed it: a draft save
     * answers with a submission time that is absent or has been.
     */
    public function test_a_draft_save_on_an_open_case_never_answers_a_future_submission(): void {
        $case = $this->openCaseId();
        $this->assertGreaterThan( 0, $case );

        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $case . '/inputs' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'free_text_notes' => 'Kort genoteerd na de training.' ] ) );

        DemoMode::overrideForRequest( DemoMode::ON );
        try {
            $response = rest_do_request( $request );
        } finally {
            DemoMode::clearOverride();
        }

        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $this->assertSame( 200, (int) $response->get_status() );
        $this->assertIsArray( $data );
        $this->assertFalse( (bool) $data['data']['submitted'] );

        $at = $data['data']['input']['submitted_at'] ?? null;
        if ( $at !== null ) {
            $this->assertLessThanOrEqual( gmdate( 'Y-m-d H:i:s' ), (string) $at, 'a draft save reported a future submission' );
        }
    }

    /** @return list<array<string,mixed>> */
    private function submittedInputs(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT i.submitted_at, c.start_date, c.end_date, IFNULL( c.decision, '' ) AS decision
               FROM {$wpdb->prefix}tt_trial_case_staff_inputs i
               JOIN {$wpdb->prefix}tt_trial_cases c ON c.id = i.case_id
              WHERE i.submitted_at IS NOT NULL",
            ARRAY_A
        );
        return array_values( (array) $rows );
    }

    private function openCaseId(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_trial_cases
              WHERE club_id = %d AND archived_at IS NULL
                AND status IN ( 'open', 'extended' )
                AND ( decision IS NULL OR decision = '' )
              ORDER BY id DESC LIMIT 1",
            CurrentClub::id()
        ) );
    }
}
