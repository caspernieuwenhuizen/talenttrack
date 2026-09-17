<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\AdminCenterClient\PayloadBuilder;

/**
 * #3494 — value signals in the phone-home payload.
 *
 * Pinned: a register counts only when recorded (`record_type = 'actual'`),
 * so a plan-only activity does not; minutes count activities with minutes
 * recorded; activities and evaluations outside 30 days do not count; goals
 * read the stored status; PDPs count only when open; and the block carries
 * nothing but integers — no id, name or text.
 */
final class ValueSignalsTest extends WP_UnitTestCase {

    public function test_counts_follow_what_was_recorded(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $today = gmdate( 'Y-m-d' );
        $old   = gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS );

        $before = PayloadBuilder::valueSignals( $wpdb );

        $recorded = $this->activity( $today );
        $wpdb->insert( "{$p}tt_attendance", [ 'club_id' => 1, 'activity_id' => $recorded, 'player_id' => 1, 'status' => 'present', 'record_type' => 'actual', 'minutes_played' => 60 ] );

        $register_only = $this->activity( $today );
        $wpdb->insert( "{$p}tt_attendance", [ 'club_id' => 1, 'activity_id' => $register_only, 'player_id' => 1, 'status' => 'present', 'record_type' => 'actual' ] );

        $plan_only = $this->activity( $today );
        $wpdb->insert( "{$p}tt_attendance", [ 'club_id' => 1, 'activity_id' => $plan_only, 'player_id' => 1, 'status' => 'present', 'record_type' => 'expected', 'minutes_played' => 60 ] );

        $long_ago = $this->activity( $old );
        $wpdb->insert( "{$p}tt_attendance", [ 'club_id' => 1, 'activity_id' => $long_ago, 'player_id' => 1, 'status' => 'present', 'record_type' => 'actual', 'minutes_played' => 60 ] );

        $wpdb->insert( "{$p}tt_evaluations", [ 'club_id' => 1, 'player_id' => 1, 'coach_id' => 1, 'eval_date' => $today, 'created_at' => current_time( 'mysql', true ) ] );
        $wpdb->insert( "{$p}tt_evaluations", [ 'club_id' => 1, 'player_id' => 1, 'coach_id' => 1, 'eval_date' => $old, 'created_at' => $old . ' 10:00:00' ] );

        $wpdb->insert( "{$p}tt_pdp_files", [ 'club_id' => 1, 'player_id' => 1, 'season_id' => 1, 'status' => 'open' ] );
        $wpdb->insert( "{$p}tt_pdp_files", [ 'club_id' => 1, 'player_id' => 2, 'season_id' => 1, 'status' => 'completed' ] );

        foreach ( [ 'in_progress', 'pending_approval', 'completed', 'cancelled' ] as $status ) {
            $wpdb->insert( "{$p}tt_goals", [ 'club_id' => 1, 'player_id' => 1, 'title' => 'Goal', 'status' => $status, 'created_by' => 1 ] );
        }

        $after = PayloadBuilder::valueSignals( $wpdb );

        $this->assertSame( 2, $after['attendance_recorded_30d'] - $before['attendance_recorded_30d'], 'Recorded registers in the window count; a plan-only activity and an old one do not.' );
        $this->assertSame( 1, $after['minutes_recorded_30d'] - $before['minutes_recorded_30d'], 'Only an activity with minutes on a recorded register counts.' );
        $this->assertSame( 1, $after['evaluations_recorded_30d'] - $before['evaluations_recorded_30d'] );
        $this->assertSame( 1, $after['pdps_active'] - $before['pdps_active'] );
        $this->assertSame( 2, $after['goals_active'] - $before['goals_active'], 'Stored statuses other than completed and cancelled are active.' );
    }

    public function test_the_block_carries_only_integer_counts(): void {
        $payload = PayloadBuilder::build( PayloadBuilder::TRIGGER_DAILY );

        $this->assertArrayHasKey( 'value_signals', $payload );
        $this->assertSame(
            [ 'attendance_recorded_30d', 'minutes_recorded_30d', 'evaluations_recorded_30d', 'pdps_active', 'goals_active' ],
            array_keys( $payload['value_signals'] )
        );
        foreach ( $payload['value_signals'] as $key => $value ) {
            $this->assertIsInt( $value, "{$key} must be a count, never an id list, a name or text." );
        }
    }

    private function activity( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => 1, 'team_id' => 1, 'title' => 'Training', 'session_date' => $date,
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }
}
