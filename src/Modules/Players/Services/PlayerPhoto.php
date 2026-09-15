<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\Delivery\MediaDelivery;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Storage\MediaStorage;

/**
 * Where a player's face comes from.
 *
 * #3399 — every player photograph was a public, unauthenticated
 * `wp-content/uploads/` URL. `tt_players.photo_url` held a bare string,
 * filled by the WordPress media picker with `.toJSON().url`, rendered
 * verbatim on a dozen surfaces. So a photograph of a minor sat at a
 * guessable path, served by Apache with no capability check, no nonce and
 * no expiry, reachable by anyone with the link whether or not they had an
 * account.
 *
 * The Media module (#2589) was built on the opposite premise — a private
 * store, delivery behind a `permission_callback`, visibility filtered per
 * viewer — and the avatar predated it and never joined. The result was an
 * academy whose media library was locked and whose player mugshots were
 * not.
 *
 * Photos now live in that store, referenced by `tt_players.photo_media_id`.
 *
 * TWO ACCESSORS, BECAUSE THERE ARE TWO KINDS OF CONSUMER
 *
 * {@see url()} returns a `MediaDelivery` URL, which carries a nonce and
 * still requires the viewer's session cookie — a link leaked through a
 * referrer header or an access log is answered with 403 rather than a
 * child's photograph. That is right for anything rendered into a page.
 *
 * {@see dataUri()} reads the bytes and inlines them. Print and PDF output
 * is handed to a renderer that may have no session at all, so a
 * session-bound URL would come back as a broken image there. Inlining also
 * means the exported file carries its own picture rather than a link that
 * stops working — which is what you want in a document somebody saves.
 *
 * Both fall back to the legacy `photo_url` column, so an install renders
 * correctly before migration 0261 has run and during it.
 */
final class PlayerPhoto {

    /**
     * Gated URL for a player's photo, or '' when there is none.
     *
     * @param object|null $player Row from `tt_players`.
     */
    public static function url( ?object $player ): string {
        if ( $player === null ) return '';

        $media = self::media( $player );
        if ( $media !== null ) {
            return MediaDelivery::url( (string) $media->uuid );
        }

        return self::legacyUrl( $player );
    }

    /**
     * The photo as a `data:` URI for print, PDF and export, or '' when
     * there is none.
     *
     * Reads through the storage adapter rather than fetching the URL: the
     * renderer may be server-side with no session, and a document that
     * embeds its picture keeps it.
     *
     * @param object|null $player Row from `tt_players`.
     */
    public static function dataUri( ?object $player ): string {
        if ( $player === null ) return '';

        $media = self::media( $player );
        if ( $media === null ) {
            // Legacy rows only. A public URL is what they already were.
            return self::legacyUrl( $player );
        }

        $adapter = (string) ( $media->storage_adapter ?? '' );
        $key     = (string) ( $media->storage_key ?? '' );
        if ( $key === '' ) return '';

        $stream = MediaStorage::for( $adapter )->readStream( $key );
        if ( ! is_resource( $stream ) ) return '';

        $bytes = stream_get_contents( $stream );
        fclose( $stream );
        if ( ! is_string( $bytes ) || $bytes === '' ) return '';

        $mime = (string) ( $media->mime_type ?? 'image/jpeg' );

        return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
    }

    /** True when this player has a photo at all, by either route. */
    public static function has( ?object $player ): bool {
        if ( $player === null ) return false;
        return (int) ( $player->photo_media_id ?? 0 ) > 0 || self::legacyUrl( $player ) !== '';
    }

    /**
     * The `tt_media` row behind this player's photo, or null.
     *
     * Not cached across the request on purpose: a roster renders many
     * different players, so a per-player cache would grow without bound
     * while never being hit twice for the same id on most screens.
     */
    private static function media( object $player ): ?object {
        $media_id = (int) ( $player->photo_media_id ?? 0 );
        if ( $media_id <= 0 ) return null;

        return ( new MediaRepository() )->find( $media_id );
    }

    /**
     * The pre-#3399 column. Kept readable so nothing goes blank between
     * this shipping and migration 0261 finishing, and so an install that
     * restores an old database row still renders.
     */
    private static function legacyUrl( object $player ): string {
        return (string) ( $player->photo_url ?? '' );
    }
}
