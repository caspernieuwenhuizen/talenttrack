<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\GoalLifecycle;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * Migration 0332 — a goal with no status (NULL, empty, or "open" in any case
 * or padding) is stored as pending, and counts as open under GoalLifecycle.
 * Every other status is left alone; a second run changes nothing.
 */
final class GoalsWithoutStatusPendingTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0332_goals_without_status_pending.php';

    public function test_goals_without_a_status_are_stored_as_pending_and_count_as_open(): void {
        $null    = $this->goal( null );
        $empty   = $this->goal( '' );
        $open    = $this->goal( ' Open ' );
        $done    = $this->goal( 'completed' );
        $working = $this->goal( 'In Progress' );

        $this->assertSame( 0, $this->openAmong( [ $null, $empty, $open ] ), 'precondition: not counted as open before' );

        $this->migration()->up();

        foreach ( [ $null, $empty, $open ] as $id ) {
            $this->assertSame( 'pending', $this->statusOf( $id ) );
        }
        $this->assertSame( 3, $this->openAmong( [ $null, $empty, $open ] ), 'counted as open under GoalLifecycle' );
        $this->assertSame( 'completed', $this->statusOf( $done ), 'another status is left alone' );
        $this->assertSame( 'In Progress', $this->statusOf( $working ), 'and so is its spelling' );
    }

    public function test_a_second_run_changes_nothing_and_says_so(): void {
        $this->goal( null );
        $this->migration()->up();

        global $wpdb;
        $before = $this->wroteNothingCount();
        $rows   = $wpdb->get_results( "SELECT id, status FROM {$wpdb->prefix}tt_goals ORDER BY id", ARRAY_A );

        $this->migration()->up();

        $this->assertSame( $rows, $wpdb->get_results( "SELECT id, status FROM {$wpdb->prefix}tt_goals ORDER BY id", ARRAY_A ) );
        $this->assertSame( $before + 1, $this->wroteNothingCount(), 'no row to change is logged as nothing written' );
    }

    private function goal( ?string $status ): int {
        global $wpdb;
        $row = [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => 1,
            'title'     => 'Weaker foot',
        ];
        $wpdb->insert( "{$wpdb->prefix}tt_goals", $row );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'goal insert: ' . $wpdb->last_error );
        // Set apart from the insert so a column default cannot replace NULL.
        if ( $status === null ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}tt_goals SET status = NULL WHERE id = %d", $id ) );
        } else {
            $wpdb->update( "{$wpdb->prefix}tt_goals", [ 'status' => $status ], [ 'id' => $id ] );
        }
        return $id;
    }

    private function statusOf( int $id ): ?string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}tt_goals WHERE id = %d", $id ) );
        return $value === null ? null : (string) $value;
    }

    /** @param list<int> $ids */
    private function openAmong( array $ids ): int {
        global $wpdb;
        $in = implode( ',', array_map( 'intval', $ids ) );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_goals g WHERE g.id IN ({$in}) AND " . GoalLifecycle::openClause( 'g' ) );
    }

    private function wroteNothingCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_error_log WHERE message = %s AND context LIKE %s",
            'migration.wrote_nothing',
            '%' . $wpdb->esc_like( '0332_goals_without_status_pending' ) . '%'
        ) );
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }
}
