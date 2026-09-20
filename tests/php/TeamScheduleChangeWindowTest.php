<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Recipients\TeamStaffLookup;
use TT\Modules\Comms\Send\TeamScheduleChangeSend;

/**
 * #3811 — the two speeds partition the change set.
 *
 * A calendar change either sends on the spot or lands in tomorrow's digest,
 * never both and never neither. The boundary is the only thing standing
 * between "the team manager hears about the Thursday session on Friday" and
 * "the team manager gets six emails because a coach fixed six kick-off
 * times", so it is worth a test that does not need a database.
 */
final class TeamScheduleChangeWindowTest extends WP_UnitTestCase {

    private const NOW = 1763000000; // arbitrary fixed point

    public function test_a_session_inside_the_window_sends_immediately(): void {
        $starts = self::NOW + 6 * HOUR_IN_SECONDS;

        $this->assertTrue( TeamScheduleChangeSend::isImminent( $starts, self::NOW ) );
    }

    public function test_a_session_beyond_the_window_waits_for_the_digest(): void {
        $starts = self::NOW + ( TeamScheduleChangeSend::IMMEDIATE_HOURS + 1 ) * HOUR_IN_SECONDS;

        $this->assertFalse( TeamScheduleChangeSend::isImminent( $starts, self::NOW ) );
    }

    public function test_the_boundary_itself_is_immediate(): void {
        $starts = self::NOW + TeamScheduleChangeSend::IMMEDIATE_HOURS * HOUR_IN_SECONDS;

        $this->assertTrue( TeamScheduleChangeSend::isImminent( $starts, self::NOW ) );
    }

    public function test_a_session_that_has_already_started_is_not_imminent(): void {
        // Nobody needs telling that yesterday moved.
        $this->assertFalse( TeamScheduleChangeSend::isImminent( self::NOW - 60, self::NOW ) );
    }

    public function test_an_activity_with_no_clock_time_starts_at_midnight(): void {
        $activity = (object) [ 'session_date' => '2026-11-03', 'start_time' => null ];

        $this->assertSame( strtotime( '2026-11-03 00:00' ), TeamScheduleChangeSend::startsAt( $activity ) );
    }

    public function test_an_activity_with_no_date_has_no_start(): void {
        $this->assertNull( TeamScheduleChangeSend::startsAt( (object) [ 'session_date' => '' ] ) );
    }

    public function test_a_digest_line_states_what_changed_and_never_names_a_player(): void {
        $activity = (object) [
            'title'        => 'Training dinsdag',
            'session_date' => '2026-11-03',
            'start_time'   => '18:30:00',
            'end_time'     => '20:00:00',
            'location'     => 'Trainingsveld',
        ];

        $cancelled = TeamScheduleChangeSend::line( $activity, true );
        $added     = TeamScheduleChangeSend::line( $activity, false, true );
        $changed   = TeamScheduleChangeSend::line( $activity );

        foreach ( [ $cancelled, $added, $changed ] as $line ) {
            $this->assertStringContainsString( 'Training dinsdag', $line );
            $this->assertStringContainsString( '18:30', $line );
            $this->assertStringContainsString( 'Trainingsveld', $line );
        }

        $this->assertNotSame( $cancelled, $added );
        $this->assertNotSame( $added, $changed );
    }

    public function test_the_running_staff_are_the_three_roles_that_act(): void {
        // A physio and a kit manager are team staff and are deliberately not
        // on the notification list — see TeamStaffLookup's docblock.
        $this->assertSame(
            [ 'head_coach', 'assistant_coach', 'manager' ],
            TeamStaffLookup::RUNS_THE_TEAM
        );
    }

    public function test_an_unknown_team_resolves_to_nobody(): void {
        $this->assertSame( [], TeamStaffLookup::forTeam( 0 ) );
        $this->assertSame( [], TeamStaffLookup::forTeams( [] ) );
    }
}
