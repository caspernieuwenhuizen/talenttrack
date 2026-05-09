<?php
/**
 * Migration 0085 — backfill `tt_translations` with role + functional-role
 * Dutch labels resolved through gettext (#0090 Phase 4).
 *
 * Mirrors Phase 3's migration 0084 (eval categories): walk every row in
 * each source table, call `__($label, 'talenttrack')` to resolve the
 * canonical Dutch translation through gettext, and `INSERT IGNORE` a
 * `nl_NL` row into `tt_translations` when gettext returns a different
 * string. One migration covers both `tt_roles` and `tt_functional_roles`
 * since the two entities share the same shape (per spec § Phase 4).
 *
 * For each source row:
 *   - Look up the canonical English label via `__()`.
 *   - If gettext returns the input unchanged → skip (operator-authored
 *     custom roles have no `.po` match; their label is already the
 *     Dutch operators want to read).
 *   - If gettext returns a different string → that's the Dutch
 *     translation; insert a `(club_id, '<entity>', $id, 'label',
 *     'nl_NL', <translated>)` row.
 *
 * Idempotent — `INSERT IGNORE` against the unique index makes re-runs
 * no-ops, and operator-edited rows from a future Phase 5 Translations
 * tab survive untouched.
 *
 * Phase 6 cleanup will prune the migrated msgids from `nl_NL.po`.
 * Until then, the resolver chain on each helper is `tt_translations →
 * __() → fallback`, so this backfill is additive — no behaviour
 * change if it doesn't run.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0085_backfill_role_translations';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $translations_table = "{$p}tt_translations";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        // Make sure the talenttrack textdomain is loaded so `__()`
        // resolves against `nl_NL.po`. Migrations sometimes run very
        // early in the request lifecycle (plugin activation, wp-cli
        // bootstrapping) before the textdomain has loaded itself.
        if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
            load_plugin_textdomain( 'talenttrack', false, dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages' );
        }

        $this->backfill( "{$p}tt_roles", 'role' );
        $this->backfill( "{$p}tt_functional_roles", 'functional_role' );
    }

    private function backfill( string $source_table, string $entity_type ): void {
        global $wpdb;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $source_table ) ) !== $source_table ) return;

        // `tt_roles` doesn't carry a club_id column today (it's a
        // global authorization table); `tt_functional_roles` does.
        // Detect at runtime so the SELECT works against either shape.
        $has_club_id = (bool) $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$source_table} LIKE %s",
            'club_id'
        ) );

        $select = $has_club_id ? 'id, club_id, label' : 'id, label';

        $rows = $wpdb->get_results(
            "SELECT {$select}
               FROM {$source_table}
              WHERE label IS NOT NULL AND label <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$wpdb->prefix}tt_translations
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";

        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $club_id = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            $row_id  = (int) ( $row['id'] ?? 0 );
            $label   = (string) ( $row['label'] ?? '' );
            if ( $row_id <= 0 || $label === '' ) continue;

            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
            $translated = (string) __( $label, 'talenttrack' );
            if ( $translated === '' || $translated === $label ) continue;

            $wpdb->query( $wpdb->prepare(
                $sql,
                $club_id,
                $entity_type,
                $row_id,
                'label',
                'nl_NL',
                $translated,
                $now
            ) );
        }
    }
};
