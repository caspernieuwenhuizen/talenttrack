<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Pdp\EvidencePacket;
use TT\Modules\TeamDevelopment\Chemistry\ChemistryProfileLoader;

/**
 * #3451 — the three readers that counted a planned squad as a register.
 *
 * `tt_attendance` holds both kinds separated only by `record_type`, and the
 * planned rows carry real statuses: `plannedStatusMap()` stores Expected as
 * `Present`, Not coming as `Absent` and Maybe as `Excused`. So a query that
 * asks "was this player present?" without naming the column gets **yes**
 * from a squad nobody has registered.
 *
 * Every one of these bugs returned a wrong answer rather than erroring,
 * which is why each case is asserted in both directions: the planned-only
 * fixture must return nothing, and the recorded one must return the row.
 * A test that only pinned the second would have passed before the fix.
 */
final class AttendanceRecordTypeReadersTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $this->team = $this->insertTeam();
    }

    /* ---- ChemistryProfileLoader ------------------------------------- */

    /**
     * Two players named in the same planned squad have not played
     * together. Before the fix this was the loader's definition of
     * chemistry.
     */
    public function test_chemistry_ignores_a_shared_planned_squad(): void {
        $a = $this->insertPlayer( 'Planned', 'One' );
        $b = $this->insertPlayer( 'Planned', 'Two' );
        $activity = $this->insertActivity( 'completed' );

        $this->insertAttendance( $activity, $a, 'expected', 'present' );
        $this->insertAttendance( $activity, $b, 'expected', 'present' );

        $loader = new ChemistryProfileLoader();
        $loader->load( [ $a, $b ] );

        $this->assertSame( 0, $loader->pairContext( $a, $b )->shared_sessions );
    }

    public function test_chemistry_counts_a_shared_recorded_session(): void {
        $a = $this->insertPlayer( 'Played', 'One' );
        $b = $this->insertPlayer( 'Played', 'Two' );
        $activity = $this->insertActivity( 'completed' );

        $this->insertAttendance( $activity, $a, 'actual', 'present' );
        $this->insertAttendance( $activity, $b, 'actual', 'present' );

        $loader = new ChemistryProfileLoader();
        $loader->load( [ $a, $b ] );

        $this->assertSame( 1, $loader->pairContext( $a, $b )->shared_sessions );
    }

    /**
     * The other half of the same query: it gated on `plan_state`, which was
     * added `DEFAULT 'completed'` and which only the planner ever sets. An
     * activity showing STATUS: Planned on screen therefore counted.
     */
    public function test_chemistry_ignores_an_activity_nobody_completed(): void {
        $a = $this->insertPlayer( 'Future', 'One' );
        $b = $this->insertPlayer( 'Future', 'Two' );
        // plan_state left at the 'completed' default, status says planned —
        // the exact disagreement ActivityLifecycle exists to settle.
        $activity = $this->insertActivity( 'planned', 'completed' );

        $this->insertAttendance( $activity, $a, 'actual', 'present' );
        $this->insertAttendance( $activity, $b, 'actual', 'present' );

        $loader = new ChemistryProfileLoader();
        $loader->load( [ $a, $b ] );

        $this->assertSame( 0, $loader->pairContext( $a, $b )->shared_sessions );
    }

    /* ---- EvidencePacket --------------------------------------------- */

    public function test_the_evidence_packet_ignores_a_planned_squad(): void {
        $player   = $this->insertPlayer( 'Packet', 'Planned' );
        $activity = $this->insertActivity( 'completed' );
        $this->insertAttendance( $activity, $player, 'expected', 'Present' );

        $counts = $this->packetAttendance( $player );

        $this->assertSame( 0, $counts['activities'], 'a selection is not attendance' );
        $this->assertSame( 0, $counts['present'] );
        $this->assertNull( $counts['rate'], 'and no rate is claimed from nothing' );
    }

    public function test_the_evidence_packet_counts_the_recorded_register(): void {
        $player = $this->insertPlayer( 'Packet', 'Recorded' );
        $this->insertAttendance( $this->insertActivity( 'completed' ), $player, 'actual', 'Present' );
        $this->insertAttendance( $this->insertActivity( 'completed' ), $player, 'actual', 'Absent' );

        $counts = $this->packetAttendance( $player );

        $this->assertSame( 2, $counts['activities'] );
        $this->assertSame( 1, $counts['present'] );
        $this->assertSame( 1, $counts['absent'] );
        $this->assertSame( 50.0, (float) $counts['rate'] );
    }

    /* ---- MinutesGridQuery -------------------------------------------- */

    public function test_the_minutes_grid_squad_is_the_recorded_register(): void {
        $recorded = $this->insertPlayer( 'Grid', 'Recorded' );
        $planned  = $this->insertPlayer( 'Grid', 'Planned' );
        $match    = $this->insertActivity( 'completed', 'completed', 'game' );

        $this->insertAttendance( $match, $recorded, 'actual', 'Present', 60 );
        $this->insertAttendance( $match, $planned, 'expected', 'Present' );

        $matrix = ( new MinutesGridQuery() )->matrix(
            $this->team,
            gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
            gmdate( 'Y-m-d', strtotime( '+1 day' ) )
        );

        $cells = $matrix['cells'] ?? [];
        $this->assertTrue( (bool) ( $cells[ $recorded ][ $match ]['squad'] ?? false ) );
        $this->assertArrayNotHasKey(
            $planned,
            $cells,
            'a planned-only player is not offered a minutes box'
        );
        $this->assertSame( 60, (int) ( $cells[ $recorded ][ $match ]['minutes'] ?? 0 ) );
    }

    /* ---- fixtures ---------------------------------------------------- */

    /**
     * @return array<string,mixed>
     */
    private function packetAttendance( int $player_id ): array {
        $method = new ReflectionMethod( EvidencePacket::class, 'attendance' );
        $method->setAccessible( true );

        /** @var array<string,mixed> $out */
        $out = $method->invoke(
            null,
            $player_id,
            $this->club,
            gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
            gmdate( 'Y-m-d', strtotime( '+1 day' ) )
        );
        return $out;
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO17-1' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( string $status, string $plan_state = 'completed', string $type = 'training' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Activity',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'plan_state'          => $plan_state,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance(
        int $activity_id,
        int $player_id,
        string $record_type,
        string $status,
        ?int $minutes = null
    ): void {
        global $wpdb;
        $row = [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'is_guest'    => 0,
            'status'      => $status,
            'record_type' => $record_type,
        ];
        if ( $minutes !== null ) {
            $row['minutes_played'] = $minutes;
        }
        $wpdb->insert( "{$this->p}tt_attendance", $row );
    }
}
