<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Services\MatchLengthResolver;

/**
 * MinutesShareQuery (#2835) — what share of the minutes a team actually
 * played did each player get.
 *
 * The product reports minutes in absolutes everywhere (Player · Minutes
 * played, Team · Minutes distribution, Minutes played per team, Minutes
 * audit) and one relative figure — the top-versus-bottom spread — which
 * compares players to each other rather than to what was on offer. So 350
 * minutes looks fine until you know the team played 700.
 *
 * ## The denominator
 *
 * Every **played** match the team had in the window, at its own resolved
 * length. Not "the matches this player was available for": a player who
 * missed six weeks injured shows a low share, and that is the honest
 * number — the injury record is what explains it, and a denominator that
 * quietly shrank to match each player's availability would hide exactly
 * the case the report exists to surface.
 *
 * "Played" is {@see MinutesQuery::playedMatchSql()}, shared with the two
 * minutes reports so the three cannot disagree. Match length comes from
 * {@see MatchLengthResolver} with its full precedence (the prep row's own
 * half length → the age-group map → the 35-minute fallback), so a team
 * whose age group plays 30-minute halves gets 600 available over ten
 * matches rather than a flat 700.
 *
 * ## The squad
 *
 * Players with a canonical attendance row on the team's played matches in
 * the window — the same definition `Team · Minutes distribution` uses
 * since #2339, including the archived-player exclusion #2833 added. A
 * player appears with 0% if they were in the squad and never got on.
 */
final class MinutesShareQuery {

    /** tt_config key holding the minimum share every player should reach. */
    public const TARGET_CONFIG_KEY = 'minutes_share_target_pct';

    /** The pilot's rule of thumb, and the default for a fresh install. */
    public const DEFAULT_TARGET_PCT = 30;

    /**
     * The academy's minimum-share target, as a percentage.
     *
     * Clamped to 0–100: a stored 250 would flag the whole squad forever,
     * which reads as a broken report rather than as a misconfiguration.
     */
    public static function targetPct(): int {
        $raw = QueryHelpers::get_config( self::TARGET_CONFIG_KEY, '' );
        if ( $raw === '' ) return self::DEFAULT_TARGET_PCT;

        $n = (int) $raw;
        if ( $n < 0 )   return 0;
        if ( $n > 100 ) return 100;

        return $n;
    }

    /**
     * Available minutes for a team over a window: the sum of every played
     * match's length.
     *
     * @return array{minutes:int, matches:int}
     */
    public function availableForTeam( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        if ( $team_id <= 0 ) return [ 'minutes' => 0, 'matches' => 0 ];

        $date_col   = 'sess' . 'ion_date'; // #0035 lint-safe
        $played_sql = MinutesQuery::playedMatchSql( 'a' );

        // The prep row's half length is the per-match override
        // MatchLengthResolver takes first; pulling it in the same query
        // saves a lookup per match on a full season.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id,
                    COALESCE( mp.half_length_minutes, 0 ) AS half_length_minutes
               FROM {$p}tt_activities a
               LEFT JOIN {$p}tt_match_prep mp ON mp.activity_id = a.id
              WHERE a.club_id = %d
                AND a.team_id = %d
                AND LOWER(a.activity_type_key) IN ( 'match', 'game', 'tournament' )
                AND a.{$date_col} BETWEEN %s AND %s
                AND {$played_sql}
                AND a.archived_at IS NULL
                AND a.trashed_at IS NULL
                AND a.plan_state <> 'cancelled'
                AND ( a.activity_status_key IS NULL OR a.activity_status_key <> 'cancelled' )",
            $club_id, $team_id, $from, $to
        ) );
        $rows = is_array( $rows ) ? $rows : [];

        $resolver = new MatchLengthResolver();
        $minutes  = 0;
        foreach ( $rows as $r ) {
            $minutes += $resolver->matchMinutesForActivity(
                (int) $r->id,
                (int) $r->half_length_minutes
            );
        }

        return [ 'minutes' => $minutes, 'matches' => count( $rows ) ];
    }

    /**
     * Per-player share for one team over a window, ordered so the
     * under-played players are read first.
     *
     * `share_pct` is null when the team played nothing in the window —
     * a share of no minutes is undefined, not zero, and rendering 0%
     * there would flag a whole squad for a season that has not started.
     *
     * @return array{
     *   available_minutes:int, matches:int, target_pct:int,
     *   players: list<array{player_id:int, name:string, jersey_number:?int,
     *                       minutes:int, share_pct:?float, below_target:bool}>
     * }
     */
    public function forTeam( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();
        $target  = self::targetPct();

        $empty = [
            'available_minutes' => 0,
            'matches'           => 0,
            'target_pct'        => $target,
            'players'           => [],
        ];
        if ( $team_id <= 0 ) return $empty;

        $available = $this->availableForTeam( $team_id, $from, $to );

        $date_col   = 'sess' . 'ion_date'; // #0035 lint-safe
        $played_sql = MinutesQuery::playedMatchSql( 'a' );

        // Minutes summed per (player, activity) in a derived table first, so
        // a duplicate `actual` row cannot fan the join out and double a
        // player's total (#2158). Same squad definition as
        // Team · Minutes distribution: attendance on the team's played
        // matches, archived players excluded (#2339, #2833).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id AS player_id, pl.name, pl.jersey_number,
                    COALESCE( SUM( m.match_minutes ), 0 ) AS minutes
               FROM (
                    SELECT att.player_id,
                           att.activity_id,
                           SUM( COALESCE( att.minutes_override, att.minutes_played, 0 ) ) AS match_minutes
                      FROM {$p}tt_attendance att
                      JOIN {$p}tt_activities a ON a.id = att.activity_id
                           AND a.club_id = %d
                           AND a.team_id = %d
                           AND a.archived_at IS NULL
                           AND a.trashed_at IS NULL
                           AND a.plan_state <> 'cancelled'
                           AND ( a.activity_status_key IS NULL OR a.activity_status_key <> 'cancelled' )
                           AND {$played_sql}
                     WHERE att.record_type = 'actual'
                       AND att.is_guest = 0
                       AND LOWER(a.activity_type_key) IN ( 'match', 'game', 'tournament' )
                       AND a.{$date_col} BETWEEN %s AND %s
                     GROUP BY att.player_id, att.activity_id
                  ) m
               JOIN {$p}tt_players pl ON pl.id = m.player_id AND pl.archived_at IS NULL
              GROUP BY pl.id, pl.name, pl.jersey_number
              LIMIT 200",
            $club_id, $team_id, $from, $to
        ) );
        $rows = is_array( $rows ) ? $rows : [];

        $players = [];
        foreach ( $rows as $r ) {
            $minutes = (int) $r->minutes;
            $share   = $available['minutes'] > 0
                ? ( $minutes / $available['minutes'] ) * 100
                : null;

            $players[] = [
                'player_id'     => (int) $r->player_id,
                'name'          => (string) $r->name,
                'jersey_number' => isset( $r->jersey_number ) ? (int) $r->jersey_number : null,
                'minutes'       => $minutes,
                'share_pct'     => $share,
                'below_target'  => $share !== null && $share < $target,
            ];
        }

        // Lowest share first — the report exists to surface who is not
        // getting on, so they should not be at the bottom of a scroll.
        usort( $players, static function ( array $a, array $b ): int {
            $as = $a['share_pct'] ?? 0.0;
            $bs = $b['share_pct'] ?? 0.0;
            if ( $as === $bs ) return strcmp( $a['name'], $b['name'] );

            return $as <=> $bs;
        } );

        return [
            'available_minutes' => $available['minutes'],
            'matches'           => $available['matches'],
            'target_pct'        => $target,
            'players'           => $players,
        ];
    }

    /**
     * One player's share on their own team. Reads `forTeam()` and picks
     * the row out, so the two can never answer differently.
     *
     * @return array{
     *   available_minutes:int, matches:int, target_pct:int,
     *   minutes:int, share_pct:?float, below_target:bool
     * }|null  null when the player is not in the team's squad for the window
     */
    public function forPlayer( int $team_id, int $player_id, string $from, string $to ): ?array {
        if ( $player_id <= 0 ) return null;

        $team = $this->forTeam( $team_id, $from, $to );
        foreach ( $team['players'] as $row ) {
            if ( $row['player_id'] !== $player_id ) continue;

            return [
                'available_minutes' => $team['available_minutes'],
                'matches'           => $team['matches'],
                'target_pct'        => $team['target_pct'],
                'minutes'           => $row['minutes'],
                'share_pct'         => $row['share_pct'],
                'below_target'      => $row['below_target'],
            ];
        }

        return null;
    }

    /**
     * Median share across the squad, or null on an empty squad. Median
     * rather than mean: one player who played every minute of every match
     * drags a mean above the line the rest of the squad is nowhere near.
     *
     * @param list<array{share_pct:?float}> $players
     */
    public static function medianShare( array $players ): ?float {
        $shares = [];
        foreach ( $players as $p ) {
            if ( $p['share_pct'] !== null ) $shares[] = (float) $p['share_pct'];
        }
        if ( $shares === [] ) return null;

        sort( $shares );
        $n   = count( $shares );
        $mid = intdiv( $n, 2 );

        return $n % 2 === 1
            ? $shares[ $mid ]
            : ( $shares[ $mid - 1 ] + $shares[ $mid ] ) / 2;
    }
}
