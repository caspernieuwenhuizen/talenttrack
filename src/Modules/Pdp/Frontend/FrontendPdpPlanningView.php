<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * FrontendPdpPlanningView (#0054) — HoD-facing planning matrix.
 *
 *   ?tt_view=pdp-planning
 *
 * Per-team × per-block matrix showing how many PDP conversations are
 * planned in their window vs. how many have a recorded result. Cells
 * link into the existing filtered PDP file list (`?tt_view=pdp&filter[team_id]=N`)
 * for drill-down to specific files.
 *
 * Cap-gated on `tt_edit_pdp` (HoD / coach / admin). Players + parents
 * hold `tt_view_pdp` for their own self-scope per #0033 — that's
 * insufficient for cross-team planning, so the planning matrix
 * checks the edit cap as the chokepoint. Read-only — all writes
 * happen on the per-file detail view via the existing PDP REST surface.
 */
final class FrontendPdpPlanningView {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_pdp' ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view PDP planning.', 'talenttrack' ) . '</p>';
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $season_id = isset( $_GET['season_id'] ) ? absint( $_GET['season_id'] ) : self::resolveCurrentSeason();
        $matrix    = self::buildMatrix( $season_id );

        $base_url = remove_query_arg( [ 'season_id' ] );

        echo '<section class="tt-pdp-planning" style="max-width:1200px;">';
        echo '<h2 style="margin:0 0 12px;">' . esc_html__( 'PDP planning', 'talenttrack' ) . '</h2>';
        echo '<p style="margin:0 0 16px;color:#5b6e75;">' . esc_html__( 'How many conversations are planned in their window, and how many have a recorded result. Click a cell to drill into the underlying files.', 'talenttrack' ) . '</p>';

        // Season picker.
        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_seasons WHERE club_id = %d ORDER BY start_date DESC LIMIT 12",
            CurrentClub::id()
        ) );
        if ( $seasons ) {
            echo '<form method="get" style="margin:0 0 16px;display:flex;gap:8px;align-items:center;">';
            $hidden_args = wp_parse_args( $_GET, [] );
            unset( $hidden_args['season_id'] );
            foreach ( $hidden_args as $k => $v ) {
                if ( is_string( $v ) ) {
                    echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
                }
            }
            echo '<label for="tt-pdp-planning-season"><strong>' . esc_html__( 'Season:', 'talenttrack' ) . '</strong></label>';
            echo '<select id="tt-pdp-planning-season" name="season_id" onchange="this.form.submit();" class="tt-input">';
            foreach ( $seasons as $s ) {
                $sel = ( (int) $s->id === $season_id ) ? ' selected' : '';
                echo '<option value="' . (int) $s->id . '"' . $sel . '>' . esc_html( (string) $s->name ) . '</option>';
            }
            echo '</select>';
            echo '</form>';
        }

        if ( empty( $matrix['teams'] ) ) {
            echo '<p>' . esc_html__( 'No PDP files for this season yet.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }

        $today = gmdate( 'Y-m-d' );
        echo '<table class="tt-table" style="width:100%;border-collapse:collapse;">';
        echo '<thead><tr>';
        echo '<th style="text-align:left;padding:8px;border-bottom:1px solid #d6dadd;">' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        $max_blocks = (int) $matrix['max_blocks'];
        for ( $b = 1; $b <= $max_blocks; $b++ ) {
            echo '<th style="text-align:left;padding:8px;border-bottom:1px solid #d6dadd;">' . esc_html( sprintf( __( 'Block %d', 'talenttrack' ), $b ) ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $matrix['teams'] as $team_id => $team ) {
            echo '<tr>';
            echo '<td style="padding:8px;border-bottom:1px solid #eef0f2;"><strong>' . esc_html( (string) $team['name'] ) . '</strong>'
                . ' <small style="color:#5b6e75;">' . (int) $team['roster'] . ' ' . esc_html__( 'players', 'talenttrack' ) . '</small></td>';
            for ( $b = 1; $b <= $max_blocks; $b++ ) {
                $cell = $team['blocks'][ $b ] ?? null;
                $cell_url = add_query_arg( [
                    'tt_view'         => 'pdp',
                    'filter[team_id]' => (int) $team_id,
                    'filter[block]'   => $b,
                    'filter[season]'  => $season_id,
                ], $base_url );
                echo '<td style="padding:8px;border-bottom:1px solid #eef0f2;">';
                if ( ! $cell || $cell['expected'] === 0 ) {
                    echo '<span style="color:#5b6e75;">—</span>';
                } else {
                    $window_open = ( $cell['window_start'] <= $today );
                    if ( ! $window_open ) {
                        echo '<span style="color:#5b6e75;">' . esc_html__( 'window not yet open', 'talenttrack' ) . '</span>';
                    } else {
                        $color = self::cellColor( $cell, $today );
                        $label = sprintf(
                            /* translators: 1: planned count, 2: roster size, 3: conducted count, 4: planned count */
                            __( '%1$d/%2$d planned · %3$d/%4$d conducted', 'talenttrack' ),
                            (int) $cell['planned'],
                            (int) $cell['expected'],
                            (int) $cell['conducted'],
                            (int) $cell['planned']
                        );
                        echo '<a href="' . esc_url( $cell_url ) . '" style="color:' . esc_attr( $color ) . ';text-decoration:none;font-weight:600;">'
                            . esc_html( $label ) . '</a>';
                    }
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }

    /**
     * @return array{max_blocks:int,teams:array<int,array{name:string,roster:int,blocks:array<int,array<string,mixed>>}>}
     */
    public static function buildMatrix( int $season_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $season_id <= 0 ) return [ 'max_blocks' => 0, 'teams' => [] ];

        // Aggregate per (team_id, sequence). For each block we need:
        //   expected   = roster (computed once below)
        //   planned    = files where the conversation has scheduled_at set
        //   conducted  = files where conducted_at IS NOT NULL
        //   window_*   = the block's window dates (constant across team)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                t.id   AS team_id,
                t.name AS team_name,
                c.sequence,
                MIN(c.planning_window_start) AS window_start,
                MAX(c.planning_window_end)   AS window_end,
                SUM(CASE WHEN c.scheduled_at IS NOT NULL THEN 1 ELSE 0 END)  AS planned,
                SUM(CASE WHEN c.conducted_at IS NOT NULL THEN 1 ELSE 0 END)  AS conducted
              FROM {$p}tt_pdp_conversations c
              JOIN {$p}tt_pdp_files f ON f.id = c.pdp_file_id AND f.club_id = c.club_id
              JOIN {$p}tt_players  pl ON pl.id = f.player_id  AND pl.club_id = f.club_id
              JOIN {$p}tt_teams    t  ON t.id  = pl.team_id   AND t.club_id  = pl.club_id
             WHERE c.club_id = %d
               AND f.season_id = %d
             GROUP BY t.id, c.sequence
             ORDER BY t.name ASC, c.sequence ASC",
            CurrentClub::id(), $season_id
        ) );

        $teams = [];
        $max_blocks = 0;
        foreach ( (array) $rows as $row ) {
            $tid = (int) $row->team_id;
            if ( ! isset( $teams[ $tid ] ) ) {
                $teams[ $tid ] = [
                    'name'   => (string) $row->team_name,
                    'roster' => 0,
                    'blocks' => [],
                ];
            }
            $b = (int) $row->sequence;
            if ( $b > $max_blocks ) $max_blocks = $b;
            $teams[ $tid ]['blocks'][ $b ] = [
                'expected'     => 0,
                'planned'      => (int) $row->planned,
                'conducted'    => (int) $row->conducted,
                'window_start' => (string) $row->window_start,
                'window_end'   => (string) $row->window_end,
            ];
        }

        // Roster size per team (active players only).
        if ( ! empty( $teams ) ) {
            $ids = array_keys( $teams );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $params       = array_merge( $ids, [ CurrentClub::id() ] );
            $roster_rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT team_id, COUNT(*) AS roster
                   FROM {$p}tt_players
                  WHERE team_id IN ({$placeholders})
                    AND club_id = %d
                    AND status = 'active'
                  GROUP BY team_id",
                ...$params
            ) );
            foreach ( (array) $roster_rows as $rr ) {
                $tid = (int) $rr->team_id;
                if ( isset( $teams[ $tid ] ) ) {
                    $teams[ $tid ]['roster'] = (int) $rr->roster;
                    foreach ( $teams[ $tid ]['blocks'] as $b => &$cell ) {
                        $cell['expected'] = (int) $rr->roster;
                    }
                    unset( $cell );
                }
            }
        }

        return [ 'max_blocks' => $max_blocks, 'teams' => $teams ];
    }

    /**
     * @param array{expected:int,planned:int,conducted:int,window_start:string,window_end:string} $cell
     */
    private static function cellColor( array $cell, string $today ): string {
        $expected = $cell['expected'];
        $planned  = $cell['planned'];
        $window_closed = $cell['window_end'] !== '' && $cell['window_end'] < $today;

        if ( $expected === 0 ) return '#5b6e75';
        if ( ! $window_closed ) {
            return $planned >= $expected ? '#16a34a' : '#d97706';
        }
        // Window closed: emphasise conducted ratio.
        $conducted = $cell['conducted'];
        if ( $conducted >= $expected ) return '#16a34a';
        if ( $planned   >= $expected ) return '#d97706';
        return '#b91c1c';
    }

    private static function resolveCurrentSeason(): int {
        global $wpdb;
        $today = gmdate( 'Y-m-d' );
        $id    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_seasons
              WHERE club_id = %d
                AND start_date <= %s AND end_date >= %s
              ORDER BY start_date DESC
              LIMIT 1",
            CurrentClub::id(), $today, $today
        ) );
        if ( $id > 0 ) return $id;
        // Fall back to most recent season.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_seasons WHERE club_id = %d ORDER BY start_date DESC LIMIT 1",
            CurrentClub::id()
        ) );
    }
}
