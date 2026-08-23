<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\ActivityRatingProgress;

/**
 * #2685 — how far the rating of an activity has got.
 *
 * `completed` says nothing about whether anyone was rated: the wizard's
 * rate step is per-player skippable, a match finalize writes minutes
 * only, and the wizard-off "Mark completed" path is a bare status flip.
 * The header's rating CTA branches on these three states rather than on
 * the activity's status, so a completed-but-unrated training reads
 * "Rate players" and a fully-rated one drops the button entirely.
 */
final class ActivityRatingProgressTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_attending_but_unrated_reads_as_nothing_started(): void {
        $team = $this->insertTeam( 'U14 unrated' );
        $a    = $this->insertTraining( $team );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'Un', 'Rated' ), 'present' );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'Also', 'Unrated' ), 'late' );

        $this->assertSame( ActivityRatingProgress::NONE, ActivityRatingProgress::state( $a ) );
    }

    public function test_some_rated_reads_as_partial(): void {
        $team  = $this->insertTeam( 'U14 partial' );
        $a     = $this->insertTraining( $team );
        $rated = $this->insertPlayer( $team, 'Half', 'Way' );
        $this->insertAttendance( $a, $rated, 'present' );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'Still', 'Waiting' ), 'present' );
        $this->insertEvaluation( $a, $rated );

        $this->assertSame( ActivityRatingProgress::PARTIAL, ActivityRatingProgress::state( $a ) );
    }

    public function test_everyone_rated_reads_as_complete(): void {
        $team = $this->insertTeam( 'U14 done' );
        $a    = $this->insertTraining( $team );
        foreach ( [ [ 'All', 'Done' ], [ 'Every', 'One' ] ] as [ $first, $last ] ) {
            $pid = $this->insertPlayer( $team, $first, $last );
            $this->insertAttendance( $a, $pid, 'present' );
            $this->insertEvaluation( $a, $pid );
        }

        $this->assertSame( ActivityRatingProgress::COMPLETE, ActivityRatingProgress::state( $a ) );
        $this->assertFalse( ActivityRatingProgress::hasWorkLeft( $a ) );
    }

    /**
     * Absent players are not ratable, so an activity where nobody turned
     * up has nothing outstanding — the same answer as fully rated, and
     * the same outcome in the header (no CTA).
     */
    public function test_nobody_to_rate_reads_as_complete(): void {
        $team = $this->insertTeam( 'U14 empty' );
        $a    = $this->insertTraining( $team );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'No', 'Show' ), 'absent' );

        $this->assertSame( ActivityRatingProgress::COMPLETE, ActivityRatingProgress::state( $a ) );
        $this->assertSame(
            ActivityRatingProgress::COMPLETE,
            ActivityRatingProgress::state( 0 ),
            'a 0 id is never a lookup'
        );
    }

    // ---- fixtures ----

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTraining( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Standard training',
            'session_date'        => '2026-08-23',
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => 'actual',
        ] );
    }

    private function insertEvaluation( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'coach_id'    => 1,
            'eval_date'   => '2026-08-23',
        ] );
    }
}
