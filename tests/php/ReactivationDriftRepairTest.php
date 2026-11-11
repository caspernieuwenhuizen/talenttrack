<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #5032 — migration 0329 puts back the migrated shape of the three tables a
 * re-activation could reshape, and leaves a table that never drifted alone.
 *
 * Each test builds the drifted shape on the real table, runs the migration,
 * and compares `SHOW CREATE TABLE` with the shape the suite started from.
 * The DDL commits the per-test transaction, so the fixtures are swept with
 * `CommitsAfterCascade`, and `tear_down()` runs the migration once more so a
 * failed assertion never leaves a drifted table to the rest of the suite.
 */
final class ReactivationDriftRepairTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private const MIGRATION = '/database/migrations/0329_repair_reactivation_schema_drift.php';

    private const TABLES = [ 'tt_attendance', 'tt_evaluations', 'tt_players' ];

    /** @var array<string,list<string>> bare table => its shape before the test */
    private array $shape = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        foreach ( self::TABLES as $table ) $this->shape[ $table ] = $this->createOf( $table );
    }

    public function tear_down(): void {
        $this->migration()->up();
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    public function test_a_table_that_never_drifted_is_left_alone(): void {
        $rows = [];
        foreach ( self::TABLES as $table ) $rows[ $table ] = $this->rows( $table );

        $before = $this->wroteNothingCount();
        $this->migration()->up();

        foreach ( self::TABLES as $table ) {
            $this->assertSame( $this->shape[ $table ], $this->createOf( $table ), "{$table} keeps its shape" );
            $this->assertSame( $rows[ $table ], $this->rows( $table ), "{$table} keeps its rows" );
        }
        $this->assertSame( $before + 1, $this->wroteNothingCount(), 'no drift is reported as nothing written' );
    }

    public function test_a_schema_only_repair_is_not_reported_as_nothing_written(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_attendance';
        $this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE player_id IS NULL" ), 'precondition: no guest rows to block NOT NULL' );
        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `player_id` BIGINT UNSIGNED NOT NULL" );

        $before = $this->wroteNothingCount();
        $this->migration()->up();

        $this->assertSame( $this->shape['tt_attendance'], $this->createOf( 'tt_attendance' ) );
        $this->assertSame( $before, $this->wroteNothingCount(), 'a schema change counts as a write' );
    }

    public function test_a_session_id_value_is_kept_in_an_empty_activity_id(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_attendance';
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `session_id` BIGINT UNSIGNED NOT NULL DEFAULT 0" );
        $club    = (int) CurrentClub::id();
        $wpdb->insert( $table, [ 'club_id' => $club, 'activity_id' => 0, 'session_id' => 4877, 'player_id' => 1, 'status' => 'present' ] );
        $empty   = (int) $wpdb->insert_id;
        $wpdb->insert( $table, [ 'club_id' => $club, 'activity_id' => 4878, 'session_id' => 4878, 'player_id' => 1, 'status' => 'present' ] );
        $same    = (int) $wpdb->insert_id;
        $wpdb->insert( $table, [ 'club_id' => $club, 'activity_id' => 4879, 'session_id' => 0, 'player_id' => 1, 'status' => 'present' ] );
        $unset   = (int) $wpdb->insert_id;

        $this->migration()->up();

        $this->assertSame( $this->shape['tt_attendance'], $this->createOf( 'tt_attendance' ) );
        $this->assertSame( '4877', $this->activityOf( $empty ), 'the session_id value moves into the empty activity_id' );
        $this->assertSame( '4878', $this->activityOf( $same ) );
        $this->assertSame( '4879', $this->activityOf( $unset ) );
    }

    public function test_two_different_activities_on_one_row_refuse_and_change_nothing(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_attendance';
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `session_id` BIGINT UNSIGNED NOT NULL DEFAULT 0" );
        $wpdb->insert( $table, [ 'club_id' => (int) CurrentClub::id(), 'activity_id' => 4880, 'session_id' => 4881, 'player_id' => 1, 'status' => 'present' ] );
        $row     = (int) $wpdb->insert_id;
        $drifted = $this->createOf( 'tt_attendance' );

        $refused = '';
        try {
            $this->migration()->up();
        } catch ( \RuntimeException $e ) {
            $refused = $e->getMessage();
        }

        $this->assertStringContainsString( '1 tt_attendance row(s)', $refused, 'the refusal names the count' );
        $this->assertSame( $drifted, $this->createOf( 'tt_attendance' ), 'nothing was changed' );
        $this->assertSame( '4880', $this->activityOf( $row ) );

        // Clear the conflict so tear_down() can put the shape back.
        $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id = %d", $row ) );
    }

    public function test_a_not_null_default_zero_account_column_is_repaired_under_strict_mode(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_players';
        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE wp_user_id IS NULL" ) > 0 ) {
            $this->markTestSkipped( 'needs a players table without unlinked rows to build the NOT NULL shape' );
        }

        $mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
        $wpdb->query( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES'" );
        try {
            $wpdb->query( "ALTER TABLE `{$table}` MODIFY `wp_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0" );
            $wpdb->insert( $table, [ 'club_id' => (int) CurrentClub::id(), 'first_name' => 'Lars', 'last_name' => 'Herstel', 'status' => 'active' ] );
            $first = (int) $wpdb->insert_id;
            $this->assertSame( 'NO', (string) $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'wp_user_id'" )->Null, 'precondition: NOT NULL' );

            $this->migration()->up();

            $this->assertSame( $this->shape['tt_players'], $this->createOf( 'tt_players' ) );
            $this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT wp_user_id FROM `{$table}` WHERE id = %d", $first ) ) );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $mode ) );
        }
    }

    public function test_attendance_loses_session_id_and_takes_a_guest_row_again(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_attendance';

        $this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE player_id IS NULL" ), 'precondition: no guest rows to block NOT NULL' );
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `session_id` BIGINT UNSIGNED NOT NULL" );
        $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_session` (`session_id`)" );
        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `player_id` BIGINT UNSIGNED NOT NULL" );
        $this->assertNotSame( $this->shape['tt_attendance'], $this->createOf( 'tt_attendance' ), 'precondition: the drifted shape is in place' );

        $this->migration()->up();
        $this->assertSame( $this->shape['tt_attendance'], $this->createOf( 'tt_attendance' ) );

        $this->migration()->up();
        $this->assertSame( $this->shape['tt_attendance'], $this->createOf( 'tt_attendance' ), 'a second run changes nothing' );

        $ok = $wpdb->insert( $table, [ 'club_id' => (int) CurrentClub::id(), 'activity_id' => 1, 'player_id' => null, 'status' => 'present' ] );
        $this->assertNotFalse( $ok, 'an insert that names no session_id and no player succeeds: ' . $wpdb->last_error );
    }

    public function test_evaluations_keep_their_result_and_lose_match_result(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';

        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `match_result` VARCHAR(50) DEFAULT NULL" );
        $only_old = $this->evaluation( [ 'game_result' => null, 'match_result' => '2-1' ] );
        $both     = $this->evaluation( [ 'game_result' => '1-1', 'match_result' => '0-3' ] );
        $neither  = $this->evaluation( [ 'game_result' => null, 'match_result' => '' ] );

        $this->migration()->up();
        $this->assertSame( $this->shape['tt_evaluations'], $this->createOf( 'tt_evaluations' ) );
        $this->assertSame( '2-1', $this->result( $only_old ), 'a result only in match_result is kept' );
        $this->assertSame( '1-1', $this->result( $both ), 'game_result wins when both hold one' );
        $this->assertNull( $this->result( $neither ) );

        $rows = $this->rows( 'tt_evaluations' );
        $this->migration()->up();
        $this->assertSame( $this->shape['tt_evaluations'], $this->createOf( 'tt_evaluations' ), 'a second run changes nothing' );
        $this->assertSame( $rows, $this->rows( 'tt_evaluations' ) );
    }

    public function test_players_without_an_account_hold_null_and_two_promotions_fit(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_players';

        $wpdb->query( "ALTER TABLE `{$table}` MODIFY `wp_user_id` BIGINT UNSIGNED DEFAULT 0" );
        $wpdb->insert( $table, [ 'club_id' => (int) CurrentClub::id(), 'first_name' => 'Sem', 'last_name' => 'Herstel', 'status' => 'active' ] );
        $first = (int) $wpdb->insert_id;
        $this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT wp_user_id FROM `{$table}` WHERE id = %d", $first ) ), 'precondition: the drifted default' );

        $this->migration()->up();
        $this->assertSame( $this->shape['tt_players'], $this->createOf( 'tt_players' ) );
        $this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT wp_user_id FROM `{$table}` WHERE id = %d", $first ) ) );

        $ok = $wpdb->insert( $table, [ 'club_id' => (int) CurrentClub::id(), 'first_name' => 'Noor', 'last_name' => 'Herstel', 'status' => 'active' ] );
        $this->assertNotFalse( $ok, 'a second player without an account fits the unique key: ' . $wpdb->last_error );

        $rows = $this->rows( 'tt_players' );
        $this->migration()->up();
        $this->assertSame( $this->shape['tt_players'], $this->createOf( 'tt_players' ), 'a second run changes nothing' );
        $this->assertSame( $rows, $this->rows( 'tt_players' ) );
    }

    /** @param array<string,mixed> $fields */
    private function evaluation( array $fields ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", array_merge( [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => 1,
            'coach_id'  => 1,
            'eval_date' => '2026-11-01',
            'notes'     => '',
        ], $fields ) );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'evaluation insert: ' . $wpdb->last_error );
        return $id;
    }

    private function result( int $evaluation_id ): ?string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT game_result FROM {$wpdb->prefix}tt_evaluations WHERE id = %d", $evaluation_id ) );
        return $value === null ? null : (string) $value;
    }

    private function activityOf( int $attendance_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT activity_id FROM {$wpdb->prefix}tt_attendance WHERE id = %d", $attendance_id ) );
    }

    /** The `migration.wrote_nothing` warnings this migration has logged. */
    private function wroteNothingCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_error_log WHERE message = %s AND context LIKE %s",
            'migration.wrote_nothing',
            '%' . $wpdb->esc_like( '0329_repair_reactivation_schema_drift' ) . '%'
        ) );
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    /** @return list<string> the definition lines, trailing commas and the counter dropped */
    private function createOf( string $bare ): array {
        global $wpdb;
        $row = $wpdb->get_row( "SHOW CREATE TABLE `{$wpdb->prefix}{$bare}`", ARRAY_N );
        $sql = is_array( $row ) ? (string) ( $row[1] ?? '' ) : '';
        $sql = (string) preg_replace( '/ AUTO_INCREMENT=\d+/', '', $sql );
        return array_values( array_map( static fn( string $l ): string => rtrim( trim( $l ), ',' ), explode( "\n", $sql ) ) );
    }

    /** @return list<array<string,mixed>> */
    private function rows( string $bare ): array {
        global $wpdb;
        return (array) $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}{$bare}` ORDER BY id", ARRAY_A );
    }
}
