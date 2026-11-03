<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Search\SearchQuery;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * Teams (#4671). The narrowing `GET /teams` applies: `tt_view_teams`, then
 * every team for a viewer with global `team` read, otherwise the teams they
 * work with (`QueryHelpers::get_teams_for_coach()`). Honours the age group.
 */
final class TeamSearchProvider extends RecordSearchProvider {

    public function kind(): string { return 'team'; }

    public function patterns(): array { return [ SearchQuery::PATTERN_AGE_GROUP ]; }

    protected function alias(): string { return 't'; }

    protected function kindBonus(): int { return 8; }

    protected function tieBreak(): string { return 't.name ASC, t.id ASC'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_teams t";
    }

    protected function columns(): string {
        return 't.id, t.name, t.age_group';
    }

    protected function scope( int $user_id ): ?array {
        if ( $user_id <= 0 || ! user_can( $user_id, 'tt_view_teams' ) ) return null;
        $mine = array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' ) );
        $own  = $mine === [] ? [ 'sql' => '', 'params' => [] ] : self::inList( 't.id', $mine );

        if ( QueryHelpers::user_has_global_entity_read( $user_id, 'team' ) ) {
            return [ 'sql' => '1=1', 'params' => [], 'own_sql' => $own['sql'], 'own_params' => $own['params'] ];
        }
        if ( $mine === [] ) return null;
        $in = self::inList( 't.id', $mine );
        return [ 'sql' => $in['sql'], 'params' => $in['params'], 'own_sql' => $own['sql'], 'own_params' => $own['params'] ];
    }

    protected function conditions( SearchQuery $query ): array {
        $parts  = [];
        $params = [];
        if ( $query->age_group !== null ) {
            $group  = self::ageGroupSql( 't', $query->age_group );
            $parts[] = $group['sql'];
            $params  = array_merge( $params, $group['params'] );
        }
        if ( $query->team_id !== null ) {
            $parts[]  = 't.id = %d';
            $params[] = $query->team_id;
        }
        return [ 'sql' => $parts === [] ? '1=1' : implode( ' AND ', $parts ), 'params' => $params ];
    }

    /** @return array<string,mixed> */
    protected function toHit( object $row, int $user_id ): array {
        $r  = (array) $row;
        $id = (int) ( $r['id'] ?? 0 );
        return [
            'type'     => 'team',
            'id'       => $id,
            'label'    => (string) ( $r['name'] ?? '' ),
            'sublabel' => (string) ( $r['age_group'] ?? '' ),
            'url'      => $this->detailUrl( 'teams', $id ),
            'score'    => 0,
            'team_id'  => $id,
            'actions'  => [],
        ];
    }

    /** The scope is the whole rule for a team: nothing beyond it to ask. */
    public function confirm( int $user_id, array $hits ): array {
        if ( $hits === [] || ! user_can( $user_id, 'tt_view_teams' ) ) return [];
        if ( QueryHelpers::user_has_global_entity_read( $user_id, 'team' ) ) return $hits;
        $mine = array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' ) );
        return array_values( array_filter( $hits, static fn( array $h ): bool => in_array( (int) $h['id'], $mine, true ) ) );
    }
}
