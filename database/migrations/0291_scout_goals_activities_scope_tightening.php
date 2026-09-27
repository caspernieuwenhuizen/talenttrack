<?php
/**
 * Migration 0291 — scout `goals` and `activities` read narrowed from
 * global to player scope (#3972).
 *
 * The scout block had already drawn the line three times: evaluations
 * (0154, #1378), media (#2591) and players (0285, #3807) all moved from
 * academy-wide to the players a scout is linked to. Goals and activities
 * were left global. A player's development goals and the list of trainings
 * and matches they took part in say as much about a child as an evaluation
 * does, so they move onto the same footing: readable for the players the
 * scout is linked to (an active trial-case panel seat or the scout
 * assignment list), and for nobody else.
 *
 * Visit planning is unaffected: it runs on `scouting_visits_panel`,
 * `test_trainings` and `prospects`, which stay global.
 *
 * Same conservative shape as 0154 and 0285: only `is_default = 1` rows are
 * touched, so an operator who widened either grant through the
 * Authorization admin keeps their row. When a player-scoped row already
 * exists (a re-run, or an operator who added one), the default global row
 * is dropped instead of updated onto the unique key. Idempotent,
 * forward-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0291_scout_goals_activities_scope_tightening';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_authorization_matrix is missing' );
            return;
        }

        $written = 0;
        $notes   = [];
        foreach ( [ 'goals', 'activities' ] as $entity ) {
            $has_player_row = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(1) FROM {$table}
                  WHERE persona = 'scout' AND entity = %s
                    AND activity = 'read' AND scope_kind = 'player'",
                $entity
            ) );

            if ( $has_player_row > 0 ) {
                $n = $this->exec( $wpdb->prepare(
                    "DELETE FROM {$table}
                      WHERE persona = 'scout' AND entity = %s
                        AND activity = 'read' AND scope_kind = 'global'
                        AND is_default = 1",
                    $entity
                ) );
                $notes[] = $entity . ': ' . ( $n > 0 ? 'dropped the default global row' : 'already player-scoped' );
            } else {
                $n = $this->exec( $wpdb->prepare(
                    "UPDATE {$table}
                        SET scope_kind = 'player'
                      WHERE persona = 'scout' AND entity = %s
                        AND activity = 'read' AND scope_kind = 'global'
                        AND is_default = 1",
                    $entity
                ) );
                $notes[] = $entity . ': ' . ( $n > 0 ? 'narrowed the default global row' : 'no default global row (customised or absent)' );
            }
            $written += $n;
        }

        // #3854 — say which case this was; zero is either the healthy
        // repeat run or an install whose rows an operator customised.
        $this->report( $written, implode( '; ', $notes ) );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Reverting would re-open academy-wide read on every
        // child's goals and activity history. An operator who needs a scout
        // to see more widens it through the Authorization admin, which writes
        // `is_default = 0` and is left alone by this migration.
    }
};
