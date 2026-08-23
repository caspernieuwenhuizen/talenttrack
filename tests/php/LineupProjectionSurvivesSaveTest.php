<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ActivitiesRestController;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #2771 — a match prep's Starting XI must survive saving the activity.
 *
 * The line-up lives as a write-through projection on `tt_attendance`
 * (`lineup_role` / `position_played`), and the activity detail's Line-up
 * card reads nothing else. Saving the activity rewrites those rows to store
 * status and notes — a rewrite that has no opinion about the line-up and was
 * silently dropping it, so the card went blank and the coach was told
 * nothing. Re-saving the prep brought it back, which is why this read as a
 * disappearing card rather than as lost data.
 */
final class LineupProjectionSurvivesSaveTest extends WP_UnitTestCase {

    private const ACTIVITY = 88771;

    /** @var int */
    private $keeper;

    /** @var int */
    private $sub;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_attendance', [ 'activity_id' => self::ACTIVITY ] );

        $this->keeper = 771001;
        $this->sub    = 771002;
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_attendance', [ 'activity_id' => self::ACTIVITY ] );
        parent::tear_down();
    }

    /** The shape match prep writes: an expected row carrying the line-up. */
    private function seedProjection(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_attendance';

        $wpdb->insert( $table, [
            'club_id'         => CurrentClub::id(),
            'activity_id'     => self::ACTIVITY,
            'player_id'       => $this->keeper,
            'is_guest'        => 0,
            'status'          => 'present',
            'notes'           => '',
            'record_type'     => 'expected',
            'lineup_role'     => 'start',
            'position_played' => 'GK',
        ] );
        $wpdb->insert( $table, [
            'club_id'         => CurrentClub::id(),
            'activity_id'     => self::ACTIVITY,
            'player_id'       => $this->sub,
            'is_guest'        => 0,
            'status'          => 'present',
            'notes'           => '',
            'record_type'     => 'expected',
            'lineup_role'     => 'bench',
            'position_played' => '',
        ] );
    }

    /** @return array<int, array{lineup_role:string, position_played:string}> */
    private function storedProjection(): array {
        return ( new ActivitiesRepository() )->lineupProjectionFor( self::ACTIVITY, null );
    }

    // ---- the planned path (the reported case) ------------------------------

    public function test_saving_planned_attendance_keeps_the_lineup(): void {
        $this->seedProjection();

        ( new ActivitiesRepository() )->replacePlannedAttendance( self::ACTIVITY, [
            $this->keeper => [ 'status' => 'present', 'notes' => 'fit' ],
            $this->sub    => [ 'status' => 'absent',  'notes' => 'school trip' ],
        ] );

        $lineup = $this->storedProjection();

        $this->assertSame( 'start', $lineup[ $this->keeper ]['lineup_role'] ?? '' );
        $this->assertSame( 'GK',    $lineup[ $this->keeper ]['position_played'] ?? '' );
        $this->assertSame( 'bench', $lineup[ $this->sub ]['lineup_role'] ?? '' );
    }

    /** The rewrite is about status and notes, and those must still land. */
    public function test_saving_planned_attendance_still_writes_status_and_notes(): void {
        $this->seedProjection();

        ( new ActivitiesRepository() )->replacePlannedAttendance( self::ACTIVITY, [
            $this->keeper => [ 'status' => 'absent', 'notes' => 'ill' ],
        ] );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, notes FROM {$wpdb->prefix}tt_attendance
             WHERE activity_id = %d AND player_id = %d",
            self::ACTIVITY,
            $this->keeper
        ) );

        $this->assertSame( 'absent', (string) $row->status );
        $this->assertSame( 'ill', (string) $row->notes );
    }

    /**
     * A player dropped from the plan takes their line-up entry with them —
     * the projection is preserved, not resurrected.
     */
    public function test_a_player_no_longer_planned_leaves_the_lineup(): void {
        $this->seedProjection();

        ( new ActivitiesRepository() )->replacePlannedAttendance( self::ACTIVITY, [
            $this->keeper => [ 'status' => 'present', 'notes' => '' ],
        ] );

        $lineup = $this->storedProjection();

        $this->assertArrayHasKey( $this->keeper, $lineup );
        $this->assertArrayNotHasKey( $this->sub, $lineup );
    }

    // ---- the completed path ------------------------------------------------

    /**
     * The completion form submits no `starter` when the coach only edited a
     * title or a note, so the projection has to be carried across.
     */
    public function test_a_save_without_starters_keeps_the_lineup(): void {
        $preserve = new ReflectionMethod( ActivitiesRestController::class, 'withPreservedLineup' );
        $preserve->setAccessible( true );

        $rows = $preserve->invoke( null, [
            $this->keeper => [ 'status' => 'Present', 'notes' => '' ],
            $this->sub    => [ 'status' => 'Present', 'notes' => '' ],
        ], [
            $this->keeper => [ 'lineup_role' => 'start', 'position_played' => 'GK' ],
            $this->sub    => [ 'lineup_role' => 'bench', 'position_played' => '' ],
        ] );

        $this->assertSame( 'start', $rows[ $this->keeper ]['lineup_role'] );
        $this->assertSame( 'GK',    $rows[ $this->keeper ]['position_played'] );
        $this->assertSame( 'bench', $rows[ $this->sub ]['lineup_role'] );
    }

    /**
     * An explicit starter flag wins. The coach who ticked the box on this
     * save meant it, and a stale projection must not overrule them.
     */
    public function test_an_explicit_starter_flag_beats_the_preserved_value(): void {
        $preserve = new ReflectionMethod( ActivitiesRestController::class, 'withPreservedLineup' );
        $preserve->setAccessible( true );

        $rows = $preserve->invoke( null, [
            $this->keeper => [ 'status' => 'Present', 'notes' => '', 'lineup_role' => 'bench' ],
        ], [
            $this->keeper => [ 'lineup_role' => 'start', 'position_played' => 'GK' ],
        ] );

        $this->assertSame( 'bench', $rows[ $this->keeper ]['lineup_role'] );
        $this->assertArrayNotHasKey(
            'position_played',
            $rows[ $this->keeper ],
            'an explicit line-up decision must not pick up a position from the old projection'
        );
    }

    // ---- the snapshot itself ------------------------------------------------

    /**
     * The snapshot must match the rows the caller is about to delete.
     * Widening it would copy an `actual` row's role onto a re-inserted
     * `expected` row, and the Line-up card reads both — the player would
     * appear in the Starting XI twice.
     */
    public function test_the_snapshot_is_scoped_to_the_record_type_asked_for(): void {
        $this->seedProjection();

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => CurrentClub::id(),
            'activity_id' => self::ACTIVITY,
            'player_id'   => 771003,
            'is_guest'    => 0,
            'status'      => 'present',
            'notes'       => '',
            'record_type' => 'actual',
            'lineup_role' => 'start',
        ] );

        $repo = new ActivitiesRepository();

        $this->assertArrayNotHasKey( 771003, $repo->lineupProjectionFor( self::ACTIVITY, 'expected' ) );
        $this->assertArrayHasKey( 771003, $repo->lineupProjectionFor( self::ACTIVITY, null ) );
    }

    /** A row with no line-up is not in the projection at all. */
    public function test_a_row_without_a_lineup_role_is_not_in_the_snapshot(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => CurrentClub::id(),
            'activity_id' => self::ACTIVITY,
            'player_id'   => $this->keeper,
            'is_guest'    => 0,
            'status'      => 'present',
            'notes'       => '',
            'record_type' => 'expected',
        ] );

        $this->assertSame( [], ( new ActivitiesRepository() )->lineupProjectionFor( self::ACTIVITY, null ) );
    }
}
