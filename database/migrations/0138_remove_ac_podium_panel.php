<?php
/**
 * Migration 0138 — Remove `podium_panel` from the assistant_coach default
 * matrix (#1105).
 *
 * Scope creep follow-up to migration 0136 (#1060). Podium surfaces the
 * top-rated players on a team based on aggregated evaluation data — the
 * same development-judgment data #1060 stripped from AC. Leaving
 * `podium_panel` granted made the Podium tile render for AC users and
 * link to a leaderboard surface populated from `evaluations` rows AC
 * can no longer read; either the tile led to an empty surface or — on
 * an install where the surface gates on a different cap path — leaked
 * the leaderboard ranking itself.
 *
 * Mirrors migration 0136 in shape:
 *
 *   - Conservative: only deletes `is_default = 1` rows so an academy
 *     that deliberately granted podium to AC via the Authorization
 *     admin keeps the override.
 *   - Forward-only: reverting would re-introduce the same scope creep.
 *   - Idempotent: re-running on already-trimmed installs is a no-op.
 *   - Flushes `MatrixRepository::clearCache()` so in-flight AC sessions
 *     pick up the change on their next request.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0138_remove_ac_podium_panel';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
              WHERE persona    = %s
                AND entity     = %s
                AND is_default = 1",
            'assistant_coach',
            'podium_panel'
        ) );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Reverting would re-introduce the leaderboard
        // visibility this migration is closing. Operators who need
        // podium for AC should grant explicitly via the Authorization
        // admin (is_default = 0).
    }
};
