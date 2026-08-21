<?php
namespace TT\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\MediaRestController;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Storage\LocalPrivateStorage;
use TT\Modules\Media\Wizard\NewMediaWizard;
use TT\Shared\Wizards\WizardRegistry;

/**
 * MediaModule (#2590, epic #2589) — photos and video attached to the
 * records they belong to.
 *
 * Owns `tt_media` and `tt_media_links` (migration 0219), the storage
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

    /**
     * Media capabilities. **Matrix-only** — deliberately absent from
     * `RolesService::VIEW_CAPS` / `EDIT_CAPS`, which propagate to Head of
     * Development and the Read-Only Observer wholesale. Photographs of
     * minors are not something to hand out by propagation; every grant is
     * an explicit row in `config/authorization_seed.php`, per persona and
     * per scope.
     *
     * Three caps rather than the usual view/edit pair: uploading is a
     * create, and the matrix carries create under `create_delete`, so an
     * upload gate needs a cap that bridges to that verb.
     *
     * They are registered on the coach/admin roles here purely so the raw
     * capability exists to be bridged; `LegacyCapMapper` routes each
     * through `MatrixGate`, and the matrix decides the scope.
     *
     * @var list<string>
     */
    public const CAPS = [
        'tt_view_media',
        'tt_edit_media',
        'tt_manage_media',
    ];

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        // Registered here rather than unconditionally, so switching the
        // module off takes the byte-delivery endpoint down with it. On
        // nginx that endpoint is the only guard on the media directory,
        // so "off" has to mean genuinely unreachable.
        MediaRestController::init();

        // Registered on `init` so the registry is populated before a
        // request resolves `?tt_view=wizard&tt_wizard=new-media`.
        //
        // No `view_slugs` entry accompanies this in FeatureRegistry: every
        // wizard is reached through the shared `wizard` aggregator slug,
        // which the media feature does not own and must not gate. Turning
        // the feature off removes the entry points that link here, and
        // turning the module off unregisters the wizard entirely.
        add_action( 'init', static function (): void {
            if ( class_exists( WizardRegistry::class ) ) {
                WizardRegistry::register( new NewMediaWizard() );
            }
        }, 20 );

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
     * Register the raw caps so the matrix has something to bridge.
     *
     * Parent and player roles are absent on purpose: their read grant is a
     * matrix row at `player` / `self` scope, resolved by `MatrixGate`
     * against the actual parent-child link. Handing a family the raw
     * capability would be a club-wide grant waiting for a bug to expose it.
     */
    public static function ensureCapabilities(): void {
        foreach ( [ 'administrator', 'tt_club_admin', 'tt_head_dev', 'tt_coach' ] as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) continue;
            foreach ( self::CAPS as $cap ) {
                if ( ! $role->has_cap( $cap ) ) $role->add_cap( $cap );
            }
        }
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
