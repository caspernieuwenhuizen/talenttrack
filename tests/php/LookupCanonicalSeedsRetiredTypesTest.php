<?php
namespace TT\Tests\Php;

use TT\Domain\Vocabularies\Lookups\TournamentFormation;
use TT\Modules\Configuration\LookupCanonicalSeeds;
use WP_UnitTestCase;

/**
 * #3126 — three vocabularies in `LookupCanonicalSeeds::canonicalMap()`
 * described lookups that no longer exist.
 *
 * `competition_type` was renamed wholesale to `game_subtype` by migration
 * 0027, so a migrated install holds no rows of it. `tournament_format` and
 * `vct_theme_status` were never seeded by any migration and never read;
 * #3117 dropped the same two from `LookupTranslationSeeds` for that reason.
 *
 * The reason this has a test rather than only a diff is the near-collision:
 * `tournament_format` is not `tournament_formation`, which is live, seeded
 * by migration 0098 and sat on the adjacent line. That is the shape of a
 * deletion that takes out a working vocabulary, so it is pinned here.
 */
final class LookupCanonicalSeedsRetiredTypesTest extends WP_UnitTestCase {

    /**
     * The distinction, by what is actually in the table rather than by
     * reading the two names — which is the check the issue asked for.
     */
    public function test_the_live_tournament_vocabulary_has_rows_and_the_retired_one_does_not(): void {
        $this->assertGreaterThan(
            0,
            $this->rowCount( 'tournament_formation' ),
            'tournament_formation is seeded by migration 0098 and must stay'
        );
        $this->assertSame(
            0,
            $this->rowCount( 'tournament_format' ),
            'tournament_format was never seeded by any migration'
        );
    }

    public function test_the_retired_types_carry_no_rows(): void {
        foreach ( [ 'competition_type', 'tournament_format', 'vct_theme_status' ] as $type ) {
            $this->assertSame( 0, $this->rowCount( $type ), "{$type} still has rows" );
        }
    }

    public function test_the_retired_types_are_out_of_the_canonical_map(): void {
        $map = LookupCanonicalSeeds::canonicalMap();

        foreach ( [ 'competition_type', 'tournament_format', 'vct_theme_status' ] as $type ) {
            $this->assertArrayNotHasKey( $type, $map );
            $this->assertSame( [], LookupCanonicalSeeds::canonicalFor( $type ) );
        }
    }

    /** The vocabulary that replaced `competition_type` is still described. */
    public function test_the_vocabulary_that_replaced_it_is_still_canonical(): void {
        $map = LookupCanonicalSeeds::canonicalMap();

        $this->assertArrayHasKey( 'game_subtype', $map );
        $this->assertArrayHasKey( 'tournament_formation', $map );
        $this->assertSame( TournamentFormation::ALL, $map['tournament_formation'] );
        $this->assertArrayHasKey( 'opponent_level', $map );
    }

    /** The class that described the renamed lookup is gone. */
    public function test_the_retired_vocabulary_class_no_longer_exists(): void {
        $this->assertFalse(
            class_exists( '\\TT\\Domain\\Vocabularies\\Lookups\\CompetitionType' ),
            'dead code that describes itself as live gets trusted by the next reader'
        );
    }

    /**
     * The drift audit skips a type it has no canonical list for, so removing
     * three entries flags nothing new and un-flags nothing that had rows.
     */
    public function test_a_row_of_a_retired_type_is_no_longer_flagged_for_review(): void {
        $this->assertTrue(
            LookupCanonicalSeeds::isCanonical( 'competition_type', 'Beker' ),
            'a retired vocabulary is not drift to review'
        );
        $this->assertSame( '', LookupCanonicalSeeds::suggestCanonicalFor( 'competition_type', 'Beker' ) );
    }

    /** The audit still flags drift on a vocabulary that is live. */
    public function test_the_audit_still_flags_a_live_vocabulary(): void {
        $this->assertFalse( LookupCanonicalSeeds::isCanonical( 'attendance_status', 'Aanwezig' ) );
        $this->assertSame( 'Present', LookupCanonicalSeeds::suggestCanonicalFor( 'attendance_status', 'Aanwezig' ) );
    }

    /**
     * Unscoped by club deliberately: the question is whether the seed
     * migrations ever wrote a row of this type at all, and a club filter
     * could answer "no" for the wrong reason.
     */
    private function rowCount( string $lookup_type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s",
            $lookup_type
        ) );
    }
}
