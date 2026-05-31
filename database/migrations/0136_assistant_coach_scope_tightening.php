<?php
/**
 * Migration 0136 — Assistant Coach scope tightening (#1060).
 *
 * Removes per-player development-data rows from the AC matrix on
 * existing installs. The seed file (`config/authorization_seed.php`)
 * was updated in v4.16.0 to omit these entries for fresh installs;
 * this migration backfills the same change onto installs that
 * already have the looser AC defaults from earlier seeds.
 *
 * Scope (matches the seed change):
 *
 *   - evaluations                  (HC professional-judgment data)
 *   - pdp_file                     (safeguarding territory)
 *   - pdp_verdict
 *   - pdp_conversations
 *   - team_chemistry               (development analytics)
 *   - dev_ideas                    (development authoring)
 *   - player_behaviour_ratings     (behaviour, dev data)
 *   - evaluations_panel            (tile-visibility — no point with no read)
 *   - team_chemistry_panel
 *   - pdp_panel
 *
 * **Conservative removal**: only deletes rows where `is_default = 1`.
 * Operator-customised rows (`is_default = 0` — flipped via the
 * Authorization admin) are left untouched, so an academy that
 * deliberately granted AC broader access keeps it. The Authorization
 * admin's "Reset to defaults" button picks up the new seed shape on
 * next click.
 *
 * Flushes the matrix cache after the delete so AC sessions in flight
 * pick up the change on their next request without a hard reload.
 *
 * The `_self`-scoped variants of removed entities (e.g.
 * `pdp_calendar_export` at `self` scope) are NOT removed — those
 * remain operational (AC exports their OWN calendar slots etc.).
 *
 * Idempotent — re-running on already-tightened installs is a no-op.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0136_assistant_coach_scope_tightening';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Entities removed from the AC default matrix per #1060.
        $removed = [
            'evaluations',
            'pdp_file',
            'pdp_verdict',
            'pdp_conversations',
            'team_chemistry',
            'dev_ideas',
            'player_behaviour_ratings',
            'evaluations_panel',
            'team_chemistry_panel',
            'pdp_panel',
        ];

        $placeholders = implode( ',', array_fill( 0, count( $removed ), '%s' ) );
        $args = array_merge( [ 'assistant_coach' ], $removed );

        // Only delete seeded defaults — operator customisations
        // (is_default=0) stay so an academy that deliberately granted
        // AC broader access keeps it. Conservative path per the
        // 2026-05-31 lock.
        $sql = $wpdb->prepare(
            "DELETE FROM {$table}
              WHERE persona = %s
                AND entity IN ($placeholders)
                AND is_default = 1",
            $args
        );
        $wpdb->query( $sql );

        // Flush the read cache so AC sessions pick up the new shape
        // on their next request without a forced refresh.
        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Reverting would re-grant AC access to per-
        // player development data, which is the safeguarding regression
        // this migration is closing. Operators who need AC broader
        // access should use the Authorization admin to set rows
        // explicitly (is_default=0).
    }
};
