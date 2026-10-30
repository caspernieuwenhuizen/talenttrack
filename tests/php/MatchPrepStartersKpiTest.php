<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #4319 — the "1st-half starters" tile carries the hook the script updates.
 *
 * The tile was rendered once and nothing touched it, so a new formation or a
 * player dropped on the pitch showed only after a reload. The script now
 * rewrites it from `renderPitches()`; this pins the server side of that
 * contract: the hook, and the server value the script must reproduce.
 */
final class MatchPrepStartersKpiTest extends WP_UnitTestCase {

    private int $activity = 0;
    private int $user     = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'JO8-1', 'football_form' => '6v6' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'           => 1,
            'team_id'           => $team,
            'title'             => 'Hedel - Den Bosch',
            'session_date'      => '2026-09-26',
            'activity_type_key' => 'match',
        ] );
        $this->activity = (int) $wpdb->insert_id;
        ( new MatchPrepRepository() )->ensureForActivity( $this->activity );

        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );
    }

    public function tear_down(): void {
        unset( $_GET['activity_id'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_starters_tile_carries_the_script_hook_and_the_server_value(): void {
        $_GET['activity_id'] = (string) $this->activity;
        ob_start();
        FrontendMatchPrepView::render( $this->user, true );
        $html = (string) ob_get_clean();

        $this->assertSame( 1, substr_count( $html, 'data-tt-mp-starters-kpi="1"' ) );
        $this->assertMatchesRegularExpression(
            '#data-tt-mp-starters-kpi="1"><span class="tt-kpi__label">[^<]*</span><span class="tt-kpi__val">0/6</span>#',
            $html,
            'a six-a-side team with nobody placed reads 0/6, as the script computes it'
        );
    }

    public function test_the_script_updates_the_tile_from_the_pitch(): void {
        $js = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/frontend-match-prep.js' );

        $this->assertStringContainsString( 'function renderStartersKpi()', $js );
        $this->assertStringContainsString( '[data-tt-mp-starters-kpi]', $js );
        $this->assertStringContainsString( 'currentSlotLayout().length', $js );

        $start = (int) strpos( $js, 'function renderPitches()' );
        $end   = (int) strpos( $js, 'function renderStartersKpi()' );
        $this->assertStringContainsString( 'renderStartersKpi();', substr( $js, $start, $end - $start ), 'renderPitches() redraws the tile' );
    }
}
