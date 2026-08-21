<?php
/**
 * Migration 0220 — grant head_coach `player_injuries:change [team]` (#2609).
 *
 * The seed now gives head_coach `player_injuries => [ 'rc', 'team' ]` where it
 * previously held `r`. Without this top-up the tuple exists in the seed file
 * but not in the live matrix, so the new injury capture surface would render
 * for a head coach and then be refused by the API.
 *
 * Deliberately scoped to the ONE new tuple — (head_coach, player_injuries,
 * change, team) — rather than re-syncing the whole entity, matching
 * `0218_authorization_seed_topup_head_coach_players` and its predecessors.
 * Re-adding every row for the entity would resurrect grants an operator
 * deliberately removed.
 *
 * `create_delete` is NOT granted: deleting a minor's medical record stays with
 * the head of development and the academy admin. assistant_coach is untouched
 * and still holds no `player_injuries` row at all — both coach personas are
 * backed by the same `tt_coach` WordPress role, so the matrix is the only
 * layer that can tell them apart, which is exactly why this is granted here
 * and not by handing a capability to the role.
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
        return '0220_authorization_seed_topup_head_coach_injuries';
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
            if ( ( $row['persona'] ?? '' ) !== 'head_coach' )      continue;
            if ( ( $row['entity'] ?? '' ) !== 'player_injuries' )  continue;
            if ( ( $row['activity'] ?? '' ) !== 'change' )         continue;
            if ( ( $row['scope_kind'] ?? '' ) !== 'team' )         continue;

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

        Logger::info( 'migration.0220.summary', [ 'rows_written' => $written ] );
    }
};
