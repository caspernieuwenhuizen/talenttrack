<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use ReflectionMethod;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\PlayerProfileGenerator;

/**
 * #3404 — the prior-spell ladder was a hardcoded `JO8 … JO19` constant while
 * every install seeds `U7 … U23`. `array_search()` therefore returned false
 * for every generated team, `$prior` was always 0, and no demo player ever
 * got a prior age-group spell on any install.
 *
 * The ladder now comes from the academy's own teams. These assertions pin the
 * notation-independence, which is the property that was missing.
 */
final class DemoAgeGroupLadderTest extends WP_UnitTestCase {

    /** @param list<string> $age_groups */
    private function ladderFor( array $age_groups ): array {
        $teams = [];
        foreach ( $age_groups as $i => $ag ) {
            $teams[] = (object) [ 'id' => $i + 1, 'age_group' => $ag ];
        }

        $gen = new PlayerProfileGenerator( new DemoBatchRegistry( 'test-ladder' ), [], $teams, 8 );

        $m = new ReflectionMethod( PlayerProfileGenerator::class, 'ladder' );
        $m->setAccessible( true );
        return (array) $m->invoke( $gen );
    }

    public function test_u_notation_sorts_youngest_first(): void {
        $this->assertSame(
            [ 'U9', 'U11', 'U13', 'U14' ],
            $this->ladderFor( [ 'U13', 'U9', 'U14', 'U11' ] ),
            'the vocabulary every install actually seeds'
        );
    }

    public function test_numeric_order_beats_string_order(): void {
        // The bug this guards: sorting these as strings puts U11 before U9.
        $this->assertSame( [ 'U9', 'U11' ], $this->ladderFor( [ 'U11', 'U9' ] ) );
    }

    public function test_jo_notation_still_works(): void {
        $this->assertSame(
            [ 'JO10', 'JO12', 'JO14' ],
            $this->ladderFor( [ 'JO14', 'JO10', 'JO12' ] ),
            'the notation the old constant assumed must not break now that it is gone'
        );
    }

    public function test_dutch_o_notation_works(): void {
        $this->assertSame( [ 'O8', 'O10', 'O14' ], $this->ladderFor( [ 'O14', 'O8', 'O10' ] ) );
    }

    public function test_senior_sorts_last(): void {
        $this->assertSame(
            [ 'U17', 'U19', 'Senior' ],
            $this->ladderFor( [ 'Senior', 'U19', 'U17' ] ),
            'a rung with no age in its label belongs at the top of the ladder'
        );
    }

    public function test_only_age_groups_that_exist_appear(): void {
        $ladder = $this->ladderFor( [ 'U12', 'U12', 'U14' ] );
        $this->assertSame( [ 'U12', 'U14' ], $ladder, 'duplicates collapse' );
    }

    public function test_teams_without_an_age_group_are_skipped(): void {
        $teams = [
            (object) [ 'id' => 1, 'age_group' => 'U12' ],
            (object) [ 'id' => 2, 'age_group' => '' ],
            (object) [ 'id' => 3 ],
        ];
        $gen = new PlayerProfileGenerator( new DemoBatchRegistry( 'test-ladder' ), [], $teams, 8 );
        $m   = new ReflectionMethod( PlayerProfileGenerator::class, 'ladder' );
        $m->setAccessible( true );

        $this->assertSame( [ 'U12' ], (array) $m->invoke( $gen ) );
    }

    public function test_a_u_team_finds_a_rung_below_it(): void {
        $ladder = $this->ladderFor( [ 'U11', 'U12', 'U13', 'U14' ] );
        $rung   = array_search( 'U14', $ladder, true );

        $this->assertNotFalse( $rung, 'the lookup that silently failed for years' );
        $this->assertSame( 3, $rung );
        $this->assertSame( 'U13', $ladder[ $rung - 1 ] );
    }
}
