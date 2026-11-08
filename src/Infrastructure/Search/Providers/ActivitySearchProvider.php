<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchText;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * Activities (#4671). Narrowed by `ActivityAccess::readScope()`: everything
 * for global read; for staff the teams they coach; and for a player, a
 * guardian or a linked scout the activities of those players, through
 * `ActivitiesRepository::playersVisibilityClause()` (their teams' and any
 * they are on the register of). Confirmed by `ActivityAccess::readableIds()`.
 *
 * Recent first after the score. A word that names an activity type in the
 * viewer's language, or by its stored name, matches that type, so
 * *wedstrijd* finds the matches. Honours the date and weekday patterns.
 */
final class ActivitySearchProvider extends RecordSearchProvider {

    /** @var array<string, array<string, list<string>>> locale => folded label => type keys */
    private static array $types = [];

    public function kind(): string { return 'activity'; }

    public function patterns(): array {
        return [ SearchQuery::PATTERN_DATE, SearchQuery::PATTERN_WEEKDAY ];
    }

    protected function alias(): string { return 'a'; }

    protected function tieBreak(): string { return 'a.session_date DESC, a.id DESC'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_activities a
           LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id";
    }

    protected function columns(): string {
        return 'a.id, a.title, a.session_date, a.team_id, t.name AS team_name';
    }

    protected function scope( int $user_id ): ?array {
        $scope = ActivityAccess::readScope( $user_id );
        $own   = $scope['team_ids'] === [] ? [ 'sql' => '', 'params' => [] ] : self::inList( 'a.team_id', $scope['team_ids'] );
        if ( $scope['global'] ) {
            return [ 'sql' => '1=1', 'params' => [], 'own_sql' => $own['sql'], 'own_params' => $own['params'] ];
        }

        $parts  = [];
        $params = [];
        if ( $scope['team_ids'] !== [] ) {
            $parts[] = $own['sql'];
            $params  = array_merge( $params, $own['params'] );
        }
        if ( $scope['player_ids'] !== [] ) {
            $players = ActivitiesRepository::playersVisibilityClause( $scope['player_ids'], 'a' );
            $parts[] = $players['sql'];
            $params  = array_merge( $params, $players['params'] );
        }
        if ( $parts === [] ) return null;
        return [ 'sql' => '( ' . implode( ' OR ', $parts ) . ' )', 'params' => $params, 'own_sql' => $own['sql'], 'own_params' => $own['params'] ];
    }

    protected function conditions( SearchQuery $query ): array {
        $parts  = [];
        $params = [];
        if ( $query->dates !== [] ) {
            $parts[] = 'a.session_date IN (' . implode( ',', array_fill( 0, count( $query->dates ), '%s' ) ) . ')';
            $params  = array_merge( $params, $query->dates );
        }
        if ( $query->team_id !== null ) {
            $parts[]  = 'a.team_id = %d';
            $params[] = $query->team_id;
        }
        if ( $query->from !== null ) {
            $parts[]  = 'a.session_date >= %s';
            $params[] = $query->from;
        }
        if ( $query->to !== null ) {
            $parts[]  = 'a.session_date <= %s';
            $params[] = $query->to;
        }
        return [ 'sql' => $parts === [] ? '1=1' : implode( ' AND ', $parts ), 'params' => $params ];
    }

    /**
     * A token that is part of an activity type's name, in the viewer's
     * language or as stored, also matches activities of that type.
     */
    protected function tokenExtras( SearchQuery $query ): array {
        $out = [];
        foreach ( $query->tokens as $token ) {
            $keys = [];
            foreach ( self::typeIndex() as $label => $type_keys ) {
                if ( strpos( $label, $token ) !== false ) $keys = array_merge( $keys, $type_keys );
            }
            $keys = array_values( array_unique( $keys ) );
            if ( $keys === [] ) continue;
            $out[ $token ] = [
                'sql'    => 'a.activity_type_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')',
                'params' => $keys,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    protected function toHit( object $row, int $user_id ): array {
        $r    = (array) $row;
        $id   = (int) ( $r['id'] ?? 0 );
        $date = (string) ( $r['session_date'] ?? '' );
        $team = (string) ( $r['team_name'] ?? '' );
        $hit  = [
            'type'     => 'activity',
            'id'       => $id,
            'label'    => (string) ( $r['title'] ?? '' ),
            'sublabel' => trim( implode( ' · ', array_filter( [ $date !== '' ? \TT\Shared\Dates\TTDate::date( $date ) : '', $team ] ) ) ),
            'url'      => $this->url( $user_id, $id ),
            'score'    => 0,
            'date'     => $date,
            'actions'  => [],
        ];
        $team_id = (int) ( $r['team_id'] ?? 0 );
        if ( $team_id > 0 ) $hit['team_id'] = $team_id;
        return $hit;
    }

    public function confirm( int $user_id, array $hits ): array {
        if ( $hits === [] ) return [];
        $rows = array_map( static fn( array $h ): object => (object) [ 'id' => (int) $h['id'], 'team_id' => (int) ( $h['team_id'] ?? 0 ) ], $hits );
        $ok   = ActivityAccess::readableIds( $user_id, $rows );
        return $this->withChild( $user_id, array_values( array_filter( $hits, static fn( array $h ): bool => in_array( (int) $h['id'], $ok, true ) ) ) );
    }

    /**
     * #4673 — for a guardian, the child each activity belongs to, on the hit
     * and in its link, so a parent of two lands on the right child's page.
     *
     * @param list<array<string,mixed>> $hits
     * @return list<array<string,mixed>>
     */
    private function withChild( int $user_id, array $hits ): array {
        if ( $hits === [] ) return $hits;
        $teams = [];
        foreach ( $hits as $hit ) $teams[ (int) $hit['id'] ] = (int) ( $hit['team_id'] ?? 0 );
        $child = \TT\Infrastructure\Search\SearchViewer::childForActivities( $user_id, $teams );
        if ( $child === [] ) return $hits;
        // A coach who is also a parent keeps the staff link; the child is
        // still on the hit, which `GET /me/search` reads.
        $staff = $this->isStaff( $user_id );
        foreach ( $hits as $i => $hit ) {
            $player_id = $child[ (int) $hit['id'] ] ?? 0;
            if ( $player_id <= 0 ) continue;
            $hits[ $i ]['player_id'] = $player_id;
            if ( ! $staff ) $hits[ $i ]['url'] = (string) add_query_arg( 'player_id', $player_id, (string) $hit['url'] );
        }
        return $hits;
    }

    /**
     * Staff open the activity page; a player or a guardian the player's own
     * activities page.
     */
    private function url( int $user_id, int $activity_id ): string {
        if ( $this->isStaff( $user_id ) ) {
            return $this->detailUrl( 'activities', $activity_id );
        }
        return (string) add_query_arg( [ 'tt_view' => 'my-activities', 'id' => $activity_id ], $this->dashboard() );
    }

    /** @var array<int,bool> */
    private array $staff = [];

    /** Does this viewer open the staff activity page? */
    private function isStaff( int $user_id ): bool {
        return $this->staff[ $user_id ] ??= ( ActivityAccess::isStaffReader( $user_id ) || ActivityAccess::hasGlobalRead( $user_id ) );
    }

    /**
     * Folded activity-type labels (the viewer's language and the stored
     * name) to the type keys they name.
     *
     * @return array<string, list<string>>
     */
    private static function typeIndex(): array {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        if ( isset( self::$types[ $locale ] ) ) return self::$types[ $locale ];

        $index = [];
        foreach ( \TT\Infrastructure\Query\QueryHelpers::get_lookups( 'activity_type' ) as $lookup ) {
            $key = (string) ( ( (array) $lookup )['name'] ?? '' );
            if ( $key === '' ) continue;
            foreach ( [ \TT\Infrastructure\Query\LookupTranslator::name( $lookup ), $key ] as $label ) {
                $folded = SearchText::fold( (string) $label );
                if ( $folded === '' ) continue;
                $keys = $index[ $folded ] ?? [];
                if ( ! in_array( $key, $keys, true ) ) $keys[] = $key;
                $index[ $folded ] = $keys;
            }
        }
        self::$types[ $locale ] = $index;
        return $index;
    }
}
