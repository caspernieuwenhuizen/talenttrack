<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Filters\SavedViewsDefaults;
use TT\Infrastructure\Filters\SavedViewsRegistry;
use TT\Infrastructure\Filters\SavedViewsRepository;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Shared\Frontend\FrontendStandardReportsView;

/**
 * #3461 (epic #3457) — saved compositions for the team monthly report.
 *
 * Pinned: the surface is registered and its default applies on arrival; an
 * explicit composition on the URL wins; a preset saved before a section or
 * layout existed still opens a report, dropping only what it cannot resolve;
 * no section list means every section; presets stay personal; and the
 * composition is a plain array a schedule can copy.
 */
final class TeamMonthlyReportPresetsTest extends WP_UnitTestCase {

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Preset U12', 'age_group' => 'U12' ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        $_GET = [];
        parent::tear_down();
    }

    public function test_the_surface_is_registered_and_routes_its_default(): void {
        $this->assertSame( 'tt_view_analytics', SavedViewsRegistry::capabilityFor( TeamMonthlyReportComposition::VIEW_KEY ) );

        $_GET = [ 'tt_view' => 'standard-report', 'slug' => 'team-monthly' ];
        $this->assertSame( TeamMonthlyReportComposition::VIEW_KEY, SavedViewsDefaults::surfaceForRequest() );
        $this->assertFalse( SavedViewsDefaults::hasOwnFilters( TeamMonthlyReportComposition::VIEW_KEY ), 'Arriving from the tile applies the default.' );

        $_GET = [ 'tt_view' => 'standard-report', 'slug' => 'minutes-share' ];
        $this->assertSame( '', SavedViewsDefaults::surfaceForRequest(), 'Another standard report does not pick up the monthly default.' );
    }

    public function test_an_explicit_composition_on_the_url_wins_over_the_default(): void {
        foreach ( [ 'layout' => 'C', 'blocks' => 'kpi', 'team_id' => '4', 'period' => 'this_month' ] as $param => $value ) {
            $_GET = [ 'tt_view' => 'standard-report', 'slug' => 'team-monthly', $param => $value ];
            $this->assertTrue( SavedViewsDefaults::hasOwnFilters( TeamMonthlyReportComposition::VIEW_KEY ), "{$param} on the URL must suppress the default." );
        }
    }

    public function test_a_preset_from_before_a_section_existed_still_resolves(): void {
        $c = TeamMonthlyReportComposition::normalise( [
            'team_id' => '7',
            'period'  => 'last_month',
            'layout'  => 'Z',
            'blocks'  => 'kpi,heatmap,roster,kpi',
        ] );

        $this->assertSame( 7, $c['team_id'] );
        $this->assertSame( TeamMonthlyReportLayout::DEFAULT, $c['layout'], 'An unknown layout falls back to the default.' );
        $this->assertSame( [ 'kpi', 'roster' ], $c['blocks'], 'An unknown section is dropped; the rest survive, once.' );
    }

    public function test_no_section_list_means_every_section(): void {
        $c = TeamMonthlyReportComposition::normalise( [ 'team_id' => 3 ] );
        $this->assertSame( [], $c['blocks'] );
        $this->assertSame( TeamMonthlyReportBlock::ALL, TeamMonthlyReportBlock::normalise( $c['blocks'] ) );
        $this->assertTrue( TeamMonthlyReportComposition::same( $c, array_merge( $c, [ 'blocks' => TeamMonthlyReportBlock::ALL ] ) ) );
    }

    public function test_a_malformed_window_falls_back_to_last_month(): void {
        $c = TeamMonthlyReportComposition::normalise( [ 'from' => '2026-09-30', 'to' => '2026-09-01', 'period' => 'yesterday' ] );
        $this->assertSame( 'last_month', $c['period'] );
        $this->assertSame( [ 'from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'last_month' ], TeamMonthlyReportComposition::window( $c, '2026-10-01' ) );

        $custom = TeamMonthlyReportComposition::normalise( [ 'from' => '2026-08-01', 'to' => '2026-08-15', 'period' => 'last_month' ] );
        $this->assertSame( '', $custom['period'], 'An explicit window wins over a stale period.' );
    }

    public function test_a_stale_preset_url_renders_the_report(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $_GET = [
            'tt_view' => 'standard-report', 'slug' => 'team-monthly', 'team_id' => (string) $this->team_id,
            'from' => '2020-03-01', 'to' => '2020-03-31', 'layout' => 'Q', 'blocks' => 'heatmap,kpi',
        ];
        ob_start();
        FrontendStandardReportsView::render( get_current_user_id(), true );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-mr-panel', $html );
        $this->assertStringContainsString( 'value="B" checked', $html, 'The unknown layout rendered as the default.' );
    }

    public function test_presets_are_personal_and_the_panel_names_the_active_one(): void {
        $owner = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $other = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $repo  = new SavedViewsRepository();

        $stored = [ 'team_id' => (string) $this->team_id, 'layout' => 'A', 'blocks' => 'kpi,attention' ];
        $view   = $repo->create( $owner, TeamMonthlyReportComposition::VIEW_KEY, 'Staff meeting', $stored );
        $this->assertNotNull( $view );
        $repo->setDefault( (int) $view->id, $owner );

        $this->assertCount( 1, $repo->listForUser( $owner, TeamMonthlyReportComposition::VIEW_KEY ) );
        $this->assertSame( [], $repo->listForUser( $other, TeamMonthlyReportComposition::VIEW_KEY ), 'Another user does not see it.' );
        $this->assertNull( $repo->update( (int) $view->id, $other, 'Hijacked' ), 'Another user cannot rename it.' );
        $this->assertFalse( $repo->delete( (int) $view->id, $other ), 'Another user cannot delete it.' );

        $current = TeamMonthlyReportComposition::normalise( $stored );
        $this->assertSame( [ 'state' => 'active', 'name' => 'Staff meeting' ], TeamMonthlyReportComposition::savedViewStatus( $owner, $current ) );
        $this->assertSame( 'none', TeamMonthlyReportComposition::savedViewStatus( $other, $current )['state'] );

        $changed = TeamMonthlyReportComposition::normalise( array_merge( $stored, [ 'layout' => 'B' ] ) );
        $this->assertSame( [ 'state' => 'drifted', 'name' => 'Staff meeting' ], TeamMonthlyReportComposition::savedViewStatus( $owner, $changed ) );
    }

    public function test_the_composition_is_a_plain_array_a_schedule_can_copy(): void {
        $c = TeamMonthlyReportComposition::normalise( [ 'team_id' => 9, 'period' => 'last_month', 'layout' => 'b', 'blocks' => [ 'roster' ] ] );

        $this->assertSame( TeamMonthlyReportComposition::PARAMS, array_keys( $c ) );
        $copy = json_decode( (string) wp_json_encode( $c ), true );
        $this->assertTrue( TeamMonthlyReportComposition::same( $c, TeamMonthlyReportComposition::normalise( $copy ) ), 'A JSON round trip describes the same report, with no reference to a preset.' );
    }
}
