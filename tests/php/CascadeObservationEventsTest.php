<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Archive\GenericCascadeDeleter;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2723 — a journey event must not outlive the observation it came from.
 *
 * Deleting an activity cascaded the observation rows themselves, but the
 * `tt_player_events` rows they emitted survived: `cascade_poly` can only
 * match `source_entity_id` against the activity's own id, and these are
 * keyed on the observation's. The result was a coach's words about a named
 * child still standing on that child's timeline, pointing at a match or a
 * training that no longer exists.
 *
 * Both observation types are covered, because the older one
 * (`training_observed`, #2500) had the same hole and a fix for only the
 * newer one would leave it.
 */
final class CascadeObservationEventsTest extends WP_UnitTestCase {

    private string $p;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    // ---- fixtures ---------------------------------------------------------

    private function insertActivity( string $type, string $date = '2026-08-15' ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 3,
            'title'             => ucfirst( $type ) . ' ' . $date,
            'session_date'      => $date,
            'activity_type_key' => $type,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( string $last ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 3,
            'first_name' => 'Cascade',
            'last_name'  => $last,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function insertEvent( int $player_id, string $type, string $entity_type, int $entity_id, string $module ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_player_events", [
            'club_id'            => CurrentClub::id(),
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $player_id,
            'event_type'         => $type,
            'event_date'         => '2026-08-15 00:00:00',
            'summary'            => 'Observed something worth remembering.',
            'payload'            => '{}',
            'payload_valid'      => 1,
            'visibility'         => 'coaching_staff',
            'source_module'      => $module,
            'source_entity_type' => $entity_type,
            'source_entity_id'   => $entity_id,
            'created_by'         => 1,
        ] );

        return (int) $wpdb->insert_id;
    }

    /** @return array{activity:int, player:int, item:int, event:int} */
    private function matchAnalysisFixture(): array {
        global $wpdb;

        $activity_id = $this->insertActivity( 'game' );
        $player_id   = $this->insertPlayer( 'Match' );

        $wpdb->insert( "{$this->p}tt_match_analyses", [
            'uuid'        => wp_generate_uuid4(),
            'club_id'     => CurrentClub::id(),
            'activity_id' => $activity_id,
            'summary'     => 'Grew into it.',
            'status'      => 'final',
            'created_at'  => '2026-08-15 20:00:00',
            'updated_at'  => '2026-08-15 20:00:00',
        ] );
        $analysis_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_match_analysis_players", [
            'club_id'     => CurrentClub::id(),
            'analysis_id' => $analysis_id,
            'player_id'   => $player_id,
            'marker'      => 'stood_out',
            'note'        => 'Took the ball on the half-turn twice.',
            'updated_at'  => '2026-08-15 20:00:00',
        ] );
        $item_id = (int) $wpdb->insert_id;

        $event_id = $this->insertEvent(
            $player_id,
            JourneyEventType::MATCH_OBSERVED,
            'match_analysis_player',
            $item_id,
            'MatchAnalysis'
        );

        return [ 'activity' => $activity_id, 'player' => $player_id, 'item' => $item_id, 'event' => $event_id ];
    }

    private function eventExists( int $event_id ): bool {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_player_events WHERE id = %d",
            $event_id
        ) ) > 0;
    }

    // ---- match analysis ---------------------------------------------------

    public function test_deleting_a_match_removes_the_match_observed_events(): void {
        $fixture = $this->matchAnalysisFixture();

        $this->assertTrue( $this->eventExists( $fixture['event'] ), 'fixture sanity' );

        ( new GenericCascadeDeleter() )->cascade( 'activity', [ $fixture['activity'] ] );

        $this->assertFalse(
            $this->eventExists( $fixture['event'] ),
            'the timeline entry outlived the item it describes'
        );
    }

    public function test_the_preview_counts_the_events_it_will_remove(): void {
        $fixture = $this->matchAnalysisFixture();

        $preview = ( new GenericCascadeDeleter() )->preview( 'activity', [ $fixture['activity'] ] );

        $tables = array_column( $preview['removals'], 'count', 'table' );

        $this->assertArrayHasKey(
            'tt_player_events',
            $tables,
            'the operator must be told the timeline entries are going, not discover it afterwards'
        );
        $this->assertGreaterThanOrEqual( 1, (int) $tables['tt_player_events'] );
    }

    /**
     * The join is scoped to the activity being deleted. Another match's
     * observations are a different child's record and must not be touched.
     */
    public function test_another_matchs_events_survive(): void {
        $doomed   = $this->matchAnalysisFixture();
        $survivor = $this->matchAnalysisFixture();

        ( new GenericCascadeDeleter() )->cascade( 'activity', [ $doomed['activity'] ] );

        $this->assertFalse( $this->eventExists( $doomed['event'] ) );
        $this->assertTrue(
            $this->eventExists( $survivor['event'] ),
            'deleting one match took another match\'s timeline entry with it'
        );
    }

    // ---- training observations (#2500, same hole) --------------------------

    public function test_deleting_a_training_removes_the_training_observed_events(): void {
        global $wpdb;

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$this->p}tt_training_observations'" ) !== "{$this->p}tt_training_observations" ) {
            $this->markTestSkipped( 'training observations table not present on this install' );
        }

        $activity_id = $this->insertActivity( 'training' );
        $player_id   = $this->insertPlayer( 'Training' );

        $wpdb->insert( "{$this->p}tt_training_plan_runs", [
            'club_id'     => CurrentClub::id(),
            'plan_id'     => 1,
            'activity_id' => $activity_id,
            'team_id'     => 3,
            'run_date'    => '2026-08-15',
            'status'      => 'completed',
        ] );
        $run_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_training_observations", [
            'uuid'      => wp_generate_uuid4(),
            'club_id'   => CurrentClub::id(),
            'run_id'    => $run_id,
            'player_id' => $player_id,
            'note'      => 'Kept the ball moving under pressure.',
        ] );
        $observation_id = (int) $wpdb->insert_id;

        $event_id = $this->insertEvent(
            $player_id,
            JourneyEventType::TRAINING_OBSERVED,
            'training_observation',
            $observation_id,
            'Training'
        );

        ( new GenericCascadeDeleter() )->cascade( 'activity', [ $activity_id ] );

        $this->assertFalse( $this->eventExists( $event_id ) );

        $left = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_training_observations WHERE id = %d",
            $observation_id
        ) );
        $this->assertSame( 0, $left, 'the observation itself must go with its activity too' );
    }
}
