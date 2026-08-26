<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2863 — one column, two stored casings, one of them untranslatable.
 *
 * `tt_attendance.status` is written as `Present` by the attendance wizard
 * and as `present` by the planned-roster path, while the seeded
 * `attendance_status` lookup rows are Title Case. `LookupTranslator`
 * matched the stored value against lookup names exactly, so `Present`
 * resolved to *Aanwezig* and `present` fell through to `__()`, found no
 * msgid, and printed the raw key — which is why one column on the player
 * profile showed Dutch on some rows and English on others.
 *
 * `LookupPill::resolveRow()` has had a normalised fallback since v3.71.2
 * for the same class of mismatch. This pins the equivalent on
 * `LookupTranslator`, so which surface a value is rendered on no longer
 * decides whether it translates.
 *
 * Reading only. Making the two writers agree is a migration, tracked
 * separately.
 */
final class LookupTranslatorCaseFallbackTest extends WP_UnitTestCase {

    private const TYPE = 'attendance_status';

    /**
     * Every row this class needs is inserted here, not in a test body.
     * `rowByTypeAndName()` memoises per lookup type in a `static` that
     * outlives an individual test — the cache is built on the first call of
     * the process and each test's inserts are rolled back afterwards. A row
     * inserted inside a test would land *after* the cache was built and be
     * invisible to it. Seeding the same set in `set_up()` keeps whichever
     * test runs first consistent with the rest.
     */
    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        // Title Case lookup rows, as the seed writes them.
        foreach ( [ 'Present' => 1, 'On Hold' => 2 ] as $name => $order ) {
            $wpdb->insert( $wpdb->prefix . 'tt_lookups', [
                'club_id'     => (int) CurrentClub::id(),
                'lookup_type' => self::TYPE,
                'name'        => $name,
                'sort_order'  => $order,
            ] );
        }
    }

    /** The casing the wizard writes has always worked. */
    public function test_exact_match_still_resolves(): void {
        $this->assertSame( 'Present', LookupTranslator::byTypeAndName( self::TYPE, 'Present' ) );
    }

    /**
     * The casing the planned-roster path writes. Before the fallback this
     * returned the raw `present`, because no msgid of that name exists.
     */
    public function test_a_case_variant_resolves_to_the_lookup_row(): void {
        $this->assertSame(
            'Present',
            LookupTranslator::byTypeAndName( self::TYPE, 'present' ),
            'a lowercase stored value must find its Title Case lookup row'
        );
    }

    /** Separator variants collapse too, matching LookupPill's rule. */
    public function test_separator_variants_resolve(): void {
        $this->assertSame( 'On Hold', LookupTranslator::byTypeAndName( self::TYPE, 'on_hold' ) );
        $this->assertSame( 'On Hold', LookupTranslator::byTypeAndName( self::TYPE, 'on-hold' ) );
    }

    /**
     * A value that matches no row, in any casing, still falls through to
     * the translation layer rather than being swallowed.
     */
    public function test_an_unknown_value_is_unchanged(): void {
        $this->assertSame(
            'Teleported',
            LookupTranslator::byTypeAndName( self::TYPE, 'Teleported' )
        );
    }

    public function test_an_empty_value_stays_empty(): void {
        $this->assertSame( '', LookupTranslator::byTypeAndName( self::TYPE, '' ) );
    }
}
