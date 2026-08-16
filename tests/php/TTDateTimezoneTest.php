<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Dates\TTDate;

/**
 * #2437 — TTDate reads a bare DATETIME as site-local, not UTC.
 *
 * WordPress pins PHP's default timezone to UTC, so a plain strtotime() on a
 * `current_time( 'mysql' )` stamp read it as UTC and wp_date() then added
 * the site offset a second time: a Spond sync at 20:20 CEST printed as
 * 22:20, i.e. in the future. A non-UTC site timezone is the only setting
 * where the bug is observable, so these tests pin one.
 *
 * Expectations are built from wp_date() rather than hardcoded strings, so
 * they hold whatever date-notation preset the install runs.
 */
final class TTDateTimezoneTest extends WP_UnitTestCase {

    /** 2026-08-16 18:20:00 UTC — 20:20 in Amsterdam, 14:20 in New York. */
    private const FIXED_TS = 1786904400;

    private string $prev_tz = '';

    public function set_up(): void {
        parent::set_up();
        $this->prev_tz = (string) get_option( 'timezone_string' );
        update_option( 'timezone_string', 'Europe/Amsterdam' );
    }

    public function tear_down(): void {
        update_option( 'timezone_string', $this->prev_tz );
        parent::tear_down();
    }

    private function expectedDateTime( int $ts ): string {
        return wp_date( TTDate::dateFormat() . ', H:i', $ts );
    }

    /**
     * The regression itself: a site-local stamp renders back as the same
     * wall clock, not one site-offset later.
     */
    public function test_local_datetime_round_trips_without_double_offset(): void {
        $stored = wp_date( 'Y-m-d H:i:s', self::FIXED_TS ); // what current_time( 'mysql' ) writes

        $this->assertSame( '2026-08-16 20:20:00', $stored, 'Precondition: the site clock is CEST.' );
        $this->assertSame( $this->expectedDateTime( self::FIXED_TS ), TTDate::dateTime( $stored ) );
        $this->assertStringContainsString( '20:20', TTDate::dateTime( $stored ) );
    }

    /** Same guarantee against the live clock — nothing renders in the future. */
    public function test_stamp_written_now_does_not_render_ahead_of_now(): void {
        $rendered = TTDate::dateTime( current_time( 'mysql' ) );

        // Minute precision: allow the clock to tick between the two calls.
        $this->assertContains(
            $rendered,
            [ $this->expectedDateTime( time() ), $this->expectedDateTime( time() + 60 ) ],
            'A stamp written this minute must not render ahead of the current wall clock.'
        );
    }

    /** A date-only string keeps its calendar day on both sides of UTC. */
    public function test_date_only_keeps_its_calendar_day_either_side_of_utc(): void {
        foreach ( [ 'Europe/Amsterdam', 'America/New_York' ] as $tz ) {
            update_option( 'timezone_string', $tz );

            $expected = wp_date( TTDate::dateFormat(), strtotime( '2026-08-18 12:00:00 UTC' ) );
            $this->assertSame( $expected, TTDate::date( '2026-08-18' ), "Calendar day drifted in {$tz}." );
        }
    }

    /** A string that names its own offset is honoured, not re-zoned. */
    public function test_explicit_offset_in_the_string_wins(): void {
        $this->assertSame(
            $this->expectedDateTime( self::FIXED_TS ),
            TTDate::dateTime( '2026-08-16T18:20:00Z' )
        );
    }

    /** The escape hatch for the columns that really do store UTC. */
    public function test_date_time_from_gmt_converts_utc_columns(): void {
        $this->assertSame(
            $this->expectedDateTime( self::FIXED_TS ),
            TTDate::dateTimeFromGmt( '2026-08-16 18:20:00' )
        );
        $this->assertSame( '', TTDate::dateTimeFromGmt( '' ) );
        $this->assertSame( '', TTDate::dateTimeFromGmt( '0000-00-00 00:00:00' ) );
    }

    /** Unparseable input still degrades to an empty string, not a fatal. */
    public function test_unparseable_input_returns_empty_string(): void {
        $this->assertSame( '', TTDate::dateTime( 'not a date' ) );
    }
}
