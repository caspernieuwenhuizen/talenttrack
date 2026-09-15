<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Services\PlayerPhoto;
use TT\Modules\Players\Services\PlayerPhotoImporter;

/**
 * #3399 — a player's photograph is not a public URL any more.
 *
 * `tt_players.photo_url` held a bare `wp-content/uploads/` address, filled
 * by the WordPress media picker with `.toJSON().url` and rendered verbatim
 * on a dozen surfaces. So a photograph of a minor sat at a guessable path,
 * served by Apache with no capability check, no nonce and no expiry,
 * reachable by anyone with the link whether or not they had an account —
 * while the Media module (#2589) kept the same academy's media library
 * locked behind a permission callback.
 *
 * Decided 2026-09-15: photos move into that store, and the public copies
 * are deleted rather than left working.
 */
final class PlayerPhotoPrivacyTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $player;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $this->player = $this->seedPlayer();
    }

    /* ---- the accessor ------------------------------------------------- */

    public function test_a_player_with_no_photo_has_no_url(): void {
        $this->assertSame( '', PlayerPhoto::url( $this->playerRow() ) );
        $this->assertFalse( PlayerPhoto::has( $this->playerRow() ) );
    }

    public function test_a_legacy_url_still_renders_before_the_sweep(): void {
        // Migration 0261 runs after 0260, and an install is mid-upgrade in
        // between. Nothing may go blank in that window.
        $this->setColumn( 'photo_url', 'https://cdn.example.test/old.jpg' );

        $this->assertSame( 'https://cdn.example.test/old.jpg', PlayerPhoto::url( $this->playerRow() ) );
        $this->assertTrue( PlayerPhoto::has( $this->playerRow() ) );
    }

    /* ---- the import --------------------------------------------------- */

    public function test_an_uploads_photo_moves_into_the_store_and_the_public_copy_goes(): void {
        [ $url, $path ] = $this->seedUploadsImage();
        $this->assertFileExists( $path, 'the premise: the file is public on disk' );

        $media_id = PlayerPhotoImporter::fromUploadsUrl( $this->player, $url );

        $this->assertGreaterThan( 0, $media_id );
        $this->assertFileDoesNotExist(
            $path,
            'the public copy is the defect; leaving it would keep every old link working'
        );

        global $wpdb;
        $media = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_media WHERE id = %d",
            $media_id
        ) );
        $this->assertNotNull( $media );
        $this->assertNotSame( '', (string) $media->storage_key );
    }

    public function test_the_stored_photo_is_served_through_the_gated_route(): void {
        [ $url ] = $this->seedUploadsImage();
        $media_id = PlayerPhotoImporter::fromUploadsUrl( $this->player, $url );
        $this->setColumn( 'photo_media_id', (string) $media_id );
        $this->setColumn( 'photo_url', '' );

        $rendered = PlayerPhoto::url( $this->playerRow() );

        $this->assertNotSame( '', $rendered );
        $this->assertStringContainsString( 'media/', $rendered );
        $this->assertStringContainsString( '_wpnonce=', $rendered, 'the delivery URL carries the nonce that makes it session-bound' );
        $this->assertStringNotContainsString( 'wp-content/uploads', $rendered );
    }

    public function test_an_off_install_url_is_left_alone(): void {
        // A club CDN or a gravatar is not ours to move — and deleting it
        // would be worse than leaving it.
        $media_id = PlayerPhotoImporter::fromUploadsUrl( $this->player, 'https://cdn.example.test/elsewhere.jpg' );

        $this->assertSame( 0, $media_id );
    }

    public function test_a_missing_file_loses_nothing(): void {
        $uploads = wp_get_upload_dir();
        $url     = $uploads['baseurl'] . '/tt-tests/not-here.jpg';

        $media_id = PlayerPhotoImporter::fromUploadsUrl( $this->player, $url );

        $this->assertSame( 0, $media_id, 'a photo that cannot be moved must not also be lost' );
    }

    public function test_a_non_image_is_refused(): void {
        $uploads = wp_get_upload_dir();
        $dir     = $uploads['basedir'] . '/tt-tests';
        wp_mkdir_p( $dir );
        $path = $dir . '/notes.txt';
        file_put_contents( $path, 'not an image' );

        $media_id = PlayerPhotoImporter::fromUploadsUrl( $this->player, $uploads['baseurl'] . '/tt-tests/notes.txt' );

        $this->assertSame( 0, $media_id );
        $this->assertFileExists( $path, 'refusing to import is not licence to delete' );
        @unlink( $path );
    }

    /* ---- print and export -------------------------------------------- */

    public function test_print_output_inlines_the_bytes(): void {
        // Print and PDF go to a renderer that may hold no session, so a
        // session-bound URL would print as a broken image.
        [ $url ] = $this->seedUploadsImage();
        $media_id = PlayerPhotoImporter::fromUploadsUrl( $this->player, $url );
        $this->setColumn( 'photo_media_id', (string) $media_id );
        $this->setColumn( 'photo_url', '' );

        $uri = PlayerPhoto::dataUri( $this->playerRow() );

        $this->assertStringStartsWith( 'data:image/', $uri );
        $this->assertStringContainsString( ';base64,', $uri );
    }

    /* ---- fixtures ----------------------------------------------------- */

    private function playerRow(): object {
        global $wpdb;
        return (object) $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->p}tt_players WHERE id = %d", $this->player ),
            ARRAY_A
        );
    }

    private function setColumn( string $column, string $value ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_players", [ $column => $value ], [ 'id' => $this->player ] );
    }

    /** @return array{0:string,1:string} url, absolute path */
    private function seedUploadsImage(): array {
        $uploads = wp_get_upload_dir();
        $dir     = $uploads['basedir'] . '/tt-tests';
        wp_mkdir_p( $dir );

        $path = $dir . '/face-' . wp_generate_password( 6, false ) . '.png';

        // A real 1x1 PNG, so wp_check_filetype and getimagesize agree.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        file_put_contents( $path, $png );

        return [ $uploads['baseurl'] . '/tt-tests/' . basename( $path ), $path ];
    }

    private function seedPlayer(): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 Photo' ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team,
            'first_name' => 'Photo',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }
}
