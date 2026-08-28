<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;

/**
 * #3028 — the band's better-side edge is open, and which side that is
 * depends on the test's direction.
 *
 * The reported case: a 30m sprint for O12 with green 4.950–5.100 and amber
 * 5.100–5.250, direction `lower`. Closed-interval maths put a 4.84s run —
 * the fastest in the squad — outside every band and flagged it red, while a
 * 5.06s run sat inside green. The report table showed the squad's colours
 * inverted.
 */
final class MeasurementTargetDirectionFlagTest extends WP_UnitTestCase {

    private MeasurementTargetsRepository $repo;

    public function set_up(): void {
        parent::set_up();
        $this->repo = new MeasurementTargetsRepository();
    }

    /** The reported sprint target: lower is better. */
    private function sprintTarget(): object {
        return (object) [
            'green_min' => 4.950,
            'green_max' => 5.100,
            'amber_min' => 5.100,
            'amber_max' => 5.250,
        ];
    }

    /** Mirror image: higher is better. */
    private function jumpTarget(): object {
        return (object) [
            'green_min' => 40.0,
            'green_max' => 50.0,
            'amber_min' => 30.0,
            'amber_max' => 40.0,
        ];
    }

    public function test_lower_is_better_treats_beating_the_band_as_green(): void {
        $t = $this->sprintTarget();
        // The regression: both of these used to come back 'bad'.
        $this->assertSame( 'ok', $this->repo->flagFor( 4.84, $t, 'lower' ) );
        $this->assertSame( 'ok', $this->repo->flagFor( 4.85, $t, 'lower' ) );
        // Inside the band, unchanged.
        $this->assertSame( 'ok', $this->repo->flagFor( 5.02, $t, 'lower' ) );
        // Past green into amber, and past amber into red.
        $this->assertSame( 'warn', $this->repo->flagFor( 5.15, $t, 'lower' ) );
        $this->assertSame( 'bad',  $this->repo->flagFor( 5.40, $t, 'lower' ) );
    }

    public function test_higher_is_better_is_the_mirror_image(): void {
        $t = $this->jumpTarget();
        $this->assertSame( 'ok',   $this->repo->flagFor( 62.0, $t, 'higher' ) );
        $this->assertSame( 'ok',   $this->repo->flagFor( 45.0, $t, 'higher' ) );
        $this->assertSame( 'warn', $this->repo->flagFor( 35.0, $t, 'higher' ) );
        $this->assertSame( 'bad',  $this->repo->flagFor( 25.0, $t, 'higher' ) );
    }

    /**
     * A `neutral` test is the one case where both edges genuinely bound —
     * "should land in this range" rather than "should reach this".
     */
    public function test_neutral_keeps_both_edges(): void {
        $t = $this->sprintTarget();
        $this->assertSame( 'bad',  $this->repo->flagFor( 4.84, $t, 'neutral' ) );
        $this->assertSame( 'ok',   $this->repo->flagFor( 5.02, $t, 'neutral' ) );
        $this->assertSame( 'warn', $this->repo->flagFor( 5.15, $t, 'neutral' ) );
        $this->assertSame( 'bad',  $this->repo->flagFor( 5.40, $t, 'neutral' ) );
    }

    /** Values sitting exactly on an edge stay inside the tighter band. */
    public function test_values_on_an_edge(): void {
        $t = $this->sprintTarget();
        $this->assertSame( 'ok', $this->repo->flagFor( 5.100, $t, 'lower' ) );
        $this->assertSame( 'ok', $this->repo->flagFor( 4.950, $t, 'lower' ) );
        $this->assertSame( 'warn', $this->repo->flagFor( 5.250, $t, 'lower' ) );

        $j = $this->jumpTarget();
        $this->assertSame( 'ok', $this->repo->flagFor( 40.0, $j, 'higher' ) );
        $this->assertSame( 'warn', $this->repo->flagFor( 30.0, $j, 'higher' ) );
    }

    /** A one-sided target still resolves. */
    public function test_single_edge_targets(): void {
        $upper_only = (object) [ 'green_max' => 5.100, 'amber_max' => 5.250 ];
        $this->assertSame( 'ok',   $this->repo->flagFor( 4.80, $upper_only, 'lower' ) );
        $this->assertSame( 'warn', $this->repo->flagFor( 5.20, $upper_only, 'lower' ) );
        $this->assertSame( 'bad',  $this->repo->flagFor( 5.90, $upper_only, 'lower' ) );

        $lower_only = (object) [ 'green_min' => 40.0, 'amber_min' => 30.0 ];
        $this->assertSame( 'ok',   $this->repo->flagFor( 44.0, $lower_only, 'higher' ) );
        $this->assertSame( 'warn', $this->repo->flagFor( 33.0, $lower_only, 'higher' ) );
        $this->assertSame( 'bad',  $this->repo->flagFor( 12.0, $lower_only, 'higher' ) );
    }

    /**
     * A target whose only edges are on the side this direction ignores
     * cannot bound anything. No flag is the honest answer; painting every
     * reading red is not.
     */
    public function test_target_with_no_usable_edge_flags_nothing(): void {
        $only_floor = (object) [ 'green_min' => 4.950, 'amber_min' => 4.800 ];
        $this->assertSame( '', $this->repo->flagFor( 5.02, $only_floor, 'lower' ) );
    }

    public function test_no_target_and_no_value_flag_nothing(): void {
        $this->assertSame( '', $this->repo->flagFor( 5.02, null, 'lower' ) );
        $this->assertSame( '', $this->repo->flagFor( null, $this->sprintTarget(), 'lower' ) );
    }
}
