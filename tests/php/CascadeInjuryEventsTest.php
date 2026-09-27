<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Archive\GenericCascadeDeleter;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4122 — purging an injury takes both of its journey events with it.
 *
 * `InjuryRepository` writes the started event as source type `injury` and
 * the recovery event as `injury_recovery`, both keyed to the injury. The
 * cascade only knew the first, so a purged injury with a return logged left
 * "Injury recovered" on a minor's timeline, pointing at a medical record
 * that no longer existed.
 *
 * A real purge commits, so this cleans up after itself; see
 * `CommitsAfterCascade`.
 */
final class CascadeInjuryEventsTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private string $p = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $this->markFixtureFloor();
    }

    public function test_purging_an_injury_removes_its_started_and_recovery_events(): void {
        $player    = $this->player( 'Purged' );
        $injury    = $this->injury( $player );
        $started   = $this->event( $player, JourneyEventType::INJURY_STARTED, 'injury', $injury );
        $recovered = $this->event( $player, JourneyEventType::INJURY_ENDED, 'injury_recovery', $injury );

        $other_injury = $this->injury( $player );
        $other_ended  = $this->event( $player, JourneyEventType::INJURY_ENDED, 'injury_recovery', $other_injury );

        $preview = ( new GenericCascadeDeleter() )->preview( 'injury', [ $injury ] );
        $counted = 0;
        foreach ( $preview['removals'] as $removal ) {
            if ( $removal['table'] === 'tt_player_events' ) $counted += (int) $removal['count'];
        }
        $this->assertSame( 2, $counted, 'the preview names both timeline entries' );

        ( new GenericCascadeDeleter() )->cascade( 'injury', [ $injury ] );

        $this->assertFalse( $this->eventExists( $started ), 'the started event went with the injury' );
        $this->assertFalse( $this->eventExists( $recovered ), 'and so did the recovery event' );
        $this->assertTrue( $this->eventExists( $other_ended ), 'another injury\'s recovery event is untouched' );

        $this->cleanUpAndCommit();
        $this->assertSame( [], $this->rowsAboveFixtureFloor(), 'a purge test must not hand its fixtures to the rest of the suite' );
    }

    private function player( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => CurrentClub::id(),
            'first_name' => 'Injury',
            'last_name'  => $last,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function injury( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_injuries", [
            'club_id'            => CurrentClub::id(),
            'player_id'          => $player_id,
            'started_on'         => '2026-08-01',
            'actual_return'      => '2026-08-20',
            'is_recovery_logged' => 1,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the injury was written' );
        return $id;
    }

    private function event( int $player_id, string $type, string $source_type, int $source_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_events", [
            'club_id'            => CurrentClub::id(),
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $player_id,
            'event_type'         => $type,
            'event_date'         => '2026-08-20 00:00:00',
            'summary'            => 'Injury fixture event.',
            'payload'            => '{}',
            'payload_valid'      => 1,
            'visibility'         => 'medical',
            'source_module'      => 'Journey',
            'source_entity_type' => $source_type,
            'source_entity_id'   => $source_id,
            'created_by'         => 1,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the event was written' );
        return $id;
    }

    private function eventExists( int $event_id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_player_events WHERE id = %d",
            $event_id
        ) ) > 0;
    }
}
