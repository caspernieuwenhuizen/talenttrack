<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;
use TT\Modules\Vct\Validation\VctMacroBlockValidator;

/**
 * #2322 (epic #2316) — per-week speelwijze theme on the VCT periodisation
 * cycle.
 *
 * Two contracts:
 *   1. The macro-block repository round-trips an optional per-week
 *      `tactical_theme` through `replaceForSeason()` → `listForSeason()`.
 *   2. The shared validator rejects a week whose `tactical_theme` is not a
 *      canonical `vct_tactical_theme` vocabulary key (the guard the REST
 *      PUT layer enforces), while accepting a known key and null.
 */
final class MethodologyPeriodisationTest extends WP_UnitTestCase {

    private int $seasonId = 0;

    public function set_up(): void {
        parent::set_up();
        $this->seasonId = ( new SeasonsRepository() )->create( [
            'name'       => 'Periodisation Test Season',
            'start_date' => '2030-08-01',
            'end_date'   => '2031-06-30',
        ] );
    }

    public function test_tactical_theme_round_trips_through_the_repository(): void {
        $this->assertGreaterThan( 0, $this->seasonId, 'season fixture must be created' );

        $repo = new VctMacroBlocksRepository();
        $ok = $repo->replaceForSeason( 0, $this->seasonId, [
            [
                'sequence'      => 1,
                'label'         => 'Themed block',
                'start_date'    => '2030-08-01',
                'end_date'      => '2030-08-28',
                'phase_profile' => [
                    [ 'week' => 1, 'phase' => 'introductie', 'multiplier' => 0.85, 'tactical_theme' => 'build_up' ],
                    [ 'week' => 2, 'phase' => 'opbouw',      'multiplier' => 1.00, 'tactical_theme' => 'defending' ],
                    [ 'week' => 3, 'phase' => 'deload',      'multiplier' => 0.70 ],
                ],
            ],
        ] );
        $this->assertTrue( $ok, 'replaceForSeason must persist the themed block' );

        $blocks = $repo->listForSeason( 0, $this->seasonId );
        $this->assertCount( 1, $blocks );

        $weeks = $blocks[0]['phase_profile'];
        $this->assertCount( 3, $weeks );

        // The per-week theme survives the round-trip.
        $this->assertSame( 'build_up', $weeks[0]['tactical_theme'] );
        $this->assertSame( 'defending', $weeks[1]['tactical_theme'] );

        // A week with no theme normalises to null (not absent), so consumers
        // can treat "no theme" uniformly.
        $this->assertArrayHasKey( 'tactical_theme', $weeks[2] );
        $this->assertNull( $weeks[2]['tactical_theme'] );

        // The conditioning phase + multiplier are untouched.
        $this->assertSame( 'introductie', $weeks[0]['phase'] );
        $this->assertSame( 0.85, $weeks[0]['multiplier'] );
    }

    public function test_validator_accepts_known_theme_and_null(): void {
        $blocks = VctMacroBlockValidator::normalise( [
            [
                'sequence'      => 1,
                'label'         => 'Valid',
                'start_date'    => '2030-08-01',
                'end_date'      => '2030-08-14',
                'phase_profile' => [
                    [ 'week' => 1, 'phase' => 'opbouw', 'multiplier' => 1.0, 'tactical_theme' => 'possession' ],
                    [ 'week' => 2, 'phase' => 'deload', 'multiplier' => 0.7, 'tactical_theme' => null ],
                ],
            ],
        ] );
        $this->assertNull( VctMacroBlockValidator::validate( $blocks ) );
    }

    public function test_validator_rejects_unknown_theme(): void {
        $blocks = VctMacroBlockValidator::normalise( [
            [
                'sequence'      => 1,
                'label'         => 'Bad theme',
                'start_date'    => '2030-08-01',
                'end_date'      => '2030-08-14',
                'phase_profile' => [
                    [ 'week' => 1, 'phase' => 'opbouw', 'multiplier' => 1.0, 'tactical_theme' => 'not_a_real_theme' ],
                ],
            ],
        ] );
        $err = VctMacroBlockValidator::validate( $blocks );
        $this->assertNotNull( $err, 'an unknown speelwijze theme must be rejected' );
        $this->assertStringContainsString( 'not_a_real_theme', (string) $err );
    }
}
