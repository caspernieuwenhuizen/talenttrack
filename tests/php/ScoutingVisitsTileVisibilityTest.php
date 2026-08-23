<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #2007 — the Scouting visits tile is the scout's, the onboarding funnel is
 * the head coach's, and they must be able to move independently.
 *
 * Both tiles used to authorise on `prospects`, which made them inseparable:
 * #0081 gave the head coach `prospects:[r,team]` on purpose so they could read
 * their own age group's funnel, so hiding the visits tile by dropping that
 * grant would have removed the funnel too.
 *
 * What is pinned here is the separation itself, because the failure it guards
 * against is a silent one: someone "simplifying" the two tiles back onto a
 * single entity would reintroduce the coupling with no visible symptom until a
 * head coach loses their funnel or gains a scouting tile.
 */
final class ScoutingVisitsTileVisibilityTest extends WP_UnitTestCase {

    /** @return array<int, array<string, mixed>> */
    private function seed(): array {
        $rows = require TT_PLUGIN_DIR . 'config/authorization_seed.php';
        return is_array( $rows ) ? $rows : [];
    }

    /** @return list<string> */
    private function personasFor( string $entity ): array {
        $out = [];
        foreach ( $this->seed() as $row ) {
            if ( ( $row['entity'] ?? '' ) === $entity ) {
                $out[] = (string) ( $row['persona'] ?? '' );
            }
        }
        return array_values( array_unique( $out ) );
    }

    public function test_the_visits_tile_declares_its_own_entity(): void {
        $entity = \TT\Shared\Tiles\TileRegistry::entityForViewSlug( 'scouting-visits' );

        $this->assertSame(
            'scouting_visits_panel',
            $entity,
            'the tile must not share prospects with the onboarding funnel, or the two cannot be gated apart'
        );
    }

    public function test_the_onboarding_funnel_still_rides_on_prospects(): void {
        $entity = \TT\Shared\Tiles\TileRegistry::entityForViewSlug( 'onboarding-pipeline' );

        $this->assertSame(
            'prospects',
            $entity,
            '#0081 gave the head coach prospects at team scope precisely so this funnel resolves'
        );
    }

    public function test_head_coach_keeps_the_funnel_but_not_the_visits_tile(): void {
        $visits = $this->personasFor( 'scouting_visits_panel' );

        $this->assertNotContains(
            'head_coach',
            $visits,
            'the head coach must not see the scout\'s outbound visits — this is the whole issue'
        );

        $prospects = $this->personasFor( 'prospects' );
        $this->assertContains(
            'head_coach',
            $prospects,
            'removing this grant would take the #0081 onboarding funnel with it'
        );
    }

    public function test_the_personas_whose_tile_it_is_keep_it(): void {
        $visits = $this->personasFor( 'scouting_visits_panel' );

        foreach ( [ 'scout', 'head_of_development', 'academy_admin' ] as $persona ) {
            $this->assertContains(
                $persona,
                $visits,
                "{$persona} runs or oversees scouting visits and must keep the tile"
            );
        }
    }

    /**
     * A tile-visibility entity with no seed at all is the #1143 phantom: the
     * matrix-dispatch gate resolves the entity, finds nothing, and denies
     * every non-administrator — which reads as "the feature is broken".
     */
    public function test_the_new_entity_is_actually_seeded(): void {
        $this->assertNotEmpty(
            $this->personasFor( 'scouting_visits_panel' ),
            'an unseeded tile entity denies everyone but WP admins'
        );
    }
}
