<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Privacy\CorePiiRegistrations;
use TT\Infrastructure\Privacy\PlayerDataMap;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\GdprSubjectAccessZipExporter;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;

/**
 * #2743 — a player's photographs in a subject-access export.
 *
 * The media library never registered with the PII registry, so an Article
 * 15 export omitted photographs entirely while the academy still held
 * them. Erasure was never affected — `PlayerDeletionCascade` handles media
 * through its own explicit path — so what is pinned here is the access
 * side, plus the thing that makes it dangerous to fix carelessly: the link
 * table is polymorphic, and a registration that forgets to narrow it would
 * pull other records' media into an individual's export.
 */
final class MediaSubjectAccessTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        CorePiiRegistrations::register();
    }

    public function test_media_is_registered_against_the_link_table(): void {
        $this->assertTrue(
            PlayerDataMap::isRegistered( 'tt_media_links' ),
            'without this an export silently omits every photograph'
        );

        $reg = null;
        foreach ( PlayerDataMap::all() as $row ) {
            if ( $row['table'] === 'tt_media_links' ) $reg = $row;
        }

        $this->assertNotNull( $reg );
        $this->assertSame( 'entity_id', $reg['player_id_column'] );
        $this->assertSame(
            [ 'entity_type' => 'player' ],
            $reg['match'],
            'entity_id is only a player id for player links — without this it counts team and activity media too'
        );
    }

    /**
     * The whole reason the match condition exists. A team photo and another
     * player's photo must not be counted against this player.
     */
    public function test_the_row_count_ignores_media_belonging_to_other_records(): void {
        $player = $this->makePlayer();
        $other  = $this->makePlayer();
        $links  = new MediaLinksRepository();

        $links->link( $this->makeMedia( 'Theirs' )->id, MediaEntityType::PLAYER, $other );
        $links->link( $this->makeMedia( 'A team shot' )->id, MediaEntityType::TEAM, $player );

        $this->assertSame( 0, $this->mediaCountFor( $player ), 'team media is not this player\'s media' );

        $links->link( $this->makeMedia( 'Ours' )->id, MediaEntityType::PLAYER, $player );

        $this->assertSame( 1, $this->mediaCountFor( $player ) );
    }

    public function test_the_export_lists_this_players_media_and_nobody_elses(): void {
        $player = $this->makePlayer();
        $other  = $this->makePlayer();
        $links  = new MediaLinksRepository();

        $links->link( $this->makeMedia( 'Ours one' )->id, MediaEntityType::PLAYER, $player );
        $links->link( $this->makeMedia( 'Ours two' )->id, MediaEntityType::PLAYER, $player );
        $links->link( $this->makeMedia( 'Theirs' )->id, MediaEntityType::PLAYER, $other );
        $links->link( $this->makeMedia( 'Team shot' )->id, MediaEntityType::TEAM, $player );

        $out = ( new GdprSubjectAccessZipExporter() )->collect( $this->requestFor( $player ) );

        $this->assertArrayHasKey( 'media.json', $out['entries'] );
        $media = json_decode( (string) $out['entries']['media.json'], true );

        $titles = array_column( (array) ( $media['items'] ?? [] ), 'title' );
        sort( $titles );

        $this->assertSame( [ 'Ours one', 'Ours two' ], $titles );
        $this->assertSame( 2, $out['manifest']['counts']['media'] );
    }

    /**
     * Metadata only was a deliberate decision, not an omission — the
     * manifest and the README have to say so, or a listing reads as
     * "the academy holds nothing".
     */
    public function test_the_export_says_the_files_are_not_included(): void {
        $player = $this->makePlayer();
        ( new MediaLinksRepository() )->link(
            $this->makeMedia( 'A photo' )->id,
            MediaEntityType::PLAYER,
            $player
        );

        $out   = ( new GdprSubjectAccessZipExporter() )->collect( $this->requestFor( $player ) );
        $media = json_decode( (string) $out['entries']['media.json'], true );

        $this->assertFalse( $media['files_included'] );
        $this->assertNotSame( '', (string) ( $media['note'] ?? '' ) );
        $this->assertStringContainsString( 'media.json', (string) $out['entries']['README.txt'] );
        $this->assertArrayHasKey( 'media_note', $out['manifest'] );
    }

    public function test_a_player_with_no_media_still_gets_the_entry(): void {
        $out   = ( new GdprSubjectAccessZipExporter() )->collect( $this->requestFor( $this->makePlayer() ) );
        $media = json_decode( (string) $out['entries']['media.json'], true );

        $this->assertSame( [], $media['items'] );
        $this->assertSame( 0, $out['manifest']['counts']['media'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function mediaCountFor( int $player_id ): int {
        foreach ( PlayerDataMap::rowCountsForPlayer( $player_id ) as $row ) {
            if ( $row['table'] === 'tt_media_links' ) return (int) $row['count'];
        }
        return -1;
    }

    private function requestFor( int $player_id ): ExportRequest {
        return new ExportRequest(
            'gdpr_subject_access_zip',
            'zip',
            1,
            (int) self::factory()->user->create( [ 'role' => 'administrator' ] ),
            null,
            [ 'player_id' => $player_id ]
        );
    }

    private function makePlayer(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Subject',
            'last_name'  => 'Access',
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeMedia( string $title ): object {
        $repo = new MediaRepository();
        return $repo->find( $repo->insert( [
            'kind'            => 'image',
            'title'           => $title,
            'storage_adapter' => 'local_private',
            'storage_key'     => 'ab/cd/' . str_repeat( 'a', 32 ) . '.jpg',
            'mime_type'       => 'image/jpeg',
            'captured_at'     => '2026-08-14 18:00:00',
        ] ) );
    }
}
