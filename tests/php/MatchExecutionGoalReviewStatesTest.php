<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #2858 — the three states one of our goals can be in, as the review reads
 * them back.
 *
 * "Attributed", "own goal" and "nobody recorded who scored" used to be two
 * states in storage and one on screen: everything without a scorer rendered
 * as "Our goal". Only one of them is something for a coach to go and fix,
 * so the review has to be able to tell them apart, and that starts with
 * `listGoalEvents` carrying enough to distinguish them.
 */
final class MatchExecutionGoalReviewStatesTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8285;
    private const HALF_LENGTH = 35;
    private const SQUAD_A = 1;
    private const SQUAD_B = 2;

    private int $exec_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->seedMatch();
    }

    private function seedMatch(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Review states match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3 ] );

        $exec_repo     = new MatchExecutionRepository();
        $this->exec_id = $exec_repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
    }

    /**
     * The count the review puts above the goal list: goals of ours with no
     * scorer, NOT counting own goals. An own goal has no scorer by nature and
     * asking a coach to supply one would be asking for something that does
     * not exist.
     */
    private function unattributedCount( array $events ): int {
        $n = 0;
        foreach ( $events as $ge ) {
            if ( (string) ( $ge->team ?? 'home' ) !== 'home' ) continue;
            if ( (int) $ge->player_id <= 0 && empty( $ge->is_own_goal ) ) $n++;
        }
        return $n;
    }

    public function test_the_three_home_goal_states_are_distinguishable(): void {
        $repo = new MatchExecutionRepository();
        $repo->logGoalEvent( $this->exec_id, wp_generate_uuid4(), self::SQUAD_A, 1, 10, 'home', self::SQUAD_B, false );
        $repo->logGoalEvent( $this->exec_id, wp_generate_uuid4(), 0, 1, 20, 'home', null, false );
        $repo->logGoalEvent( $this->exec_id, wp_generate_uuid4(), 0, 2, 30, 'home', null, true );

        $events = $repo->listGoalEvents( $this->exec_id );
        $this->assertCount( 3, $events );

        [ $attributed, $unknown, $own ] = $events;

        $this->assertSame( self::SQUAD_A, (int) $attributed->player_id );
        $this->assertSame( self::SQUAD_B, (int) $attributed->assist_player_id );
        $this->assertSame( 0, (int) $attributed->is_own_goal );

        $this->assertSame( 0, (int) $unknown->player_id );
        $this->assertSame( 0, (int) $unknown->is_own_goal, 'an unrecorded scorer is not an own goal' );

        $this->assertSame( 1, (int) $own->is_own_goal );
        $this->assertSame( 0, (int) $own->player_id );
    }

    public function test_only_the_unrecorded_scorer_counts_as_needing_one(): void {
        $repo = new MatchExecutionRepository();
        $repo->logGoalEvent( $this->exec_id, wp_generate_uuid4(), self::SQUAD_A, 1, 10, 'home' );
        $repo->logGoalEvent( $this->exec_id, wp_generate_uuid4(), 0, 1, 20, 'home', null, true );
        $repo->logGoalEvent( $this->exec_id, wp_generate_uuid4(), 0, 2, 5, 'away' );

        $this->assertSame(
            0,
            $this->unattributedCount( $repo->listGoalEvents( $this->exec_id ) ),
            'an own goal and an opponent goal are complete records, not gaps'
        );

        $repo->logGoalEvent( $this->exec_id, wp_generate_uuid4(), 0, 2, 25, 'home' );
        $this->assertSame( 1, $this->unattributedCount( $repo->listGoalEvents( $this->exec_id ) ) );
    }

    /**
     * Attributing a goal afterwards clears it from the count — the reminder
     * has to go away once it has been acted on, or it stops meaning anything.
     */
    public function test_attributing_a_goal_clears_the_reminder(): void {
        $repo = new MatchExecutionRepository();
        $uuid = wp_generate_uuid4();
        $repo->logGoalEvent( $this->exec_id, $uuid, 0, 1, 20, 'home' );
        $this->assertSame( 1, $this->unattributedCount( $repo->listGoalEvents( $this->exec_id ) ) );

        $repo->updateGoalAttribution( $uuid, self::SQUAD_A, self::SQUAD_B, false );

        $this->assertSame( 0, $this->unattributedCount( $repo->listGoalEvents( $this->exec_id ) ) );
    }

    /**
     * A reversed goal is not on the screen, so it cannot be waiting for a
     * scorer either.
     */
    public function test_an_undone_goal_does_not_ask_for_a_scorer(): void {
        $repo = new MatchExecutionRepository();
        $uuid = wp_generate_uuid4();
        $repo->logGoalEvent( $this->exec_id, $uuid, 0, 1, 20, 'home' );
        $repo->reverseGoalEvent( $uuid );

        $this->assertSame( 0, $this->unattributedCount( $repo->listGoalEvents( $this->exec_id ) ) );
    }
}
