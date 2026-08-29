<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Documentation\Markdown;
use TT\Modules\Knowledge\LessonMarkdown;
use TT\Shared\Content\MarkdownProfile;
use TT\Shared\Content\MarkdownRenderer;

/**
 * #2663 — one markdown renderer, two profiles.
 *
 * The whole value of the fold is that nothing looks different, which is the
 * part a test cannot see. What it can pin is the contract underneath: the
 * docs profile emits classes rather than inline styles, still strips its
 * metadata, still resolves its own link shapes, and now gets the tables and
 * fence info strings the course reader already had.
 */
final class MarkdownRendererTest extends WP_UnitTestCase {

    /* ---- the docs profile ------------------------------------------- */

    public function test_the_docs_renderer_emits_no_inline_styles(): void {
        $html = Markdown::render(
            "# Heading\n\nA paragraph with `code` and **bold**.\n\n"
            . "- one\n- two\n\n> a quote\n\n```\nfenced\n```\n"
        );

        $this->assertStringNotContainsString( 'style=', $html,
            'the inline wp-admin greys are what this issue removed' );
    }

    public function test_the_docs_renderer_classes_every_element_it_emits(): void {
        $html = Markdown::render(
            "## Heading\n\nWith `code`.\n\n- one\n\n1. first\n\n> quote\n\n```\nfenced\n```\n"
        );

        foreach ( [ 'tt-doc-h2', 'tt-doc-inline-code', 'tt-doc-list', 'tt-doc-quote', 'tt-doc-code' ] as $class ) {
            $this->assertStringContainsString( $class, $html, "missing {$class}" );
        }
    }

    public function test_the_docs_renderer_still_strips_front_matter_and_the_legacy_comment(): void {
        $html = Markdown::render(
            "---\ntitle: Something\naudience: [user]\n---\n\n<!-- audience: user -->\n\n# Body\n\nText.\n"
        );

        $this->assertStringNotContainsString( 'title:', $html );
        $this->assertStringNotContainsString( 'audience:', $html );
        $this->assertStringContainsString( '<h1', $html );
    }

    public function test_doc_topics_now_render_pipe_tables(): void {
        $html = Markdown::render( "| Column | Other |\n| --- | --- |\n| a | b |\n" );

        $this->assertStringContainsString( 'tt-doc-table', $html );
        $this->assertStringContainsString( '<th scope="col">Column</th>', $html );
        $this->assertStringContainsString( '<th scope="row">a</th>', $html );
        $this->assertStringContainsString( '<td>b</td>', $html );
    }

    public function test_the_docs_renderer_keeps_its_own_link_shapes(): void {
        $html = Markdown::render( "See [the site](https://example.org/x).\n" );

        $this->assertStringContainsString( 'tt-doc-link', $html );
        $this->assertStringContainsString( 'https://example.org/x', $html );
    }

    public function test_an_unresolvable_link_renders_as_plain_text(): void {
        $html = Markdown::render( "See [this](mailto:someone@example.org).\n" );

        $this->assertStringNotContainsString( '<a ', $html );
        $this->assertStringContainsString( 'this', $html );
    }

    /* ---- the lesson profile is unchanged ----------------------------- */

    public function test_the_lesson_profile_keeps_its_class_prefix(): void {
        $html = LessonMarkdown::render( "## Heading\n\nWith `code`.\n\n- one\n" )['html'];

        $this->assertStringContainsString( 'tt-lesson-h2', $html );
        $this->assertStringContainsString( 'tt-lesson-inline-code', $html );
        $this->assertStringContainsString( 'tt-lesson-list', $html );
    }

    /* ---- the shared renderer ---------------------------------------- */

    public function test_a_profile_without_a_fence_renderer_falls_back_to_a_code_block(): void {
        $renderer = new MarkdownRenderer( new MarkdownProfile( 'tt-x' ) );
        $result   = $renderer->render( "```php\n\$a = 1;\n```\n" );

        $this->assertStringContainsString( 'tt-x-code', $result['html'] );
        $this->assertStringContainsString( 'data-language="php"', $result['html'] );
        $this->assertFalse( $result['interactive'] );
    }

    public function test_text_is_escaped_before_markup_is_reintroduced(): void {
        $renderer = new MarkdownRenderer( new MarkdownProfile( 'tt-x' ) );
        $html     = $renderer->render( "A <script>alert(1)</script> line.\n" )['html'];

        $this->assertStringNotContainsString( '<script', $html );
    }

    public function test_a_code_span_is_not_scanned_for_emphasis(): void {
        $renderer = new MarkdownRenderer( new MarkdownProfile( 'tt-x' ) );
        $html     = $renderer->render( "Use `a * b * c` here.\n" )['html'];

        $this->assertStringNotContainsString( '<em>', $html );
    }
}
