<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryRepository;

/**
 * #2609 — the injury capture surfaces and who reaches them.
 *
 * The bug this locks: injury writes used to gate on `canEditPlayer()`,
 * i.e. `players.edit`, which no coach persona holds. Granting
 * `player_injuries:change` in the matrix therefore changed nothing, and
 * the capture UI would have shipped invisible to the one role it exists
 * for. The entity that names the thing is the entity that decides.
 */
final class InjuryCaptureAccessTest extends WP_UnitTestCase {

    private int $playerId = 0;
    private int $teamId   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => 1,
            'name'    => 'Injury Test XI',
        ] );
        $this->teamId = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Injury',
            'last_name'  => 'Tester',
            'team_id'    => $this->teamId,
        ] );
        $this->playerId = (int) $wpdb->insert_id;
    }

    public function test_the_injuries_table_and_lookup_seeds_exist(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'tt_player_injuries';
        $this->assertSame( $t, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) );

        foreach ( [ 'body_part', 'injury_type', 'injury_severity' ] as $type ) {
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s",
                $type
            ) );
            $this->assertGreaterThan( 0, $n, "{$type} lookup must be seeded" );
        }
    }

    public function test_head_coach_holds_change_on_player_injuries_after_the_topup(): void {
        global $wpdb;
        $n = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_authorization_matrix
              WHERE persona = 'head_coach' AND entity = 'player_injuries'
                AND activity = 'change' AND scope_kind = 'team'"
        );
        $this->assertSame( 1, $n, 'migration 0220 must add the head_coach change tuple' );
    }

    public function test_assistant_coach_holds_no_injury_row_at_all(): void {
        global $wpdb;
        $n = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_authorization_matrix
              WHERE persona = 'assistant_coach' AND entity = 'player_injuries'"
        );
        $this->assertSame( 0, $n, 'medical data stays out of the assistant-coach scope' );
    }

    public function test_recording_a_return_emits_the_recovery_event_once(): void {
        global $wpdb;
        $repo = new InjuryRepository();

        $id = $repo->create( [
            'player_id'  => $this->playerId,
            'started_on' => '2026-03-01',
        ] );
        $this->assertGreaterThan( 0, $id );

        $started = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $this->playerId, 'injury_started'
        ) );
        $this->assertSame( 1, $started, 'creating an injury puts it on the journey' );

        $this->assertTrue( $repo->update( $id, [ 'actual_return' => '2026-04-01' ] ) );

        $ended = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $this->playerId, 'injury_ended'
        ) );
        $this->assertSame( 1, $ended, 'recording a return closes the loop on the journey' );

        // Idempotent: re-saving the same return date must not stack events.
        $repo->update( $id, [ 'actual_return' => '2026-04-01' ] );
        $ended_again = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $this->playerId, 'injury_ended'
        ) );
        $this->assertSame( 1, $ended_again, 're-saving a return must not duplicate the event' );
    }

    public function test_open_injuries_drop_out_of_the_overview_once_returned(): void {
        $repo = new InjuryRepository();

        $id = $repo->create( [
            'player_id'  => $this->playerId,
            'started_on' => '2026-03-01',
        ] );

        $open = $repo->listForTeams( [ $this->teamId ], [ 'status' => 'open' ] );
        $this->assertCount( 1, $open, 'an injury with no return date is open' );

        $repo->update( $id, [ 'actual_return' => '2026-04-01' ] );

        $open_after = $repo->listForTeams( [ $this->teamId ], [ 'status' => 'open' ] );
        $this->assertCount( 0, $open_after, 'a returned player is no longer out' );

        $recovered = $repo->listForTeams( [ $this->teamId ], [ 'status' => 'recovered' ] );
        $this->assertCount( 1, $recovered );
    }

    public function test_the_overview_is_scoped_to_the_teams_asked_for(): void {
        $repo = new InjuryRepository();
        $repo->create( [ 'player_id' => $this->playerId, 'started_on' => '2026-03-01' ] );

        $other_team = $this->teamId + 9999;
        $rows = $repo->listForTeams( [ $other_team ], [ 'status' => 'open' ] );
        $this->assertCount( 0, $rows, 'another team\'s injuries are not visible' );
    }
}
