<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Security\AuthorizationService;
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
     * #3392 — the ladder moved to
     * {@see \TT\Infrastructure\Visibility\RecordVisibility} so measurements
     * could reuse it instead of growing a second copy that drifts. This
     * stays the name the journey code calls, and the behaviour is
     * unchanged: public, plus coaching-staff for anyone who may edit
     * evaluations or settings, plus medical when the cap and the #1538
     * sub-feature both hold, plus safeguarding on its own cap.
     *
     * @return list<string>
     */
    public static function visibilitiesForUser( int $user_id ): array {
        return \TT\Infrastructure\Visibility\RecordVisibility::forJourney( $user_id );
    }

    /**
     * Journey entry types that describe a record of their own, and the read
     * right that record asks: `[ entity, parent section, family ]`.
     *
     * - The section, when set, is the child's own sharing switch a linked
     *   parent also needs (a no-op for the player and for staff).
     * - `family` true: the player themselves and their linked parents (with
     *   the child's journey shared) see the entry without holding the
     *   entity, reduced to its type and date. That is the family section of
     *   the map; it never opens the record the entry describes.
     *
     * A type not listed here passes on the journey right alone.
     */
    public const ENTRY_RIGHTS = [
        JourneyEventType::TRIAL_STARTED        => [ 'trial_cases', null, true ],
        JourneyEventType::TRIAL_ENDED          => [ 'trial_cases', null, true ],
        JourneyEventType::SUSPENSION_STARTED   => [ 'player_suspensions', null, false ],
        JourneyEventType::SUSPENSION_ENDED     => [ 'player_suspensions', null, false ],
        JourneyEventType::INJURY_STARTED       => [ 'player_injuries', null, false ],
        JourneyEventType::INJURY_ENDED         => [ 'player_injuries', null, false ],
        JourneyEventType::PDP_VERDICT_RECORDED => [ 'pdp_verdict', 'pdp', false ],
    ];

    /**
     * The entries as this reader may see them. A journey entry follows the
     * read right of the record it describes: an entry whose type is in
     * `ENTRY_RIGHTS` shows only to a reader who holds that entity's read for
     * the entry's player (and, for a linked parent, the child's switch where
     * one is named). A family-marked entry also shows to the player and their
     * linked parents, as its type and date only. Every other entry passes
     * unchanged.
     *
     * Visibility rungs decide medical and safeguarding entries in SQL; this
     * is the entity rule on top, applied by every surface that shows journey
     * entries to a reader. A reader of 0 (an unattended render) holds no
     * right, so it gets none of the mapped entries. Entries left out here are
     * not counted anywhere: a count would say the record exists.
     *
     * @param list<object> $rows      Each with `event_type`, and `player_id` unless `$player_id` is given.
     * @param int|null     $player_id The player every row belongs to, for rows read without the column.
     * @return list<object>
     */
    public static function readableBy( int $user_id, array $rows, ?int $player_id = null ): array {
        $out     = [];
        $allowed = [];
        $family  = [];
        foreach ( $rows as $row ) {
            $type = (string) ( $row->event_type ?? '' );
            if ( isset( self::ENTRY_RIGHTS[ $type ] ) ) {
                [ $entity, $section, $family_visible ] = self::ENTRY_RIGHTS[ $type ];
                $pid = $player_id ?? (int) ( $row->player_id ?? 0 );
                $key = $entity . '|' . $pid;
                if ( ! isset( $allowed[ $key ] ) ) {
                    $allowed[ $key ] = $pid > 0
                        && AuthorizationService::canReadPlayerSection( $user_id, $pid, $entity )
                        && ( $section === null || AuthorizationService::parentCanViewSection( $user_id, $pid, $section ) );
                }
                if ( ! $allowed[ $key ] ) {
                    if ( ! $family_visible ) continue;
                    if ( ! isset( $family[ $pid ] ) ) {
                        $family[ $pid ] = self::isFamilyOf( $user_id, $pid );
                    }
                    if ( ! $family[ $pid ] ) continue;
                    $row = self::familyView( $row );
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The player themselves, or a linked parent who may read the child's
     * journey and whose child shares it with them.
     */
    private static function isFamilyOf( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        $own = \TT\Infrastructure\Query\QueryHelpers::get_player_for_user( $user_id );
        if ( $own !== null && (int) ( $own->id ?? 0 ) === $player_id ) return true;

        return \TT\Infrastructure\Players\ParentChildResolver::guardianAllows( $user_id, $player_id, 'player_timeline', 'read' )
            && AuthorizationService::parentCanViewSection( $user_id, $player_id, 'journey' );
    }

    /**
     * A family-visible entry as the family reads it: the type and the date.
     * The summary becomes the plain event name, and the payload, the source
     * record and the activity are dropped, so no decision, reason, note or
     * letter travels with it.
     */
    private static function familyView( object $row ): object {
        $fields = get_object_vars( $row );

        $fields['summary'] = (string) ( $fields['event_type'] ?? '' ) === JourneyEventType::TRIAL_ENDED
            ? __( 'Trial ended', 'talenttrack' )
            : __( 'Trial started', 'talenttrack' );
        $fields['payload']          = '{}';
        $fields['source_entity_id'] = null;
        if ( array_key_exists( 'activity', $fields ) ) $fields['activity'] = null;

        return (object) $fields;
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
        JourneySummaryLocaliser::localise( $rows );

        // Hidden count — same WHERE but visibility NOT IN allowed.
        $hidden_sql = "SELECT COUNT(*) FROM {$this->table}
                        WHERE {$where_sql}
                          AND visibility NOT IN ($vis_placeholders)";
        $hidden_count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare( $hidden_sql, ...array_merge( $params, $allowed_visibilities ) )
        );

        return [
            // #4181 — a match observation or an evaluation says which
            // activity it was about, on the timeline and in its REST payload.
            'events'       => JourneyActivityResolver::withActivities( array_values( $rows ), CurrentClub::id() ),
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
        if ( ! $rows ) return [];
        JourneySummaryLocaliser::localise( $rows );
        return JourneyActivityResolver::withActivities( $rows, CurrentClub::id() );
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
        if ( ! $rows ) return [];
        JourneySummaryLocaliser::localise( $rows );
        return $rows;
    }

    /**
     * #3458 — every journey event of the given types for a team's players in a
     * window: the team monthly report's "what changed" block.
     *
     * `cohortByType()` answers one type across the academy for a head of
     * development; this answers several types across one squad for the monthly
     * meeting. The squad is the team's players as they stand, and superseded
     * events are left out for the same reason the timeline leaves them out.
     *
     * The upper bound is exclusive of the day after `$to`, not `<= $to`:
     * `event_date` carries a time, and a comparison against a bare date would
     * drop everything that happened on the last day of the month after
     * midnight.
     *
     * @param list<string> $event_types
     * @param list<string> $allowed_visibilities
     * @return list<object>
     */
    public function forTeamBetween( int $team_id, string $from, string $to, array $event_types, array $allowed_visibilities ): array {
        if ( $team_id <= 0 || $event_types === [] || $allowed_visibilities === [] ) return [];

        // The lists go in as one comma-joined parameter each and are matched
        // with FIND_IN_SET, so the SQL stays a literal string for `prepare()`
        // rather than being assembled from placeholder runs. Keys are
        // vocabulary constants with no commas in them. The squad is small
        // enough that losing an index on `event_type` does not matter.
        $types = implode( ',', $event_types );
        $vis   = implode( ',', $allowed_visibilities );

        // The global handle rather than `$this->wpdb`: its `prefix` is what
        // keeps the interpolated SQL a literal string for the type checker.
        global $wpdb;

        /** @var list<object> $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.player_id, e.event_type, e.event_date, e.summary,
                    p.first_name, p.last_name
               FROM {$wpdb->prefix}tt_player_events e
               JOIN {$wpdb->prefix}tt_players p ON p.id = e.player_id AND p.club_id = e.club_id
              WHERE p.team_id = %d
                AND e.club_id = %d
                AND FIND_IN_SET( e.event_type, %s ) > 0
                AND e.event_date >= %s
                AND e.event_date < DATE_ADD( %s, INTERVAL 1 DAY )
                AND e.superseded_by_event_id IS NULL
                AND FIND_IN_SET( e.visibility, %s ) > 0
              ORDER BY e.event_date DESC, e.id DESC
              LIMIT 200",
            $team_id, CurrentClub::id(), $types, $from, $to, $vis
        ) );
        if ( ! $rows ) return [];
        JourneySummaryLocaliser::localise( $rows );
        return $rows;
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
