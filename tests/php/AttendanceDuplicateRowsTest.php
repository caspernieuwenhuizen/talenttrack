<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #2862 — a player holds two attendance rows for one activity (the
 * `expected` row the planner writes, and the `actual` row recording what
 * happened). The counters filtered to `actual`; the lists beside them did
 * not, so every number was right and every list was doubled.
 *
 * The pilot symptom: a profile Activities tab whose badge read 14 next to a
 * card header reading "Recent · 19", with four entries repeated; and an
 * activity whose summary read "15 / 15 aanwezig" above a roster listing all
 * fifteen names twice.
 *
 * #2521 and #2522 fixed exactly this on the counts. These tests cover the
 * lists, and the last one pins the count and the list to each other so a
 * future fix to one cannot leave the other behind again.
 */
final class AttendanceDuplicateRowsTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    /**
     * The profile Activities tab: one entry per activity, even when the
     * player has both an expected and a recorded row on it.
     */
    public function test_a_player_with_both_rows_appears_once_per_activity(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id );

        $activity = $this->insertActivity( $team_id, '2026-04-23', 'completed' );
        $this->insertAttendance( $activity, $player_id, 'expected', 'present' );
        $this->insertAttendance( $activity, $player_id, 'actual', 'present' );

        $rows = ( new ActivitiesRepository() )->listForPlayer( $player_id, 25, 'ASC', [
            'record_types'        => null,
            'plan_states'         => [ 'completed', 'planned', 'scheduled' ],
            'only_past_completed' => true,
        ] );

        $this->assertCount( 1, $rows, 'two attendance rows on one activity are one list entry' );
        $this->assertSame(
            'actual',
            (string) ( $rows[0]->record_type ?? '' ),
            'the recorded row wins: it carries the real status'
        );
    }

    /**
     * A still-planned activity has only an expected row. Filtering the list
     * to `actual` — the obvious-looking fix — would have emptied this half
     * of the tab, so it is pinned here.
     */
    public function test_a_planned_activity_still_appears(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id );

        $future = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
        $planned = $this->insertActivity( $team_id, $future, 'planned' );
        $this->insertAttendance( $planned, $player_id, 'expected', 'present' );

        $rows = ( new ActivitiesRepository() )->listForPlayer( $player_id, 25, 'ASC', [
            'record_types'        => null,
            'plan_states'         => [ 'completed', 'planned', 'scheduled' ],
            'only_past_completed' => true,
        ] );

        $this->assertCount( 1, $rows, 'a planned activity has only an expected row and must still show' );
        $this->assertSame( 'expected', (string) ( $rows[0]->record_type ?? '' ) );
    }

    /** The activity roster: fifteen players, fifteen rows. */
    public function test_the_roster_lists_each_player_once(): void {
        $team_id  = $this->insertTeam();
        $activity = $this->insertActivity( $team_id, '2026-08-24', 'completed' );

        for ( $i = 1; $i <= 15; $i++ ) {
            $player_id = $this->insertPlayer( $team_id, 'P' . $i );
            $this->insertAttendance( $activity, $player_id, 'expected', 'present' );
            $this->insertAttendance( $activity, $player_id, 'actual', 'present' );
        }

        $repo = new ActivitiesRepository();

        $this->assertCount(
            15,
            $repo->listRosterAttendance( $activity, true ),
            'a fifteen-player squad is fifteen rows, not thirty'
        );
        $this->assertCount(
            15,
            $repo->listRosterAttendance( $activity, true, 'actual' ),
            'the recorded roster a completed activity shows'
        );
    }

    /**
     * The explicit record_type argument still reaches the expected rows —
     * the wizard pre-fill path depends on being able to ask for them.
     */
    public function test_expected_rows_remain_addressable(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id );
        $activity  = $this->insertActivity( $team_id, '2026-08-24', 'planned' );

        $this->insertAttendance( $activity, $player_id, 'expected', 'present' );

        $rows = ( new ActivitiesRepository() )->listRosterAttendance( $activity, true, 'expected' );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'expected', (string) ( $rows[0]->record_type ?? '' ) );
    }

    /**
     * The badge and the list must count the same activities. A mismatch is
     * the symptom that survived #2521 and #2522, so it is asserted against
     * the two code paths rather than against a literal.
     */
    public function test_the_tab_badge_matches_the_rendered_rows(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id );

        foreach ( [ '2026-04-23', '2026-05-18', '2026-06-22' ] as $date ) {
            $activity = $this->insertActivity( $team_id, $date, 'completed' );
            $this->insertAttendance( $activity, $player_id, 'expected', 'present' );
            $this->insertAttendance( $activity, $player_id, 'actual', 'present' );
        }

        $rows   = ( new ActivitiesRepository() )->listForPlayer( $player_id, 25, 'ASC', [
            'record_types'        => null,
            'plan_states'         => [ 'completed', 'planned', 'scheduled' ],
            'only_past_completed' => true,
        ] );
        $counts = PlayerFileCounts::for( $player_id );

        $this->assertSame(
            (int) $counts['activities'],
            count( $rows ),
            'the badge and the list must not disagree about how many activities there are'
        );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO14-1' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $last = 'Nieuwenhuizen' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Luuk',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $date, string $plan_state ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $team_id,
            'title'             => 'Training ' . $date,
            'session_date'      => $date,
            'activity_type_key' => 'training',
            'plan_state'        => $plan_state,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $record_type, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => $record_type,
        ] );
        return (int) $wpdb->insert_id;
    }
}
