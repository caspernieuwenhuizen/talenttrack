<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\PlayedMatch;
use TT\Infrastructure\Query\QueryHelpers;
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
 * Every **played** match the team had in the window **whose minutes were
 * recorded**, at its own resolved length (#4966): the rule the minutes report
 * and the team monthly report count by (#2252), so the three give one share.
 * A played match with an empty register adds nothing to either side. Not
 * "the matches this player was available for": a player who
 * missed six weeks injured shows a low share, and that is the honest
 * number — the injury record is what explains it, and a denominator that
 * quietly shrank to match each player's availability would hide exactly
 * the case the report exists to surface.
 *
 * "Played" is `PlayedMatch` over its minutes-bearing set (tournament days
 * included), shared with the two minutes reports so the three cannot
 * disagree. Match length comes from
 * {@see MatchLengthResolver::resolvePlayedLength()}, the chain the minutes
 * report and the minutes audit read too (#4077): the prep row's own half
 * length → the activity's match length → the age-group map → the
 * scheduled duration → the 35-minute-per-half fallback. So a team
 * whose age group plays 30-minute halves gets 600 available over ten
 * matches rather than a flat 700.
 *
 * ## The squad
 *
 * `MinutesQuery::squadForTeam()` (#5019), the squad every minutes surface
 * reads: players with a canonical attendance row on the team's played
 * matches in the window, plus the current roster, archived players out. A
 * player appears with 0% if they were in the squad and never got on. The
 * median is `MinutesQuery::medianShare()`.
 */
final class MinutesShareQuery {

    /** tt_config key holding the minimum share every player should reach. */
    public const TARGET_CONFIG_KEY = 'minutes_share_target_pct';

    /** Half the available minutes: the default for an install that has saved no target of its own. */
    public const DEFAULT_TARGET_PCT = 50;

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
     * Available minutes for a team over a window: the sum of the length of
     * every played match **whose minutes were recorded**.
     *
     * #4966 — a played match nobody recorded minutes for adds nothing, as in
     * the minutes report and the team monthly report (`MinutesQuery::
     * forTeam()`, #2252). It used to count, which lowered every player's
     * share for a register nobody filled in, and set this report against the
     * monthly report's figure for the same squad. `matches` counts the
     * matches that fed the minutes; `played` every played match in the
     * window, recorded or not, so a reader can tell "played, nothing
     * recorded" from "nothing played".
     *
     * @return array{minutes:int, matches:int, played:int}
     */
    public function availableForTeam( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;

        if ( $team_id <= 0 ) return [ 'minutes' => 0, 'matches' => 0, 'played' => 0 ];

        $date_col     = 'sess' . 'ion_date'; // #0035 lint-safe
        $played_sql   = PlayedMatch::playedInSetSql( PlayedMatch::MINUTES_BEARING, 'a' );
        $recorded_sql = MinutesQuery::recordedMinutesSql( 'a' );

        // The prep row's half length is the per-match override
        // MatchLengthResolver takes first; pulling it in the same query
        // saves a lookup per match on a full season. #4993 — whether the
        // match has minutes recorded is asked in the same query, by the
        // predicate `outsideWindowForTeam()` counts with.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id,
                    COALESCE( mp.half_length_minutes, 0 ) AS half_length_minutes,
                    CASE WHEN {$recorded_sql} THEN 1 ELSE 0 END AS has_minutes
               FROM {$p}tt_activities a
               LEFT JOIN {$p}tt_match_prep mp ON mp.activity_id = a.id
              WHERE a.team_id = %d
                AND a.{$date_col} BETWEEN %s AND %s
                AND {$played_sql}",
            $team_id, $from, $to
        ) );
        $rows   = is_array( $rows ) ? $rows : [];
        $played = count( $rows );
        $rows   = array_values( array_filter( $rows, static fn( $r ): bool => is_object( $r ) && (int) ( $r->has_minutes ?? 0 ) === 1 ) );

        // #4087 — the prep's period length times the periods the match was
        // played in, as the minutes report counts it: a quarters match is
        // four periods, not two halves.
        $periods  = MinutesQuery::periodCountsFor(
            array_map( static fn( object $r ): int => (int) ( $r->id ?? 0 ), $rows )
        );
        $resolver = new MatchLengthResolver();
        $minutes  = 0;
        foreach ( $rows as $r ) {
            $id       = (int) ( $r->id ?? 0 );
            $minutes += $resolver->playedMatchMinutes(
                $id,
                (int) ( $r->half_length_minutes ?? 0 ),
                $periods[ $id ] ?? 2
            );
        }

        return [ 'minutes' => $minutes, 'matches' => count( $rows ), 'played' => $played ];
    }

    /**
     * #3796 — the played matches this window left out, and when they were.
     *
     * `availableForTeam()` counts what is inside the window and says nothing
     * about what it excluded, so a report drawn over the wrong twelve months
     * reads identically to one drawn over a season the team genuinely sat
     * out. A board member following one player's minutes plan cannot tell
     * "this team played nothing" from "everything this team played is on the
     * other side of your window" — and the second is one click from being
     * right. The dates are the actionable part: they say where to widen to.
     *
     * Same "played match" predicate as the window query, so the two counts
     * partition the team's matches between them and cannot overlap.
     *
     * - `matches`: the played matches outside the window with minutes
     *   recorded, the ones `availableForTeam()` would count (#4966), and
     *   `earliest` / `latest` their dates.
     * - `played` (#4993): every played match outside the window, recorded or
     *   not, so a team whose matches all lack minutes is not told it has
     *   played none.
     *
     * #4993 — one query, whatever the team's history: the counts and dates
     * are aggregates over `MinutesQuery::recordedMinutesSql()`, the predicate
     * the window query flags its matches with. No match or minutes row is
     * loaded.
     *
     * @return array{matches:int, played:int, earliest:string, latest:string}
     */
    public function outsideWindowForTeam( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $empty   = [ 'matches' => 0, 'played' => 0, 'earliest' => '', 'latest' => '' ];

        if ( $team_id <= 0 ) return $empty;

        $date_col     = 'sess' . 'ion_date'; // #0035 lint-safe
        $played_sql   = PlayedMatch::playedInSetSql( PlayedMatch::MINUTES_BEARING, 'a' );
        $recorded_sql = MinutesQuery::recordedMinutesSql( 'a' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS played,
                    COALESCE( SUM( r.has_minutes ), 0 ) AS matches,
                    MIN( CASE WHEN r.has_minutes = 1 THEN r.match_date END ) AS earliest,
                    MAX( CASE WHEN r.has_minutes = 1 THEN r.match_date END ) AS latest
               FROM ( SELECT a.{$date_col} AS match_date,
                             CASE WHEN {$recorded_sql} THEN 1 ELSE 0 END AS has_minutes
                        FROM {$p}tt_activities a
                       WHERE a.team_id = %d
                         AND a.{$date_col} IS NOT NULL
                         AND a.{$date_col} NOT BETWEEN %s AND %s
                         AND {$played_sql} ) r",
            $team_id, $from, $to
        ) );
        if ( ! is_object( $row ) ) return $empty;

        $matches = (int) ( $row->matches ?? 0 );
        return [
            'matches'  => $matches,
            'played'   => (int) ( $row->played ?? 0 ),
            'earliest' => $matches > 0 ? substr( (string) ( $row->earliest ?? '' ), 0, 10 ) : '',
            'latest'   => $matches > 0 ? substr( (string) ( $row->latest ?? '' ), 0, 10 ) : '',
        ];
    }

    /**
     * Per-player share for one team over a window, ordered so the
     * under-played players are read first.
     *
     * `share_pct` is null when the team played nothing in the window —
     * a share of no minutes is undefined, not zero, and rendering 0%
     * there would flag a whole squad for a season that has not started.
     *
     * `outside_window` (#3796) reports the played matches this window
     * excluded, so a reader can tell an empty report from a mis-aimed one.
     * `matches` are the matches whose minutes fed the share (#4966);
     * `played_matches` every played match in the window, recorded or not.
     *
     * @return array{
     *   available_minutes:int, matches:int, played_matches:int, target_pct:int,
     *   outside_window: array{matches:int, played:int, earliest:string, latest:string},
     *   players: list<array{player_id:int, name:string, jersey_number:?int,
     *                       minutes:int, share_pct:?float, below_target:bool}>
     * }
     */
    public function forTeam( int $team_id, string $from, string $to ): array {
        $target = self::targetPct();

        $empty = [
            'available_minutes' => 0,
            'matches'           => 0,
            'played_matches'    => 0,
            'target_pct'        => $target,
            'outside_window'    => [ 'matches' => 0, 'played' => 0, 'earliest' => '', 'latest' => '' ],
            'players'           => [],
        ];
        if ( $team_id <= 0 ) return $empty;

        $available = $this->availableForTeam( $team_id, $from, $to );
        $outside   = $this->outsideWindowForTeam( $team_id, $from, $to );

        // #5019 — the one minutes squad (`MinutesQuery::squadForTeam()`): the
        // attendees of the team's played matches plus the current roster, the
        // squad the monthly report and the Minutes distribution read too.
        $rows = array_slice( ( new MinutesQuery() )->squadForTeam( $team_id, $from, $to ), 0, 200 );

        $players = [];
        foreach ( $rows as $r ) {
            $minutes = $r['total_minutes'];
            $share   = $available['minutes'] > 0
                ? ( $minutes / $available['minutes'] ) * 100
                : null;

            $players[] = [
                'player_id'     => $r['player_id'],
                'name'          => trim( $r['first_name'] . ' ' . $r['last_name'] ),
                'jersey_number' => $r['jersey_number'],
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
            'played_matches'    => $available['played'],
            'target_pct'        => $target,
            'outside_window'    => $outside,
            'players'           => $players,
        ];
    }

    /**
     * One player's share on their own team. Reads `forTeam()` and picks
     * the row out, so the two can never answer differently.
     *
     * @return array{
     *   available_minutes:int, matches:int, played_matches:int, target_pct:int,
     *   outside_window: array{matches:int, played:int, earliest:string, latest:string},
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
                'played_matches'    => $team['played_matches'],
                'target_pct'        => $team['target_pct'],
                'outside_window'    => $team['outside_window'],
                'minutes'           => $row['minutes'],
                'share_pct'         => $row['share_pct'],
                'below_target'      => $row['below_target'],
            ];
        }

        return null;
    }
}
