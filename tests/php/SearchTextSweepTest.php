<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Search\SearchText;
use TT\Infrastructure\Search\SearchTextSweep;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4671 — the folded columns: written on save, backfilled and kept honest by
 * the sweep, bounded per tick, and the migration runs twice harmlessly.
 */
final class SearchTextSweepTest extends WP_UnitTestCase {

    private function player( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [ 'club_id' => (int) CurrentClub::id(), 'first_name' => $first, 'last_name' => $last, 'status' => 'active', 'wp_user_id' => null ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array<string,mixed> */
    private function fold( int $id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare( "SELECT search_title, search_text, search_v FROM {$wpdb->prefix}tt_players WHERE id = %d", $id ), ARRAY_A );
    }

    public function test_refresh_writes_the_fold_and_skips_a_row_already_right(): void {
        $id = $this->player( 'Zoë', "O'Neill" );
        $this->assertSame( 1, SearchText::refresh( 'player', $id ) );
        $this->assertSame( [ 'search_title' => ' zoe o neill', 'search_text' => '', 'search_v' => (string) SearchText::VERSION ], $this->fold( $id ) );
        $this->assertSame( 0, SearchText::refresh( 'player', $id ), 'nothing to write the second time' );
    }

    public function test_the_sweep_backfills_and_catches_drift(): void {
        global $wpdb;
        $unfolded = $this->player( 'Unfolded', 'Person' );
        $drifted  = $this->player( 'Old', 'Name' );
        SearchText::refresh( 'player', $drifted );
        // A write that changed the name without refreshing.
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'first_name' => 'New' ], [ 'id' => $drifted ] );
        ( new ConfigService() )->set( SearchTextSweep::CURSOR_KEY . 'player', '0' );

        $stats = ( new SearchTextSweep() )->runForCurrentClub();
        $this->assertGreaterThanOrEqual( 1, $stats['player']['folded'] );
        $this->assertSame( ' unfolded person', $this->fold( $unfolded )['search_title'] );
        $this->assertSame( ' new name', $this->fold( $drifted )['search_title'] );

        $again = ( new SearchTextSweep() )->runForCurrentClub();
        $this->assertSame( 0, $again['player']['folded'], 'idempotent' );
    }

    public function test_a_spent_budget_still_folds_one_batch_per_kind(): void {
        for ( $i = 0; $i < 5; $i++ ) $this->player( 'Bound', 'Row' . $i );
        $stats = ( new SearchTextSweep( 0.0, 2 ) )->runForCurrentClub();
        $this->assertSame( 2, $stats['player']['folded'], 'one batch, then the budget stops it' );
    }

    public function test_the_backfill_loops_batches_until_the_backlog_is_done(): void {
        global $wpdb;
        for ( $i = 0; $i < 5; $i++ ) $this->player( 'Loop', 'Row' . $i );
        $before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players WHERE club_id = %d AND search_v IS NULL", CurrentClub::id() ) );
        $this->assertGreaterThanOrEqual( 5, $before );

        $stats = ( new SearchTextSweep( 60.0, 2 ) )->runForCurrentClub();
        $this->assertSame( $before, $stats['player']['folded'], 'batches of 2 until none are left, in one tick' );
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players WHERE club_id = %d AND search_v IS NULL", CurrentClub::id() ) ) );
    }

    public function test_the_migration_runs_twice_without_harm(): void {
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0317_search_text_and_recent_items.php';
        $migration->up();
        $migration->up();
        global $wpdb;
        foreach ( [ 'tt_players', 'tt_teams', 'tt_activities', 'tt_people' ] as $table ) {
            foreach ( [ 'search_title', 'search_text', 'search_v' ] as $column ) {
                $this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $wpdb->prefix . $table, $column
                ) ), "{$table}.{$column}" );
            }
        }
        $this->assertSame( $wpdb->prefix . 'tt_user_recent', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'tt_user_recent' ) ) );
    }

    public function test_the_sweep_rides_the_engine_heartbeat(): void {
        $this->assertNotFalse( has_action( \TT\Modules\Workflow\Dispatchers\CronDispatcher::TICK_HOOK, [ SearchTextSweep::class, 'onTick' ] ) );
    }
}
