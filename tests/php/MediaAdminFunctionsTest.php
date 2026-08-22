<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Media\Ingest\MediaIngestService;

/**
 * #2674 — media code must not call an admin-only function from a path a
 * REST request can reach.
 *
 * `wp_tempnam()` lives in `wp-admin/includes/file.php`. WordPress does
 * not load that file during a REST request, so calling it from an
 * endpoint is a fatal. `MediaIngestService::makeThumbnail()` did, and
 * every photo upload returned a 500 while video uploads happened to
 * survive — only the image path builds a thumbnail.
 *
 * **Why the existing tests did not catch it.** `MediaFoundationTest`
 * ingests a real JPEG and passed CI throughout. The wp-env test
 * bootstrap loads the admin includes, so `wp_tempnam()` exists there and
 * the call worked in every environment except the one that matters. A
 * behavioural test therefore cannot catch this class of bug at all, no
 * matter how thorough — which is why this one reads the source instead.
 */
final class MediaAdminFunctionsTest extends WP_UnitTestCase {

    /**
     * Functions defined only under `wp-admin/includes/`. Not exhaustive —
     * these are the ones media code plausibly reaches for.
     *
     * @var list<string>
     */
    private const ADMIN_ONLY = [
        'wp_tempnam',
        'download_url',
        'wp_handle_upload',
        'wp_handle_sideload',
        'media_handle_upload',
        'media_handle_sideload',
        'wp_read_image_metadata',
        'wp_crop_image',
        'wp_generate_attachment_metadata',
        'request_filesystem_credentials',
    ];

    /**
     * A file may call them, but only if it loads the include first.
     *
     * Checked per file rather than per call: a lint, not a data-flow
     * analysis. A file that guards one call and not another would slip
     * through — which is precisely why the guard is centralised in
     * `MediaIngestService::tempFile()` and the third test below forbids
     * calling `wp_tempnam()` anywhere else.
     */
    private const GUARDS = [
        "wp-admin/includes/file.php",
        "wp-admin/includes/image.php",
        "wp-admin/includes/media.php",
    ];

    public function test_no_media_path_calls_an_admin_only_function_unguarded(): void {
        $offences = [];

        foreach ( $this->mediaSources() as $path ) {
            $src = (string) file_get_contents( $path );
            $rel = $this->relative( $path );

            $guarded = false;
            foreach ( self::GUARDS as $guard ) {
                if ( strpos( $src, $guard ) !== false ) $guarded = true;
            }

            foreach ( self::ADMIN_ONLY as $fn ) {
                // A call, not a mention in a comment or a string.
                if ( ! preg_match( '/(?<![\w$>-])' . preg_quote( $fn, '/' ) . '\s*\(/', $this->stripComments( $src ) ) ) {
                    continue;
                }
                if ( $guarded ) continue;

                $offences[] = "{$rel} calls {$fn}() without loading the wp-admin include it lives in — "
                    . 'that is a fatal in a REST request.';
            }
        }

        $this->assertSame( [], $offences, implode( "\n", $offences ) );
    }

    /**
     * The helper every media temp file should come through, so the guard
     * lives in one place rather than at each call site.
     */
    public function test_the_shared_temp_file_helper_works(): void {
        $path = MediaIngestService::tempFile( 'tt-media-test' );

        $this->assertNotSame( '', $path );
        $this->assertFileExists( $path );

        @unlink( $path );
    }

    /**
     * Guards the fix directly: nothing in the media tree should reach for
     * `wp_tempnam()` itself any more.
     */
    public function test_media_code_uses_the_helper_rather_than_wp_tempnam(): void {
        $direct = [];

        foreach ( $this->mediaSources() as $path ) {
            $rel = $this->relative( $path );
            // The helper itself is where the one legitimate call lives.
            if ( strpos( $rel, 'Media/Ingest/MediaIngestService.php' ) !== false ) continue;

            $src = $this->stripComments( (string) file_get_contents( $path ) );
            if ( preg_match( '/(?<![\w$>-])wp_tempnam\s*\(/', $src ) ) {
                $direct[] = "{$rel} calls wp_tempnam() directly; use MediaIngestService::tempFile().";
            }
        }

        $this->assertSame( [], $direct, implode( "\n", $direct ) );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return list<string> */
    private function mediaSources(): array {
        $root = dirname( __DIR__, 2 );

        $files = [];

        $dir = $root . '/src/Modules/Media';
        if ( is_dir( $dir ) ) {
            $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
            foreach ( $it as $entry ) {
                if ( $entry->isFile() && substr( $entry->getFilename(), -4 ) === '.php' ) $files[] = $entry->getPathname();
            }
        }

        foreach ( [
            '/src/Infrastructure/REST/MediaRestController.php',
            '/src/Modules/DemoData/Generators/MediaGenerator.php',
            '/src/Shared/Frontend/Components/MediaGallery.php',
            '/src/Shared/Frontend/Components/MediaUploader.php',
        ] as $rel ) {
            if ( is_file( $root . $rel ) ) $files[] = $root . $rel;
        }

        sort( $files );
        return $files;
    }

    private function relative( string $path ): string {
        return ltrim( str_replace( [ dirname( __DIR__, 2 ), '\\' ], [ '', '/' ], $path ), '/' );
    }

    /** Comments and docblocks explain these functions; they do not call them. */
    private function stripComments( string $src ): string {
        $out = '';
        foreach ( token_get_all( $src ) as $token ) {
            if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) continue;
            $out .= is_array( $token ) ? $token[1] : $token;
        }
        return $out;
    }
}
