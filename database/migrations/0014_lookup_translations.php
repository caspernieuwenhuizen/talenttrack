<?php
/**
 * Migration 0014 — Per-lookup translations column (v3.6.0).
 *
 * Adds `translations` TEXT column to `tt_lookups`. Stores a JSON object
 * keyed by WP locale, each value carrying the translated `name` (and
 * optionally `description`) for that locale:
 *
 *   {
 *     "nl_NL": { "name": "Rechts" },
 *     "de_DE": { "name": "Rechts" }
 *   }
 *
 * Resolution lives in `LookupTranslator::translate()`:
 *   1. Look up the current-user locale (`determine_locale()`).
 *   2. If `translations[locale].name` is set, return it.
 *   3. Otherwise fall back to `__($lookup->name, 'talenttrack')` so
 *      existing .po-driven translations for seeded values keep working.
 *
 * Column is nullable — existing rows stay at NULL and continue to resolve
 * via the .po fallback. Admin-added rows get translations via the edit
 * form under Configuration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0014_lookup_translations';
    }

    public function up(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_lookups";

        $col_exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = 'translations'",
            $table
        ) );
        if ( $col_exists > 0 ) return;

        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN translations TEXT DEFAULT NULL AFTER meta" );
    }
};
