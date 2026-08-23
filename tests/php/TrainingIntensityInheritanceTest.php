<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Training\Print\TrainingPlanPrintable;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * #2739 — the growth-spurt warning has to be able to fire.
 *
 * It could not. `phvWarning()` read the block's own `intensity_band`,
 * and only the generator's selection pass ever wrote that column, so a
 * plan built by the builder, the REST bulk replace or the photo flow
 * read as peak zero and lost the warning entirely — not because the
 * plan was easy, but because nobody had recorded how hard it was.
 *
 * Every plan here is therefore built **the way those three paths build
 * one**: `replaceAll()` with no `intensity_band`. Building it the
 * generator's way would pass against the broken code, which is exactly
 * how this survived being shipped.
 *
 * The assertions key on CSS classes rather than on the copy. The first
 * version of this check looked for the English word "ceiling" and
 * silently passed nothing on a Dutch install.
 */
final class TrainingIntensityInheritanceTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    private function makeTeam(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'JO14-1' ] );

        return (int) $wpdb->insert_id;
    }

    private function makePlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => $team_id, 'first_name' => 'Ryan', 'last_name' => 'Schouten',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function flagPlayer( int $player_id, int $ceiling ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_player_phv_flags', [
            'club_id' => 1, 'player_id' => $player_id, 'is_active' => 1,
            'reason_key' => 'growth_spurt', 'intensity_ceiling' => $ceiling,
        ] );
    }

    private function makeExercise( ?int $band ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_exercises', [
            'club_id' => 1, 'uuid' => wp_generate_uuid4(), 'name' => 'Drill',
            'visibility' => 'club', 'duration_minutes' => 15, 'intensity_band' => $band,
        ] );

        return (int) $wpdb->insert_id;
    }

    /** Built the way the builder, the REST bulk replace and the photo flow build one. */
    private function makePlan( int $team_id, array $exercise_ids ): int {
        $plan_id = ( new TrainingPlansRepository() )->create( [ 'title' => 'Opbouw', 'team_id' => $team_id ] );

        ( new TrainingPlanBlocksRepository() )->replaceAll( (int) $plan_id, array_map(
            static fn( int $id, int $i ): array => [
                'order_index' => $i, 'block_type' => 'main',
                'exercise_id' => $id, 'duration_minutes' => 15,
                // Deliberately no intensity_band.
            ],
            $exercise_ids,
            array_keys( $exercise_ids )
        ) );

        return (int) $plan_id;
    }

    private function sheet( int $plan_id ): string {
        return (string) TrainingPlanPrintable::render( $plan_id )['body'];
    }

    // ---- the promise ------------------------------------------------------

    public function test_a_hard_plan_warns_about_a_player_with_a_lower_ceiling(): void {
        $team = $this->makeTeam();
        $this->flagPlayer( $this->makePlayer( $team ), 2 );

        $plan = $this->makePlan( $team, [ $this->makeExercise( 5 ), $this->makeExercise( 4 ) ] );
        $body = $this->sheet( $plan );

        $this->assertStringContainsString( 'tt-tp__warn', $body, 'the warning must appear' );
        $this->assertStringNotContainsString(
            'tt-tp__warn--unknown',
            $body,
            'the intensity IS known here — it came from the exercise'
        );
    }

    public function test_a_plan_below_the_ceiling_stays_silent(): void {
        $team = $this->makeTeam();
        $this->flagPlayer( $this->makePlayer( $team ), 4 );

        $body = $this->sheet( $this->makePlan( $team, [ $this->makeExercise( 2 ) ] ) );

        $this->assertStringNotContainsString( 'tt-tp__warn', $body );
    }

    /**
     * The heart of it. Silence has to mean "checked, nothing to say" —
     * never "could not check". A coach holding the sheet cannot tell
     * those apart, and one of them is a child who needed an adapted role.
     */
    public function test_a_plan_with_no_intensity_anywhere_says_it_could_not_check(): void {
        $team = $this->makeTeam();
        $this->flagPlayer( $this->makePlayer( $team ), 2 );

        $body = $this->sheet( $this->makePlan( $team, [ $this->makeExercise( null ) ] ) );

        $this->assertStringContainsString(
            'tt-tp__warn--unknown',
            $body,
            'an empty space reads as an all-clear; the sheet has to say the check did not run'
        );
    }

    // ---- inheritance ------------------------------------------------------

    public function test_a_block_written_without_an_intensity_takes_the_exercise_s(): void {
        global $wpdb;

        $team = $this->makeTeam();
        $plan = $this->makePlan( $team, [ $this->makeExercise( 3 ) ] );

        $band = $wpdb->get_var( $wpdb->prepare(
            "SELECT intensity_band FROM {$wpdb->prefix}tt_training_plan_blocks WHERE plan_id = %d",
            $plan
        ) );

        $this->assertSame( 3, (int) $band, 'the block carries it, so the run snapshot will too' );
    }

    public function test_an_explicit_intensity_is_not_overwritten(): void {
        global $wpdb;

        $team     = $this->makeTeam();
        $exercise = $this->makeExercise( 5 );
        $plan_id  = ( new TrainingPlansRepository() )->create( [ 'title' => 'Opbouw', 'team_id' => $team ] );

        // A coach who says this block is a 2 means it, even when the
        // exercise is normally a 5 — an adapted version, or a walk-through.
        ( new TrainingPlanBlocksRepository() )->replaceAll( (int) $plan_id, [ [
            'order_index' => 0, 'block_type' => 'main',
            'exercise_id' => $exercise, 'duration_minutes' => 15, 'intensity_band' => 2,
        ] ] );

        $band = $wpdb->get_var( $wpdb->prepare(
            "SELECT intensity_band FROM {$wpdb->prefix}tt_training_plan_blocks WHERE plan_id = %d",
            $plan_id
        ) );

        $this->assertSame( 2, (int) $band );
    }

    public function test_a_block_with_no_exercise_at_all_is_left_alone(): void {
        global $wpdb;

        $team    = $this->makeTeam();
        $plan_id = ( new TrainingPlansRepository() )->create( [ 'title' => 'Praatje', 'team_id' => $team ] );

        ( new TrainingPlanBlocksRepository() )->replaceAll( (int) $plan_id, [ [
            'order_index' => 0, 'block_type' => 'talk',
            'title_override' => 'Bespreking', 'duration_minutes' => 10,
        ] ] );

        $band = $wpdb->get_var( $wpdb->prepare(
            "SELECT intensity_band FROM {$wpdb->prefix}tt_training_plan_blocks WHERE plan_id = %d",
            $plan_id
        ) );

        $this->assertNull( $band, 'a team talk has no intensity and should not acquire one' );
    }
}
