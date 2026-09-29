<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Activities\Services\ActivityRecordedData;
use TT\Modules\Spond\SpondClient;
use TT\Modules\Spond\SpondFetchWindow;
use TT\Modules\Spond\SpondRemovedEvents;
use TT\Modules\Spond\SpondSync;

/**
 * #4182 — the Spond sync archived every Spond activity older than its
 * 30-day fetch window, played matches with their attendance and analysis
 * included.
 *
 * Covers the three rules that replace it: the sync only archives what the
 * feed was asked about, never archives an activity with recorded data, and
 * migration 0298 restores what the old sync archived while leaving a
 * person's archive alone.
 */
final class SpondArchiveWindowTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO14-1' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    // ── the window ─────────────────────────────────────────────────────

    public function test_the_window_is_defined_once_and_shared_with_the_client(): void {
        $this->assertSame( SpondFetchWindow::PAST_DAYS, SpondClient::WINDOW_PAST_DAYS );
        $this->assertSame( SpondFetchWindow::FUTURE_DAYS, SpondClient::WINDOW_FUTURE_DAYS );

        $src = (string) file_get_contents( (string) ( new \ReflectionClass( SpondClient::class ) )->getFileName() );
        $this->assertStringContainsString( '$window->startUtc()', $src );
        $this->assertStringContainsString( '$window->endUtc()', $src );

        $sync = (string) file_get_contents( (string) ( new \ReflectionClass( SpondSync::class ) )->getFileName() );
        $this->assertStringContainsString( 'SpondClient::fetchEvents( $group_id, $account, $window )', $sync );
        $this->assertStringContainsString( 'SpondRemovedEvents::apply( $team_id, $seen, $window )', $sync );
        $this->assertStringNotContainsString( 'SET archived_at = NOW()', $sync, 'the unbounded raw archive is back' );
    }

    public function test_the_window_covers_only_whole_days_inside_it(): void {
        $now    = (int) gmmktime( 12, 0, 0, 9, 29, 2026 );
        $window = SpondFetchWindow::around( $now, new \DateTimeZone( 'UTC' ) );

        $this->assertSame( '2026-08-30T12:00:00.000Z', $window->startUtc() );
        $this->assertSame( '2026-08-30', $window->firstDateExclusive() );
        $this->assertSame( '2027-03-28', $window->lastDateExclusive() );

        $this->assertFalse( $window->covers( '2026-08-24' ), 'a 36-day-old match aged out; it was not removed' );
        $this->assertFalse( $window->covers( '2026-08-30' ), 'the boundary day is half outside the fetch' );
        $this->assertTrue( $window->covers( '2026-08-31' ) );
        $this->assertTrue( $window->covers( '2026-12-01' ) );
        $this->assertFalse( $window->covers( '2027-03-28' ) );
    }

    // ── the sync's archive rule ────────────────────────────────────────

    public function test_an_activity_older_than_the_window_is_never_archived(): void {
        $old = $this->spond( $this->daysAgo( 45 ) );

        $result = SpondRemovedEvents::apply( $this->team, [ 'something-else' ], SpondFetchWindow::around() );

        $this->assertSame( 0, $result['archived'] );
        $this->assertNull( $this->archivedAt( $old ), 'a 45-day-old match stays live after a sync' );
    }

    public function test_a_future_event_removed_in_spond_is_archived_by_the_system(): void {
        $future = $this->spond( $this->daysAgo( -14 ) );

        $result = SpondRemovedEvents::apply( $this->team, [ 'something-else' ], SpondFetchWindow::around() );

        $this->assertSame( 1, $result['archived'] );
        $this->assertNotNull( $this->archivedAt( $future ) );
        $this->assertSame( '0', $this->archivedBy( $future ), 'a sync archive is attributed to the system, not left blank' );
    }

    public function test_an_event_still_in_the_feed_is_left_alone(): void {
        $seen = $this->spond( $this->daysAgo( -3 ), 'spond-seen' );

        SpondRemovedEvents::apply( $this->team, [ 'spond-seen' ], SpondFetchWindow::around() );

        $this->assertNull( $this->archivedAt( $seen ) );
    }

    public function test_a_played_event_removed_in_spond_is_kept_and_reported(): void {
        $played = $this->spond( $this->daysAgo( 5 ) );
        $this->attendance( $played );

        $plan = SpondRemovedEvents::plan( $this->team, [ 'something-else' ], SpondFetchWindow::around() );
        $this->assertSame( [], $plan['archive'] );
        $this->assertSame( $played, $plan['keep'][0]['activity_id'] );

        $result = SpondRemovedEvents::apply( $this->team, [ 'something-else' ], SpondFetchWindow::around() );
        $this->assertSame( [ 'archived' => 0, 'kept' => 1 ], $result );
        $this->assertNull( $this->archivedAt( $played ) );
    }

    public function test_a_manual_activity_is_never_touched(): void {
        $manual = $this->activity( $this->daysAgo( -3 ), 'manual', null );

        SpondRemovedEvents::apply( $this->team, [ 'something-else' ], SpondFetchWindow::around() );

        $this->assertNull( $this->archivedAt( $manual ) );
    }

    // ── recorded data ──────────────────────────────────────────────────

    public function test_recorded_data_is_status_attendance_or_evaluation(): void {
        $bare      = $this->spond( $this->daysAgo( 2 ) );
        $completed = $this->spond( $this->daysAgo( 2 ), null, 'completed' );
        $attended  = $this->spond( $this->daysAgo( 2 ) );
        $planned   = $this->spond( $this->daysAgo( 2 ) );
        $evaluated = $this->spond( $this->daysAgo( 2 ) );

        $this->attendance( $attended );
        $this->attendance( $planned, 'expected' );

        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'     => $this->club,
            'activity_id' => $evaluated,
            'player_id'   => 1,
            'coach_id'    => 1,
            'eval_date'   => $this->daysAgo( 2 ),
        ] );

        $with = ActivityRecordedData::idsWithRecordedData( [ $bare, $completed, $attended, $planned, $evaluated ] );
        sort( $with );

        $this->assertSame( [ $completed, $attended, $evaluated ], $with, 'a planned roster is not a recorded register' );
    }

    // ── migration 0298 ─────────────────────────────────────────────────

    public function test_the_migration_restores_sync_archives_and_leaves_user_archives(): void {
        $user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $now  = current_time( 'mysql' );

        $aged_out      = $this->spond( $this->daysAgo( 45 ) );
        $user_archived = $this->spond( $this->daysAgo( 45 ) );
        $removed       = $this->spond( $this->daysAgo( 5 ) );
        $removed_data  = $this->spond( $this->daysAgo( 5 ) );
        $system        = $this->spond( $this->daysAgo( 45 ) );
        $manual        = $this->activity( $this->daysAgo( 45 ), 'manual', null );
        $this->attendance( $removed_data );

        $this->archive( $aged_out, $now, null );
        $this->archive( $user_archived, $now, $user );
        $this->archive( $removed, $now, null );
        $this->archive( $removed_data, $now, null );
        $this->archive( $system, $now, 0 );
        $this->archive( $manual, $now, null );

        $this->migrate();

        $this->assertNull( $this->archivedAt( $aged_out ), 'an activity that only aged out of the window is restored' );
        $this->assertNull( $this->archivedAt( $removed_data ), 'an activity with recorded data is restored' );
        $this->assertNotNull( $this->archivedAt( $user_archived ), 'a person\'s archive stays' );
        $this->assertSame( (string) $user, $this->archivedBy( $user_archived ) );
        $this->assertNotNull( $this->archivedAt( $removed ), 'a bare in-window event really was removed in Spond' );
        $this->assertNotNull( $this->archivedAt( $system ), 'an attributed system archive is not the old sync\'s' );
        $this->assertNotNull( $this->archivedAt( $manual ), 'only Spond rows' );

        // Idempotent: nothing changes on a second run.
        $this->migrate();
        $this->assertNull( $this->archivedAt( $aged_out ) );
        $this->assertNotNull( $this->archivedAt( $user_archived ) );
        $this->assertNotNull( $this->archivedAt( $removed ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function migrate(): void {
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0298_restore_spond_sync_archived_activities.php';
        $migration->up();
    }

    private function daysAgo( int $days ): string {
        return wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
    }

    private function spond( string $date, ?string $uid = null, string $status = 'planned' ): int {
        return $this->activity( $date, 'spond', $uid ?? uniqid( 'spond-', true ), $status );
    }

    private function activity( string $date, string $source, ?string $uid, string $status = 'planned' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Wedstrijd',
            'session_date'        => $date,
            'activity_type_key'   => 'match',
            'activity_status_key' => $status,
            'activity_source_key' => $source,
            'external_id'         => $uid,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attendance( int $activity_id, string $record_type = 'actual' ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => 1,
            'is_guest'       => 0,
            'status'         => 'present',
            'record_type'    => $record_type,
            'minutes_played' => 60,
        ] );
    }

    private function archive( int $id, string $at, ?int $by ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_activities", [ 'archived_at' => $at, 'archived_by' => $by ], [ 'id' => $id ] );
    }

    private function archivedAt( int $id ): ?string {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare( "SELECT archived_at FROM {$this->p}tt_activities WHERE id = %d", $id ) );
        return $v === null ? null : (string) $v;
    }

    private function archivedBy( int $id ): ?string {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare( "SELECT archived_by FROM {$this->p}tt_activities WHERE id = %d", $id ) );
        return $v === null ? null : (string) $v;
    }
}
