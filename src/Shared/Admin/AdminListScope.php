<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * AdminListScope — the viewer's team scope, for wp-admin lists and pickers.
 *
 * The frontend and REST siblings of every wp-admin list already narrow: a
 * team-scoped coach sees their own squads and nobody else's. The wp-admin
 * copies were built before the matrix existed and were gated only by a menu
 * capability every coach holds, so they answered "may this person look at
 * players" where the question is "may this person look at *these* players".
 *
 * Nothing here is new policy. It composes the three helpers the REST
 * controllers already use — `user_has_global_entity_read()` for the
 * global-read bypass, `get_teams_for_coach()` for the team-scoped case, and
 * `get_players_for_teams()` for the roster — so wp-admin and REST cannot
 * drift apart on the same question.
 *
 * `null` means "no narrowing" (a global read), which is deliberately
 * different from `[]` — an empty array is a viewer with no teams, and their
 * list is genuinely empty rather than unfiltered.
 */
final class AdminListScope {

    /**
     * Team ids the viewer may see on a surface backed by `$entity`, or null
     * when they hold a global read and no narrowing applies.
     *
     * @return int[]|null
     */
    public static function teamIds( int $user_id, string $entity ): ?array {
        if ( $user_id <= 0 ) return [];
        if ( QueryHelpers::user_has_global_entity_read( $user_id, $entity ) ) return null;

        return array_values( array_unique( array_map(
            'intval',
            array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' )
        ) ) );
    }

    /**
     * May the viewer open this team on a surface backed by `$entity`?
     * Use it on every `?id=` / `?team_id=` that reaches a roster or a
     * team-scoped record.
     */
    public static function canOpenTeam( int $user_id, int $team_id, string $entity = 'team' ): bool {
        if ( $team_id <= 0 ) return false;
        $ids = self::teamIds( $user_id, $entity );
        return $ids === null || in_array( $team_id, $ids, true );
    }

    /**
     * `AND <column> IN (…)` for the viewer's teams, or an empty string when
     * they may see every team. A viewer with no teams gets a never-true
     * clause, so the list is empty rather than club-wide.
     *
     * `$column` is a caller-supplied SQL identifier and must be a literal —
     * never request data. The id list is cast to int, so the fragment is
     * safe to interpolate.
     */
    public static function teamIdClause( int $user_id, string $entity, string $column ): string {
        $ids = self::teamIds( $user_id, $entity );
        if ( $ids === null ) return '';
        if ( ! $ids )       return ' AND 1=0';
        return ' AND ' . $column . ' IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
    }

    /**
     * Active players the viewer may pick on a surface backed by `$entity`.
     *
     * @return object[]
     */
    public static function players( int $user_id, string $entity ): array {
        $ids = self::teamIds( $user_id, $entity );
        return $ids === null
            ? QueryHelpers::get_players()
            : QueryHelpers::get_players_for_teams( $ids );
    }
}
