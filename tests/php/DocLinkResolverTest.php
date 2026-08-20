<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Modules\Documentation\DocLinkResolver;
use TT\Modules\Documentation\Markdown;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Tiles\TileRegistry;

/**
 * #2545 — links inside a rendered help topic.
 *
 * Two behaviours matter and neither is about markdown:
 *
 *  - a link into the app must resolve, carry a back hint, and disappear
 *    when the reader cannot open the destination;
 *  - a link into wp-admin must be invisible to anyone who is not an admin.
 *
 * The registry is driven through TileRegistry directly rather than through
 * CoreSurfaceRegistration, so a tile's real capability wiring cannot make
 * the gating assertions pass or fail for the wrong reason.
 */
final class DocLinkResolverTest extends WP_UnitTestCase {

    /** @var int */
    private $admin_id;
    /** @var int */
    private $coach_id;

    public function set_up(): void {
        parent::set_up();
        TileRegistry::clear();
        DocLinkResolver::flushCache();

        $this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->coach_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        // One tile the coach may see, one they may not.
        TileRegistry::register( [
            'slug' => 'players', 'kind' => 'work', 'group' => 'Squad',
            'label' => 'Players', 'view_slug' => 'players', 'cap' => 'read',
        ] );
        TileRegistry::register( [
            'slug' => 'configuration', 'kind' => 'setup', 'group' => 'System',
            'label' => 'Configuration', 'view_slug' => 'configuration', 'cap' => 'manage_options',
        ] );
    }

    public function tear_down(): void {
        TileRegistry::clear();
        DocLinkResolver::flushCache();
        wp_set_current_user( 0 );
        // Restore the real registry for any later test that assumes it.
        CoreSurfaceRegistration::register();
        parent::tear_down();
    }

    private function render( string $markdown ): string {
        DocLinkResolver::flushCache();
        return Markdown::render( $markdown, 'evaluations' );
    }

    // ── into the app ───────────────────────────────────────────────────

    /**
     * The defect this issue exists to fix: a `?tt_view=` link matched no
     * branch and came out as bare text, so the corpus contained none.
     */
    public function test_a_view_link_resolves_to_the_frontend(): void {
        wp_set_current_user( $this->admin_id );

        $html = $this->render( '[Open players](?tt_view=players)' );

        $this->assertStringContainsString( '<a ', $html );
        $this->assertStringContainsString( 'tt_view=players', $html );
        $this->assertStringNotContainsString( 'wp-admin', $html );
    }

    public function test_a_view_link_renders_as_an_action_chip(): void {
        wp_set_current_user( $this->admin_id );
        $this->assertStringContainsString( 'tt-doc-action', $this->render( '[Open players](?tt_view=players)' ) );
    }

    /**
     * The back hint names the topic explicitly rather than being captured
     * from the request: the drawer renders through REST, where the request
     * URI is the endpoint, and the reader would get a pill back to JSON.
     */
    public function test_a_view_link_carries_a_back_hint_to_the_topic(): void {
        wp_set_current_user( $this->admin_id );

        $html = $this->render( '[Open players](?tt_view=players)' );

        $this->assertStringContainsString( 'tt_back', $html );
        $this->assertStringContainsString( urlencode( 'topic=evaluations' ), $html );
    }

    public function test_extra_query_args_survive(): void {
        wp_set_current_user( $this->admin_id );
        $this->assertStringContainsString( 'status=trial', $this->render( '[Trialists](?tt_view=players&status=trial)' ) );
    }

    // ── gating ─────────────────────────────────────────────────────────

    public function test_a_view_the_reader_cannot_open_renders_as_plain_text(): void {
        wp_set_current_user( $this->coach_id );

        $html = $this->render( '[Configuration](?tt_view=configuration)' );

        $this->assertStringNotContainsString( '<a ', $html );
        $this->assertStringContainsString( 'Configuration', $html );
    }

    /**
     * A slug no tile claims carries no capability declaration here, so it
     * stays linked — sub-views reached from within a record are governed
     * by the surface that links to them, not by the docs.
     */
    public function test_a_slug_no_tile_claims_stays_linked(): void {
        wp_set_current_user( $this->coach_id );
        $this->assertStringContainsString( '<a ', $this->render( '[A teammate](?tt_view=teammate&id=4)' ) );
    }

    public function test_a_disabled_feature_hides_the_link(): void {
        wp_set_current_user( $this->admin_id );

        $key = $this->firstFeatureKeyOwningAViewSlug();
        if ( $key === null ) {
            $this->markTestSkipped( 'no catalogued feature declares a view slug' );
        }
        [ $feature, $slug ] = $key;

        $was = FeatureRegistry::isEnabled( $feature );
        FeatureRegistry::setEnabled( $feature, false );
        try {
            $html = $this->render( "[Go](?tt_view={$slug})" );
            $this->assertStringNotContainsString( '<a ', $html );
        } finally {
            FeatureRegistry::setEnabled( $feature, $was );
        }
    }

    public function test_can_open_refuses_a_traversal_slug(): void {
        $this->assertFalse( DocLinkResolver::canOpen( '../../wp-config' ) );
        $this->assertFalse( DocLinkResolver::canOpen( '' ) );
    }

    public function test_frontend_returns_null_without_a_view_slug(): void {
        $this->assertNull( DocLinkResolver::frontend( '?foo=1' ) );
    }

    // ── into wp-admin ──────────────────────────────────────────────────

    public function test_wp_admin_links_are_hidden_from_non_admins(): void {
        wp_set_current_user( $this->coach_id );

        $html = $this->render( '[Error Log](?page=tt-error-log)' );

        $this->assertStringNotContainsString( '<a ', $html );
        $this->assertStringNotContainsString( 'wp-admin', $html );
        $this->assertStringContainsString( 'Error Log', $html );
    }

    public function test_wp_admin_links_render_for_admins_and_are_marked_external(): void {
        wp_set_current_user( $this->admin_id );

        $html = $this->render( '[Error Log](?page=tt-error-log)' );

        $this->assertStringContainsString( 'page=tt-error-log', $html );
        $this->assertStringContainsString( 'tt-doc-extlink', $html );
        $this->assertStringContainsString( 'aria-label', $html );
    }

    // ── cross-references ───────────────────────────────────────────────

    public function test_a_doc_cross_reference_stays_in_the_docs_viewer(): void {
        wp_set_current_user( $this->admin_id );

        $html = $this->render( '[REST API](rest-api.md)' );

        $this->assertStringContainsString( 'topic=rest-api', $html );
        $this->assertStringNotContainsString( '.md', $html );
    }

    /**
     * The anchor's `#` is also the pattern delimiter. Unescaped it broke
     * preg_match for every link shape, not just anchored ones.
     */
    public function test_a_cross_reference_with_an_anchor_resolves(): void {
        wp_set_current_user( $this->admin_id );
        $this->assertStringContainsString( 'topic=rest-api', $this->render( '[REST API](rest-api.md#authentication)' ) );
    }

    public function test_a_locale_prefixed_cross_reference_resolves(): void {
        wp_set_current_user( $this->admin_id );
        $this->assertStringContainsString( 'topic=evaluations', $this->render( '[NL](nl_NL/evaluations.md)' ) );
    }

    // ── shapes that must not change ────────────────────────────────────

    public function test_offsite_links_are_left_alone(): void {
        $this->assertStringContainsString( 'https://example.test/x', $this->render( '[Site](https://example.test/x)' ) );
    }

    public function test_an_unrecognised_target_renders_as_plain_text(): void {
        $html = $this->render( '[Mail](mailto:coach@example.test)' );
        $this->assertStringNotContainsString( '<a ', $html );
        $this->assertStringContainsString( 'Mail', $html );
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function firstFeatureKeyOwningAViewSlug(): ?array {
        // allWithState() returns a list; the feature key is a field on
        // each entry, not the array key.
        foreach ( FeatureRegistry::allWithState() as $meta ) {
            $slugs = $meta['view_slugs'] ?? [];
            if ( is_array( $slugs ) && $slugs !== [] ) {
                return [ (string) $meta['key'], (string) $slugs[0] ];
            }
        }
        return null;
    }
}
