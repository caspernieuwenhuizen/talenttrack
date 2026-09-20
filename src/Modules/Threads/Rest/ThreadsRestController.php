<?php
namespace TT\Modules\Threads\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\AuthorNameResolver;
use TT\Modules\Threads\Domain\ThreadAccess;
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
        // Runs on rest_api_init, after every module's boot() has
        // registered its adapter, so the enum covers each known type.
        $types = ThreadTypeRegistry::known();
        $args = [
            'type' => [
                'type'              => 'string',
                'enum'              => $types,
                'description'       => 'The record the thread belongs to. One of: ' . implode( ', ', $types ) . '.',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => [ self::class, 'validateType' ],
            ],
            'id' => [
                'type'              => 'integer',
                'description'       => 'The id of the record the thread belongs to (the goal, player or blueprint id).',
                'sanitize_callback' => 'absint',
                'validate_callback' => static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0,
            ],
        ];
        $msg_id_arg = [
            'msg_id' => [
                'type'              => 'integer',
                'description'       => 'The message id.',
                'sanitize_callback' => 'absint',
            ],
        ];
        $body_arg = [
            'body' => [
                'type'        => 'string',
                'required'    => true,
                'description' => 'The message text. Basic HTML is kept; an empty message is refused.',
            ],
        ];
        $visibility_arg = [
            'visibility' => [
                'type'        => 'string',
                'enum'        => ThreadVisibility::all(),
                'description' => 'Who sees the message. "public" (the default) reaches everyone who can read the thread; "private_to_coach" only coaches and admins. A caller who cannot see private messages posts public.',
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
                'args'                => $args + $body_arg + $visibility_arg,
            ],
        ] );

        register_rest_route( 'talenttrack/v1', '/threads/(?P<type>[a-z_]+)/(?P<id>\d+)/messages/(?P<msg_id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'edit' ],
                'permission_callback' => [ self::class, 'guardPost' ],
                'args'                => $args + $msg_id_arg + $body_arg + $visibility_arg,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete' ],
                'permission_callback' => [ self::class, 'guardRead' ],
                'args'                => $args + $msg_id_arg,
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

    /**
     * #3674 — an unknown type names the valid ones. Returning plain
     * `false` left the caller with WordPress's bare "Invalid parameter"
     * and no way to tell which types exist. Reads the live registry, so
     * it stays right even for a type registered after the routes.
     *
     * @param mixed $value
     * @return true|WP_Error
     */
    public static function validateType( $value ) {
        if ( is_string( $value ) && $value !== '' && ThreadTypeRegistry::get( $value ) !== null ) {
            return true;
        }
        $given = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
        return new WP_Error(
            'unknown_thread_type',
            sprintf(
                /* translators: 1: the thread type the caller sent, 2: comma-separated list of valid thread types */
                __( 'Unknown thread type "%1$s". Valid types: %2$s.', 'talenttrack' ),
                $given,
                implode( ', ', ThreadTypeRegistry::known() )
            ),
            [ 'status' => 400, 'valid_types' => ThreadTypeRegistry::known() ]
        );
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

        // #3672 — one name lookup for the whole thread, not one per
        // message. Resolved through the linked player or person, so a
        // player whose account carries somebody else's display_name
        // still appears under their own name.
        $author_ids = [];
        foreach ( $messages as $message ) {
            $row = (array) $message;
            $author_ids[] = (int) ( $row['author_user_id'] ?? 0 );
        }
        $names = AuthorNameResolver::namesFor( $author_ids );

        $serialized = [];
        foreach ( $messages as $message ) {
            $serialized[] = self::serialize( $message, $names );
        }

        $payload = [
            'messages'     => $serialized,
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
        if ( ! ThreadVisibility::isValid( $visibility ) ) {
            return self::unknownVisibility( $visibility );
        }

        // #3858 — refuse, never rewrite. This used to quietly downgrade a
        // staff-only note to public and answer 201, so an author who
        // believed they had kept a note within the staff had in fact
        // published it to everyone who reads the thread, the child's
        // guardian included. A refusal loses nothing: the client still
        // holds the text.
        if ( $visibility === ThreadVisibility::PRIVATE_COACH
            && ! ThreadAccess::canWritePrivate( $type, $id, get_current_user_id() )
        ) {
            return self::staffOnlyDenied();
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
        if ( $visibility !== null && ! ThreadVisibility::isValid( $visibility ) ) {
            return self::unknownVisibility( $visibility );
        }

        $repo = new ThreadMessagesRepository();

        // #3858 — the edit path had no entitlement check of any kind, and
        // failed the opposite way to the post path: an author who could
        // not create a staff-only note could post it public and edit it to
        // `private_to_coach`, hiding a note from a guardian with no grant
        // behind it. Both directions are the same question, so both are
        // asked here — and again in the repository, which is the gate a
        // second caller would meet.
        $existing = $repo->find( $msg_id );
        // Read through an array cast: `find()` hands back the row `$wpdb`
        // built, whose shape the type system does not know.
        $row = $existing !== null ? (array) $existing : [];
        if ( $row !== []
            && $visibility !== null
            && ThreadMessagesRepository::visibilityChangeNeedsStaffOnlyRight( (string) ( $row['visibility'] ?? '' ), $visibility )
            && ! ThreadAccess::canWritePrivate(
                (string) ( $row['thread_type'] ?? '' ),
                (int) ( $row['thread_id'] ?? 0 ),
                get_current_user_id()
            )
        ) {
            return self::staffOnlyDenied();
        }

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

    /**
     * #3302 — both gates moved to {@see ThreadAccess} once the PDP
     * evidence packet became a second reader of a player's notes. The
     * controller keeps the thin wrappers so the call sites below read the
     * same as they always did.
     */
    private static function canSeePrivate( string $type, int $thread_id, int $user_id ): bool {
        return ThreadAccess::canSeePrivate( $type, $thread_id, $user_id );
    }

    /**
     * #3858 — the refusal an author meets when they mark a note staff-only
     * without the right. It names the right, because "forbidden" leaves
     * somebody who is entitled to ask for it with nothing to ask for.
     */
    private static function staffOnlyDenied(): WP_Error {
        return new WP_Error(
            'tt_thread_staff_only_denied',
            __( 'You do not hold the staff-only notes right, so this note cannot be kept within the staff. Ask an academy admin for it, or post the note where everyone on the conversation can read it.', 'talenttrack' ),
            [ 'status' => 403, 'entity' => ThreadAccess::STAFF_ONLY_ENTITY ]
        );
    }

    /**
     * #3858 — an unrecognised visibility used to be silently rewritten to
     * `public`. The route's enum already refuses one, so this is the
     * belt-and-braces answer for a caller that reaches the handler another
     * way; either way nothing is stored under a visibility nobody asked
     * for.
     */
    private static function unknownVisibility( string $given ): WP_Error {
        return new WP_Error(
            'tt_thread_visibility_unknown',
            sprintf(
                /* translators: 1: the visibility the caller sent, 2: comma-separated list of valid values */
                __( 'Unknown visibility "%1$s". Valid values: %2$s.', 'talenttrack' ),
                sanitize_key( $given ),
                implode( ', ', ThreadVisibility::all() )
            ),
            [ 'status' => 400 ]
        );
    }

    /**
     * @param string $activity 'read' | 'change'
     */
    private static function hasGlobalThreadAccess( int $user_id, string $activity ): bool {
        return ThreadAccess::hasGlobalAccess( $user_id, $activity );
    }

    /**
     * @param  array<int,string>|null $names Resolved author names, keyed by
     *                                       wp_user_id. Null resolves this one
     *                                       message's author on the spot — the
     *                                       single-message POST/PUT paths.
     * @return array<string,mixed>
     */
    private static function serialize( object $msg, ?array $names = null ): array {
        $author = (int) $msg->author_user_id;
        if ( $names === null ) {
            $names = AuthorNameResolver::namesFor( [ $author ] );
        }
        $name = $author > 0 ? (string) ( $names[ $author ] ?? '' ) : '';
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
