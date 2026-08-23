<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Shared\Frontend\FrontendActivitiesManageView;

/**
 * #2686 — which activity types get the per-fixture surfaces.
 *
 * Match preparation and the live-match screen hold one line-up, one
 * availability list and one set of player goals **per activity**. A
 * tournament is usually several games in one day, so offering them there
 * would describe a whole tournament as a single fixture — silently. The
 * button was offered and the destination refused it, which is how the bug
 * was found; the gate is what makes both ends agree.
 *
 * Frozen here because the list has already drifted once: #2253 added
 * tournaments to the button while the view kept refusing them.
 */
final class FixtureSurfaceGateTest extends WP_UnitTestCase {

    private function offers( string $type_key ): bool {
        $method = new \ReflectionMethod( FrontendActivitiesManageView::class, 'offersFixtureSurfaces' );
        $method->setAccessible( true );

        return (bool) $method->invoke( null, $type_key );
    }

    public function test_a_game_gets_match_prep_and_the_live_match_screen(): void {
        $this->assertTrue( $this->offers( ActivityTypeKey::GAME ) );
    }

    /** `match` is the legacy synonym for `game` and still in the data. */
    public function test_the_legacy_match_key_is_accepted(): void {
        $this->assertTrue( $this->offers( 'match' ) );
        $this->assertTrue( $this->offers( 'MATCH' ), 'the stored value is not case-normalised everywhere' );
    }

    public function test_a_tournament_does_not(): void {
        $this->assertFalse(
            $this->offers( ActivityTypeKey::TOURNAMENT ),
            'a tournament day is several fixtures; one prep row cannot describe it'
        );
    }

    public function test_nothing_else_does_either(): void {
        foreach ( [ ActivityTypeKey::TRAINING, ActivityTypeKey::MEETING, ActivityTypeKey::OTHER, '' ] as $type ) {
            $this->assertFalse( $this->offers( $type ), "type '$type' must not offer the fixture surfaces" );
        }
    }
}
