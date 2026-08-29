<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3031 — migration 0242 repairs the Dutch label for the `Left` foot
 * option without touching a label an academy chose for itself.
 *
 * The bug it fixes: migration 0086 backfilled lookup translations from
 * gettext, the catalogue's only `msgid "Left"` meant *left the academy*,
 * and migration 0151's `INSERT IGNORE` then declined to correct the slot
 * it had filled.
 */
final class FootOptionTranslationRepairTest extends WP_UnitTestCase {

    private string $p;
    private int $club = 1;

    /** @var int[] lookup row ids created by this test, by canonical name */
    private array $lookups = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        // Fixture rows of our own rather than the seeded `foot_option`
        // vocabulary: the migration walks every row of that type, and
        // asserting against seeded data would couple this test to whatever
        // the install happens to have seeded.
        foreach ( [ 'Left', 'Right' ] as $name ) {
            $wpdb->insert( "{$this->p}tt_lookups", [
                'club_id'     => $this->club,
                'lookup_type' => 'foot_option',
                'name'        => $name,
            ] );
            $this->lookups[ $name ] = (int) $wpdb->insert_id;
        }
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( $this->lookups as $id ) {
            $wpdb->delete( "{$this->p}tt_translations", [ 'entity_type' => 'lookup', 'entity_id' => $id ] );
            $wpdb->delete( "{$this->p}tt_lookups", [ 'id' => $id ] );
        }
        parent::tear_down();
    }

    public function test_the_wrong_sense_value_is_replaced(): void {
        $this->putTranslation( $this->lookups['Left'], 'nl_NL', 'Vertrokken' );

        $this->runMigration();

        $this->assertSame( 'Links', $this->translationOf( $this->lookups['Left'], 'nl_NL' ) );
    }

    public function test_a_label_the_academy_chose_is_left_alone(): void {
        $this->putTranslation( $this->lookups['Left'], 'nl_NL', 'Linksbenig' );

        $this->runMigration();

        $this->assertSame(
            'Linksbenig',
            $this->translationOf( $this->lookups['Left'], 'nl_NL' ),
            'a value that is not the known wrong-sense string is the operator\'s, not ours to rewrite'
        );
    }

    public function test_a_missing_translation_is_filled_in(): void {
        $this->runMigration();

        $this->assertSame( 'Rechts', $this->translationOf( $this->lookups['Right'], 'nl_NL' ) );
    }

    public function test_running_twice_changes_nothing_further(): void {
        $this->putTranslation( $this->lookups['Left'], 'nl_NL', 'Vertrokken' );

        $this->runMigration();
        $this->runMigration();

        $this->assertSame( 'Links', $this->translationOf( $this->lookups['Left'], 'nl_NL' ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function runMigration(): void {
        $path = dirname( __DIR__, 2 ) . '/database/migrations/0242_repair_foot_option_translations.php';
        $this->assertFileExists( $path );
        $migration = require $path;
        $migration->up();
    }

    private function putTranslation( int $lookup_id, string $locale, string $value ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_translations", [
            'club_id'     => $this->club,
            'entity_type' => 'lookup',
            'entity_id'   => $lookup_id,
            'field'       => 'name',
            'locale'      => $locale,
            'value'       => $value,
            'updated_at'  => current_time( 'mysql', true ),
        ] );
    }

    private function translationOf( int $lookup_id, string $locale ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT value FROM {$this->p}tt_translations
              WHERE entity_type = 'lookup' AND entity_id = %d AND field = 'name' AND locale = %s",
            $lookup_id,
            $locale
        ) );
    }
}
