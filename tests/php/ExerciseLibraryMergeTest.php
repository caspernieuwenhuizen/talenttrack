<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Vct\Repositories\VctExercisesRepository;

/**
 * Migration 0212 (#2494) — the VCT catalogue merged into `tt_exercises`.
 *
 * The acceptance gate for this merge is behavioural, not structural: the
 * rules engine must still find the same candidates it found before. These
 * tests assert that from both ends — the schema and data landed, and
 * `findCandidates()` still returns rows for a context the seeded catalogue
 * covers.
 *
 * The bootstrap runs every migration before the suite, so the state under
 * test here is the post-merge state.
 */
final class ExerciseLibraryMergeTest extends WP_UnitTestCase {

    private function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private function tableExists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    public function test_merged_columns_exist_on_tt_exercises(): void {
        global $wpdb;
        $t = $this->table( 'tt_exercises' );

        $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$t}`" );

        foreach ( [
            'code', 'category_key', 'tactical_theme', 'source',
            'intensity_band', 'duration_minutes_min', 'duration_minutes_max',
            'players_min', 'players_max', 'sided_size', 'age_min', 'age_max',
            'pitch_preset', 'equipment_json', 'verheijen_classification',
            'seed_revision',
            'md_minus_4', 'md_minus_3', 'md_minus_2', 'md_minus_1',
            'md_zero', 'md_plus_1', 'md_plus_2', 'md_none',
        ] as $column ) {
            $this->assertContains( $column, $columns, "tt_exercises must carry {$column} after the merge" );
        }
    }

    public function test_candidate_index_is_present(): void {
        global $wpdb;
        $t = $this->table( 'tt_exercises' );

        $names = $wpdb->get_col( "SHOW INDEX FROM `{$t}`", 2 );

        $this->assertContains(
            'idx_candidate_lookup',
            $names,
            'without the covering index the engine degrades to a table scan (0122 H5)'
        );
    }

    public function test_old_vct_table_is_emptied(): void {
        $t = $this->table( 'tt_vct_exercises' );
        if ( ! $this->tableExists( $t ) ) {
            $this->markTestSkipped( 'VCT schema not installed on this fixture' );
        }

        global $wpdb;
        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" );

        $this->assertSame(
            0,
            $remaining,
            'a second catalogue left populated is exactly the drift the merge exists to prevent'
        );
    }

    public function test_seeded_vct_catalogue_moved_across(): void {
        global $wpdb;
        $t = $this->table( 'tt_exercises' );

        $moved = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE source = 'vct'"
        );

        $this->assertGreaterThan(
            0,
            $moved,
            'the seeded VCT catalogue must be present in tt_exercises after the merge'
        );

        $incomplete = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$t}`
              WHERE source = 'vct'
                AND (category_key IS NULL OR age_min IS NULL OR intensity_band IS NULL)"
        );

        $this->assertSame(
            0,
            $incomplete,
            'a moved VCT row missing category_key / age_min / intensity_band would silently drop out of candidate selection'
        );
    }

    public function test_pre_merge_exercises_stay_out_of_candidate_selection(): void {
        global $wpdb;
        $t = $this->table( 'tt_exercises' );

        // The 18 drills seeded by 0090 carry no VCT attributes. They must
        // remain invisible to the engine, or the merge changes what a
        // generated session contains — the one thing it must not do.
        $leaky = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$t}`
              WHERE source <> 'vct' AND category_key IS NOT NULL AND age_min IS NOT NULL"
        );

        $this->assertSame(
            0,
            $leaky,
            'general library exercises must not enter VCT candidate selection until someone fills in their VCT attributes'
        );
    }

    public function test_find_candidates_still_returns_rows(): void {
        global $wpdb;
        $t = $this->table( 'tt_exercises' );

        // Drive the assertion off whatever the seed actually contains, so
        // the test does not rot when the catalogue is re-seeded.
        $row = $wpdb->get_row(
            "SELECT category_key, intensity_band, age_min, age_max, tactical_theme
               FROM `{$t}`
              WHERE source = 'vct' AND archived_at IS NULL AND md_none = 1
              LIMIT 1"
        );

        if ( ! $row ) {
            $this->markTestSkipped( 'no seeded VCT exercise available to probe' );
        }

        $candidates = ( new VctExercisesRepository() )->findCandidates(
            (string) $row->category_key,
            (int) $row->intensity_band,
            (int) $row->intensity_band,
            (int) $row->age_min,
            'NONE',
            null
        );

        $this->assertNotEmpty(
            $candidates,
            'the engine must still find candidates through the merged table'
        );

        $first = $candidates[0];
        $this->assertArrayHasKey( 'name_canonical', $first, 'the VCT-shaped contract must survive the column rename' );
        $this->assertArrayHasKey( 'category', $first, 'category must still be exposed under its VCT name' );
        $this->assertNotSame( '', (string) $first['name_canonical'] );
    }

    public function test_session_blocks_reference_live_exercises(): void {
        global $wpdb;
        $blocks    = $this->table( 'tt_vct_session_blocks' );
        $exercises = $this->table( 'tt_exercises' );

        if ( ! $this->tableExists( $blocks ) ) {
            $this->markTestSkipped( 'VCT schema not installed on this fixture' );
        }

        $orphans = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$blocks}` b
              WHERE b.exercise_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM `{$exercises}` e WHERE e.id = b.exercise_id)"
        );

        $this->assertSame(
            0,
            $orphans,
            'a published session pointing at a dead exercise id would render blank blocks'
        );
    }

    public function test_coaching_points_reference_live_exercises(): void {
        global $wpdb;
        $points    = $this->table( 'tt_vct_coaching_points' );
        $exercises = $this->table( 'tt_exercises' );

        if ( ! $this->tableExists( $points ) ) {
            $this->markTestSkipped( 'VCT schema not installed on this fixture' );
        }

        $orphans = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$points}` cp
              WHERE NOT EXISTS (SELECT 1 FROM `{$exercises}` e WHERE e.id = cp.exercise_id)"
        );

        $this->assertSame(
            0,
            $orphans,
            'coaching points must follow their exercise across the merge'
        );
    }

    public function test_uuids_survived_the_move(): void {
        global $wpdb;
        $t = $this->table( 'tt_exercises' );

        $blank = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE source = 'vct' AND (uuid IS NULL OR uuid = '')"
        );

        $this->assertSame(
            0,
            $blank,
            'uuid is what makes the migration idempotent and the child remap replayable'
        );
    }
}
