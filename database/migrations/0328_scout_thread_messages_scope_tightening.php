<?php
/**
 * Migration 0328 — scout `thread_messages` read narrowed from global to
 * player scope (#5059).
 *
 * A global `thread_messages` grant is the academy-wide conversation
 * administrator's rung in `ThreadAccess::hasGlobalAccess()`, and that rung
 * reads staff-only notes. The scout persona holds no staff-only right, so
 * its grant moves onto the footing of its evaluations, media, goals and
 * activities rows: player scope, the players the scout is linked to.
 *
 * Same conservative shape as 0154, 0285 and 0291: only the scout's
 * `is_default = 1` row is touched, so an operator who widened the grant
 * through the Authorization admin keeps their row. When a player-scoped row
 * already exists (a re-run, or an operator who added one), the default
 * global row is dropped instead of updated onto the unique key. Idempotent,
 * forward-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0328_scout_thread_messages_scope_tightening';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_authorization_matrix is missing' );
            return;
        }

        $has_player_row = (int) $wpdb->get_var(
            "SELECT COUNT(1) FROM {$table}
              WHERE persona = 'scout' AND entity = 'thread_messages'
                AND activity = 'read' AND scope_kind = 'player'"
        );

        if ( $has_player_row > 0 ) {
            $written = $this->exec(
                "DELETE FROM {$table}
                  WHERE persona = 'scout' AND entity = 'thread_messages'
                    AND activity = 'read' AND scope_kind = 'global'
                    AND is_default = 1"
            );
            $note = $written > 0 ? 'dropped the default global row' : 'already player-scoped';
        } else {
            $written = $this->exec(
                "UPDATE {$table}
                    SET scope_kind = 'player'
                  WHERE persona = 'scout' AND entity = 'thread_messages'
                    AND activity = 'read' AND scope_kind = 'global'
                    AND is_default = 1"
            );
            $note = $written > 0 ? 'narrowed the default global row' : 'no default global row (customised or absent)';
        }

        $this->report( $written, 'thread_messages: ' . $note );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Reverting would hand scouts the academy-wide
        // conversation rung again, staff-only notes included. An operator
        // who wants a scout to read more widens it through the Authorization
        // admin, which writes `is_default = 0` and is left alone here.
    }
};
