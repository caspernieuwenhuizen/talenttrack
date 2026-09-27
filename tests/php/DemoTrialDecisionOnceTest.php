<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\TrialCaseGenerator;

/**
 * #4090 — the demo academy announces each trial decision once.
 *
 * `TrialCasesRepository::recordDecision()` fires
 * `tt_trial_decision_recorded` itself, and the generator fired it a second
 * time straight after, so every subscriber ran twice for every seeded admit.
 * The journey writer is idempotent and hid it; a subscriber that is not
 * would have doubled its work on every demo install.
 */
final class DemoTrialDecisionOnceTest extends WP_UnitTestCase {

    /** @var object[] */
    private array $roster = [];

    /** @var object[] */
    private array $teams = [];

    /** @var list<int> */
    private array $announced = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'demo-' . uniqid(), 'name' => 'Standard' ] );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Demo U13', 'age_group' => 'U13' ] );
        $team_id       = (int) $wpdb->insert_id;
        $this->teams[] = (object) [ 'id' => $team_id, 'age_group' => 'U13' ];

        for ( $i = 1; $i <= 9; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $club, 'team_id' => $team_id, 'first_name' => 'Vaste', 'last_name' => "Speler {$i}",
                'status' => 'active', 'jersey_number' => $i, 'date_joined' => '2024-01-03',
            ] );
            $this->roster[] = (object) [ 'id' => (int) $wpdb->insert_id, 'team_id' => $team_id, 'date_joined' => '2024-01-03' ];
        }

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        add_action( 'tt_trial_decision_recorded', [ $this, 'recordAnnouncement' ], 99, 1 );

        ( new TrialCaseGenerator( new DemoBatchRegistry( 'test-4090' ), $this->roster, $this->teams, [ 'hjo' => $admin ], 'en_US' ) )->generate();
    }

    public function tear_down(): void {
        remove_action( 'tt_trial_decision_recorded', [ $this, 'recordAnnouncement' ], 99 );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @param int|string $case_id */
    public function recordAnnouncement( $case_id ): void {
        $this->announced[] = (int) $case_id;
    }

    public function test_each_decision_is_announced_once(): void {
        $decided = $this->decidedCaseIds();
        $this->assertNotEmpty( $decided, 'the generator recorded no decision' );

        $announced = $this->announced;
        sort( $announced );
        $this->assertSame( $decided, $announced, 'a seeded decision was announced more or less than once' );
    }

    public function test_each_decision_writes_one_trial_ended_journey_event(): void {
        global $wpdb;
        foreach ( $this->decidedCaseIds() as $case_id ) {
            $events = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
                  WHERE event_type = %s AND source_entity_type = %s AND source_entity_id = %d",
                JourneyEventType::TRIAL_ENDED, 'trial_case', $case_id
            ) );
            $this->assertSame( 1, $events, 'case ' . $case_id . ' has ' . $events . ' trial-ended journey events' );
        }
    }

    /** @return list<int> */
    private function decidedCaseIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_trial_cases
              WHERE club_id = %d AND decision IS NOT NULL AND decision <> '' ORDER BY id",
            CurrentClub::id()
        ) );
        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }
}
