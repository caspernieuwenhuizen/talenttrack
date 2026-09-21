<?php
/**
 * Migration 0285 — the last of the scout's three global reads (#3807).
 *
 * `scout => players` was read-global. The full player record carries
 * guardian name, e-mail and phone plus every custom field a club has
 * defined, with no per-field filter, so that grant handed a scout the
 * contact details of every family in the academy — including children
 * they hold no link to.
 *
 * This is the third pass over the same block, and the first two said why:
 *
 *   - 0154 (#1378) narrowed `evaluations` global → player, calling it
 *     "the widest sensitive-data grant in the matrix".
 *   - #2591 narrowed `media` for the same reason, in the same words:
 *     photographs of children are at least as sensitive as a judgment
 *     about them.
 *
 * Both passes left `players` alone. Family contact details are personal
 * data of identifiable adults attached to identifiable minors, and belong
 * on the same footing.
 *
 * The scout does not lose the work this grant was standing in for.
 * `GET /players/{id}/scout-card` ships in the same change: the squad
 * comparison a scout needs, as a closed field list with no family contact
 * on it, readable for the players they are actually linked to.
 *
 * Deliberately the **same conservative shape as 0154**: only
 * `is_default = 1` rows are touched, so an operator who widened this on
 * purpose through the Authorization admin keeps their row. Idempotent,
 * forward-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0285_scout_players_scope_tightening';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_authorization_matrix is missing' );
            return;
        }

        // The unique key covers (persona, entity, activity, scope_kind), so
        // when a player-scoped row is already there — a re-run, or an
        // operator who added one — the global row is dropped rather than
        // updated onto a collision.
        $has_player_row = (int) $wpdb->get_var(
            "SELECT COUNT(1) FROM {$table}
              WHERE persona = 'scout' AND entity = 'players'
                AND activity = 'read' AND scope_kind = 'player'"
        );

        if ( $has_player_row > 0 ) {
            $written = (int) $wpdb->query(
                "DELETE FROM {$table}
                  WHERE persona = 'scout' AND entity = 'players'
                    AND activity = 'read' AND scope_kind = 'global'
                    AND is_default = 1"
            );
            $note = 'player-scoped row already present; dropped the global one';
        } else {
            $written = (int) $wpdb->query(
                "UPDATE {$table}
                    SET scope_kind = 'player'
                  WHERE persona = 'scout' AND entity = 'players'
                    AND activity = 'read' AND scope_kind = 'global'
                    AND is_default = 1"
            );
            $note = 'narrowed the default global row to player scope';
        }

        // #3854 — say so either way. Zero here is the healthy repeat run OR
        // an install whose row an operator has already customised away from
        // the default, and the two are indistinguishable afterwards unless
        // the migration writes down which it saw.
        $this->report( $written, $note );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Reverting would re-open academy-wide read on every
        // family's contact details. An operator who genuinely needs a scout
        // to see more widens it through the Authorization admin, which
        // writes `is_default = 0` and is left alone by this migration.
    }
};
