<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\FactQuery;

/**
 * #2440 — the Explorer advertised a relative date bound (`-30 days`) in its
 * input placeholder, its docblocks and four KPI `defaultFilters`, but nothing
 * ever expanded it. The raw string was bound straight into a comparison
 * against a DATE column, where MySQL coerces it to `0000-00-00` — so the
 * filter matched every row while looking like a 30-day window.
 *
 * These tests lock the normaliser that now sits at the bind-time chokepoint:
 * absolute dates pass through untouched, relative forms resolve to a real
 * date, and anything unparseable returns null so the caller drops the clause
 * rather than silently widening the query to everything.
 */
final class ExplorerDateBoundTest extends WP_UnitTestCase {

    public function test_absolute_dates_pass_through_unchanged(): void {
        $this->assertSame( '2026-01-15', FactQuery::normaliseDateBound( '2026-01-15' ) );
        $this->assertSame( '1999-12-31', FactQuery::normaliseDateBound( '1999-12-31' ) );
    }

    public function test_relative_forms_resolve_to_an_absolute_date(): void {
        // Regression: these were the bounds that matched everything. `-30 days`
        // ships on four KPI defaultFilters, `-12 months` on the explorer presets.
        $base = strtotime( gmdate( 'Y-m-d' ) );
        $this->assertSame(
            gmdate( 'Y-m-d', strtotime( '-30 days', $base ) ),
            FactQuery::normaliseDateBound( '-30 days' )
        );
        $this->assertSame(
            gmdate( 'Y-m-d', strtotime( '-12 months', $base ) ),
            FactQuery::normaliseDateBound( '-12 months' )
        );
    }

    public function test_relative_form_accepts_every_supported_unit_and_spelling(): void {
        $base = strtotime( gmdate( 'Y-m-d' ) );
        $this->assertSame( gmdate( 'Y-m-d', strtotime( '-1 week', $base ) ), FactQuery::normaliseDateBound( '-1 week' ) );
        $this->assertSame( gmdate( 'Y-m-d', strtotime( '+7 days', $base ) ), FactQuery::normaliseDateBound( '+7 days' ) );
        // Singular / plural, missing space and mixed case all resolve alike.
        $this->assertSame( gmdate( 'Y-m-d', strtotime( '-30 days', $base ) ), FactQuery::normaliseDateBound( '-30days' ) );
        $this->assertSame( gmdate( 'Y-m-d', strtotime( '-1 year', $base ) ), FactQuery::normaliseDateBound( '-1 YEAR' ) );
    }

    public function test_relative_bound_is_anchored_to_midnight_not_now(): void {
        // Whole days back, so the window does not depend on the time of day
        // the report happens to be opened.
        $base = strtotime( gmdate( 'Y-m-d' ) );
        $this->assertSame( gmdate( 'Y-m-d', strtotime( '-1 day', $base ) ), FactQuery::normaliseDateBound( '-1 day' ) );
    }

    public function test_unparseable_and_empty_bounds_return_null(): void {
        // Null means "drop the clause" — never bind a value that would be
        // coerced to 0000-00-00 and match the whole table.
        $this->assertNull( FactQuery::normaliseDateBound( '' ) );
        $this->assertNull( FactQuery::normaliseDateBound( '   ' ) );
        $this->assertNull( FactQuery::normaliseDateBound( 'not a date' ) );
    }

    public function test_near_miss_relative_forms_are_rejected_rather_than_guessed(): void {
        // strtotime() is lenient enough to turn these into plausible-but-wrong
        // dates. A typo must read as "no filter", not as a different window —
        // that failure mode is harder to notice than no filter at all.
        $this->assertNull( FactQuery::normaliseDateBound( '30 dayz ago' ) );
        $this->assertNull( FactQuery::normaliseDateBound( 'tomorrow' ) );
        $this->assertNull( FactQuery::normaliseDateBound( 'last monday' ) );
    }

    public function test_impossible_calendar_dates_are_rejected(): void {
        // Right shape, not a real date. `0000-00-00` is precisely the value
        // MySQL coerced the broken bound to, so it must never bind.
        $this->assertNull( FactQuery::normaliseDateBound( '0000-00-00' ) );
        $this->assertNull( FactQuery::normaliseDateBound( '2026-02-30' ) );
        $this->assertNull( FactQuery::normaliseDateBound( '2026-13-01' ) );
    }

    public function test_surrounding_whitespace_is_tolerated(): void {
        $this->assertSame( '2026-01-15', FactQuery::normaliseDateBound( '  2026-01-15  ' ) );
    }
}
