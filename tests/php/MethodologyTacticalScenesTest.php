<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Methodology\MethodologyScope;
use TT\Modules\Methodology\Repositories\TacticalScenesRepository;

/**
 * #2323 (epic #2316) — per-phase animated tactical scenes.
 *
 * The bootstrap runs every plugin migration once, so migration 0204 has
 * already created `tt_methodology_tactical_scenes` and seeded the shipped
 * JO13-1 Hedel scenes. This suite locks that contract: scenes exist for
 * the jo13-1-hedel set and surface when it is the active scope, the
 * `scene_json` animation payload round-trips, and a repo create stamps
 * the active methodology set.
 */
final class MethodologyTacticalScenesTest extends WP_UnitTestCase {

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

    public function test_scenes_seeded_for_jo13_set(): void {
        $this->assertGreaterThan( 0, $this->jo13SetId, 'jo13-1-hedel set must exist' );

        MethodologyScope::set( $this->jo13SetId );
        $scenes = ( new TacticalScenesRepository() )->listFiltered();

        $this->assertGreaterThanOrEqual( 5, count( $scenes ), 'at least five shipped scenes seeded' );
        foreach ( $scenes as $s ) {
            $this->assertSame( $this->jo13SetId, (int) $s->methodology_id );
            $this->assertSame( 1, (int) $s->is_shipped );
        }
    }

    public function test_scenes_cover_distinct_phase_sides(): void {
        MethodologyScope::set( $this->jo13SetId );
        $sides = array_unique( array_map(
            static fn( $s ) => (string) $s->phase_side,
            ( new TacticalScenesRepository() )->listForMethodology( $this->jo13SetId )
        ) );

        $this->assertContains( 'defending', $sides );
        $this->assertContains( 'attacking', $sides );
        $this->assertContains( 'transition', $sides );
    }

    public function test_scene_json_round_trips_to_animation_payload(): void {
        MethodologyScope::set( $this->jo13SetId );
        $scenes = ( new TacticalScenesRepository() )->listFiltered();
        $this->assertNotEmpty( $scenes );

        $scene = $scenes[0];
        $this->assertIsArray( $scene->scene_decoded );
        $this->assertArrayHasKey( 'duration_ms', $scene->scene_decoded );
        $this->assertArrayHasKey( 'players', $scene->scene_decoded );
        $this->assertGreaterThan( 0, (int) $scene->scene_decoded['duration_ms'] );
        $this->assertIsArray( $scene->scene_decoded['players'] );

        // A player keyframe carries normalized x/y coordinates.
        $player = $scene->scene_decoded['players'][0];
        $this->assertArrayHasKey( 'keyframes', $player );
        $this->assertArrayHasKey( 'x', $player['keyframes'][0] );
        $this->assertArrayHasKey( 'y', $player['keyframes'][0] );
    }

    public function test_phase_filter_narrows_results(): void {
        MethodologyScope::set( $this->jo13SetId );
        $defending = ( new TacticalScenesRepository() )->listFiltered( [ 'phase_side' => 'defending' ] );
        $this->assertNotEmpty( $defending );
        foreach ( $defending as $s ) {
            $this->assertSame( 'defending', (string) $s->phase_side );
        }
    }

    public function test_create_stamps_the_active_set(): void {
        MethodologyScope::set( $this->jo13SetId );
        $repo = new TacticalScenesRepository();
        $id   = $repo->create( [
            'phase_side'       => 'attacking',
            'phase_number'     => 1,
            'title_json'       => [ 'nl' => 'Test scene', 'en' => 'Test scene' ],
            'description_json' => [ 'nl' => 'Coaching', 'en' => 'Coaching' ],
            'scene_json'       => [ 'duration_ms' => 4000, 'players' => [], 'ball' => [ 'keyframes' => [] ] ],
        ] );
        $this->assertGreaterThan( 0, $id );

        $row = $repo->find( $id );
        $this->assertNotNull( $row );
        $this->assertSame( $this->jo13SetId, (int) $row->methodology_id );
        $this->assertSame( 0, (int) $row->is_shipped );
        $this->assertSame( 4000, (int) $row->scene_decoded['duration_ms'] );
    }
}
