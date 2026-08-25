<?php
/**
 * Migration 0235 — assists and unattributed goals (#2856).
 *
 * `tt_match_execution_goal_events` recorded a scorer and a minute and
 * nothing else, and the write path refused any of our goals that did not
 * name a scorer. Two gaps followed from that.
 *
 * The first is the assist. A goal has a finisher and, usually, a creator,
 * and only one of them was representable. For an academy that is the
 * wrong half to keep: the pass that opened the defence is often the more
 * developed piece of play.
 *
 * The second is the refusal. A coach on the touchline does not always
 * know who got the final touch, and an own goal has no scorer on the
 * side that benefits from it. With no way to record either, the only
 * remaining affordance was the free score stepper — which recorded a
 * number and no event at all, and is exactly how the scoreline and the
 * goal log came to disagree.
 *
 *   assist_player_id   who created the goal; NULL when nobody did, or
 *                      when nobody recorded who
 *   is_own_goal        whether the goal was put in by the side it
 *                      counts against
 *
 * `player_id` keeps its NOT NULL and gains a meaning it did not have
 * before: `0` is "no scorer recorded". The column already stored 0 for
 * every opponent goal (`logGoalEvent` writes `max(0, $player_id)`), so
 * this widens an existing convention rather than introducing one, and
 * needs no rewrite of the rows already there.
 *
 * Read together with `team`, the three columns say which side a goal
 * counted for and who — if anyone — it is attributable to:
 *
 *   home / >0 / 0    our player scored
 *   home / 0  / 0    our goal, scorer not recorded
 *   home / 0  / 1    opponent own goal, counts for us
 *   away / 0  / 0    opponent goal (their squad is not in the system)
 *   away / >0 / 1    our player put it into our own net
 *
 * Additive and idempotent. Existing rows read back as an attributed
 * regular goal with no assist, which is what they were.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0235_match_execution_goal_attribution';
    }

    public function up(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_match_execution_goal_events';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'assist_player_id', 'BIGINT UNSIGNED DEFAULT NULL', 'player_id' );
        MigrationHelpers::addColumnIfMissing( $table, 'is_own_goal', 'TINYINT(1) NOT NULL DEFAULT 0', 'assist_player_id' );

        // The assist lookup mirrors the existing idx_exec_player: the
        // per-player contribution query filters on it the same way.
        $has_index = $wpdb->get_var( $wpdb->prepare(
            "SHOW INDEX FROM `$table` WHERE Key_name = %s",
            'idx_exec_assist'
        ) );
        if ( ! $has_index ) {
            $this->exec( "ALTER TABLE `$table` ADD KEY `idx_exec_assist` (`execution_id`, `assist_player_id`)" );
        }
    }
};
