<?php
/**
 * #3521 (epic #3519) — Overview / Statistics on the team page.
 *
 * Structural only. What is worth pinning is that the strip exists and comes
 * from the shared spine (CLAUDE.md §5c), that an unrecognised `tab` lands on
 * Overview rather than on nothing, that Overview is untouched, and that the
 * navigation contract holds on every path — including the two permission
 * refusals, which are where a breadcrumb chain usually goes missing.
 */

use TT\Infrastructure\Teams\TeamDetailSections;
use TT\Shared\Frontend\FrontendTeamDetailView;
use TT\Shared\Frontend\ShellPreference;

class TeamDetailTabsTest extends WP_UnitTestCase {

    private const TEAM_ID = 991;

    private int $admin_id = 0;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $wpdb->hide_errors();

        $this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin_id );
        ShellPreference::setClubDefault( ShellPreference::APP );
        delete_user_meta( $this->admin_id, ShellPreference::USER_META_KEY );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'   => 1,
            'id'        => self::TEAM_ID,
            'name'      => 'Ajax JO15-1',
            'age_group' => 'JO15',
        ] );
    }

    public function tear_down(): void {
        unset( $_GET['tab'] );
        parent::tear_down();
    }

    private function render( ?string $tab = null ): string {
        if ( $tab === null ) {
            unset( $_GET['tab'] );
        } else {
            $_GET['tab'] = $tab;
        }

        ob_start();
        FrontendTeamDetailView::render( self::TEAM_ID, $this->admin_id, true );
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // The strip
    // ---------------------------------------------------------------

    public function test_the_team_page_renders_an_overview_statistics_strip(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'tt-spine__tab', $html, 'the strip comes from RecordSpine, not a hand-rolled row' );
        $this->assertStringContainsString( 'Overview', $html );
        $this->assertStringContainsString( 'Statistics', $html );
    }

    public function test_the_tabs_navigate_on_the_teams_slug_rather_than_a_new_route(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'tab=stats', $html );
        $this->assertStringContainsString( 'tt_view=teams', $html );
        $this->assertStringNotContainsString( 'tt_view=team-stats', $html, 'no new ?tt_view= route is registered' );
    }

    public function test_the_strip_is_navigating_not_in_page(): void {
        // In-page tabs would run the statistics queries on every visit to the
        // roster. A coach opening the team page pays for the roster only.
        $html = $this->render();

        $this->assertStringNotContainsString( 'role="tab"', $html );
    }

    public function test_the_strip_survives_the_classic_shell(): void {
        // These tabs are the only route to the Statistics content, so a
        // surface that loses them under `classic` is broken, not degraded.
        ShellPreference::setClubDefault( ShellPreference::CLASSIC );

        $this->assertStringContainsString( 'tt-spine__tab', $this->render() );
    }

    // ---------------------------------------------------------------
    // Which tab
    // ---------------------------------------------------------------

    public function test_an_unknown_tab_falls_back_to_overview(): void {
        $html = $this->render( 'nonsense' );

        $this->assertStringContainsString( 'tt-player-detail__rail', $html, 'Overview content is on the page' );
    }

    public function test_an_absent_tab_falls_back_to_overview(): void {
        $this->assertStringContainsString( 'tt-player-detail__rail', $this->render() );
    }

    /**
     * #3521 asserted the placeholder here. #3522 replaced it with the real
     * content, so what this now pins is the property that outlives both: the
     * tab renders *something* of its own and never Overview's content.
     */
    public function test_the_statistics_tab_renders_its_own_content_not_a_blank_page(): void {
        $html = $this->render( 'stats' );

        $this->assertStringContainsString( 'Statistics', $html );
        $this->assertStringContainsString( 'tt-ts', $html, 'the statistics tab renders its own body' );
        $this->assertStringNotContainsString( 'tt-player-detail__rail', $html, 'Overview content belongs to Overview' );
    }

    public function test_overview_still_honours_the_per_user_section_preference(): void {
        // #3521 changes no preference and migrates none: the toggle still
        // governs what appears inside Overview.
        $sections = TeamDetailSections::forUser( $this->admin_id );

        $this->assertArrayHasKey( 'roster', $sections );
        $this->assertStringContainsString( 'tt-player-detail__main', $this->render() );
    }

    // ---------------------------------------------------------------
    // Navigation contract (CLAUDE.md §5a)
    // ---------------------------------------------------------------

    public function test_the_breadcrumb_chain_renders_on_both_tabs(): void {
        foreach ( [ null, 'stats' ] as $tab ) {
            $this->assertStringContainsString(
                'tt-breadcrumbs',
                $this->render( $tab ),
                'the chain is emitted on every path, whichever tab is open'
            );
        }
    }

    public function test_the_breadcrumb_chain_survives_a_permission_refusal(): void {
        // The early returns are where a chain usually goes missing, and this
        // view has two of them.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        ob_start();
        FrontendTeamDetailView::render( self::TEAM_ID, get_current_user_id(), false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
    }

    public function test_no_third_back_affordance_is_emitted(): void {
        $html = $this->render( 'stats' );

        $this->assertStringNotContainsString( 'Back to dashboard', $html );
        $this->assertStringNotContainsString( 'tt-back-button', $html );
    }
}
