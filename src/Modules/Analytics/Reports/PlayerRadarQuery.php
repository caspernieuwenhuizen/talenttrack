<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * PlayerRadarQuery (#1369) — dataset builders behind the
 * "Player · Progress & radar" report, extracted from the legacy
 * wp-admin `ReportsPage::runLegacy()` so the frontend renderer and
 * `GET /reports/player-radar` share one source of truth (CLAUDE.md §4).
 *
 * The SQL is byte-for-byte the legacy report's — parity with the
 * wp-admin version is the #1369 acceptance criterion.
 *
 * All three builders return `[ 'labels' => list<string>,
 * 'datasets' => list<array{label:string, values:list<float>}> ]`,
 * ready for `QueryHelpers::radar_chart_svg()` or a JSON payload.
 */
final class PlayerRadarQuery {

    /**
     * Progress mode: one radar overlay per player — the player's last
     * `$limit` evaluations as stacked series. Thin wrapper over the
     * existing `QueryHelpers::player_radar_datasets()`.
     *
     * @return array{labels: array<int,string>, datasets: array<int,array<string,mixed>>}
     */
    public function progressForPlayer( int $player_id, int $limit = 5 ): array {
        $rd = QueryHelpers::player_radar_datasets( $player_id, $limit );
        return [
            'labels'   => is_array( $rd['labels'] ?? null ) ? $rd['labels'] : [],
            'datasets' => is_array( $rd['datasets'] ?? null ) ? $rd['datasets'] : [],
        ];
    }

    /**
     * Comparison mode: each player's MOST RECENT evaluation as one
     * radar series over the main categories.
     *
     * @param list<int> $player_ids
     * @return array{labels: array<int,string>, datasets: array<int,array<string,mixed>>}
     */
    public function comparison( array $player_ids ): array {
        global $wpdb;
        $p          = $wpdb->prefix;
        $categories = QueryHelpers::get_categories();
        $labels     = wp_list_pluck( $categories, 'name' );
        $cat_ids    = wp_list_pluck( $categories, 'id' );

        $datasets = [];
        foreach ( $player_ids as $pid ) {
            $pl = QueryHelpers::get_player( (int) $pid );
            if ( ! $pl ) continue;
            $eval_scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $ev = $wpdb->get_row( $wpdb->prepare( "SELECT e.id FROM {$p}tt_evaluations e WHERE e.player_id=%d {$eval_scope} ORDER BY e.eval_date DESC LIMIT 1", $pid ) );
            if ( ! $ev ) continue;
            $raw = $wpdb->get_results( $wpdb->prepare( "SELECT category_id, rating FROM {$p}tt_eval_ratings WHERE evaluation_id=%d", $ev->id ) );
            $map = [];
            foreach ( (array) $raw as $r ) $map[ (int) $r->category_id ] = (float) $r->rating;
            $vals = [];
            foreach ( $cat_ids as $cid ) $vals[] = $map[ (int) $cid ] ?? 0;
            $datasets[] = [ 'label' => QueryHelpers::player_display_name( $pl ), 'values' => $vals ];
        }
        return [ 'labels' => $labels, 'datasets' => $datasets ];
    }

    /**
     * Team-averages mode: one radar series per team — the average
     * rating per main category across every evaluation of players
     * currently on the team.
     *
     * @param list<int>|null $team_ids Restrict to these teams; null = all.
     * @return array{labels: array<int,string>, datasets: array<int,array<string,mixed>>}
     */
    public function teamAverages( ?array $team_ids = null ): array {
        global $wpdb;
        $p          = $wpdb->prefix;
        $categories = QueryHelpers::get_categories();
        $labels     = wp_list_pluck( $categories, 'name' );
        $cat_ids    = wp_list_pluck( $categories, 'id' );

        $teams = QueryHelpers::get_teams();
        if ( $team_ids !== null && is_array( $teams ) ) {
            $teams = array_values( array_filter(
                $teams,
                static fn( $t ): bool => in_array( (int) $t->id, $team_ids, true )
            ) );
        }

        $datasets = [];
        foreach ( (array) $teams as $team ) {
            $vals = [];
            foreach ( $cat_ids as $cid ) {
                $player_scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r JOIN {$p}tt_evaluations e ON r.evaluation_id=e.id JOIN {$p}tt_players pl ON e.player_id=pl.id WHERE pl.team_id=%d AND r.category_id=%d {$player_scope}", $team->id, $cid ) );
                $vals[] = round( (float) $avg, 2 );
            }
            $datasets[] = [ 'label' => (string) $team->name, 'values' => $vals ];
        }
        return [ 'labels' => $labels, 'datasets' => $datasets ];
    }

    /**
     * Top-10 active-player fallback for progress mode when no players
     * are selected — mirrors the legacy report's default, optionally
     * narrowed to a team allow-list for scoped viewers.
     *
     * @param list<int>|null $team_ids
     * @return list<int>
     */
    public function defaultProgressPlayerIds( ?array $team_ids = null ): array {
        global $wpdb;
        $p            = $wpdb->prefix;
        $player_scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );

        $team_clause = '';
        $team_params = [];
        if ( $team_ids !== null ) {
            if ( empty( $team_ids ) ) return [];
            $team_clause = ' AND pl.team_id IN (' . implode( ',', array_fill( 0, count( $team_ids ), '%d' ) ) . ')';
            $team_params = $team_ids;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT pl.id FROM {$p}tt_players pl WHERE pl.status='active' AND pl.archived_at IS NULL {$player_scope}{$team_clause} LIMIT 10";
        $ids = $team_params
            ? $wpdb->get_col( $wpdb->prepare( $sql, ...$team_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map( 'intval', (array) $ids );
    }
}
