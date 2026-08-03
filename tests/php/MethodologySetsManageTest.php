<?php
namespace TT\Tests\Php;

use TT\Modules\Methodology\ActiveMethodologyResolver;
use TT\Modules\Methodology\Repositories\MethodologiesRepository;
use WP_UnitTestCase;

/**
 * #2320 (epic #2316) — methodology-set management write side.
 *
 * The bootstrap runs every plugin migration, so migration 0200 has
 * already seeded a shipped default set ("JO14-1 Hedel") for club 1. This
 * suite locks the write methods MethodologiesRepository grew for the
 * admin surface: create, setDefault (which also moves the install
 * default) and the archive guard against shipped sets.
 */
final class MethodologySetsManageTest extends WP_UnitTestCase {

    private MethodologiesRepository $repo;

    public function set_up(): void {
        parent::set_up();
        $this->repo = new MethodologiesRepository();
        ActiveMethodologyResolver::setRepository( null );
    }

    public function test_create_returns_id_and_scopes_row_to_club(): void {
        global $wpdb;

        $id = $this->repo->create( [
            'name'        => [ 'nl' => 'Vierhoek', 'en' => 'Diamond' ],
            'description' => [ 'nl' => 'Alt.', 'en' => 'Alt.' ],
        ] );
        $this->assertGreaterThan( 0, $id, 'create returns the new id' );

        $row = $this->repo->find( $id );
        $this->assertNotNull( $row, 'the row is readable back' );
        $this->assertSame( 1, (int) $row->club_id, 'the row is club-scoped' );
        $this->assertSame( 'vierhoek', (string) $row->slug, 'slug derives from the NL name when blank' );
        $this->assertNotEmpty( $row->uuid, 'a uuid is stamped on create' );

        $name = json_decode( (string) $row->name_json, true );
        $this->assertSame( 'Vierhoek', $name['nl'] ?? null );
        $this->assertSame( 'Diamond', $name['en'] ?? null );
    }

    public function test_set_default_moves_flag_and_updates_install_default(): void {
        $shipped_id = $this->repo->defaultId();
        $this->assertGreaterThan( 0, $shipped_id );

        $new_id = $this->repo->create( [ 'name' => [ 'nl' => 'Nieuwe set', 'en' => 'New set' ] ] );
        $this->assertGreaterThan( 0, $new_id );

        $this->assertTrue( $this->repo->setDefault( $new_id ) );

        // is_default moved to the new set, cleared on the old one.
        $this->assertSame( 1, (int) $this->repo->find( $new_id )->is_default );
        $this->assertSame( 0, (int) $this->repo->find( $shipped_id )->is_default );

        // The install-wide active pointer follows the new default.
        $this->assertSame( $new_id, ActiveMethodologyResolver::forInstall() );
    }

    public function test_archive_refuses_a_shipped_set(): void {
        $shipped_id = $this->repo->defaultId();
        $this->assertGreaterThan( 0, $shipped_id );

        $row = $this->repo->find( $shipped_id );
        $this->assertSame( 1, (int) $row->is_shipped, 'the seeded default is shipped' );

        $this->assertFalse( $this->repo->archive( $shipped_id ), 'a shipped set cannot be archived' );
        $this->assertNotNull( $this->repo->find( $shipped_id ), 'the shipped set is still active' );
    }
}
