<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Journey\EventEmitter;
use TT\Infrastructure\Journey\EventTypeRegistry;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * PlayerJourneyRestController (#0053) — /wp-json/talenttrack/v1
 *
 * Exposes the journey timeline + cohort + injury surfaces. Visibility
 * filtering is server-side: each list endpoint computes the viewer's
 * allowed visibilities from their caps and returns a `hidden_count` so
 * the UI can render placeholder cards for masked rows.
 *
 * Lives under Infrastructure/REST rather than a single module because
 * the journey is cross-module by design — it aggregates events emitted
 * by Evaluations, Goals, PDP, Players, Trials, and the Journey module
 * itself (injuries, manual notes).
 */
class PlayerJourneyRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        // Timeline + transitions per player.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/timeline', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_timeline' ],
                'permission_callback' => self::permCan( 'tt_view_players' ),
            ],
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/transitions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_transitions' ],
                'permission_callback' => self::permCan( 'tt_view_players' ),
            ],
        ] );

        // #1384 — personal rating trend (growth chip on My team). Gated
        // per-player by AuthorizationService::canViewPlayer in the
        // handler so a player/parent can read their own trend without a
        // staff capability, while staff still pass via the same check.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/rating-trend', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_rating_trend' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            ],
        ] );

        // Manual events.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/events', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_event' ],
                'permission_callback' => self::permCan( 'tt_edit_evaluations' ),
            ],
        ] );
        register_rest_route( self::NS, '/player-events/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'supersede_event' ],
                'permission_callback' => self::permCan( 'tt_edit_evaluations' ),
            ],
        ] );

        // Taxonomy.
        register_rest_route( self::NS, '/journey/event-types', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_event_types' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            ],
        ] );

        // Cohort transitions. #1485 — gated by the cohort_transitions
        // sub-feature flag on top of the cap, so switching the feature
        // off takes its REST surface dark while the rest of Journey
        // (timeline / injuries) keeps serving.
        register_rest_route( self::NS, '/journey/cohort-transitions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'cohort_transitions' ],
                'permission_callback' => self::permCanFeature( 'tt_view_settings', 'cohort_transitions' ),
            ],
        ] );

        // Injuries.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/injuries', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_injuries' ],
                'permission_callback' => self::permCan( 'tt_view_player_medical' ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_injury' ],
                'permission_callback' => self::permCan( 'tt_view_player_medical' ),
            ],
        ] );
        register_rest_route( self::NS, '/player-injuries/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_injury' ],
                'permission_callback' => self::permCan( 'tt_view_player_medical' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive_injury' ],
                'permission_callback' => self::permCan( 'tt_view_player_medical' ),
            ],
        ] );

        // #1784 — referential-integrity permanent delete of an injury (a
        // minor's medical record). Removes its journey-timeline events too,
        // so a right-to-erasure delete actually erases. Gated by
        // tt_edit_settings (the destructive-op gate).
        register_rest_route( self::NS, '/player-injuries/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_injury_permanently' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_settings' ); },
            ],
        ] );
    }

    /** #1784 — permanently delete an injury + its journey events (irreversible). */
    public static function delete_injury_permanently( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid injury id.', 'talenttrack' ), 400 );
        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'injury', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) return RestResponse::error( 'not_found', __( 'Injury not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function get_timeline( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $user_id   = get_current_user_id();
        if ( ! AuthorizationService::canViewPlayer( $user_id, $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You do not have access to this player.', 'talenttrack' ), 403 );
        }
        // #1867 — a parent only reads the journey when the child shares it.
        if ( ! AuthorizationService::parentCanViewSection( $user_id, $player_id, 'journey' ) ) {
            return RestResponse::error( 'section_private', __( 'This section has been kept private.', 'talenttrack' ), 403 );
        }

        $filters = self::extractTimelineFilters( $r );
        $allowed = PlayerEventsRepository::visibilitiesForUser( $user_id );

        $repo = new PlayerEventsRepository();
        $result = $repo->timelineForPlayer( $player_id, $filters, $allowed );

        // #1348 — reads that actually returned medical/safeguarding
        // entries are audit-logged (CLAUDE.md §1). Ordinary timeline
        // reads stay unlogged to keep the audit table signal-dense.
        $sensitive = 0;
        foreach ( $result['events'] as $event ) {
            if ( in_array( (string) ( $event->visibility ?? '' ), [ 'medical', 'safeguarding' ], true ) ) {
                $sensitive++;
            }
        }
        if ( $sensitive > 0 ) {
            ( new AuditService() )->record( 'player.sensitive_timeline_viewed', 'player', $player_id, [
                'sensitive_count' => $sensitive,
            ] );
        }

        return RestResponse::success( [
            'events'       => array_map( [ __CLASS__, 'formatEvent' ], $result['events'] ),
            'hidden_count' => $result['hidden_count'],
            'next_cursor'  => $result['next_cursor'],
        ] );
    }

    public static function get_transitions( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $user_id   = get_current_user_id();
        if ( ! AuthorizationService::canViewPlayer( $user_id, $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You do not have access to this player.', 'talenttrack' ), 403 );
        }
        // #1867 — a parent only reads the journey when the child shares it.
        if ( ! AuthorizationService::parentCanViewSection( $user_id, $player_id, 'journey' ) ) {
            return RestResponse::error( 'section_private', __( 'This section has been kept private.', 'talenttrack' ), 403 );
        }

        $allowed = PlayerEventsRepository::visibilitiesForUser( $user_id );
        $rows    = ( new PlayerEventsRepository() )->transitionsForPlayer( $player_id, $allowed );
        return RestResponse::success( [ 'events' => array_map( [ __CLASS__, 'formatEvent' ], $rows ) ] );
    }

    public static function get_rating_trend( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $user_id   = get_current_user_id();
        if ( ! AuthorizationService::canViewPlayer( $user_id, $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You do not have access to this player.', 'talenttrack' ), 403 );
        }

        $trend = ( new \TT\Infrastructure\Evaluations\EvaluationsRepository() )
            ->personalTrendForPlayer( $player_id );
        return RestResponse::success( $trend );
    }

    public static function create_event( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $user_id   = get_current_user_id();
        if ( ! AuthorizationService::canEditPlayer( $user_id, $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You cannot add events for this player.', 'talenttrack' ), 403 );
        }

        $payload     = (array) $r->get_json_params();
        $event_type  = sanitize_key( (string) ( $payload['event_type'] ?? 'note_added' ) );
        $summary     = sanitize_text_field( (string) ( $payload['summary'] ?? '' ) );
        $event_date  = sanitize_text_field( (string) ( $payload['event_date'] ?? current_time( 'mysql' ) ) );
        $visibility  = isset( $payload['visibility'] ) ? sanitize_key( (string) $payload['visibility'] ) : null;
        $extra       = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : [];

        if ( $summary === '' ) {
            return RestResponse::error( 'missing_summary', __( 'A summary is required.', 'talenttrack' ), 400 );
        }

        // Manual events use a synthetic source-entity id so uk_natural is
        // unique per emission. wp_generate_uuid4 keeps each manual entry
        // independently idempotent across repeated POSTs.
        $synthetic_id = (int) substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 9 );

        $id = EventEmitter::emit(
            $player_id,
            $event_type,
            $event_date,
            $summary,
            $extra,
            'Journey',
            'manual_note',
            $synthetic_id,
            $visibility
        );
        if ( ! $id ) {
            return RestResponse::error( 'emit_failed', __( 'Could not record the event.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function supersede_event( \WP_REST_Request $r ): \WP_REST_Response {
        $original_id = (int) $r['id'];
        $payload     = (array) $r->get_json_params();
        $repo = new PlayerEventsRepository();
        $original = $repo->find( $original_id );
        if ( ! $original ) {
            return RestResponse::error( 'not_found', __( 'Event not found.', 'talenttrack' ), 404 );
        }
        $user_id = get_current_user_id();
        if ( ! AuthorizationService::canEditPlayer( $user_id, (int) $original->player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You cannot retract events for this player.', 'talenttrack' ), 403 );
        }

        $summary    = sanitize_text_field( (string) ( $payload['summary'] ?? $original->summary ) );
        $event_date = sanitize_text_field( (string) ( $payload['event_date'] ?? $original->event_date ) );
        $visibility = isset( $payload['visibility'] ) ? sanitize_key( (string) $payload['visibility'] ) : (string) $original->visibility;
        $extra      = is_string( $original->payload ) ? (array) ( json_decode( $original->payload, true ) ?: [] ) : [];
        if ( isset( $payload['payload'] ) && is_array( $payload['payload'] ) ) {
            $extra = $payload['payload'];
        }

        $synthetic_id = (int) substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 9 );
        $replacement = EventEmitter::emit(
            (int) $original->player_id,
            (string) $original->event_type,
            $event_date,
            $summary,
            $extra,
            'Journey',
            'manual_correction',
            $synthetic_id,
            $visibility
        );
        if ( ! $replacement ) {
            return RestResponse::error( 'emit_failed', __( 'Could not record the corrected event.', 'talenttrack' ), 500 );
        }
        EventEmitter::supersede( $original_id, $replacement );
        return RestResponse::success( [ 'replacement_id' => $replacement, 'superseded_id' => $original_id ] );
    }

    public static function list_event_types(): \WP_REST_Response {
        $out = [];
        foreach ( EventTypeRegistry::all() as $def ) {
            $out[] = [
                'key'                => $def->key,
                'label'              => $def->label,
                'severity'           => $def->severity,
                'default_visibility' => $def->defaultVisibility,
                'group'              => $def->group,
                'icon'               => $def->icon,
                'color'              => $def->color,
            ];
        }
        return RestResponse::success( [ 'event_types' => $out ] );
    }

    public static function cohort_transitions( \WP_REST_Request $r ): \WP_REST_Response {
        $event_type = sanitize_key( (string) $r->get_param( 'event_type' ) );
        $from       = sanitize_text_field( (string) ( $r->get_param( 'from' ) ?? '' ) );
        $to         = sanitize_text_field( (string) ( $r->get_param( 'to' ) ?? '' ) );
        $team_id    = $r->get_param( 'team_id' ) !== null ? (int) $r->get_param( 'team_id' ) : null;

        if ( $event_type === '' || $from === '' || $to === '' ) {
            return RestResponse::error( 'missing_fields', __( 'event_type, from and to are required.', 'talenttrack' ), 400 );
        }

        $allowed = PlayerEventsRepository::visibilitiesForUser( get_current_user_id() );
        $rows = ( new PlayerEventsRepository() )->cohortByType( $event_type, $from, $to, $team_id, $allowed );
        return RestResponse::success( [ 'rows' => array_map( [ __CLASS__, 'formatCohortRow' ], $rows ) ] );
    }

    public static function list_injuries( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        // #1348 — the global medical cap alone is not enough: the viewer
        // must also be in scope for THIS player, and the read is logged.
        // Medical records on minors are the most sensitive data class in
        // the system (CLAUDE.md §1: permission-gated AND audit-logged).
        if ( ! AuthorizationService::canViewPlayer( get_current_user_id(), $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You do not have access to this player.', 'talenttrack' ), 403 );
        }
        $rows = ( new InjuryRepository() )->listForPlayer( $player_id, (bool) $r->get_param( 'include_archived' ) );
        ( new AuditService() )->record( 'player.injuries_viewed', 'player', $player_id, [
            'count' => count( $rows ),
        ] );
        return RestResponse::success( [ 'injuries' => array_map( static fn( $row ) => (array) $row, $rows ) ] );
    }

    public static function create_injury( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        if ( ! AuthorizationService::canEditPlayer( get_current_user_id(), $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You cannot record injuries for this player.', 'talenttrack' ), 403 );
        }
        $payload = (array) $r->get_json_params();

        $id = ( new InjuryRepository() )->create( [
            'player_id'             => $player_id,
            'started_on'            => sanitize_text_field( (string) ( $payload['started_on'] ?? '' ) ),
            'expected_return'       => isset( $payload['expected_return'] ) ? sanitize_text_field( (string) $payload['expected_return'] ) : null,
            'actual_return'         => isset( $payload['actual_return'] ) ? sanitize_text_field( (string) $payload['actual_return'] ) : null,
            'injury_type_lookup_id' => isset( $payload['injury_type_lookup_id'] ) ? (int) $payload['injury_type_lookup_id'] : null,
            'body_part_lookup_id'   => isset( $payload['body_part_lookup_id'] ) ? (int) $payload['body_part_lookup_id'] : null,
            'severity_lookup_id'    => isset( $payload['severity_lookup_id'] ) ? (int) $payload['severity_lookup_id'] : null,
            'notes'                 => sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) ),
        ] );

        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_request', __( 'Could not record injury.', 'talenttrack' ), 400 );
        }
        ( new AuditService() )->record( 'player.injury_created', 'player_injury', $id, [
            'player_id' => $player_id,
        ] );
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function update_injury( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = (int) $r['id'];
        $repo = new InjuryRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Injury not found.', 'talenttrack' ), 404 );
        }
        $player_id = (int) $row->player_id;
        if ( ! AuthorizationService::canEditPlayer( get_current_user_id(), $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You cannot edit injuries for this player.', 'talenttrack' ), 403 );
        }

        // #1348 — sanitize per field instead of passing client values
        // through array_intersect_key untouched.
        $payload = (array) $r->get_json_params();
        $patch   = [];
        foreach ( [ 'expected_return', 'actual_return' ] as $key ) {
            if ( array_key_exists( $key, $payload ) ) {
                $patch[ $key ] = $payload[ $key ] === null ? null : sanitize_text_field( (string) $payload[ $key ] );
            }
        }
        foreach ( [ 'injury_type_lookup_id', 'body_part_lookup_id', 'severity_lookup_id' ] as $key ) {
            if ( array_key_exists( $key, $payload ) ) {
                $patch[ $key ] = $payload[ $key ] === null ? null : (int) $payload[ $key ];
            }
        }
        if ( array_key_exists( 'notes', $payload ) ) {
            $patch['notes'] = sanitize_textarea_field( (string) $payload['notes'] );
        }

        $ok = $repo->update( $id, $patch );
        if ( $ok ) {
            ( new AuditService() )->record( 'player.injury_updated', 'player_injury', $id, [
                'player_id' => $player_id,
                'fields'    => array_keys( $patch ),
            ] );
        }
        return $ok
            ? RestResponse::success( [ 'updated' => true ] )
            : RestResponse::error( 'bad_request', __( 'No fields to update or update failed.', 'talenttrack' ), 400 );
    }

    public static function archive_injury( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = (int) $r['id'];
        $repo = new InjuryRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Injury not found.', 'talenttrack' ), 404 );
        }
        $player_id = (int) $row->player_id;
        if ( ! AuthorizationService::canEditPlayer( get_current_user_id(), $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You cannot edit injuries for this player.', 'talenttrack' ), 403 );
        }
        $ok = $repo->archive( $id, get_current_user_id() );
        if ( $ok ) {
            ( new AuditService() )->record( 'player.injury_archived', 'player_injury', $id, [
                'player_id' => $player_id,
            ] );
        }
        return $ok
            ? RestResponse::success( [ 'archived' => true ] )
            : RestResponse::error( 'not_found', __( 'Injury not found.', 'talenttrack' ), 404 );
    }

    /**
     * @param array{
     *   from?: string, to?: string,
     *   event_types?: list<string>,
     *   include_superseded?: bool,
     *   cursor?: int, limit?: int,
     * } $_unused
     */
    private static function extractTimelineFilters( \WP_REST_Request $r ): array {
        $now    = strtotime( current_time( 'mysql' ) ) ?: time();
        $twelve_months_ago = gmdate( 'Y-m-d 00:00:00', $now - ( 60 * 60 * 24 * 365 ) );

        $from = (string) ( $r->get_param( 'from' ) ?? '' );
        $to   = (string) ( $r->get_param( 'to' ) ?? '' );

        // Default 12-month window — caller can override or pass `full=1`
        // to drop the date filter entirely.
        if ( ! $r->get_param( 'full' ) ) {
            if ( $from === '' ) $from = $twelve_months_ago;
            if ( $to === '' )   $to   = current_time( 'mysql' );
        }

        $types = $r->get_param( 'event_type' );
        if ( is_string( $types ) && $types !== '' ) {
            $types = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $types ) ) ) );
        } elseif ( ! is_array( $types ) ) {
            $types = [];
        }

        return [
            'from'               => $from !== '' ? $from : null,
            'to'                 => $to !== '' ? $to : null,
            'event_types'        => $types,
            'include_superseded' => (bool) $r->get_param( 'include_superseded' ),
            'cursor'             => (int) $r->get_param( 'cursor' ),
            'limit'              => (int) ( $r->get_param( 'limit' ) ?: 50 ),
        ];
    }

    /** @param object $event */
    public static function formatEvent( $event ): array {
        $payload = is_string( $event->payload ?? null ) ? json_decode( $event->payload, true ) : null;
        return [
            'id'                  => (int) $event->id,
            'uuid'                => (string) $event->uuid,
            'player_id'           => (int) $event->player_id,
            'event_type'          => (string) $event->event_type,
            'event_date'          => (string) $event->event_date,
            'effective_from'      => $event->effective_from ?? null,
            'effective_to'        => $event->effective_to ?? null,
            'summary'             => (string) $event->summary,
            'payload'             => is_array( $payload ) ? $payload : [],
            'payload_valid'       => (bool) $event->payload_valid,
            'visibility'          => (string) $event->visibility,
            'source_module'       => (string) $event->source_module,
            'source_entity_type'  => (string) $event->source_entity_type,
            'source_entity_id'    => $event->source_entity_id !== null ? (int) $event->source_entity_id : null,
            'superseded_by'       => $event->superseded_by_event_id !== null ? (int) $event->superseded_by_event_id : null,
            'superseded_at'       => $event->superseded_at ?? null,
        ];
    }

    /** @param object $row */
    public static function formatCohortRow( $row ): array {
        $payload = is_string( $row->payload ?? null ) ? json_decode( $row->payload, true ) : null;
        return [
            'event_id'   => (int) $row->id,
            'player_id'  => (int) $row->player_id,
            'first_name' => (string) ( $row->first_name ?? '' ),
            'last_name'  => (string) ( $row->last_name ?? '' ),
            'team_id'    => (int) ( $row->team_id ?? 0 ),
            'event_type' => (string) $row->event_type,
            'event_date' => (string) $row->event_date,
            'summary'    => (string) $row->summary,
            'payload'    => is_array( $payload ) ? $payload : [],
        ];
    }
}
