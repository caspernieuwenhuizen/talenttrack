<?php
namespace TT\Modules\CustomWidgets\DataSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExportScope;

/**
 * SourceScope — the rows a custom widget's viewer may see.
 *
 * A source's capability is held at any scope: a coach holds it for their
 * teams, a parent for their own child. So `requiredCap()` answers whether
 * the viewer reads the entity at all, never whose rows. This narrows the
 * rows with the rule the bulk exports use (`ExportScope`), because a widget
 * is the same list on a dashboard:
 *
 *  - a holder at global scope reads the club, and no clause is added;
 *  - a coach reads the teams they hold the entity on;
 *  - anyone else (a parent, a player, a linked scout, a coach with no
 *    team, or a team filter outside the viewer's teams) gets no rows. A
 *    dashboard gets an empty widget rather than an error.
 *
 * Both a source's `fetch()` and its `aggregate()` add the clause, so a KPI
 * counts exactly the rows the table would list.
 */
final class SourceScope {

    /**
     * A WHERE clause narrowing `$team_column` to the viewer's teams.
     */
    public static function teamClause( int $user_id, string $entity, string $team_column, int $requested_team = 0 ): string {
        $team_ids = self::teamIds( $user_id, $entity, $requested_team );
        if ( $team_ids === null ) return '1 = 1';
        return ExportScope::inClause( $team_column, $team_ids );
    }

    /**
     * A WHERE clause narrowing `$player_column` to the players of the
     * viewer's teams, for a source whose rows hang off a player.
     */
    public static function playerClause( int $user_id, string $entity, string $player_column, int $requested_team = 0 ): string {
        $team_ids = self::teamIds( $user_id, $entity, $requested_team );
        if ( $team_ids === null ) return '1 = 1';

        global $wpdb;
        return $player_column . " IN ( SELECT tt_scope_pl.id FROM {$wpdb->prefix}tt_players tt_scope_pl WHERE "
            . ExportScope::inClause( 'tt_scope_pl.team_id', $team_ids ) . ' )';
    }

    /**
     * Null for every team; an empty list when the viewer may see none.
     *
     * @return list<int>|null
     */
    private static function teamIds( int $user_id, string $entity, int $requested_team ): ?array {
        if ( $user_id <= 0 ) return [];
        try {
            return ExportScope::teamIdsFor( $user_id, $entity, $requested_team );
        } catch ( ExportException $e ) {
            return [];
        }
    }
}
