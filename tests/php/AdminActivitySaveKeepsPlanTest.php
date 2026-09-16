<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #3456 — saving an activity in wp-admin deleted the planned roster.
 *
 * `ActivitiesPage::handle_save()` hands the posted `att[]` map to
 * `ActivitiesRepository::replaceRosterAttendance()`, which wiped every
 * non-guest row for the activity before rewriting it. Migration 0121 had
 * since split `tt_attendance` into a planned (`expected`) and a recorded
 * (`actual`) half, so the wipe took the coach's squad with it and the
 * rewrite brought it back as a register nobody had taken: the plan gone,
 * and the attendance reports and completeness counts believing a register
 * existed.
 *
 * The write is exercised directly because `handle_save()` ends in
 * `wp_safe_redirect()` + `exit` — it is the whole of what that request
 * does to `tt_attendance`, and the assertion below is the one whose
 * absence let this ship.
 */
final class AdminActivitySaveKeepsPlanTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    /**
     * The bug, stated as an assertion: an administrator saves the activity
     * and the planned squad is still there, byte for byte — same rows,
     * same statuses, same notes, same line-up.
     */
    public function test_saving_an_activity_leaves_the_planned_roster_untouched(): void {
        $team     = $this->insertTeam();
        $keeper   = $this->insertPlayer( $team, 'Keeper' );
        $striker  = $this->insertPlayer( $team, 'Striker' );
        $activity = $this->insertActivity( $team );

        $planned_keeper  = $this->insertAttendance( $activity, $keeper, 'expected', 'Present', 'Plans to play', 'start' );
        $planned_striker = $this->insertAttendance( $activity, $striker, 'expected', 'Excused', 'Away with family', 'bench' );

        ( new ActivitiesRepository() )->replaceRosterAttendance( $activity, [
            $keeper  => [ 'status' => 'Present', 'notes' => '' ],
            $striker => [ 'status' => 'Absent',  'notes' => '' ],
        ] );

        $rows = $this->rowsOfType( $activity, 'expected' );
        $this->assertCount( 2, $rows, 'the planned squad survives an activity save' );

        $by_id = [];
        foreach ( $rows as $row ) {
            $by_id[ (int) $row->id ] = $row;
        }

        $this->assertArrayHasKey( $planned_keeper, $by_id, 'the planned row is the same row, not a rewritten one' );
        $this->assertArrayHasKey( $planned_striker, $by_id );

        $this->assertSame( 'Excused', (string) $by_id[ $planned_striker ]->status, 'the plan keeps its own status' );
        $this->assertSame( 'Away with family', (string) $by_id[ $planned_striker ]->notes, 'the plan keeps its own notes' );
        $this->assertSame( 'bench', (string) $by_id[ $planned_striker ]->lineup_role, 'the plan keeps its line-up' );
        $this->assertSame( 'start', (string) $by_id[ $planned_keeper ]->lineup_role );
    }

    /**
     * The converse: the method still does its job. A register already taken
     * is replaced by the posted one, so an administrator correcting an
     * attendance row sees the correction.
     */
    public function test_a_recorded_register_is_still_replaced(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Recorded' );
        $activity = $this->insertActivity( $team );

        $stale = $this->insertAttendance( $activity, $player, 'actual', 'Absent', 'Marked in error' );

        ( new ActivitiesRepository() )->replaceRosterAttendance( $activity, [
            $player => [ 'status' => 'Late', 'notes' => 'Bus' ],
        ] );

        $rows = $this->rowsOfType( $activity, 'actual' );
        $this->assertCount( 1, $rows, 'the recorded register is replaced, not appended to' );
        $this->assertNotSame( $stale, (int) $rows[0]->id, 'the stale row is gone' );
        $this->assertSame( 'Late', (string) $rows[0]->status );
        $this->assertSame( 'Bus', (string) $rows[0]->notes );
    }

    /**
     * Rows written by this path are recorded attendance.
     */
    public function test_rows_written_are_actual(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Written' );
        $activity = $this->insertActivity( $team );

        ( new ActivitiesRepository() )->replaceRosterAttendance( $activity, [
            $player => [ 'status' => 'Present', 'notes' => '' ],
        ] );

        $rows = $this->rowsOfType( $activity, 'actual' );
        $this->assertCount( 1, $rows );
        $this->assertCount( 0, $this->rowsOfType( $activity, 'expected' ), 'the wp-admin form never writes a plan' );
    }

    /**
     * ...and they say so themselves rather than inheriting the column
     * default. A runtime read cannot tell the two apart — the value is
     * `actual` either way — so this reads the source. The default is right
     * today; the point is that the method states which kind of row it
     * writes next to the delete that scopes to the same kind, so the two
     * cannot drift apart again the way they did after migration 0121.
     */
    public function test_the_method_names_record_type_on_both_statements(): void {
        $body = $this->methodSource( 'replaceRosterAttendance' );

        $this->assertMatchesRegularExpression(
            "/->delete\(.*'record_type'\s*=>\s*'actual'/s",
            $body,
            "the delete scopes to 'actual' so the planned roster survives"
        );
        $this->assertMatchesRegularExpression(
            "/->insert\(.*'record_type'\s*=>\s*'actual'/s",
            $body,
            "the insert names 'actual' rather than leaning on the column default"
        );
    }

    /**
     * #0026's contract, pinned because this fix narrows the same delete:
     * guest rows are managed elsewhere and survive an admin save cycle.
     */
    public function test_guest_rows_survive(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Home' );
        $activity = $this->insertActivity( $team );

        $this->insertAttendance( $activity, 0, 'actual', 'Present', '', null, 1 );

        ( new ActivitiesRepository() )->replaceRosterAttendance( $activity, [
            $player => [ 'status' => 'Present', 'notes' => '' ],
        ] );

        global $wpdb;
        $guests = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_attendance WHERE activity_id = %d AND club_id = %d AND is_guest = 1",
            $activity, $this->club
        ) );
        $this->assertSame( 1, $guests, 'a guest visit is not this form\'s to delete' );
    }

    /**
     * @return array<int, object>
     */
    private function rowsOfType( int $activity_id, string $record_type ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_attendance
              WHERE activity_id = %d AND club_id = %d AND is_guest = 0 AND record_type = %s
              ORDER BY id ASC",
            $activity_id, $this->club, $record_type
        ) );
    }

    private function methodSource( string $method ): string {
        $reflection = new \ReflectionMethod( ActivitiesRepository::class, $method );
        $file       = (string) $reflection->getFileName();
        $lines      = (array) file( $file );
        $start      = (int) $reflection->getStartLine();
        $end        = (int) $reflection->getEndLine();

        return implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO14-1' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $last ): int {
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

    private function insertActivity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Thuis tegen Hedel',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
            'activity_type_key'   => 'game',
            'plan_state'          => 'scheduled',
            'activity_status_key' => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance(
        int $activity_id,
        int $player_id,
        string $record_type,
        string $status,
        string $notes = '',
        ?string $lineup_role = null,
        int $is_guest = 0
    ): int {
        global $wpdb;
        $row = [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'notes'       => $notes,
            'is_guest'    => $is_guest,
            'record_type' => $record_type,
        ];
        if ( $lineup_role !== null ) {
            $row['lineup_role'] = $lineup_role;
        }
        if ( $is_guest === 1 ) {
            $row['guest_name'] = 'Bezoeker';
        }
        $wpdb->insert( "{$this->p}tt_attendance", $row );
        return (int) $wpdb->insert_id;
    }
}
