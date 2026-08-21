<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Media\Delivery\MediaDelivery;
use TT\Modules\Media\Links\VideoLinkResolver;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Storage\LocalPrivateStorage;

/**
 * #2592 (epic #2589) — byte delivery and link resolution.
 *
 * Two security surfaces, tested apart from the REST plumbing:
 *
 *   - **Delivery.** On nginx the media directory's own guard does
 *     nothing, so this path is the only thing between a photograph and
 *     the open web. What it sends, and what it refuses to send, is
 *     asserted here directly rather than inferred from a 200.
 *   - **Link resolution.** A pasted URL can drive a server-side request,
 *     which is the definition of an SSRF surface. The rejection table is
 *     the point of that half.
 */
final class MediaDeliveryTest extends WP_UnitTestCase {

    /** @var string */
    private $root = '';

    /** @var list<string> */
    private $temp_files = [];

    public function set_up(): void {
        parent::set_up();
        $this->root = untrailingslashit( get_temp_dir() ) . '/tt-media-delivery-' . wp_generate_password( 8, false );
        add_filter( 'tt_media_storage_root', function () { return $this->root; } );
    }

    public function tear_down(): void {
        remove_all_filters( 'tt_media_storage_root' );
        foreach ( $this->temp_files as $p ) {
            if ( is_file( $p ) ) @unlink( $p );
        }
        $this->temp_files = [];
        if ( $this->root !== '' && is_dir( $this->root ) ) $this->rmrf( $this->root );
        parent::tear_down();
    }

    // ── Range parsing ──────────────────────────────────────────────────

    /**
     * Range support is not a nicety: mobile Safari refuses to seek — and
     * in practice refuses to play — a video served without it.
     *
     * @dataProvider provideRanges
     * @param array{0:int,1:int}|string|null $expected
     */
    public function test_range_header_is_parsed( ?string $header, int $total, $expected ): void {
        $this->assertSame( $expected, MediaDelivery::parseRange( $header, $total ) );
    }

    /** @return array<string, array{0:?string,1:int,2:mixed}> */
    public function provideRanges(): array {
        return [
            'absent'                  => [ null, 1000, null ],
            'empty'                   => [ '', 1000, null ],
            'not a byte range'        => [ 'items=0-99', 1000, null ],
            'garbage'                 => [ 'bytes=abc', 1000, null ],
            'open ended'              => [ 'bytes=0-', 1000, [ 0, 999 ] ],
            'explicit'                => [ 'bytes=100-199', 1000, [ 100, 199 ] ],
            'first byte'              => [ 'bytes=0-0', 1000, [ 0, 0 ] ],
            'last byte'               => [ 'bytes=999-', 1000, [ 999, 999 ] ],
            'end past the file'       => [ 'bytes=900-99999', 1000, [ 900, 999 ] ],
            'suffix'                  => [ 'bytes=-200', 1000, [ 800, 999 ] ],
            'suffix longer than file' => [ 'bytes=-99999', 1000, [ 0, 999 ] ],
            // Multi-range answers with the whole file rather than a
            // multipart/byteranges body — see the class docblock.
            'multi range'             => [ 'bytes=0-99,200-299', 1000, null ],
            'start past the end'      => [ 'bytes=1000-1099', 1000, 'unsatisfiable' ],
            'reversed'                => [ 'bytes=500-100', 1000, 'unsatisfiable' ],
            'zero length suffix'      => [ 'bytes=-0', 1000, 'unsatisfiable' ],
        ];
    }

    // ── Plans ──────────────────────────────────────────────────────────

    public function test_full_response_is_200_with_no_content_range(): void {
        $media = $this->storedImage( 'abcdefghij' ); // 10 bytes

        $plan = MediaDelivery::plan( $media );

        $this->assertNotWPError( $plan );
        $this->assertSame( 200, $plan->status );
        $this->assertSame( 10, $plan->length() );
        $this->assertArrayNotHasKey( 'Content-Range', $plan->headers() );
        $this->assertSame( 'bytes', $plan->headers()['Accept-Ranges'] );
    }

    public function test_range_response_is_206_with_content_range(): void {
        $media = $this->storedImage( str_repeat( 'x', 1000 ) );

        $plan = MediaDelivery::plan( $media, MediaDelivery::VARIANT_FILE, 'bytes=100-199' );

        $this->assertNotWPError( $plan );
        $this->assertSame( 206, $plan->status );
        $this->assertSame( 100, $plan->length() );
        $this->assertSame( 'bytes 100-199/1000', $plan->headers()['Content-Range'] );
        $this->assertSame( '100', $plan->headers()['Content-Length'] );
    }

    public function test_unsatisfiable_range_is_refused(): void {
        $media = $this->storedImage( 'short' );

        $plan = MediaDelivery::plan( $media, MediaDelivery::VARIANT_FILE, 'bytes=9999-' );

        $this->assertWPError( $plan );
        $this->assertSame( 416, $plan->get_error_data()['status'] );
    }

    /**
     * The header that stops a browser deciding for itself that a file we
     * call an image is really something it should execute.
     */
    public function test_every_response_carries_nosniff_and_is_uncacheable(): void {
        $plan = MediaDelivery::plan( $this->storedImage( 'bytes' ) );

        $headers = $plan->headers();
        $this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
        $this->assertStringContainsString( 'private', $headers['Cache-Control'] );
        $this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
    }

    public function test_whitelisted_types_render_inline(): void {
        $plan = MediaDelivery::plan( $this->storedImage( 'bytes', 'image/jpeg' ) );
        $this->assertSame( 'inline', $plan->disposition );
    }

    /**
     * A row whose stored mime is not on the inline list must never be
     * offered for rendering in our own origin.
     */
    public function test_unexpected_type_is_forced_to_download(): void {
        $plan = MediaDelivery::plan( $this->storedImage( 'bytes', 'text/html' ) );

        $this->assertStringStartsWith( 'attachment;', $plan->disposition );
        $this->assertSame(
            'text/html',
            $plan->mime,
            'the stored type is reported as-is; it is the disposition that makes it safe, not a rewritten header'
        );
    }

    /**
     * The filename in a download header is built from the item's title,
     * never from anything the request supplied — and a title carrying a
     * quote must not be able to break out of the header.
     */
    public function test_download_filename_cannot_break_out_of_the_header(): void {
        $media        = $this->storedImage( 'bytes', 'text/html' );
        $media->title = 'evil"; x=1; y="';

        $plan = MediaDelivery::plan( $media );

        $this->assertStringStartsWith( 'attachment; filename="', $plan->disposition );
        $filename = substr( $plan->disposition, strlen( 'attachment; filename="' ), -1 );
        $this->assertStringNotContainsString( '"', $filename );
        $this->assertStringNotContainsString( ';', $filename );
    }

    public function test_video_link_has_no_bytes_to_serve(): void {
        $media = (object) [
            'kind'            => MediaKind::VIDEO_LINK,
            'provider'        => 'veo',
            'external_url'    => 'https://app.veo.co/matches/x/',
            'storage_key'     => '',
            'thumbnail_key'   => '',
            'storage_adapter' => LocalPrivateStorage::NAME,
            'mime_type'       => '',
            'title'           => 'Clip',
        ];

        $plan = MediaDelivery::plan( $media );

        $this->assertWPError( $plan );
        $this->assertSame( 'not_stored', $plan->get_error_code() );
        $this->assertSame( 409, $plan->get_error_data()['status'] );
    }

    public function test_missing_thumbnail_is_a_404_not_a_broken_stream(): void {
        $plan = MediaDelivery::plan( $this->storedImage( 'bytes' ), MediaDelivery::VARIANT_THUMB );

        $this->assertWPError( $plan );
        $this->assertSame( 404, $plan->get_error_data()['status'] );
    }

    public function test_a_row_pointing_at_a_missing_file_is_a_404(): void {
        $media = $this->storedImage( 'bytes' );
        ( new LocalPrivateStorage() )->delete( (string) $media->storage_key );

        $plan = MediaDelivery::plan( $media );

        $this->assertWPError( $plan );
        $this->assertSame( 404, $plan->get_error_data()['status'] );
    }

    // ── Link resolution / SSRF ─────────────────────────────────────────

    /**
     * The rejection table. Every one of these is a URL that must never
     * become an outbound request from the server.
     *
     * @dataProvider provideUnacceptableUrls
     */
    public function test_unacceptable_urls_are_refused( string $url ): void {
        $this->assertFalse( VideoLinkResolver::isAcceptable( $url ), $url . ' must not be storable' );
    }

    /** @return array<string, array{string}> */
    public function provideUnacceptableUrls(): array {
        return [
            'cloud metadata'     => [ 'http://169.254.169.254/latest/meta-data/' ],
            'loopback ip'        => [ 'http://127.0.0.1/admin' ],
            'private range'      => [ 'http://192.168.1.1/' ],
            'ipv6 loopback'      => [ 'http://[::1]/' ],
            'localhost'          => [ 'http://localhost/' ],
            'dotless host'       => [ 'http://intranet/' ],
            'file scheme'        => [ 'file:///etc/passwd' ],
            'javascript scheme'  => [ 'javascript:alert(1)' ],
            'data scheme'        => [ 'data:text/html,<script>alert(1)</script>' ],
            'gopher scheme'      => [ 'gopher://example.com/' ],
            'no host'            => [ 'https://' ],
            'empty'              => [ '' ],
        ];
    }

    public function test_ordinary_provider_urls_are_storable(): void {
        foreach ( [
            'https://www.youtube.com/watch?v=abc',
            'https://vimeo.com/12345',
            'https://app.veo.co/matches/x/',
            'https://www.hudl.com/video/x',
            'https://some-club-portal.example.com/match/7',
        ] as $url ) {
            $this->assertTrue( VideoLinkResolver::isAcceptable( $url ), $url );
        }
    }

    /**
     * Suffix matching, not substring matching. `evil-youtube.com` and
     * `youtube.com.attacker.net` must not be treated as YouTube — the
     * provider decides whether we make a request at all.
     *
     * @dataProvider provideProviderUrls
     */
    public function test_provider_detection( string $url, string $expected ): void {
        $this->assertSame( $expected, VideoLinkResolver::detectProvider( $url ) );
    }

    /** @return array<string, array{0:string,1:string}> */
    public function provideProviderUrls(): array {
        return [
            'youtube'            => [ 'https://www.youtube.com/watch?v=abc', 'youtube' ],
            'youtu.be'           => [ 'https://youtu.be/abc', 'youtube' ],
            'vimeo'              => [ 'https://vimeo.com/12345', 'vimeo' ],
            'veo'                => [ 'https://app.veo.co/matches/x/', 'veo' ],
            'hudl'               => [ 'https://www.hudl.com/video/x', 'hudl' ],
            'lookalike prefix'   => [ 'https://evil-youtube.com/watch?v=abc', 'other' ],
            'lookalike suffix'   => [ 'https://youtube.com.attacker.net/x', 'other' ],
            'unknown host'       => [ 'https://club.example.com/match/7', 'other' ],
        ];
    }

    /**
     * An unknown provider must resolve to a bare record with no outbound
     * request. Asserted by counting HTTP requests, because "it returned
     * empty strings" would also be true of a request that simply failed.
     */
    public function test_an_unknown_provider_triggers_no_outbound_request(): void {
        $calls = 0;
        add_filter( 'pre_http_request', static function ( $pre ) use ( &$calls ) {
            $calls++;
            return new \WP_Error( 'blocked', 'no network in tests' );
        }, 10, 1 );

        $meta = VideoLinkResolver::resolve( 'https://club.example.com/match/7' );

        remove_all_filters( 'pre_http_request' );

        $this->assertSame( 0, $calls, 'a host we do not recognise must never be fetched' );
        $this->assertSame( 'other', $meta['provider'] );
        $this->assertSame( '', $meta['title'] );
    }

    public function test_veo_and_hudl_are_stored_without_being_fetched(): void {
        $calls = 0;
        add_filter( 'pre_http_request', static function ( $pre ) use ( &$calls ) {
            $calls++;
            return new \WP_Error( 'blocked', 'no network in tests' );
        }, 10, 1 );

        $veo  = VideoLinkResolver::resolve( 'https://app.veo.co/matches/x/' );
        $hudl = VideoLinkResolver::resolve( 'https://www.hudl.com/video/x' );

        remove_all_filters( 'pre_http_request' );

        $this->assertSame( 0, $calls, 'neither provider publishes an oEmbed endpoint, so neither is queried' );
        $this->assertSame( 'veo', $veo['provider'] );
        $this->assertSame( 'hudl', $hudl['provider'] );
    }

    /**
     * The request must go to the provider's own oEmbed endpoint with the
     * video URL as a parameter — never to the pasted URL itself, which
     * would make a redirect chain in it something we follow.
     */
    public function test_oembed_requests_target_the_provider_endpoint(): void {
        $requested = '';
        add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$requested ) {
            $requested = $url;
            return [
                'response' => [ 'code' => 200 ],
                'body'     => wp_json_encode( [ 'title' => 'A training clip', 'thumbnail_url' => '' ] ),
            ];
        }, 10, 3 );

        $meta = VideoLinkResolver::resolve( 'https://www.youtube.com/watch?v=abc' );

        remove_all_filters( 'pre_http_request' );

        $this->assertStringStartsWith( 'https://www.youtube.com/oembed', $requested );
        $this->assertSame( 'A training clip', $meta['title'] );
    }

    /**
     * A provider is not trusted to name our next request either: a
     * thumbnail URL pointing somewhere else must be dropped.
     */
    public function test_a_thumbnail_url_off_the_allowlist_is_dropped(): void {
        add_filter( 'pre_http_request', static function () {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => wp_json_encode( [
                    'title'         => 'Clip',
                    'thumbnail_url' => 'http://169.254.169.254/latest/meta-data/',
                ] ),
            ];
        }, 10, 1 );

        $meta = VideoLinkResolver::resolve( 'https://www.youtube.com/watch?v=abc' );

        remove_all_filters( 'pre_http_request' );

        $this->assertSame( '', $meta['thumbnail_url'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** A media-shaped object with real bytes behind it. */
    private function storedImage( string $contents, string $mime = 'image/jpeg' ): object {
        $tmp = wp_tempnam( 'tt-media-delivery' ) . '.jpg';
        file_put_contents( $tmp, $contents );
        $this->temp_files[] = $tmp;

        $key = ( new LocalPrivateStorage() )->store( $tmp, 'jpg' );
        $this->assertNotSame( '', $key, 'the scratch store must accept the file' );

        return (object) [
            'kind'            => MediaKind::IMAGE,
            'storage_key'     => $key,
            'thumbnail_key'   => '',
            'storage_adapter' => LocalPrivateStorage::NAME,
            'mime_type'       => $mime,
            'title'           => 'Photo',
        ];
    }

    private function rmrf( string $dir ): void {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $dir );
    }
}
