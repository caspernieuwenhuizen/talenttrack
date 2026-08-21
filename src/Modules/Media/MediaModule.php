<?php
namespace TT\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Storage\LocalPrivateStorage;

/**
 * MediaModule (#2590, epic #2589) — photos and video attached to the
 * records they belong to.
 *
 * Owns `tt_media` and `tt_media_links` (migration 0218), the storage
 * adapters, the ingest pipeline and the repositories over both tables.
 *
 * The module is switchable from its first commit rather than gaining an
 * off-switch once six surfaces already depend on it. It is deliberately
 * absent from `ModuleRegistry::ALWAYS_ON_MODULES`: an academy that does
 * not want photographs of its players in the system must be able to
 * refuse the whole feature, not merely decline to use it. With the module
 * off, `registerAll()` / `bootAll()` never run, so its hooks and — from
 * the REST slice — its routes are not registered at all.
 *
 * Shipped here:
 *   - The two tables plus their repositories.
 *   - `MediaStorageInterface` + `LocalPrivateStorage`, the private store.
 *   - `MediaIngestService`: content-sniffed type whitelist, SVG refusal,
 *     EXIF stripping, checksums, thumbnails.
 *   - Player-deletion cascade over the polymorphic link table.
 *
 * Not yet, by slice: authorization + visibility (#2591), the REST surface
 * including byte delivery (#2592), the upload wizard (#2593), the player
 * media tab (#2594), team + activity surfaces (#2595), and the tile,
 * docs and demo data (#2596).
 */
class MediaModule implements ModuleInterface {

    public function getName(): string { return 'media'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // The private store's guards are written on first use rather than
        // on activation, so an install whose uploads directory only
        // becomes writable later still gets them.
        add_action( 'init', [ self::class, 'ensureStorage' ] );

        // A player's media is part of the player's record, so erasing the
        // player must erase it. `PlayerDeletionCascade` deletes the link
        // rows inside its transaction and fires this once the batch is
        // durable; removing bytes is not something a rollback could undo,
        // so it deliberately happens after the commit rather than during.
        add_action( 'tt_media_links_pruned', [ self::class, 'onLinksPruned' ], 10, 1 );

        // Activities announce their own deletion; teams and players do not
        // (players go through the cascade above).
        add_action( 'tt_activity_deleted', [ self::class, 'onActivityDeleted' ], 10, 1 );
    }

    public static function ensureStorage(): void {
        LocalPrivateStorage::ensureRoot();
    }

    /**
     * Media whose links were removed elsewhere. Anything left attached to
     * nothing is unreachable by any surface, so its row and its bytes go.
     *
     * @param int[] $media_ids
     */
    public static function onLinksPruned( array $media_ids ): void {
        $links = new MediaLinksRepository();
        $media = new MediaRepository();

        foreach ( $media_ids as $media_id ) {
            $media_id = (int) $media_id;
            if ( $media_id <= 0 ) continue;
            if ( $links->countFor( $media_id ) > 0 ) continue;
            $media->deleteWithBlobs( $media_id );
        }
    }

    public static function onActivityDeleted( int $activity_id ): void {
        if ( $activity_id <= 0 ) return;
        ( new MediaLinksRepository() )->unlinkEntity( MediaEntityType::ACTIVITY, $activity_id );
    }
}
