<?php
/**
 * Migration 0184 — performance indexes for hot player-scoped filters (#1964).
 *
 * Final slice of the performance umbrella (#1649). Adds the two indexes the
 * existing schema is genuinely missing for the academy's hottest read paths.
 * Every other index the targeted queries want is already present from an
 * earlier migration, so this migration is deliberately small.
 *
 *  - tt_evaluations (player_id, club_id) — the eval-trend read in
 *    QueryHelpers::... (`SELECT id, eval_date FROM tt_evaluations
 *    WHERE player_id = %d AND club_id = %d ORDER BY eval_date DESC`) and the
 *    single-eval load both filter on player_id + club_id. The table carries
 *    single-column idx_player(player_id) and idx_club_id(club_id) (from the
 *    initial schema + the 0038 tenancy scaffold) but no composite, so MySQL
 *    can only seek on one of the two columns and filters the rest as a
 *    residual. The composite lets the optimiser resolve both equality
 *    predicates from one index seek.
 *
 *  - tt_attendance (guest_player_id) — the player-dashboard attendance read
 *    (PlayerDashboardView, `WHERE a.player_id = %d OR a.guest_player_id = %d`)
 *    has a two-branch OR across two columns. player_id already carries
 *    idx_player; guest_player_id had no index, so that branch table-scanned
 *    and defeated any index on the other branch. Indexing guest_player_id
 *    lets MySQL index-merge (union) the two single-column lookups instead of
 *    scanning the whole table. No query change is needed — the index-merge
 *    keeps the read byte-identical.
 *
 * Deliberately NOT added (verified already present in the schema history):
 *  - tt_evaluations.player_id        — idx_player (0001 initial schema)
 *  - tt_evaluations.club_id          — idx_club_id (0038 tenancy scaffold)
 *  - tt_attendance.player_id         — idx_player (0001 initial schema)
 *  - tt_attendance.activity_id       — idx_activity (0027 rename) +
 *                                      idx_activity_record_type (0121) +
 *                                      idx_activity_guest (0027)
 *  - tt_goals.player_id              — idx_player (0001 initial schema)
 *
 * Idempotent — each index add is gated on INFORMATION_SCHEMA.STATISTICS, so
 * re-running is a no-op. Forward-only (additive secondary indexes, no data
 * change).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0184_perf_hot_filter_indexes';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Composite for the player_id + club_id eval filter. player_id leads
        // because it is the more selective of the two predicates (club_id is
        // 1 for the whole single-tenant install today).
        $this->addIndexIfMissing(
            $p . 'tt_evaluations',
            'idx_player_club',
            '(player_id, club_id)'
        );

        // Index the second branch of the attendance OR so MySQL can
        // index-merge it with the existing idx_player rather than scan.
        $this->addIndexIfMissing(
            $p . 'tt_attendance',
            'idx_guest_player',
            '(guest_player_id)'
        );
    }

    /**
     * Add a secondary index only when neither the table is absent nor the
     * index already present. Guarded on INFORMATION_SCHEMA so re-runs and
     * fresh-install activator paths are both safe.
     *
     * @param string $table   Fully-prefixed table name.
     * @param string $index   Index name.
     * @param string $columns Parenthesised column list, e.g. '(a, b)'.
     */
    private function addIndexIfMissing( string $table, string $index, string $columns ): void {
        global $wpdb;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND INDEX_NAME = %s",
            $table, $index
        ) );
        if ( $exists === 0 ) {
            $this->exec( "ALTER TABLE `{$table}` ADD KEY `{$index}` {$columns}" );
        }
    }
};
