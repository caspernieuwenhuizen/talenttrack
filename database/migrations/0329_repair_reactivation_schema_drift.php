<?php
/**
 * Migration 0329 — repair the table shapes a re-activation put back (#5032).
 *
 * Until #5020 the install routine ran its CREATE statements through dbDelta
 * on every activation and every "Retry migrations now", over tables the
 * migrations had already shaped. dbDelta adds and widens, never drops, so an
 * install that took that path after 0027 / 0101 / 0170 can carry:
 *
 *   - `tt_attendance.session_id` (NOT NULL, no default) and `idx_session`
 *     next to `activity_id`, and `player_id` NOT NULL again;
 *   - `tt_evaluations.match_result` next to `game_result`;
 *   - `tt_players.wp_user_id` defaulting to 0 instead of NULL.
 *
 * Every step reads the live shape first and changes only what differs from
 * the migrated one, so an install that never drifted is left untouched.
 * The report counts each schema change as a write, so only an install with
 * no drift reports nothing written. Forward-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0329_repair_reactivation_schema_drift';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $written = 0;
        $notes   = [];

        $attendance = "{$p}tt_attendance";
        if ( $this->tableExists( $attendance ) ) {
            $clauses = [];
            if ( MigrationHelpers::columnExists( $attendance, 'activity_id' ) && MigrationHelpers::columnExists( $attendance, 'session_id' ) ) {
                // Refuse before anything is written when a row holds two
                // different activities: which one is right cannot be told
                // from the row.
                $conflicts = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM `{$attendance}`
                      WHERE session_id IS NOT NULL AND session_id <> 0
                        AND activity_id IS NOT NULL AND activity_id <> 0
                        AND activity_id <> session_id"
                );
                if ( $conflicts > 0 ) {
                    throw new \RuntimeException( sprintf(
                        '0329: %d tt_attendance row(s) hold a session_id that differs from their activity_id; resolve them before this repair runs. Nothing was changed.',
                        $conflicts
                    ) );
                }
                $kept = $this->exec(
                    "UPDATE `{$attendance}`
                        SET activity_id = session_id
                      WHERE ( activity_id IS NULL OR activity_id = 0 )
                        AND session_id IS NOT NULL AND session_id <> 0"
                );
                $written += $kept;
                if ( $kept > 0 ) $notes[] = sprintf( 'tt_attendance: %d session_id value(s) kept in activity_id', $kept );

                // Only an index on `session_id` alone goes; an `idx_session`
                // over anything else is not the one the CREATE added.
                if ( $this->indexColumns( $attendance, 'idx_session' ) === [ 'session_id' ] ) {
                    $clauses[] = 'DROP INDEX `idx_session`';
                }
                $clauses[] = 'DROP COLUMN `session_id`';
                $notes[]   = 'tt_attendance: dropped session_id';
            }
            $player = $this->column( $attendance, 'player_id' );
            if ( $player !== null && (string) ( $player['Null'] ?? '' ) !== 'YES' ) {
                $clauses[] = 'MODIFY COLUMN `player_id` BIGINT UNSIGNED NULL';
                $notes[]   = 'tt_attendance: player_id nullable';
            }
            // One statement, so the table is rebuilt once.
            if ( $clauses !== [] ) {
                $this->exec( "ALTER TABLE `{$attendance}` " . implode( ', ', $clauses ) );
                $written += count( $clauses );
            }
        }

        $evaluations = "{$p}tt_evaluations";
        if ( $this->tableExists( $evaluations ) && MigrationHelpers::columnExists( $evaluations, 'game_result' ) && MigrationHelpers::columnExists( $evaluations, 'match_result' ) ) {
            $copied = $this->exec(
                "UPDATE `{$evaluations}`
                    SET game_result = match_result
                  WHERE ( game_result IS NULL OR game_result = '' )
                    AND match_result IS NOT NULL AND match_result <> ''"
            );
            $written += $copied;
            $this->exec( "ALTER TABLE `{$evaluations}` DROP COLUMN `match_result`" );
            $written += 1;
            $notes[] = sprintf( 'tt_evaluations: dropped match_result, %d result(s) copied to game_result', $copied );
        }

        $players = "{$p}tt_players";
        if ( $this->tableExists( $players ) ) {
            $account = $this->column( $players, 'wp_user_id' );
            if ( $account !== null ) {
                // The column is made nullable first: setting NULL on a
                // NOT NULL column fails under strict SQL mode.
                $default = $account['Default'] ?? null;
                if ( (string) ( $account['Null'] ?? '' ) !== 'YES' || ( $default !== null && strtoupper( (string) $default ) !== 'NULL' ) ) {
                    $this->exec( "ALTER TABLE `{$players}` MODIFY `wp_user_id` BIGINT UNSIGNED DEFAULT NULL" );
                    $written += 1;
                    $notes[] = 'tt_players: wp_user_id defaults to NULL';
                }

                $unlinked = $this->exec( "UPDATE `{$players}` SET wp_user_id = NULL WHERE wp_user_id = 0" );
                $written += $unlinked;
                if ( $unlinked > 0 ) $notes[] = sprintf( 'tt_players: %d wp_user_id 0 set to NULL', $unlinked );
            }
        }

        $this->report( $written, $notes === [] ? 'no drift found' : implode( '; ', $notes ) );
    }

    private function tableExists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
    }

    /** @return array<string,mixed>|null the SHOW COLUMNS row */
    private function column( string $table, string $column ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return list<string> */
    private function indexColumns( string $table, string $index ): array {
        global $wpdb;
        return array_values( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              ORDER BY SEQ_IN_INDEX',
            $table, $index
        ) ) ) );
    }

    public function down(): void {
        // Forward-only. The shapes this removes are the ones that break
        // attendance writes and a second player promotion in a club.
    }
};
