<?php
/**
 * Migration 0326 — where a goal entry came from, and who touched it (#4994).
 *
 * The goals and assists drill-down shows a coach every entry behind a count,
 * so a wrong one can be found: 70 assists for one player is a typo somewhere,
 * and the question is which match and which screen. `created_at` was already
 * on the row; the rest was not.
 *
 *   - `source`     — `live_sheet`, `match_form` or `demo`;
 *   - `created_by` — the account that entered it;
 *   - `updated_at` / `updated_by` — the last change to who scored or assisted,
 *     or to the minute.
 *
 * All nullable, and no backfill. A row written before this migration has no
 * author on record, and a guessed one would be worse than none: the reader
 * derives "live match sheet" or "entered after the match" from
 * `execution_id` and says the entry predates tracking.
 *
 * Additive and idempotent through `addColumnIfMissing` (never `dbDelta` on a
 * pre-existing table, per 0196).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0326_goal_events_provenance';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_match_execution_goal_events';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $columns = [
            [ 'source',     'VARCHAR(20) NULL DEFAULT NULL',     'created_at' ],
            [ 'created_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'source' ],
            [ 'updated_at', 'DATETIME NULL DEFAULT NULL',        'created_by' ],
            [ 'updated_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'updated_at' ],
        ];
        foreach ( $columns as [ $column, $definition, $after ] ) {
            if ( ! MigrationHelpers::addColumnIfMissing( $table, $column, $definition, $after ) ) {
                throw new \RuntimeException( "0326: failed to add {$column} to tt_match_execution_goal_events" );
            }
        }
    }
};
