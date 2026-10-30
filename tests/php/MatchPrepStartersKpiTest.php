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
    private int $team     = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'JO8-1', 'football_form' => '6v6' ] );
        $team       = (int) $wpdb->insert_id;
        $this->team = $team;
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

    public function test_players_kept_off_the_current_layout_do_not_count_as_starters(): void {
        // A full 4-3-3 (slots 1-11) left in place after a switch to the
        // six-a-side 3-2-1, which draws slots 1-6 only.
        $prep_id = ( new MatchPrepRepository() )->ensureForActivity( $this->activity );
        $slots   = [];
        for ( $slot = 1; $slot <= 11; $slot++ ) {
            $slots[ $slot ] = 9000 + $slot;
        }
        ( new MatchPrepRepository() )->replaceLineupForHalf( $prep_id, 1, $slots );

        $this->assertStringContainsString( '<span class="tt-kpi__val">6/6</span>', $this->renderPrep(), 'eleven stored, six on the pitch: 6/6, not 11/6' );

        unset( $slots[3] );
        ( new MatchPrepRepository() )->replaceLineupForHalf( $prep_id, 1, $slots );

        $html = $this->renderPrep();
        $this->assertMatchesRegularExpression(
            '#data-tt-mp-starters-kpi="1"><span class="tt-kpi__label">[^<]*</span><span class="tt-kpi__val">5/6</span>#',
            $html,
            'a cleared visible slot reads 5/6 although slots 7-11 still hold players'
        );
    }

    /**
     * #4346 — a player left on slot 9 of a 4-3-3 after the switch to 3-2-1
     * plays no minutes on this pitch; a player on slot 2 does.
     */
    public function test_roster_minutes_count_only_players_on_the_current_layout(): void {
        global $wpdb;
        $ids = [];
        foreach ( [ 'Daan', 'Sem' ] as $first ) {
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'    => 1,
                'team_id'    => $this->team,
                'first_name' => $first,
                'last_name'  => 'Roster',
                'status'     => 'active',
            ] );
            $ids[] = (int) $wpdb->insert_id;
        }
        [ $on_pitch, $off_layout ] = $ids;

        $repo    = new MatchPrepRepository();
        $prep_id = $repo->ensureForActivity( $this->activity );
        $repo->replaceAvailability( $prep_id, [
            $on_pitch   => [ 'status' => 'Present' ],
            $off_layout => [ 'status' => 'Present' ],
        ] );
        $repo->replaceLineupForHalf( $prep_id, 1, [ 2 => $on_pitch, 9 => $off_layout ] );

        $html = $this->renderPrep();

        $this->assertMatchesRegularExpression(
            '#<tr data-pid="' . $off_layout . '"\s+class="tt-mp-roster-row tt-mp-unassigned"#',
            $html,
            'slot 9 is not on a 3-2-1, so the player is not on the pitch'
        );
        $this->assertMatchesRegularExpression(
            '#<tr data-pid="' . $off_layout . '"(?:(?!</tr>).)*?data-tt-mp-min="tot">0</td>#s',
            $html,
            'and plays no minutes'
        );
        $this->assertMatchesRegularExpression(
            '#<tr data-pid="' . $on_pitch . '"\s+class="tt-mp-roster-row tt-mp-assigned"#',
            $html,
            'slot 2 is on a 3-2-1'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<tr data-pid="' . $on_pitch . '"(?:(?!</tr>).)*?data-tt-mp-min="tot">0</td>#s',
            $html,
            'and plays the first half'
        );
    }

    private function renderPrep(): string {
        $_GET['activity_id'] = (string) $this->activity;
        ob_start();
        FrontendMatchPrepView::render( $this->user, true );
        return (string) ob_get_clean();
    }

    public function test_the_script_updates_the_tile_from_the_pitch(): void {
        $js = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/frontend-match-prep.js' );

        $this->assertStringContainsString( 'function renderStartersKpi()', $js );
        $this->assertStringContainsString( '[data-tt-mp-starters-kpi]', $js );

        $kpi_start = (int) strpos( $js, 'function renderStartersKpi()' );
        $kpi_body  = substr( $js, $kpi_start, (int) strpos( $js, 'function renderDps()' ) - $kpi_start );
        $this->assertStringContainsString( 'currentSlotLayout()', $kpi_body );
        $this->assertStringContainsString( "state.lineup['1'].get(pos.num)", $kpi_body, 'counts only slots the current layout draws' );
        $this->assertStringNotContainsString( "state.lineup['1'].forEach", $kpi_body, 'does not count every stored entry' );

        $start = (int) strpos( $js, 'function renderPitches()' );
        $end   = (int) strpos( $js, 'function renderStartersKpi()' );
        $this->assertStringContainsString( 'renderStartersKpi();', substr( $js, $start, $end - $start ), 'renderPitches() redraws the tile' );
    }

    /**
     * #4346 — the minutes footer counts out of the current layout's
     * positions, not a fixed eleven, and follows a formation change.
     */
    public function test_the_minutes_footer_counts_out_of_the_current_layout(): void {
        $js = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/frontend-match-prep.js' );

        $start = (int) strpos( $js, 'function renderFoot()' );
        $foot  = substr( $js, $start, (int) strpos( $js, 'function setFoot(' ) - $start );
        $this->assertStringNotContainsString( "' / 11'", $foot );
        $this->assertStringContainsString( 'currentSlotLayout()', $foot );
        $this->assertStringContainsString( 'layout.length', $foot );

        // The numerator and the minutes count only slots the layout draws,
        // not every stored entry, so a 4-3-3 switched to 3-2-1 reads 6 / 6.
        $this->assertStringNotContainsString( '.size', $foot );
        $this->assertStringContainsString( "state.lineup['1'].get(pos.num)", $foot );
        $this->assertStringContainsString( "state.lineup['2'].get(pos.num)", $foot );
        $this->assertStringContainsString( 'var m1 = c1 * state.halfLength;', $foot );
        $this->assertStringContainsString( 'var m2 = c2 * state.halfLength;', $foot );

        $change = (int) strpos( $js, "formationSel.addEventListener('change'" );
        $this->assertGreaterThan( 0, $change );
        $handler = substr( $js, $change, (int) strpos( $js, 'saveAll();', $change ) - $change );
        $this->assertStringContainsString( 'renderFoot();', $handler, 'a formation change redraws the footer' );
        $this->assertStringContainsString( 'renderRoster();', $handler, 'and the roster minutes' );

        // The roster's minutes ask whether the player is on the layout, not
        // merely stored in the half.
        $roster_start = (int) strpos( $js, 'function renderRoster()' );
        $roster       = substr( $js, $roster_start, 1500 );
        $this->assertStringContainsString( 'var on1 = isOnLayout(1, p.id);', $roster );
        $this->assertStringContainsString( 'var on2 = isOnLayout(2, p.id);', $roster );
        $helper_start = (int) strpos( $js, 'function isOnLayout(' );
        $this->assertGreaterThan( 0, $helper_start );
        $this->assertStringContainsString( 'currentSlotLayout().some(', substr( $js, $helper_start, 300 ) );
    }
}
