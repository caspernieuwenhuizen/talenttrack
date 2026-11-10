<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Domain\ContributionsCeiling;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * GoalContributionQuery (#2859) — goals and assists per player, read from
 * the match-execution goal log.
 *
 * Until this existed, `tt_match_execution_goal_events` had no reader outside
 * its own module: a player could score a hat-trick and nothing on their
 * record would say so. The plugin measured a player's **exposure** (minutes,
 * via {@see MinutesQuery}) and a coach's **judgement** (evaluations, tracked
 * actions) but never their **output**, which is half of what a conversation
 * about an attacker is actually about.
 *
 * Counting rules, which are the whole substance of this class:
 *
 *   - **Goals** are ours (`team = 'home'`), not own goals, with a scorer.
 *     A goal nobody attributed (`player_id = 0`) belongs to the match, not
 *     to a player, and is counted for nobody rather than being guessed at.
 *   - **Assists** are credited from `assist_player_id`, independently of who
 *     scored. A player credited with a goal and an assist in the same match
 *     counts once in each.
 *   - **Own goals** are tracked but never added to a scorer's goal tally.
 *     Putting one into your own net is not an attacking contribution, and a
 *     tally that quietly said otherwise would be worse than not showing it.
 *   - **Reversed** (undone) events count for nobody, on either side.
 *
 * Reads are club-scoped and filtered to real, non-cancelled match activities,
 * matching the set {@see MinutesQuery} counts, so goals and minutes on the
 * same screen always describe the same matches.
 */
final class GoalContributionQuery {

    /**
     * One player's contribution over a window.
     *
     * @param array{from?:string, to?:string, team_id?:int} $filters
     * @return array{
     *     player_id:int, goals:int, assists:int, own_goals:int,
     *     contributions:int, matches_with_a_contribution:int,
     *     per_match:array<int, array{activity_id:int, session_date:string, goals:int, assists:int}>
     * }
     */
    public function forPlayer( int $player_id, array $filters = [] ): array {
        $empty = [
            'player_id'                   => $player_id,
            'goals'                       => 0,
            'assists'                     => 0,
            'own_goals'                   => 0,
            'contributions'               => 0,
            'matches_with_a_contribution' => 0,
            'per_match'                   => [],
        ];
        if ( $player_id <= 0 ) return $empty;

        $filters['player_id'] = $player_id;
        $rows = $this->fetchEvents( $filters );
        if ( empty( $rows ) ) return $empty;

        $per_match = [];
        $own_goals = 0;

        foreach ( $rows as $row ) {
            $aid    = (int) ( $row['activity_id'] ?? 0 );
            $date   = (string) ( $row['session_date'] ?? '' );
            $credit = self::credits( $row )[ $player_id ] ?? null;
            if ( $credit === null ) continue;

            $scored   = $credit['goals'] > 0;
            $assisted = $credit['assists'] > 0;

            $own_goals += $credit['own_goals'];
            if ( ! $scored && ! $assisted ) continue;

            if ( ! isset( $per_match[ $aid ] ) ) {
                $per_match[ $aid ] = [
                    'activity_id'  => $aid,
                    'session_date' => $date,
                    'goals'        => 0,
                    'assists'      => 0,
                ];
            }
            if ( $scored )   $per_match[ $aid ]['goals']++;
            if ( $assisted ) $per_match[ $aid ]['assists']++;
        }

        $goals = 0;
        $assists = 0;
        foreach ( $per_match as $m ) {
            $goals   += $m['goals'];
            $assists += $m['assists'];
        }

        return [
            'player_id'                   => $player_id,
            'goals'                       => $goals,
            'assists'                     => $assists,
            'own_goals'                   => $own_goals,
            'contributions'               => $goals + $assists,
            'matches_with_a_contribution' => count( $per_match ),
            'per_match'                   => $per_match,
        ];
    }

    /**
     * Per-player totals across a team's matches in the window. Only players
     * who actually contributed appear; the caller joins this onto its own
     * roster so a player with none renders a zero rather than vanishing.
     *
     * @param array{from?:string, to?:string} $filters
     * @return array<int, array{player_id:int, goals:int, assists:int, own_goals:int, contributions:int}>
     */
    public function forTeam( int $team_id, array $filters = [] ): array {
        if ( $team_id <= 0 ) return [];

        $filters['team_id'] = $team_id;
        $rows = $this->fetchEvents( $filters );
        if ( empty( $rows ) ) return [];

        $out = [];
        $touch = static function ( array &$out, int $pid ): void {
            if ( $pid > 0 && ! isset( $out[ $pid ] ) ) {
                $out[ $pid ] = [
                    'player_id'     => $pid,
                    'goals'         => 0,
                    'assists'       => 0,
                    'own_goals'     => 0,
                    'contributions' => 0,
                ];
            }
        };

        foreach ( $rows as $row ) {
            foreach ( self::credits( $row ) as $pid => $credit ) {
                $touch( $out, $pid );
                $out[ $pid ]['goals']     += $credit['goals'];
                $out[ $pid ]['assists']   += $credit['assists'];
                $out[ $pid ]['own_goals'] += $credit['own_goals'];
            }
        }

        foreach ( $out as $pid => $row ) {
            $out[ $pid ]['contributions'] = $row['goals'] + $row['assists'];
        }

        return $out;
    }

    /**
     * Goals of ours with a scorer entered, over exactly the fixtures given
     * (#4079). The monthly report compares this with the goals in its match
     * record, which leaves tournaments out, so the count has to be taken
     * over the same fixtures or the line reads "9 of 8 goals attributed".
     * The scorers table keeps tournament goals; this number does not.
     *
     * @param list<int> $activity_ids
     */
    public function attributedGoalsIn( int $team_id, array $activity_ids ): int {
        $activity_ids = array_values( array_filter( array_map( 'intval', $activity_ids ), static fn( int $id ): bool => $id > 0 ) );
        if ( $team_id <= 0 || $activity_ids === [] ) return 0;

        $count = 0;
        foreach ( $this->fetchEvents( [ 'team_id' => $team_id, 'activity_ids' => $activity_ids ] ) as $row ) {
            foreach ( self::credits( $row ) as $credit ) {
                $count += $credit['goals'];
            }
        }
        return $count;
    }

    /**
     * The counting rule, for one goal event: who it credits, and with what.
     * Every reader in this class counts through here, so the drill-down lists
     * exactly the rows the totals were taken from (#4994).
     *
     *   - a goal of ours that is not an own goal credits its scorer;
     *   - an own goal is tallied against the player who put it in;
     *   - an assist credits the assister on any non-reversed row. That
     *     includes an opponent goal or an own goal, which no real assist can
     *     be: the drill-down flags it rather than this rule dropping it, so
     *     the totals every surface shows stay what they were.
     *
     * @param array<string, mixed> $row
     * @return array<int, array{goals:int, assists:int, own_goals:int}> player id => credit
     */
    private static function credits( array $row ): array {
        $scorer  = (int) ( $row['player_id'] ?? 0 );
        $assist  = (int) ( $row['assist_player_id'] ?? 0 );
        $own     = ! empty( $row['is_own_goal'] );
        $is_ours = ( (string) ( $row['team'] ?? 'home' ) === 'home' );

        $out  = [];
        $zero = [ 'goals' => 0, 'assists' => 0, 'own_goals' => 0 ];

        if ( $scorer > 0 && $own ) {
            $out[ $scorer ] = $zero;
            $out[ $scorer ]['own_goals'] = 1;
        } elseif ( $scorer > 0 && $is_ours ) {
            $out[ $scorer ] = $zero;
            $out[ $scorer ]['goals'] = 1;
        }

        if ( $assist > 0 ) {
            if ( ! isset( $out[ $assist ] ) ) $out[ $assist ] = $zero;
            $out[ $assist ]['assists']++;
        }

        return $out;
    }

    /**
     * #4994 — the window a drill-down or the Goals and assists report reads,
     * from a `season` id or a `from` / `to` pair, as the REST route and the
     * report page both take them. Neither given: the current season, or all
     * time when the academy has none.
     *
     * `error` is '' when the window is usable, `bad_filter` for a season that
     * is not this academy's, `bad_date` for a malformed or backwards pair.
     *
     * @return array{error:string, from:string, to:string, season_id:int, season:string, source:string}
     */
    public static function resolveWindow( string $season, string $from, string $to ): array {
        $out = [ 'error' => '', 'from' => '', 'to' => '', 'season_id' => 0, 'season' => '', 'source' => '' ];
        $season = trim( $season );
        $from   = trim( $from );
        $to     = trim( $to );
        $repo   = new SeasonsRepository();

        if ( $season !== '' ) {
            $row = ctype_digit( $season ) ? $repo->find( (int) $season ) : null;
            if ( ! $row || empty( $row->start_date ) || empty( $row->end_date ) ) {
                $out['error'] = 'bad_filter';
                return $out;
            }
            return array_merge( $out, [
                'from'      => (string) $row->start_date,
                'to'        => (string) $row->end_date,
                'season_id' => (int) ( ( (array) $row )['id'] ?? 0 ),
                'season'    => (string) ( $row->name ?? '' ),
                'source'    => 'season',
            ] );
        }

        if ( $from !== '' || $to !== '' ) {
            $iso = '/^\d{4}-\d{2}-\d{2}$/';
            if ( ! preg_match( $iso, $from ) || ! preg_match( $iso, $to ) || $from > $to ) {
                $out['error'] = 'bad_date';
                return $out;
            }
            return array_merge( $out, [ 'from' => $from, 'to' => $to, 'source' => 'custom' ] );
        }

        $current = $repo->current();
        if ( $current && ! empty( $current->start_date ) && ! empty( $current->end_date ) ) {
            return array_merge( $out, [
                'from'      => (string) $current->start_date,
                'to'        => (string) $current->end_date,
                'season_id' => (int) ( ( (array) $current )['id'] ?? 0 ),
                'season'    => (string) ( $current->name ?? '' ),
                'source'    => 'season',
            ] );
        }

        // The same open window TeamMatchStatsQuery uses when no season is set.
        return array_merge( $out, [ 'from' => '1970-01-01', 'to' => '2999-12-31', 'source' => 'all_time' ] );
    }

    /**
     * Whether a goal event is a goal of ours that is not an own goal.
     *
     * @param array<string, mixed> $row
     */
    private static function isOurGoal( array $row ): bool {
        return (string) ( $row['team'] ?? 'home' ) === 'home' && empty( $row['is_own_goal'] );
    }

    /**
     * #4994 — every goal event behind one player's goals and assists for a
     * team over a window, grouped per match, with the provenance of each
     * entry and the flags that point at a wrong one.
     *
     * The match totals the flags need (all of the match's goals and assists,
     * not only this player's) come from the same read: the team's events over
     * the window, in one statement, joined to the activity, the two player
     * names and the two account names. No query runs per match.
     *
     * The per-match `goals` and `assists` are counted through {@see credits()},
     * so they add up to {@see forTeam()}'s numbers for the player.
     *
     * @param array{from?:string, to?:string} $filters
     * @return array{
     *     team_id:int, player_id:int, goals:int, assists:int, own_goals:int,
     *     matches:list<array<string, mixed>>
     * }
     */
    public function eventsFor( int $team_id, int $player_id, array $filters = [] ): array {
        $out = [
            'team_id'   => $team_id,
            'player_id' => $player_id,
            'goals'     => 0,
            'assists'   => 0,
            'own_goals' => 0,
            'matches'   => [],
        ];
        if ( $team_id <= 0 || $player_id <= 0 ) return $out;

        $filters['team_id'] = $team_id;
        $rows = $this->fetchEvents( $filters, true );

        // Match totals, over every event of the match.
        $totals = [];
        foreach ( $rows as $row ) {
            $aid = (int) ( $row['activity_id'] ?? 0 );
            if ( ! isset( $totals[ $aid ] ) ) $totals[ $aid ] = [ 'goals' => 0, 'assists' => 0 ];
            foreach ( self::credits( $row ) as $credit ) {
                $totals[ $aid ]['goals']   += $credit['goals'];
                $totals[ $aid ]['assists'] += $credit['assists'];
            }
        }

        $matches = [];
        foreach ( $rows as $row ) {
            $credit = self::credits( $row )[ $player_id ] ?? null;
            if ( $credit === null ) continue;

            $aid = (int) ( $row['activity_id'] ?? 0 );
            if ( ! isset( $matches[ $aid ] ) ) {
                $matches[ $aid ] = self::matchFrame( $row );
            }

            $matches[ $aid ]['goals']     += $credit['goals'];
            $matches[ $aid ]['assists']   += $credit['assists'];
            $matches[ $aid ]['own_goals'] += $credit['own_goals'];

            $event_flags = [];
            if ( $credit['assists'] > 0 && ! self::isOurGoal( $row ) ) {
                $event_flags[] = 'assist_on_other_goal';
            }
            $matches[ $aid ]['events'][] = self::eventFrame( $row, $credit, $event_flags );
        }

        foreach ( $matches as $aid => $m ) {
            $score = $m['team_score'];
            $flags = [];
            if ( ContributionsCeiling::matchOver( $score, $totals[ $aid ]['assists'] ?? 0 ) ) $flags[] = 'assists_exceed_score';
            if ( ContributionsCeiling::matchOver( $score, $totals[ $aid ]['goals'] ?? 0 ) )   $flags[] = 'goals_exceed_score';
            if ( ContributionsCeiling::playerOver( $score, $m['goals'] ) || ContributionsCeiling::playerOver( $score, $m['assists'] ) ) {
                $flags[] = $score === null ? 'player_over_cap' : 'player_over_score';
            }
            foreach ( $m['events'] as $e ) {
                if ( in_array( 'assist_on_other_goal', $e['flags'], true ) ) {
                    $flags[] = 'assist_on_other_goal';
                    break;
                }
            }
            $matches[ $aid ]['match_goals']   = (int) ( $totals[ $aid ]['goals'] ?? 0 );
            $matches[ $aid ]['match_assists'] = (int) ( $totals[ $aid ]['assists'] ?? 0 );
            $matches[ $aid ]['flags']         = $flags;

            $out['goals']     += $m['goals'];
            $out['assists']   += $m['assists'];
            $out['own_goals'] += $m['own_goals'];
        }

        $out['matches'] = array_values( $matches );
        return $out;
    }

    /**
     * The match a drill-down row belongs to. `home_score` is ours whatever the
     * venue (#4066); no score recorded is null, never 0.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function matchFrame( array $row ): array {
        $home  = $row['home_score'] ?? null;
        $away  = $row['away_score'] ?? null;
        $ours  = ( $home !== null && $home !== '' ) ? (int) $home : null;
        $their = ( $away !== null && $away !== '' ) ? (int) $away : null;

        return [
            'activity_id'   => (int) ( $row['activity_id'] ?? 0 ),
            'date'          => (string) ( $row['session_date'] ?? '' ),
            'title'         => (string) ( $row['activity_title'] ?? '' ),
            'opponent'      => (string) ( $row['opponent'] ?? '' ),
            'home_away'     => (string) ( $row['home_away'] ?? '' ),
            'is_tournament' => strtolower( (string) ( $row['activity_type_key'] ?? '' ) ) === ActivityTypeKey::TOURNAMENT,
            'team_score'    => $ours,
            'opp_score'     => $their,
            'goals'         => 0,
            'assists'       => 0,
            'own_goals'     => 0,
            'match_goals'   => 0,
            'match_assists' => 0,
            'flags'         => [],
            'events'        => [],
        ];
    }

    /**
     * One goal event as the drill-down shows it. A row written before #4994
     * carries no `source`: it is derived from `execution_id` and marked as
     * such, and no author is invented for it.
     *
     * @param array<string, mixed>                          $row
     * @param array{goals:int, assists:int, own_goals:int}  $credit
     * @param list<string>                                  $flags
     * @return array<string, mixed>
     */
    private static function eventFrame( array $row, array $credit, array $flags ): array {
        $stored  = (string) ( $row['source'] ?? '' );
        $derived = $stored === '';
        $source  = $derived
            ? ( ( $row['execution_id'] ?? null ) !== null ? MatchExecutionRepository::SOURCE_LIVE_SHEET : MatchExecutionRepository::SOURCE_MATCH_FORM )
            : $stored;

        $half   = ( $row['half'] ?? null ) !== null ? (int) $row['half'] : null;
        $minute = ( $row['minute_in_half'] ?? null ) !== null ? (int) $row['minute_in_half'] : null;
        $as     = $credit['goals'] > 0 ? 'goal' : ( $credit['own_goals'] > 0 ? 'own_goal' : 'assist' );
        if ( $credit['assists'] > 0 && $as !== 'assist' ) $as = 'goal_and_assist';

        return [
            'event_uuid'      => (string) ( $row['event_uuid'] ?? '' ),
            'credited_as'     => $as,
            'team'            => (string) ( $row['team'] ?? 'home' ),
            'is_own_goal'     => ! empty( $row['is_own_goal'] ),
            'half'            => $half,
            'minute'          => $minute,
            'scorer_id'       => (int) ( $row['player_id'] ?? 0 ),
            'scorer'          => trim( (string) ( $row['scorer_first'] ?? '' ) . ' ' . (string) ( $row['scorer_last'] ?? '' ) ),
            'assister_id'     => (int) ( $row['assist_player_id'] ?? 0 ),
            'assister'        => trim( (string) ( $row['assister_first'] ?? '' ) . ' ' . (string) ( $row['assister_last'] ?? '' ) ),
            'source'          => $source,
            'source_derived'  => $derived,
            'created_at'      => (string) ( $row['created_at'] ?? '' ),
            'created_by'      => (int) ( $row['created_by'] ?? 0 ),
            'created_by_name' => (string) ( $row['created_by_name'] ?? '' ),
            'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
            'updated_by'      => (int) ( $row['updated_by'] ?? 0 ),
            'updated_by_name' => (string) ( $row['updated_by_name'] ?? '' ),
            'flags'           => $flags,
        ];
    }

    /**
     * Non-reversed goal events joined to their activity, club-scoped and
     * windowed. One query; the counting happens above so both entry points
     * apply exactly the same rules.
     *
     * Rows come back as associative arrays rather than objects: every column
     * here is read through a `??` default, and an array says that plainly
     * where a stdClass only says it to the reader who already knows.
     *
     * `$detail` (#4994) adds what the drill-down shows: the event's own
     * identity, minute and provenance, the activity's opponent, venue and
     * score, and the scorer's, assister's and authors' names, in the same
     * statement. The counting readers leave it off.
     *
     * @param array{from?:string, to?:string, team_id?:int, player_id?:int, activity_ids?:list<int>} $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchEvents( array $filters, bool $detail = false ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $from = isset( $filters['from'] ) ? (string) $filters['from'] : '';
        $to   = isset( $filters['to'] ) ? (string) $filters['to'] : '';
        $team = isset( $filters['team_id'] ) ? (int) $filters['team_id'] : 0;

        // The match types MinutesQuery counts, so goals and minutes describe
        // the same matches.
        $is_match_sql = ActivityTypeKey::matchTypeClause( 'a.activity_type_key' );
        $params = [ $club_id, $club_id ];

        $where = "ge.club_id = %d
                    AND ge.reversed_at IS NULL
                    AND a.club_id = %d
                    AND {$is_match_sql}
                    AND a.archived_at IS NULL
                    AND a.trashed_at IS NULL
                    AND " . ActivityLifecycle::notCancelledClause( 'a' );

        if ( $team > 0 ) {
            $where .= ' AND a.team_id = %d';
            $params[] = $team;
        }
        if ( $from !== '' && $to !== '' ) {
            $where .= ' AND a.session_date BETWEEN %s AND %s';
            $params[] = $from;
            $params[] = $to;
        }
        // A player profile only needs the events that mention them. Narrowing
        // here rather than in PHP keeps the read proportional to one player's
        // contribution instead of the whole club's goal log.
        $player = isset( $filters['player_id'] ) ? (int) $filters['player_id'] : 0;
        if ( $player > 0 ) {
            $where .= ' AND ( ge.player_id = %d OR ge.assist_player_id = %d )';
            $params[] = $player;
            $params[] = $player;
        }
        $ids = $filters['activity_ids'] ?? [];
        if ( $ids !== [] ) {
            $where .= ' AND a.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
            foreach ( $ids as $id ) $params[] = $id;
        }

        // #3094 — joined on the goal's own `activity_id` rather than through
        // its execution. The old INNER JOIN was what made a goal impossible
        // to record without a live match sheet: no execution, no row in the
        // result, and a club that does its admin on a Sunday evening had
        // players whose output was permanently blank.
        //
        // A manual goal has no half and no minute, so both sort NULL. MySQL
        // sorts NULL first ascending, which puts a remembered goal ahead of
        // the observed ones within its own match — the honest order, since
        // nobody knows when it happened.
        $columns = "ge.player_id, ge.assist_player_id, ge.is_own_goal, ge.team,
                       a.id AS activity_id, a.session_date, a.team_id";
        $joins   = '';
        if ( $detail ) {
            $columns .= ", ge.event_uuid, ge.execution_id, ge.half, ge.minute_in_half,
                       ge.created_at, ge.source, ge.created_by, ge.updated_at, ge.updated_by,
                       a.title AS activity_title, a.opponent, a.home_away, a.home_score, a.away_score,
                       a.activity_type_key,
                       ps.first_name AS scorer_first, ps.last_name AS scorer_last,
                       pa.first_name AS assister_first, pa.last_name AS assister_last,
                       uc.display_name AS created_by_name, uu.display_name AS updated_by_name";
            $joins = "LEFT JOIN {$p}tt_players ps ON ps.id = ge.player_id AND ps.club_id = ge.club_id
                      LEFT JOIN {$p}tt_players pa ON pa.id = ge.assist_player_id AND pa.club_id = ge.club_id
                      LEFT JOIN {$wpdb->users} uc ON uc.ID = ge.created_by
                      LEFT JOIN {$wpdb->users} uu ON uu.ID = ge.updated_by";
        }

        $sql = "SELECT {$columns}
                  FROM {$p}tt_match_execution_goal_events ge
            INNER JOIN {$p}tt_activities a
                    ON a.id = ge.activity_id AND a.club_id = ge.club_id
                 {$joins}
                 WHERE {$where}
              ORDER BY a.session_date ASC, ge.half ASC, ge.minute_in_half ASC, ge.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }
}
