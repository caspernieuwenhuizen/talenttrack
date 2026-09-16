<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\AttendanceWriter;

/**
 * #3451 — the property `AttendanceWriter` exists to have.
 *
 * `tt_attendance` holds a planned squad and a recorded register in one
 * table, separated by `record_type`, and the column defaults to `actual`.
 * So the cheapest way to get it wrong is to say nothing: an insert that
 * omits the column claims a register was taken, and a delete that omits it
 * reaches the plan. That is how a coach's Saturday selection was destroyed
 * twice (#3456, and the REST path fixed alongside this) and how a match's
 * derived minutes ended up somewhere no correct reader looks (#3445).
 *
 * These assertions are about the shape of the API, not about any one
 * caller: every method names the kind it writes, and none of them can
 * reach the other kind by omission.
 */
final class AttendanceWriterContractTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private AttendanceWriter $writer;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $this->p      = $wpdb->prefix;
        $this->club   = (int) CurrentClub::id();
        $this->writer = new AttendanceWriter();
    }

    /* ---- the insert half ------------------------------------------- */

    public function test_record_actual_writes_a_recorded_row(): void {
        $activity = $this->activity();

        $id = $this->writer->recordActual( [
            'activity_id' => $activity,
            'player_id'   => 501,
            'status'      => 'Present',
        ] );

        $this->assertNotNull( $id );
        $this->assertSame( 'actual', $this->kindOf( (int) $id ) );
    }

    public function test_plan_expected_writes_a_planned_row(): void {
        $activity = $this->activity();

        $id = $this->writer->planExpected( [
            'activity_id' => $activity,
            'player_id'   => 502,
            'status'      => 'Present',
        ] );

        $this->assertNotNull( $id );
        $this->assertSame( 'expected', $this->kindOf( (int) $id ) );
    }

    /**
     * The method name is the declaration, not a default the caller can
     * override by passing the column. A map that says otherwise is a caller
     * that has confused the two kinds, which is the whole failure.
     */
    public function test_a_caller_cannot_talk_the_writer_out_of_the_kind(): void {
        $activity = $this->activity();

        $recorded = $this->writer->recordActual( [
            'activity_id' => $activity,
            'player_id'   => 503,
            'record_type' => 'expected',
        ] );
        $planned = $this->writer->planExpected( [
            'activity_id' => $activity,
            'player_id'   => 504,
            'record_type' => 'actual',
        ] );

        $this->assertSame( 'actual', $this->kindOf( (int) $recorded ) );
        $this->assertSame( 'expected', $this->kindOf( (int) $planned ) );
    }

    /* ---- the delete half ------------------------------------------- */

    public function test_clearing_the_register_leaves_the_plan(): void {
        $activity = $this->activity();
        $planned  = $this->row( $activity, 505, 'expected' );
        $this->row( $activity, 505, 'actual' );

        $this->assertSame( 1, $this->writer->clearActual( $activity ) );

        $this->assertSame( [ $planned ], $this->idsOfType( $activity, 'expected' ) );
        $this->assertSame( [], $this->idsOfType( $activity, 'actual' ) );
    }

    public function test_clearing_the_plan_leaves_the_register(): void {
        $activity = $this->activity();
        $this->row( $activity, 506, 'expected' );
        $recorded = $this->row( $activity, 506, 'actual' );

        $this->assertSame( 1, $this->writer->clearExpected( $activity ) );

        $this->assertSame( [], $this->idsOfType( $activity, 'expected' ) );
        $this->assertSame( [ $recorded ], $this->idsOfType( $activity, 'actual' ) );
    }

    /** #0026 — guest rows survive an ordinary rewrite unless asked for. */
    public function test_guest_rows_are_out_of_scope_by_default(): void {
        $activity = $this->activity();
        $this->row( $activity, 0, 'actual', 1 );

        $this->writer->clearActual( $activity );
        $this->assertCount( 1, $this->idsOfType( $activity, 'actual' ), 'a guest visit survives' );

        $this->writer->clearActual( $activity, true );
        $this->assertCount( 0, $this->idsOfType( $activity, 'actual' ), '...unless the caller owns them' );
    }

    public function test_clear_actual_except_keeps_the_named_players_and_the_whole_plan(): void {
        $activity = $this->activity();
        $keep     = $this->row( $activity, 507, 'actual' );
        $this->row( $activity, 508, 'actual' );
        $planned  = $this->row( $activity, 508, 'expected' );

        $this->writer->clearActualExcept( $activity, [ 507 ] );

        $this->assertSame( [ $keep ], $this->idsOfType( $activity, 'actual' ) );
        $this->assertSame( [ $planned ], $this->idsOfType( $activity, 'expected' ), 'the planned denominator survives' );
    }

    /** An empty keep-list means "keep everything", never "delete everything". */
    public function test_clear_actual_except_nothing_deletes_nothing(): void {
        $activity = $this->activity();
        $this->row( $activity, 509, 'actual' );

        $this->assertSame( 0, $this->writer->clearActualExcept( $activity, [] ) );
        $this->assertCount( 1, $this->idsOfType( $activity, 'actual' ) );
    }

    /** The one method that spans both kinds says so in its name. */
    public function test_clear_for_deleted_activity_takes_both_kinds(): void {
        $activity = $this->activity();
        $this->row( $activity, 510, 'expected' );
        $this->row( $activity, 510, 'actual' );
        $this->row( $activity, 0, 'actual', 1 );

        $this->assertSame( 3, $this->writer->clearForDeletedActivity( $activity ) );
        $this->assertSame( [], $this->idsOfType( $activity, 'expected' ) );
        $this->assertSame( [], $this->idsOfType( $activity, 'actual' ) );
    }

    /* ---- the update half ------------------------------------------- */

    /**
     * Relabelling a row is not an edit — it is the bug. A plan becomes a
     * register by somebody recording one, never by a column being rewritten
     * underneath it.
     */
    public function test_an_update_cannot_relabel_a_row(): void {
        $activity = $this->activity();
        $planned  = $this->row( $activity, 511, 'expected' );

        $this->writer->updateRow( $planned, [ 'status' => 'Absent', 'record_type' => 'actual' ] );

        $this->assertSame( 'expected', $this->kindOf( $planned ), 'the plan is still the plan' );
        $this->assertSame( 'Absent', $this->columnOf( $planned, 'status' ), 'the edit itself landed' );
    }

    /* ---- the kind-scoped lookups ----------------------------------- */

    public function test_the_lookups_find_only_their_own_kind(): void {
        $activity = $this->activity();
        $planned  = $this->row( $activity, 512, 'expected' );
        $recorded = $this->row( $activity, 512, 'actual' );

        $this->assertSame( $recorded, $this->writer->actualRowId( $activity, 512 ) );
        $this->assertSame( $planned, $this->writer->expectedRowId( $activity, 512 ) );
    }

    public function test_a_planned_only_player_has_no_recorded_row(): void {
        $activity = $this->activity();
        $this->row( $activity, 513, 'expected' );

        $this->assertSame( 0, $this->writer->actualRowId( $activity, 513 ) );
        $this->assertSame( [], $this->writer->actualRowIds( $activity, 513 ) );
    }

    /* ---- the line-up projection ------------------------------------ */

    public function test_the_projection_lands_on_the_plan_when_there_is_one(): void {
        $activity = $this->activity();
        $planned  = $this->row( $activity, 514, 'expected' );
        $recorded = $this->row( $activity, 514, 'actual' );

        $this->writer->upsertLineupProjection( $activity, 514, 'start', 'GK' );

        $this->assertSame( 'start', $this->columnOf( $planned, 'lineup_role' ) );
        $this->assertSame( '', $this->columnOf( $recorded, 'lineup_role' ), 'the register is not the line-up' );
    }

    /**
     * On an install whose plan was already destroyed by the pre-#3451 save,
     * the recorded row is the only one there is. Seeding a second row and
     * leaving the stale role behind would list the player twice.
     */
    public function test_the_projection_falls_back_to_the_recorded_row(): void {
        $activity = $this->activity();
        $recorded = $this->row( $activity, 515, 'actual' );

        $this->writer->upsertLineupProjection( $activity, 515, 'bench', null );

        $this->assertSame( 'bench', $this->columnOf( $recorded, 'lineup_role' ) );
        $this->assertSame( [], $this->idsOfType( $activity, 'expected' ), 'no phantom plan row' );
    }

    public function test_the_projection_seeds_a_plan_row_when_there_is_nothing(): void {
        $activity = $this->activity();

        $this->writer->upsertLineupProjection( $activity, 516, 'start', 'CM' );

        $seeded = $this->idsOfType( $activity, 'expected' );
        $this->assertCount( 1, $seeded );
        $this->assertSame( 'start', $this->columnOf( $seeded[0], 'lineup_role' ) );
    }

    /* ---- helpers ---------------------------------------------------- */

    private function activity(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => 0,
            'title'               => 'Training',
            'session_date'        => gmdate( 'Y-m-d' ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function row( int $activity_id, int $player_id, string $record_type, int $is_guest = 0 ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'is_guest'    => $is_guest,
            'status'      => 'Present',
            'record_type' => $record_type,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function kindOf( int $row_id ): string {
        return $this->columnOf( $row_id, 'record_type' );
    }

    private function columnOf( int $row_id, string $column ): string {
        global $wpdb;
        // The column name is one of a fixed set written by this file, never
        // caller input, so it is interpolated rather than bound.
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT `{$column}` FROM {$this->p}tt_attendance WHERE id = %d",
            $row_id
        ) );
    }

    /** @return list<int> */
    private function idsOfType( int $activity_id, string $record_type ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_attendance
              WHERE activity_id = %d AND club_id = %d AND record_type = %s
              ORDER BY id ASC",
            $activity_id, $this->club, $record_type
        ) );
        return array_values( array_map( 'intval', (array) $ids ) );
    }
}
