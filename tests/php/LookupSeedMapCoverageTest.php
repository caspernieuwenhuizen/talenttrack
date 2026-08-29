<?php
namespace TT\Tests\Php;

use TT\Modules\Configuration\LookupTranslationSeeds;
use WP_UnitTestCase;

/**
 * #3117 — the curated translation seed map has to agree with the
 * vocabulary it claims to translate.
 *
 * It stopped agreeing, silently, for a long time: 68 entries matched no
 * `tt_lookups` row, so migration 0151 seeded nothing at all for 13 of
 * the 20 types it covers. Nothing surfaced, because **`INSERT IGNORE`
 * against a key that matches nothing is a no-op with no error**.
 *
 * This is a PHPUnit case rather than a `tools/` script on purpose: the
 * question is "what does a freshly migrated database hold", and only the
 * wp-env suite has one.
 *
 * Both directions are checked, and the second matters as much as the
 * first. #3082 and `0242_repair_foot_option_translations` both lean on
 * "the curated seed is the known-good value", and a map covering half a
 * type makes that claim false in a way that is worse than covering none
 * of it — the half that is missing looks curated.
 */
final class LookupSeedMapCoverageTest extends WP_UnitTestCase {

    /** @var array<string, array<string, true>> lookup_type => name => true */
    private array $live = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT lookup_type, name FROM {$wpdb->prefix}tt_lookups
              WHERE name IS NOT NULL AND name <> ''",
            ARRAY_A
        );
        foreach ( (array) $rows as $row ) {
            $this->live[ (string) $row['lookup_type'] ][ (string) $row['name'] ] = true;
        }
    }

    /**
     * Every key in the map resolves to a row. A key that does not is the
     * #3117 failure exactly: the vocabulary moved and the map did not.
     */
    public function test_every_seed_entry_resolves_to_a_live_lookup_row(): void {
        $orphans = [];

        foreach ( LookupTranslationSeeds::map() as $type => $entries ) {
            if ( isset( LookupTranslationSeeds::UNSEEDED_VOCABULARIES[ $type ] ) ) continue;
            foreach ( array_keys( $entries ) as $name ) {
                if ( ! isset( $this->live[ $type ][ (string) $name ] ) ) {
                    $orphans[] = "{$type} / {$name}";
                }
            }
        }

        $this->assertSame(
            [],
            $orphans,
            "LookupTranslationSeeds::map() carries entries that match no tt_lookups row, so migration 0248 "
            . "seeds nothing for them and INSERT IGNORE reports no error. Re-key them to tt_lookups.name, "
            . "or remove them if the vocabulary is retired:\n  " . implode( "\n  ", $orphans )
        );
    }

    /**
     * The inverse. A type listed in the map is a promise that all of its
     * rows are curated — a row without an entry gets its label from
     * wherever it happened to come from, which for fr/de/es means raw
     * English.
     */
    public function test_every_live_row_in_a_curated_type_has_a_seed_entry(): void {
        $missing = [];

        foreach ( LookupTranslationSeeds::map() as $type => $entries ) {
            $invariant = LookupTranslationSeeds::LOCALE_INVARIANT_ROWS[ $type ] ?? [];
            foreach ( array_keys( $this->live[ $type ] ?? [] ) as $name ) {
                $name = (string) $name;
                if ( isset( $entries[ $name ] ) ) continue;
                if ( in_array( $name, $invariant, true ) ) continue;
                $missing[] = "{$type} / {$name}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These lookup rows sit inside a type LookupTranslationSeeds::map() curates, but carry no entry, "
            . "so their non-Dutch labels fall through to English. Add them, or list them in "
            . "LOCALE_INVARIANT_ROWS if the value genuinely reads the same in every language:\n  "
            . implode( "\n  ", $missing )
        );
    }

    /**
     * A curated type with no rows at all is the case that needs a human:
     * either the vocabulary was retired and the entries are stale, or it
     * exists and nothing seeds it — and deleting the translations for the
     * second makes the missing rows permanently invisible. Neither is
     * something a test should guess at, so it demands an explicit entry.
     */
    public function test_a_curated_type_with_no_rows_is_declared_deliberately(): void {
        $empty = [];

        foreach ( array_keys( LookupTranslationSeeds::map() ) as $type ) {
            $type = (string) $type;
            if ( ! empty( $this->live[ $type ] ) ) continue;
            if ( isset( LookupTranslationSeeds::UNSEEDED_VOCABULARIES[ $type ] ) ) continue;
            $empty[] = $type;
        }

        $this->assertSame(
            [],
            $empty,
            "These lookup types are curated but have no rows on a freshly migrated install. Decide which "
            . "they are: a retired vocabulary (delete the entries) or a declared-but-unseeded one (file the "
            . "missing seed, and list the type in UNSEEDED_VOCABULARIES with a reason):\n  "
            . implode( "\n  ", $empty )
        );
    }
}
