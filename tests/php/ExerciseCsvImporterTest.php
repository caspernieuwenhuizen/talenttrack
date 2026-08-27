<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Exercises\ExerciseCsvImporter;
use TT\Modules\Exercises\ExercisesRepository;

/**
 * #2613 — CSV bulk import for the exercise library.
 *
 * Most of the weight is on validation rather than on the happy path,
 * for two reasons.
 *
 * The first is the reject-not-clamp rule. `ExercisesRepository` clamps
 * out-of-range numbers, which is right for a REST caller and wrong for
 * an import: a column filled in on a 1-10 scale would land as 150 rows
 * silently rewritten to 7, and nobody would ever know the library was
 * wrong. So the importer has to reject where the repository would clamp,
 * and that difference is exactly the kind of thing a later refactor
 * "simplifies" away.
 *
 * The second is `principle_codes`. A drill with no principle links can be
 * picked by the generator but never preferred, so an import that quietly
 * dropped an unrecognised code would produce a large library that behaves
 * like an empty one — a failure that looks like the generator being bad
 * rather than like an import problem.
 */
final class ExerciseCsvImporterTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var list<string> */
    private $tmp_files = [];

    /**
     * Exercises already in the table when the test starts.
     *
     * An install seeds a substantial library — migration 0090 plus the VCT
     * catalogue — so an absolute `COUNT(*)` measures the seed, not the
     * import. Every count assertion here is a delta from this baseline.
     *
     * @var int
     */
    private $baseline = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->baseline = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_exercises" );
    }

    public function tear_down(): void {
        foreach ( $this->tmp_files as $file ) {
            if ( is_file( $file ) ) unlink( $file );
        }
        $this->tmp_files = [];
        parent::tear_down();
    }

    // ── parsing ────────────────────────────────────────────────────────

    public function test_unknown_columns_are_reported_not_silently_dropped(): void {
        $csv = $this->csv( "name,nonsense\nRondo,x\n" );

        $parsed = ExerciseCsvImporter::parse( $csv );

        $this->assertNotEmpty( $parsed['header_warnings'] );
        $this->assertStringContainsString( 'nonsense', implode( ' ', $parsed['header_warnings'] ) );
    }

    public function test_coaching_points_gets_its_own_explanation(): void {
        $csv = $this->csv( "name,coaching_points\nRondo,Head up\n" );

        $warnings = implode( ' ', ExerciseCsvImporter::parse( $csv )['header_warnings'] );

        // Not lumped in with typos: the column is a reasonable thing to
        // expect, it just has no home on an exercise.
        $this->assertStringContainsString( 'coaching_points', $warnings );
        $this->assertStringContainsString( 'training plan', $warnings );
    }

    public function test_a_missing_name_column_is_reported(): void {
        $csv = $this->csv( "description\nSomething\n" );

        $this->assertStringContainsString(
            'name',
            implode( ' ', ExerciseCsvImporter::parse( $csv )['header_warnings'] )
        );
    }

    public function test_trailing_empty_rows_are_ignored(): void {
        // Excel exports these routinely; counting them as rows would report
        // a total nobody recognises.
        $csv = $this->csv( "name\nRondo\n\n,\n\n" );

        $this->assertCount( 1, ExerciseCsvImporter::parse( $csv )['rows'] );
    }

    public function test_a_utf8_bom_does_not_break_the_first_column(): void {
        $csv = $this->csv( "\xEF\xBB\xBFname\nRondo\n" );

        $parsed = ExerciseCsvImporter::parse( $csv );

        $this->assertSame( 'Rondo', $parsed['rows'][0]['name'] ?? '' );
    }

    // ── the reject-not-clamp rule ──────────────────────────────────────

    public function test_an_out_of_range_intensity_fails_its_row_and_is_not_clamped(): void {
        $over = ExercisesRepository::INTENSITY_BAND_MAX + 3;
        $csv  = $this->csv( "name,intensity_band\nRondo,{$over}\n" );

        $preview = ExerciseCsvImporter::preview( $csv );

        $this->assertSame( 1, $preview['errored'] );
        $this->assertSame( 0, $preview['valid'] );
        $this->assertSame( 'error', $preview['preview'][0]['status'] );

        // And a commit does not quietly write the clamped value.
        ExerciseCsvImporter::commit( $csv );
        $this->assertSame( 0, $this->countExercises() );
    }

    public function test_an_in_range_intensity_is_accepted_at_both_bounds(): void {
        $min = ExercisesRepository::INTENSITY_BAND_MIN;
        $max = ExercisesRepository::INTENSITY_BAND_MAX;
        $csv = $this->csv( "name,intensity_band\nLow,{$min}\nHigh,{$max}\n" );

        $preview = ExerciseCsvImporter::preview( $csv );

        $this->assertSame( 2, $preview['valid'] );
        $this->assertSame( 0, $preview['errored'] );
    }

    public function test_a_non_numeric_number_fails_its_row(): void {
        $csv = $this->csv( "name,players_min\nRondo,eight\n" );

        $preview = ExerciseCsvImporter::preview( $csv );

        $this->assertSame( 1, $preview['errored'] );
        $this->assertStringContainsString( 'whole number', implode( ' ', $preview['preview'][0]['messages'] ) );
    }

    public function test_a_min_greater_than_its_max_fails_its_row(): void {
        $csv = $this->csv( "name,players_min,players_max\nRondo,12,6\n" );

        $preview = ExerciseCsvImporter::preview( $csv );

        $this->assertSame( 1, $preview['errored'] );
        $this->assertStringContainsString( 'greater than', implode( ' ', $preview['preview'][0]['messages'] ) );
    }

    // ── principles ─────────────────────────────────────────────────────

    public function test_a_known_principle_code_is_linked(): void {
        $principle = $this->insertPrinciple( 'ZZTEST-01' );
        $csv       = $this->csv( "name,principle_codes\nRondo,ZZTEST-01\n" );

        $summary = ExerciseCsvImporter::commit( $csv );

        $this->assertSame( 1, $summary['created'] );
        $this->assertSame( [ $principle ], $this->linkedPrinciples( $this->lastExerciseId() ) );
    }

    public function test_several_codes_link_and_a_repeat_does_not_duplicate(): void {
        $a   = $this->insertPrinciple( 'ZZTEST-01' );
        $b   = $this->insertPrinciple( 'ZZTEST-02' );
        $csv = $this->csv( "name,principle_codes\nRondo,ZZTEST-01;ZZTEST-02;ZZTEST-01\n" );

        ExerciseCsvImporter::commit( $csv );

        $linked = $this->linkedPrinciples( $this->lastExerciseId() );
        sort( $linked );
        $this->assertSame( [ $a, $b ], $linked );
    }

    public function test_an_unknown_principle_code_fails_its_row_rather_than_being_dropped(): void {
        $this->insertPrinciple( 'ZZTEST-01' );
        $csv = $this->csv( "name,principle_codes\nRondo,ZZTEST-01;NOPE-99\n" );

        $preview = ExerciseCsvImporter::preview( $csv );

        $this->assertSame( 1, $preview['errored'] );
        $this->assertStringContainsString( 'NOPE-99', implode( ' ', $preview['preview'][0]['messages'] ) );

        // Importing it half-linked would be worse than not importing it:
        // the drill would look tagged and rank as though it were not.
        ExerciseCsvImporter::commit( $csv );
        $this->assertSame( 0, $this->countExercises() );
    }

    public function test_rows_without_principles_are_counted_in_the_preview(): void {
        $this->insertPrinciple( 'ZZTEST-01' );
        $csv = $this->csv( "name,principle_codes\nTagged,ZZTEST-01\nUntagged,\nAlsoUntagged,\n" );

        $preview = ExerciseCsvImporter::preview( $csv );

        // Reported, not rejected — an untagged drill is legal, just less
        // useful, and the moment to notice is before the commit.
        $this->assertSame( 3, $preview['valid'] );
        $this->assertSame( 2, $preview['without_principles'] );
    }

    // ── visibility ─────────────────────────────────────────────────────

    public function test_visibility_defaults_to_team_not_club(): void {
        $csv = $this->csv( "name\nRondo\n" );

        ExerciseCsvImporter::commit( $csv );

        // Narrower than the repository's own default. A bulk import is
        // where an accidental academy-wide publish is easiest to do and
        // hardest to notice.
        $this->assertSame( 'team', $this->lastExerciseColumn( 'visibility' ) );
    }

    public function test_an_unknown_visibility_fails_its_row(): void {
        $csv = $this->csv( "name,visibility\nRondo,everyone\n" );

        $this->assertSame( 1, ExerciseCsvImporter::preview( $csv )['errored'] );
    }

    // ── description + organisation ─────────────────────────────────────

    public function test_organisation_is_appended_to_the_description(): void {
        $csv = $this->csv( "name,description,organisation\nRondo,Warm up,\"6v2 in a 20m square\"\n" );

        ExerciseCsvImporter::commit( $csv );

        $description = (string) $this->lastExerciseColumn( 'description' );
        $this->assertStringContainsString( 'Warm up', $description );
        $this->assertStringContainsString( '6v2 in a 20m square', $description );
    }

    public function test_organisation_alone_becomes_the_description(): void {
        $csv = $this->csv( "name,organisation\nRondo,6v2 in a 20m square\n" );

        ExerciseCsvImporter::commit( $csv );

        $this->assertSame( '6v2 in a 20m square', $this->lastExerciseColumn( 'description' ) );
    }

    // ── commit semantics ───────────────────────────────────────────────

    public function test_a_bad_row_does_not_stop_the_good_ones(): void {
        $over = ExercisesRepository::INTENSITY_BAND_MAX + 5;
        $csv  = $this->csv( "name,intensity_band\nFirst,3\nBroken,{$over}\nThird,4\n" );

        $summary = ExerciseCsvImporter::commit( $csv );

        // Accept-what-worked, like the player importer.
        $this->assertSame( 2, $summary['created'] );
        $this->assertSame( 1, $summary['errored'] );
        $this->assertSame( 2, $this->countExercises() );
    }

    public function test_the_failed_rows_come_back_as_a_correctable_csv(): void {
        $over = ExercisesRepository::INTENSITY_BAND_MAX + 5;
        $csv  = $this->csv( "name,intensity_band\nGood,3\nBroken,{$over}\n" );

        $summary = ExerciseCsvImporter::commit( $csv );

        // The point is that it can be fixed in a spreadsheet and
        // re-uploaded, so it has to carry the original values back.
        $this->assertStringContainsString( 'Broken', $summary['error_csv'] );
        $this->assertStringContainsString( 'import_error', $summary['error_csv'] );
        $this->assertStringNotContainsString( 'Good', $summary['error_csv'] );
    }

    public function test_a_row_with_no_name_is_rejected(): void {
        $csv = $this->csv( "name,description\n,Orphan description\n" );

        $summary = ExerciseCsvImporter::commit( $csv );

        $this->assertSame( 0, $summary['created'] );
        $this->assertSame( 1, $summary['errored'] );
    }

    public function test_a_clean_commit_reports_no_errors_and_no_error_csv(): void {
        $csv = $this->csv( "name\nOne\nTwo\n" );

        $summary = ExerciseCsvImporter::commit( $csv );

        $this->assertSame( 2, $summary['created'] );
        $this->assertSame( 0, $summary['errored'] );
        $this->assertSame( '', $summary['error_csv'] );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function csv( string $contents ): string {
        $path = tempnam( sys_get_temp_dir(), 'tt-ex-csv' );
        file_put_contents( $path, $contents );
        $this->tmp_files[] = $path;
        return $path;
    }

    private function insertPrinciple( string $code ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_principles", [
            'club_id'           => 1,
            'code'              => $code,
            'team_function_key' => 'attacking',
            'team_task_key'     => 'build_up',
            'methodology_id'    => \TT\Modules\Methodology\ActiveMethodologyResolver::forInstall(),
        ] );

        return (int) $wpdb->insert_id;
    }

    /** Exercises created since the test started. */
    private function countExercises(): int {
        global $wpdb;
        $now = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_exercises" );
        return $now - $this->baseline;
    }

    private function lastExerciseId(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT id FROM {$this->p}tt_exercises ORDER BY id DESC LIMIT 1" );
    }

    /** @return mixed */
    private function lastExerciseColumn( string $column ) {
        global $wpdb;
        return $wpdb->get_var( "SELECT `{$column}` FROM {$this->p}tt_exercises ORDER BY id DESC LIMIT 1" );
    }

    /** @return list<int> */
    private function linkedPrinciples( int $exercise_id ): array {
        global $wpdb;

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT principle_id FROM {$this->p}tt_exercise_principles WHERE exercise_id = %d",
            $exercise_id
        ) );

        return array_map( 'intval', is_array( $rows ) ? $rows : [] );
    }
}
