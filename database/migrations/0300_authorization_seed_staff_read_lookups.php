<?php
/**
 * Migration 0300 — staff personas read the club lookups (#4296, D-15).
 *
 * `GET lookups` and `GET lookups/{type}` now gate on `tt_view_lookups`
 * (`lookups: read`) instead of the `tt_view_settings` roll-up, and the
 * seed grants that read to the head coach, assistant coach, staff and
 * scout personas: the people who write injuries and other records whose
 * fields are lookup ids. The head of development and the academy admin
 * already held it.
 *
 * The matrix reseed never runs on upgrade, so existing installs would
 * not gain the new rows and those personas would keep getting 403. This
 * top-up adds them, on the 0194 pattern.
 *
 * Idempotent: INSERT IGNORE on the unique key (persona, entity, activity,
 * scope_kind) leaves operator-edited rows alone and only adds what is
 * missing. Scoped to the `lookups` entity AND the four personas, so it
 * never touches another entity or persona, and it only adds rows.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'lookups' ];
    private const PERSONAS = [ 'head_coach', 'assistant_coach', 'staff', 'scout' ];

    public function getName(): string {
        return '0300_authorization_seed_staff_read_lookups';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            if ( ! in_array( $row['entity'] ?? '', self::ENTITIES, true ) ) {
                continue;
            }
            if ( ! in_array( $row['persona'] ?? '', self::PERSONAS, true ) ) {
                continue;
            }
            $this->exec( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }
    }
};
