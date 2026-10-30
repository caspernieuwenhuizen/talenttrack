<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;

/**
 * InjuryOverviewScope — which teams' injuries may this user read in the
 * squad overview?
 *
 * Asked by the overview screen and by `GET /player-injuries`, so a future
 * front end gets the answer the screen gives (CLAUDE.md §4).
 *
 * Academy-wide readers (`player_injuries` read at global scope, or an
 * administrator) see every team. Everyone else sees the teams they are
 * attached to on which the matrix lets them read injuries, asked per team:
 * injury access follows the functional role (#3257), so a physio of one
 * squad and kit manager of another reads only the first.
 */
final class InjuryOverviewScope {

    /** May this user open the overview at all? */
    public static function canRead( int $user_id, bool $is_admin ): bool {
        return $is_admin || MatrixGate::canAnyScope( $user_id, 'player_injuries', 'read' );
    }

    /** Does this user read every team's injuries? */
    public static function seesAll( int $user_id, bool $is_admin ): bool {
        return $is_admin || MatrixGate::can( $user_id, 'player_injuries', 'read', 'global' );
    }

    /**
     * The teams whose injuries this user may read, as team rows.
     *
     * @return list<object>
     */
    public static function teams( int $user_id, bool $is_admin ): array {
        if ( ! self::canRead( $user_id, $is_admin ) ) return [];

        if ( self::seesAll( $user_id, $is_admin ) ) {
            return array_values( (array) QueryHelpers::get_teams() );
        }

        return array_values( array_filter(
            (array) QueryHelpers::get_teams_for_coach( $user_id ),
            static function ( $t ) use ( $user_id ): bool {
                $vars    = get_object_vars( (object) $t );
                $team_id = isset( $vars['id'] ) ? (int) $vars['id'] : 0;
                return $team_id > 0
                    && MatrixGate::can( $user_id, 'player_injuries', 'read', MatrixGate::SCOPE_TEAM, $team_id );
            }
        ) );
    }

    /**
     * Audit one read of the overview, by the screen or the REST route: one
     * row per read carrying the ids of every player whose injuries were in
     * the returned rows, so a subject-access request for one child can be
     * answered from the log. Nothing is written for an empty read.
     *
     * @param list<object> $rows Rows from `InjuryRepository::listForTeams()`.
     */
    public static function recordRead( string $surface, array $rows ): void {
        if ( $rows === [] ) return;

        $player_ids = [];
        foreach ( $rows as $row ) {
            $pid = (int) ( ( (array) $row )['player_id'] ?? 0 );
            if ( $pid > 0 ) $player_ids[ $pid ] = $pid;
        }

        ( new AuditService() )->record( 'player.injuries_viewed', 'club', 0, [
            'surface'    => $surface,
            'count'      => count( $rows ),
            'player_ids' => array_values( $player_ids ),
        ] );
    }

    /**
     * The team ids to hand `InjuryRepository::listForTeams()`, or null when
     * the requested team is outside the user's scope. An empty list means
     * every team, and is only returned to an academy-wide reader.
     *
     * @param list<object> $teams From `teams()`.
     * @return list<int>|null
     */
    public static function repositoryScope( array $teams, int $team_id, bool $sees_all ): ?array {
        $allowed = [];
        foreach ( $teams as $t ) {
            $vars = get_object_vars( (object) $t );
            $tid  = isset( $vars['id'] ) ? (int) $vars['id'] : 0;
            if ( $tid > 0 ) $allowed[] = $tid;
        }

        if ( $team_id > 0 ) {
            return in_array( $team_id, $allowed, true ) ? [ $team_id ] : null;
        }
        if ( $sees_all ) return [];
        return $allowed === [] ? null : $allowed;
    }
}
