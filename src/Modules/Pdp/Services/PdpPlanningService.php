<?php
namespace TT\Modules\Pdp\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;

/**
 * PdpPlanningService (#4448) — the PDP planning matrix: per team and per
 * block, how many talks are planned in their window and how many have a
 * recorded result, plus each block's window.
 *
 * The planning page (`?tt_view=pdp-planning`) and
 * `GET /talenttrack/v1/pdp-planning` both read from here, so a
 * non-WordPress front end gets the cells and windows the page shows, for
 * the same teams.
 */
final class PdpPlanningService {

    /**
     * #1865 — the teams a user may see in PDP planning. The team set follows
     * the planning permission, `pdp_planning` read, the same grant the page
     * and the route are gated on. A global grant (and the settings admin)
     * means no team restriction: null. Otherwise it is the teams the user is
     * assigned to on which that grant applies, possibly none, which scopes
     * the matrix to nothing.
     *
     * @return list<int>|null
     */
    public static function accessibleTeamIds( int $user_id ): ?array {
        if ( QueryHelpers::user_has_global_entity_read( $user_id, 'pdp_planning' ) ) {
            return null;
        }
        $ids = [];
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
            $id = (int) ( ( (array) $t )['id'] ?? 0 );
            if ( $id > 0 && MatrixGate::can( $user_id, 'pdp_planning', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $id ) ) {
                $ids[] = $id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * @param list<int>|null $team_ids #1865 — null = no team restriction
     *        (HoD/admin); a list scopes the matrix to those teams; an empty
     *        list yields an empty matrix (coach with no teams).
     * @return array{max_blocks:int,teams:array<int,array{name:string,roster:int,blocks:array<int,array<string,mixed>>}>}
     */
    public static function buildMatrix( int $season_id, ?array $team_ids = null ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $season_id <= 0 ) return [ 'max_blocks' => 0, 'teams' => [] ];
        if ( is_array( $team_ids ) && $team_ids === [] ) return [ 'max_blocks' => 0, 'teams' => [] ];

        // Every team set follows the demo scope, whether it was asked for by
        // id or not: a list of ids is a filter, never a way past the scope.
        $team_clause  = QueryHelpers::apply_demo_scope( 't', 'team' );
        $team_params  = [];
        if ( is_array( $team_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $team_clause .= " AND t.id IN ($placeholders)";
            $team_params  = array_map( 'intval', $team_ids );
        }

        // Aggregate per (team_id, sequence). For each block we need:
        //   expected   = roster (computed once below)
        //   planned    = files where the conversation has scheduled_at set
        //   conducted  = files where conducted_at IS NOT NULL
        //   not_held   = #4298, talks marked as not having taken place;
        //                neither planned nor conducted
        //   window_*   = from blockWindows(), the span the block detail shows
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                t.id   AS team_id,
                t.name AS team_name,
                c.sequence,
                SUM(CASE WHEN c.scheduled_at IS NOT NULL AND c.not_held_at IS NULL THEN 1 ELSE 0 END)  AS planned,
                SUM(CASE WHEN c.conducted_at IS NOT NULL THEN 1 ELSE 0 END)  AS conducted,
                SUM(CASE WHEN c.conducted_at IS NULL AND c.not_held_at IS NOT NULL THEN 1 ELSE 0 END) AS not_held
              FROM {$p}tt_pdp_conversations c
              JOIN {$p}tt_pdp_files f ON f.id = c.pdp_file_id AND f.club_id = c.club_id
              JOIN {$p}tt_players  pl ON pl.id = f.player_id  AND pl.club_id = f.club_id
              JOIN {$p}tt_teams    t  ON t.id  = pl.team_id   AND t.club_id  = pl.club_id
             WHERE c.club_id = %d
               AND f.season_id = %d{$team_clause}
               AND pl.status = 'active'
               AND pl.archived_at IS NULL
             GROUP BY t.id, c.sequence
             ORDER BY t.name ASC, c.sequence ASC",
            CurrentClub::id(), $season_id, ...$team_params
        ) );

        $windows    = self::blockWindows( $season_id, $team_ids );
        $teams      = [];
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
            // $max_blocks is the data-derived fallback; the configured
            // block count (below) wins when blocks are configured.
            $teams[ $tid ]['blocks'][ $b ] = [
                'expected'     => 0,
                'planned'      => (int) $row->planned,
                'conducted'    => (int) $row->conducted,
                'not_held'     => (int) $row->not_held,
                'window_start' => $windows[ $tid ][ $b ]['start'] ?? '',
                'window_end'   => $windows[ $tid ][ $b ]['end'] ?? '',
            ];
        }

        // Roster size per team (active players only). #4248 - planned,
        // conducted, expected and the block's missing list all count the
        // same population: active, not archived.
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
                    AND archived_at IS NULL
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

        // #1759 — the planning grid's column count follows the academy's
        // configured PDP block count for the season (tt_pdp_blocks), not
        // the MAX(sequence) seen across conversation rows. A legacy/seed
        // conversation carrying a higher sequence than the current config
        // no longer expands the grid — the render loop iterates
        // 1..max_blocks, so blocks beyond the configured count are simply
        // not drawn. When no blocks are configured for the season, fall
        // back to the data-derived max so legacy even-divide installs keep
        // their previous behaviour.
        $configured = ( new \TT\Modules\Pdp\Repositories\PdpBlocksRepository() )->listForSeason( $season_id );
        $config_max = 0;
        foreach ( $configured as $cb ) {
            $config_max = max( $config_max, (int) $cb['sequence'] );
        }
        if ( $config_max > 0 ) {
            $max_blocks = $config_max;
        }

        return [ 'max_blocks' => $max_blocks, 'teams' => $teams ];
    }

    /**
     * #4305 — a block's window per team: the earliest start to the latest
     * end over the team's conversations in that block and season. Each
     * conversation carries its own window, so the block is open while any
     * player's talk window is. The matrix cell, its chip colour and the
     * block detail all read this, so they cannot show different dates.
     *
     * @param list<int>|null $team_ids null = every team.
     * @return array<int,array<int,array{start:string,end:string}>> team_id => sequence => window
     */
    public static function blockWindows( int $season_id, ?array $team_ids = null, ?int $block = null ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $season_id <= 0 ) return [];
        if ( is_array( $team_ids ) && $team_ids === [] ) return [];

        // Same demo scope as buildMatrix(), on every path.
        $clause = QueryHelpers::apply_demo_scope( 't', 'team' );
        $params = [ CurrentClub::id(), $season_id ];
        if ( is_array( $team_ids ) ) {
            $clause .= ' AND pl.team_id IN (' . implode( ',', array_fill( 0, count( $team_ids ), '%d' ) ) . ')';
            $params  = array_merge( $params, array_map( 'intval', $team_ids ) );
        }
        if ( $block !== null ) {
            $clause  .= ' AND c.sequence = %d';
            $params[] = $block;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.team_id,
                    c.sequence,
                    MIN(c.planning_window_start) AS window_start,
                    MAX(c.planning_window_end)   AS window_end
               FROM {$p}tt_pdp_conversations c
               JOIN {$p}tt_pdp_files f ON f.id = c.pdp_file_id AND f.club_id = c.club_id
               JOIN {$p}tt_players  pl ON pl.id = f.player_id  AND pl.club_id = f.club_id
               JOIN {$p}tt_teams    t  ON t.id  = pl.team_id   AND t.club_id  = pl.club_id
              WHERE c.club_id = %d
                AND f.season_id = %d{$clause}
                AND pl.status = 'active'
                AND pl.archived_at IS NULL
              GROUP BY pl.team_id, c.sequence",
            ...$params
        ) );

        $windows = [];
        foreach ( (array) $rows as $row ) {
            $windows[ (int) $row->team_id ][ (int) $row->sequence ] = [
                'start' => (string) ( $row->window_start ?? '' ),
                'end'   => (string) ( $row->window_end ?? '' ),
            ];
        }
        return $windows;
    }

    /**
     * The teams the matrix covers when a caller asks for some: the ones it
     * asked for that this user may see. Not asking (null) means every team
     * the user may see. Asking for none, which is what a request carrying
     * only unusable ids comes to, means none. A coach who asks for another
     * team's matrix gets only their own teams' share of the request, which
     * may be none.
     *
     * @param list<int>|null $requested null = no team filter sent
     * @return list<int>|null null = no team restriction
     */
    public static function scopedTeamIds( int $user_id, ?array $requested ): ?array {
        $allowed = self::accessibleTeamIds( $user_id );
        if ( $requested === null ) return $allowed;
        if ( $requested === [] ) return [];
        if ( $allowed === null ) return array_values( array_unique( $requested ) );
        return array_values( array_intersect( array_unique( $requested ), $allowed ) );
    }
}
