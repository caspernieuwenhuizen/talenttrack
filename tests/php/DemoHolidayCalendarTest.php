<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\Generators\ActivityContentGenerator;

/**
 * #4040 — a generated holiday is named by the date it falls on.
 *
 * The generator used to place three breaks at fixed fractions of the window
 * and label them winter, spring and summer in that order, whatever the
 * calendar said. On a September run that produced a "Winterstop" from
 * 2026-07-09 to 07-23 and a "Voorjaarsvakantie" in August, and no May break
 * at all. The holiday calendar decides when a team trains, so a wrong one
 * hides where a player's next weeks go.
 *
 * Every clock here is pinned: the defect was precisely a dependence on what
 * month the generator happened to run in.
 */
final class DemoHolidayCalendarTest extends WP_UnitTestCase {

    /** Mid-September 2026 — the month the reported run happened in. */
    private const SEPTEMBER = 1789430400; // 2026-09-15 00:00:00 UTC

    /** Mid-March 2027, the other side of the season. */
    private const MARCH = 1805328000; // 2027-03-15 00:00:00 UTC

    /**
     * The months each Dutch break may start in. A name outside its months is
     * the defect.
     *
     * @var array<string, list<int>>
     */
    private const MONTHS_BY_NAME = [
        'Voorjaarsvakantie' => [ 2, 3 ],
        'Meivakantie'       => [ 4, 5 ],
        'Zomerstop'         => [ 7, 8 ],
        'Herfstvakantie'    => [ 10 ],
        'Kerstvakantie'     => [ 12 ],
    ];

    private function generateAt( int $now, int $weeks = 8 ): DemoBatchRegistry {
        $registry = new DemoBatchRegistry( 'test-4040-' . $now . '-' . $weeks );

        ( new ActivityContentGenerator(
            $registry,
            [],
            $weeks,
            'nl_NL',
            new DemoCalendar( $weeks, $now )
        ) )->generate();

        return $registry;
    }

    /** @return list<object> the generated rows, oldest first */
    private function holidays(): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            "SELECT name, start_date, end_date, note, color
               FROM {$wpdb->prefix}tt_holidays ORDER BY start_date ASC"
        );
    }

    // ----- The name matches the calendar -----

    public function test_a_september_run_names_every_break_after_its_own_month(): void {
        $this->generateAt( self::SEPTEMBER );
        $this->assertNamesMatchTheirMonths();
    }

    public function test_a_march_run_names_every_break_after_its_own_month(): void {
        $this->generateAt( self::MARCH );
        $this->assertNamesMatchTheirMonths();
    }

    public function test_a_season_long_window_covers_the_may_and_summer_breaks(): void {
        $this->generateAt( self::MARCH, 52 );

        $names = array_column( array_map( 'get_object_vars', $this->holidays() ), 'name' );
        $this->assertContains( 'Meivakantie', $names, 'a window over late April gets a May break' );
        $this->assertContains( 'Zomerstop', $names, 'and one over July gets a summer break' );
        $this->assertNamesMatchTheirMonths();
    }

    /** The reported symptom, as a case: no winter or spring break in high summer. */
    public function test_no_winter_or_spring_break_is_dated_in_july_or_august(): void {
        $this->generateAt( self::SEPTEMBER, 26 );

        foreach ( $this->holidays() as $row ) {
            $month = (int) gmdate( 'n', (int) strtotime( (string) $row->start_date . ' UTC' ) );
            if ( ! in_array( $month, [ 7, 8 ], true ) ) continue;

            $this->assertSame(
                'Zomerstop',
                (string) $row->name,
                'a break in July or August is the summer one, whatever the run date'
            );
        }
    }

    // ----- Shape of what is written -----

    public function test_generated_breaks_do_not_overlap_each_other(): void {
        $this->generateAt( self::SEPTEMBER, 104 );

        $previous_end = '';
        foreach ( $this->holidays() as $row ) {
            if ( $previous_end !== '' ) {
                $this->assertGreaterThan(
                    $previous_end,
                    (string) $row->start_date,
                    'two breaks covering the same day would double-block a training calendar'
                );
            }
            $this->assertGreaterThan( (string) $row->start_date, (string) $row->end_date );
            $previous_end = (string) $row->end_date;
        }
    }

    public function test_a_generated_break_reads_like_a_hand_entered_one(): void {
        $this->generateAt( self::SEPTEMBER, 52 );

        $rows = $this->holidays();
        $this->assertNotSame( [], $rows, 'a year-long window touches at least one break' );

        foreach ( $rows as $row ) {
            $this->assertNotNull( $row->note, 'a generated break carries a note, like the ones staff enter' );
            $this->assertNotSame( '', (string) $row->note );
            $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', (string) $row->color );
        }
    }

    public function test_every_generated_break_is_tagged_so_the_wipe_reaches_it(): void {
        $registry = $this->generateAt( self::SEPTEMBER, 52 );

        $this->assertCount(
            count( $this->holidays() ),
            $registry->entityIds( 'holiday' ),
            'an untagged holiday survives the demo wipe for ever'
        );
    }

    public function test_a_second_run_into_the_same_install_does_not_double_the_calendar(): void {
        $this->generateAt( self::SEPTEMBER, 52 );
        $first = count( $this->holidays() );
        $this->assertGreaterThan( 0, $first );

        $this->generateAt( self::SEPTEMBER, 52 );
        $this->assertCount( $first, $this->holidays(), 'the same break is not written twice' );
    }

    private function assertNamesMatchTheirMonths(): void {
        $rows = $this->holidays();
        $this->assertNotSame( [], $rows, 'the window must produce at least one break, or this proves nothing' );

        foreach ( $rows as $row ) {
            $name  = (string) $row->name;
            $month = (int) gmdate( 'n', (int) strtotime( (string) $row->start_date . ' UTC' ) );

            $this->assertArrayHasKey( $name, self::MONTHS_BY_NAME, "unexpected break name {$name}" );
            $this->assertContains(
                $month,
                self::MONTHS_BY_NAME[ $name ],
                "{$name} starting in month {$month} is the #4040 defect"
            );
        }
    }
}
