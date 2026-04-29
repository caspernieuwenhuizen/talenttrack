<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerEventsRepository — read API for journey events.
 *
 * Visibility filtering happens server-side: callers pass the viewer's
 * allowed visibilities (computed from their caps) and queries restrict
 * to those rows. The `hidden_count` companion query lets the UI render
 * "1 entry hidden" placeholders without a second round-trip.
 */
final class PlayerEventsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_player_events';
    }

    /**
     * Compute the visibility levels a viewer is allowed to see.
     *
     * @return list<string>
     */
    public static function visibilitiesForUser( int $user_id ): array {
        $out = [ EventTypeDefinition::VISIBILITY_PUBLIC ];
        if ( user_can( $user_id, 'tt_edit_evaluations' ) || user_can( $user_id, 'tt_edit_settings' ) ) {
            $out[] = EventTypeDefinition::VISIBILITY_COACHING_STAFF;
        }
        if ( user_can( $user_id, 'tt_view_player_medical' ) ) {
            $out[] = EventTypeDefinition::VISIBILITY_MEDICAL;
        }
        if ( user_can( $user_id, 'tt_view_player_safeguarding' ) ) {
            $out[] = EventTypeDefinition::VISIBILITY_SAFEGUARDING;
        }
        return $out;
    }

    /**
     * @param array{
     *   from?: string,
     *   to?: string,
     *   event_types?: list<string>,
     *   include_superseded?: bool,
     *   cursor?: int,
     *   limit?: int,
     * } $filters
     * @param list<string> $allowed_visibilities
     * @return array{events: list<object>, hidden_count: int, next_cursor: int|null}
     */
    public function timelineForPlayer( int $player_id, array $filters, array $allowed_visibilities ): array {
        if ( $player_id <= 0 || empty( $allowed_visibilities ) ) {
            return [ 'events' => [], 'hidden_count' => 0, 'next_cursor' => null ];
        }

        $limit = isset( $filters['limit'] ) ? max( 1, min( 200, (int) $filters['limit'] ) ) : 50;

        [ $where_sql, $params ] = $this->buildWhereForTimeline( $player_id, $filters );

        $vis_placeholders = implode( ',', array_fill( 0, count( $allowed_visibilities ), '%s' ) );

        $sql = "SELECT * FROM {$this->table}
                 WHERE {$where_sql}
                   AND visibility IN ($vis_placeholders)
                 ORDER BY event_date DESC, id DESC
                 LIMIT %d";
        $merged = array_merge( $params, $allowed_visibilities, [ $limit + 1 ] );

        /** @var array<int,object> $rows */
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$merged ) );

        $next_cursor = null;
        if ( count( $rows ) > $limit ) {
            $rows = array_slice( $rows, 0, $limit );
            $tail = end( $rows );
            $next_cursor = $tail ? (int) $tail->id : null;
        }

        // Hidden count — same WHERE but visibility NOT IN allowed.
        $hidden_sql = "SELECT COUNT(*) FROM {$this->table}
                        WHERE {$where_sql}
                          AND visibility NOT IN ($vis_placeholders)";
        $hidden_count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare( $hidden_sql, ...array_merge( $params, $allowed_visibilities ) )
        );

        return [
            'events'       => $rows,
            'hidden_count' => $hidden_count,
            'next_cursor'  => $next_cursor,
        ];
    }

    /**
     * Milestone-only events for the Transitions view mode.
     *
     * @param list<string> $allowed_visibilities
     * @return list<object>
     */
    public function transitionsForPlayer( int $player_id, array $allowed_visibilities ): array {
        if ( $player_id <= 0 || empty( $allowed_visibilities ) ) return [];

        $milestone_keys = $this->milestoneKeys();
        if ( empty( $milestone_keys ) ) return [];

        $type_placeholders = implode( ',', array_fill( 0, count( $milestone_keys ), '%s' ) );
        $vis_placeholders  = implode( ',', array_fill( 0, count( $allowed_visibilities ), '%s' ) );

        $sql = "SELECT * FROM {$this->table}
                 WHERE player_id = %d
                   AND club_id = %d
                   AND superseded_by_event_id IS NULL
                   AND event_type IN ($type_placeholders)
                   AND visibility IN ($vis_placeholders)
                 ORDER BY event_date DESC, id DESC
                 LIMIT 200";

        /** @var list<object> $rows */
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            $sql,
            ...array_merge( [ $player_id, CurrentClub::id() ], $milestone_keys, $allowed_visibilities )
        ) );
        return $rows ?: [];
    }

    /**
     * HoD cohort query — every player whose journey has the given event
     * type within a date range.
     *
     * @param list<string> $allowed_visibilities
     * @return list<object>
     */
    public function cohortByType( string $event_type, string $from, string $to, ?int $team_id, array $allowed_visibilities ): array {
        if ( $event_type === '' || empty( $allowed_visibilities ) ) return [];

        $vis_placeholders = implode( ',', array_fill( 0, count( $allowed_visibilities ), '%s' ) );

        $extra       = '';
        $extra_param = [];
        if ( $team_id !== null && $team_id > 0 ) {
            $extra       = 'AND p.team_id = %d';
            $extra_param = [ $team_id ];
        }

        $sql = "SELECT e.id, e.player_id, e.event_type, e.event_date, e.summary, e.payload,
                       p.first_name, p.last_name, p.team_id
                  FROM {$this->table} e
                  JOIN {$this->wpdb->prefix}tt_players p ON p.id = e.player_id AND p.club_id = e.club_id
                 WHERE e.event_type = %s
                   AND e.event_date >= %s
                   AND e.event_date <= %s
                   AND e.club_id = %d
                   AND e.superseded_by_event_id IS NULL
                   AND e.visibility IN ($vis_placeholders)
                   {$extra}
                 ORDER BY e.event_date DESC, e.id DESC
                 LIMIT 500";

        $params = array_merge(
            [ $event_type, $from, $to, CurrentClub::id() ],
            $allowed_visibilities,
            $extra_param
        );

        /** @var list<object> $rows */
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ) );
        return $rows ?: [];
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @return list<string>
     */
    private function milestoneKeys(): array {
        $keys = [];
        foreach ( EventTypeRegistry::all() as $def ) {
            if ( $def->severity === EventTypeDefinition::SEVERITY_MILESTONE ) {
                $keys[] = $def->key;
            }
        }
        return $keys;
    }

    /**
     * @param array{
     *   from?: string,
     *   to?: string,
     *   event_types?: list<string>,
     *   include_superseded?: bool,
     *   cursor?: int,
     * } $filters
     * @return array{0:string, 1:list<int|string>}
     */
    private function buildWhereForTimeline( int $player_id, array $filters ): array {
        $clauses = [ 'player_id = %d', 'club_id = %d' ];
        $params  = [ $player_id, CurrentClub::id() ];

        if ( empty( $filters['include_superseded'] ) ) {
            $clauses[] = 'superseded_by_event_id IS NULL';
        }

        if ( ! empty( $filters['from'] ) ) {
            $clauses[] = 'event_date >= %s';
            $params[]  = (string) $filters['from'];
        }
        if ( ! empty( $filters['to'] ) ) {
            $clauses[] = 'event_date <= %s';
            $params[]  = (string) $filters['to'];
        }
        if ( ! empty( $filters['event_types'] ) && is_array( $filters['event_types'] ) ) {
            $types = array_values( array_filter( $filters['event_types'], static fn( $t ) => is_string( $t ) && $t !== '' ) );
            if ( $types ) {
                $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
                $clauses[]    = "event_type IN ($placeholders)";
                $params       = array_merge( $params, $types );
            }
        }
        if ( ! empty( $filters['cursor'] ) ) {
            $clauses[] = 'id < %d';
            $params[]  = (int) $filters['cursor'];
        }

        return [ implode( ' AND ', $clauses ), $params ];
    }
}
