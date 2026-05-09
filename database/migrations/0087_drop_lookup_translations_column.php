<?php
/**
 * Migration 0087 — drop the legacy `tt_lookups.translations` JSON
 * column (#0090 Phase 6).
 *
 * The column was added by migration 0014 (v3.6.0) to give admin-added
 * lookup values a translation channel that bypassed `.po`. Phase 2
 * (migration 0082) copied its contents into `tt_translations`. Phase 6
 * (migration 0086) caught the .po-only rows that 0082 missed. Both
 * `LookupsRestController` and `ConfigurationPage::handle_save_lookup()`
 * stop writing to the JSON column in this same ship; `LookupTranslator`
 * stops reading from it. The column is now vestigial — drop it.
 *
 * Defensive: if the column doesn't exist (fresh install that never had
 * 0014 in its history, or a partial-rollback), the migration is a
 * no-op. Idempotent on re-run.
 *
 * No data is at risk — `tt_translations` already holds every translation
 * the column carried, plus the `.po`-resolved seeded translations that
 * migration 0086 just backfilled.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0087_drop_lookup_translations_column';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_lookups';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'translations'
        ) );
        if ( $col === null ) return; // Already dropped or never created.

        // dbDelta won't drop columns; ALTER TABLE is the right tool here.
        $wpdb->query( "ALTER TABLE {$table} DROP COLUMN translations" );
    }
};
