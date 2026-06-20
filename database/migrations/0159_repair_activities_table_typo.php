<?php
/**
 * Migration 0159 — repair the `tt_activitys` table-name typo (#1511).
 *
 * The Activator created the activities table as `tt_activitys` (a
 * misspelling introduced in the #0035 sessions→activities rename) while
 * the entire codebase reads `tt_activities`. On installs that upgraded
 * from `tt_sessions`, migration 0027 produced the correct `tt_activities`
 * and this is a no-op. On FRESH installs created after #0035, the
 * Activator made `tt_activitys`, 0027's rename skipped (no `tt_sessions`),
 * and every later `ALTER tt_activities` migration no-op'd against a
 * missing table — leaving an orphaned base table and a broken activities
 * feature.
 *
 * This migration adopts the orphaned table under the correct name and
 * brings it up to the current schema. Idempotent and safe on every
 * install shape:
 *   - correctly-built install → `tt_activitys` absent (no rename), all
 *     columns present (every addColumnIfMissing is a no-op);
 *   - typo-corrupted install → rename + column backfill;
 *   - both tables present (defensive) → drop the typo'd one only when
 *     it holds no rows.
 *
 * Indexes the original migrations added (status/source/uuid) are not
 * recreated here — they affect query performance, not correctness, and
 * a correctly-built install already has them.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0159_repair_activities_table_typo';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $typo  = "{$p}tt_activitys";
        $table = "{$p}tt_activities";

        $typo_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $typo ) ) === $typo;
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

        // Adopt the orphaned base table under the correct name.
        if ( $typo_exists && ! $table_exists ) {
            $wpdb->query( "RENAME TABLE `{$typo}` TO `{$table}`" );
            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
            $typo_exists  = ! $table_exists;
        }

        // Defensive: if both somehow exist, drop the typo'd one only when
        // it is empty so no data is lost.
        if ( $typo_exists && $table_exists ) {
            $orphan_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$typo}`" );
            if ( $orphan_rows === 0 ) {
                $wpdb->query( "DROP TABLE `{$typo}`" );
            }
        }

        if ( ! $table_exists ) {
            return; // nothing to backfill
        }

        // Bring a typo-orphaned base table up to the current schema.
        // Mirrors the column adds from migrations 0027 / 0038 / 0040 /
        // 0073 / 0079 / 0100 / 0120 / 0144 / 0152 / 0157. No-ops when a
        // column already exists. `after` is best-effort (the helper omits
        // the AFTER clause when the anchor column is missing).
        $columns = [
            [ 'activity_type_key',   "VARCHAR(50) NOT NULL DEFAULT 'training'",  'notes' ],
            [ 'game_subtype_key',    'VARCHAR(50) DEFAULT NULL',                 'activity_type_key' ],
            [ 'other_label',         'VARCHAR(120) DEFAULT NULL',                'game_subtype_key' ],
            [ 'club_id',             'INT UNSIGNED NOT NULL DEFAULT 1',          '' ],
            [ 'uuid',                'VARCHAR(36) DEFAULT NULL',                 '' ],
            [ 'activity_status_key', "VARCHAR(50) NOT NULL DEFAULT 'planned'",   '' ],
            [ 'activity_source_key', "VARCHAR(50) NOT NULL DEFAULT 'manual'",    '' ],
            [ 'evaluation_skipped',  'TINYINT(1) NOT NULL DEFAULT 0',            'activity_status_key' ],
            [ 'plan_state',          "VARCHAR(16) NOT NULL DEFAULT 'completed'", 'notes' ],
            [ 'planned_at',          'DATETIME DEFAULT NULL',                    'plan_state' ],
            [ 'planned_by',          'BIGINT UNSIGNED DEFAULT NULL',             'planned_at' ],
            [ 'opponent',            'VARCHAR(255) DEFAULT NULL',                'notes' ],
            [ 'home_away',           'VARCHAR(10) DEFAULT NULL',                 'opponent' ],
            [ 'kickoff_time',        'TIME DEFAULT NULL',                        'home_away' ],
            [ 'formation',           'VARCHAR(20) DEFAULT NULL',                 'kickoff_time' ],
            [ 'home_score',          'TINYINT UNSIGNED DEFAULT NULL',            '' ],
            [ 'away_score',          'TINYINT UNSIGNED DEFAULT NULL',            '' ],
            [ 'start_time',          'TIME DEFAULT NULL',                        'session_date' ],
            [ 'end_time',            'TIME DEFAULT NULL',                        'start_time' ],
            [ 'tournament_id',       'BIGINT UNSIGNED DEFAULT NULL',             'team_id' ],
            [ 'created_by',          'BIGINT UNSIGNED DEFAULT NULL',             'updated_at' ],
            [ 'updated_by',          'BIGINT UNSIGNED DEFAULT NULL',             'created_by' ],
        ];

        foreach ( $columns as [ $name, $definition, $after ] ) {
            MigrationHelpers::addColumnIfMissing( $table, $name, $definition, $after );
        }
    }

    public function down(): void {
        // Forward-only — a structural repair is not reverted.
    }
};
