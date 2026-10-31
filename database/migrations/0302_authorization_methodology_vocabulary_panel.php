<?php
/**
 * Migration 0302 — the methodology vocabulary tile gets its own panel
 * entity (#4391).
 *
 * The editor tile was registered on `methodology`, which the head coach,
 * the assistant coach and the scout read. With the matrix active a tile
 * shows to anyone who reads its entity, so those three saw a tile whose
 * view refuses them: it requires `tt_edit_methodology`. Their read is
 * load-bearing elsewhere (the Methodology read tile, the REST reads), so
 * it stays. Tile visibility moves to `methodology_vocabulary_panel`,
 * seeded to the personas who may edit the methodology — the
 * `holidays_panel` resolution of 0234.
 *
 * The matrix reseed never runs on upgrade, so existing installs would not
 * gain the new rows and the head of development and the academy admin
 * would lose the tile. This top-up adds them.
 *
 * Idempotent: INSERT IGNORE on the unique key (persona, entity, activity,
 * scope_kind) only adds what is missing. Scoped to the new entity; it
 * adds rows and deletes none. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** Tile-visibility entity to seed from config/authorization_seed.php. */
    private const ADD_ENTITIES = [ 'methodology_vocabulary_panel' ];

    public function getName(): string {
        return '0302_authorization_methodology_vocabulary_panel';
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

        $insert = "INSERT IGNORE INTO {$table}
                     (persona, entity, activity, scope_kind, module_class, is_default)
                   VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            if ( ! in_array( $row['entity'] ?? '', self::ADD_ENTITIES, true ) ) {
                continue;
            }
            $this->exec( $wpdb->prepare(
                $insert,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }
    }
};
