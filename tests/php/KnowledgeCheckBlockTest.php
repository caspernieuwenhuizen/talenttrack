<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Knowledge\Blocks\BlockRegistry;
use TT\Modules\Knowledge\Blocks\CheckBlock;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\LessonRenderer;

/**
 * #2738 — the inline check.
 *
 * Two properties carry the weight here.
 *
 * The block must not reveal which option is right before the reader has
 * committed. It is scored in the browser, so the answer key *is* in the
 * markup — that is deliberate and argued in `CheckBlock`'s docblock — but
 * nothing may be pre-selected, pre-marked or pre-disabled, because that
 * would hand the answer to a reader who has not thought about it. The
 * verdict state is applied by the script after a choice and never before.
 *
 * And a lesson carrying checks must be flagged interactive, or the script
 * that scores them is never enqueued and every check is inert.
 */
final class KnowledgeCheckBlockTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
        BlockRegistry::flush();
    }

    public function test_the_block_is_registered(): void {
        $this->assertTrue( BlockRegistry::has( 'tt-check' ) );
        $this->assertSame( CheckBlock::class, BlockRegistry::resolve( 'tt-check' ) );
    }

    public function test_a_check_renders_its_prompt_and_every_option(): void {
        $html = CheckBlock::render(
            [ 'prompt' => 'Kan 4v4 op dinsdag?', 'answer' => 'B' ],
            "- A. Ja\n- B. Nee, 72 uur\n- C. Soms\n> Omdat 4v4 drie dagen vraagt.\n"
        );

        $this->assertStringContainsString( 'Kan 4v4 op dinsdag?', $html );
        $this->assertSame( 3, substr_count( $html, 'data-tt-option=' ) );
        $this->assertStringContainsString( 'Nee, 72 uur', $html );
        $this->assertStringContainsString( 'Omdat 4v4 drie dagen vraagt.', $html );
    }

    public function test_nothing_is_preselected_or_premarked(): void {
        $html = CheckBlock::render(
            [ 'prompt' => 'Q?', 'answer' => 'B' ],
            "- A. Een\n- B. Twee\n> Omdat.\n"
        );

        // The script applies these after a choice. Server-side they would
        // give the answer away to a reader who has not committed.
        $this->assertStringNotContainsString( 'checked', $html );
        $this->assertStringNotContainsString( 'disabled', $html );
        $this->assertStringNotContainsString( 'data-tt-state=', $html );
        $this->assertMatchesRegularExpression( '#<p class="tt-lesson-check__verdict"[^>]*></p>#', $html );
    }

    public function test_the_answer_key_reaches_the_script(): void {
        $html = CheckBlock::render(
            [ 'prompt' => 'Q?', 'answer' => 'c' ],
            "- A. Een\n- B. Twee\n- C. Drie\n> Omdat.\n"
        );

        // Lowercased in the corpus, normalised in the markup — the script
        // compares upper-case throughout.
        $this->assertStringContainsString( 'data-tt-answer="C"', $html );
    }

    public function test_a_malformed_check_degrades_to_prose(): void {
        foreach ( [
            'no options' => [ [ 'prompt' => 'Q?', 'answer' => 'A' ], "Just prose.\n" ],
            'no answer'  => [ [ 'prompt' => 'Q?' ], "- A. Een\n- B. Twee\n> Omdat.\n" ],
            'no prompt'  => [ [ 'answer' => 'A' ], "- A. Een\n- B. Twee\n> Omdat.\n" ],
        ] as $case => [ $attrs, $body ] ) {
            $html = CheckBlock::render( $attrs, $body );

            $this->assertStringContainsString( 'tt-lesson-check--malformed', $html, $case );
            $this->assertStringNotContainsString( 'role="radiogroup"', $html, $case );
        }
    }

    public function test_inspect_reports_what_the_lint_needs(): void {
        $found = CheckBlock::inspect(
            [ 'prompt' => 'Q?', 'answer' => 'b' ],
            "- A. Een\n- B. Twee\n> Omdat.\n"
        );

        $this->assertSame( 'B', $found['answer'] );
        $this->assertSame( [ 'A', 'B' ], $found['options'] );
        $this->assertSame( 'Q?', $found['prompt'] );
        $this->assertSame( 'Omdat.', $found['explanation'] );
    }

    /* ===== against the shipped corpus ===== */

    public function test_every_lesson_with_a_check_is_flagged_interactive(): void {
        $seen = 0;

        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            if ( strpos( $lesson->body(), '```tt-check' ) === false ) {
                continue;
            }

            $seen++;
            $rendered = LessonRenderer::render( $lesson->body(), self::COURSE, (string) $slug );

            $this->assertTrue(
                $rendered['interactive'],
                "{$slug} carries checks but is not flagged interactive, so knowledge-blocks.js never loads"
            );
        }

        $this->assertGreaterThan( 0, $seen, 'the corpus should carry inline checks' );
    }

    public function test_every_check_in_the_corpus_renders(): void {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            $declared = substr_count( $lesson->body(), '```tt-check' );
            if ( $declared === 0 ) {
                continue;
            }

            $html = LessonRenderer::render( $lesson->body(), self::COURSE, (string) $slug )['html'];

            $this->assertSame(
                $declared,
                substr_count( $html, 'data-tt-block="check"' ),
                "{$slug}: not every declared check rendered"
            );
            $this->assertStringNotContainsString(
                'tt-lesson-check--malformed',
                $html,
                "{$slug}: a check failed to parse"
            );
        }
    }

    /**
     * The density the feedback asked for. A soft floor rather than an exact
     * count: the point is that no lesson goes back to being a wall of text
     * with one quiz at the end, not that any particular number is sacred.
     */
    public function test_every_lesson_carries_inline_checks(): void {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            $this->assertGreaterThanOrEqual(
                3,
                substr_count( $lesson->body(), '```tt-check' ),
                "{$slug} has fewer than three inline checks"
            );
        }
    }
}
