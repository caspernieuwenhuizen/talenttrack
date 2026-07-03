<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use ReflectionMethod;
use TT\Infrastructure\REST\ActivitiesRestController;

/**
 * #2248 — the planned (expected) attendance plan status maps to a stored
 * attendance_status and back. Expected → present, Not coming → absent,
 * Maybe → excused (excused is reused so no lookup seed / migration is
 * needed). These pin the round-trip so a renamed/unknown value degrades
 * to the safe "expected" default rather than corrupting the plan.
 */
final class PlannedAttendanceStatusMapTest extends WP_UnitTestCase {

    /** @return array<string,string> */
    private function map(): array {
        $m = new ReflectionMethod( ActivitiesRestController::class, 'plannedStatusMap' );
        $m->setAccessible( true );
        return (array) $m->invoke( null );
    }

    private function toKey( string $status ): string {
        $m = new ReflectionMethod( ActivitiesRestController::class, 'plannedStatusToKey' );
        $m->setAccessible( true );
        return (string) $m->invoke( null, $status );
    }

    public function test_plan_keys_map_to_stored_statuses(): void {
        $map = $this->map();
        $this->assertSame( 'present', $map['expected'] );
        $this->assertSame( 'absent',  $map['not_coming'] );
        $this->assertSame( 'excused', $map['maybe'] );
    }

    public function test_stored_status_round_trips_to_plan_key(): void {
        $this->assertSame( 'expected',   $this->toKey( 'present' ) );
        $this->assertSame( 'not_coming', $this->toKey( 'absent' ) );
        $this->assertSame( 'maybe',      $this->toKey( 'excused' ) );
    }

    public function test_stored_status_is_case_insensitive(): void {
        $this->assertSame( 'not_coming', $this->toKey( 'Absent' ) );
        $this->assertSame( 'maybe',      $this->toKey( '  EXCUSED ' ) );
    }

    public function test_unknown_status_defaults_to_expected(): void {
        $this->assertSame( 'expected', $this->toKey( 'late' ) );
        $this->assertSame( 'expected', $this->toKey( '' ) );
    }
}
