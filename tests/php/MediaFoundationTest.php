<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Media\Ingest\MediaIngestService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Storage\LocalPrivateStorage;

/**
 * #2590 (epic #2589) — media foundation.
 *
 * The assertions here are the ones that protect a minor's photograph:
 * that a disguised SVG cannot get in, that GPS coordinates do not survive
 * ingest, that a hostile storage key cannot escape the media root, and
 * that removing the last link takes the bytes with it. A round-trip test
 * of the happy path would pass while every one of those was broken.
 */
final class MediaFoundationTest extends WP_UnitTestCase {

    /** @var list<string> */
    private $temp_files = [];

    public function tear_down(): void {
        foreach ( $this->temp_files as $path ) {
            if ( is_file( $path ) ) @unlink( $path );
        }
        $this->temp_files = [];
        parent::tear_down();
    }

    // ── storage ────────────────────────────────────────────────────────

    public function test_store_read_and_delete_round_trip(): void {
        $storage = new LocalPrivateStorage();
        $source  = $this->tempFileWith( 'hello media', 'bin' );

        $key = $storage->store( $source, 'jpg' );

        $this->assertNotSame( '', $key, 'store() should return a key' );
        $this->assertTrue( $storage->exists( $key ) );
        $this->assertSame( 11, $storage->size( $key ) );

        $handle = $storage->readStream( $key );
        $this->assertIsResource( $handle );
        $this->assertSame( 'hello media', stream_get_contents( $handle ) );
        fclose( $handle );

        $this->assertTrue( $storage->delete( $key ) );
        $this->assertFalse( $storage->exists( $key ) );
    }

    public function test_store_moves_the_source_file(): void {
        $storage = new LocalPrivateStorage();
        $source  = $this->tempFileWith( 'x', 'bin' );

        $key = $storage->store( $source, 'jpg' );

        $this->assertNotSame( '', $key );
        $this->assertFileDoesNotExist( $source, 'the source should be moved, not copied' );
        $storage->delete( $key );
    }

    /**
     * The keys below are the whole reason `pathFor()` validates instead of
     * concatenating. Every one of them must resolve to nothing rather than
     * to a file outside the media root.
     *
     * @dataProvider hostileKeys
     */
    public function test_hostile_keys_never_reach_the_filesystem( string $key ): void {
        $storage = new LocalPrivateStorage();

        $this->assertFalse( $storage->exists( $key ) );
        $this->assertNull( $storage->readStream( $key ) );
        $this->assertSame( 0, $storage->size( $key ) );
    }

    /** @return array<string, array{string}> */
    public function hostileKeys(): array {
        $stem = 'ab/cd/' . str_repeat( 'a', 32 );

        return [
            'parent traversal'   => [ '../../wp-config.php' ],
            'embedded traversal' => [ 'ab/../../../wp-config.php' ],
            'absolute path'      => [ '/etc/passwd' ],
            'windows absolute'   => [ 'C:\\Windows\\win.ini' ],
            'null byte'          => [ $stem . ".jpg\0.php" ],
            // `$` in a pattern also matches before a trailing newline, so
            // this shape is what an anchor mistake would let through.
            'trailing newline'   => [ $stem . ".jpg\n" ],
            'newline traversal'  => [ $stem . ".jpg\n../../evil" ],
            // Executable extensions inside uploads are the classic path to
            // RCE on any server the deny-all guard does not cover.
            'php extension'      => [ $stem . '.php' ],
            'phtml extension'    => [ $stem . '.phtml' ],
            'no extension'       => [ $stem ],
            'uppercase hex'      => [ 'AB/CD/' . str_repeat( 'A', 32 ) . '.jpg' ],
            'wrong shape'        => [ 'not-a-key.jpg' ],
            'empty'              => [ '' ],
        ];
    }

    public function test_store_refuses_an_unsafe_extension(): void {
        $storage = new LocalPrivateStorage();
        $source  = $this->tempFileWith( "<?php echo 1;", 'php' );

        $this->assertSame(
            '',
            $storage->store( $source, 'php' ),
            'the store names files itself and must never be talked into an executable extension'
        );
    }

    public function test_media_root_is_guarded_against_direct_access(): void {
        $dir = LocalPrivateStorage::ensureRoot();

        $this->assertNotSame( '', $dir );
        $this->assertDirectoryExists( $dir );
        $this->assertFileExists( $dir . '/index.php' );
        $this->assertFileExists(
            $dir . '/.htaccess',
            'Apache deny-all guard. Defence in depth — on nginx the REST endpoint is the real boundary.'
        );
    }

    // ── ingest ─────────────────────────────────────────────────────────

    public function test_svg_disguised_as_a_jpeg_is_refused(): void {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $path = $this->tempFileWith( $svg, 'jpg' );

        $result = ( new MediaIngestService() )->ingest( $path );

        $this->assertFalse( $result->isOk(), 'an SVG must not be storable, whatever it is named' );
        $this->assertContains( $result->code(), [ 'unsafe_type', 'unsupported_type' ] );
    }

    public function test_php_source_renamed_to_png_is_refused(): void {
        $path = $this->tempFileWith( "<?php echo 'pwned';", 'png' );

        $result = ( new MediaIngestService() )->ingest( $path );

        $this->assertFalse( $result->isOk() );
    }

    public function test_empty_file_is_refused(): void {
        $path = $this->tempFileWith( '', 'jpg' );

        $result = ( new MediaIngestService() )->ingest( $path );

        $this->assertFalse( $result->isOk() );
        $this->assertSame( 'empty_file', $result->code() );
    }

    public function test_oversized_file_is_refused_with_the_real_limit_named(): void {
        add_filter( 'tt_media_max_upload_bytes', static function () { return 4; } );
        $path = $this->tempFileWith( 'more than four bytes', 'jpg' );

        $result = ( new MediaIngestService() )->ingest( $path );

        remove_all_filters( 'tt_media_max_upload_bytes' );

        $this->assertFalse( $result->isOk() );
        $this->assertSame( 'too_large', $result->code() );
        $this->assertNotSame( '', $result->message(), 'the user needs to be told the limit, not just refused' );
    }

    public function test_jpeg_ingests_and_keeps_no_exif(): void {
        if ( ! function_exists( 'imagejpeg' ) ) {
            $this->markTestSkipped( 'GD is not available' );
        }

        $path   = $this->makeJpeg( 40, 25 );
        $result = ( new MediaIngestService() )->ingest( $path, [ 'title' => 'Session photo' ] );

        $this->assertTrue( $result->isOk(), $result->message() );
        $payload = $result->payload();

        $this->assertSame( MediaKind::IMAGE, $payload['kind'] );
        $this->assertSame( 'image/jpeg', $payload['mime_type'] );
        $this->assertSame( 40, $payload['width'] );
        $this->assertSame( 25, $payload['height'] );
        $this->assertSame( 64, strlen( (string) $payload['checksum'] ), 'sha256 is 64 hex characters' );
        $this->assertSame( LocalPrivateStorage::NAME, $payload['storage_adapter'] );

        $storage = new LocalPrivateStorage();
        $this->assertTrue( $storage->exists( $payload['storage_key'] ) );

        // The stored bytes must carry pixels and nothing else. exif_read_data
        // on a stripped JPEG finds no APP1 block at all.
        if ( function_exists( 'exif_read_data' ) ) {
            $stored = LocalPrivateStorage::root() . '/' . $payload['storage_key'];
            $exif   = @exif_read_data( $stored );
            if ( is_array( $exif ) ) {
                $this->assertArrayNotHasKey( 'GPSLatitude', $exif );
                $this->assertArrayNotHasKey( 'GPSLongitude', $exif );
            }
        }

        $storage->delete( $payload['storage_key'] );
        if ( ! empty( $payload['thumbnail_key'] ) ) $storage->delete( $payload['thumbnail_key'] );
    }

    public function test_allowed_types_exclude_svg(): void {
        $allowed = MediaIngestService::allowedTypes();

        $this->assertArrayNotHasKey( 'image/svg+xml', $allowed );
        $this->assertArrayHasKey( 'image/jpeg', $allowed );
        $this->assertArrayHasKey( 'video/mp4', $allowed );
    }

    // ── links ──────────────────────────────────────────────────────────

    public function test_linking_the_same_pair_twice_is_idempotent(): void {
        $media_id = $this->makeLinkRow();
        $links    = new MediaLinksRepository();

        $first  = $links->link( $media_id, MediaEntityType::PLAYER, 77 );
        $second = $links->link( $media_id, MediaEntityType::PLAYER, 77 );

        $this->assertGreaterThan( 0, $first );
        $this->assertSame( $first, $second, 'a repeat attach must not create a second link' );
        $this->assertSame( 1, $links->countFor( $media_id ) );
    }

    public function test_invalid_entity_type_is_refused(): void {
        $media_id = $this->makeLinkRow();

        $this->assertSame( 0, ( new MediaLinksRepository() )->link( $media_id, 'coach', 5 ) );
    }

    public function test_removing_the_last_link_deletes_the_media(): void {
        $media_id = $this->makeLinkRow();
        $links    = new MediaLinksRepository();
        $media    = new MediaRepository();

        $a = $links->link( $media_id, MediaEntityType::PLAYER, 77 );
        $links->link( $media_id, MediaEntityType::ACTIVITY, 12 );

        $links->unlink( $a );
        $this->assertNotNull( $media->find( $media_id ), 'still attached to the activity' );

        $remaining = $links->listForMedia( $media_id );
        $links->unlink( (int) $remaining[0]->id );

        $this->assertNull(
            $media->find( $media_id ),
            'media attached to nothing is unreachable, so it must not survive as an orphan row'
        );
    }

    public function test_unlinking_a_player_takes_its_orphans_with_it(): void {
        $links = new MediaLinksRepository();
        $media = new MediaRepository();

        $only_player = $this->makeLinkRow();
        $shared      = $this->makeLinkRow();

        $links->link( $only_player, MediaEntityType::PLAYER, 91 );
        $links->link( $shared, MediaEntityType::PLAYER, 91 );
        $links->link( $shared, MediaEntityType::TEAM, 3 );

        $links->unlinkEntity( MediaEntityType::PLAYER, 91 );

        $this->assertNull( $media->find( $only_player ), 'nothing else pointed at it' );
        $this->assertNotNull( $media->find( $shared ), 'the team still points at it' );
    }

    public function test_primary_is_per_record_not_per_media(): void {
        $links = new MediaLinksRepository();

        $a = $this->makeLinkRow();
        $b = $this->makeLinkRow();

        $link_a = $links->link( $a, MediaEntityType::TEAM, 3, true );
        $link_b = $links->link( $b, MediaEntityType::TEAM, 3, true );
        $link_c = $links->link( $a, MediaEntityType::PLAYER, 77, true );

        $this->assertSame( 0, (int) $links->find( $link_a )->is_primary, 'b took over as the team primary' );
        $this->assertSame( 1, (int) $links->find( $link_b )->is_primary );
        $this->assertSame(
            1,
            (int) $links->find( $link_c )->is_primary,
            'the same item can be primary for a player while not being primary for a team'
        );
    }

    // ── ordering ───────────────────────────────────────────────────────

    /**
     * The reason `captured_at` exists. A coach empties their camera roll
     * weeks later, so upload order says nothing about when things happened.
     */
    public function test_listing_sorts_by_capture_date_not_upload_order(): void {
        $links = new MediaLinksRepository();

        $august   = $this->makeLinkRow( [ 'title' => 'August', 'captured_at' => '2026-08-14 18:00:00' ] );
        $november = $this->makeLinkRow( [ 'title' => 'November', 'captured_at' => '2026-11-02 11:00:00' ] );

        // Uploaded in the wrong order on purpose: August's photo is added last.
        $links->link( $november, MediaEntityType::PLAYER, 55 );
        $links->link( $august, MediaEntityType::PLAYER, 55 );

        $rows = ( new MediaRepository() )->listForEntity( MediaEntityType::PLAYER, 55 );

        $this->assertCount( 2, $rows );
        $this->assertSame( 'November', $rows[0]->title );
        $this->assertSame( 'August', $rows[1]->title );
    }

    public function test_archived_media_is_hidden_from_listings(): void {
        $links    = new MediaLinksRepository();
        $media    = new MediaRepository();
        $media_id = $this->makeLinkRow();

        $links->link( $media_id, MediaEntityType::PLAYER, 61 );
        $media->archive( $media_id );

        $this->assertCount( 0, $media->listForEntity( MediaEntityType::PLAYER, 61 ) );
        $this->assertCount( 1, $media->listForEntity( MediaEntityType::PLAYER, 61, true ) );

        $media->restore( $media_id );
        $this->assertCount( 1, $media->listForEntity( MediaEntityType::PLAYER, 61 ) );
    }

    public function test_rows_get_a_uuid_and_are_findable_by_it(): void {
        $media_id = $this->makeLinkRow();
        $repo     = new MediaRepository();

        $row = $repo->find( $media_id );
        $this->assertNotNull( $row );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $row->uuid
        );
        $this->assertSame( $media_id, (int) $repo->findByUuid( (string) $row->uuid )->id );
    }

    // ── switchability ──────────────────────────────────────────────────

    public function test_module_is_registered_and_remains_switchable(): void {
        $modules = require dirname( __DIR__, 2 ) . '/config/modules.php';

        $this->assertArrayHasKey( 'TT\\Modules\\Media\\MediaModule', $modules );

        $this->assertFalse(
            \TT\Core\ModuleRegistry::isAlwaysOn( 'TT\\Modules\\Media\\MediaModule' ),
            'an academy must be able to refuse photographs of its players entirely'
        );
    }

    public function test_media_feature_is_in_the_toggle_catalog(): void {
        $this->assertTrue( \TT\Core\FeatureRegistry::exists( 'media' ) );

        $owned = \TT\Core\FeatureRegistry::forModule( 'TT\\Modules\\Media\\MediaModule' );
        $this->assertContains(
            'media',
            array_column( $owned, 'key' ),
            'the feature must be owned by the media module, or the module toggle and the feature toggle drift apart'
        );
    }

    public function test_module_has_human_facing_metadata(): void {
        // Every class in config/modules.php needs an entry, or the modules
        // page shows a slugified class name where a label belongs.
        $meta = \TT\Shared\Modules\ModuleMetadata::for( 'TT\\Modules\\Media\\MediaModule' );

        $this->assertSame( 'Media library', $meta['label'] );
        $this->assertNotSame( '', $meta['description'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function tempFileWith( string $contents, string $ext ): string {
        $path = wp_tempnam( 'tt-media-test' ) . '.' . $ext;
        file_put_contents( $path, $contents );
        $this->temp_files[] = $path;
        return $path;
    }

    private function makeJpeg( int $width, int $height ): string {
        $image = imagecreatetruecolor( $width, $height );
        imagefill( $image, 0, 0, imagecolorallocate( $image, 10, 120, 60 ) );
        $path = wp_tempnam( 'tt-media-test' ) . '.jpg';
        imagejpeg( $image, $path, 90 );
        imagedestroy( $image );
        $this->temp_files[] = $path;
        return $path;
    }

    /**
     * A media row without going through ingest — these tests are about
     * link and ordering behaviour, not about the file pipeline.
     *
     * @param array<string, mixed> $overrides
     */
    private function makeLinkRow( array $overrides = [] ): int {
        return ( new MediaRepository() )->insert( array_merge( [
            'kind'            => MediaKind::VIDEO_LINK,
            'title'           => 'Test item',
            'provider'        => 'veo',
            'external_url'    => 'https://app.veo.co/matches/test/',
            'storage_adapter' => LocalPrivateStorage::NAME,
        ], $overrides ) );
    }
}
