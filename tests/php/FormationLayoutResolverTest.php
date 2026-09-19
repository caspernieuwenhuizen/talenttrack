<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Services\PitchLayoutService;
use TT\Modules\MatchPrep\Services\FormationLayoutResolver;

/**
 * #3574 — a small-sided team's line-up is drawn on its own shape.
 *
 * The small-sided templates migration 0243 seeds carry no slot numbers, and
 * no default layout knew their shapes, so every surface fell back to an
 * eleven-a-side pitch — each to a different one. An 8v8 team's eight players
 * filled slots 1–8 of a 4-3-3 and three attacking slots stood empty. All
 * five surfaces now resolve through `FormationLayoutResolver`.
 */
final class FormationLayoutResolverTest extends WP_UnitTestCase {

    /**
     * @dataProvider smallSidedShapes
     * @param list<string> $labels
     */
    public function test_a_bound_small_sided_template_draws_its_own_slots( string $shape, array $labels ): void {
        $template = $this->seededTemplate( $shape );

        $layout = FormationLayoutResolver::layoutFor( $template );

        $this->assertCount( count( $labels ), $layout );
        $this->assertEqualsCanonicalizing( $labels, array_column( $layout, 'label' ) );
        $this->assertEqualsCanonicalizing( range( 1, count( $labels ) ), array_column( $layout, 'num' ) );
    }

    /** @return array<string, array{0:string, 1:list<string>}> */
    public function smallSidedShapes(): array {
        return [
            '8v8 3-3-1' => [ '3-3-1', [ 'GK', 'LB', 'CB', 'RB', 'LM', 'CM', 'RM', 'ST' ] ],
            '8v8 3-2-2' => [ '3-2-2', [ 'GK', 'LB', 'CB', 'RB', 'LCM', 'RCM', 'LF', 'RF' ] ],
            '6v6 2-3-1' => [ '2-3-1', [ 'LB', 'RB', 'LM', 'CM', 'RM', 'ST' ] ],
            '6v6 3-2-1' => [ '3-2-1', [ 'LB', 'CB', 'RB', 'LM', 'RM', 'ST' ] ],
        ];
    }

    public function test_the_keeper_is_slot_one_in_eight_a_side_and_absent_in_six(): void {
        $eight = array_column( FormationLayoutResolver::layoutFor( $this->seededTemplate( '3-3-1' ) ), 'label', 'num' );
        $this->assertSame( 'GK', $eight[1] );

        $six = FormationLayoutResolver::layoutFor( $this->seededTemplate( '3-2-1' ) );
        $this->assertNotContains( 'GK', array_column( $six, 'label' ) );
    }

    public function test_an_unbound_prep_follows_the_teams_football_form(): void {
        $this->assertSame( '3-3-1', FormationLayoutResolver::shapeFor( 0, $this->team( '8v8' ) ) );
        $this->assertSame( '3-2-1', FormationLayoutResolver::shapeFor( 0, $this->team( '6v6' ) ) );
        $this->assertSame( '4-3-3', FormationLayoutResolver::shapeFor( 0, $this->team( '11v11' ) ) );
        $this->assertSame( '4-3-3', FormationLayoutResolver::shapeFor( 0, 0 ) );

        $this->assertCount( 8, FormationLayoutResolver::layoutFor( 0, $this->team( '8v8' ) ) );
    }

    public function test_a_templates_own_numbered_slots_still_win(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_formation_templates", [
            'name'            => 'Custom diamond',
            'formation_shape' => '3-3-1',
            'football_form'   => '8v8',
            'slots_json'      => (string) wp_json_encode( [
                [ 'num' => 1, 'label' => 'GK', 'pos' => [ 'x' => 0.5, 'y' => 0.9 ] ],
                [ 'num' => 2, 'label' => 'DM', 'pos' => [ 'x' => 0.5, 'y' => 0.6 ] ],
            ] ),
            'is_seeded'       => 0,
        ] );

        $layout = FormationLayoutResolver::layoutFor( (int) $wpdb->insert_id, $this->team( '8v8' ) );

        $this->assertSame( [ 'GK', 'DM' ], array_column( $layout, 'label' ) );
    }

    /**
     * The acceptance case through the live sheet's own service: a prep bound
     * to Small-sided 3-3-1 with eight players returns eight slots, every
     * player placed, and none of the 4-3-3's attacking labels.
     */
    public function test_the_live_sheet_draws_an_eight_a_side_lineup_on_eight_slots(): void {
        $team          = $this->team( '8v8' );
        $slot_to_player = [];
        $meta           = [];
        for ( $slot = 1; $slot <= 8; $slot++ ) {
            $slot_to_player[ $slot ] = 1000 + $slot;
            $meta[ 1000 + $slot ]    = [ 'name' => 'Player ' . $slot, 'jersey' => $slot ];
        }

        $slots = ( new PitchLayoutService() )->positionedXi( $this->seededTemplate( '3-3-1' ), $slot_to_player, $meta, $team );

        $this->assertCount( 8, $slots );
        $this->assertEqualsCanonicalizing( array_values( $slot_to_player ), array_column( $slots, 'player_id' ) );
        foreach ( [ 'LW', 'AM', 'RW' ] as $eleven_a_side_only ) {
            $this->assertNotContains( $eleven_a_side_only, array_column( $slots, 'label' ) );
        }
    }

    private function seededTemplate( string $shape ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_formation_templates WHERE formation_shape = %s AND is_seeded = 1 ORDER BY id LIMIT 1",
            $shape
        ) );
        $this->assertGreaterThan( 0, $id, "migration 0243 seeds a {$shape} template" );
        return $id;
    }

    private function team( string $form ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'club_id'       => (int) CurrentClub::id(),
            'name'          => 'Team ' . $form,
            'football_form' => $form,
        ] );
        return (int) $wpdb->insert_id;
    }
}
