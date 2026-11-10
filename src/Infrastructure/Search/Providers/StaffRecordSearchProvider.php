<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Search\SearchProvider;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchText;
use TT\Infrastructure\Search\SearchViewer;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;

/**
 * StaffRecordSearchProvider (#4936) — a player's development record found
 * by the staff who read it: evaluations, goals and notes.
 *
 * Every statement joins the record to its player (`p`) and narrows on both
 * before the `LIMIT`:
 *
 *   - the club on both tables, live players and the demo scope;
 *   - the players the viewer may view (`PlayerVisibility::sqlClause()`) and
 *     the kind's own read on them ({@see self::scope()});
 *   - every folded token, in the player's name or in the kind's own text
 *     ({@see self::tokenText()}), which a kind narrows to the rows whose
 *     text the viewer may read;
 *   - the query's dates and the team filter.
 *
 * `confirm()` asks each hit the record's own check, batched; `count()`
 * counts over the same `WHERE`. A parent or a player is never answered
 * here: their own records are the family kinds (#4673). A staff member's
 * own children are left to those kinds too, so one record is never listed
 * twice.
 */
abstract class StaffRecordSearchProvider implements SearchProvider {

    /** How many words of free text a hit shows. */
    protected const EXCERPT_WORDS = 14;

    public function minChars(): int { return 2; }

    public function isRecord(): bool { return false; }

    public function patterns(): array {
        return [ SearchQuery::PATTERN_DATE, SearchQuery::PATTERN_WEEKDAY ];
    }

    /** The record's table alias. The player is always `p`. */
    abstract protected function alias(): string;

    /** `FROM <record table> <alias> JOIN tt_players p …`, prefix in place. */
    abstract protected function from(): string;

    /** The columns a hit is built from: the record's `id` first. */
    abstract protected function columns(): string;

    /** The record's lifecycle condition: live rows only. */
    abstract protected function live(): string;

    /** The column the query's dates are matched on. */
    abstract protected function dateColumn(): string;

    /** What sorts hits of equal score. */
    abstract protected function tieBreak(): string;

    /**
     * The viewer's read on this kind, beside the players they may view, or
     * null when they read none of it.
     *
     * @return array{sql:string, params:list<mixed>}|null
     */
    abstract protected function scope( int $user_id ): ?array;

    /**
     * Per token, the kind's own text it may match: a condition the record
     * matches the token in. Text the viewer may not read is left out here,
     * so a hit is never found by it.
     *
     * @return array{sql:string, params:list<mixed>}
     */
    abstract protected function tokenText( string $token, int $user_id ): array;

    /** @return array<string,mixed> */
    abstract protected function toHit( object $row, int $user_id ): array;

    /**
     * The hits the record's own access check keeps, with whatever the
     * viewer may also read on them (an excerpt).
     *
     * @param list<array<string,mixed>> $hits
     * @return list<array<string,mixed>>
     */
    abstract protected function confirmHits( int $user_id, array $hits ): array;

    public function match( SearchQuery $query, int $user_id, int $limit ): array {
        $built = $this->build( $query, $user_id );
        if ( $built === null || $limit <= 0 ) return [];

        global $wpdb;
        $score = SearchText::scoreSql( 'player', 'p', $query->tokens, $built['extras'] );
        $sql   = "SELECT {$this->columns()}, ( {$score['sql']} ) AS tt_score
                    {$this->from()}
                   WHERE {$built['where']}
                ORDER BY tt_score DESC, {$this->tieBreak()}
                   LIMIT " . (int) $limit;
        $params = array_merge( $score['params'], $built['params'] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $hit          = $this->toHit( $row, $user_id );
            $hit['score'] = (int) ( $row->tt_score ?? 0 );
            $out[]        = $hit;
        }
        return $out;
    }

    public function count( SearchQuery $query, int $user_id ): int {
        $built = $this->build( $query, $user_id );
        if ( $built === null ) return 0;
        global $wpdb;
        $sql = "SELECT COUNT(*) {$this->from()} WHERE {$built['where']}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$built['params'] ) );
    }

    public function confirm( int $user_id, array $hits ): array {
        if ( $hits === [] || ! self::serves( $user_id ) ) return [];
        $kept = $this->confirmHits( $user_id, $hits );
        foreach ( $kept as $i => $hit ) unset( $kept[ $i ]['tt_row'] );
        return $kept;
    }

    public function hydrate( int $user_id, array $ids ): array {
        return [];
    }

    /** Staff and scouts: anyone who is not answered as a family. */
    protected static function serves( int $user_id ): bool {
        return $user_id > 0 && ! SearchViewer::isFamily( $user_id );
    }

    /**
     * @return array{where:string, params:list<mixed>, extras:array<string, array{sql:string, params:list<mixed>}>}|null
     */
    private function build( SearchQuery $query, int $user_id ): ?array {
        if ( $query->length < $this->minChars() || $query->isEmpty() ) return null;
        foreach ( $query->patterns() as $pattern ) {
            if ( ! in_array( $pattern, $this->patterns(), true ) ) return null;
        }
        if ( ! self::serves( $user_id ) ) return null;
        $scope = $this->scope( $user_id );
        if ( $scope === null ) return null;

        $extras = [];
        foreach ( $query->tokens as $token ) $extras[ $token ] = $this->tokenText( $token, $user_id );

        $a       = $this->alias();
        $visible = PlayerVisibility::sqlClause( $user_id, 'p' );
        $tokens  = SearchText::whereSql( 'player', 'p', $query->tokens, $extras );
        $dates   = $this->dateSql( $query );
        $demo    = QueryHelpers::apply_demo_scope( 'p', 'player' );

        $where = "{$a}.club_id = %d AND p.club_id = {$a}.club_id AND {$this->live()}"
            . ' AND ' . ArchiveRepository::filterClause( 'active', 'p' ) . " {$demo}"
            . " AND {$visible['sql']} AND {$scope['sql']} AND {$tokens['sql']} AND {$dates['sql']}";
        $params = array_merge( [ (int) CurrentClub::id() ], $visible['params'], $scope['params'], $tokens['params'], $dates['params'] );

        return [ 'where' => $where, 'params' => $params, 'extras' => $extras ];
    }

    /** @return array{sql:string, params:list<mixed>} */
    private function dateSql( SearchQuery $query ): array {
        $column = $this->dateColumn();
        $parts  = [];
        $params = [];
        if ( $query->dates !== [] ) {
            $parts[] = "{$column} IN (" . implode( ',', array_fill( 0, count( $query->dates ), '%s' ) ) . ')';
            $params  = array_merge( $params, $query->dates );
        }
        if ( $query->from !== null ) {
            $parts[]  = "{$column} >= %s";
            $params[] = $query->from;
        }
        if ( $query->to !== null ) {
            $parts[]  = "{$column} <= %s";
            $params[] = $query->to;
        }
        if ( $query->team_id !== null ) {
            $parts[]  = 'p.team_id = %d';
            $params[] = $query->team_id;
        }
        return [ 'sql' => $parts === [] ? '1=1' : implode( ' AND ', $parts ), 'params' => $params ];
    }

    /**
     * The players whose `$entity` section the viewer reads as staff, as a
     * condition on `p`: the read at global scope, on the teams of their
     * player scope, or on the players a scout is linked to, the rungs
     * `AuthorizationService::canReadPlayerSection()` asks. Their own
     * children and their own record are left to the family kinds. Null when
     * none is left.
     *
     * @return array{sql:string, params:list<mixed>}|null
     */
    protected static function sectionScope( int $user_id, string $entity ): ?array {
        if ( ! class_exists( MatrixGate::class ) ) return null;

        if ( MatrixGate::can( $user_id, $entity, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) ) {
            $parts  = [ '1=1' ];
            $params = [];
        } else {
            $parts  = [];
            $params = [];
            $teams  = [];
            foreach ( AuthorizationService::playerReadScope( $user_id )['team_ids'] as $team_id ) {
                if ( MatrixGate::can( $user_id, $entity, MatrixGate::READ, MatrixGate::SCOPE_TEAM, (int) $team_id ) ) $teams[] = (int) $team_id;
            }
            if ( $teams !== [] ) {
                $parts[] = 'p.team_id IN (' . implode( ',', array_fill( 0, count( $teams ), '%d' ) ) . ')';
                $params  = array_merge( $params, $teams );
            }
            $linked = AuthorizationService::scoutLinkedReadablePlayerIds( $user_id, $entity );
            if ( $linked !== [] ) {
                $parts[] = 'p.id IN (' . implode( ',', array_fill( 0, count( $linked ), '%d' ) ) . ')';
                $params  = array_merge( $params, $linked );
            }
            if ( $parts === [] ) return null;
        }

        $sql    = '( ' . implode( ' OR ', $parts ) . ' )';
        $family = SearchViewer::playerIdsReading( $user_id, $entity );
        if ( $family !== [] ) {
            $sql   .= ' AND p.id NOT IN (' . implode( ',', array_fill( 0, count( $family ), '%d' ) ) . ')';
            $params = array_merge( $params, $family );
        }
        return [ 'sql' => $sql, 'params' => $params ];
    }

    /**
     * Hits whose player the viewer may view and whose `$section` they read,
     * asked batched: `AuthorizationService::canViewPlayers()` and
     * `mayReadPlayerSections()`, the pair a record's detail page asks.
     *
     * @param list<array<string,mixed>> $hits each with `player_id` and `tt_row` (`team_id`, `wp_user_id`)
     * @return list<array<string,mixed>>
     */
    protected static function keepReadable( int $user_id, array $hits, string $section ): array {
        $players = [];
        foreach ( $hits as $hit ) {
            $row = (array) ( $hit['tt_row'] ?? [] );
            $players[ (int) ( $hit['player_id'] ?? 0 ) ] = [
                'team_id'    => (int) ( $row['team_id'] ?? 0 ),
                'wp_user_id' => (int) ( $row['wp_user_id'] ?? 0 ),
            ];
        }
        unset( $players[0] );
        if ( $players === [] ) return [];
        $view = AuthorizationService::canViewPlayers( $user_id, array_keys( $players ) );
        $read = AuthorizationService::mayReadPlayerSections( $user_id, $players, $section );

        $out = [];
        foreach ( $hits as $hit ) {
            $pid = (int) ( $hit['player_id'] ?? 0 );
            if ( ! empty( $view[ $pid ] ) && ! empty( $read[ $pid ] ) ) $out[] = $hit;
        }
        return $out;
    }

    /**
     * A `LIKE` on a raw text column, lower-cased, for one folded token.
     *
     * @return array{sql:string, params:list<mixed>}
     */
    protected static function likeSql( string $column, string $token ): array {
        return [ 'sql' => "LOWER( COALESCE( {$column}, '' ) ) LIKE %s", 'params' => [ '%' . $token . '%' ] ];
    }

    /**
     * Join conditions with OR, or a condition that matches nothing.
     *
     * @param list<array{sql:string, params:list<mixed>}> $parts
     * @return array{sql:string, params:list<mixed>}
     */
    protected static function anyOf( array $parts ): array {
        if ( $parts === [] ) return [ 'sql' => '1=0', 'params' => [] ];
        $params = [];
        foreach ( $parts as $part ) $params = array_merge( $params, $part['params'] );
        return [ 'sql' => '( ' . implode( ' OR ', array_column( $parts, 'sql' ) ) . ' )', 'params' => $params ];
    }

    /** A short excerpt of free text, tags removed; '' for none. */
    protected static function excerpt( string $text ): string {
        $text = trim( wp_strip_all_tags( $text ) );
        return $text === '' ? '' : wp_trim_words( $text, self::EXCERPT_WORDS );
    }

    /** The player's name from a row's `first_name` and `last_name`. */
    protected static function playerName( object $row ): string {
        $r = (array) $row;
        return trim( (string) ( $r['first_name'] ?? '' ) . ' ' . (string) ( $r['last_name'] ?? '' ) );
    }

    private ?string $dashboard = null;

    /**
     * A detail URL on the memoised dashboard URL.
     *
     * @param array<string, int|string> $args
     */
    protected function detailUrl( string $slug, int $id, array $args = [] ): string {
        $this->dashboard ??= \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        return (string) add_query_arg( array_merge( [ 'tt_view' => $slug, 'id' => $id ], $args ), $this->dashboard );
    }
}
