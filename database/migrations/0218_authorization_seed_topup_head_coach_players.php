<?php
/**
 * Migration 0218 — grant head_coach `players:change [team]` (#2584).
 *
 * The seed now gives head_coach `players => [ 'rc', 'team' ]` where it
 * previously held `r`. Without this top-up the tuple exists in the seed file
 * but not in the live matrix, so `tt_edit_players` (which the cap bridge maps
 * to `players:change`) keeps resolving to false and the grant never takes
 * effect on an existing install.
 *
 * Deliberately scoped to the ONE new tuple — (head_coach, players, change,
 * team) — rather than re-syncing the whole `players` entity, matching
 * `0179_authorization_seed_topup_tournaments` and
 * `0165_authorization_seed_topup_my_evaluations_panel`. Re-adding every row
 * for the entity would resurrect grants an operator deliberately removed.
 *
 * assistant_coach is untouched: it keeps `players [r, team]`. Both coach
 * personas are backed by the same `tt_coach` WordPress role, so the matrix is
 * the only layer that can tell them apart — which is exactly why the grant is
 * made here and not by handing `tt_edit_players` to the role.
 *
 * Idempotent. `INSERT IGNORE` against the unique (persona, entity, activity,
 * scope_kind) key leaves an operator-edited row untouched and only adds the
 * missing tuple.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0218_authorization_seed_topup_head_coach_players';
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

        $written = 0;

        foreach ( $rows as $row ) {
            if ( ( $row['persona'] ?? '' ) !== 'head_coach' )   continue;
            if ( ( $row['entity'] ?? '' ) !== 'players' )       continue;
            if ( ( $row['activity'] ?? '' ) !== 'change' )      continue;
            if ( ( $row['scope_kind'] ?? '' ) !== 'team' )      continue;

            $ok = $wpdb->query( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
            if ( $ok === 1 ) $written++;
        }

        Logger::info( 'migration.0218.summary', [ 'rows_written' => $written ] );
    }
};
