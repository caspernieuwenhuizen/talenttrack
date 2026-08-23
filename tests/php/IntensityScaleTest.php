<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Exercises\ExercisesRepository;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;

/**
 * #2767 — one intensity scale, read by everything that states it.
 *
 * Three parts of the product disagreed: the engine and the shipped catalogue
 * used 1–7, the create form offered 1–10, and the docs said 1–5. The number is
 * what the age-safe ceiling is compared against, so the disagreement landed on
 * the safety check rather than on presentation — a coach rating against a
 * documented 1–5 under-rates every hard drill, and the warning that should
 * fire for a player never does.
 *
 * What is pinned here is the invariant that broke: the form must not offer a
 * band no age profile can accommodate.
 */
final class IntensityScaleTest extends WP_UnitTestCase {

    public function test_the_scale_is_a_sane_range(): void {
        $this->assertGreaterThan(
            ExercisesRepository::INTENSITY_BAND_MIN,
            ExercisesRepository::INTENSITY_BAND_MAX,
            'the scale must have a top above its bottom'
        );
        $this->assertSame( 1, ExercisesRepository::INTENSITY_BAND_MIN );
    }

    /**
     * The drift that caused the issue. An age profile's ceiling is compared
     * against an exercise's band, so a band above every profile's ceiling is
     * a value the age-safe check has no definition for.
     */
    public function test_no_age_profile_ceiling_exceeds_the_scale(): void {
        $repo     = new VctAgeProfilesRepository();
        $profiles = $repo->listAll();

        if ( empty( $profiles ) ) {
            $this->markTestSkipped( 'no VCT age profiles seeded on this install' );
        }

        $over = [];
        foreach ( $profiles as $profile ) {
            $max = (int) ( $profile['intensity_band_max'] ?? 0 );
            if ( $max > ExercisesRepository::INTENSITY_BAND_MAX ) {
                $over[] = ( $profile['age_group'] ?? '?' ) . "={$max}";
            }
        }

        $this->assertSame(
            [],
            $over,
            'age profiles capping above the scale maximum: ' . implode( ', ', $over )
        );
    }

    /**
     * The write path clamps rather than trusting its input. Clamping too low
     * is the failure #2495 shipped — a band 6 exercise silently saved as 5 —
     * so the bound has to be the scale itself, not a literal someone picked.
     */
    public function test_the_write_path_clamps_to_the_scale(): void {
        $repo   = new ExercisesRepository();
        $method = new \ReflectionMethod( ExercisesRepository::class, 'sanitizePayload' );
        $method->setAccessible( true );

        $clamp = static function ( int $band ) use ( $method, $repo ): int {
            $out = $method->invoke( $repo, [ 'intensity_band' => $band ], false );
            return (int) ( $out['intensity_band'] ?? 0 );
        };

        $this->assertSame(
            ExercisesRepository::INTENSITY_BAND_MAX,
            $clamp( 47 ),
            'an out-of-range band must clamp to the top of the scale, not be stored'
        );
        $this->assertSame(
            ExercisesRepository::INTENSITY_BAND_MIN,
            $clamp( 0 ),
            'a band below the scale must clamp to its bottom'
        );
        $this->assertSame(
            6,
            $clamp( 6 ),
            'a band inside the scale must survive the write path unchanged - #2495 was exactly this being downgraded'
        );
    }
}
