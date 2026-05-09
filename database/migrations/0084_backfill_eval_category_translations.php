<?php
/**
 * Migration 0084 — backfill `tt_translations` with eval-category
 * Dutch labels resolved through gettext (#0090 Phase 3).
 *
 * Phase 2 (migration 0082) backfilled lookups by copying from a
 * legacy JSON column. `tt_eval_categories` has no such column — its
 * Dutch translations live exclusively in `nl_NL.po`. So this
 * migration calls `__($label, 'talenttrack')` on every existing row
 * and, when gettext returns a different string, persists that
 * translation as a `nl_NL` row in `tt_translations`.
 *
 * For each row in `tt_eval_categories`:
 *   - Look up the canonical English label via `__()`.
 *   - If gettext returns the input unchanged → skip (row has no
 *     translation, or the operator authored a non-seeded label).
 *   - If gettext returns a different string → that's the Dutch
 *     translation; insert a `(club_id, 'eval_category', $id, 'label',
 *     'nl_NL', <translated>)` row.
 *
 * Idempotent — `INSERT IGNORE` against the unique index makes
 * re-runs no-ops, and operator-edited rows from a future Phase 5
 * Translations tab survive untouched.
 *
 * Phase 6 cleanup will prune the migrated msgids from `nl_NL.po`.
 * Until then, the resolver chain in `EvalCategoriesRepository::displayLabel()`
 * is `tt_translations → __() → fallback`, so this backfill is
 * additive — no behaviour change if it doesn't run.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0084_backfill_eval_category_translations';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $categories_table   = "{$p}tt_eval_categories";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $categories_table ) ) !== $categories_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        // Make sure the talenttrack textdomain is loaded so `__()`
        // resolves against `nl_NL.po`. Migrations sometimes run very
        // early in the request lifecycle (plugin activation, wp-cli
        // bootstrapping) before the textdomain has loaded itself.
        if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
            load_plugin_textdomain( 'talenttrack', false, dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages' );
        }

        $rows = $wpdb->get_results(
            "SELECT id, club_id, label
               FROM {$categories_table}
              WHERE label IS NOT NULL AND label <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";

        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $club_id = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            $cat_id  = (int) ( $row['id'] ?? 0 );
            $label   = (string) ( $row['label'] ?? '' );
            if ( $cat_id <= 0 || $label === '' ) continue;

            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
            $translated = (string) __( $label, 'talenttrack' );
            if ( $translated === '' || $translated === $label ) continue;

            $wpdb->query( $wpdb->prepare(
                $sql,
                $club_id,
                'eval_category',
                $cat_id,
                'label',
                'nl_NL',
                $translated,
                $now
            ) );
        }
    }
};
