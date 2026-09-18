<?php
/**
 * Migration 0268 — per-fixture results on `tt_tournament_matches` (#3532,
 * epic #3529).
 *
 * A tournament day with five fixtures had nowhere to record any of their
 * results. The table carried sequence, label, opponent, formation, duration,
 * substitution windows and timestamps — and no score columns at all, so a
 * tournament read in the product as attendance and minutes with no outcome
 * attached.
 *
 * WHY NOT THE ACTIVITY-LEVEL SCORELINE
 *
 * #2686 and #3519 both ruled that a tournament is a multi-game day and one
 * score line cannot describe it, which is why a tournament-typed activity
 * deliberately gets no Result card and no score boxes in the minutes grid.
 * That decision stands; it is exactly what left this gap, and the gap belongs
 * here rather than being closed by weakening the rule.
 *
 * WHY `our_score` / `their_score`
 *
 * #3529 decision 3 ruled `home_score` / `away_score` misnomers — a fixture at
 * a tournament has no home leg to speak of — so this does not repeat them.
 * Deliberately not `team_score` / `opponent_score` either, despite
 * `TeamMatchStatsQuery` returning that pair: that query *frames* a stored
 * home/away row from the academy's side, and naming the stored columns after
 * the derived shape would suggest the two are the same thing.
 *
 * NULLABLE, NO DEFAULT
 *
 * Empty is not 0-0 (#3529 decision 7, #3519). A fixture with no result stores
 * NULL and is counted as played-without-a-result; a default of 0 would record
 * a goalless draw for every fixture ever created, including the four that have
 * not kicked off yet.
 *
 * `club_id` is already on the table. Additive + idempotent through
 * MigrationHelpers::addColumnIfMissing, so a re-run is a no-op. Forward-only.
 * Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0268_tournament_match_scores';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_tournament_matches';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'our_score', 'TINYINT UNSIGNED DEFAULT NULL', 'notes' );
        MigrationHelpers::addColumnIfMissing( $table, 'their_score', 'TINYINT UNSIGNED DEFAULT NULL', 'our_score' );
    }
};
