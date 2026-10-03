<?php
namespace TT\Modules\MatchExecution\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AllTeamsScope;

/**
 * MatchExecutionListQuery (#4194) — the read model behind the match
 * executions list: which teams a caller may list, and one page of their
 * executions inside a date window and a state bucket.
 *
 * It used to live inside `FrontendMatchExecutionsListView`. Moved here so
 * `GET /match-executions` and the rendered list give the same answer
 * (CLAUDE.md §4).
 *
 * Scope: a settings admin, or anyone with the academy-wide activities lens
 * (#1942), lists every active team in the club; everyone else the teams
 * `QueryHelpers::get_teams_for_coach()` grants them.
 */
final class MatchExecutionListQuery {

    /** State buckets the list filters on. `live` covers every in-play state. */
    public const STATE_FILTERS = [ 'all', 'live', MatchExecutionState::PENDING_REVIEW, MatchExecutionState::FINALIZED ];

    /** Sort keys a caller may ask for, mapped to their SQL. */
    public const ORDERBY = [
        'session_date' => 'a.session_date',
        'team_name'    => 't.name',
        'opponent'     => 'a.opponent',
        'state'        => 'e.state',
    ];

    /**
     * Active teams the caller may list executions for, by name.
     *
     * @return list<\stdClass> each carrying `id` and `name`
     */
    public static function teamsForUser( int $user_id, bool $is_admin ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        if ( $is_admin || AllTeamsScope::canSeeAllTeamsActivities( $user_id ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$p}tt_teams
                  WHERE club_id = %d AND " . ArchiveRepository::filterClause( 'active' ) . "
                  ORDER BY name ASC",
                $club_id
            ) );
            $out = [];
            foreach ( is_array( $rows ) ? $rows : [] as $t ) {
                $out[] = (object) [ 'id' => (int) $t->id, 'name' => (string) $t->name ];
            }
            return $out;
        }

        $out = [];
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
            if ( ! empty( $t->archived_at ) ) continue;
            $out[] = (object) [ 'id' => (int) $t->id, 'name' => (string) $t->name ];
        }
        return $out;
    }

    /**
     * Default window: the last 365 days (one season back from today).
     *
     * @return array{from:string, to:string}
     */
    public static function defaultWindow(): array {
        return [
            'from' => gmdate( 'Y-m-d', (int) strtotime( '-365 days' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }

    /**
     * One page of executions on the given teams.
     *
     * @param list<int> $team_ids teams to read; an empty list reads nothing
     * @return array{rows: list<\stdClass>, total: int}
     */
    public static function page(
        array $team_ids,
        string $from,
        string $to,
        string $state_filter,
        string $orderby,
        string $order,
        int $page,
        int $per_page
    ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $team_ids === [] ) return [ 'rows' => [], 'total' => 0 ];

        $team_in = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

        $state_clause = '';
        $state_params = [];
        if ( $state_filter === 'live' ) {
            $live         = MatchExecutionState::LIVE;
            $state_clause = 'AND e.state IN (' . implode( ',', array_fill( 0, count( $live ), '%s' ) ) . ')';
            $state_params = $live;
        } elseif ( $state_filter === MatchExecutionState::PENDING_REVIEW || $state_filter === MatchExecutionState::FINALIZED ) {
            $state_clause = 'AND e.state = %s';
            $state_params = [ $state_filter ];
        }

        $sort_sql = self::ORDERBY[ $orderby ] ?? self::ORDERBY['session_date'];
        $dir      = $order === 'asc' ? 'ASC' : 'DESC';

        $from_sql = "FROM {$p}tt_match_execution e
                  INNER JOIN {$p}tt_activities a ON a.id = e.activity_id AND a.club_id = e.club_id
                  LEFT JOIN  {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                 WHERE e.club_id = %d
                   AND a.team_id IN ($team_in)
                   AND a.session_date BETWEEN %s AND %s
                   $state_clause";

        $params = array_merge( [ (int) CurrentClub::id() ], $team_ids, [ $from, $to ], $state_params );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $from_sql", ...$params ) );

        $offset = max( 0, ( $page - 1 ) * $per_page );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id AS execution_id, e.state, e.home_score, e.away_score,
                    a.id AS activity_id, a.session_date, a.opponent, a.team_id,
                    t.name AS team_name
               $from_sql
              ORDER BY $sort_sql $dir, a.session_date DESC, e.id DESC
              LIMIT %d OFFSET %d",
            ...array_merge( $params, [ $per_page, $offset ] )
        ) );

        return [ 'rows' => is_array( $rows ) ? array_values( $rows ) : [], 'total' => $total ];
    }
}
