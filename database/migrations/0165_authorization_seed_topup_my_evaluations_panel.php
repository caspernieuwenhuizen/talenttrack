<?php
/**
 * Migration: 0165_authorization_seed_topup_my_evaluations_panel
 *
 * #1482 follow-up — backfill the `my_evaluations_panel` tile-visibility
 * entity that the seed now grants to player (r[self]) and parent
 * (r[player]). Same pattern as
 * `0064_authorization_seed_topup_my_pdp_panel`: walk the seed and
 * `INSERT IGNORE` the rows the matrix doesn't have yet.
 *
 * Why this is needed:
 *   - The Me-group "My evaluations" tile previously used the data entity
 *     `my_evaluations`, which assistant / head coaches also hold at self
 *     scope for their authored-evals feed (`/evaluations/recent` + the
 *     "evaluations this week" KPI). With matrix-as-truth, that grant
 *     surfaced the player-self "My evaluations" tile on a coach's
 *     dashboard.
 *   - The fix disambiguates the tile via a new `my_evaluations_panel`
 *     entity granted only to player + parent. Without this migration the
 *     entity exists in the seed file but not in `tt_authorization_matrix`,
 *     so the tile gate returns false for everyone — the player surface
 *     disappears for the people who legitimately use it.
 *
 * Scoped to the new entity only (unlike 0064's whole-seed walk) so it
 * never re-adds rows an operator deliberately removed for other entities.
 *
 * Idempotent. INSERT IGNORE on the unique key (persona, entity,
 * activity, scope_kind) leaves any operator-edited rows untouched
 * and only adds the new tuples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0165_authorization_seed_topup_my_evaluations_panel';
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
            if ( ( $row['entity'] ?? '' ) !== 'my_evaluations_panel' ) {
                continue;
            }
            $wpdb->query( $wpdb->prepare(
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
