<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\MatchExecutionLayout;
use TT\Modules\MatchExecution\Frontend\FrontendMatchExecutionView;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #3848 — the Edit toggle is on the tab that asks for it.
 *
 * The review panel's own copy says "Turn on Edit to correct any
 * datapoint". Under the sectioned shell the only toggle lived in the
 * header, which is routed to the Pitch panel — two taps from the tab a
 * post-match screen opens on, and until it was pressed every correction
 * control on all four tabs stayed hidden. The first thing a coach does
 * after a match read as "you cannot do this here".
 *
 * One root `data-edit-mode` still decides; this is a second way to reach
 * it, not a second source of truth.
 */
final class MatchExecutionReviewEditToggleTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8484;

    private int $user_id = 0;
    private int $exec_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Review Team' ] );
        $team_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => $team_id,
            'title'             => 'Review match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, 35 );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3 ] );

        $repo          = new MatchExecutionRepository();
        $this->exec_id = $repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $repo->update( $this->exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
    }

    public function tear_down(): void {
        unset( $_GET['activity_id'] );
        parent::tear_down();
    }

    private function renderSectioned(): string {
        update_user_meta( $this->user_id, MatchExecutionLayout::USER_META_KEY, MatchExecutionLayout::SECTIONS );
        return $this->render();
    }

    private function render(): string {
        $_GET['activity_id'] = (string) self::ACTIVITY_ID;
        ob_start();
        FrontendMatchExecutionView::render( $this->user_id, true );
        return (string) ob_get_clean();
    }

    /** The review panel's markup, or '' when there is none. */
    private function reviewPanel( string $html ): string {
        $at = strpos( $html, 'id="tt-mexec-panel-review"' );
        if ( $at === false ) return '';
        $end = strpos( $html, 'id="tt-mexec-panel-squad"', $at );
        return substr( $html, $at, $end === false ? null : $end - $at );
    }

    public function test_the_review_panel_carries_its_own_edit_toggle(): void {
        $panel = $this->reviewPanel( $this->renderSectioned() );

        $this->assertNotSame( '', $panel, 'the sectioned shell renders a review panel' );
        $this->assertStringContainsString( 'data-tt-mexec-edit-toggle', $panel );
    }

    /** The whole point: the coach never has to leave the tab to reach it. */
    public function test_the_toggle_is_above_the_copy_that_asks_for_it(): void {
        $panel = $this->reviewPanel( $this->renderSectioned() );

        $toggle = strpos( $panel, 'data-tt-mexec-edit-toggle' );
        $copy   = strpos( $panel, 'tt-mexec-finalize-help' );

        $this->assertIsInt( $toggle );
        $this->assertIsInt( $copy );
        $this->assertLessThan( $copy, $toggle, 'the control comes before the sentence asking for it' );
    }

    public function test_the_classic_shell_keeps_exactly_one_toggle(): void {
        update_user_meta( $this->user_id, MatchExecutionLayout::USER_META_KEY, MatchExecutionLayout::CLASSIC );
        $html = $this->render();

        $this->assertSame( 1, substr_count( $html, 'data-tt-mexec-edit-toggle' ), 'nothing changes under the classic shell' );
    }

    public function test_the_sectioned_shell_has_two_toggles_and_one_edit_mode(): void {
        $html = $this->renderSectioned();

        $this->assertSame( 2, substr_count( $html, 'data-tt-mexec-edit-toggle' ), 'the header keeps its own' );
        $this->assertSame( 1, substr_count( $html, 'data-edit-mode=' ), 'one root attribute decides for both' );
    }

    public function test_a_finalized_match_offers_no_toggle_anywhere(): void {
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );

        $html = $this->renderSectioned();

        $this->assertStringNotContainsString( 'data-tt-mexec-edit-toggle', $html );
    }

    // ---- the shared helper, per state ------------------------------------

    private function toggleHtml( string $state, string $mode ): string {
        $m = new ReflectionMethod( FrontendMatchExecutionView::class, 'renderEditToggle' );
        $m->setAccessible( true );
        ob_start();
        $m->invoke( null, $state, $mode );
        return (string) ob_get_clean();
    }

    public function test_the_helper_renders_only_where_the_toggle_belongs(): void {
        $this->assertStringContainsString(
            'data-tt-mexec-edit-toggle',
            $this->toggleHtml( MatchExecutionState::PENDING_REVIEW, 'off' )
        );

        foreach ( [
            MatchExecutionState::NOT_STARTED,
            MatchExecutionState::FIRST_HALF,
            MatchExecutionState::HALF_TIME,
            MatchExecutionState::SECOND_HALF,
            MatchExecutionState::FINALIZED,
        ] as $state ) {
            $this->assertSame( '', trim( $this->toggleHtml( $state, 'off' ) ), $state . ' needs no toggle' );
        }
    }

    public function test_the_pressed_state_and_label_follow_the_mode(): void {
        $off = $this->toggleHtml( MatchExecutionState::PENDING_REVIEW, 'off' );
        $this->assertStringContainsString( 'aria-pressed="false"', $off );

        $on = $this->toggleHtml( MatchExecutionState::PENDING_REVIEW, 'on' );
        $this->assertStringContainsString( 'aria-pressed="true"', $on );

        // Both labels travel with the button, so the JS can swap them
        // without a round trip.
        $this->assertStringContainsString( 'data-label-edit=', $off );
        $this->assertStringContainsString( 'data-label-done=', $off );
    }
}
