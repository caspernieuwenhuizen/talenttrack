<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Methodology\MethodologyScope;
use TT\Modules\Methodology\Repositories\SubPrinciplesRepository;

/**
 * #2369 (epic #2316 follow-up) — first-class sub-principles + the JO13-1
 * formation-diagram fix.
 *
 * The bootstrap runs every plugin migration once, so migrations 0205
 * (schema) and 0206 (data) have already created the table, fixed the
 * `1-4-3-3-jo13` formation diagram and seeded the JO13 sub-principles.
 * This suite locks that contract.
 */
final class MethodologySubPrinciplesTest extends WP_UnitTestCase {

    private int $jo13SetId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        MethodologyScope::reset();

        $this->jo13SetId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_methodologies WHERE club_id = 1 AND slug = 'jo13-1-hedel' LIMIT 1"
        );
    }

    public function tear_down(): void {
        MethodologyScope::reset();
        parent::tear_down();
    }

    public function test_jo13_formation_diagram_has_full_1_4_3_3(): void {
        global $wpdb;
        $json = (string) $wpdb->get_var(
            "SELECT diagram_data_json FROM {$wpdb->prefix}tt_formations WHERE slug = '1-4-3-3-jo13' LIMIT 1"
        );
        $this->assertNotSame( '', $json, 'the JO13 formation must now carry a diagram' );

        $decoded = json_decode( $json, true );
        $this->assertIsArray( $decoded );
        $this->assertArrayHasKey( 'positions', $decoded );
        $positions = $decoded['positions'];
        $this->assertCount( 11, $positions, 'a 1-4-3-3 has 11 positions' );

        // The striker (9) sits near the top (opponent goal), the keeper (1)
        // near the bottom (own goal) in the 0–140 y coordinate system.
        $this->assertLessThan( 40, (int) $positions['9']['y'], 'the striker is near the top' );
        $this->assertGreaterThan( 100, (int) $positions['1']['y'], 'the keeper is near the bottom' );
    }

    public function test_jo13_sub_principles_seeded_and_scoped(): void {
        MethodologyScope::set( $this->jo13SetId );
        $rows = ( new SubPrinciplesRepository() )->listFiltered();
        $this->assertNotEmpty( $rows, 'JO13 sub-principles must be returned when the set is active' );

        $lines = array_unique( array_map( static fn ( $r ) => (string) $r->line_key, $rows ) );
        $this->assertContains( 'aanvallers', $lines );
        $this->assertContains( 'middenvelders', $lines );
        $this->assertContains( 'verdedigers', $lines );
    }

    public function test_sub_principles_grouped_by_phase(): void {
        MethodologyScope::set( $this->jo13SetId );
        $defending1 = ( new SubPrinciplesRepository() )->listFiltered( [
            'phase_side'   => 'defending',
            'phase_number' => 1,
        ] );
        $this->assertNotEmpty( $defending1, 'defending phase 1 must have sub-principles' );
        foreach ( $defending1 as $r ) {
            $this->assertSame( 'defending', (string) $r->phase_side );
            $this->assertSame( 1, (int) $r->phase_number );
        }
    }

    public function test_created_sub_principle_is_stamped_with_active_methodology(): void {
        MethodologyScope::set( $this->jo13SetId );
        $repo = new SubPrinciplesRepository();
        $id = $repo->create( [
            'phase_side'   => 'attacking',
            'phase_number' => 1,
            'line_key'     => 'aanvallers',
            'title_json'   => [ 'nl' => 'Test', 'en' => 'Test' ],
        ] );
        $this->assertGreaterThan( 0, $id );

        $row = $repo->find( $id );
        $this->assertNotNull( $row );
        $this->assertSame( $this->jo13SetId, (int) $row->methodology_id, 'create must stamp the active methodology_id' );
        $this->assertNotEmpty( $row->uuid, 'create must generate a uuid' );
    }
}
