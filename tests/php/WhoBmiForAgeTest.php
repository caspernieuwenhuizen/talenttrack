<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PlayerSex;
use TT\Modules\Measurements\Growth\WhoBmiForAgeReference;

/**
 * #2895 — the WHO 2007 BMI-for-age reference, checked against its source.
 *
 * This is the test that makes the reference safe to ship. A growth
 * reference recalled or transcribed approximately produces percentiles that
 * look plausible and are quietly wrong — about children's bodies, shown to
 * coaches and parents. Reviewing a thousand decimals by eye is not a
 * control.
 *
 * So the data file carries WHO's own published cut-offs alongside the LMS
 * coefficients, and `test_every_published_cut_off_is_reproduced` recomputes
 * all of them from L, M and S. That checks three things at once:
 *
 *   - the workbook was parsed into the right columns,
 *   - the LMS values survived the round trip to PHP,
 *   - and the Box-Cox arithmetic is right.
 *
 * If any one of those is wrong the recomputed number stops matching what
 * WHO printed, for 168 months × 7 cut-offs × 2 sexes.
 */
final class WhoBmiForAgeTest extends WP_UnitTestCase {

    private WhoBmiForAgeReference $ref;

    /** @var array<string, array<int, array<int, float>>> */
    private array $published;

    public function set_up(): void {
        parent::set_up();
        $this->ref = new WhoBmiForAgeReference();

        $data = require TT_PLUGIN_DIR . 'config/growth/who-2007-bmi-for-age.php';
        $this->published = $data['published_sd'];
    }

    /**
     * The load-bearing one. Every cut-off WHO printed, recomputed from the
     * coefficients this plugin ships.
     *
     * Tolerance is 0.001 — WHO publishes the cut-offs to three decimals, so
     * agreement to the last printed digit is the strictest check the source
     * allows.
     */
    public function test_every_published_cut_off_is_reproduced(): void {
        $checked = 0;

        foreach ( [ 'boys' => PlayerSex::MALE, 'girls' => PlayerSex::FEMALE ] as $table => $sex ) {
            foreach ( $this->published[ $table ] as $month => $cut_offs ) {
                foreach ( $cut_offs as $z => $expected ) {
                    $actual = $this->ref->valueAtSds( (float) $z, (int) $month, $sex );

                    $this->assertNotNull( $actual, "no value at z={$z}, month={$month}, {$table}" );
                    $this->assertEqualsWithDelta(
                        $expected,
                        $actual,
                        0.001,
                        "WHO printed {$expected} at z={$z} for month {$month} ({$table}); we computed {$actual}"
                    );
                    $checked++;
                }
            }
        }

        // 168 months x 7 cut-offs x 2 sexes.
        $this->assertSame( 2352, $checked, 'the whole reference must be covered, not a sample of it' );
    }

    /** sds() and valueAtSds() must be exact inverses. */
    public function test_sds_and_value_are_inverses(): void {
        foreach ( [ 61, 120, 180, 228 ] as $month ) {
            foreach ( [ PlayerSex::MALE, PlayerSex::FEMALE ] as $sex ) {
                foreach ( [ -2.5, -1.0, 0.0, 0.75, 2.0 ] as $z ) {
                    $value = $this->ref->valueAtSds( $z, $month, $sex );
                    $this->assertNotNull( $value );

                    $this->assertEqualsWithDelta(
                        $z,
                        (float) $this->ref->sds( $value, $month, $sex ),
                        0.0001,
                        "round trip failed at z={$z}, month={$month}"
                    );
                }
            }
        }
    }

    /** The median is z = 0 by definition. */
    public function test_the_median_is_zero_sds(): void {
        $median = $this->ref->valueAtSds( 0.0, 120, PlayerSex::MALE );
        $this->assertNotNull( $median );
        $this->assertEqualsWithDelta( 0.0, (float) $this->ref->sds( $median, 120, PlayerSex::MALE ), 0.0001 );
        $this->assertEqualsWithDelta( 50.0, (float) $this->ref->percentile( $median, 120, PlayerSex::MALE ), 0.05 );
    }

    /**
     * The range is the reference's own. Below 61 months the 0–5 reference
     * applies; above 228 there is no curve. Extrapolating would be a
     * confident answer to a question the reference cannot answer.
     */
    public function test_outside_the_range_there_is_no_answer(): void {
        $this->assertFalse( $this->ref->covers( 60, PlayerSex::MALE ) );
        $this->assertFalse( $this->ref->covers( 229, PlayerSex::MALE ) );
        $this->assertTrue( $this->ref->covers( 61, PlayerSex::MALE ) );
        $this->assertTrue( $this->ref->covers( 228, PlayerSex::FEMALE ) );

        $this->assertNull( $this->ref->sds( 18.0, 60, PlayerSex::MALE ) );
        $this->assertNull( $this->ref->sds( 18.0, 229, PlayerSex::MALE ) );
        $this->assertNull( $this->ref->percentile( 18.0, 240, PlayerSex::FEMALE ) );
    }

    /**
     * A blank sex has no curve. That is the documented cost of leaving the
     * field unrecorded (#2894) — not an error, and not a guess.
     */
    public function test_a_blank_sex_has_no_curve(): void {
        $this->assertFalse( $this->ref->covers( 120, PlayerSex::NONE ) );
        $this->assertNull( $this->ref->sds( 18.0, 120, PlayerSex::NONE ) );
        $this->assertNull( $this->ref->percentile( 18.0, 120, '' ) );
        $this->assertNull( $this->ref->sds( 18.0, 120, 'something-else' ) );
    }

    /** Nonsense input is refused rather than returning NAN or INF. */
    public function test_a_non_positive_bmi_has_no_sds(): void {
        $this->assertNull( $this->ref->sds( 0.0, 120, PlayerSex::MALE ) );
        $this->assertNull( $this->ref->sds( -5.0, 120, PlayerSex::MALE ) );
    }

    /**
     * Boys and girls are genuinely different curves — this is the whole
     * reason #2894 had to add the field. If the two tables were ever wired
     * to the same data this fails.
     */
    public function test_the_two_sexes_differ(): void {
        $boy  = $this->ref->valueAtSds( 0.0, 150, PlayerSex::MALE );
        $girl = $this->ref->valueAtSds( 0.0, 150, PlayerSex::FEMALE );

        $this->assertNotNull( $boy );
        $this->assertNotNull( $girl );
        $this->assertNotEqualsWithDelta( (float) $boy, (float) $girl, 0.01 );
    }

    /** Percentiles rise with BMI, and stay inside 0–100. */
    public function test_percentiles_are_monotonic_and_bounded(): void {
        $last = -1.0;
        foreach ( [ 12.0, 14.0, 16.0, 18.0, 22.0, 30.0 ] as $bmi ) {
            $p = $this->ref->percentile( $bmi, 150, PlayerSex::MALE );
            $this->assertNotNull( $p );
            $this->assertGreaterThan( $last, $p, "percentile must rise with BMI (at {$bmi})" );
            $this->assertGreaterThanOrEqual( 0.0, $p );
            $this->assertLessThanOrEqual( 100.0, $p );
            $last = (float) $p;
        }
    }
}
