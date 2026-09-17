<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

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
 * fixtures land in `record.without_a_score` and are excluded from `played`,
 * W/D/L and the goal columns. Counting them as a scoreless draw would make the
 * record quietly wrong, which is worse than an obvious gap.
 *
 * Only fixtures whose date has passed are considered at all — next week's
 * league game is not a match missing its result.
 *
 * ## What is not computed here
 *
 * Goals and assists come from {@see GoalContributionQuery}, appearances and
 * minutes from {@see MinutesQuery}. Only the record is new SQL. That is what
 * makes the statistics tab's goals reconcile exactly with the minutes report's
 * goals rather than approximately, which is the whole reason #2859 put the
 * counting in one place.
 *
 * Clean sheets are team-level and deliberately unattributed: the plugin
 * records no per-match goalkeeper, so crediting one would be a guess printed
 * as a fact.
 */
final class TeamMatchStatsQuery {

    /**
     * Types that carry a single scoreline. Deliberately **not**
     * `ActivityTypeKey::MATCH_LIKE`, which includes `tournament`.
     *
     * As an SQL value list, following `ActivityTypeKey::MATCH_LIKE_SQL`: a
     * constant expression over class constants, so it stays a literal-string
     * for the prepared-statement analyser and can never carry request input.
     */
    private const FIXTURE_TYPES_SQL = "'" . ActivityTypeKey::GAME . "','" . ActivityTypeKey::LEGACY_GAME . "'";

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
            'form'        => $this->form( $fixtures, $limit ),
            'scorers'     => $this->leaderboard( $contributions, 'goals', $names ),
            'assists'     => $this->leaderboard( $contributions, 'assists', $names ),
            'appearances' => $this->appearances( $minutes, $names ),
        ];
    }

    /**
     * The fixtures themselves, one row per match, newest first — and
     * optionally who played in each and for how long.
     *
     * #3516 — the monthly report's results section needs the matches listed
     * rather than totalled, and it must list exactly the fixtures
     * {@see forTeam()} counted. Two readers with two ideas of what a fixture
     * is would print a record that does not add up to the list beneath it, so
     * this shares the same query and the same framing.
     *
     * A fixture with no recorded score comes back with null scores and a null
     * outcome rather than being dropped: "played, nobody typed the result" is
     * the row a coach most needs to see.
     *
     * @param array{from?:string, to?:string} $filters
     * @return array<string, mixed> `window`, `tournaments_excluded`, and
     *         `matches` — each with `activity_id`, `session_date`, `opponent`,
     *         `home_away`, `team_score`, `opp_score`, `outcome`, and `squad`
     *         when asked for.
     */
    public function perMatchForTeam( int $team_id, array $filters = [], bool $with_squads = false ): array {
        $window = $this->resolveWindow( $filters );
        if ( $team_id <= 0 ) {
            return [ 'window' => $window, 'tournaments_excluded' => 0, 'matches' => [] ];
        }

        $fixtures = $this->fixtures( $team_id, $window['from'], $window['to'] );

        $squads = [];
        if ( $with_squads && $fixtures !== [] ) {
            $squads = $this->squadMinutes( array_map(
                static fn( object $f ): int => (int) ( $f->id ?? 0 ),
                $fixtures
            ) );
        }

        $matches = [];
        foreach ( array_reverse( $fixtures ) as $fixture ) {
            $id     = (int) ( $fixture->id ?? 0 );
            $result = $this->frame( $fixture );

            $row = $result ?? [
                'activity_id'  => $id,
                'session_date' => (string) ( $fixture->session_date ?? '' ),
                'opponent'     => (string) ( $fixture->opponent ?? '' ),
                'home_away'    => ( (string) ( $fixture->home_away ?? '' ) ) === 'away' ? 'away' : 'home',
                'team_score'   => null,
                'opp_score'    => null,
                'outcome'      => null,
            ];

            if ( $with_squads ) {
                $row['squad'] = $squads[ $id ] ?? [];
            }

            $matches[] = $row;
        }

        return [
            'window'               => $window,
            'tournaments_excluded' => $this->tournamentCount( $team_id, $window['from'], $window['to'] ),
            'matches'              => $matches,
        ];
    }

    /**
     * Who played in each of these fixtures, and for how long.
     *
     * Effective minutes are `COALESCE(minutes_override, minutes_played)` —
     * the same expression {@see MinutesQuery} reads, so a coach's explicit
     * override on the match-execution surface is what both report. One query
     * across the whole window rather than one per match: a season's fixtures
     * would otherwise be forty round trips to print one table.
     *
     * @param list<int> $activity_ids
     * @return array<int, list<array{player_id:int, name:string, jersey_number:?int, minutes:int}>>
     */
    private function squadMinutes( array $activity_ids ): array {
        $ids = array_values( array_filter( $activity_ids, static fn( int $id ): bool => $id > 0 ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        $p            = $wpdb->prefix;
        $club_id      = (int) CurrentClub::id();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.activity_id, a.player_id,
                    SUM( COALESCE(a.minutes_override, a.minutes_played) ) AS minutes,
                    p.first_name, p.last_name, p.jersey_number
               FROM {$p}tt_attendance a
          LEFT JOIN {$p}tt_players p ON p.id = a.player_id AND p.club_id = a.club_id
              WHERE a.club_id = %d
                AND a.activity_id IN ({$placeholders})
                AND a.record_type = 'actual'
                AND a.is_guest = 0
                AND COALESCE(a.minutes_override, a.minutes_played) > 0
           GROUP BY a.activity_id, a.player_id, p.first_name, p.last_name, p.jersey_number",
            $club_id, ...$ids
        ) );

        $out = [];
        foreach ( $rows ?? [] as $row ) {
            $activity_id = (int) ( $row->activity_id ?? 0 );
            $jersey      = $row->jersey_number ?? null;
            $out[ $activity_id ][] = [
                'player_id'     => (int) ( $row->player_id ?? 0 ),
                'name'          => trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) ),
                'jersey_number' => is_numeric( $jersey ) ? (int) $jersey : null,
                'minutes'       => (int) ( $row->minutes ?? 0 ),
            ];
        }

        // Shirt order, the rule every other player table in the report follows.
        foreach ( $out as $activity_id => $squad ) {
            $out[ $activity_id ] = PlayerOrder::sort( $squad, PlayerOrder::jerseys( $squad ) );
        }

        return $out;
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

        $season = ( new SeasonsRepository() )->current();
        if ( $season && ! empty( $season->start_date ) && ! empty( $season->end_date ) ) {
            return [
                'from'   => (string) $season->start_date,
                'to'     => (string) $season->end_date,
                'source' => 'season',
                'season' => (string) ( $season->name ?? '' ),
            ];
        }

        // No season on the install. '1970-01-01' rather than an open-ended
        // clause keeps every read on one BETWEEN shape.
        return [ 'from' => '1970-01-01', 'to' => '2999-12-31', 'source' => 'all_time', 'season' => '' ];
    }

    // ──────────────────────────────────────────────────────────────
    // Record and form — the only new SQL in the class
    // ──────────────────────────────────────────────────────────────

    /**
     * The team's fixtures in the window: past, live, not called off, and
     * carrying a single scoreline (so: no tournaments).
     *
     * `completedClause()` is deliberately not used. A match nobody marked
     * completed is exactly the match nobody typed a result into, and those are
     * the rows `without_a_score` exists to surface.
     *
     * @return list<object>
     */
    private function fixtures( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 'a', 'activity' );

        /** @var list<object>|null $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.session_date, a.opponent, a.home_away, a.home_score, a.away_score
               FROM {$p}tt_activities a
              WHERE a.team_id = %d
                AND a.club_id = %d
                AND LOWER(a.activity_type_key) IN (" . self::FIXTURE_TYPES_SQL . ")
                AND a.session_date <= CURDATE()
                AND a.session_date BETWEEN %s AND %s
                AND " . ArchiveRepository::filterClause( 'active', 'a' ) . "
                AND " . ActivityLifecycle::notCancelledClause( 'a' ) . "
                AND a.plan_state <> 'cancelled'
                {$scope}
           ORDER BY a.session_date ASC, a.id ASC",
            $team_id, CurrentClub::id(), $from, $to
        ) );

        return $rows ?? [];
    }

    /**
     * How many tournament days fall in the same window. Not a result — a
     * number the caller prints beside the record so it adds up to the season
     * the coach remembers.
     */
    private function tournamentCount( int $team_id, string $from, string $to ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 'a', 'activity' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$p}tt_activities a
              WHERE a.team_id = %d
                AND a.club_id = %d
                AND LOWER(a.activity_type_key) = 'tournament'
                AND a.session_date <= CURDATE()
                AND a.session_date BETWEEN %s AND %s
                AND " . ArchiveRepository::filterClause( 'active', 'a' ) . "
                AND " . ActivityLifecycle::notCancelledClause( 'a' ) . "
                AND a.plan_state <> 'cancelled'
                {$scope}",
            $team_id, CurrentClub::id(), $from, $to
        ) );
    }

    /**
     * @param list<object> $fixtures
     * @return array{played:int, won:int, drawn:int, lost:int, goals_for:int,
     *               goals_against:int, goal_difference:int, clean_sheets:int,
     *               without_a_score:int, tournaments_excluded:int}
     */
    private function record( array $fixtures, int $tournaments ): array {
        $out = [
            'played'               => 0,
            'won'                  => 0,
            'drawn'                => 0,
            'lost'                 => 0,
            'goals_for'            => 0,
            'goals_against'        => 0,
            'goal_difference'      => 0,
            'clean_sheets'         => 0,
            'without_a_score'      => 0,
            'tournaments_excluded' => $tournaments,
        ];

        foreach ( $fixtures as $fixture ) {
            $result = $this->frame( $fixture );
            if ( $result === null ) {
                $out['without_a_score']++;
                continue;
            }

            $out['played']++;
            $out['goals_for']     += $result['team_score'];
            $out['goals_against'] += $result['opp_score'];

            if ( $result['outcome'] === 'W' )      $out['won']++;
            elseif ( $result['outcome'] === 'L' )  $out['lost']++;
            else                                   $out['drawn']++;

            if ( $result['opp_score'] === 0 ) $out['clean_sheets']++;
        }

        $out['goal_difference'] = $out['goals_for'] - $out['goals_against'];

        return $out;
    }

    /**
     * The last results, most recent first. Drawn from the fixtures already
     * fetched for the record rather than from a second query, so the form line
     * can never describe a different set of matches than the record above it.
     *
     * @param list<object> $fixtures
     * @return list<array{activity_id:int, session_date:string, opponent:string,
     *                    home_away:string, team_score:int, opp_score:int, outcome:string}>
     */
    private function form( array $fixtures, int $limit ): array {
        $out = [];
        foreach ( array_reverse( $fixtures ) as $fixture ) {
            $result = $this->frame( $fixture );
            if ( $result === null ) continue;
            $out[] = $result;
            if ( count( $out ) >= $limit ) break;
        }
        return $out;
    }

    /**
     * One fixture from the academy's side, or null when it carries no result.
     *
     * Home or away is read the way `ActivitiesRepository::recentResultsForTeam()`
     * reads it — the academy team is home unless the row says `away` — so the
     * two never disagree about which way round a scoreline goes.
     *
     * @return array{activity_id:int, session_date:string, opponent:string,
     *               home_away:string, team_score:int, opp_score:int, outcome:string}|null
     */
    private function frame( object $fixture ): ?array {
        $home = $fixture->home_score ?? null;
        $away = $fixture->away_score ?? null;
        if ( $home === null || $away === null || $home === '' || $away === '' ) {
            return null;
        }

        $is_home = ( (string) ( $fixture->home_away ?? '' ) ) !== 'away';
        $team    = (int) ( $is_home ? $home : $away );
        $opp     = (int) ( $is_home ? $away : $home );

        return [
            'activity_id'  => (int) ( $fixture->id ?? 0 ),
            'session_date' => (string) ( $fixture->session_date ?? '' ),
            'opponent'     => (string) ( $fixture->opponent ?? '' ),
            'home_away'    => $is_home ? 'home' : 'away',
            'team_score'   => $team,
            'opp_score'    => $opp,
            'outcome'      => $team > $opp ? 'W' : ( $team < $opp ? 'L' : 'D' ),
        ];
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
