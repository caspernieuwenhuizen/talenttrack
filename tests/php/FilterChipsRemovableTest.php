<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FilterBar;

/**
 * #3292 — the summary chips say which filters are applied, to everyone, and
 * each one can be taken off.
 *
 * They were `aria-hidden="true"` spans. A screen-reader user heard the
 * trigger as "Filters, 3" and had no way to learn what the three were; a
 * sighted touch user could read them but not tap one off. The bar already
 * contradicted itself here — the `⋯` menu in the same component ships a
 * clearable chip.
 *
 * The chips arrived as a flat list of pre-rendered labels, so the component
 * could not make them removable without deriving them itself. It has every
 * group's options and active state, which is the same source `paramNames()`
 * reads.
 */
final class FilterChipsRemovableTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // remove_query_arg() works off the current request.
        $_SERVER['REQUEST_URI'] = '/dash/?tt_view=players&team_id=2&q=jansen&archived=1';
    }

    /** @param array<int,array<string,mixed>> $extra_groups */
    private function render( array $args = [], array $extra_groups = [] ): string {
        $groups = array_merge( [
            [
                'type'     => 'select',
                'key'      => 'team',
                'name'     => 'team_id',
                'label'    => 'Team',
                // #3318 — `selected`, the key renderSelect() reads and every
                // caller passes. This fixture used to say `value`, which no
                // select group carries: it produced a chip while rendering an
                // empty <select>, so the suite was green while the feature
                // reached no select on any surface in the app.
                'selected' => '2',
                'options'  => [ '' => 'All', '2' => 'Ajax U17' ],
            ],
            [
                'type'  => 'text',
                'key'   => 'q',
                'name'  => 'q',
                'label' => 'Search',
                'value' => 'jansen',
            ],
        ], $extra_groups );

        return FilterBar::html( array_merge( [
            'action'    => '/',
            'method'    => 'get',
            'reset_url' => '/?reset=1',
            'groups'    => $groups,
        ], $args ) );
    }

    public function test_the_chip_strip_is_not_hidden_from_assistive_tech(): void {
        $html = $this->render();

        $this->assertStringContainsString( '<ul class="tt-chips">', $html );
        $this->assertStringNotContainsString( 'tt-chips" aria-hidden', $html );
    }

    public function test_each_active_filter_gets_a_named_chip(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'Team: Ajax U17', $html );
        $this->assertStringContainsString( 'Search: jansen', $html );
    }

    /**
     * #3318 — the chip and the control it describes must read the same group
     * key, and the only way to prove that is to assert on BOTH in one render.
     *
     * Asserting the chip alone is what let the derivation ship reading keys
     * no caller passes: the fixture said `value`, the chip appeared, and the
     * <select> it claimed to describe rendered with nothing selected. Every
     * surface in the app had the mirror image — a correct select and no chip.
     */
    public function test_a_selects_chip_and_its_rendered_option_agree(): void {
        $html = $this->render();

        // The control really is on Ajax U17 …
        $this->assertMatchesRegularExpression(
            '/<option value="2" selected>Ajax U17<\/option>/',
            $html,
            'The select should render its `selected` option as selected.'
        );
        // … and the chip says so.
        $this->assertStringContainsString( 'Team: Ajax U17', $html );
    }

    /** A toggle chips off `on` — the key renderToggle() reads (#3318). */
    public function test_a_toggle_chips_when_it_is_on(): void {
        $html = $this->render( [], [
            [
                'type'  => 'toggle',
                'key'   => 'cancelled',
                'name'  => 'show_cancelled',
                'label' => 'Show cancelled',
                'on'    => true,
            ],
        ] );

        // The switch really is on …
        $this->assertStringContainsString( 'tt-switch tt-switch--on', $html );
        // … and it carries a chip, with no "Label: value" — the label of a
        // toggle already reads as a statement.
        $this->assertStringContainsString( 'Show cancelled', $html );
        $this->assertMatchesRegularExpression(
            '/tt-chip__clear[^>]*aria-label="Remove filter Show cancelled/',
            $html
        );
    }

    /** An off toggle is not a filter, so it gets no chip (#3318). */
    public function test_a_toggle_that_is_off_gets_no_chip(): void {
        $html = $this->render( [], [
            [
                'type'  => 'toggle',
                'key'   => 'cancelled',
                'name'  => 'show_cancelled',
                'label' => 'Show cancelled',
                'on'    => false,
            ],
        ] );

        $this->assertStringNotContainsString( 'Remove filter Show cancelled', $html );
    }

    /**
     * The cluster is gated on the derived chips too, not only on what the
     * caller passed — otherwise a surface deriving chips but passing no
     * reset URL renders none of them (#3318).
     */
    public function test_derived_chips_render_without_a_reset_url(): void {
        $html = $this->render( [ 'reset_url' => '' ] );

        $this->assertStringContainsString( '<div class="tt-filterbar__utils">', $html );
        $this->assertStringContainsString( 'Team: Ajax U17', $html );
        $this->assertStringNotContainsString( 'tt-filterbar__clear', $html );
    }

    /**
     * The interaction the chip shape already implies: a ✕ that removes ONLY
     * that filter and leaves the others set.
     */
    public function test_a_chip_clears_only_its_own_param(): void {
        $html = $this->render();

        $this->assertMatchesRegularExpression( '/tt-chip__clear[^>]*aria-label="Remove filter Team/', $html );

        // The team chip's URL drops team_id and keeps q.
        preg_match_all( '/<a class="tt-chip__clear" href="([^"]+)"/', $html, $m );
        $urls = array_map( 'html_entity_decode', $m[1] );
        $this->assertNotEmpty( $urls );

        $team_url = $urls[0];
        $this->assertStringNotContainsString( 'team_id=2', $team_url );
        $this->assertStringContainsString( 'q=jansen', $team_url );
    }

    /** Every ✕ carries a discernible name — it is an icon-only control. */
    public function test_every_clear_link_has_an_accessible_name(): void {
        $html = $this->render();

        preg_match_all( '/<a class="tt-chip__clear"[^>]*>/', $html, $links );
        $this->assertNotEmpty( $links[0] );
        foreach ( $links[0] as $link ) {
            $this->assertStringContainsString( 'aria-label=', $link );
        }
    }

    /**
     * A link-based group clears back to its OWN default option's URL, not to
     * a param-free URL some other group owns — several of these have no
     * "none" option to fall back on otherwise.
     */
    public function test_a_link_group_clears_to_its_default_option(): void {
        $html = $this->render( [], [
            [
                'type'          => 'menu',
                'key'           => 'archived',
                'label'         => 'More',
                'default_value' => '',
                'options'       => [
                    [ 'value' => '',  'label' => 'Active',   'url' => '/dash/?tt_view=players&team_id=2' ],
                    [ 'value' => '1', 'label' => 'Archived', 'url' => '/dash/?tt_view=players&archived=1', 'active' => true ],
                ],
            ],
        ] );

        $this->assertStringContainsString( 'More: Archived', $html );
        $this->assertStringContainsString( '/dash/?tt_view=players&#038;team_id=2', $html );
    }

    /** A group sitting on its default is not a filter and gets no chip. */
    public function test_a_default_link_group_gets_no_chip(): void {
        $html = $this->render( [], [
            [
                'type'          => 'menu',
                'key'           => 'archived',
                'label'         => 'More',
                'default_value' => '',
                'options'       => [
                    [ 'value' => '',  'label' => 'Active', 'url' => '/dash/?tt_view=players', 'active' => true ],
                    [ 'value' => '1', 'label' => 'Archived', 'url' => '/dash/?tt_view=players&archived=1' ],
                ],
            ],
        ] );

        $this->assertStringNotContainsString( 'More: Active', $html );
    }

    /** The badge cannot disagree with the chips — one source of truth. */
    public function test_the_badge_counts_the_derived_chips(): void {
        $html = $this->render();

        $this->assertMatchesRegularExpression( '/tt-filterbtn__badge">2</', $html );
    }

    /**
     * A caller still passing `chips` keeps its labels. They cannot be
     * removable — bare strings carry no param to drop — but they are no
     * longer hidden from assistive tech, which was wrong either way.
     */
    public function test_caller_supplied_chips_still_render_and_are_not_hidden(): void {
        $html = $this->render( [ 'chips' => [ 'Period: This month' ], 'active_count' => 1 ] );

        $this->assertStringContainsString( 'Period: This month', $html );
        $this->assertStringNotContainsString( 'aria-hidden="true"><span class="tt-chip', $html );
        // The caller's own count is respected rather than overwritten.
        $this->assertMatchesRegularExpression( '/tt-filterbtn__badge">1</', $html );
    }
}
