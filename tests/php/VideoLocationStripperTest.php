<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Media\Ingest\MediaIngestService;
use TT\Modules\Media\Ingest\VideoLocationStripper;

/**
 * #2611 (epic #2589) — location metadata in uploaded video.
 *
 * The trees here are built byte by byte rather than loaded from a binary
 * fixture, because the thing under test is the parsing: a fixture would
 * pin one phone's output and say nothing about the 64-bit header, the
 * QuickTime-versus-ISO `meta` ambiguity, or a box that lies about its own
 * size. Each of those is a way this corrupts a video, so each gets a tree.
 *
 * The load-bearing assertion is not that the coordinates are gone. It is
 * that **the file is exactly as long afterwards** — `stco` holds absolute
 * file offsets into `mdat`, so a `moov` that shrinks by even one byte
 * takes the playhead with it on every faststart file in the world.
 */
final class VideoLocationStripperTest extends WP_UnitTestCase {

    /** ISO 6709, the way a phone writes it. */
    private const COORD = '+52.3702+004.8952+002.000/';

    /** @var list<string> */
    private $temp_files = [];

    public function tear_down(): void {
        foreach ( $this->temp_files as $path ) {
            if ( is_file( $path ) ) @unlink( $path );
        }
        $this->temp_files = [];
        parent::tear_down();
    }

    public function test_ios_udta_coordinates_are_removed_without_changing_length(): void {
        $path = $this->write(
            $this->ftyp()
            . $this->box( 'moov', $this->box( 'mvhd', str_repeat( "\0", 100 ) ) . $this->box( 'udta', $this->xyz() ) )
            . $this->mdat()
        );

        $before = filesize( $path );

        $this->assertSame( VideoLocationStripper::REMOVED, VideoLocationStripper::strip( $path ) );
        $this->assertSame( $before, filesize( $path ), 'The file changed length; every stco offset just moved.' );
        $this->assertStringNotContainsString( '52.3702', (string) file_get_contents( $path ) );
        $this->assertStringContainsString( 'free', (string) file_get_contents( $path ) );
    }

    public function test_faststart_file_keeps_mdat_at_the_same_offset(): void {
        $head = $this->ftyp() . $this->box( 'moov', $this->box( 'udta', $this->xyz() ) );
        $path = $this->write( $head . $this->mdat() );

        $expected = strlen( $head ) + 4; // `mdat` type sits after its size field.

        VideoLocationStripper::strip( $path );

        $this->assertSame( $expected, strpos( (string) file_get_contents( $path ), 'mdat' ) );
    }

    public function test_3gpp_loci_under_a_track_is_removed(): void {
        $trak = $this->box(
            'trak',
            $this->box( 'tkhd', str_repeat( "\0", 84 ) )
            . $this->box( 'udta', $this->box( 'loci', "\0\0\0\0" . "eng\0" . self::COORD ) )
        );

        $path = $this->write( $this->ftyp() . $this->box( 'moov', $trak ) . $this->mdat() );

        $this->assertSame( VideoLocationStripper::REMOVED, VideoLocationStripper::strip( $path ) );
        $this->assertStringNotContainsString( '52.3702', (string) file_get_contents( $path ) );
    }

    /**
     * Modern iOS: a `keys` table names the fields and the `ilst` children
     * are numeric indexes into it, so the coordinates live in a box whose
     * type is "\0\0\0\x02". A sweep for `©xyz` alone would walk straight
     * past this, which is exactly why the keys table is parsed.
     */
    public function test_indexed_ilst_entry_is_resolved_through_the_keys_table(): void {
        $keys = $this->box( 'keys', "\0\0\0\0" . pack( 'N', 2 )
            . $this->keyEntry( 'com.apple.quicktime.make' )
            . $this->keyEntry( 'com.apple.quicktime.location.ISO6709' ) );

        $ilst = $this->box( 'ilst',
            $this->box( pack( 'N', 1 ), $this->data( 'Apple' ) )
            . $this->box( pack( 'N', 2 ), $this->data( self::COORD ) )
        );

        // ISO 14496-12 `meta` is a FullBox: four bytes before the children.
        $meta = $this->box( 'meta', "\0\0\0\0" . $this->box( 'hdlr', str_repeat( "\0", 24 ) ) . $keys . $ilst );
        $path = $this->write( $this->ftyp() . $this->box( 'moov', $meta ) . $this->mdat() );

        $before = filesize( $path );

        $this->assertSame( VideoLocationStripper::REMOVED, VideoLocationStripper::strip( $path ) );
        $this->assertSame( $before, filesize( $path ) );
        $this->assertStringNotContainsString( '52.3702', (string) file_get_contents( $path ) );
        $this->assertStringContainsString(
            'Apple',
            (string) file_get_contents( $path ),
            'The sibling metadata value was collateral damage.'
        );
    }

    /** QuickTime writes `meta` as a plain container, with no version and flags. */
    public function test_quicktime_meta_without_the_fullbox_header_is_still_walked(): void {
        $ilst = $this->box( 'ilst', $this->box( "\xA9xyz", $this->data( self::COORD ) ) );
        $meta = $this->box( 'meta', $this->box( 'hdlr', str_repeat( "\0", 24 ) ) . $ilst );
        $path = $this->write( $this->ftyp() . $this->box( 'moov', $meta ) . $this->mdat() );

        $this->assertSame( VideoLocationStripper::REMOVED, VideoLocationStripper::strip( $path ) );
    }

    public function test_a_64_bit_box_header_is_handled(): void {
        $payload = pack( 'n', strlen( self::COORD ) ) . pack( 'n', 0x15C7 ) . self::COORD;
        $large   = pack( 'N', 1 ) . "\xA9xyz" . pack( 'J', 16 + strlen( $payload ) ) . $payload;

        $path   = $this->write( $this->ftyp() . $this->box( 'moov', $this->box( 'udta', $large ) ) . $this->mdat() );
        $before = filesize( $path );

        $this->assertSame( VideoLocationStripper::REMOVED, VideoLocationStripper::strip( $path ) );
        $this->assertSame( $before, filesize( $path ) );
        $this->assertStringNotContainsString( '52.3702', (string) file_get_contents( $path ) );
    }

    public function test_a_clean_file_reports_none_and_keeps_its_other_metadata(): void {
        $udta = $this->box( 'udta', $this->box( "\xA9nam", 'Training clip' ) );
        $path = $this->write( $this->ftyp() . $this->box( 'moov', $udta ) . $this->mdat() );

        $this->assertSame( VideoLocationStripper::NONE, VideoLocationStripper::strip( $path ) );
        $this->assertStringContainsString( 'Training clip', (string) file_get_contents( $path ) );
    }

    /**
     * Coordinates in a box we have never heard of. We cannot remove what we
     * cannot find, and reporting the file as clean would be a lie the
     * academy would repeat in its DPIA.
     */
    public function test_coordinates_in_an_unknown_box_report_unreadable(): void {
        $udta = $this->box( 'udta', $this->box( 'XtRa', 'GPS ' . self::COORD ) );
        $path = $this->write( $this->ftyp() . $this->box( 'moov', $udta ) . $this->mdat() );

        $this->assertSame( VideoLocationStripper::UNREADABLE, VideoLocationStripper::strip( $path ) );
    }

    public function test_a_box_that_lies_about_its_size_reports_unreadable(): void {
        $path = $this->write( $this->ftyp() . pack( 'N', 999999 ) . 'moov' . 'junk' );

        $this->assertSame( VideoLocationStripper::UNREADABLE, VideoLocationStripper::strip( $path ) );
    }

    public function test_a_missing_file_reports_unreadable(): void {
        $this->assertSame( VideoLocationStripper::UNREADABLE, VideoLocationStripper::strip( '' ) );
        $this->assertSame(
            VideoLocationStripper::UNREADABLE,
            VideoLocationStripper::strip( get_temp_dir() . '/tt-not-here-' . wp_generate_password( 8, false ) . '.mp4' )
        );
    }

    /**
     * The poster frame is an image and goes through the image path, so it
     * should already be clean. #2611 asked for that to be a test rather
     * than an assumption, because the assumption is only true for as long
     * as nobody gives video its own thumbnail route.
     */
    public function test_the_video_poster_frame_goes_through_the_image_strip(): void {
        if ( ! function_exists( 'imagejpeg' ) ) {
            $this->markTestSkipped( 'GD is not available.' );
        }

        $poster = get_temp_dir() . '/tt-poster-' . wp_generate_password( 8, false ) . '.jpg';
        $this->temp_files[] = $poster;

        $image = imagecreatetruecolor( 32, 32 );
        imagejpeg( $image, $poster, 90 );
        imagedestroy( $image );

        // Append an APP1 marker's worth of GPS-looking text. A real EXIF
        // block would be re-encoded away; so is this, which is the point —
        // the stored poster carries pixels and nothing else.
        file_put_contents( $poster, self::COORD, FILE_APPEND );

        $root = untrailingslashit( get_temp_dir() ) . '/tt-media-poster-' . wp_generate_password( 8, false );
        add_filter( 'tt_media_storage_root', static function () use ( $root ) { return $root; } );

        $key = ( new MediaIngestService() )->storeThumbnail( $poster, 'image/jpeg' );

        remove_all_filters( 'tt_media_storage_root' );

        $this->assertNotNull( $key, 'The poster frame was not stored at all.' );
        $this->assertStringNotContainsString(
            '52.3702',
            (string) file_get_contents( $root . '/' . $key ),
            'The stored poster frame still carries coordinates.'
        );
    }

    // Tree builders

    private function box( string $type, string $payload ): string {
        return pack( 'N', 8 + strlen( $payload ) ) . $type . $payload;
    }

    private function ftyp(): string {
        return $this->box( 'ftyp', 'isom' . pack( 'N', 512 ) . 'isomiso2avc1mp41' );
    }

    /** Stand-in sample data. Its bytes must survive untouched. */
    private function mdat(): string {
        return $this->box( 'mdat', str_repeat( 'M', 4096 ) );
    }

    private function xyz(): string {
        return $this->box( "\xA9xyz", pack( 'n', strlen( self::COORD ) ) . pack( 'n', 0x15C7 ) . self::COORD );
    }

    private function keyEntry( string $name ): string {
        return pack( 'N', 8 + strlen( $name ) ) . 'mdta' . $name;
    }

    private function data( string $value ): string {
        return $this->box( 'data', pack( 'N', 1 ) . pack( 'N', 0 ) . $value );
    }

    private function write( string $bytes ): string {
        $path = untrailingslashit( get_temp_dir() ) . '/tt-strip-' . wp_generate_password( 8, false ) . '.mp4';
        file_put_contents( $path, $bytes );
        $this->temp_files[] = $path;
        return $path;
    }
}
