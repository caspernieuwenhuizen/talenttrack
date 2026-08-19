<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Documentation\AudienceResolver;
use TT\Modules\Documentation\DocFrontMatter;
use TT\Modules\Documentation\HelpTopics;
use TT\Modules\Documentation\Markdown;

/**
 * #2544 — front matter is the help-topic registry.
 *
 * Two halves. The parser is exercised directly against strings, including
 * the shapes that must NOT be treated as metadata (an unterminated block, a
 * horizontal rule further down the document). The registry is exercised
 * against the shipped `docs/` corpus, because the failure this issue exists
 * to prevent is corpus drift, not a parser bug — a scan that silently drops
 * half the topics passes every synthetic test.
 */
final class DocFrontMatterTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        HelpTopics::flushCache();
    }

    public function tear_down(): void {
        HelpTopics::flushCache();
        parent::tear_down();
    }

    // ── parser ─────────────────────────────────────────────────────────

    public function test_parses_scalars_and_inline_lists(): void {
        $data = DocFrontMatter::parse( "---\ntitle: Match minutes\ngroup: performance\naudience: [user, admin]\n---\n\n# Body\n" );

        $this->assertSame( 'Match minutes', $data['title'] );
        $this->assertSame( 'performance', $data['group'] );
        $this->assertSame( [ 'user', 'admin' ], $data['audience'] );
    }

    public function test_source_without_a_block_yields_nothing(): void {
        $this->assertSame( [], DocFrontMatter::parse( "# Heading\n\nSome prose.\n" ) );
    }

    /**
     * An unterminated block is a malformed file. Returning the keys anyway
     * would register a topic whose body silently starts mid-document.
     */
    public function test_unterminated_block_yields_nothing(): void {
        $this->assertSame( [], DocFrontMatter::parse( "---\ntitle: Orphan\n\n# Body\n" ) );
    }

    /**
     * `---` is also a markdown horizontal rule. Only a block at byte zero
     * counts, or every doc with a rule in it would lose its opening
     * section to the parser.
     */
    public function test_horizontal_rule_mid_document_is_not_front_matter(): void {
        $this->assertSame( [], DocFrontMatter::parse( "# Heading\n\n---\n\ntitle: not metadata\n" ) );
    }

    public function test_quoted_value_may_contain_a_colon(): void {
        $data = DocFrontMatter::parse( "---\nsummary: 'Run a trial: templates, input, decision.'\n---\n" );
        $this->assertSame( 'Run a trial: templates, input, decision.', $data['summary'] );
    }

    public function test_crlf_line_endings_parse(): void {
        $data = DocFrontMatter::parse( "---\r\ntitle: Windows\r\n---\r\n" );
        $this->assertSame( 'Windows', $data['title'] );
    }

    public function test_blank_lines_and_comments_inside_the_block_are_skipped(): void {
        $data = DocFrontMatter::parse( "---\n\n# a note\ntitle: Kept\n---\n" );
        $this->assertSame( [ 'title' => 'Kept' ], $data );
    }

    public function test_accessors_normalise_scalar_and_list_forms(): void {
        $this->assertSame( 'x', DocFrontMatter::string( [ 'a' => [ 'x', 'y' ] ], 'a' ) );
        $this->assertSame( 'fallback', DocFrontMatter::string( [], 'a', 'fallback' ) );
        $this->assertSame( [ 'x' ], DocFrontMatter::list( [ 'a' => 'x' ], 'a' ) );
        $this->assertSame( [], DocFrontMatter::list( [], 'a' ) );
    }

    public function test_strip_removes_the_block_and_leaves_plain_sources_alone(): void {
        $this->assertSame( "# Body\n", DocFrontMatter::strip( "---\ntitle: A\n---\n\n# Body\n" ) );
        $this->assertSame( "# Body\n", DocFrontMatter::strip( "# Body\n" ) );
    }

    // ── registry over the shipped corpus ───────────────────────────────

    public function test_every_registered_topic_has_a_complete_tuple(): void {
        $topics = HelpTopics::all();
        $groups = HelpTopics::groups();

        $this->assertNotEmpty( $topics, 'the docs scan found no topics at all' );

        foreach ( $topics as $slug => $t ) {
            $this->assertNotSame( '', $t['title'], "topic '$slug' has no title" );
            $this->assertNotSame( '', $t['summary'], "topic '$slug' has no summary" );
            $this->assertNotSame( [], $t['audience'], "topic '$slug' declares no audience" );
            $this->assertArrayHasKey(
                $t['group'],
                $groups,
                "topic '$slug' is in group '{$t['group']}', which no group label covers — it would never render in the sidebar"
            );
        }
    }

    public function test_every_declared_audience_value_is_recognised(): void {
        foreach ( HelpTopics::all() as $slug => $t ) {
            foreach ( $t['audience'] as $aud ) {
                $this->assertContains(
                    $aud,
                    [ AudienceResolver::USER, AudienceResolver::ADMIN, AudienceResolver::DEV, AudienceResolver::PLAYER, AudienceResolver::PARENT ],
                    "topic '$slug' declares unknown audience '$aud'"
                );
            }
        }
    }

    public function test_the_default_topic_is_registered(): void {
        $this->assertArrayHasKey( HelpTopics::defaultSlug(), HelpTopics::all() );
    }

    /**
     * A doc without front matter stays out of the index — that is how
     * developer documentation opts out rather than needing an allowlist.
     */
    public function test_a_doc_without_front_matter_is_not_registered(): void {
        $this->assertArrayNotHasKey( 'contributing', HelpTopics::all() );
    }

    public function test_file_path_only_resolves_registered_slugs(): void {
        $this->assertNotNull( HelpTopics::filePath( HelpTopics::defaultSlug() ) );
        $this->assertNull( HelpTopics::filePath( 'no-such-topic-anywhere' ) );
    }

    /**
     * Slugs name files. A caller passing a traversal must not be able to
     * read outside `docs/`.
     */
    public function test_file_path_refuses_traversal(): void {
        $this->assertNull( HelpTopics::filePath( '../../wp-config' ) );
    }

    public function test_rendering_a_topic_never_leaks_its_metadata(): void {
        $path = HelpTopics::filePath( HelpTopics::defaultSlug() );
        $this->assertNotNull( $path );

        $html = Markdown::render( (string) file_get_contents( $path ) );

        $this->assertStringNotContainsString( 'title:', $html );
        $this->assertStringNotContainsString( 'audience:', $html );
        $this->assertStringContainsString( '<h1', $html );
    }

    /**
     * The legacy `<!-- audience: … -->` marker still resolves, so a file
     * that has not been migrated keeps its audience instead of vanishing.
     */
    public function test_legacy_audience_comment_still_parses(): void {
        $this->assertSame( [ 'user', 'admin' ], AudienceResolver::parse( "<!-- audience: user, admin -->\n" ) );
    }
}
