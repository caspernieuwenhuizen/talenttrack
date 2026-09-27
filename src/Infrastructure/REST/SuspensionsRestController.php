<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Journey\SuspensionRepository;
use TT\Infrastructure\Journey\SuspensionService;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * SuspensionsRestController (#4103) — /wp-json/talenttrack/v1
 *
 *   GET    /players/{id}/suspensions  a player's suspensions, newest first
 *   POST   /players/{id}/suspensions  record one
 *   GET    /suspensions/{id}          one suspension
 *   PATCH  /suspensions/{id}          edit (PUT accepted too); partial
 *   DELETE /suspensions/{id}          archive
 *
 * The route-level gate names the capability; the handler then asks the
 * `player_suspensions` entity about THIS player through
 * `AuthorizationService::canAccessSuspensions()`, which is what keeps a
 * coach of another team out. The player and their parents read their own,
 * reason included (decision 5). The rendered views call the same
 * repository and service, so both answer alike.
 */
class SuspensionsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/suspensions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_for_player' ],
                'permission_callback' => self::permCanFeature( 'tt_view_suspensions', SuspensionService::FEATURE ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => self::permCanFeature( 'tt_manage_suspensions', SuspensionService::FEATURE ),
                'args'                => self::createArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/suspensions/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_one' ],
                'permission_callback' => self::permCanFeature( 'tt_view_suspensions', SuspensionService::FEATURE ),
            ],
            [
                'methods'             => 'PUT, PATCH',
                'callback'            => [ __CLASS__, 'update' ],
                'permission_callback' => self::permCanFeature( 'tt_manage_suspensions', SuspensionService::FEATURE ),
                'args'                => self::updateArgs(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive' ],
                'permission_callback' => self::permCanFeature( 'tt_manage_suspensions', SuspensionService::FEATURE ),
            ],
        ] );
    }

    /**
     * `POST /players/{id}/suspensions`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function createArgs(): array {
        return [
            'id'               => [ 'type' => [ 'integer', 'string' ], 'description' => 'The player, from the URL. A copy in the body is accepted and ignored.' ],
            'started_on'       => [ 'type' => 'string', 'required' => true, 'description' => 'The first day the suspension applies, as YYYY-MM-DD. Matches from this day on count.' ],
            'match_count'      => [ 'type' => [ 'integer', 'string' ], 'required' => true, 'description' => 'How many matches it lasts, 1 to 40.' ],
            'reason_lookup_id' => [ 'type' => [ 'integer', 'string', 'null' ], 'description' => 'Why, from the suspension_reason lookup.' ],
            'notes'            => [ 'type' => 'string', 'description' => 'Context. The player and their parents can read it.' ],
        ];
    }

    /**
     * `PATCH /suspensions/{id}`. Every field is optional and an omitted one
     * is left alone (CLAUDE.md §6). Once served, only the reason and the
     * note move: the matches it covered are history.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function updateArgs(): array {
        $args = self::createArgs();
        foreach ( $args as $key => $spec ) {
            unset( $args[ $key ]['required'] );
        }
        $args['id']['description'] = 'The suspension, from the URL. A copy in the body is accepted and ignored.';
        return $args;
    }

    public static function list_for_player( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $uid       = get_current_user_id();
        if ( ! AuthorizationService::canViewPlayer( $uid, $player_id )
            || ! AuthorizationService::canAccessSuspensions( $uid, $player_id, 'read' )
        ) {
            return RestResponse::error( 'forbidden', __( 'You do not have access to this player.', 'talenttrack' ), 403 );
        }

        $rows = ( new SuspensionRepository() )->listForPlayer( $player_id, (bool) $r->get_param( 'include_archived' ) );
        return RestResponse::success( [
            'suspensions' => array_map( [ __CLASS__, 'format' ], $rows ),
        ] );
    }

    public static function get_one( \WP_REST_Request $r ): \WP_REST_Response {
        $row = ( new SuspensionRepository() )->find( (int) $r['id'] );
        if ( $row === null ) {
            return RestResponse::error( 'not_found', __( 'Suspension not found.', 'talenttrack' ), 404 );
        }
        $player_id = (int) $row['player_id'];
        $uid       = get_current_user_id();
        if ( ! AuthorizationService::canViewPlayer( $uid, $player_id )
            || ! AuthorizationService::canAccessSuspensions( $uid, $player_id, 'read' )
        ) {
            // Not found rather than forbidden: whether another squad's player
            // is suspended is itself the thing being protected.
            return RestResponse::error( 'not_found', __( 'Suspension not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( self::format( $row ) );
    }

    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        $refused = self::checkBody( $r, self::createArgs() );
        if ( $refused !== null ) return $refused;

        $player_id = (int) $r['id'];
        if ( ! AuthorizationService::canAccessSuspensions( get_current_user_id(), $player_id, 'change' ) ) {
            return RestResponse::error( 'forbidden', __( 'You cannot record a suspension for this player.', 'talenttrack' ), 403 );
        }

        $payload = self::body( $r );
        $invalid = self::validate( $payload, true );
        if ( $invalid !== null ) return $invalid;

        $id = ( new SuspensionRepository() )->create( [
            'player_id'        => $player_id,
            'started_on'       => (string) $payload['started_on'],
            'match_count'      => (int) $payload['match_count'],
            'reason_lookup_id' => (int) ( $payload['reason_lookup_id'] ?? 0 ),
            'notes'            => sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) ),
        ] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_request', __( 'Could not record the suspension.', 'talenttrack' ), 400 );
        }

        ( new AuditService() )->record( 'player.suspension_created', 'player_suspension', $id, [
            'player_id' => $player_id,
        ] );

        $row = ( new SuspensionRepository() )->find( $id );
        return RestResponse::success( $row !== null ? self::format( $row ) : [ 'id' => $id ], 201 );
    }

    public static function update( \WP_REST_Request $r ): \WP_REST_Response {
        $refused = self::checkBody( $r, self::updateArgs() );
        if ( $refused !== null ) return $refused;

        $id   = (int) $r['id'];
        $repo = new SuspensionRepository();
        $row  = $repo->find( $id );
        if ( $row === null ) {
            return RestResponse::error( 'not_found', __( 'Suspension not found.', 'talenttrack' ), 404 );
        }
        $player_id = (int) $row['player_id'];
        if ( ! AuthorizationService::canAccessSuspensions( get_current_user_id(), $player_id, 'change' ) ) {
            return RestResponse::error( 'forbidden', __( 'You cannot edit suspensions for this player.', 'talenttrack' ), 403 );
        }

        $payload = self::body( $r );
        $invalid = self::validate( $payload, false );
        if ( $invalid !== null ) return $invalid;

        $served = ! empty( $row['served_on'] );
        $patch  = [];
        foreach ( [ 'started_on', 'match_count' ] as $key ) {
            if ( ! array_key_exists( $key, $payload ) ) continue;
            if ( $served && (string) $payload[ $key ] !== (string) $row[ $key ] ) {
                return RestResponse::error(
                    'suspension_served',
                    __( 'This suspension has been served. Its start date and number of matches can no longer change.', 'talenttrack' ),
                    409
                );
            }
            $patch[ $key ] = $payload[ $key ];
        }
        if ( array_key_exists( 'reason_lookup_id', $payload ) ) {
            $patch['reason_lookup_id'] = (int) $payload['reason_lookup_id'];
        }
        if ( array_key_exists( 'notes', $payload ) ) {
            $patch['notes'] = sanitize_textarea_field( (string) $payload['notes'] );
        }
        if ( $patch === [] ) {
            return RestResponse::error( 'no_fields', __( 'Nothing to update.', 'talenttrack' ), 400 );
        }

        if ( ! $repo->update( $id, $patch ) ) {
            return RestResponse::error( 'bad_request', __( 'Could not update the suspension.', 'talenttrack' ), 400 );
        }
        // A shorter ban may already be served; a moved start may cover
        // different matches. The service settles both.
        SuspensionService::onSuspensionRecorded( $id );

        ( new AuditService() )->record( 'player.suspension_updated', 'player_suspension', $id, [
            'player_id' => $player_id,
            'fields'    => array_keys( $patch ),
        ] );

        $fresh = $repo->find( $id );
        return RestResponse::success( $fresh !== null ? self::format( $fresh ) : [ 'id' => $id ] );
    }

    public static function archive( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = (int) $r['id'];
        $repo = new SuspensionRepository();
        $row  = $repo->find( $id );
        if ( $row === null ) {
            return RestResponse::error( 'not_found', __( 'Suspension not found.', 'talenttrack' ), 404 );
        }
        $player_id = (int) $row['player_id'];
        if ( ! AuthorizationService::canAccessSuspensions( get_current_user_id(), $player_id, 'create_delete' ) ) {
            return RestResponse::error( 'forbidden', __( 'You cannot remove suspensions for this player.', 'talenttrack' ), 403 );
        }
        if ( ! $repo->archive( $id, get_current_user_id() ) ) {
            return RestResponse::error( 'not_found', __( 'Suspension not found.', 'talenttrack' ), 404 );
        }
        ( new AuditService() )->record( 'player.suspension_archived', 'player_suspension', $id, [
            'player_id' => $player_id,
        ] );
        return RestResponse::success( [ 'archived' => true ] );
    }

    /**
     * The shape every route returns, and the one the views read.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format( array $row ): array {
        $status = SuspensionService::status( $row );
        $reason = (int) ( $row['reason_lookup_id'] ?? 0 );
        return [
            'id'               => (int) ( $row['id'] ?? 0 ),
            'uuid'             => (string) ( $row['uuid'] ?? '' ),
            'player_id'        => (int) ( $row['player_id'] ?? 0 ),
            'team_id'          => (int) ( $row['team_id'] ?? 0 ),
            'started_on'       => (string) ( $row['started_on'] ?? '' ),
            'match_count'      => (int) ( $row['match_count'] ?? 0 ),
            'matches_served'   => $status['served'],
            'status'           => $status['state'],
            'served_on'        => $status['served_on'] !== '' ? $status['served_on'] : null,
            'reason_lookup_id' => $reason > 0 ? $reason : null,
            'reason'           => SuspensionRepository::reasonLabel( $reason ),
            'notes'            => (string) ( $row['notes'] ?? '' ),
            'archived'         => ! empty( $row['archived_at'] ),
            'created_at'       => (string) ( $row['created_at'] ?? '' ),
            'updated_at'       => (string) ( $row['updated_at'] ?? '' ),
        ];
    }

    /** @return array<string, mixed> */
    private static function body( \WP_REST_Request $r ): array {
        $body = $r->get_json_params();
        if ( ! $body ) $body = $r->get_body_params();
        $out = [];
        foreach ( (array) $body as $key => $value ) {
            $out[ (string) $key ] = $value;
        }
        return $out;
    }

    /**
     * The values, once the shape has passed `checkBody()`.
     *
     * @param array<string, mixed> $payload
     */
    private static function validate( array $payload, bool $creating ): ?\WP_REST_Response {
        if ( $creating || array_key_exists( 'started_on', $payload ) ) {
            if ( SuspensionRepository::cleanDate( (string) ( $payload['started_on'] ?? '' ) ) === '' ) {
                return RestResponse::error( 'bad_started_on', __( 'Enter the start date as YYYY-MM-DD.', 'talenttrack' ), 400 );
            }
        }
        if ( $creating || array_key_exists( 'match_count', $payload ) ) {
            $raw = $payload['match_count'] ?? '';
            $n   = is_numeric( $raw ) ? (int) $raw : 0;
            if ( $n < 1 || $n > SuspensionRepository::MAX_MATCHES ) {
                return RestResponse::error(
                    'bad_match_count',
                    /* translators: %d: the highest number of matches allowed */
                    sprintf( __( 'A suspension lasts between 1 and %d matches.', 'talenttrack' ), SuspensionRepository::MAX_MATCHES ),
                    400
                );
            }
        }
        if ( ! empty( $payload['reason_lookup_id'] ) && SuspensionRepository::reasonLabel( (int) $payload['reason_lookup_id'] ) === '' ) {
            return RestResponse::error( 'bad_reason', __( 'That is not a suspension reason.', 'talenttrack' ), 400 );
        }
        return null;
    }
}
