<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Domain\ProspectOutcome;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectVisitObservationsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Prospects\ScoutingVisitsAccess;

/**
 * ScoutingVisitsRestController — /wp-json/talenttrack/v1/scouting-visits
 *
 * List + read + create + update + archive endpoints for the scout's
 * visit planner. The PHP views (FrontendScoutingPlanView /
 * FrontendScoutingVisitDetailView) render the same data; #3604 added the
 * two read routes so a client that writes a visit can read back what was
 * actually stored, and so the surface is reachable without WordPress.
 *
 * Cap model: read requires one of the prospects caps AND the
 * `scouting_visits_panel` entity (ScoutingVisitsAccess); write requires
 * `tt_edit_prospects` (which every scout holds). A scout only reads,
 * edits and archives their own visits; `tt_manage_prospects` (HoD +
 * admin) reaches any. That rule lives in ScoutingVisitsAccess, which the
 * views call too.
 *
 * Every response goes through `serialize()`, so a create, an update and a
 * read describe a visit with the same field names.
 */
class ScoutingVisitsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        // #3600 — the routes declare their fields, so a client can find
        // them: a misnamed `age_groups` used to vanish behind a 200.
        register_rest_route( self::NS, '/scouting-visits', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'index' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
                'args'                => self::listArgs(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::visitArgs( true ),
            ],
        ] );

        register_rest_route( self::NS, '/scouting-visits/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'show' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'update' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::visitArgs( false ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // #3711 — who was watched at this visit. Nested under the visit
        // because the visit is what decides access: a scout reads and
        // writes observations on their own visits, the head of development
        // on any. A top-level `/observations/{id}` would have to resolve
        // the visit first to ask the same question.
        register_rest_route( self::NS, '/scouting-visits/(?P<id>\d+)/observations', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_observations' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_observation' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => [
                    'prospect_id' => [
                        'type'        => 'integer',
                        'required'    => true,
                        'description' => 'The existing prospect watched at this visit.',
                    ],
                    'observed_at' => [
                        'type'        => 'string',
                        'required'    => false,
                        'description' => 'YYYY-MM-DD. Defaults to the visit date.',
                    ],
                    'notes' => [
                        'type'        => 'string',
                        'required'    => false,
                        'description' => 'What was seen. Free text.',
                    ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/scouting-visits/(?P<id>\d+)/observations/(?P<observation_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_observation' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    /**
     * The fields a visit write accepts. On create, `visit_date` and
     * `location` are required; on update every field is optional and only
     * the ones sent are changed.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function visitArgs( bool $create ): array {
        return [
            'visit_date' => [
                'type'        => 'string',
                'required'    => $create,
                'description' => 'Date of the visit, YYYY-MM-DD.',
            ],
            'location' => [
                'type'        => 'string',
                'required'    => $create,
                'description' => 'Where the visit is: club, ground or tournament.',
            ],
            'visit_time' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Start time, HH:MM. Blank clears it.',
            ],
            'event_description' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'What is being watched, e.g. a district tournament.',
            ],
            'age_groups_csv' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Age groups to watch, comma-separated, e.g. "u13,u14".',
            ],
            'notes' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Free-text notes.',
            ],
            'status' => [
                'type'        => 'string',
                'enum'        => [
                    ScoutingVisitsRepository::STATUS_PLANNED,
                    ScoutingVisitsRepository::STATUS_COMPLETED,
                    ScoutingVisitsRepository::STATUS_CANCELLED,
                ],
                'description' => 'planned (default), completed or cancelled.',
            ],
            'scout_user_id' => [
                'type'        => 'integer',
                'description' => 'The scout the visit belongs to. Defaults to the caller.',
            ],
        ];
    }

    /**
     * The filters `GET /scouting-visits` takes. Each one is a column the
     * repository already searches on; nothing here widens what a caller
     * may see — `scout_user_id` is overruled for a scout who may only
     * read their own.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function listArgs(): array {
        return [
            'scout_user_id' => [
                'type'        => 'integer',
                'description' => 'Only this scout\'s visits. Ignored for a caller who may only read their own.',
            ],
            'status' => [
                'type'        => 'string',
                'enum'        => [
                    ScoutingVisitsRepository::STATUS_PLANNED,
                    ScoutingVisitsRepository::STATUS_COMPLETED,
                    ScoutingVisitsRepository::STATUS_CANCELLED,
                ],
                'description' => 'planned, completed or cancelled.',
            ],
            'date_from' => [
                'type'        => 'string',
                'description' => 'Earliest visit date, YYYY-MM-DD.',
            ],
            'date_to' => [
                'type'        => 'string',
                'description' => 'Latest visit date, YYYY-MM-DD.',
            ],
            'include_archived' => [
                'type'        => 'boolean',
                'description' => 'Include archived visits. Default false.',
            ],
        ];
    }

    public static function can_edit(): bool {
        $uid = get_current_user_id();
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_prospects' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_prospects' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_settings' );
    }

    /**
     * Read permission — the same pair the detail view checks (#2007): a
     * prospects capability decides whether the caller may read prospect
     * data at all, the panel entity decides whether the scout's visit
     * surfaces are theirs. A head coach holds the first on purpose and
     * must not hold the second.
     */
    public static function can_read(): bool {
        $uid = get_current_user_id();
        if ( ! ScoutingVisitsAccess::allows( $uid, self::isScopeAdmin() ) ) return false;

        return AuthorizationService::userCanOrMatrix( $uid, 'tt_view_prospects' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_prospects' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_prospects' );
    }

    public static function index( \WP_REST_Request $r ): \WP_REST_Response {
        $uid     = get_current_user_id();
        $filters = [];

        $forced = ScoutingVisitsAccess::forcedScoutFilter( $uid, self::isScopeAdmin() );
        if ( $forced !== null ) {
            $filters['scout_user_id'] = $forced;
        } elseif ( isset( $r['scout_user_id'] ) && (int) $r['scout_user_id'] > 0 ) {
            $filters['scout_user_id'] = (int) $r['scout_user_id'];
        }

        if ( isset( $r['status'] ) && (string) $r['status'] !== '' ) {
            $filters['status'] = self::normaliseStatus( (string) $r['status'] );
        }
        foreach ( [ 'date_from' => 'from', 'date_to' => 'to' ] as $param => $key ) {
            if ( ! isset( $r[ $param ] ) || (string) $r[ $param ] === '' ) continue;
            $date = sanitize_text_field( (string) $r[ $param ] );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                return RestResponse::error( 'bad_date',
                    __( 'Dates must be written as YYYY-MM-DD.', 'talenttrack' ), 400,
                    [ 'field' => $param ] );
            }
            $filters[ $key ] = $date;
        }
        if ( ! empty( $r['include_archived'] ) ) {
            $filters['include_archived'] = true;
        }

        $repo = new ScoutingVisitsRepository();
        $rows = $repo->search( $filters );
        // One count query for the whole page, not one per row.
        $counts = $repo->prospectCountsForVisits(
            array_values( array_map( static fn( $row ) => (int) ( ( (array) $row )['id'] ?? 0 ), $rows ) )
        );

        $out = [];
        foreach ( $rows as $row ) {
            $id    = (int) ( ( (array) $row )['id'] ?? 0 );
            $out[] = self::serialize( $row, (int) ( $counts[ $id ] ?? 0 ) );
        }

        return RestResponse::success( [ 'rows' => $out, 'total' => count( $out ) ] );
    }

    public static function show( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid scouting visit id.', 'talenttrack' ), 400 );
        }

        $repo = new ScoutingVisitsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Scouting visit not found.', 'talenttrack' ), 404 );
        }
        if ( ! ScoutingVisitsAccess::canReadVisit( get_current_user_id(), $row, self::isScopeAdmin() ) ) {
            return RestResponse::error( 'forbidden',
                __( 'You can only view your own scouting visits.', 'talenttrack' ), 403 );
        }

        $visit = self::serialize( $row, $repo->prospectCount( $id ) );
        $visit['prospects'] = self::serialiseProspects( $repo->prospectsForVisit( $id ) );

        return RestResponse::success( [ 'visit' => $visit ] );
    }

    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        // #3689 contract: a key this route does not take is named back to
        // the caller rather than dropped behind a 200. `club` and
        // `age_groups` were the two that kept vanishing.
        $bad = BaseController::checkBody( $r, self::visitArgs( true ) );
        if ( $bad ) return $bad;

        $visit_date = sanitize_text_field( (string) ( $r['visit_date'] ?? '' ) );
        $location   = sanitize_text_field( (string) ( $r['location'] ?? '' ) );

        if ( $visit_date === '' ) {
            return RestResponse::error( 'missing_fields',
                __( 'Visit date is required.', 'talenttrack' ), 400 );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $visit_date ) ) {
            return RestResponse::error( 'invalid_date',
                __( 'Visit date must be YYYY-MM-DD.', 'talenttrack' ), 400 );
        }
        if ( $location === '' ) {
            return RestResponse::error( 'missing_fields',
                __( 'Location is required.', 'talenttrack' ), 400 );
        }

        $payload = [
            'visit_date'        => $visit_date,
            'visit_time'        => self::normaliseTime( $r['visit_time'] ?? null ),
            'location'          => $location,
            'event_description' => isset( $r['event_description'] )
                ? sanitize_text_field( (string) $r['event_description'] )
                : null,
            'age_groups_csv'    => isset( $r['age_groups_csv'] )
                ? self::sanitiseCsv( (string) $r['age_groups_csv'] )
                : null,
            'notes'             => isset( $r['notes'] )
                ? sanitize_textarea_field( (string) $r['notes'] )
                : null,
            'scout_user_id'     => isset( $r['scout_user_id'] ) && (int) $r['scout_user_id'] > 0
                ? (int) $r['scout_user_id']
                : get_current_user_id(),
            'status'            => self::normaliseStatus( (string) ( $r['status'] ?? '' ) ),
        ];

        $repo = new ScoutingVisitsRepository();
        $id   = $repo->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'scouting_visit.create.failed', [ 'payload' => $payload ] );
            return RestResponse::error( 'db_error',
                __( 'The scouting visit could not be saved.', 'talenttrack' ), 500 );
        }

        // The stored row, not an echo of the request: a caller can see what
        // the sanitisers made of what it sent. It still carries `id`.
        $stored = $repo->find( $id );

        return RestResponse::success( $stored ? self::serialize( $stored, 0 ) : [ 'id' => $id ] );
    }

    public static function update( \WP_REST_Request $r ): \WP_REST_Response {
        $bad = BaseController::checkBody( $r, self::visitArgs( false ) );
        if ( $bad ) return $bad;

        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid scouting visit id.', 'talenttrack' ), 400 );
        }

        $repo  = new ScoutingVisitsRepository();
        $row   = $repo->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Scouting visit not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canEditRow( $row ) ) {
            return RestResponse::error( 'forbidden', __( 'You can only edit your own scouting visits.', 'talenttrack' ), 403 );
        }

        $patch = [];
        if ( isset( $r['visit_date'] ) ) {
            $d = sanitize_text_field( (string) $r['visit_date'] );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
                return RestResponse::error( 'invalid_date',
                    __( 'Visit date must be YYYY-MM-DD.', 'talenttrack' ), 400 );
            }
            $patch['visit_date'] = $d;
        }
        if ( array_key_exists( 'visit_time', $r->get_params() ) ) {
            $patch['visit_time'] = self::normaliseTime( $r['visit_time'] );
        }
        if ( isset( $r['location'] ) ) {
            $patch['location'] = sanitize_text_field( (string) $r['location'] );
        }
        if ( array_key_exists( 'event_description', $r->get_params() ) ) {
            $patch['event_description'] = $r['event_description'] !== null && $r['event_description'] !== ''
                ? sanitize_text_field( (string) $r['event_description'] )
                : null;
        }
        if ( array_key_exists( 'age_groups_csv', $r->get_params() ) ) {
            $patch['age_groups_csv'] = $r['age_groups_csv'] !== null && $r['age_groups_csv'] !== ''
                ? self::sanitiseCsv( (string) $r['age_groups_csv'] )
                : null;
        }
        if ( array_key_exists( 'notes', $r->get_params() ) ) {
            $patch['notes'] = $r['notes'] !== null && $r['notes'] !== ''
                ? sanitize_textarea_field( (string) $r['notes'] )
                : null;
        }
        if ( isset( $r['status'] ) ) {
            $patch['status'] = self::normaliseStatus( (string) $r['status'] );
        }

        if ( ! $patch ) {
            return RestResponse::success( [
                'id'      => $id,
                'changed' => false,
                'visit'   => self::serialize( $row, $repo->prospectCount( $id ) ),
            ] );
        }

        $ok      = $repo->update( $id, $patch );
        $stored  = $repo->find( $id ) ?? $row;

        return RestResponse::success( [
            'id'      => $id,
            'changed' => $ok,
            'visit'   => self::serialize( $stored, $repo->prospectCount( $id ) ),
        ] );
    }

    public static function archive( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid scouting visit id.', 'talenttrack' ), 400 );
        }
        $repo = new ScoutingVisitsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Scouting visit not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canEditRow( $row ) ) {
            return RestResponse::error( 'forbidden', __( 'You can only archive your own scouting visits.', 'talenttrack' ), 403 );
        }
        $repo->archive( $id );
        return RestResponse::success( [ 'id' => $id, 'archived' => true ] );
    }

    /**
     * One shape for a visit, whatever route answers. `visit_time` is
     * HH:MM — the seconds the column stores are not something a caller
     * ever sent or needs.
     *
     * @return array<string,mixed>
     */
    private static function serialize( object $row, int $prospect_count ): array {
        $visit = (array) $row;
        $time  = (string) ( $visit['visit_time'] ?? '' );
        $scout = get_userdata( (int) ( $visit['scout_user_id'] ?? 0 ) );

        return [
            'id'                => (int) ( $visit['id'] ?? 0 ),
            'uuid'              => (string) ( $visit['uuid'] ?? '' ),
            'visit_date'        => (string) ( $visit['visit_date'] ?? '' ),
            'visit_time'        => ( $time !== '' && $time !== '00:00:00' ) ? substr( $time, 0, 5 ) : null,
            'location'          => (string) ( $visit['location'] ?? '' ),
            'event_description' => self::nullableString( $visit['event_description'] ?? null ),
            'age_groups_csv'    => self::nullableString( $visit['age_groups_csv'] ?? null ),
            'notes'             => self::nullableString( $visit['notes'] ?? null ),
            'status'            => (string) ( $visit['status'] ?? ScoutingVisitsRepository::STATUS_PLANNED ),
            'scout_user_id'     => (int) ( $visit['scout_user_id'] ?? 0 ),
            'scout_name'        => $scout ? (string) $scout->display_name : '',
            'archived_at'       => self::nullableString( $visit['archived_at'] ?? null ),
            'prospect_count'    => $prospect_count,
        ];
    }

    /**
     * The prospects logged from a visit, as the detail view lists them.
     *
     * Birth YEAR only, never the date of birth: these are children, and
     * "who did we watch" does not need the day they were born.
     *
     * @param object[] $rows
     * @return list<array<string,mixed>>
     */
    /**
     * GET /scouting-visits/{id}/observations — everyone watched at this
     * visit, with the observation id the DELETE below takes.
     */
    public static function list_observations( \WP_REST_Request $r ): \WP_REST_Response {
        $visit = self::readableVisit( (int) $r['id'] );
        if ( $visit instanceof \WP_REST_Response ) return $visit;

        $repo = new ScoutingVisitsRepository();
        return RestResponse::success( [
            'visit_id'     => (int) $visit->id,
            'observations' => self::serialiseObservations( $repo->prospectsForVisit( (int) $visit->id ), (int) $visit->id ),
        ] );
    }

    /**
     * POST /scouting-visits/{id}/observations — record that an existing
     * prospect was watched here.
     *
     * Linking the same prospect twice returns the existing observation
     * rather than creating a second one: a double tap is one statement
     * made twice, not two sightings.
     */
    public static function create_observation( \WP_REST_Request $r ): \WP_REST_Response {
        $visit = self::readableVisit( (int) $r['id'] );
        if ( $visit instanceof \WP_REST_Response ) return $visit;
        if ( ! empty( $visit->archived_at ) ) {
            return RestResponse::error( 'visit_archived',
                __( 'This scouting visit is archived, so prospects cannot be linked to it.', 'talenttrack' ), 409 );
        }

        $prospect_id = (int) $r['prospect_id'];
        if ( $prospect_id <= 0 ) {
            return RestResponse::error( 'bad_prospect', __( 'Invalid prospect id.', 'talenttrack' ), 400 );
        }
        // These are minors: a caller may only link a prospect they could
        // already see. Without this, the id in the body would be a way to
        // confirm that a child outside the caller's age groups exists.
        if ( ! ProspectScope::canSee( get_current_user_id(), $prospect_id ) ) {
            return RestResponse::error( 'not_found', __( 'Prospect not found.', 'talenttrack' ), 404 );
        }

        $observed_at = sanitize_text_field( (string) ( $r['observed_at'] ?? '' ) );
        if ( $observed_at === '' ) $observed_at = (string) $visit->visit_date;

        $id = ( new ProspectVisitObservationsRepository() )->link(
            $prospect_id,
            (int) $visit->id,
            $observed_at,
            sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) )
        );
        if ( $id <= 0 ) {
            return RestResponse::error( 'link_failed',
                __( 'Could not link the prospect to this visit.', 'talenttrack' ), 500 );
        }

        Logger::info( 'prospect linked to scouting visit', [
            'observation_id' => $id,
            'prospect_id'    => $prospect_id,
            'visit_id'       => (int) $visit->id,
        ] );

        $repo = new ScoutingVisitsRepository();
        return RestResponse::success( [
            'observation_id' => $id,
            'visit_id'       => (int) $visit->id,
            'observations'   => self::serialiseObservations( $repo->prospectsForVisit( (int) $visit->id ), (int) $visit->id ),
        ] );
    }

    /** DELETE /scouting-visits/{id}/observations/{observation_id} — undo a link. */
    public static function delete_observation( \WP_REST_Request $r ): \WP_REST_Response {
        $visit = self::readableVisit( (int) $r['id'] );
        if ( $visit instanceof \WP_REST_Response ) return $visit;

        $observations = new ProspectVisitObservationsRepository();
        $observation  = $observations->findById( (int) $r['observation_id'] );
        if ( ! $observation || (int) $observation->scouting_visit_id !== (int) $visit->id ) {
            return RestResponse::error( 'not_found', __( 'Observation not found.', 'talenttrack' ), 404 );
        }

        $observations->delete( (int) $observation->id );

        $repo = new ScoutingVisitsRepository();
        return RestResponse::success( [
            'visit_id'     => (int) $visit->id,
            'observations' => self::serialiseObservations( $repo->prospectsForVisit( (int) $visit->id ), (int) $visit->id ),
        ] );
    }

    /**
     * The visit behind an observation route, or the refusal to return.
     * One place, so the three routes cannot answer differently.
     *
     * @return object|\WP_REST_Response
     */
    private static function readableVisit( int $id ) {
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid scouting visit id.', 'talenttrack' ), 400 );
        }
        $row = ( new ScoutingVisitsRepository() )->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Scouting visit not found.', 'talenttrack' ), 404 );
        }
        if ( ! ScoutingVisitsAccess::canReadVisit( get_current_user_id(), $row, self::isScopeAdmin() ) ) {
            return RestResponse::error( 'forbidden',
                __( 'You can only view your own scouting visits.', 'talenttrack' ), 403 );
        }
        return $row;
    }

    /**
     * #3711 — the prospect rows plus the observation that put them on this
     * visit, so a client can undo the link it just made and tell the
     * discovery sighting from a later one.
     *
     * @param object[] $rows rows from `ScoutingVisitsRepository::prospectsForVisit()`
     * @return array<int, array<string,mixed>>
     */
    private static function serialiseObservations( array $rows, int $visit_id ): array {
        $prospects = self::serialiseProspects( $rows );
        $out       = [];

        foreach ( array_values( $rows ) as $i => $row ) {
            $row   = (array) $row;
            $entry = $prospects[ $i ] ?? [ 'id' => (int) ( $row['id'] ?? 0 ) ];

            $out[] = $entry + [
                'observation_id' => (int) ( $row['observation_id'] ?? 0 ),
                'observed_at'    => self::nullableString( $row['observed_at'] ?? null ),
                'notes'          => self::nullableString( $row['observation_notes'] ?? null ),
                // The discovery pointer still lives on the prospect, so
                // "is this the visit they were found at" is answerable
                // without ordering every sighting again.
                'is_discovery'   => (int) ( $row['scouting_visit_id'] ?? 0 ) === $visit_id,
            ];
        }
        return $out;
    }

    private static function serialiseProspects( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $p          = (array) $row;
            $dob        = (string) ( $p['date_of_birth'] ?? '' );
            $birth_year = preg_match( '/^(\d{4})/', $dob, $m ) ? (int) $m[1] : null;
            $outcome    = ProspectOutcome::forRow( $p );

            $out[] = [
                'id'            => (int) ( $p['id'] ?? 0 ),
                'name'          => trim( (string) ( $p['first_name'] ?? '' ) . ' ' . (string) ( $p['last_name'] ?? '' ) ),
                'birth_year'    => $birth_year,
                'club'          => self::nullableString( $p['current_club'] ?? null ),
                'position'      => self::nullableString( $p['position'] ?? null ),
                'logged_at'     => self::nullableString( $p['discovered_at'] ?? null ),
                'outcome'       => $outcome,
                'outcome_label' => ProspectOutcome::label( $outcome ),
            ];
        }

        return $out;
    }

    /** @param mixed $value */
    private static function nullableString( $value ): ?string {
        return ( $value === null || $value === '' ) ? null : (string) $value;
    }

    /**
     * The bypass this controller has always applied: an operator who can
     * edit settings reaches every visit. Passed to ScoutingVisitsAccess as
     * the caller's administrator determination.
     */
    private static function isScopeAdmin(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_edit_settings' );
    }

    private static function canEditRow( object $row ): bool {
        // Reading and editing a visit are the same question — it is the
        // scout's own planning record — so both go through the one rule in
        // ScoutingVisitsAccess (#3604) rather than a copy per route.
        return ScoutingVisitsAccess::canReadVisit( get_current_user_id(), $row, self::isScopeAdmin() );
    }

    private static function normaliseTime( $raw ): ?string {
        if ( $raw === null || $raw === '' ) return null;
        $raw = (string) $raw;
        if ( preg_match( '/^\d{2}:\d{2}$/', $raw ) ) {
            return $raw . ':00';
        }
        if ( preg_match( '/^\d{2}:\d{2}:\d{2}$/', $raw ) ) {
            return $raw;
        }
        return null;
    }

    private static function sanitiseCsv( string $raw ): ?string {
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        if ( ! $parts ) return null;
        $parts = array_map( static fn( $v ) => sanitize_key( (string) $v ), $parts );
        return implode( ',', array_filter( $parts ) );
    }

    private static function normaliseStatus( string $raw ): string {
        $allowed = [
            ScoutingVisitsRepository::STATUS_PLANNED,
            ScoutingVisitsRepository::STATUS_COMPLETED,
            ScoutingVisitsRepository::STATUS_CANCELLED,
        ];
        return in_array( $raw, $allowed, true ) ? $raw : ScoutingVisitsRepository::STATUS_PLANNED;
    }
}
