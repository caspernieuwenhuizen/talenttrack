<?php
namespace TT\Modules\Threads\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadMessagesRepository;
use TT\Modules\Threads\ThreadReadsRepository;
use TT\Modules\Threads\ThreadTypeRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ThreadsRestController (#0028) — five endpoints under /threads/{type}/{id}.
 *
 *   GET    /threads/{type}/{id}                     list messages + mark-read
 *   POST   /threads/{type}/{id}/messages            post message
 *   PUT    /threads/{type}/{id}/messages/{msg_id}   edit (5-min window, author only)
 *   DELETE /threads/{type}/{id}/messages/{msg_id}   soft-delete (author or admin)
 *   POST   /threads/{type}/{id}/read                explicit read marker
 */
final class ThreadsRestController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        $args = [
            'type' => [
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => static fn( $v ): bool => is_string( $v ) && $v !== '' && ThreadTypeRegistry::get( $v ) !== null,
            ],
            'id' => [
                'sanitize_callback' => 'absint',
                'validate_callback' => static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0,
            ],
        ];

        register_rest_route( 'talenttrack/v1', '/threads/(?P<type>[a-z_]+)/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list' ],
                'permission_callback' => [ self::class, 'guardRead' ],
                'args'                => $args,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'markRead' ],
                'permission_callback' => [ self::class, 'guardRead' ],
                'args'                => $args,
            ],
        ] );

        register_rest_route( 'talenttrack/v1', '/threads/(?P<type>[a-z_]+)/(?P<id>\d+)/messages', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'post' ],
                'permission_callback' => [ self::class, 'guardPost' ],
                'args'                => $args,
            ],
        ] );

        register_rest_route( 'talenttrack/v1', '/threads/(?P<type>[a-z_]+)/(?P<id>\d+)/messages/(?P<msg_id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'edit' ],
                'permission_callback' => [ self::class, 'guardPost' ],
                'args'                => $args + [ 'msg_id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete' ],
                'permission_callback' => [ self::class, 'guardRead' ],
                'args'                => $args + [ 'msg_id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        register_rest_route( 'talenttrack/v1', '/threads/(?P<type>[a-z_]+)/(?P<id>\d+)/read', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'markRead' ],
                'permission_callback' => [ self::class, 'guardRead' ],
                'args'                => $args,
            ],
        ] );
    }

    public static function guardRead( WP_REST_Request $req ): bool {
        if ( ! is_user_logged_in() ) return false;
        $adapter = ThreadTypeRegistry::get( (string) $req->get_param( 'type' ) );
        if ( ! $adapter ) return false;
        return $adapter->canRead( get_current_user_id(), (int) $req->get_param( 'id' ) );
    }

    public static function guardPost( WP_REST_Request $req ): bool {
        if ( ! is_user_logged_in() ) return false;
        $adapter = ThreadTypeRegistry::get( (string) $req->get_param( 'type' ) );
        if ( ! $adapter ) return false;
        return $adapter->canPost( get_current_user_id(), (int) $req->get_param( 'id' ) );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function list( WP_REST_Request $req ) {
        $type = (string) $req->get_param( 'type' );
        $id   = (int) $req->get_param( 'id' );
        $user = get_current_user_id();
        $since_id = (int) ( $req->get_param( 'since' ) ?? 0 );

        $can_see_private = self::canSeePrivate( $type, $id, $user );

        $messages = ( new ThreadMessagesRepository() )->listForThread( $type, $id, $can_see_private, $since_id );
        $reads    = new ThreadReadsRepository();
        $unread_since = $reads->lastReadAt( $user, $type, $id );
        $reads->markRead( $user, $type, $id );

        $payload = [
            'messages'     => array_map( [ self::class, 'serialize' ], $messages ),
            'unread_since' => $unread_since,
            'edit_window_seconds' => ThreadMessagesRepository::EDIT_WINDOW_SECONDS,
            'current_user_id' => $user,
        ];
        return new WP_REST_Response( $payload, 200 );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function post( WP_REST_Request $req ) {
        $type = (string) $req->get_param( 'type' );
        $id   = (int) $req->get_param( 'id' );
        $body_param = (string) ( $req->get_param( 'body' ) ?? '' );
        $body = wp_kses_post( trim( $body_param ) );
        if ( $body === '' ) {
            return new WP_Error( 'tt_thread_empty', __( 'Message body required.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $visibility = (string) ( $req->get_param( 'visibility' ) ?? ThreadVisibility::PUBLIC_LEVEL );
        if ( ! ThreadVisibility::isValid( $visibility ) ) $visibility = ThreadVisibility::PUBLIC_LEVEL;

        // private_to_coach is gated to coaches + admins.
        if ( $visibility === ThreadVisibility::PRIVATE_COACH && ! self::canSeePrivate( $type, $id, get_current_user_id() ) ) {
            $visibility = ThreadVisibility::PUBLIC_LEVEL;
        }

        $repo = new ThreadMessagesRepository();
        $msg_id = $repo->insert( [
            'thread_type'    => $type,
            'thread_id'      => $id,
            'author_user_id' => get_current_user_id(),
            'body'           => $body,
            'visibility'     => $visibility,
            'is_system'      => 0,
        ] );
        if ( $msg_id === 0 ) {
            return new WP_Error( 'tt_thread_post_failed', __( 'Could not post message.', 'talenttrack' ), [ 'status' => 500 ] );
        }
        do_action( 'tt_thread_message_posted', $type, $id, $msg_id, get_current_user_id(), $visibility );

        $msg = $repo->find( $msg_id );
        return new WP_REST_Response( $msg ? self::serialize( $msg ) : [], 201 );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function edit( WP_REST_Request $req ) {
        $msg_id = (int) $req->get_param( 'msg_id' );
        $body   = wp_kses_post( trim( (string) ( $req->get_param( 'body' ) ?? '' ) ) );
        if ( $body === '' ) {
            return new WP_Error( 'tt_thread_empty', __( 'Message body required.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $visibility = $req->has_param( 'visibility' ) ? (string) $req->get_param( 'visibility' ) : null;

        $repo = new ThreadMessagesRepository();
        $ok = $repo->update( $msg_id, get_current_user_id(), $body, $visibility );
        if ( ! $ok ) {
            return new WP_Error( 'tt_thread_edit_denied', __( 'Edit window has expired or you are not the author.', 'talenttrack' ), [ 'status' => 403 ] );
        }
        do_action( 'tt_thread_message_edited', (string) $req->get_param( 'type' ), (int) $req->get_param( 'id' ), $msg_id, get_current_user_id() );

        $msg = $repo->find( $msg_id );
        return new WP_REST_Response( $msg ? self::serialize( $msg ) : [], 200 );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function delete( WP_REST_Request $req ) {
        $msg_id = (int) $req->get_param( 'msg_id' );
        $repo = new ThreadMessagesRepository();
        $msg  = $repo->find( $msg_id );
        if ( ! $msg ) {
            return new WP_Error( 'tt_thread_not_found', __( 'Message not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        $is_admin  = self::hasGlobalThreadAccess( get_current_user_id(), 'change' );
        $is_author = (int) $msg->author_user_id === get_current_user_id();
        if ( ! $is_author && ! $is_admin ) {
            return new WP_Error( 'tt_thread_delete_denied', __( 'You cannot delete this message.', 'talenttrack' ), [ 'status' => 403 ] );
        }
        $original_body = (string) $msg->body;
        $ok = $repo->softDelete( $msg_id, get_current_user_id() );
        if ( ! $ok ) {
            return new WP_Error( 'tt_thread_delete_failed', __( 'Could not delete message.', 'talenttrack' ), [ 'status' => 500 ] );
        }
        do_action( 'tt_thread_message_deleted', (string) $req->get_param( 'type' ), (int) $req->get_param( 'id' ), $msg_id, get_current_user_id(), $original_body );
        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    public static function markRead( WP_REST_Request $req ): WP_REST_Response {
        ( new ThreadReadsRepository() )->markRead(
            get_current_user_id(),
            (string) $req->get_param( 'type' ),
            (int) $req->get_param( 'id' )
        );
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    private static function canSeePrivate( string $type, int $thread_id, int $user_id ): bool {
        if ( self::hasGlobalThreadAccess( $user_id, 'read' ) ) return true;
        $adapter = ThreadTypeRegistry::get( $type );
        if ( ! $adapter ) return false;
        // Goal-specific: a coach who owns the player can see private; we
        // ask the adapter for that via canRead + a coach-cap probe.
        if ( ! $adapter->canRead( $user_id, $thread_id ) ) return false;
        return user_can( $user_id, 'tt_edit_evaluations' );
    }

    /**
     * #0080 Wave C3 — was `current_user_can('tt_view_settings')` /
     * `'tt_edit_evaluations' || tt_view_settings`. Replace the umbrella
     * "is admin?" probes with a precise check: does the user have
     * `thread_messages/<activity>/global` in the matrix? Falls back to
     * WP `manage_options` for matrix-dormant installs, then to the
     * v3.0 umbrella for back-compat.
     *
     * @param string $activity 'read' | 'change'
     */
    private static function hasGlobalThreadAccess( int $user_id, string $activity ): bool {
        if ( $user_id <= 0 ) return false;
        if ( class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            $matrix_activity = $activity === 'read' ? MatrixGate::READ : MatrixGate::CHANGE;
            if ( MatrixGate::can( $user_id, 'thread_messages', $matrix_activity, MatrixGate::SCOPE_GLOBAL ) ) {
                return true;
            }
        }
        if ( user_can( $user_id, 'manage_options' ) ) return true;
        return user_can( $user_id, 'tt_view_settings' );
    }

    /** @return array<string,mixed> */
    private static function serialize( object $msg ): array {
        $author = (int) $msg->author_user_id;
        $name = '';
        if ( $author > 0 ) {
            $u = get_user_by( 'id', $author );
            if ( $u instanceof \WP_User ) $name = (string) $u->display_name;
        }
        return [
            'id'              => (int) $msg->id,
            'thread_type'     => (string) $msg->thread_type,
            'thread_id'       => (int) $msg->thread_id,
            'author_user_id'  => $author,
            'author_name'     => $name,
            'body'            => (string) $msg->body,
            'visibility'      => (string) $msg->visibility,
            'is_system'       => (int) $msg->is_system === 1,
            'created_at'      => (string) $msg->created_at,
            'edited_at'       => $msg->edited_at,
            'deleted_at'      => $msg->deleted_at,
        ];
    }
}
