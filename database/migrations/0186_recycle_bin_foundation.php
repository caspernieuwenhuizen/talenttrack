<?php
/**
 * Migration 0186 — recycle-bin foundation (#2020, epic #2018).
 *
 * The recycle bin adds a second soft-delete tier on top of the existing
 * archive (`archived_at` / `archived_by`): archive → trash → purge. This
 * migration lays the schema + config substrate every later child (#2021–
 * #2025) builds on. It does three things, all idempotent / re-runnable:
 *
 *   1. Adds the uniform `trashed_at DATETIME NULL` + `trashed_by BIGINT
 *      UNSIGNED NULL` pair (mirroring the 0010 / 0172 archive columns) to
 *      every entity table in `ArchiveRepository::TABLE_MAP` — the
 *      authoritative list of bin-archivable entities. NULL = not trashed;
 *      a timestamp = moved to the bin at that moment, by that user. An
 *      index on `trashed_at` keeps the purge-cron sweep (#2025) and the
 *      bin list view (#2022) cheap.
 *
 *   2. Seeds the per-club retention window `tt_recycle_bin_retention_days`
 *      (default 30) into `tt_config` for every club that has config rows,
 *      so the purge cron has an explicit, operator-tunable window from day
 *      one. No settings UI — `ConfigService::getInt()` reads it with a 30
 *      fallback regardless, so the seed is belt-and-braces for the audit
 *      script + future per-tenant overrides.
 *
 * The list mirrors `ArchiveRepository::TABLE_MAP` exactly — 20 entities.
 * Lookups / config / vocabulary tables are deliberately excluded (they are
 * never bin-archivable).
 *
 * Forward-only (additive columns + INSERT IGNORE config seed). Re-runnable:
 * each ADD COLUMN is guarded by an existence check; the index guard checks
 * information_schema.STATISTICS first; the config seed is INSERT IGNORE on
 * the (club_id, config_key) primary key, so an operator-edited window is
 * never overwritten.
 *
 * @see database/migrations/0010_archive_support.php — the archive-column twin
 * @see database/migrations/0172_archive_by_completion.php — archive_by completion
 * @see src/Infrastructure/Archive/ArchiveRepository.php — TABLE_MAP source of truth
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    /**
     * Bin-archivable entity tables. Kept in lock-step with
     * `ArchiveRepository::TABLE_MAP` — 20 entries. If TABLE_MAP grows, a
     * follow-up migration extends this; the two lists must agree so the
     * bin operates over exactly the entities the archive does.
     *
     * @var list<string>
     */
    private const TABLES = [
        'tt_players',
        'tt_teams',
        'tt_evaluations',
        'tt_activities',
        'tt_goals',
        'tt_people',
        'tt_tournaments',
        'tt_trial_cases',
        'tt_holidays',
        'tt_test_trainings',
        'tt_trial_tracks',
        'tt_vct_exercises',
        'tt_custom_widgets',
        'tt_player_injuries',
        'tt_scheduled_reports',
        'tt_measurement_definitions',
        'tt_measurement_sessions',
        'tt_measurement_targets',
        'tt_measurement_results',
        'tt_player_attribute_defs',
    ];

    /** Default retention window, in days, before a trashed row is purged. */
    private const RETENTION_DEFAULT_DAYS = 30;

    public function getName(): string {
        return '0186_recycle_bin_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( self::TABLES as $table ) {
            $full = $p . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                continue;
            }

            // Both adds are idempotent — addColumnIfMissing no-ops when the
            // column is already present (re-run / partially-migrated host).
            MigrationHelpers::addColumnIfMissing( $full, 'trashed_at', 'DATETIME NULL DEFAULT NULL', 'archived_at' );
            MigrationHelpers::addColumnIfMissing( $full, 'trashed_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'archived_by' );

            $this->addIndexIfMissing( $full, 'idx_trashed_at', 'trashed_at' );
        }

        $this->seedRetentionConfig();
    }

    /**
     * Seed the retention window for every club that already has config
     * rows, so the purge cron reads an explicit value. INSERT IGNORE on
     * the (club_id, config_key) primary key leaves operator edits intact.
     */
    private function seedRetentionConfig(): void {
        global $wpdb;
        $config = $wpdb->prefix . 'tt_config';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $config ) ) !== $config ) {
            return;
        }

        // One row per existing club_id; club 1 always exists (single-tenant
        // default) — seed it explicitly so a brand-new install with no other
        // config rows still gets the window.
        $club_ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$config}" );
        $club_ids = array_map( 'intval', is_array( $club_ids ) ? $club_ids : [] );
        if ( ! in_array( 1, $club_ids, true ) ) {
            $club_ids[] = 1;
        }

        foreach ( $club_ids as $club_id ) {
            $this->exec( $wpdb->prepare(
                "INSERT IGNORE INTO {$config} (club_id, config_key, config_value) VALUES (%d, %s, %s)",
                $club_id,
                'tt_recycle_bin_retention_days',
                (string) self::RETENTION_DEFAULT_DAYS
            ) );
        }
    }

    /**
     * Add a non-unique index iff missing. No `addIndexIfMissing` helper
     * exists yet (see docs/migrations.md § "Writing migrations"); this
     * follows migration 0170's information_schema.STATISTICS guard pattern.
     */
    private function addIndexIfMissing( string $table, string $index, string $column ): void {
        global $wpdb;
        $exists = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            $table, $index
        ) );
        if ( $exists ) return;

        // Column must exist for the index to be addable — it was added just
        // above, but guard anyway so a drifted host fails loud, not silent.
        if ( ! MigrationHelpers::columnExists( $table, $column ) ) return;

        $this->exec( "ALTER TABLE {$table} ADD INDEX {$index} ({$column})" );
    }

    public function down(): void {
        // Forward-only. Dropping the columns would lose the in-bin state of
        // any row currently trashed (and the audit trail of who trashed it).
        // A genuine rollback restores from backup; the additive columns are
        // inert when the feature is unused (NULL = not trashed).
    }
};
