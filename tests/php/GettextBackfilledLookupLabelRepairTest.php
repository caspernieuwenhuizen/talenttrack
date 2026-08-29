<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3082 — migration 0247 repairs a lookup label that migration 0086's
 * gettext backfill wrote in the wrong sense, and leaves alone a label an
 * academy chose for itself.
 *
 * The two cases the migration has to tell apart are the whole point:
 *
 *   - stored value is character-for-character what a bare `__()` returns
 *     for that canonical name → 0086 wrote it, repair it;
 *   - stored value is anything else → an operator wrote it, keep it.
 *
 * The derived half needs a catalogue to derive from, which a test
 * install has no guarantee of, so it is exercised through the
 * `gettext` filter: the migration asks `__()` the same question 0086
 * asked, and a filter is how a test gets to control the answer.
 */
final class GettextBackfilledLookupLabelRepairTest extends WP_UnitTestCase {

    private string $p;
    private int $club = 1;

    /** @var array<string, int> lookup row ids created by this test, by canonical name */
    private array $lookups = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        // Run as a Dutch install. That is the case the repair is for, and
        // it is also the branch of `gettextByLocale()` that has to work
        // without `switch_to_locale()` — that function returns false when
        // asked for the locale already in effect, so on a Dutch site the
        // most important locale is precisely the one it will not switch to.
        add_filter( 'locale', static fn () => 'nl_NL' );

        // Fixture rows of our own: the migration walks every curated row
        // on the install, and asserting against seeded data would couple
        // the test to whatever that install happens to hold.
        foreach ( [ 'Left', 'Right' ] as $name ) {
            $wpdb->insert( "{$this->p}tt_lookups", [
                'club_id'     => $this->club,
                'lookup_type' => 'foot_option',
                'name'        => $name,
            ] );
            $this->lookups[ $name ] = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_lookups", [
            'club_id'     => $this->club,
            'lookup_type' => 'goal_priority',
            'name'        => 'Medium',
        ] );
        $this->lookups['Medium'] = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( $this->lookups as $id ) {
            $wpdb->delete( "{$this->p}tt_translations", [ 'entity_type' => 'lookup', 'entity_id' => $id ] );
            $wpdb->delete( "{$this->p}tt_lookups", [ 'id' => $id ] );
        }
        parent::tear_down();
    }

    public function test_a_value_the_catalogue_would_have_produced_is_replaced(): void {
        $this->pretendCatalogueSays( 'Left', 'Vertrokken' );
        $this->putTranslation( $this->lookups['Left'], 'nl_NL', 'Vertrokken' );

        $this->runMigration();

        $this->assertSame( 'Links', $this->translationOf( $this->lookups['Left'], 'nl_NL' ) );
    }

    public function test_a_label_the_academy_chose_is_left_alone(): void {
        $this->pretendCatalogueSays( 'Left', 'Vertrokken' );
        $this->putTranslation( $this->lookups['Left'], 'nl_NL', 'Linksbenig' );

        $this->runMigration();

        $this->assertSame(
            'Linksbenig',
            $this->translationOf( $this->lookups['Left'], 'nl_NL' ),
            'a value the catalogue would not have produced is the operator\'s, not ours to rewrite'
        );
    }

    public function test_a_catalogue_entry_that_matches_the_seed_is_not_treated_as_damage(): void {
        $this->pretendCatalogueSays( 'Left', 'Links' );
        $this->putTranslation( $this->lookups['Left'], 'nl_NL', 'Links' );

        $this->runMigration();

        $this->assertSame( 'Links', $this->translationOf( $this->lookups['Left'], 'nl_NL' ) );
    }

    public function test_a_missing_translation_is_filled_in(): void {
        $this->runMigration();

        $this->assertSame( 'Rechts', $this->translationOf( $this->lookups['Right'], 'nl_NL' ) );
    }

    public function test_the_mistranslated_medium_priority_seed_is_repaired(): void {
        // 'Middel' never came from gettext — the curated seed itself was
        // wrong — so it only gets repaired because the migration lists it.
        $this->putTranslation( $this->lookups['Medium'], 'nl_NL', 'Middel' );

        $this->runMigration();

        $this->assertSame( 'Gemiddeld', $this->translationOf( $this->lookups['Medium'], 'nl_NL' ) );
    }

    public function test_running_twice_changes_nothing_further(): void {
        $this->pretendCatalogueSays( 'Left', 'Vertrokken' );
        $this->putTranslation( $this->lookups['Left'], 'nl_NL', 'Vertrokken' );

        $this->runMigration();
        $this->runMigration();

        $this->assertSame( 'Links', $this->translationOf( $this->lookups['Left'], 'nl_NL' ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    /**
     * Make `__( $msgid, 'talenttrack' )` answer `$msgstr`, standing in
     * for a `.mo` this test install has no guarantee of shipping.
     */
    private function pretendCatalogueSays( string $msgid, string $msgstr ): void {
        add_filter(
            'gettext',
            static function ( $translation, $text, $domain ) use ( $msgid, $msgstr ) {
                if ( $domain === 'talenttrack' && $text === $msgid ) return $msgstr;
                return $translation;
            },
            10,
            3
        );
    }

    private function runMigration(): void {
        $path = dirname( __DIR__, 2 ) . '/database/migrations/0247_repair_gettext_backfilled_lookup_labels.php';
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
