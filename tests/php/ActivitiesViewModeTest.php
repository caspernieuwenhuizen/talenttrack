<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\ActivitiesViewMode;

/**
 * #2390 — the activities list/calendar toggle is remembered per user. List
 * is the default; an explicit ?view_mode both switches and persists.
 */
final class ActivitiesViewModeTest extends WP_UnitTestCase {

    public function tear_down(): void {
        unset( $_GET['view_mode'] );
        parent::tear_down();
    }

    public function test_defaults_to_list(): void {
        $uid = self::factory()->user->create();
        $this->assertSame( ActivitiesViewMode::LIST, ActivitiesViewMode::forUser( $uid ) );
    }

    public function test_override_persists_calendar_then_clears_back_to_list(): void {
        $uid = self::factory()->user->create();

        ActivitiesViewMode::setUserOverride( $uid, ActivitiesViewMode::CALENDAR );
        $this->assertSame( ActivitiesViewMode::CALENDAR, ActivitiesViewMode::forUser( $uid ) );

        ActivitiesViewMode::setUserOverride( $uid, ActivitiesViewMode::LIST );
        $this->assertSame( ActivitiesViewMode::LIST, ActivitiesViewMode::forUser( $uid ) );
    }

    public function test_resolve_reads_and_persists_query_param(): void {
        $uid = self::factory()->user->create();

        $_GET['view_mode'] = 'calendar';
        $this->assertSame( ActivitiesViewMode::CALENDAR, ActivitiesViewMode::resolve( $uid ) );
        // Persisted, so a later request with no param still resolves calendar.
        unset( $_GET['view_mode'] );
        $this->assertSame( ActivitiesViewMode::CALENDAR, ActivitiesViewMode::forUser( $uid ) );
    }

    public function test_resolve_ignores_a_bogus_mode(): void {
        $uid = self::factory()->user->create();
        $_GET['view_mode'] = 'nonsense';
        $this->assertSame( ActivitiesViewMode::LIST, ActivitiesViewMode::resolve( $uid ) );
    }
}
