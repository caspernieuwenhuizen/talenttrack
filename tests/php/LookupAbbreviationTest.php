<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

/**
 * #3246 — the short code an academy prints instead of a full label.
 *
 * Before this, "GK" was `tt_lookups.name` — the internal key. It read
 * like a football code only because the eleven seeded positions happen
 * to have code-shaped keys; a position an academy added itself printed
 * `linker_middenvelder` on the player form.
 *
 * The three things worth pinning: an unset abbreviation resolves to
 * empty (so callers fall back to the label, never the key), a per-locale
 * value beats the canonical column, and none of it touches the value the
 * rest of the system joins on.
 */
final class LookupAbbreviationTest extends WP_UnitTestCase {

    private const TYPE = 'position';

    private int $with_code = 0;
    private int $without_code = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        TranslatableFieldRegistry::register(
            TranslatableFieldRegistry::ENTITY_LOOKUP,
            [ 'name', 'description', 'abbreviation' ]
        );

        $wpdb->insert( $wpdb->prefix . 'tt_lookups', [
            'club_id'      => (int) CurrentClub::id(),
            'lookup_type'  => self::TYPE,
            'name'         => 'GK',
            'abbreviation' => 'GK',
            'sort_order'   => 1,
        ] );
        $this->with_code = (int) $wpdb->insert_id;

        // The shape the pilot install hit: an operator-added position
        // whose key is a snake_case phrase and which has no short code.
        $wpdb->insert( $wpdb->prefix . 'tt_lookups', [
            'club_id'     => (int) CurrentClub::id(),
            'lookup_type' => self::TYPE,
            'name'        => 'linker_middenvelder',
            'sort_order'  => 2,
        ] );
        $this->without_code = (int) $wpdb->insert_id;
    }

    private function row( int $id ): ?object {
        return QueryHelpers::get_lookup( $id );
    }

    public function test_canonical_column_is_returned_when_no_translation_exists(): void {
        $this->assertSame( 'GK', LookupTranslator::abbreviation( $this->row( $this->with_code ) ) );
    }

    /**
     * The whole point of the field being translatable: a Dutch install
     * says K where an English one says GK, without either of them
     * touching the key.
     */
    public function test_locale_translation_wins_over_the_column(): void {
        ( new TranslationsRepository() )->upsert(
            TranslatableFieldRegistry::ENTITY_LOOKUP,
            $this->with_code,
            'abbreviation',
            'nl_NL',
            'K',
            1
        );

        $filter = static fn(): string => 'nl_NL';
        add_filter( 'locale', $filter );
        $resolved = LookupTranslator::abbreviation( $this->row( $this->with_code ) );
        remove_filter( 'locale', $filter );

        $this->assertSame( 'K', $resolved );
    }

    /**
     * Empty, not the key. A caller that got the key here would put
     * `linker_middenvelder` back on the player form, which is the bug.
     */
    public function test_unset_abbreviation_resolves_to_empty(): void {
        $this->assertSame( '', LookupTranslator::abbreviation( $this->row( $this->without_code ) ) );
    }

    /**
     * `get_lookup_abbrev_pairs()` omits a row with no code rather than
     * mapping it to '', so `$codes[$k] ?? $labels[$k]` is the whole
     * fallback a caller has to write.
     */
    public function test_pairs_omit_rows_without_a_code(): void {
        $pairs = QueryHelpers::get_lookup_abbrev_pairs( self::TYPE );

        $this->assertSame( 'GK', $pairs['GK'] ?? null );
        $this->assertArrayNotHasKey( 'linker_middenvelder', $pairs );
    }

    /**
     * Display only. Every consumer that matches on a position — chemistry
     * buckets, formation slots, the tournament squad step — keys on the
     * stored name, and this field must never enter that list.
     */
    public function test_abbreviation_does_not_change_the_stored_values(): void {
        $names = QueryHelpers::get_lookup_names( self::TYPE );

        $this->assertContains( 'GK', $names );
        $this->assertContains( 'linker_middenvelder', $names );
    }
}
