<?php
/**
 * Migration 0170 — enforce one WP account ↔ one player (#1772).
 *
 * `tt_players.wp_user_id` was indexed but not UNIQUE and defaulted to
 * `0` for "no account". Two players could therefore share a WP account,
 * and the derived-player scope resolver (a `LIMIT 1` / `get_col` over
 * `wp_user_id`) could then resolve to the WRONG child's record — a
 * safeguarding problem for minors. This mirrors the `tt_people` incident
 * that migration 0139 fixed; players had no equivalent guard.
 *
 * Three ordered steps, all idempotent:
 *
 *   1. Dedupe. Any `(club_id, wp_user_id)` shared by more than one
 *      player (wp_user_id > 0, ANY status — the UNIQUE index below
 *      spans archived rows too) is resolved by keeping one winner and
 *      nulling the `wp_user_id` on the losers. Losers are NOT deleted —
 *      they're real players, just unlinked. Each unlink is logged to
 *      `tt_audit_log` as `player.account_deduped`.
 *      Winner tiebreak (mirrors AuthorizationService's resolver order so
 *      migration and read agree): active over archived, then the row
 *      with the most evaluations, then highest id (newest).
 *
 *   2. Normalise "no account" from `0` to `NULL`. NULLs don't collide in
 *      a MySQL UNIQUE index, so every unlinked player coexists; only
 *      real accounts are constrained. This is also the SaaS-correct
 *      representation (a missing FK is NULL, not a magic 0).
 *
 *   3. Make the column nullable (default NULL) and add
 *      `UNIQUE (club_id, wp_user_id)` — the DB-layer guarantee.
 *
 * Forward-only. The runtime `delete_user` cleanup (WpUserUnlink) and the
 * write-paths that now store NULL for "no account" ship in the same PR.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0170_player_account_integrity';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $players_t = "{$p}tt_players";
        $evals_t   = "{$p}tt_evaluations";
        $audit_t   = "{$p}tt_audit_log";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $players_t ) ) !== $players_t ) return;
        // club_id is added by the tenancy scaffold (0038); without it the
        // composite key can't be built. Bail rather than build a wrong key.
        if ( ! $this->columnExists( $players_t, 'club_id' ) ) return;

        $this->dedupe( $players_t, $evals_t, $audit_t );

        // Step 2 — "no account" 0 → NULL (after dedupe, before UNIQUE).
        $wpdb->query( "UPDATE {$players_t} SET wp_user_id = NULL WHERE wp_user_id = 0" );

        // Step 3 — make nullable, then add the composite UNIQUE. Both go
        // through exec() (guarded raw ALTER — throws at the exact failing
        // statement) per docs/migrations.md.
        $this->exec( "ALTER TABLE {$players_t} MODIFY wp_user_id BIGINT UNSIGNED DEFAULT NULL" );
        $this->addUniqueIfMissing( $players_t, 'uniq_club_user', 'club_id, wp_user_id' );
    }

    private function dedupe( string $players_t, string $evals_t, string $audit_t ): void {
        global $wpdb;

        $clusters = $wpdb->get_results(
            "SELECT club_id, wp_user_id, COUNT(*) AS n
               FROM {$players_t}
              WHERE wp_user_id IS NOT NULL AND wp_user_id <> 0
              GROUP BY club_id, wp_user_id
             HAVING n > 1"
        );
        if ( empty( $clusters ) ) return;

        $has_evals = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $evals_t ) ) === $evals_t;
        $audit_ok  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_t ) ) === $audit_t;
        $rel_expr  = $has_evals
            ? "( SELECT COUNT(*) FROM {$evals_t} e WHERE e.player_id = p.id )"
            : '0';

        foreach ( $clusters as $c ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.id, p.status, {$rel_expr} AS rel_count
                   FROM {$players_t} p
                  WHERE p.club_id = %d AND p.wp_user_id = %d
                  ORDER BY ( p.status = 'active' ) DESC, rel_count DESC, p.id DESC",
                (int) $c->club_id, (int) $c->wp_user_id
            ) );
            if ( count( $rows ) < 2 ) continue;

            $winner = array_shift( $rows );
            foreach ( $rows as $loser ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$players_t} SET wp_user_id = NULL WHERE id = %d AND club_id = %d",
                    (int) $loser->id, (int) $c->club_id
                ) );

                if ( $audit_ok ) {
                    $wpdb->insert( $audit_t, [
                        'club_id'     => (int) $c->club_id,
                        'user_id'     => 0,
                        'action'      => 'player.account_deduped',
                        'entity_type' => 'player',
                        'entity_id'   => (int) $loser->id,
                        'payload'     => (string) wp_json_encode( [
                            'reason'         => 'migration_0170',
                            'wp_user_id'     => (int) $c->wp_user_id,
                            'winner_id'      => (int) $winner->id,
                            'winner_rel_cnt' => (int) $winner->rel_count,
                            'loser_id'       => (int) $loser->id,
                            'loser_rel_cnt'  => (int) $loser->rel_count,
                        ] ),
                        'ip_address'  => '',
                        'created_at'  => current_time( 'mysql' ),
                    ] );
                }
            }
        }
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table, $column
        ) );
    }

    private function addUniqueIfMissing( string $table, string $index, string $columns ): void {
        global $wpdb;
        $exists = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            $table, $index
        ) );
        if ( $exists ) return;

        // If a duplicate slipped through (shouldn't, post-dedupe), exec()
        // throws loudly — the migration fails and is retryable rather than
        // silently leaving the table unguarded.
        $this->exec( "ALTER TABLE {$table} ADD UNIQUE KEY {$index} ({$columns})" );
    }

    public function down(): void {
        // Forward-only. Dropping the UNIQUE key or reviving 0-as-no-account
        // would re-open the wrong-child resolution risk this closes.
    }
};
