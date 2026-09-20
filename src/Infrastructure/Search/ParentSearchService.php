<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * ParentSearchService (#3806) — find something in your own child's record.
 *
 * A coach says a session is "in TalentTrack now" and the parent has no way
 * to reach it. There is no search anywhere on the parent surface, the
 * child's activities page is a history that stops at today, and the only
 * thing that worked was pasting a record number out of a text message into
 * the address bar.
 *
 * ## The scope is the whole design
 *
 * This resolves the caller's children **first**, through the same guardian
 * link every other parent surface uses, and every query is then bounded by
 * that list of player ids. There is no global index with a filter applied
 * afterwards, because a filter is something that can be forgotten and an
 * `IN (…)` built from the caller's own children cannot.
 *
 * A parent with no linked child gets an empty result from every method
 * without a query running at all.
 *
 * **A miss looks the same whatever it missed.** Searching for another
 * family's child returns exactly what searching for a made-up name returns:
 * nothing, with no count, no suggestion and no "did you mean". A response
 * that differs between "exists but is not yours" and "does not exist" is a
 * way of discovering that a child exists, which is the thing this must not
 * allow.
 *
 * ## What it searches
 *
 * Four record types, by title and by date, and nothing a parent could not
 * already open by its own id:
 *
 *   - **Activities** the child is on — their team's calendar, plus any
 *     activity they are registered against. Future ones included; that is
 *     the whole complaint.
 *   - **Evaluations** of the child.
 *   - **Goals** belonging to the child.
 *   - **Messages** in the caller's own inbox.
 *
 * Domain layer, not a view (CLAUDE.md §4): the REST route and the rendered
 * page call this and get the same answer.
 */
final class ParentSearchService {

    /** Per record type. A parent scanning a page does not want a hundred. */
    public const PER_TYPE = 10;

    public const TYPE_ACTIVITY   = 'activity';
    public const TYPE_EVALUATION = 'evaluation';
    public const TYPE_GOAL       = 'goal';
    public const TYPE_MESSAGE    = 'message';

    /**
     * Search one parent's world.
     *
     * @return array{
     *   query: string,
     *   children: list<array{id:int, name:string}>,
     *   results: list<array{type:string, id:int, player_id:int, title:string, subtitle:string, date:string, url:string}>,
     *   total: int
     * }
     */
    public function search( int $parent_user_id, string $query ): array {
        $query    = trim( $query );
        $children = $this->children( $parent_user_id );

        $empty = [
            'query'    => $query,
            'children' => array_map(
                static fn ( object $c ): array => [
                    'id'   => (int) $c->id,
                    'name' => trim( (string) ( $c->first_name ?? '' ) . ' ' . (string) ( $c->last_name ?? '' ) ),
                ],
                $children
            ),
            'results'  => [],
            'total'    => 0,
        ];

        // Two characters is the shortest thing worth looking for; one
        // character matches most of the academy and tells the reader
        // nothing.
        if ( $query === '' || mb_strlen( $query ) < 2 || $children === [] ) {
            return $empty;
        }

        $player_ids = array_values( array_map( static fn ( object $c ): int => (int) $c->id, $children ) );
        $date       = self::asDate( $query );

        $results = array_merge(
            $this->activities( $player_ids, $query, $date ),
            $this->evaluations( $player_ids, $query, $date ),
            $this->goals( $player_ids, $query, $date ),
            $this->messages( $parent_user_id, $query, $date )
        );

        // Soonest first among what is still to come, then most recent of
        // what has been. A parent searching a session name almost always
        // wants the next one.
        usort( $results, static function ( array $a, array $b ): int {
            $today  = current_time( 'Y-m-d' );
            $a_next = $a['date'] >= $today;
            $b_next = $b['date'] >= $today;
            if ( $a_next !== $b_next ) return $a_next ? -1 : 1;
            return $a_next ? strcmp( $a['date'], $b['date'] ) : strcmp( $b['date'], $a['date'] );
        } );

        return [
            'query'    => $query,
            'children' => $empty['children'],
            'results'  => $results,
            'total'    => count( $results ),
        ];
    }

    /** @return list<object> */
    private function children( int $parent_user_id ): array {
        if ( $parent_user_id <= 0 ) return [];

        return ParentChildResolver::children( $parent_user_id );
    }

    /**
     * A date the reader typed, as `Y-m-d`, or '' when the query is not one.
     *
     * "3 nov" and "2026-11-03" both name a day; "training" does not, and
     * must not be coerced into one — `strtotime()` will happily read almost
     * anything as "now", which would make every text search also match
     * today.
     */
    public static function asDate( string $query ): string {
        $query = trim( $query );
        if ( $query === '' ) return '';
        // A date has a digit in it. This is what keeps `strtotime( 'now' )`
        // and its friends out.
        if ( ! preg_match( '/\d/', $query ) ) return '';

        $stamp = strtotime( $query, (int) current_time( 'timestamp' ) );
        if ( $stamp === false ) return '';

        return (string) gmdate( 'Y-m-d', $stamp );
    }

    /**
     * @param list<int> $player_ids
     * @return list<array<string,mixed>>
     */
    private function activities( array $player_ids, string $query, string $date ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $ids  = implode( ',', array_map( 'intval', $player_ids ) );
        $like = '%' . $wpdb->esc_like( $query ) . '%';

        $params = [ CurrentClub::id() ];

        $match  = '( a.title LIKE %s OR a.location LIKE %s OR a.opponent LIKE %s';
        array_push( $params, $like, $like, $like );
        if ( $date !== '' ) {
            $match   .= ' OR a.session_date = %s';
            $params[] = $date;
        }
        $match .= ' )';

        // The child's own calendar: their team's activities, plus any they
        // are registered against (a guest appearance for another team is
        // still their evening). Two `EXISTS` rather than two joins, so an
        // activity a child is on twice is still one result.
        $on_the_team = "EXISTS ( SELECT 1 FROM {$p}tt_players pl
                                  WHERE pl.id IN ({$ids}) AND pl.team_id = a.team_id )";
        // Both kinds of attendance row on purpose: a child on the planned
        // squad for a fixture next month has no recorded register yet, and
        // that fixture is exactly the one the parent came here to find.
        // /* both-kinds-ok */
        $registered  = "EXISTS ( SELECT 1 FROM {$p}tt_attendance att
                                  WHERE att.activity_id = a.id AND att.player_id IN ({$ids}) )";

        $sql = "SELECT a.id, a.title, a.session_date, a.location, a.team_id,
                       t.name AS team_name,
                       ( SELECT MIN(att2.player_id) FROM {$p}tt_attendance att2
                          WHERE att2.activity_id = a.id AND att2.player_id IN ({$ids}) ) AS registered_player_id,
                       ( SELECT MIN(pl2.id) FROM {$p}tt_players pl2
                          WHERE pl2.id IN ({$ids}) AND pl2.team_id = a.team_id ) AS team_player_id
                  FROM {$p}tt_activities a
                  LEFT JOIN {$p}tt_teams t ON t.id = a.team_id
                 WHERE a.archived_at IS NULL
                   AND a.club_id = %d
                   AND ( {$on_the_team} OR {$registered} )
                   AND {$match}
              ORDER BY a.session_date DESC
                 LIMIT " . self::PER_TYPE;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $player_id = (int) ( $row->registered_player_id ?? 0 );
            if ( $player_id <= 0 ) $player_id = (int) ( $row->team_player_id ?? 0 );
            $out[] = [
                'type'      => self::TYPE_ACTIVITY,
                'id'        => (int) $row->id,
                'player_id' => $player_id,
                'title'     => (string) ( $row->title ?? '' ),
                'subtitle'  => trim( implode( ' · ', array_filter( [
                    (string) ( $row->team_name ?? '' ),
                    (string) ( $row->location ?? '' ),
                ] ) ) ),
                'date'      => (string) ( $row->session_date ?? '' ),
                'url'       => $this->url( 'my-activities', $player_id, (int) $row->id ),
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $player_ids
     * @return list<array<string,mixed>>
     */
    private function evaluations( array $player_ids, string $query, string $date ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $ids  = implode( ',', array_map( 'intval', $player_ids ) );
        $like = '%' . $wpdb->esc_like( $query ) . '%';

        // A NULL type name does not match, which is what we want: the row
        // has no title to search.
        $match  = '( lt.name LIKE %s OR e.opponent LIKE %s';
        $params = [ $like, $like ];
        if ( $date !== '' ) {
            $match   .= ' OR e.eval_date = %s';
            $params[] = $date;
        }
        $match .= ' )';

        $params[] = CurrentClub::id();

        $sql = "SELECT e.id, e.player_id, e.eval_date, e.opponent,
                       lt.name AS type_name
                  FROM {$p}tt_evaluations e
                  LEFT JOIN {$p}tt_lookups lt ON lt.id = e.eval_type_id
                 WHERE e.player_id IN ({$ids})
                   AND e.archived_at IS NULL
                   AND {$match}
                   AND ( e.club_id = %d OR e.club_id IS NULL )
              ORDER BY e.eval_date DESC
                 LIMIT " . self::PER_TYPE;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $player_id = (int) $row->player_id;
            $title     = trim( (string) ( $row->type_name ?? '' ) );
            if ( $title === '' ) $title = __( 'Evaluation', 'talenttrack' );
            $out[] = [
                'type'      => self::TYPE_EVALUATION,
                'id'        => (int) $row->id,
                'player_id' => $player_id,
                'title'     => $title,
                'subtitle'  => (string) ( $row->opponent ?? '' ),
                'date'      => (string) ( $row->eval_date ?? '' ),
                'url'       => $this->url( 'my-evaluations', $player_id, 0 ),
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $player_ids
     * @return list<array<string,mixed>>
     */
    private function goals( array $player_ids, string $query, string $date ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $ids  = implode( ',', array_map( 'intval', $player_ids ) );
        $like = '%' . $wpdb->esc_like( $query ) . '%';

        $match  = '( g.title LIKE %s';
        $params = [ $like ];
        if ( $date !== '' ) {
            $match   .= ' OR g.due_date = %s';
            $params[] = $date;
        }
        $match .= ' )';

        $params[] = CurrentClub::id();

        $sql = "SELECT g.id, g.player_id, g.title, g.due_date, g.status
                  FROM {$p}tt_goals g
                 WHERE g.player_id IN ({$ids})
                   AND g.archived_at IS NULL
                   AND {$match}
                   AND g.club_id = %d
              ORDER BY g.due_date DESC
                 LIMIT " . self::PER_TYPE;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $player_id = (int) $row->player_id;
            $out[] = [
                'type'      => self::TYPE_GOAL,
                'id'        => (int) $row->id,
                'player_id' => $player_id,
                'title'     => (string) ( $row->title ?? '' ),
                'subtitle'  => '',
                'date'      => (string) ( $row->due_date ?? '' ),
                'url'       => $this->url( 'my-goals', $player_id, 0 ),
            ];
        }
        return $out;
    }

    /**
     * The caller's own inbox. Not the child's: a message is addressed to a
     * person, and this one is addressed to the reader.
     *
     * @return list<array<string,mixed>>
     */
    private function messages( int $parent_user_id, string $query, string $date ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $like = '%' . $wpdb->esc_like( $query ) . '%';

        $match  = '( subject LIKE %s';
        $params = [ $like ];
        if ( $date !== '' ) {
            $match   .= ' OR DATE(created_at) = %s';
            $params[] = $date;
        }
        $match .= ' )';

        $params[] = $parent_user_id;
        $params[] = CurrentClub::id();

        $sql = "SELECT id, subject, created_at, recipient_player_id
                  FROM {$p}tt_comms_inbox
                 WHERE {$match}
                   AND recipient_user_id = %d
                   AND club_id = %d
              ORDER BY created_at DESC
                 LIMIT " . self::PER_TYPE;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = [
                'type'      => self::TYPE_MESSAGE,
                'id'        => (int) $row->id,
                'player_id' => (int) ( $row->recipient_player_id ?? 0 ),
                'title'     => (string) ( $row->subject ?? '' ),
                'subtitle'  => '',
                'date'      => substr( (string) ( $row->created_at ?? '' ), 0, 10 ),
                'url'       => $this->url( 'my-messages', 0, (int) $row->id ),
            ];
        }
        return $out;
    }

    /**
     * Where a result opens. `player_id` is carried because every parent
     * surface resolves its subject from it, and a parent with two children
     * landing on the wrong one is the bug that would make this useless.
     */
    private function url( string $slug, int $player_id, int $record_id ): string {
        $args = [ 'tt_view' => $slug ];
        if ( $player_id > 0 ) $args['player_id'] = $player_id;
        if ( $record_id > 0 ) $args['id']        = $record_id;

        return BackLink::appendTo( add_query_arg( $args, RecordLink::dashboardUrl() ) );
    }
}
