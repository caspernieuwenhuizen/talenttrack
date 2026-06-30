<?php
/**
 * Migration 0192 — Status value type + operator-defined coloured levels
 * (#2138, epic #2116).
 *
 * A status-type measurement is a manually maintained, dated player status
 * (a stopgap until the computed player_status calculator #0057 is good
 * enough). It rides the measurement framework so it inherits history,
 * profile surfacing and the archive/recycle plumbing for free.
 *
 * Three additive, idempotent changes:
 *
 *   1. Extend tt_measurement_definitions.value_type ENUM with 'status'
 *      (alongside numeric / scale / passfail). Guarded on the live column
 *      definition so a re-run is a no-op.
 *   2. Create tt_measurement_levels — the operator-defined, colour-tagged
 *      levels of a status test (e.g. "On track" green, "Watch" amber,
 *      "At risk" red). Carries the tenancy scaffold (club_id + uuid) and
 *      the archive/soft-delete pair, matching tt_measurement_definitions.
 *   3. Seed a "Player status" measurement_category lookup + its Dutch /
 *      English labels in tt_translations (never the dropped JSON column).
 *
 * Forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0192_measurement_status_levels';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $this->extendValueTypeEnum();

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_measurement_levels (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                definition_id BIGINT UNSIGNED NOT NULL,
                label VARCHAR(190) NOT NULL,
                color_token VARCHAR(40) NOT NULL DEFAULT 'grey',
                ordinal INT NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                archived_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                KEY idx_club (club_id),
                KEY idx_definition (definition_id)
            ) {$charset}"
        );

        $this->seedStatusCategory();
    }

    /**
     * Add 'status' to the value_type ENUM if it is not already a member.
     * Reads the live column type so a re-run (or an install that already
     * has it) is a no-op rather than a redundant ALTER.
     */
    private function extendValueTypeEnum(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = $p . 'tt_measurement_definitions';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'value_type'
        ) );
        if ( ! $col || ! isset( $col->Type ) ) {
            return;
        }
        if ( strpos( (string) $col->Type, "'status'" ) !== false ) {
            return; // already extended
        }

        $this->exec(
            "ALTER TABLE {$table}
                MODIFY COLUMN value_type
                ENUM('numeric','scale','passfail','status')
                NOT NULL DEFAULT 'numeric'"
        );
    }

    /**
     * Seed the "Player status" measurement_category lookup + its labels.
     * Existence-checked on (club_id, lookup_type, name); INSERT IGNORE on
     * the translations natural key — idempotent on re-run.
     */
    private function seedStatusCategory(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $lookups = $p . 'tt_lookups';
        $trans   = $p . 'tt_translations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $trans ) ) !== $trans ) return;

        $club_id = 1;
        $now     = current_time( 'mysql', true );
        $type    = 'measurement_category';
        $name    = 'Player status';
        $labels  = [ 'en_US' => 'Player status', 'nl_NL' => 'Spelersstatus' ];

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$lookups}
              WHERE club_id = %d AND lookup_type = %s AND name = %s LIMIT 1",
            $club_id, $type, $name
        ) );

        if ( $existing_id <= 0 ) {
            $wpdb->insert( $lookups, [
                'club_id'     => $club_id,
                'lookup_type' => $type,
                'name'        => $name,
                'sort_order'  => 50,
            ] );
            $lookup_id = (int) $wpdb->insert_id;
        } else {
            $lookup_id = $existing_id;
        }
        if ( $lookup_id <= 0 ) return;

        foreach ( $labels as $locale => $value ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$trans}
                   (club_id, entity_type, entity_id, field, locale, value, updated_at)
                 VALUES (%d, 'lookup', %d, 'name', %s, %s, %s)",
                $club_id, $lookup_id, $locale, $value, $now
            ) );
        }
    }
};
