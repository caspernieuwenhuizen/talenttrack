<?php
/**
 * Migration 0143 — Remove `rate_cards` + `compare` from the
 * assistant_coach default matrix (#1106).
 *
 * Per-entity audit follow-up to migrations 0136 (#1060) + 0138 (#1105).
 * Both entities aggregate development-judgment data #1060 stripped
 * from AC:
 *
 *   - `rate_cards` — leaderboard derived from aggregated
 *     `tt_eval_ratings`. Same loophole #1105 closed for `podium_panel`.
 *   - `compare` — side-by-side player comparison on rating, position,
 *     PDP and behaviour signals. Every primary axis is dev data.
 *
 * The other three entities pilot flagged in the audit (`people`,
 * `reports`, `vct`) stay — `people` is the staff directory
 * (operational, not safeguarding), `reports` is a surface gate that
 * upstreams per-report cap checks, and `vct` (Voetbal Conditionele
 * Training) is operational session planning AC shares with HC by spec.
 *
 * Mirrors migration 0138 in shape:
 *
 *   - Conservative: only deletes `is_default = 1` rows so an academy
 *     that deliberately granted either entity via the Authorization
 *     admin keeps the override.
 *   - Forward-only: reverting would re-introduce the same leakage.
 *   - Idempotent: re-running on already-trimmed installs is a no-op.
 *   - Flushes `MatrixRepository::clearCache()` so in-flight AC
 *     sessions pick up the change on their next request.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0143_remove_ac_rate_cards_compare';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Two-row DELETE in a single statement so the cache flush below
        // runs once. `IN (...)` shape mirrors 0138's single-entity
        // pattern at minimal cost.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
              WHERE persona    = %s
                AND entity IN ( %s, %s )
                AND is_default = 1",
            'assistant_coach',
            'rate_cards',
            'compare'
        ) );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Reverting would re-introduce the leakage this
        // migration closes. Operators who need either entity for AC
        // should grant explicitly via the Authorization admin
        // (`is_default = 0`).
    }
};
