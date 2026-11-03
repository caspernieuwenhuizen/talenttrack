<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Vct\Wizard\ReviewStep;

/**
 * #4784 — the VCT wizard asks whether the user may plan for the team at the
 * moment it writes, not only on the When step.
 *
 * `ReviewStep::submit()` creates the VCT training. It used to trust the
 * team the When step had checked, so a post straight to the review step
 * with another team in the state, or a run whose rights changed in
 * between, wrote a training for a team the user may not plan.
 */
final class VctReviewStepPlanCheckTest extends WP_UnitTestCase {

    private string $p   = '';
    private int $mine   = 0;
    private int $theirs = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->mine   = $this->team( 'Review Mine' );
        $this->theirs = $this->team( 'Review Theirs' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_a_post_for_a_team_the_user_cannot_plan_is_refused_and_writes_nothing(): void {
        $coach = $this->coachOn( $this->mine );
        $this->assertFalse(
            AuthorizationService::canPlanForTeam( $coach, $this->theirs, 'create_delete' ),
            'fixture sanity: the coach may not plan for the other team'
        );
        wp_set_current_user( $coach );

        $before = $this->sessionCount();
        $result = ( new ReviewStep() )->submit( $this->state( $this->theirs ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'forbidden', $result->get_error_code() );
        $this->assertSame( $before, $this->sessionCount(), 'no VCT training was written' );
        $this->assertSame( 0, $this->sessionsFor( $this->theirs ) );
    }

    public function test_a_user_who_can_plan_for_the_team_passes_the_check(): void {
        $coach = $this->coachOn( $this->mine );
        $this->assertTrue(
            AuthorizationService::canPlanForTeam( $coach, $this->mine, 'create_delete' ),
            'fixture sanity: the coach may plan for their own team'
        );
        wp_set_current_user( $coach );

        $result = ( new ReviewStep() )->submit( $this->state( $this->mine ) );

        // Whether the composer can build a training on a bare test install
        // is the composer's business; the planning check must let it try.
        if ( $result instanceof \WP_Error ) {
            $this->assertNotSame( 'forbidden', $result->get_error_code(), 'a planner is not refused' );
        } else {
            $this->assertArrayHasKey( 'redirect_url', $result );
            $this->assertSame( 1, $this->sessionsFor( $this->mine ), 'the training is written for the planned team' );
        }
    }

    public function test_a_post_without_a_team_is_refused(): void {
        wp_set_current_user( $this->coachOn( $this->mine ) );
        $before = $this->sessionCount();

        $result = ( new ReviewStep() )->submit( $this->state( 0 ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'forbidden', $result->get_error_code() );
        $this->assertSame( $before, $this->sessionCount() );
    }

    // ---- fixtures ------------------------------------------------------

    /** @return array<string, mixed> */
    private function state( int $team_id ): array {
        return [
            'team_id'                    => $team_id,
            'session_date'               => '2031-03-12',
            'start_time'                 => '',
            '_vct_preview_age_group'     => 'U12',
            'tactical_theme'             => '',
            'requested_duration_minutes' => 75,
        ];
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => $name, 'age_group' => 'U12' ] );
        return (int) $wpdb->insert_id;
    }

    /** A head coach with a person row and a scope on one team. */
    private function coachOn( int $team_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Review',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id'    => 1,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function sessionCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_vct_sessions" );
    }

    private function sessionsFor( int $team_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_vct_sessions WHERE team_id = %d", $team_id ) );
    }
}
