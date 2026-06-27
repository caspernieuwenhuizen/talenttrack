<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Archive\LifecycleFields;
use TT\Modules\Holidays\Rest\HolidaysRestController;

/**
 * #2023 (epic #2018) — archived-list affordances + payload audit.
 *
 * Bug 2: archived holiday rows showed Restore but no destructive action,
 * because `HolidaysRestController::serialize()` omitted `archived_at` — the
 * FrontendListTable `show_if => 'archived_at'` check then hid BOTH archived-
 * row actions. The regression guard asserts the holiday row payload now
 * carries `archived_at` (so the actions render) AND the new `trashed_at`.
 *
 * The shared LifecycleFields helper is the single source for both fields, so
 * a couple of direct assertions on it lock the contract in one place.
 */
final class RecycleBinListPayloadTest extends WP_UnitTestCase {

    /**
     * Bug-2 root-cause guard: the serialized holiday row exposes the
     * lifecycle fields the list-table `show_if` reads. Without `archived_at`
     * the Restore + Move-to-recycle-bin actions both vanish on archived rows.
     */
    public function test_holiday_serialize_exposes_lifecycle_fields(): void {
        $row = (object) [
            'id'          => 7,
            'uuid'        => 'abc',
            'name'        => 'Summer break',
            'start_date'  => '2026-07-01',
            'end_date'    => '2026-07-31',
            'note'        => null,
            'color'       => null,
            'archived_at' => '2026-06-01 10:00:00',
            'trashed_at'  => null,
        ];

        $payload = $this->serializeHoliday( $row );

        $this->assertArrayHasKey( 'archived_at', $payload, 'Bug 2: archived_at must be in the holiday payload' );
        $this->assertArrayHasKey( 'trashed_at', $payload, 'trashed_at must be in the holiday payload' );
        $this->assertSame( '2026-06-01 10:00:00', $payload['archived_at'] );
        $this->assertNull( $payload['trashed_at'] );
    }

    /** An active (non-archived) holiday reports both fields as null. */
    public function test_holiday_serialize_active_row_has_null_lifecycle_fields(): void {
        $row = (object) [
            'id'          => 8,
            'uuid'        => 'def',
            'name'        => 'Active',
            'start_date'  => '2026-08-01',
            'end_date'    => '2026-08-02',
            'note'        => null,
            'color'       => null,
            'archived_at' => null,
            'trashed_at'  => null,
        ];

        $payload = $this->serializeHoliday( $row );

        $this->assertNull( $payload['archived_at'] );
        $this->assertNull( $payload['trashed_at'] );
    }

    /** The shared helper normalises both lifecycle fields, empty → null. */
    public function test_lifecycle_fields_helper_normalises_values(): void {
        $row = (object) [ 'archived_at' => '2026-06-01 00:00:00', 'trashed_at' => '' ];
        $out = LifecycleFields::forRow( $row );

        $this->assertSame( [ 'archived_at', 'trashed_at' ], array_keys( $out ) );
        $this->assertSame( '2026-06-01 00:00:00', $out['archived_at'] );
        $this->assertNull( $out['trashed_at'], 'empty-string trashed_at normalises to null' );

        // A row missing the columns entirely still yields both keys as null.
        $bare = LifecycleFields::forRow( (object) [] );
        $this->assertNull( $bare['archived_at'] );
        $this->assertNull( $bare['trashed_at'] );
    }

    /** @return array<string,mixed> */
    private function serializeHoliday( object $row ): array {
        $m = new ReflectionMethod( HolidaysRestController::class, 'serialize' );
        $m->setAccessible( true );
        return (array) $m->invoke( null, $row );
    }
}
