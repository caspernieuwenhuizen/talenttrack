<?php
/**
 * Migration 0246 — goals without a live match sheet (#3094).
 *
 * `GoalContributionQuery` (#2859) reads goals and assists from
 * `tt_match_execution_goal_events`, and the live match sheet is the only
 * thing that writes that table. A club doing its post-match admin on a
 * Sunday evening rather than running a stopwatch on the touchline therefore
 * has players whose minutes are complete and whose output is permanently
 * blank — on the player record, in the reports, and in everything built on
 * top of them.
 *
 * The plugin measures a player's exposure (minutes) and a coach's judgement
 * (evaluations, analysis). Their output is the third leg, and until now it
 * existed only for clubs who run matches live.
 *
 * WHY WIDEN THIS TABLE RATHER THAN ADD A SECOND ONE
 *
 * One store for every goal in the system. `GoalContributionQuery`, the
 * player timeline and the reports keep working with a changed JOIN and no
 * UNION; a parallel table would mean every present and future reader
 * remembering to look in two places, and the first one that forgot would
 * silently under-report a child's season.
 *
 * WHAT A MANUAL GOAL LOOKS LIKE
 *
 * `execution_id NULL`, `half NULL`, `minute_in_half NULL`. **Inventing a
 * minute would be a lie** — the coach does not know it, and a fabricated
 * 45' would flow straight into the match timeline as though someone had
 * watched it happen. Anything reading `minute_in_half` therefore has to
 * tolerate NULL, which is a smaller cost than data that reads as observed
 * when it was remembered.
 *
 * THE NAME IS NOW A MISNOMER
 *
 * `tt_match_execution_goal_events` holds goals that never belonged to an
 * execution. Renaming it is a bigger job than this issue — every reader,
 * every fixture, a data migration — and is left undone deliberately rather
 * than by oversight. Stated here so the next person to notice knows it was
 * seen.
 *
 * Additive and idempotent: `addColumnIfMissing` per 0196's docblock (never
 * `dbDelta` on a pre-existing table), `makeColumnNullable` no-ops when the
 * column already allows NULL, and the backfill only touches rows that still
 * carry `activity_id = 0`.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0246_goal_events_activity_scope';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_match_execution_goal_events';

        if ( ! MigrationHelpers::addColumnIfMissing(
            $table,
            'activity_id',
            'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'club_id'
        ) ) {
            throw new \RuntimeException( '0246: failed to add activity_id to tt_match_execution_goal_events' );
        }

        // The three columns a manually recorded goal cannot honestly fill.
        MigrationHelpers::makeColumnNullable( $table, 'execution_id', 'BIGINT UNSIGNED NULL' );
        MigrationHelpers::makeColumnNullable( $table, 'half', 'TINYINT UNSIGNED NULL' );
        MigrationHelpers::makeColumnNullable( $table, 'minute_in_half', 'SMALLINT UNSIGNED NULL' );

        $this->addIndexIfMissing( $table, 'idx_activity_player', '(activity_id, player_id)' );

        // Backfill: every goal logged so far came through an execution, and
        // that execution knows its activity. Guarded on activity_id = 0 so a
        // re-run cannot touch a row that already resolved.
        $execs   = $wpdb->prefix . 'tt_match_execution';
        $updated = $wpdb->query(
            "UPDATE {$table} g
               JOIN {$execs} e ON e.id = g.execution_id
                SET g.activity_id = e.activity_id
              WHERE g.activity_id = 0"
        );

        Logger::info( 'migration.0246.summary', [
            'backfilled' => is_int( $updated ) ? $updated : 0,
        ] );
    }

    /**
     * `ADD INDEX` has no `IF NOT EXISTS` on the MySQL versions this plugin
     * supports, so the check is a `SHOW INDEX` rather than a swallowed error
     * — a migration that fails loudly on a real problem is worth more than
     * one that ignores every problem to survive a second run.
     */
    private function addIndexIfMissing( string $table, string $index, string $columns ): void {
        global $wpdb;

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
            $index
        ) );
        if ( $exists !== null ) return;

        $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `{$index}` {$columns}" );
    }
};
