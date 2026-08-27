<?php
/**
 * RecordSpine rendering (#2479).
 *
 * The component composes and does not decide, so what is worth pinning is
 * the shell gate (nothing at all under `classic` — that is the rollback
 * contract), the escaping, and the degenerate inputs a calling view can
 * realistically hand it.
 */

use TT\Shared\Frontend\Components\RecordSpine;
use TT\Shared\Frontend\ShellPreference;

class RecordSpineTest extends WP_UnitTestCase {

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );
        ShellPreference::setClubDefault( ShellPreference::APP );
        delete_user_meta( $this->user_id, ShellPreference::USER_META_KEY );
    }

    private function render( array $config ): string {
        ob_start();
        RecordSpine::render( $config );
        return (string) ob_get_clean();
    }

    public function test_emits_nothing_under_the_classic_shell(): void {
        ShellPreference::setClubDefault( ShellPreference::CLASSIC );

        $this->assertSame( '', $this->render( [ 'name' => 'Ajax JO15-1' ] ) );
    }

    public function test_renders_the_identity_under_the_app_shell(): void {
        $html = $this->render( [ 'name' => 'Ajax JO15-1', 'meta' => 'JO15' ] );

        $this->assertStringContainsString( 'tt-spine', $html );
        $this->assertStringContainsString( 'Ajax JO15-1', $html );
        $this->assertStringContainsString( 'JO15', $html );
    }

    public function test_identity_is_aria_hidden_so_the_record_is_not_announced_twice(): void {
        // The accessible name is the view's own <h1>; this copy is for the
        // eye while scrolled.
        $html = $this->render( [ 'name' => 'Ajax JO15-1' ] );

        $this->assertStringContainsString( 'aria-hidden="true"', $html );
    }

    public function test_a_nameless_record_renders_nothing_rather_than_an_empty_strip(): void {
        $this->assertSame( '', $this->render( [ 'name' => '' ] ) );
        $this->assertSame( '', $this->render( [ 'name' => '   ' ] ) );
        $this->assertSame( '', $this->render( [] ) );
    }

    public function test_falls_back_to_initials_when_there_is_no_photo(): void {
        $html = $this->render( [ 'name' => 'Sem de Vries' ] );

        $this->assertStringContainsString( 'SD', $html );
        $this->assertStringNotContainsString( '<img', $html );
    }

    public function test_uses_the_photo_when_one_is_given(): void {
        $html = $this->render( [
            'name'      => 'Sem de Vries',
            'photo_url' => 'https://example.test/p.jpg',
        ] );

        $this->assertStringContainsString( 'tt-spine__photo', $html );
        $this->assertStringContainsString( 'https://example.test/p.jpg', $html );
    }

    public function test_escapes_the_name(): void {
        $html = $this->render( [ 'name' => '<script(alert(1))/script(' ] );

        $this->assertStringNotContainsString( '<script', $html );
        $this->assertStringContainsString( '&lt;script', $html );
    }

    public function test_tabs_render_only_when_supplied(): void {
        $without = $this->render( [ 'name' => 'Ajax JO15-1' ] );
        $this->assertStringNotContainsString( 'tt-spine__tabs', $without );

        $with = $this->render( [
            'name' => 'Ajax JO15-1',
            'tabs' => [
                [ 'label' => 'Overview', 'url' => 'https://example.test/?tab=overview', 'active' => true ],
                [ 'label' => 'Roster',   'url' => 'https://example.test/?tab=roster' ],
            ],
        ] );
        $this->assertStringContainsString( 'tt-spine__tabs', $with );
        $this->assertStringContainsString( 'aria-current="page"', $with );
        $this->assertStringContainsString( 'Roster', $with );
    }

    public function test_incomplete_tabs_are_skipped_rather_than_rendered_dead(): void {
        $html = $this->render( [
            'name' => 'Ajax JO15-1',
            'tabs' => [
                [ 'label' => 'Overview', 'url' => '' ],
                [ 'label' => '',         'url' => 'https://example.test/' ],
                [ 'label' => 'Roster',   'url' => 'https://example.test/?tab=roster' ],
            ],
        ] );

        $this->assertStringContainsString( 'Roster', $html );
        $this->assertStringNotContainsString( 'Overview', $html );
    }

    public function test_initials_handle_single_and_empty_names(): void {
        $this->assertSame( 'S', RecordSpine::initials( 'Sem' ) );
        $this->assertSame( 'SD', RecordSpine::initials( 'Sem de Vries' ) );
        $this->assertSame( '?', RecordSpine::initials( '' ) );
    }

    /* ---- In-page tabs (#2932) --------------------------------------- */

    /** @return list<array<string,mixed>> */
    private function panelTabs(): array {
        return [
            [ 'label' => 'Squad', 'panel' => 'tt-panel-squad', 'active' => true ],
            [ 'label' => 'Pitch', 'panel' => 'tt-panel-pitch' ],
        ];
    }

    public function test_in_page_tabs_emit_tablist_semantics(): void {
        $html = $this->render( [ 'name' => 'Ajax JO15-1', 'tabs' => $this->panelTabs() ] );

        $this->assertStringContainsString( 'role="tablist"', $html );
        $this->assertStringContainsString( 'role="tab"', $html );
        $this->assertStringContainsString( 'aria-controls="tt-panel-squad"', $html );
        $this->assertStringContainsString( 'id="tt-tab-tt-panel-squad"', $html );
        $this->assertStringContainsString( '<button', $html );
        // Never a link: an in-page tab has no destination to navigate to.
        $this->assertStringNotContainsString( 'aria-current="page"', $html );
    }

    public function test_in_page_tabs_carry_a_roving_tabindex(): void {
        $html = $this->render( [ 'name' => 'Ajax JO15-1', 'tabs' => $this->panelTabs() ] );

        // Exactly one tab stop; the arrow keys reach the rest.
        $this->assertSame( 1, substr_count( $html, 'tabindex="0"' ) );
        $this->assertSame( 1, substr_count( $html, 'tabindex="-1"' ) );
        $this->assertStringContainsString( 'aria-selected="true"', $html );
        $this->assertStringContainsString( 'aria-selected="false"', $html );
    }

    public function test_without_an_active_tab_the_first_one_takes_the_stop(): void {
        // A caller that forgets `active` must not produce a strip no
        // keyboard can enter.
        $html = $this->render( [
            'name' => 'Ajax JO15-1',
            'tabs' => [
                [ 'label' => 'Squad', 'panel' => 'tt-panel-squad' ],
                [ 'label' => 'Pitch', 'panel' => 'tt-panel-pitch' ],
            ],
        ] );

        $this->assertSame( 1, substr_count( $html, 'tabindex="0"' ) );
        $this->assertSame( 1, substr_count( $html, 'aria-selected="true"' ) );
    }

    public function test_in_page_tabs_survive_the_classic_shell(): void {
        // The identity strip is app-shell chrome and still disappears; the
        // switcher is the only route to the panels, so it must not.
        ShellPreference::setClubDefault( ShellPreference::CLASSIC );

        $html = $this->render( [ 'name' => 'Ajax JO15-1', 'tabs' => $this->panelTabs() ] );

        $this->assertStringContainsString( 'tt-spine--tabs-only', $html );
        $this->assertStringContainsString( 'role="tablist"', $html );
        $this->assertStringContainsString( 'Pitch', $html );
        $this->assertStringNotContainsString( 'tt-spine__id', $html );
        $this->assertStringNotContainsString( 'Ajax JO15-1', $html );
    }

    public function test_navigating_tabs_still_vanish_under_classic(): void {
        // Unchanged behaviour: a row of links to other pages is not a way
        // into hidden content, so the rollback contract still covers it.
        ShellPreference::setClubDefault( ShellPreference::CLASSIC );

        $this->assertSame( '', $this->render( [
            'name' => 'Ajax JO15-1',
            'tabs' => [ [ 'label' => 'Overview', 'url' => 'https://example.test/' ] ],
        ] ) );
    }

    public function test_in_page_tabs_render_for_a_nameless_record(): void {
        $html = $this->render( [ 'name' => '', 'tabs' => $this->panelTabs() ] );

        $this->assertStringContainsString( 'role="tablist"', $html );
        $this->assertStringContainsString( 'tt-spine--tabs-only', $html );
    }

    public function test_one_panel_entry_makes_the_whole_strip_in_page(): void {
        // Mixing is unsupported; the panel kind wins and the url entry is
        // skipped rather than rendered as a stray link inside a tablist.
        $html = $this->render( [
            'name' => 'Ajax JO15-1',
            'tabs' => [
                [ 'label' => 'Squad',  'panel' => 'tt-panel-squad', 'active' => true ],
                [ 'label' => 'Roster', 'url'   => 'https://example.test/?tab=roster' ],
            ],
        ] );

        $this->assertStringContainsString( 'role="tablist"', $html );
        $this->assertStringNotContainsString( '<a ', $html );
        $this->assertStringNotContainsString( 'Roster', $html );
    }

    public function test_in_page_tabs_escape_label_and_panel_id(): void {
        $html = $this->render( [
            'name' => 'Ajax JO15-1',
            'tabs' => [ [
                'label' => '<script(alert(1))/script(',
                'panel' => 'tt"onload="alert(1)',
                'active' => true,
            ] ],
        ] );

        $this->assertStringNotContainsString( '<script', $html );
        $this->assertStringNotContainsString( 'onload="alert', $html );
    }

    public function test_tab_id_is_derived_from_the_panel_id(): void {
        $this->assertSame( 'tt-tab-tt-panel-squad', RecordSpine::tabId( 'tt-panel-squad' ) );
    }
}
