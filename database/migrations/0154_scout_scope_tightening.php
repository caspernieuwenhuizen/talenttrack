<?php
/**
 * Migration 0154 — Scout scope tightening (#1378).
 *
 * The scout persona held the widest sensitive-data grant in the
 * matrix: read-global on every player's evaluations, PDP files and
 * promote/release verdicts. Per the 2026-06-11 decision:
 *
 *   - pdp_file / pdp_verdict: removed entirely — release deliberations
 *     are not scouting inputs.
 *   - evaluations: read scope narrowed global → player (players linked
 *     to the scout via trial/prospect assignment).
 *
 * Seed updated in the same release for fresh installs; this backfills
 * existing matrices. Mirrors the 0136 assistant-coach pattern:
 * **conservative** — only `is_default = 1` rows are touched, so
 * operator-customised grants survive.
 *
 * Idempotent; forward-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0154_scout_scope_tightening';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $wpdb->query(
            "DELETE FROM {$table}
              WHERE persona = 'scout'
                AND entity IN ('pdp_file', 'pdp_verdict')
                AND is_default = 1"
        );

        // Narrow evaluations read to player scope. The unique key
        // covers (persona, entity, activity, scope_kind), so when a
        // player-scoped row already exists (re-run, or operator added
        // one) the global row is dropped instead of updated.
        $has_player_row = (int) $wpdb->get_var(
            "SELECT COUNT(1) FROM {$table}
              WHERE persona = 'scout' AND entity = 'evaluations'
                AND activity = 'read' AND scope_kind = 'player'"
        );
        if ( $has_player_row > 0 ) {
            $wpdb->query(
                "DELETE FROM {$table}
                  WHERE persona = 'scout' AND entity = 'evaluations'
                    AND activity = 'read' AND scope_kind = 'global'
                    AND is_default = 1"
            );
        } else {
            $wpdb->query(
                "UPDATE {$table}
                    SET scope_kind = 'player'
                  WHERE persona = 'scout' AND entity = 'evaluations'
                    AND activity = 'read' AND scope_kind = 'global'
                    AND is_default = 1"
            );
        }

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Reverting would re-open academy-wide read on
        // minors' evaluations and release verdicts. Operators who need
        // a scout to see more use the Authorization admin (is_default=0).
    }
};
