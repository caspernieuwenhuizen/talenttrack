<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Threads\Subscribers\GoalSystemMessageSubscriber;

/**
 * #3781 — a goal's conversation records a change to the fields the
 * player and their family plan around: title, target date, progress.
 *
 * The goal edit form autosaves, so the flood case is the one that
 * matters: a coach dragging the progress slider must not leave a run of
 * entries behind them. Consecutive saves inside the quiet window amend
 * the entry already written.
 */
final class GoalFieldChangeMessageTest extends WP_UnitTestCase {

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->coach );

        do_action( 'rest_api_init' );
        GoalSystemMessageSubscriber::init();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function makeGoal(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 7,
            'first_name' => 'Bas',
            'last_name'  => 'Willems',
        ] );
        $player_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'club_id'      => CurrentClub::id(),
            'player_id'    => $player_id,
            'title'        => 'Improve first touch under pressure',
            'description'  => 'Body position before the ball arrives.',
            'status'       => 'active',
            'priority'     => 'high',
            'due_date'     => '2026-11-01',
            'progress_pct' => 20,
            'created_by'   => $this->coach,
        ] );
        $goal_id = (int) $wpdb->insert_id;

        // Clear the quiet window between tests — the transient key is
        // goal + author, and goal ids do not repeat within a run, but a
        // fixture reused inside one test would.
        delete_transient( 'tt_goal_change_msg_' . $goal_id . '_' . $this->coach );

        return $goal_id;
    }

    /** @param array<string,mixed> $body */
    private function put( int $goal_id, array $body ): int {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/goals/' . $goal_id );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        return (int) rest_get_server()->dispatch( $request )->get_status();
    }

    /** @return list<object> */
    private function systemMessages( int $goal_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_thread_messages
              WHERE thread_type = 'goal' AND thread_id = %d AND is_system = 1
              ORDER BY id ASC",
            $goal_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function test_moving_the_target_date_writes_one_entry(): void {
        $goal_id = $this->makeGoal();

        $this->assertSame( 200, $this->put( $goal_id, [ 'due_date' => '2026-11-19' ] ) );

        $messages = $this->systemMessages( $goal_id );
        $this->assertCount( 1, $messages, 'a target-date move writes exactly one system entry' );

        $this->assertStringContainsString(
            \TT\Shared\Dates\TTDate::date( '2026-11-19' ),
            (string) $messages[0]->body,
            'the date renders in the academy notation, not the raw stored value'
        );
    }

    public function test_a_save_with_the_same_values_writes_nothing(): void {
        $goal_id = $this->makeGoal();

        $this->assertSame( 200, $this->put( $goal_id, [
            'due_date'     => '2026-11-01',
            'title'        => 'Improve first touch under pressure',
            'progress_pct' => 20,
        ] ) );

        $this->assertCount( 0, $this->systemMessages( $goal_id ) );
    }

    public function test_a_save_touching_only_unwatched_fields_writes_nothing(): void {
        $goal_id = $this->makeGoal();

        $this->assertSame( 200, $this->put( $goal_id, [
            'description' => 'Scan before the pass arrives.',
            'priority'    => 'medium',
        ] ) );

        $this->assertCount( 0, $this->systemMessages( $goal_id ) );
    }

    public function test_three_fields_in_one_save_produce_one_entry(): void {
        $goal_id = $this->makeGoal();

        $this->assertSame( 200, $this->put( $goal_id, [
            'title'        => 'Win more first duels',
            'due_date'     => '2026-12-12',
            'progress_pct' => 45,
        ] ) );

        $messages = $this->systemMessages( $goal_id );
        $this->assertCount( 1, $messages, 'one save, one entry — not one per field' );

        $body = (string) $messages[0]->body;
        $this->assertStringContainsString( 'Win more first duels', $body );
        $this->assertStringContainsString( '45%', $body );
        $this->assertStringContainsString( '<br />', $body, 'the three changes are listed in one entry' );
    }

    public function test_repeated_progress_edits_amend_the_same_entry(): void {
        $goal_id = $this->makeGoal();

        foreach ( [ 30, 45, 60, 90 ] as $pct ) {
            $this->assertSame( 200, $this->put( $goal_id, [ 'progress_pct' => $pct ] ) );
        }

        $messages = $this->systemMessages( $goal_id );
        $this->assertCount( 1, $messages, 'four slider drags leave one entry, not four' );
        $this->assertStringContainsString( '90%', (string) $messages[0]->body,
            'the surviving entry reads where the coach ended up' );
    }

    public function test_a_value_moved_and_moved_back_takes_its_entry_with_it(): void {
        $goal_id = $this->makeGoal();

        $this->assertSame( 200, $this->put( $goal_id, [ 'progress_pct' => 70 ] ) );
        $this->assertCount( 1, $this->systemMessages( $goal_id ) );

        $this->assertSame( 200, $this->put( $goal_id, [ 'progress_pct' => 20 ] ) );
        $this->assertCount( 0, $this->systemMessages( $goal_id ),
            'back to where it started means there is nothing to report' );
    }

    public function test_a_status_change_is_not_duplicated_by_the_field_diff(): void {
        $goal_id = $this->makeGoal();

        $this->assertSame( 200, $this->put( $goal_id, [ 'status' => 'achieved' ] ) );

        $this->assertCount( 0, $this->systemMessages( $goal_id ),
            'status has its own announcement on update_status; the field diff ignores it' );
    }
}
