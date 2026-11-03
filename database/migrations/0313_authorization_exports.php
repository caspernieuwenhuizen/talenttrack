<?php
/**
 * Migration 0313 — the right to receive a file the server produces.
 *
 * `ExportGate::mayExport()` now stands in front of every download and every
 * mailed report: the export pipeline, the report and explorer CSV and PDF
 * streams, and the scheduled report deliveries. It asks the new matrix
 * entity `exports`. The seed grants it to every persona that downloads
 * today (player and parent at self scope, the staff persona at team scope,
 * every other staff persona at global scope: the right is about the
 * account, never wider than the file's own rule) and not to the read-only
 * observer. The matrix reseed
 * never runs on upgrade, so this adds the rows to existing installs; without
 * them every persona would lose its downloads.
 *
 * Idempotent: INSERT IGNORE on the unique key (persona, entity, activity,
 * scope_kind) only adds what is missing, and an operator-edited row is left
 * alone. Scoped to the one entity and the nine personas; the observer gets
 * nothing. Adds rows and deletes none. Run-alone (no other migration in
 * parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITY   = 'exports';
    private const PERSONAS = [
        'player',
        'parent',
        'assistant_coach',
        'head_coach',
        'team_manager',
        'scout',
        'head_of_development',
        'academy_admin',
        'staff',
    ];

    public function getName(): string {
        return '0313_authorization_exports';
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
            if ( (string) ( $row['entity'] ?? '' ) !== self::ENTITY ) continue;
            if ( ! in_array( (string) ( $row['persona'] ?? '' ), self::PERSONAS, true ) ) continue;

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
