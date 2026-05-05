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

        // v3.94.1 — block-detail drill-down (`?tt_view=pdp-planning&action=block`)
        // shows per-player status for a single team × block × season.
        // Replaces the v1 cell-click target which routed to the unfiltered
        // `?tt_view=pdp` list and didn't actually scope by block.
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        if ( $action === 'block' ) {
            self::renderBlockDetail( $user_id );
            return;
        }

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'PDP planning', 'talenttrack' ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $season_id = isset( $_GET['season_id'] ) ? absint( $_GET['season_id'] ) : self::resolveCurrentSeason();
        $matrix    = self::buildMatrix( $season_id );

        $base_url = remove_query_arg( [ 'season_id', 'action', 'team_id', 'block' ] );

        echo '<section class="tt-pdp-planning" style="max-width:1200px;">';
        echo '<h2 style="margin:0 0 12px;">' . esc_html__( 'PDP planning', 'talenttrack' ) . '</h2>';
        echo '<p style="margin:0 0 16px;color:#5b6e75;">' . esc_html__( 'How many conversations are planned in their window, and how many have a recorded result. Click a cell to drill into per-player status for that team × block.', 'talenttrack' ) . '</p>';

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
            // #0063 — team name links to the frontend team detail.
            $team_link = \TT\Shared\Frontend\Components\RecordLink::inline(
                (string) $team['name'],
                \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'teams', (int) $team_id )
            );
            echo '<tr>';
            echo '<td style="padding:8px;border-bottom:1px solid #eef0f2;"><strong>' . $team_link . '</strong>'
                . ' <small style="color:#5b6e75;">' . (int) $team['roster'] . ' ' . esc_html__( 'players', 'talenttrack' ) . '</small></td>';
            for ( $b = 1; $b <= $max_blocks; $b++ ) {
                $cell = $team['blocks'][ $b ] ?? null;
                // v3.94.1 — drill-down lands on the per-player block view
                // (action=block) rather than the unfiltered PDP list.
                $cell_url = add_query_arg( [
                    'tt_view'   => 'pdp-planning',
                    'action'    => 'block',
                    'team_id'   => (int) $team_id,
                    'block'     => $b,
                    'season_id' => $season_id,
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

    /**
     * Per-player block detail — renders three columns (Planned /
     * Conducted / Missing) for one team × block × season tuple.
     * v3.94.1 replacement for the v1 cell-click that routed to the
     * unfiltered PDP list.
     */
    private static function renderBlockDetail( int $user_id ): void {
        $team_id   = isset( $_GET['team_id'] )   ? absint( $_GET['team_id'] )   : 0;
        $block     = isset( $_GET['block'] )     ? absint( $_GET['block'] )     : 0;
        $season_id = isset( $_GET['season_id'] ) ? absint( $_GET['season_id'] ) : self::resolveCurrentSeason();

        if ( $team_id <= 0 || $block <= 0 || $season_id <= 0 ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'PDP planning', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Block detail requires a team, a block number, and a season. Click a cell on the planning matrix to drill in.', 'talenttrack' ) . '</p>';
            return;
        }

        $team = QueryHelpers::get_team( $team_id );
        if ( ! $team ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'PDP planning', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That team no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        $detail = self::loadBlockDetail( $team_id, $block, $season_id );

        // Breadcrumb chain: Dashboard / PDP planning / Team — Block N.
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            sprintf(
                /* translators: 1: team name, 2: block number */
                __( '%1$s — Block %2$d', 'talenttrack' ),
                (string) $team->name,
                $block
            ),
            [
                \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb(
                    'pdp-planning',
                    __( 'PDP planning', 'talenttrack' ),
                    [ 'season_id' => $season_id ]
                ),
            ]
        );

        echo '<section class="tt-pdp-planning-block" style="max-width:1200px;">';
        echo '<h2 style="margin:0 0 4px;">' . esc_html( sprintf(
            /* translators: 1: team name, 2: block number */
            __( '%1$s — PDP block %2$d', 'talenttrack' ),
            (string) $team->name,
            $block
        ) ) . '</h2>';

        if ( $detail['window_start'] !== '' || $detail['window_end'] !== '' ) {
            echo '<p style="color:#5b6e75; margin:0 0 16px;">' . esc_html( sprintf(
                /* translators: 1: window start date, 2: window end date */
                __( 'Window %1$s → %2$s.', 'talenttrack' ),
                (string) ( $detail['window_start'] ?: '—' ),
                (string) ( $detail['window_end']   ?: '—' )
            ) ) . '</p>';
        }

        $columns = [
            [
                'key'    => 'conducted',
                'label'  => __( 'Conducted', 'talenttrack' ),
                'colour' => '#16a34a',
                'help'   => __( 'Conversation has a recorded conducted_at — the talk happened.', 'talenttrack' ),
            ],
            [
                'key'    => 'planned',
                'label'  => __( 'Planned', 'talenttrack' ),
                'colour' => '#d97706',
                'help'   => __( 'Conversation is on the calendar (scheduled_at set) but no conducted_at yet.', 'talenttrack' ),
            ],
            [
                'key'    => 'missing',
                'label'  => __( 'Missing', 'talenttrack' ),
                'colour' => '#b91c1c',
                'help'   => __( 'Active roster player with no conversation in this block. Open their PDP file to start one.', 'talenttrack' ),
            ],
        ];

        echo '<div class="tt-pdp-block-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-top:8px;">';
        foreach ( $columns as $col ) {
            $bucket = $detail[ $col['key'] ] ?? [];
            echo '<div class="tt-pdp-block-col" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:14px;">';
            echo '<h3 style="margin:0 0 6px; font-size:14px; color:' . esc_attr( $col['colour'] ) . ';">' .
                 esc_html( $col['label'] ) .
                 ' <span style="background:' . esc_attr( $col['colour'] ) . '; color:#fff; font-size:11px; padding:2px 6px; border-radius:10px; margin-left:4px;">' .
                 (int) count( $bucket ) .
                 '</span></h3>';
            echo '<p style="color:#5b6e75; font-size:12px; margin:0 0 8px;">' . esc_html( (string) $col['help'] ) . '</p>';

            if ( empty( $bucket ) ) {
                echo '<p style="color:#5b6e75; font-style:italic; margin:0;">' . esc_html__( 'None.', 'talenttrack' ) . '</p>';
            } else {
                echo '<ul class="tt-stack" style="list-style:none; padding:0; margin:0;">';
                foreach ( $bucket as $row ) {
                    $name = trim( ( (string) ( $row['first_name'] ?? '' ) ) . ' ' . ( (string) ( $row['last_name'] ?? '' ) ) );
                    if ( $name === '' ) continue;
                    $pdp_id = (int) ( $row['pdp_file_id'] ?? 0 );
                    $url    = $pdp_id > 0
                        ? add_query_arg( [ 'tt_view' => 'pdp', 'id' => $pdp_id ], remove_query_arg( [ 'action', 'team_id', 'block', 'season_id' ] ) )
                        : add_query_arg( [ 'tt_view' => 'pdp', 'action' => 'new', 'player_id' => (int) $row['player_id'] ], remove_query_arg( [ 'action', 'team_id', 'block', 'season_id' ] ) );
                    $label  = esc_html( $name );
                    $meta   = '';
                    if ( $col['key'] === 'conducted' && ! empty( $row['conducted_at'] ) ) {
                        $meta = sprintf(
                            /* translators: %s: ISO date string */
                            __( ' · conducted %s', 'talenttrack' ),
                            esc_html( substr( (string) $row['conducted_at'], 0, 10 ) )
                        );
                    } elseif ( $col['key'] === 'planned' && ! empty( $row['scheduled_at'] ) ) {
                        $meta = sprintf(
                            /* translators: %s: ISO date string */
                            __( ' · planned %s', 'talenttrack' ),
                            esc_html( substr( (string) $row['scheduled_at'], 0, 10 ) )
                        );
                    }
                    echo '<li style="padding:6px 0; border-bottom:1px solid #f1f3f5;">';
                    echo '<a class="tt-record-link" href="' . esc_url( $url ) . '">' . $label . '</a>';
                    if ( $meta !== '' ) echo '<span class="tt-muted" style="color:#5b6e75; font-size:12px;">' . $meta . '</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';
    }

    /**
     * Build the per-player split for one (team, block, season).
     *
     * @return array{
     *   window_start:string,
     *   window_end:string,
     *   conducted: list<array<string,mixed>>,
     *   planned:   list<array<string,mixed>>,
     *   missing:   list<array<string,mixed>>,
     * }
     */
    private static function loadBlockDetail( int $team_id, int $block, int $season_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // Conversations in this block for this team's roster + season.
        // Returned regardless of conducted_at so the caller can split.
        $conv_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id            AS conversation_id,
                    c.pdp_file_id,
                    c.scheduled_at,
                    c.conducted_at,
                    c.planning_window_start AS window_start,
                    c.planning_window_end   AS window_end,
                    pl.id            AS player_id,
                    pl.first_name,
                    pl.last_name
               FROM {$p}tt_pdp_conversations c
               JOIN {$p}tt_pdp_files f ON f.id = c.pdp_file_id AND f.club_id = c.club_id
               JOIN {$p}tt_players  pl ON pl.id = f.player_id  AND pl.club_id = f.club_id
              WHERE c.club_id = %d
                AND f.season_id = %d
                AND pl.team_id = %d
                AND c.sequence = %d
              ORDER BY pl.last_name ASC",
            CurrentClub::id(), $season_id, $team_id, $block
        ) );

        $conducted = [];
        $planned   = [];
        $covered_player_ids = [];
        $window_start = '';
        $window_end   = '';
        foreach ( (array) $conv_rows as $r ) {
            $covered_player_ids[ (int) $r->player_id ] = true;
            if ( $window_start === '' && ! empty( $r->window_start ) ) $window_start = (string) $r->window_start;
            if ( $window_end   === '' && ! empty( $r->window_end ) )   $window_end   = (string) $r->window_end;
            $row = [
                'conversation_id' => (int) $r->conversation_id,
                'pdp_file_id'     => (int) $r->pdp_file_id,
                'player_id'       => (int) $r->player_id,
                'first_name'      => (string) $r->first_name,
                'last_name'       => (string) $r->last_name,
                'scheduled_at'    => (string) ( $r->scheduled_at ?: '' ),
                'conducted_at'    => (string) ( $r->conducted_at ?: '' ),
            ];
            if ( ! empty( $r->conducted_at ) ) {
                $conducted[] = $row;
            } else {
                $planned[] = $row;
            }
        }

        // Missing = active roster players with no conversation row in
        // this block. They may have a PDP file already (link to it) or
        // they may not — in which case we link to "create new PDP for
        // this player" so the coach can start the file straight away.
        $missing_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id   AS player_id,
                    pl.first_name,
                    pl.last_name,
                    f.id    AS pdp_file_id
               FROM {$p}tt_players pl
          LEFT JOIN {$p}tt_pdp_files f ON f.player_id = pl.id AND f.season_id = %d AND f.club_id = pl.club_id
              WHERE pl.team_id = %d
                AND pl.club_id = %d
                AND pl.status  = 'active'
                AND pl.archived_at IS NULL
              ORDER BY pl.last_name ASC",
            $season_id, $team_id, CurrentClub::id()
        ) );

        $missing = [];
        foreach ( (array) $missing_rows as $r ) {
            if ( isset( $covered_player_ids[ (int) $r->player_id ] ) ) continue;
            $missing[] = [
                'player_id'   => (int) $r->player_id,
                'first_name'  => (string) $r->first_name,
                'last_name'   => (string) $r->last_name,
                'pdp_file_id' => $r->pdp_file_id !== null ? (int) $r->pdp_file_id : 0,
            ];
        }

        return [
            'window_start' => $window_start,
            'window_end'   => $window_end,
            'conducted'    => $conducted,
            'planned'      => $planned,
            'missing'      => $missing,
        ];
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
