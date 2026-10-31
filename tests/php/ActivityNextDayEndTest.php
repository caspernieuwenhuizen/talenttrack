<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Activities\Services\ActivityTimeWindow;
use TT\Modules\MatchPrep\Services\MatchLengthResolver;

/**
 * #4513 — an activity that crosses midnight keeps its end time. An end
 * before the start is a next-day end when the span is six hours or less
 * (23:00 to 00:30 is 90 minutes); anything else is still dropped.
 */
final class ActivityNextDayEndTest extends WP_UnitTestCase {

    /** @var int */
    private $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'JO17-1' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    // The helper

    public function test_an_end_before_the_start_is_next_day_up_to_six_hours(): void {
        $this->assertTrue( ActivityTimeWindow::endIsNextDay( '23:00:00', '00:30:00' ) );
        $this->assertTrue( ActivityTimeWindow::endIsNextDay( '20:00', '02:00' ), 'exactly six hours' );
        $this->assertFalse( ActivityTimeWindow::endIsNextDay( '20:00', '02:01' ), 'over six hours' );
        $this->assertFalse( ActivityTimeWindow::endIsNextDay( '10:00', '09:00' ), 'a 23-hour span is a typo' );
        $this->assertFalse( ActivityTimeWindow::endIsNextDay( '10:00', '10:00' ), 'equal is not next day' );
        $this->assertFalse( ActivityTimeWindow::endIsNextDay( '18:30', '20:00' ), 'a same-day end is not next day' );
        $this->assertFalse( ActivityTimeWindow::endIsNextDay( '', '00:30' ) );
        $this->assertFalse( ActivityTimeWindow::endIsNextDay( '23:00', 'later' ) );
    }

    public function test_minutes_count_a_next_day_end(): void {
        $this->assertSame( 90, ActivityTimeWindow::minutes( '23:00:00', '00:30:00' ) );
        $this->assertSame( 90, ActivityTimeWindow::minutes( '18:30', '20:00' ) );
        $this->assertSame( 0, ActivityTimeWindow::minutes( '10:00', '09:00' ) );
        $this->assertSame( 0, ActivityTimeWindow::minutes( '10:00', '10:00' ) );
        $this->assertSame( 0, ActivityTimeWindow::minutes( '18:30', '' ) );
        $this->assertSame( 90, MatchLengthResolver::scheduledMinutes( '23:00:00', '00:30:00' ), 'the match length basis uses the same rule' );
    }

    public function test_the_stat_strip_reads_ninety_minutes(): void {
        $id    = $this->activity( 'manual', '23:00:00', '00:30:00' );
        $strip = ( new ActivitiesRepository() )->statStripForActivity( $id, $this->team, false, '23:00:00', '00:30:00', 0, 'completed' );
        $this->assertSame( 90, $strip->duration_minutes );
    }

    public function test_both_planner_prints_show_a_next_day_duration(): void {
        foreach ( [ \TT\Modules\Export\Exporters\TeamPlanningPdfExporter::class, \TT\Modules\Planning\Print\TeamPlannerWeeklyPrintable::class ] as $class ) {
            $label = new \ReflectionMethod( $class, 'durationLabel' );
            $label->setAccessible( true );
            $this->assertSame( '1h30', $label->invoke( null, '23:00:00', '00:30:00' ), $class . ' counts a next-day end' );
            $this->assertSame( '1h30', $label->invoke( null, '18:30:00', '20:00:00' ), $class . ' keeps a same-day end' );
            $this->assertSame( '', $label->invoke( null, '10:00:00', '09:00:00' ), $class . ' shows no duration for a 23-hour span' );
        }
    }

    // The create wizard (#4528)

    public function test_the_wizard_accepts_its_own_wrapped_default_end(): void {
        $out = ( new \TT\Modules\Wizards\Activity\DetailsStep() )->validate( [
            'title'        => 'Late match',
            'session_date' => '2026-11-07',
            'start_time'   => '22:30',
            'end_time'     => '00:15',
        ], [ 'activity_type_key' => 'training' ] );

        $this->assertIsArray( $out, 'a 22:30 start with its 00:15 default end is accepted' );
        $this->assertSame( '00:15', $out['end_time'] );
    }

    public function test_the_wizard_still_refuses_an_end_that_is_not_next_day(): void {
        $step = new \TT\Modules\Wizards\Activity\DetailsStep();
        foreach ( [ [ '10:00', '09:00' ], [ '10:00', '10:00' ] ] as [ $start, $end ] ) {
            $out = $step->validate( [
                'title'        => 'Training',
                'session_date' => '2026-11-07',
                'start_time'   => $start,
                'end_time'     => $end,
            ], [ 'activity_type_key' => 'training' ] );
            $this->assertInstanceOf( \WP_Error::class, $out, "{$start} to {$end} is refused" );
            $this->assertSame( 'end_before_start', $out->get_error_code() );
        }
    }

    // Through the REST edit path

    public function test_a_save_crossing_midnight_keeps_the_end(): void {
        $id = $this->activity( 'manual', '18:30:00', '20:00:00' );
        $this->put( $id, [ 'start_time' => '23:00', 'end_time' => '00:30' ] );
        $this->assertSame( '00:30:00', $this->column( $id, 'end_time' ) );
    }

    public function test_a_same_day_save_is_unchanged(): void {
        $id = $this->activity( 'manual', '17:00:00', '18:00:00' );
        $this->put( $id, [ 'start_time' => '18:30', 'end_time' => '20:00' ] );
        $this->assertSame( '20:00:00', $this->column( $id, 'end_time' ) );
    }

    public function test_an_end_that_is_not_next_day_is_still_dropped(): void {
        $id = $this->activity( 'manual', '18:30:00', '20:00:00' );
        $this->put( $id, [ 'start_time' => '10:00', 'end_time' => '09:00' ] );
        $this->assertNull( $this->column( $id, 'end_time' ), '10:00 to 09:00 drops the end' );

        $this->put( $id, [ 'start_time' => '10:00', 'end_time' => '10:00' ] );
        $this->assertNull( $this->column( $id, 'end_time' ), '10:00 to 10:00 drops the end' );
    }

    public function test_an_unchanged_resave_of_a_late_spond_activity_locks_nothing(): void {
        $id = $this->activity( 'spond', '23:00:00', '00:30:00' );
        $this->put( $id, [ 'notes' => 'Late kick-off, bring lights.' ] );

        $this->assertSame( '00:30:00', $this->column( $id, 'end_time' ) );
        $this->assertNull( $this->column( $id, 'sync_locked_fields' ), 'the times group is not locked by a save that changed nothing' );
    }

    // Fixtures

    /** @param array<string,string> $body */
    private function put( int $id, array $body ): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        do_action( 'rest_api_init' );
        $req = new WP_REST_Request( 'PUT', "/talenttrack/v1/activities/{$id}" );
        $req->set_body_params( $body );
        $res = rest_get_server()->dispatch( $req );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
    }

    private function activity( string $source, string $start, string $end ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $this->team,
            'title'               => 'Toernooiavond',
            'session_date'        => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ),
            'start_time'          => $start,
            'end_time'            => $end,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'activity_source_key' => $source,
            'external_id'         => $source === 'spond' ? 'spond-4513' : null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function column( int $id, string $column ): ?string {
        global $wpdb;
        $column = in_array( $column, [ 'end_time', 'sync_locked_fields' ], true ) ? $column : 'end_time';
        $value  = $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ) );
        return $value === null ? null : (string) $value;
    }
}
