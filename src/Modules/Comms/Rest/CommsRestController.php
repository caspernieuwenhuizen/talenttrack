<?php
namespace TT\Modules\Comms\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\Repositories\CommsInboxRepository;
use TT\Modules\Comms\Repositories\CommsLogRepository;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use WP_REST_Request;

/**
 * CommsRestController (#2605, Gate D).
 *
 *   GET   /comms/messages          the audit log, filterable
 *   GET   /players/{id}/messages   the same log, scoped to one player
 *   GET   /comms/inbox             the caller's own in-app messages
 *   PATCH /comms/inbox/{id}        mark one read / unread
 *   GET   /comms/templates         every registered template + its switch
 *   PATCH /comms/templates/{key}   flip one template's switch
 *   GET   /comms/preferences       the caller's per-message-type opt-outs
 *   PUT   /comms/preferences       replace them
 *
 * Comms was the last module of its size with no REST surface at all, which
 * put it outside CLAUDE.md §4: every feature has to be reachable by a
 * non-WordPress front end even when nothing consumes it yet. The queries
 * live in `Repositories\CommsLogRepository` / `CommsInboxRepository`, so
 * this controller composes and never decides — the Gate C surfaces call
 * the same repositories and cannot answer differently.
 *
 * ## Authorization, in three shapes
 *
 * The log routes gate on `tt_view_audit_log`. The comms log is a
 * read-only operator log about minors, which is the same audience and the
 * same sensitivity as the audit log and the error log — `ErrorLogRestController`
 * made exactly this reuse for exactly this reason. Deliberately NOT
 * `tt_send_email`: being allowed to send is not being allowed to read
 * what everyone else sent. (Whether coaches should have a narrower reader
 * cap for their own squad is Gate C's open question, not this one's.)
 *
 * The inbox and preference routes are `permLoggedIn`, because both are
 * scoped to `current_user_id()` in SQL. There is no route here that can
 * read another person's inbox — a parent's messages about their own child
 * are the most sensitive rows the module holds, and the guarantee is
 * structural rather than a capability check that could be got round.
 *
 * The template-switch routes gate on `tt_edit_settings`: turning a
 * template off is a configuration change for the whole academy.
 */
final class CommsRestController extends BaseController {

    /** The cap that reads the message log. See the class docblock. */
    public const CAP_READ_LOG = 'tt_view_audit_log';

    /** The cap that changes which templates are switched on. */
    public const CAP_MANAGE_TEMPLATES = 'tt_edit_settings';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        $log_args = [
            'player_id'    => [ 'sanitize_callback' => 'absint',            'required' => false ],
            'user_id'      => [ 'sanitize_callback' => 'absint',            'required' => false ],
            'template_key' => [ 'sanitize_callback' => 'sanitize_key',      'required' => false ],
            'message_type' => [ 'sanitize_callback' => 'sanitize_key',      'required' => false ],
            'status'       => [ 'sanitize_callback' => 'sanitize_key',      'required' => false ],
            'channel'      => [ 'sanitize_callback' => 'sanitize_key',      'required' => false ],
            'date_from'    => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
            'date_to'      => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
            'page'         => [ 'sanitize_callback' => 'absint', 'default' => 1 ],
            'per_page'     => [ 'sanitize_callback' => 'absint', 'default' => 50 ],
        ];

        register_rest_route( self::NS, '/comms/messages', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'listMessages' ],
                'permission_callback' => self::permCan( self::CAP_READ_LOG ),
                'args'                => $log_args,
            ],
        ] );

        // The player-scoped alias. §1 wants the question asked from the
        // player's record rather than from a global list with a filter
        // applied, and the `GET /players/{id}/timeline` precedent already
        // reads that way.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/messages', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'listPlayerMessages' ],
                'permission_callback' => self::permCan( self::CAP_READ_LOG ),
                'args'                => $log_args + [
                    'id' => [ 'validate_callback' => [ self::class, 'isPositiveInt' ] ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/comms/inbox', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'listInbox' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
                'args'                => [
                    'unread_only' => [ 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ],
                    'page'        => [ 'sanitize_callback' => 'absint', 'default' => 1 ],
                    'per_page'    => [ 'sanitize_callback' => 'absint', 'default' => 25 ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/comms/inbox/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ self::class, 'patchInboxItem' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
                'args'                => [
                    'id'   => [ 'validate_callback' => [ self::class, 'isPositiveInt' ] ],
                    'read' => [ 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/comms/templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'listTemplates' ],
                'permission_callback' => self::permCan( self::CAP_MANAGE_TEMPLATES ),
            ],
        ] );

        register_rest_route( self::NS, '/comms/templates/(?P<key>[a-z0-9_]+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ self::class, 'patchTemplate' ],
                'permission_callback' => self::permCan( self::CAP_MANAGE_TEMPLATES ),
                'args'                => [
                    'key'     => [ 'sanitize_callback' => 'sanitize_key' ],
                    'enabled' => [ 'sanitize_callback' => 'rest_sanitize_boolean', 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/comms/preferences', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'getPreferences' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'putPreferences' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
                'args'                => [
                    'opted_out' => [ 'required' => true ],
                ],
            ],
        ] );
    }

    // ── the log ─────────────────────────────────────────────────────────

    public static function listMessages( WP_REST_Request $req ): \WP_REST_Response {
        return self::respondWithLog( $req, self::filtersFrom( $req ) );
    }

    public static function listPlayerMessages( WP_REST_Request $req ): \WP_REST_Response {
        // The URL segment wins over any `player_id` query parameter: the
        // route says whose record this is.
        $filters = self::filtersFrom( $req );
        $filters['player_id'] = (int) $req['id'];
        return self::respondWithLog( $req, $filters );
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function respondWithLog( WP_REST_Request $req, array $filters ): \WP_REST_Response {
        $repo     = new CommsLogRepository();
        $page     = max( 1, (int) $req->get_param( 'page' ) );
        $per_page = max( 1, min( CommsLogRepository::MAX_PER_PAGE, (int) $req->get_param( 'per_page' ) ) );

        $total = $repo->count( $filters );
        $rows  = $repo->search( $filters, $page, $per_page );

        $response = RestResponse::success( [
            'messages'        => array_map( [ self::class, 'serializeLogRow' ], $rows ),
            'statuses_in_use' => $repo->statusesInUse(),
        ] );
        $response->header( 'X-WP-Total', (string) $total );
        $response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    private static function filtersFrom( WP_REST_Request $req ): array {
        return [
            'player_id'    => (int) $req->get_param( 'player_id' ),
            'user_id'      => (int) $req->get_param( 'user_id' ),
            'template_key' => (string) $req->get_param( 'template_key' ),
            'message_type' => (string) $req->get_param( 'message_type' ),
            'status'       => (string) $req->get_param( 'status' ),
            'channel'      => (string) $req->get_param( 'channel' ),
            'date_from'    => (string) $req->get_param( 'date_from' ),
            'date_to'      => (string) $req->get_param( 'date_to' ),
        ];
    }

    /**
     * The audit row as a consumer sees it.
     *
     * No body and no hash. The body is not stored; the hash is an internal
     * integrity value and telling an API consumer about it would only
     * invite someone to try to reverse it.
     *
     * @return array<string,mixed>
     */
    private static function serializeLogRow( object $row ): array {
        return [
            'id'           => (int) $row->id,
            'uuid'         => (string) $row->uuid,
            'created_at'   => (string) $row->created_at,
            'template_key' => (string) $row->template_key,
            'message_type' => (string) $row->message_type,
            'channel'      => (string) $row->channel,
            'sender_user_id' => (int) $row->sender_user_id,
            'recipient'    => [
                'user_id'   => $row->recipient_user_id !== null ? (int) $row->recipient_user_id : null,
                'player_id' => $row->recipient_player_id !== null ? (int) $row->recipient_player_id : null,
                'kind'      => (string) $row->recipient_kind,
                'address'   => (string) $row->address_blob,
            ],
            'subject'      => $row->subject !== null ? (string) $row->subject : null,
            'status'       => (string) $row->status,
            'error_code'   => $row->error_code !== null ? (string) $row->error_code : null,
            'attempt'      => (int) $row->attempt,
        ];
    }

    // ── the inbox ───────────────────────────────────────────────────────

    public static function listInbox( WP_REST_Request $req ): \WP_REST_Response {
        $user = get_current_user_id();
        $repo = new CommsInboxRepository();

        $unread_only = (bool) $req->get_param( 'unread_only' );
        $page        = max( 1, (int) $req->get_param( 'page' ) );
        $per_page    = max( 1, min( CommsInboxRepository::MAX_PER_PAGE, (int) $req->get_param( 'per_page' ) ) );

        $total = $repo->countForUser( $user, $unread_only );
        $rows  = $repo->forUser( $user, $unread_only, $page, $per_page );

        $response = RestResponse::success( [
            'messages'     => array_map( [ self::class, 'serializeInboxRow' ], $rows ),
            'unread_count' => $repo->unreadCount( $user ),
        ] );
        $response->header( 'X-WP-Total', (string) $total );
        $response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
        return $response;
    }

    public static function patchInboxItem( WP_REST_Request $req ): \WP_REST_Response {
        $user = get_current_user_id();
        $id   = (int) $req['id'];
        $read = (bool) $req->get_param( 'read' );
        $repo = new CommsInboxRepository();

        // 404, not 403: a message that is not yours does not exist as far
        // as this route is concerned, so the response cannot be used to
        // confirm that some other family received something.
        if ( $repo->findForUser( $id, $user ) === null ) {
            return RestResponse::error( 'not_found', __( 'That message is not in your inbox.', 'talenttrack' ), 404 );
        }
        if ( ! $repo->setRead( $id, $user, $read ) ) {
            return RestResponse::error( 'db_error', __( 'The message could not be updated.', 'talenttrack' ), 500 );
        }

        $row = $repo->findForUser( $id, $user );
        return RestResponse::success( [
            'message'      => $row !== null ? self::serializeInboxRow( $row ) : null,
            'unread_count' => $repo->unreadCount( $user ),
        ] );
    }

    /** @return array<string,mixed> */
    private static function serializeInboxRow( object $row ): array {
        $payload = (string) ( $row->payload_json ?? '' );
        $decoded = $payload !== '' ? json_decode( $payload, true ) : null;

        return [
            'id'           => (int) $row->id,
            'uuid'         => (string) $row->uuid,
            'created_at'   => (string) $row->created_at,
            'template_key' => (string) $row->template_key,
            'message_type' => (string) $row->message_type,
            'player_id'    => $row->recipient_player_id !== null ? (int) $row->recipient_player_id : null,
            'subject'      => $row->subject !== null ? (string) $row->subject : null,
            'body'         => (string) ( $row->body ?? '' ),
            'payload'      => is_array( $decoded ) ? $decoded : null,
            'read_at'      => $row->read_at !== null ? (string) $row->read_at : null,
            'is_read'      => $row->read_at !== null && (string) $row->read_at !== '',
        ];
    }

    // ── the template switch ─────────────────────────────────────────────

    public static function listTemplates(): \WP_REST_Response {
        $disabled = TemplateSwitch::disabledKeys();
        $out      = [];

        foreach ( TemplateRegistry::all() as $key => $template ) {
            $out[] = [
                'key'      => (string) $key,
                'label'    => $template->label(),
                'channels' => $template->supportedChannels(),
                'editable' => $template->isEditable(),
                'enabled'  => ! in_array( (string) $key, $disabled, true ),
            ];
        }

        return RestResponse::success( [ 'templates' => $out ] );
    }

    public static function patchTemplate( WP_REST_Request $req ): \WP_REST_Response {
        $key = (string) $req['key'];
        if ( TemplateRegistry::get( $key ) === null ) {
            return RestResponse::error( 'unknown_template', __( 'No such message template.', 'talenttrack' ), 404 );
        }

        $enabled  = (bool) $req->get_param( 'enabled' );
        $disabled = TemplateSwitch::disabledKeys();

        $disabled = $enabled
            ? array_values( array_diff( $disabled, [ $key ] ) )
            : array_values( array_unique( array_merge( $disabled, [ $key ] ) ) );

        TemplateSwitch::setDisabled( $disabled );

        return RestResponse::success( [ 'key' => $key, 'enabled' => TemplateSwitch::isEnabled( $key ) ] );
    }

    // ── the caller's own opt-outs ───────────────────────────────────────

    public static function getPreferences(): \WP_REST_Response {
        $user = get_current_user_id();
        // Only the mutable types are offered. An operational type would
        // render a switch that silently does nothing — `OptOutPolicy`
        // ignores it by design, and safeguarding email is not negotiable.
        return RestResponse::success( [
            'message_types' => MessageType::optOutable(),
            'opted_out'     => ( new OptOutPolicy() )->optedOutTypesFor( $user ),
        ] );
    }

    public static function putPreferences( WP_REST_Request $req ): \WP_REST_Response {
        $user = get_current_user_id();
        $sent = $req->get_param( 'opted_out' );
        if ( ! is_array( $sent ) ) {
            return RestResponse::error( 'bad_payload', __( 'Expected a list of message types.', 'talenttrack' ), 400 );
        }

        $known  = MessageType::optOutable();
        $wanted = array_values( array_intersect( array_map( 'strval', $sent ), $known ) );
        $policy = new OptOutPolicy();

        // Replace the whole set rather than diffing what the caller sent:
        // a PUT is the caller stating the complete list, and a type they
        // left out is a type they want to hear about again.
        foreach ( $known as $type ) {
            $policy->setOptedOut( $user, $type, in_array( $type, $wanted, true ) );
        }

        return RestResponse::success( [
            'message_types' => $known,
            'opted_out'     => $policy->optedOutTypesFor( $user ),
        ] );
    }
}
