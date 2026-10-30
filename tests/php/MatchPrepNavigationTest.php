<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #4284 — match prep emits one breadcrumb chain (CLAUDE.md §5a).
 *
 * With `match_prep` locked and a prep present, the view drew a short
 * `Dashboard / Match prep` chain (and its back pill) above the upgrade panel
 * and then fell through to the full chain below it: two chains, two pills.
 * The locked branch now only adds the panel under the one full chain.
 *
 * The test install is non-commercial, where every feature is allowed and
 * the commercial-mode constant cannot be defined for one test alone, so the
 * locked path is pinned on the source and the rendered output is checked on
 * the allowed path.
 */
final class MatchPrepNavigationTest extends WP_UnitTestCase {

    private int $activity = 0;
    private int $user     = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'JO11-1' ] );
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

    public function test_an_existing_prep_renders_one_full_chain(): void {
        $_GET['activity_id'] = (string) $this->activity;
        ob_start();
        FrontendMatchPrepView::render( $this->user, true );
        $html = (string) ob_get_clean();

        $this->assertSame( 1, substr_count( $html, '<nav class="tt-breadcrumbs"' ), 'exactly one breadcrumb chain' );
        $this->assertLessThanOrEqual( 1, substr_count( $html, 'tt-back-link-wrap' ), 'at most one back pill' );

        $start = (int) strpos( $html, '<nav class="tt-breadcrumbs"' );
        $nav   = substr( $html, $start, (int) strpos( $html, '</nav>', $start ) - $start );
        $this->assertStringContainsString( 'Hedel - Den Bosch', $nav, 'the chain runs through the match' );
    }

    /**
     * The locked branch for an existing prep adds the panel after the full
     * chain and draws no chain of its own.
     */
    public function test_the_locked_branch_adds_no_second_chain(): void {
        $src = (string) file_get_contents( TT_PLUGIN_DIR . 'src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php' );

        $empty_state = strpos( $src, "'Start match prep'" );
        $full_chain  = strpos( $src, "FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) )" );
        $panel       = strpos( $src, "UpgradePanel::render( 'match_prep', [ 'reads_kept' => true ] )" );

        $this->assertNotFalse( $empty_state );
        $this->assertNotFalse( $full_chain );
        $this->assertNotFalse( $panel );
        $this->assertGreaterThan( $full_chain, $panel, 'the panel sits under the full chain' );

        $between = substr( $src, (int) $empty_state, (int) $full_chain - (int) $empty_state );
        $this->assertSame(
            1,
            substr_count( $between, 'FrontendBreadcrumbs::fromDashboard(' ),
            'after the empty-prep branch, only the full chain is drawn'
        );
    }
}
