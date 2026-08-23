<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\Delivery\MediaDelivery;
use TT\Modules\Media\Ingest\MediaIngestService;
use TT\Modules\Media\Links\VideoLinkResolver;
use TT\Modules\Media\MediaAttachmentPolicy;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\MediaTagRoster;
use TT\Shared\Frontend\Components\MediaGallery;
use TT\Modules\Media\Storage\MediaStorage;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Retention\MediaRetentionService;

/**
 * MediaRestController — /wp-json/talenttrack/v1/media (#2592, epic #2589).
 *
 * The REST surface is the contract; the screens that follow are one
 * consumer of it (CLAUDE.md §4). Everything media can do is reachable
 * here before any screen exists.
 *
 *   GET    /media                       list for one record
 *   POST   /media                       upload a file, or record a video link
 *   GET    /media/{uuid}                one item, with its attachments
 *   PATCH  /media/{uuid}                title / description / capture date
 *   DELETE /media/{uuid}                archive, or ?hard=1 to erase
 *   POST   /media/{uuid}/links          attach to another record
 *   DELETE /media/{uuid}/links/{id}     detach
 *   GET    /media/{uuid}/file           the bytes
 *   GET    /media/{uuid}/thumb          the thumbnail
 *   GET    /players/{id}/media          convenience collection
 *
 * Items are addressed by **uuid**, never by the autoincrement id. The id
 * is an implementation detail of this database; the uuid is the identity
 * that survives a SaaS migration, and it also means a URL cannot be
 * walked to enumerate an academy's photographs.
 *
 * Two-stage authorization throughout. `permission_callback` asks the only
 * question answerable before routing — does this user have a media grant
 * anywhere — and the callback then asks `MediaVisibilityService` about
 * the actual record. The coarse gate never grants; it only refuses early.
 *
 * Responses use the standard `RestResponse` envelope, except the two
 * byte-serving routes, which stream and exit.
 */
final class MediaRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    // Gates

    private static function canRead(): bool {
        return MediaVisibilityService::hasReadAuthority( get_current_user_id() );
    }

    private static function canEdit(): bool {
        return MediaVisibilityService::hasEditAuthority( get_current_user_id() );
    }

    private static function canUpload(): bool {
        return MediaVisibilityService::hasUploadAuthority( get_current_user_id() );
    }

    /**
     * #2666 — retention review authority.
     *
     * Deliberately **global** `create_delete`, not the any-scope check the
     * other write routes use. A coach with team-scoped delete may curate
     * their own squad's media; deciding what leaves the academy's records
     * permanently, across every player who has ever left, is a different
     * act and belongs to an academy admin.
     */
    private static function canAdminRetention(): bool {
        return \TT\Modules\Authorization\MatrixGate::can(
            get_current_user_id(),
            MediaVisibilityService::ENTITY,
            \TT\Modules\Authorization\MatrixGate::CREATE_DELETE,
            \TT\Modules\Authorization\MatrixGate::SCOPE_GLOBAL
        );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/media', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_media' ],
                'permission_callback' => static fn() => self::canRead(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_media' ],
                'permission_callback' => static fn() => self::canUpload(),
            ],
        ] );

        register_rest_route( self::NS, '/media/(?P<uuid>[a-f0-9-]{36})', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_media' ],
                'permission_callback' => static fn() => self::canRead(),
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_media' ],
                'permission_callback' => static fn() => self::canEdit(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_media' ],
                'permission_callback' => static fn() => self::canUpload(),
            ],
        ] );

        register_rest_route( self::NS, '/media/(?P<uuid>[a-f0-9-]{36})/links', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'add_link' ],
                'permission_callback' => static fn() => self::canUpload(),
            ],
        ] );

        register_rest_route( self::NS, '/media/(?P<uuid>[a-f0-9-]{36})/links/(?P<link_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'remove_link' ],
                'permission_callback' => static fn() => self::canUpload(),
            ],
        ] );

        register_rest_route( self::NS, '/media/(?P<uuid>[a-f0-9-]{36})/file', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'serve_file' ],
                'permission_callback' => static fn() => self::canRead(),
            ],
        ] );

        register_rest_route( self::NS, '/media/(?P<uuid>[a-f0-9-]{36})/thumb', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'serve_thumb' ],
                'permission_callback' => static fn() => self::canRead(),
            ],
        ] );

        // #2666 — retention review. Global `create_delete` authority, not
        // per-record: this surface exists to remove other people's media
        // wholesale, which is an academy-admin act rather than a coach's.
        register_rest_route( self::NS, '/media/retention', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'retention_queue' ],
                'permission_callback' => static fn() => self::canAdminRetention(),
            ],
        ] );

        register_rest_route( self::NS, '/media/retention/(?P<link_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'retention_remove' ],
                'permission_callback' => static fn() => self::canAdminRetention(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'retention_decide' ],
                'permission_callback' => static fn() => self::canAdminRetention(),
            ],
        ] );

        register_rest_route( self::NS, '/players/(?P<id>\d+)/media', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_for_player' ],
                'permission_callback' => static fn() => self::canRead(),
            ],
        ] );
    }

    // Collections

    public static function list_media( \WP_REST_Request $request ): \WP_REST_Response {
        $entity_type = (string) $request->get_param( 'entity_type' );
        $entity_id   = (int) $request->get_param( 'entity_id' );

        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) {
            return RestResponse::error(
                'bad_target',
                __( 'Provide a valid entity_type and entity_id.', 'talenttrack' ),
                400
            );
        }

        return self::collection( $entity_type, $entity_id, $request );
    }

    public static function list_for_player( \WP_REST_Request $request ): \WP_REST_Response {
        return self::collection( MediaEntityType::PLAYER, (int) $request->get_param( 'id' ), $request );
    }

    private static function collection( string $entity_type, int $entity_id, \WP_REST_Request $request ): \WP_REST_Response {
        // #2745 — the grid pages rather than rendering a season at once.
        // `limit` absent means every row, which keeps every existing
        // consumer of this endpoint behaving as it did.
        $limit  = max( 0, (int) $request->get_param( 'limit' ) );
        $offset = max( 0, (int) $request->get_param( 'offset' ) );

        // One more than asked for, purely to answer "is there another
        // page?" without a second COUNT(*) over the same join.
        $items = ( new MediaRepository() )->listForEntity(
            $entity_type,
            $entity_id,
            (bool) $request->get_param( 'include_archived' ),
            $limit > 0 ? $limit + 1 : 0,
            $offset
        );

        $has_more = $limit > 0 && count( $items ) > $limit;
        if ( $has_more ) $items = array_slice( $items, 0, $limit );

        // Rows consumed, not rows shown. `filterVisible()` below can drop
        // items, and an offset advanced by the visible count would skip
        // whatever it removed on the next page.
        $next_offset = $offset + count( $items );

        $kind = (string) $request->get_param( 'kind' );
        if ( $kind !== '' && MediaKind::isValid( $kind ) ) {
            $items = array_values( array_filter( $items, static function ( $m ) use ( $kind ) {
                return (string) $m->kind === $kind;
            } ) );
        }

        // Filter before shaping. The list query is scoped to one record,
        // but a user may reach that record without being entitled to every
        // item hanging off it.
        $visible = ( new MediaVisibilityService() )->filterVisible( get_current_user_id(), $items );

        $shaped = array_map( [ __CLASS__, 'shape' ], $visible );

        // Opt-in, so a data consumer is not made to carry markup it will
        // never render. The gallery asks for it because assembling a tile
        // client-side would need the nonce dance #2742 exists to avoid.
        if ( (bool) $request->get_param( 'with_tiles' ) ) {
            $roster   = MediaTagRoster::for( $entity_type, $entity_id );
            $can_edit = self::canEdit();

            foreach ( $visible as $i => $item ) {
                $shaped[ $i ] = self::withTile( $shaped[ $i ], $item, $entity_type, $entity_id, $roster, $can_edit );
            }
        }

        return RestResponse::success( [
            'items'       => $shaped,
            'total'       => count( $shaped ),
            'has_more'    => $has_more,
            'next_offset' => $next_offset,
        ] );
    }

    // Items

    public static function get_media( \WP_REST_Request $request ): \WP_REST_Response {
        $media = self::findVisible( $request );
        if ( $media instanceof \WP_REST_Response ) return $media;

        return RestResponse::success( self::shape( $media, true ) );
    }

    public static function create_media( \WP_REST_Request $request ): \WP_REST_Response {
        $entity_type = (string) $request->get_param( 'entity_type' );
        $entity_id   = (int) $request->get_param( 'entity_id' );

        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) {
            return RestResponse::error(
                'bad_target',
                __( 'Provide a valid entity_type and entity_id to attach the media to.', 'talenttrack' ),
                400
            );
        }

        $user = get_current_user_id();
        if ( ! ( new MediaVisibilityService() )->canAttachTo( $user, $entity_type, $entity_id ) ) {
            return self::forbidden();
        }

        $external_url = trim( (string) $request->get_param( 'external_url' ) );

        // Check the target's policy before ingest, not after. The uploader
        // narrows its `accept` from the same policy, but `accept` is a hint
        // to a file picker and this is the check that holds — and ingest
        // writes the bytes to the store as it runs, so refusing on the far
        // side of it would leave an orphaned object behind every rejected
        // upload.
        $intended = $external_url !== ''
            ? MediaKind::VIDEO_LINK
            : self::intendedKind( $request );

        if ( $intended !== '' && ! MediaAttachmentPolicy::allows( $entity_type, $intended ) ) {
            return RestResponse::error(
                'kind_not_allowed',
                MediaAttachmentPolicy::refusalMessage( $entity_type ),
                415
            );
        }

        $payload = $external_url !== ''
            ? self::linkPayload( $external_url, $request )
            : self::uploadPayload( $request );

        if ( $payload instanceof \WP_REST_Response ) return $payload;

        // Backstop. The pre-check sniffs the temp file with `sniff()` while
        // ingest re-detects with its own fallback chain, so the two can in
        // principle disagree. If they do, the stored object is removed
        // rather than left unreferenced.
        $kind = (string) ( $payload['kind'] ?? '' );
        if ( ! MediaAttachmentPolicy::allows( $entity_type, $kind ) ) {
            self::discardStored( $payload );
            return RestResponse::error(
                'kind_not_allowed',
                MediaAttachmentPolicy::refusalMessage( $entity_type ),
                415
            );
        }

        $repo     = new MediaRepository();
        $media_id = $repo->insert( $payload );
        if ( $media_id <= 0 ) {
            return RestResponse::error( 'create_failed', __( 'The media item could not be saved.', 'talenttrack' ), 500 );
        }

        ( new MediaLinksRepository() )->link(
            $media_id,
            $entity_type,
            $entity_id,
            (bool) $request->get_param( 'is_primary' )
        );

        $created = $repo->find( $media_id );

        return RestResponse::success(
            self::withTile( self::shape( $created, true ), $created, $entity_type, $entity_id ),
            201
        );
    }

    /**
     * Attach the rendered gallery tile to a create response.
     *
     * #2742 — the uploader announced a new item and the grid ignored it, so
     * nothing appeared until the page was reloaded. The tile is rendered
     * here rather than assembled in JavaScript: the payload's `_links`
     * carry no nonce by design, so a client-built `<img src>` would be
     * answered 401 and re-create #2715 through a different door.
     *
     * Rendering is best-effort. A tile that cannot be produced must never
     * turn a successful upload into a failed one — the file is already
     * stored and linked by this point.
     *
     * @param array<string, mixed> $shaped
     * @return array<string, mixed>
     */
    private static function withTile(
        array $shaped,
        ?object $media,
        string $entity_type,
        int $entity_id,
        ?array $roster = null,
        ?bool $can_edit = null
    ): array {
        if ( ! $media ) return $shaped;

        // Resolved once by a list caller and handed in — working it out per
        // item would put a roster query behind every tile on the page.
        if ( $roster === null )   $roster   = MediaTagRoster::for( $entity_type, $entity_id );
        if ( $can_edit === null ) $can_edit = self::canEdit();

        try {
            $shaped['tile_html'] = MediaGallery::tileHtml( $media, $can_edit, $roster );
        } catch ( \Throwable $e ) {
            // The client falls back to its own minimal row.
            $shaped['tile_html'] = '';
        }

        return $shaped;
    }

    public static function update_media( \WP_REST_Request $request ): \WP_REST_Response {
        $media = self::findVisible( $request, 'canEdit' );
        if ( $media instanceof \WP_REST_Response ) return $media;

        $fields = [];
        foreach ( [ 'title', 'description' ] as $key ) {
            if ( $request->get_param( $key ) !== null ) {
                $fields[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
            }
        }

        if ( $request->get_param( 'captured_at' ) !== null ) {
            $raw = trim( (string) $request->get_param( 'captured_at' ) );
            if ( $raw === '' ) {
                $fields['captured_at'] = null;
            } else {
                $ts = strtotime( $raw );
                if ( $ts === false ) {
                    return RestResponse::error(
                        'bad_captured_at',
                        __( 'The capture date could not be understood.', 'talenttrack' ),
                        400
                    );
                }
                $fields['captured_at'] = gmdate( 'Y-m-d H:i:s', $ts );
            }
        }

        if ( $fields === [] ) {
            return RestResponse::error( 'nothing_to_update', __( 'No editable fields were provided.', 'talenttrack' ), 400 );
        }

        $repo = new MediaRepository();
        $repo->update( (int) $media->id, $fields );

        return RestResponse::success( self::shape( $repo->find( (int) $media->id ), true ) );
    }

    /**
     * Archive by default; `?hard=1` erases the row and its bytes.
     *
     * The split matters for a right-to-erasure request: archiving keeps
     * the file, so an academy acting on one has to be able to ask for the
     * bytes to actually go.
     */
    public static function delete_media( \WP_REST_Request $request ): \WP_REST_Response {
        $media = self::findVisible( $request, 'canDelete' );
        if ( $media instanceof \WP_REST_Response ) return $media;

        $repo = new MediaRepository();
        $hard = (bool) $request->get_param( 'hard' );

        $ok = $hard ? $repo->deleteWithBlobs( (int) $media->id ) : $repo->archive( (int) $media->id );
        if ( ! $ok ) {
            return RestResponse::error( 'delete_failed', __( 'The media item could not be removed.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [ 'deleted' => true, 'hard' => $hard ] );
    }

    // Links

    public static function add_link( \WP_REST_Request $request ): \WP_REST_Response {
        $media = self::findVisible( $request, 'canDelete' );
        if ( $media instanceof \WP_REST_Response ) return $media;

        $entity_type = (string) $request->get_param( 'entity_type' );
        $entity_id   = (int) $request->get_param( 'entity_id' );

        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) {
            return RestResponse::error( 'bad_target', __( 'Provide a valid entity_type and entity_id.', 'talenttrack' ), 400 );
        }

        // Attaching is a write to the *destination* record, so it is the
        // destination that must be authorised — otherwise reach over one
        // record would let a user publish into another.
        if ( ! ( new MediaVisibilityService() )->canAttachTo( get_current_user_id(), $entity_type, $entity_id ) ) {
            return self::forbidden();
        }

        $link_id = ( new MediaLinksRepository() )->link(
            (int) $media->id,
            $entity_type,
            $entity_id,
            (bool) $request->get_param( 'is_primary' )
        );

        if ( $link_id <= 0 ) {
            return RestResponse::error( 'link_failed', __( 'The media item could not be attached.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( self::shape( ( new MediaRepository() )->find( (int) $media->id ), true ), 201 );
    }

    public static function remove_link( \WP_REST_Request $request ): \WP_REST_Response {
        $media = self::findVisible( $request, 'canDelete' );
        if ( $media instanceof \WP_REST_Response ) return $media;

        $links = new MediaLinksRepository();
        $link  = $links->find( (int) $request->get_param( 'link_id' ) );

        if ( ! $link || (int) $link->media_id !== (int) $media->id ) {
            return RestResponse::error( 'link_not_found', __( 'That attachment was not found.', 'talenttrack' ), 404 );
        }

        $links->unlink( (int) $link->id );

        // The item is gone when that was its last attachment, so there may
        // be nothing left to return.
        $remaining = ( new MediaRepository() )->find( (int) $media->id );

        return RestResponse::success( [
            'detached'      => true,
            'media_deleted' => $remaining === null,
        ] );
    }

    // Bytes

    public static function serve_file( \WP_REST_Request $request ) {
        return self::serve( $request, MediaDelivery::VARIANT_FILE );
    }

    public static function serve_thumb( \WP_REST_Request $request ) {
        return self::serve( $request, MediaDelivery::VARIANT_THUMB );
    }

    /**
     * The access boundary. On nginx the directory guard does nothing, so
     * this check is the only one — it runs before a single byte moves.
     */
    private static function serve( \WP_REST_Request $request, string $variant ) {
        $media = self::findVisible( $request );
        if ( $media instanceof \WP_REST_Response ) return $media;

        $plan = MediaDelivery::plan( $media, $variant, $request->get_header( 'range' ) );

        if ( is_wp_error( $plan ) ) {
            $status = (int) ( $plan->get_error_data()['status'] ?? 400 );
            $response = RestResponse::error( $plan->get_error_code(), $plan->get_error_message(), $status );
            if ( $status === 416 ) {
                $response->header( 'Content-Range', 'bytes */' . (int) ( $plan->get_error_data()['total'] ?? 0 ) );
            }
            return $response;
        }

        // Ends the request — the REST server would otherwise append a JSON
        // envelope to the bytes we just wrote.
        MediaDelivery::stream( $plan );
    }

    // Retention (#2666)

    public static function retention_queue( \WP_REST_Request $request ): \WP_REST_Response {
        $service = new MediaRetentionService();

        return RestResponse::success( [
            'enabled' => MediaRetentionService::isEnabled(),
            'years'   => MediaRetentionService::years(),
            'items'   => $service->candidates( (int) ( $request->get_param( 'limit' ) ?: 200 ) ),
            'held'    => $service->held(),
        ] );
    }

    /**
     * Act on one expired attachment: remove it, or keep it with a reason.
     *
     * `POST` with `decision=keep` holds it; `decision=release` puts a held
     * one back in the queue. Removal is a `DELETE` on the same route
     * because it is the destructive verb and should read as one.
     */
    public static function retention_decide( \WP_REST_Request $request ): \WP_REST_Response {
        $link_id  = (int) $request->get_param( 'link_id' );
        $decision = (string) $request->get_param( 'decision' );
        $service  = new MediaRetentionService();

        if ( $decision === 'release' ) {
            return $service->releaseHold( $link_id )
                ? RestResponse::success( [ 'held' => false ] )
                : RestResponse::error( 'release_failed', __( 'That could not be put back in the queue.', 'talenttrack' ), 500 );
        }

        if ( $decision !== 'keep' ) {
            return RestResponse::error( 'bad_decision', __( 'Provide decision=keep or decision=release.', 'talenttrack' ), 400 );
        }

        $reason = trim( (string) $request->get_param( 'reason' ) );
        if ( $reason === '' ) {
            return RestResponse::error(
                'reason_required',
                __( 'Give a reason for keeping this. Without one the exception cannot be audited.', 'talenttrack' ),
                400
            );
        }

        return $service->hold( $link_id, sanitize_text_field( $reason ) )
            ? RestResponse::success( [ 'held' => true ] )
            : RestResponse::error( 'hold_failed', __( 'That could not be saved.', 'talenttrack' ), 500 );
    }

    public static function retention_remove( \WP_REST_Request $request ): \WP_REST_Response {
        $result = ( new MediaRetentionService() )->removeAttachment( (int) $request->get_param( 'link_id' ) );

        if ( ! $result['removed'] ) {
            return RestResponse::error( 'not_found', __( 'That attachment was not found.', 'talenttrack' ), 404 );
        }

        return RestResponse::success( $result );
    }

    // Payload builders

    /** @return array<string, mixed>|\WP_REST_Response */
    private static function linkPayload( string $external_url, \WP_REST_Request $request ) {
        if ( ! VideoLinkResolver::isAcceptable( $external_url ) ) {
            return RestResponse::error(
                'bad_url',
                __( 'That does not look like a video address. Paste the web address of the video as it appears in your browser.', 'talenttrack' ),
                400
            );
        }

        $meta  = VideoLinkResolver::resolve( $external_url );
        $title = trim( (string) $request->get_param( 'title' ) );
        if ( $title === '' ) $title = $meta['title'];

        $thumb_key = null;
        if ( $meta['thumbnail_url'] !== '' ) {
            $thumb_key = VideoLinkResolver::fetchThumbnail( $meta['thumbnail_url'] );
        }

        return [
            'kind'          => MediaKind::VIDEO_LINK,
            'title'         => sanitize_text_field( $title ),
            'description'   => sanitize_text_field( (string) $request->get_param( 'description' ) ),
            'provider'      => $meta['provider'],
            'external_url'  => esc_url_raw( $external_url ),
            'thumbnail_key' => $thumb_key,
            'captured_at'   => self::capturedAtParam( $request ),
        ];
    }

    /** @return array<string, mixed>|\WP_REST_Response */
    private static function uploadPayload( \WP_REST_Request $request ) {
        $files = $request->get_file_params();
        $file  = $files['file'] ?? null;

        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
            return RestResponse::error(
                'no_file',
                __( 'No file was received. Attach a file, or provide external_url for a video hosted elsewhere.', 'talenttrack' ),
                400
            );
        }

        if ( ! empty( $file['error'] ) ) {
            return RestResponse::error( 'upload_error', self::uploadErrorMessage( (int) $file['error'] ), 400 );
        }

        $result = ( new MediaIngestService() )->ingest( (string) $file['tmp_name'], [
            'title'       => sanitize_text_field( (string) $request->get_param( 'title' ) ),
            'description' => sanitize_text_field( (string) $request->get_param( 'description' ) ),
            'captured_at' => self::capturedAtParam( $request ) ?? '',
        ] );

        if ( ! $result->isOk() ) {
            $status = $result->code() === 'too_large' ? 413 : 400;
            return RestResponse::error( $result->code(), $result->message(), $status );
        }

        $payload = $result->payload();

        // The browser can hand us a poster frame it grabbed off the video
        // element — which is what keeps a transcoder off the server.
        if ( ( $payload['kind'] ?? '' ) === MediaKind::VIDEO && isset( $files['poster']['tmp_name'] ) ) {
            $poster_mime = self::sniff( (string) $files['poster']['tmp_name'] );
            $poster_key  = ( new MediaIngestService() )->storeThumbnail( (string) $files['poster']['tmp_name'], $poster_mime );
            if ( $poster_key !== null ) $payload['thumbnail_key'] = $poster_key;
        }

        if ( $request->get_param( 'duration_seconds' ) !== null ) {
            $payload['duration_seconds'] = max( 0, (int) $request->get_param( 'duration_seconds' ) );
        }

        return $payload;
    }

    // Helpers

    /**
     * Resolve the addressed item and check it, or return the response to
     * send instead.
     *
     * A missing item and a forbidden item both answer 404. Distinguishing
     * them would confirm that a given uuid exists in this academy, which
     * is exactly what someone probing for other people's photographs wants
     * to learn.
     *
     * @return object|\WP_REST_Response
     */
    private static function findVisible( \WP_REST_Request $request, string $method = 'canView' ) {
        $media = ( new MediaRepository() )->findByUuid( (string) $request->get_param( 'uuid' ) );
        if ( ! $media ) return self::notFound();

        $svc  = new MediaVisibilityService();
        $user = get_current_user_id();

        if ( ! $svc->canView( $user, $media ) ) return self::notFound();

        // Read is established; a write refusal can be honest about itself.
        if ( $method !== 'canView' && ! $svc->{$method}( $user, $media ) ) return self::forbidden();

        return $media;
    }

    private static function notFound(): \WP_REST_Response {
        return RestResponse::error( 'not_found', __( 'Media item not found.', 'talenttrack' ), 404 );
    }

    private static function forbidden(): \WP_REST_Response {
        return RestResponse::error( 'forbidden', __( 'You do not have permission to do that.', 'talenttrack' ), 403 );
    }

    private static function capturedAtParam( \WP_REST_Request $request ): ?string {
        $raw = trim( (string) $request->get_param( 'captured_at' ) );
        if ( $raw === '' ) return null;
        $ts = strtotime( $raw );
        return $ts === false ? null : gmdate( 'Y-m-d H:i:s', $ts );
    }

    private static function sniff( string $path ): string {
        if ( ! function_exists( 'finfo_open' ) ) return '';
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        if ( $finfo === false ) return '';
        $mime = (string) finfo_file( $finfo, $path );
        finfo_close( $finfo );
        return strtolower( $mime );
    }

    /**
     * The kind an uploaded file will land as, known before ingest runs.
     *
     * Empty when there is nothing to sniff — no file, or no `finfo` on the
     * host. Both cases fall through to `uploadPayload()`, which raises the
     * proper error for a missing file and whose own detection has a
     * fallback for a host without `finfo`. Returning empty here means "no
     * opinion", never "allowed".
     */
    private static function intendedKind( \WP_REST_Request $request ): string {
        $files = $request->get_file_params();
        $tmp   = (string) ( $files['file']['tmp_name'] ?? '' );
        if ( $tmp === '' || ! is_file( $tmp ) ) return '';

        $mime = self::sniff( $tmp );
        return $mime === '' ? '' : MediaKind::forMime( $mime );
    }

    /**
     * Drop bytes ingest already wrote for a payload that is not going to be
     * inserted.
     *
     * @param array<string, mixed> $payload
     */
    private static function discardStored( array $payload ): void {
        $key = (string) ( $payload['storage_key'] ?? '' );
        if ( $key === '' ) return;

        $storage = MediaStorage::default();
        $storage->delete( $key );

        $thumb = (string) ( $payload['thumbnail_key'] ?? '' );
        if ( $thumb !== '' ) $storage->delete( $thumb );
    }

    private static function uploadErrorMessage( int $code ): string {
        if ( $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ) {
            return sprintf(
                /* translators: %s is the server's maximum upload size. */
                __( 'That file is larger than this server accepts (%s).', 'talenttrack' ),
                size_format( MediaIngestService::maxUploadBytes() )
            );
        }
        if ( $code === UPLOAD_ERR_PARTIAL ) {
            return __( 'The upload did not finish. Check your connection and try again.', 'talenttrack' );
        }
        return __( 'The file could not be uploaded.', 'talenttrack' );
    }

    /**
     * Public shape of a media row.
     *
     * Carries no filesystem path and no uploads URL (CLAUDE.md §4) — only
     * REST URLs, so a consumer never learns where the bytes actually live
     * and an object-storage swap changes nothing here.
     *
     * @return array<string, mixed>
     */
    public static function shape( ?object $media, bool $with_links = false ): array {
        if ( ! $media ) return [];

        $uuid = (string) $media->uuid;
        $kind = (string) $media->kind;
        $base = rest_url( self::NS . '/media/' . $uuid );

        $out = [
            'uuid'        => $uuid,
            'kind'        => $kind,
            'title'       => (string) $media->title,
            'description' => $media->description !== null ? (string) $media->description : '',
            'captured_at' => $media->captured_at !== null ? (string) $media->captured_at : null,
            'created_at'  => (string) $media->created_at,
            'archived'    => $media->archived_at !== null,
            'uploaded_by' => (int) $media->uploaded_by,
            '_links'      => [],
        ];

        if ( MediaKind::isStored( $kind ) ) {
            $out['mime_type']        = (string) $media->mime_type;
            $out['file_size']        = (int) $media->file_size;
            $out['width']            = $media->width !== null ? (int) $media->width : null;
            $out['height']           = $media->height !== null ? (int) $media->height : null;
            $out['duration_seconds'] = $media->duration_seconds !== null ? (int) $media->duration_seconds : null;
            $out['_links']['file']   = $base . '/file';
        } else {
            $out['provider']     = (string) $media->provider;
            $out['external_url'] = (string) $media->external_url;
        }

        if ( ! empty( $media->thumbnail_key ) ) {
            $out['_links']['thumb'] = $base . '/thumb';
        }

        if ( $with_links ) {
            $out['links'] = array_map( static function ( $link ) {
                return [
                    'id'          => (int) $link->id,
                    'entity_type' => (string) $link->entity_type,
                    'entity_id'   => (int) $link->entity_id,
                    'is_primary'  => (bool) $link->is_primary,
                ];
            }, ( new MediaLinksRepository() )->listForMedia( (int) $media->id ) );
        }

        return $out;
    }
}
