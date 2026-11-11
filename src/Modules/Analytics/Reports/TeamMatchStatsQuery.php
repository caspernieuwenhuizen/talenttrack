<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * TeamMatchStatsQuery (#3520, epic #3519) — "how is this team doing", as one
 * answer.
 *
 * Every number here was already recorded and every one of them had a reader
 * that answered a narrower question: {@see GoalContributionQuery} returns
 * output, {@see MinutesQuery} returns exposure, `recentResultsForTeam()`
 * returns the last few results. Nothing returned a team's **record**, and
 * nothing composed the three — so the first surface that wanted all of it
 * would have assembled it in a view file, and the second would have assembled
 * it again, differently.
 *
 * Named for what it reads rather than for the team: `TeamStatsService` already
 * exists under `Infrastructure\Stats` and means evaluation ratings. Match
 * output is a different thing and does not belong on that class.
 *
 * ## Two scopes, not one
 *
 * This is the substance of the class, and the bug it exists to prevent.
 * A tournament is a multi-game day (#2686), so:
 *
 *   - **Record and form are per fixture** — activity type `game` (or the
 *     tolerated legacy `match`), **tournaments excluded**. One score line
 *     cannot describe a day with four of them. Tournaments falling in the
 *     window are counted into `record.tournaments_excluded` so the caller can
 *     say so out loud; silently dropping them is how a record stops matching
 *     what the coach remembers.
 *   - **Scorers, assists and appearances are per player** — tournaments
 *     **included**. A goal at a tournament is still that player's goal.
 *
 * Reusing one window for both is the mistake. `GoalContributionQuery::
 * MATCH_TYPES` spans all three types, which is right for the leaderboards and
 * wrong for the record.
 *
 * ## A match with no score is not a nil-nil
 *
 * Common in practice: the match was played, nobody typed the result in. Those
 * fixtures land in `record.without_a_score` and in `played`, and are excluded
 * from W/D/L and the goal columns. Counting them as a scoreless draw would
 * make the record quietly wrong, which is worse than an obvious gap.
 *
 * Only played fixtures are counted (`PlayedMatch`, #4957) — next week's
 * league game is not a match missing its result.
 *
 * ## What is not computed here
 *
 * Goals and assists come from {@see GoalContributionQuery}, appearances and
 * minutes from {@see MinutesQuery}, and the record from {@see TeamMatchRecord},
 * the read the monthly report's match section shares (#4590). That is what
 * makes the statistics tab's goals reconcile exactly with the minutes report's
 * goals rather than approximately, which is the whole reason #2859 put the
 * counting in one place.
 *
 * Clean sheets are team-level and deliberately unattributed: the plugin
 * records no per-match goalkeeper, so crediting one would be a guess printed
 * as a fact.
 */
final class TeamMatchStatsQuery {

    /** How many results the form line carries unless the caller says otherwise. */
    private const FORM_LIMIT = 5;

    /**
     * A team's match output over a window.
     *
     * Keys, in the order the tab reads them:
     *
     *   - `window`      — `from`, `to`, `source` (`season` | `custom` |
     *                     `all_time`), `season` name where there is one.
     *   - `record`      — `played`, `won`, `drawn`, `lost`, `goals_for`,
     *                     `goals_against`, `goal_difference`, `clean_sheets`,
     *                     `without_a_score`, `tournaments_excluded`.
     *   - `form`        — recent results, newest first: `activity_id`,
     *                     `session_date`, `opponent`, `home_away`,
     *                     `team_score`, `opp_score`, `outcome` (W/D/L).
     *   - `scorers`     — `player_id`, `name`, `jersey_number`, `goals`.
     *   - `assists`     — the same with `assists`.
     *   - `appearances` — `matches`, `starts`, `subs_in`, `minutes`.
     *
     * Documented in `docs/rest-api.md`, which is the contract the REST route
     * and any front end read.
     *
     * @param array{from?:string, to?:string, form_limit?:int} $filters
     *        `from` / `to` default to the current season.
     * @return array<string, mixed>
     */
    public function forTeam( int $team_id, array $filters = [] ): array {
        $window = $this->resolveWindow( $filters );

        if ( $team_id <= 0 ) {
            return $this->emptyResult( $team_id, $window );
        }

        $fixtures = $this->fixtures( $team_id, $window['from'], $window['to'] );
        $limit    = isset( $filters['form_limit'] ) ? max( 1, min( 20, (int) $filters['form_limit'] ) ) : self::FORM_LIMIT;

        $record = $this->record(
            $fixtures,
            $this->tournamentCount( $team_id, $window['from'], $window['to'] )
        );

        $contributions = ( new GoalContributionQuery() )->forTeam( $team_id, [
            'from' => $window['from'],
            'to'   => $window['to'],
        ] );
        $minutes = ( new MinutesQuery() )->forTeam( $team_id, $window['from'], $window['to'] );

        $names = $this->names( array_merge(
            array_keys( $contributions ),
            array_map( static fn( array $row ): int => $row['player_id'], $minutes )
        ) );

        return [
            'team_id'     => $team_id,
            'window'      => $window,
            'record'      => $record,
            'form'        => $this->form( TeamMatchRecord::played( $fixtures ), $limit ),
            'scorers'     => $this->leaderboard( $contributions, 'goals', $names ),
            'assists'     => $this->leaderboard( $contributions, 'assists', $names ),
            'appearances' => $this->appearances( $minutes, $names ),
        ];
    }

    /**
     * {@see self::forTeam()}'s answer as `$user_id` may read it: the
     * per-player rows (`scorers`, `assists`, `appearances`) keep only the
     * players the reader may view, by the player pages' own check, asked once
     * for every row. `record` and `form` are team totals and stay whole.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    public static function forReader( array $stats, int $user_id ): array {
        $keys = [ 'scorers', 'assists', 'appearances' ];

        $ids = [];
        foreach ( $keys as $key ) {
            foreach ( (array) ( $stats[ $key ] ?? [] ) as $row ) {
                $pid = (int) ( $row['player_id'] ?? 0 );
                if ( $pid > 0 ) $ids[ $pid ] = $pid;
            }
        }
        $visible = $ids !== [] ? AuthorizationService::canViewPlayers( $user_id, array_values( $ids ) ) : [];

        foreach ( $keys as $key ) {
            if ( ! isset( $stats[ $key ] ) ) continue;
            $stats[ $key ] = array_values( array_filter(
                (array) $stats[ $key ],
                static fn( $row ): bool => ! empty( $visible[ (int) ( $row['player_id'] ?? 0 ) ] )
            ) );
        }
        return $stats;
    }

    // ──────────────────────────────────────────────────────────────
    // Window
    // ──────────────────────────────────────────────────────────────

    /**
     * The window to read, and which rule produced it.
     *
     * An install with no current season configured falls back to all time and
     * says so, rather than handing back an empty screen the coach cannot
     * explain. `source` is what lets the caller label "12 played" with a
     * period instead of leaving it ambiguous.
     *
     * @param array{from?:string, to?:string} $filters
     * @return array{from:string, to:string, source:string, season:string}
     */
    private function resolveWindow( array $filters ): array {
        $from = isset( $filters['from'] ) ? trim( (string) $filters['from'] ) : '';
        $to   = isset( $filters['to'] ) ? trim( (string) $filters['to'] ) : '';

        if ( $from !== '' && $to !== '' ) {
            return [ 'from' => $from, 'to' => $to, 'source' => 'custom', 'season' => '' ];
        }

        // #4994 — the current season, else all time, by the one rule the
        // goals and assists drill-down this tab links into reads, so the two
        // cannot disagree about which matches a number covers.
        $window = GoalContributionQuery::resolveWindow( '', '', '' );
        return [
            'from'   => $window['from'],
            'to'     => $window['to'],
            'source' => $window['source'],
            'season' => $window['season'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Record and form
    // ──────────────────────────────────────────────────────────────

    /**
     * The team's live fixtures in the window, not called off, and carrying a
     * single scoreline (so: no tournaments), each with `played`. The same
     * read the monthly report's match section counts ({@see TeamMatchRecord},
     * #4590). The record and the form count the played ones (#4957).
     *
     * A match nobody marked completed is still read: it is exactly the match
     * nobody typed a result into, and those are the rows `without_a_score`
     * exists to surface.
     *
     * @return list<array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string, played:bool}>
     */
    private function fixtures( int $team_id, string $from, string $to ): array {
        return TeamMatchRecord::fixtures( $team_id, $from, $to );
    }

    /**
     * How many tournament days were played in the same window. Not a result
     * — a number the caller prints beside the record so it adds up to the
     * season the coach remembers. The monthly report's read (#4957).
     */
    private function tournamentCount( int $team_id, string $from, string $to ): int {
        return ( new ActivitiesRepository() )->tournamentCountInWindow( $team_id, $from, $to );
    }

    /**
     * The tab's record. `played` is the reports' one played-match rule
     * (#4572), the figure the monthly report prints under the same label; a
     * played fixture with no score is also in `without_a_score`.
     *
     * @param list<array{outcome:string, team_score:int|null, opp_score:int|null, played:bool}> $fixtures
     * @return array{played:int, won:int, drawn:int, lost:int, goals_for:int,
     *               goals_against:int, goal_difference:int, clean_sheets:int,
     *               without_a_score:int, tournaments_excluded:int}
     */
    private function record( array $fixtures, int $tournaments ): array {
        $tally = TeamMatchRecord::tally( $fixtures );

        return [
            'played'               => $tally['played'],
            'won'                  => $tally['won'],
            'drawn'                => $tally['drawn'],
            'lost'                 => $tally['lost'],
            'goals_for'            => $tally['goals_for'],
            'goals_against'        => $tally['goals_against'],
            'goal_difference'      => $tally['goal_difference'],
            'clean_sheets'         => $tally['clean_sheets'],
            'without_a_score'      => $tally['without_score'],
            'tournaments_excluded' => $tournaments,
        ];
    }

    /**
     * The last results, most recent first. Drawn from the fixtures already
     * fetched for the record rather than from a second query, so the form line
     * can never describe a different set of matches than the record above it.
     *
     * The scoreline was framed by `MatchResultQuery::frame()` on the way in:
     * `home_score` is the academy's goals whatever the venue (#3530, #4066).
     * `home_away` is only the label; the academy team is home unless the row
     * says `away`.
     *
     * @param list<array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string, played:bool}> $fixtures
     * @return list<array{activity_id:int, session_date:string, opponent:string,
     *                    home_away:string, team_score:int, opp_score:int, outcome:string}>
     */
    private function form( array $fixtures, int $limit ): array {
        $out = [];
        foreach ( array_reverse( $fixtures ) as $fixture ) {
            if ( $fixture['team_score'] === null || $fixture['opp_score'] === null ) continue;
            $out[] = [
                'activity_id'  => $fixture['activity_id'],
                'session_date' => $fixture['date'],
                'opponent'     => $fixture['opponent'],
                'home_away'    => $fixture['home_away'] === 'away' ? 'away' : 'home',
                'team_score'   => $fixture['team_score'],
                'opp_score'    => $fixture['opp_score'],
                'outcome'      => $fixture['outcome'],
            ];
            if ( count( $out ) >= $limit ) break;
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    // Per-player blocks — composed, not computed
    // ──────────────────────────────────────────────────────────────

    /**
     * Contributors only, ranked. A player with none is absent rather than
     * present as a zero: a roster-length table of zeros buries the six names
     * the coach opened the tab to read.
     *
     * @param array<int, array{player_id:int, goals:int, assists:int}> $contributions
     * @param array<int, array{name:string, jersey_number:?int}>       $names
     * @return list<array<string, mixed>>
     */
    private function leaderboard( array $contributions, string $key, array $names ): array {
        $out = [];
        foreach ( $contributions as $player_id => $row ) {
            $count = (int) ( $row[ $key ] ?? 0 );
            if ( $count <= 0 ) continue;
            $out[] = [
                'player_id'     => (int) $player_id,
                'name'          => $names[ (int) $player_id ]['name'] ?? '',
                'jersey_number' => $names[ (int) $player_id ]['jersey_number'] ?? null,
                $key            => $count,
            ];
        }

        usort( $out, static function ( array $a, array $b ) use ( $key ): int {
            $by_count = (int) $b[ $key ] <=> (int) $a[ $key ];
            // Equal tallies break by name so the table does not reshuffle
            // between two renders of the same window.
            return $by_count !== 0 ? $by_count : strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
        } );

        return $out;
    }

    /**
     * @param list<array{player_id:int, first_name:string, last_name:string,
     *                   jersey_number:?int, total_minutes:int, matches:int,
     *                   starts:int, subs_in:int}>                $minutes
     * @param array<int, array{name:string, jersey_number:?int}>  $names
     * @return list<array{player_id:int, name:string, jersey_number:?int,
     *                    matches:int, starts:int, subs_in:int, minutes:int}>
     */
    private function appearances( array $minutes, array $names ): array {
        $out = [];
        foreach ( $minutes as $row ) {
            $player_id = $row['player_id'];
            if ( $player_id <= 0 ) continue;
            $out[] = [
                'player_id'     => $player_id,
                'name'          => $names[ $player_id ]['name'] ?? trim( $row['first_name'] . ' ' . $row['last_name'] ),
                'jersey_number' => $names[ $player_id ]['jersey_number'] ?? $row['jersey_number'],
                'matches'       => $row['matches'],
                'starts'        => $row['starts'],
                'subs_in'       => $row['subs_in'],
                'minutes'       => $row['total_minutes'],
            ];
        }

        usort( $out, static function ( array $a, array $b ): int {
            $by_minutes = $b['minutes'] <=> $a['minutes'];
            return $by_minutes !== 0 ? $by_minutes : strcasecmp( $a['name'], $b['name'] );
        } );

        return $out;
    }

    /**
     * Names and shirt numbers for the players the blocks mention.
     *
     * Looked up by id rather than off the current roster: a player who scored
     * in September and moved up an age group in January still scored, and a
     * leaderboard that dropped them would be wrong about the season.
     *
     * @param list<int> $player_ids
     * @return array<int, array{name:string, jersey_number:?int}>
     */
    private function names( array $player_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( empty( $ids ) ) return [];

        global $wpdb;
        $p            = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.jersey_number
               FROM {$p}tt_players p
              WHERE p.club_id = %d
                AND p.id IN ({$placeholders})",
            CurrentClub::id(), ...$ids
        ) );

        $out = [];
        foreach ( $rows ?? [] as $row ) {
            $jersey = $row->jersey_number ?? null;
            $out[ (int) ( $row->id ?? 0 ) ] = [
                'name'          => trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) ),
                'jersey_number' => is_numeric( $jersey ) ? (int) $jersey : null,
            ];
        }
        return $out;
    }

    /**
     * @param array{from:string, to:string, source:string, season:string} $window
     * @return array<string, mixed>
     */
    private function emptyResult( int $team_id, array $window ): array {
        return [
            'team_id'     => $team_id,
            'window'      => $window,
            'record'      => [
                'played'               => 0,
                'won'                  => 0,
                'drawn'                => 0,
                'lost'                 => 0,
                'goals_for'            => 0,
                'goals_against'        => 0,
                'goal_difference'      => 0,
                'clean_sheets'         => 0,
                'without_a_score'      => 0,
                'tournaments_excluded' => 0,
            ],
            'form'        => [],
            'scorers'     => [],
            'assists'     => [],
            'appearances' => [],
        ];
    }
}
