<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Knowledge\Blocks\BlockRegistry;
use TT\Modules\Knowledge\Blocks\LoadMatrixBlock;
use TT\Modules\Knowledge\Blocks\WeekPlannerBlock;
use TT\Modules\Knowledge\Blocks\ZeroPointBlock;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\LessonMarkdown;
use TT\Modules\Knowledge\Periodisation;

/**
 * #2643 — markdown is the storage format; this is the render.
 *
 * The interesting assertions here are not "does markdown produce HTML" but
 * the three that would silently teach a coach the wrong thing: the
 * zero-point resolver, the recovery check, and the load pattern. Each is
 * asserted against the worked examples in the methodology rather than
 * against its own output.
 */
final class LessonBlocksTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        BlockRegistry::flush();
        CourseRegistry::flushCache();
    }

    public function tear_down(): void {
        BlockRegistry::flush();
        CourseRegistry::flushCache();
        parent::tear_down();
    }

    // ── the numbers ────────────────────────────────────────────────────

    /**
     * The two worked examples in the source: an amateur team measuring 24
     * minutes is at step 3 (2 × 12), a professional squad measuring 36 is
     * at step 8 (3 × 12).
     */
    public function test_zero_point_matches_the_worked_examples(): void {
        $amateur = ZeroPointBlock::resolveStep( 'extensive_endurance', 24 );
        $this->assertSame( 3, $amateur['step'] );
        $this->assertSame( 2, $amateur['games'] );
        $this->assertSame( 12.0, $amateur['minutes'] );

        $professional = ZeroPointBlock::resolveStep( 'extensive_endurance', 36 );
        $this->assertSame( 8, $professional['step'] );
        $this->assertSame( 3, $professional['games'] );
        $this->assertSame( 12.0, $professional['minutes'] );
    }

    /**
     * Rounding direction matters more than it looks. A team that managed
     * 25 minutes has completed step 3 at 24, not step 4 at 26 — starting
     * a coach above their own measurement is the one error that produces
     * injuries rather than slow progress.
     */
    public function test_zero_point_rounds_down_never_up(): void {
        // Step 3 is 2 × 12 = 24 minutes, step 4 is 2 × 13 = 26.
        $this->assertSame( 3, ZeroPointBlock::resolveStep( 'extensive_endurance', 25 )['step'] );

        // Exactly reaching a step's total counts as reaching it.
        $this->assertSame( 4, ZeroPointBlock::resolveStep( 'extensive_endurance', 26 )['step'] );
    }

    public function test_zero_point_below_the_lightest_step_still_starts_at_one(): void {
        $step = ZeroPointBlock::resolveStep( 'extensive_endurance', 5 );

        $this->assertSame( 1, $step['step'] );
    }

    public function test_zero_point_rejects_an_unknown_method_and_a_zero_measurement(): void {
        $this->assertNull( ZeroPointBlock::resolveStep( 'no-such-method', 24 ) );
        $this->assertNull( ZeroPointBlock::resolveStep( 'extensive_endurance', 0 ) );
    }

    /**
     * The step table is not a rectangular grid: after 2 × 15 the next step
     * is 3 × 11, not 3 × 10. A generated grid gets this wrong and shifts
     * every step number from the seventh on.
     */
    public function test_step_tables_match_the_published_progression(): void {
        $steps = Periodisation::overloadSteps()['extensive_endurance']['steps'];

        $this->assertCount( 21, $steps );
        $this->assertSame( [ 2, 10.0 ], [ $steps[0]['games'], $steps[0]['minutes'] ] );
        $this->assertSame( [ 2, 15.0 ], [ $steps[5]['games'], $steps[5]['minutes'] ] );
        $this->assertSame( [ 3, 11.0 ], [ $steps[6]['games'], $steps[6]['minutes'] ] );
        $this->assertSame( [ 6, 15.0 ], [ $steps[20]['games'], $steps[20]['minutes'] ] );

        $intensive = Periodisation::overloadSteps()['intensive_endurance']['steps'];
        $this->assertCount( 15, $intensive );
        $this->assertSame( 4.5, $intensive[1]['minutes'] );
    }

    // ── the recovery check ─────────────────────────────────────────────

    /**
     * The headline case from the course: small games on Thursday with a
     * Saturday match leaves 48 hours where 72 are needed.
     */
    public function test_week_planner_catches_small_games_too_close_to_a_match(): void {
        $plan = [ 'off', 'technical', 'off', 'games_small', 'tactical', 'match', 'off' ];

        $problems = WeekPlannerBlock::violations( $plan );

        $this->assertCount( 1, $problems );
        $this->assertSame( 'before_match', $problems[0]['kind'] );
        $this->assertStringContainsString( '48', $problems[0]['message'] );
        $this->assertStringContainsString( '72', $problems[0]['message'] );
    }

    public function test_week_planner_accepts_the_same_session_far_enough_out(): void {
        $plan = [ 'off', 'games_small', 'off', 'tactical', 'tactical', 'match', 'off' ];

        $this->assertSame( [], WeekPlannerBlock::violations( $plan ) );
    }

    public function test_week_planner_catches_the_same_stimulus_twice_inside_its_window(): void {
        // Monday and Tuesday: 24 hours apart, 72 needed.
        $plan = [ 'games_small', 'games_small', 'off', 'off', 'tactical', 'match', 'off' ];

        $problems = WeekPlannerBlock::violations( $plan );
        $kinds    = array_column( $problems, 'kind' );

        $this->assertContains( 'repeat', $kinds );
    }

    /**
     * Exactly the required gap is enough. An off-by-one here would reject
     * the model's own recommended week, which is the fastest way to make a
     * coach stop trusting the tool.
     */
    public function test_week_planner_allows_exactly_the_required_gap(): void {
        // Medium games Monday and Wednesday: 48 hours apart, 48 needed.
        $plan = [ 'games_medium', 'off', 'games_medium', 'off', 'tactical', 'match', 'off' ];

        $repeats = array_filter(
            WeekPlannerBlock::violations( $plan ),
            static function ( array $problem ): bool {
                return $problem['kind'] === 'repeat';
            }
        );

        $this->assertSame( [], $repeats );
    }

    public function test_sessions_without_an_exercise_never_violate_anything(): void {
        $plan = [ 'recovery', 'technical', 'tactical', 'tactical', 'tactical', 'match', 'off' ];

        $this->assertSame( [], WeekPlannerBlock::violations( $plan ) );
    }

    // ── the load pattern ───────────────────────────────────────────────

    /**
     * Reproduces the published twelve-week pattern for a six-week cycle:
     * each format at 100 in its own two weeks, 50 in the two before, 0 in
     * the two after.
     */
    public function test_load_matrix_reproduces_the_published_pattern(): void {
        $expected = [
            // 11v11 – 8v8
            [ 100, 100, 0, 0, 50, 50 ],
            // 7v7 – 5v5
            [ 50, 50, 100, 100, 0, 0 ],
            // 4v4 – 3v3
            [ 0, 0, 50, 50, 100, 100 ],
        ];

        foreach ( $expected as $index => $weeks ) {
            foreach ( $weeks as $week => $load ) {
                $this->assertSame(
                    $load,
                    LoadMatrixBlock::loadFor( $index, $week, 6 ),
                    sprintf( 'format %d, week %d', $index, $week + 1 )
                );
            }
        }
    }

    public function test_load_matrix_repeats_across_cycles(): void {
        for ( $index = 0; $index < 3; $index++ ) {
            for ( $week = 0; $week < 6; $week++ ) {
                $this->assertSame(
                    LoadMatrixBlock::loadFor( $index, $week, 6 ),
                    LoadMatrixBlock::loadFor( $index, $week + 6, 6 )
                );
            }
        }
    }

    public function test_load_matrix_recomputes_for_a_three_week_cycle(): void {
        $this->assertSame( 100, LoadMatrixBlock::loadFor( 0, 0, 3 ) );
        $this->assertSame( 100, LoadMatrixBlock::loadFor( 1, 1, 3 ) );
        $this->assertSame( 100, LoadMatrixBlock::loadFor( 2, 2, 3 ) );
    }

    // ── the renderer ───────────────────────────────────────────────────

    public function test_fence_attributes_parse(): void {
        $attrs = BlockRegistry::parseAttributes( 'tt-quiz pass="4" mode=\'strict\' empty=""' );

        $this->assertSame( '4', $attrs['pass'] );
        $this->assertSame( 'strict', $attrs['mode'] );
        $this->assertSame( '', $attrs['empty'] );
    }

    public function test_block_name_is_the_first_token(): void {
        $this->assertSame( 'tt-zeropoint', BlockRegistry::parseName( 'tt-zeropoint method="x"' ) );
        $this->assertSame( '', BlockRegistry::parseName( '   ' ) );
    }

    /**
     * A course written against a newer release must degrade, not explode.
     */
    public function test_unknown_block_renders_as_a_code_fence(): void {
        $result = LessonMarkdown::render( "```tt-from-the-future\nsomething\n```\n" );

        $this->assertStringContainsString( 'tt-lesson-code', $result['html'] );
        $this->assertStringContainsString( 'something', $result['html'] );
        $this->assertFalse( $result['interactive'] );
    }

    public function test_only_interactive_blocks_flag_the_script(): void {
        $static = LessonMarkdown::render( "```tt-callout type=\"note\"\nHi.\n```\n" );
        $this->assertFalse( $static['interactive'] );

        $live = LessonMarkdown::render( "```tt-zeropoint\n```\n" );
        $this->assertTrue( $live['interactive'] );
    }

    public function test_pipe_tables_render_with_scopes_and_a_scroll_container(): void {
        $markdown = "| A | B |\n| --- | --- |\n| one | two |\n";

        $html = LessonMarkdown::render( $markdown )['html'];

        $this->assertStringContainsString( 'tt-lesson-table-scroll', $html );
        $this->assertStringContainsString( '<th scope="col">A</th>', $html );
        $this->assertStringContainsString( '<th scope="row">one</th>', $html );
        $this->assertStringContainsString( '<td>two</td>', $html );
    }

    /**
     * The corpus wraps at the column, so emphasis routinely spans a line
     * break inside a list item. Inlining each line separately leaves the
     * opening `**` unmatched and prints it as text — which is exactly what
     * happened on the first pass through lesson 5.
     */
    public function test_emphasis_spanning_a_wrapped_list_item_still_renders(): void {
        $markdown = "1. **Tussen twee prikkels ligt minimaal de\n   supercompensatietijd.** Daarna mag het weer.\n";

        $html = LessonMarkdown::render( $markdown )['html'];

        $this->assertStringNotContainsString( '**', $html );
        $this->assertStringContainsString( '<strong>', $html );
    }

    public function test_html_in_the_source_is_escaped(): void {
        $html = LessonMarkdown::render( "A <script>alert(1)</script> line.\n" )['html'];

        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
    }

    public function test_inline_code_is_not_scanned_for_emphasis(): void {
        $html = LessonMarkdown::render( "Use `a * b * c` here.\n" )['html'];

        $this->assertStringNotContainsString( '<em>', $html );
        $this->assertStringContainsString( 'tt-lesson-inline-code', $html );
    }

    public function test_external_links_open_safely(): void {
        $html = LessonMarkdown::render( "See [the source](https://example.org/x).\n" )['html'];

        $this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
    }

    // ── the shipped corpus ─────────────────────────────────────────────

    /**
     * Every fence in the corpus must be claimed by a registered block.
     * An unclaimed one still renders, so nothing breaks — it just quietly
     * degrades into a code sample, which is the sort of thing that ships
     * unnoticed.
     */
    public function test_every_fence_in_the_shipped_corpus_is_a_registered_block(): void {
        foreach ( CourseRegistry::lessons( 'voetbalperiodisering' ) as $slug => $lesson ) {
            preg_match_all( '/^```(\S+)/m', $lesson->body(), $matches );

            foreach ( $matches[1] as $info ) {
                $this->assertTrue(
                    BlockRegistry::has( $info ),
                    sprintf( 'Lesson %s uses unregistered block "%s".', $slug, $info )
                );
            }
        }
    }

    public function test_the_whole_corpus_renders_without_leaking_markdown(): void {
        foreach ( CourseRegistry::lessons( 'voetbalperiodisering' ) as $slug => $lesson ) {
            $html = LessonMarkdown::render( $lesson->body() )['html'];

            $this->assertNotSame( '', $html, "Lesson {$slug} rendered empty." );
            $this->assertStringNotContainsString( '```', $html, "Lesson {$slug} leaked a fence." );
            $this->assertStringNotContainsString( '**', $html, "Lesson {$slug} leaked emphasis markers." );
            $this->assertStringNotContainsString( '| ---', $html, "Lesson {$slug} leaked a table delimiter." );
        }
    }
}
