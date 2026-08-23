<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Privacy\CorePiiRegistrations;
use TT\Infrastructure\Privacy\PlayerDataMap;

/**
 * #2758 — every PII registration names a column its table actually has.
 *
 * `tt_eval_ratings` was registered against a `player_id` column it does not
 * have; a rating reaches a player through `tt_evaluations`. Every call to
 * `rowCountsForPlayer()` emitted a database error, and once #2743 taught that
 * method to skip a missing column the registration went quiet instead —
 * claiming coverage it never provided, which is the one failure mode a PII
 * registry exists to prevent.
 *
 * `rowCountsForPlayer()` is deliberately tolerant: it skips a dangling
 * registration rather than raising, because a disabled module legitimately
 * leaves one behind. That tolerance is right for runtime and wrong for CI —
 * so the assertion lives here, where a mis-registration fails loudly.
 */
final class PiiRegistrationColumnsTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        CorePiiRegistrations::register();
    }

    /**
     * A table absent from this install is not a failure — a module can be
     * toggled off, leaving its registration dangling. A table that is
     * present while its registered column is not is always a bug.
     */
    public function test_every_registration_names_a_column_its_table_has(): void {
        global $wpdb;

        $checked = 0;
        $broken  = [];

        foreach ( PlayerDataMap::all() as $reg ) {
            $table  = $wpdb->prefix . $reg['table'];
            $column = (string) $reg['player_id_column'];

            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                continue;
            }

            $columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
            if ( ! in_array( $column, $columns, true ) ) {
                $broken[] = "{$reg['table']}.{$column}";
            }
            $checked++;
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'no registered table exists on this install — the assertion below would pass vacuously'
        );
        $this->assertSame(
            [],
            $broken,
            'registrations naming a column their table does not have: ' . implode( ', ', $broken )
        );
    }

    /**
     * The specific registration that prompted this, pinned so it cannot be
     * reinstated without also reading why it was removed.
     */
    public function test_eval_ratings_reaches_a_player_through_its_parent(): void {
        $this->assertFalse(
            PlayerDataMap::isRegistered( 'tt_eval_ratings' ),
            'tt_eval_ratings has no player column; erasure and access follow tt_evaluations'
        );
        $this->assertTrue(
            PlayerDataMap::isRegistered( 'tt_evaluations' ),
            'the parent registration is what covers the ratings'
        );
    }
}
