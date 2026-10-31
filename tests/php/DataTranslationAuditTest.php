<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\I18n\DataTranslationAudit;

/**
 * #4511 — data that a Dutch screen showed in English because the Dutch
 * was never stored.
 *
 * The suite's database is a freshly migrated install, which is exactly the
 * question the report asks: after every seed migration and 0306, does a
 * shipped vocabulary have Dutch for every row?
 */
final class DataTranslationAuditTest extends WP_UnitTestCase {

    private string $p = '';

    /** @var list<int> */
    private array $rows = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( $this->rows as $id ) {
            $wpdb->delete( "{$this->p}tt_translations", [ 'entity_type' => 'lookup', 'entity_id' => $id ] );
            $wpdb->delete( "{$this->p}tt_lookups", [ 'id' => $id ] );
        }
        remove_all_filters( 'determine_locale' );
        parent::tear_down();
    }

    private function lookup( string $type, string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_lookups", [ 'club_id' => (int) CurrentClub::id(), 'lookup_type' => $type, 'name' => $name ] );
        $id           = (int) $wpdb->insert_id;
        $this->rows[] = $id;
        return $id;
    }

    private function dutchOf( int $id ): ?string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT value FROM {$this->p}tt_translations
              WHERE entity_type = 'lookup' AND entity_id = %d AND field = 'name' AND locale = 'nl_NL'",
            $id
        ) );
        return $value === null ? null : (string) $value;
    }

    private function runReseed(): void {
        $path = dirname( __DIR__, 2 ) . '/database/migrations/0306_reseed_lookup_translations_after_seeds.php';
        $this->assertFileExists( $path );
        $migration = require $path;
        $migration->up();
    }

    public function test_every_shipped_lookup_has_dutch_on_a_fresh_install(): void {
        $shipped = array_filter(
            DataTranslationAudit::lookupGaps( 'nl_NL' ),
            static fn ( array $g ): bool => $g['origin'] === DataTranslationAudit::ORIGIN_SHIPPED
        );

        $this->assertSame(
            [],
            array_values( array_map( static fn ( array $g ): string => $g['lookup_type'] . ' / ' . $g['name'], $shipped ) ),
            'Shipped lookup rows a Dutch install shows in English. Add their Dutch to LookupTranslationSeeds::map().'
        );
    }

    public function test_a_club_typed_value_is_not_reported(): void {
        $this->lookup( 'position', 'Libero van de club' );

        $names = array_column( DataTranslationAudit::lookupGaps( 'nl_NL' ), 'name' );

        $this->assertNotContains( 'Libero van de club', $names, 'a value the academy typed is its own' );
    }

    public function test_a_locale_invariant_row_is_not_a_gap(): void {
        $names = array_column( DataTranslationAudit::lookupGaps( 'nl_NL' ), 'name' );

        $this->assertNotContains( 'UEFA-B', $names );
    }

    public function test_the_reseed_fills_a_missing_label_and_a_second_run_changes_nothing(): void {
        $gk = $this->lookup( 'position', 'GK' );
        $this->assertNull( $this->dutchOf( $gk ), 'fixture: a new row starts without Dutch' );

        $this->runReseed();
        $this->assertSame( 'Keeper', $this->dutchOf( $gk ) );

        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_translations" );
        $this->runReseed();
        $this->assertSame( $count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_translations" ), 'idempotent' );
    }

    public function test_the_reseed_leaves_a_club_edited_label_alone(): void {
        global $wpdb;
        $gk = $this->lookup( 'position', 'GK' );
        $wpdb->insert( "{$this->p}tt_translations", [
            'club_id'     => (int) CurrentClub::id(),
            'entity_type' => 'lookup',
            'entity_id'   => $gk,
            'field'       => 'name',
            'locale'      => 'nl_NL',
            'value'       => 'Doelman',
            'updated_at'  => current_time( 'mysql', true ),
        ] );

        $this->runReseed();

        $this->assertSame( 'Doelman', $this->dutchOf( $gk ) );
    }

    public function test_a_position_reads_dutch_on_a_dutch_install(): void {
        $gk = $this->lookup( 'position', 'GK' );
        $this->runReseed();
        add_filter( 'determine_locale', static fn (): string => 'nl_NL' );

        $this->assertSame( 'Keeper', LookupTranslator::name( (object) [ 'id' => $gk, 'name' => 'GK' ] ) );
    }

    public function test_the_shipped_config_default_is_reported_while_it_is_unchanged(): void {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->replace( "{$wpdb->prefix}tt_config", [ 'club_id' => $club, 'config_key' => 'academy_name', 'config_value' => 'Soccer Academy' ] );
        $this->assertContains( 'academy_name', array_column( DataTranslationAudit::configGaps(), 'key' ) );

        $wpdb->replace( "{$wpdb->prefix}tt_config", [ 'club_id' => $club, 'config_key' => 'academy_name', 'config_value' => 'Jeugdacademie Noord' ] );
        $this->assertNotContains( 'academy_name', array_column( DataTranslationAudit::configGaps(), 'key' ) );
    }

    public function test_no_demo_generator_writes_english_without_a_dutch_variant(): void {
        $this->assertSame( [], DataTranslationAudit::demoGaps( dirname( __DIR__, 2 ) ) );
        $this->assertTrue(
            DataTranslationAudit::hasEnglishSentence( "<?php \$a = 'Took the ball on the half-turn under pressure';" ),
            'the detector recognises an English sentence'
        );
    }

    public function test_the_report_names_every_source(): void {
        $report = DataTranslationAudit::report( 'nl_NL', dirname( __DIR__, 2 ) );

        foreach ( [ 'shipped vocabulary', 'canonical list does not know', 'tt_config', 'Demo generators' ] as $section ) {
            $this->assertStringContainsString( $section, $report );
        }
    }
}
