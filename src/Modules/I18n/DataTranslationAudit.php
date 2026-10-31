<?php
namespace TT\Modules\I18n;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Configuration\LookupCanonicalSeeds;
use TT\Modules\Configuration\LookupTranslationSeeds;

/**
 * DataTranslationAudit (#4511) — text that lives in data rather than code,
 * and that a Dutch screen shows in English because the Dutch was never
 * stored. Read-only: it lists, it writes nothing.
 *
 * Three sources:
 *
 *   - lookup rows with no translation row for the locale. Only rows of a
 *     shipped vocabulary count as a gap (`LookupCanonicalSeeds`); a value
 *     an academy typed itself is its own business, and a type the
 *     canonical list does not know is listed separately because shipped
 *     and typed cannot be told apart there;
 *   - `tt_config` text values still at the English default the installer
 *     writes;
 *   - demo generators that write English copy with no Dutch variant.
 *
 * Reached through `wp tt i18n-data` and `tools/check-data-i18n.php`.
 */
final class DataTranslationAudit {

    public const ORIGIN_SHIPPED      = 'shipped';
    public const ORIGIN_UNCATALOGUED = 'uncatalogued';

    /**
     * Text defaults the installer writes into `tt_config` (`Activator`),
     * in English, that an academy reads back on its own screens.
     *
     * @var array<string, string> config_key => shipped English value
     */
    public const SHIPPED_TEXT_DEFAULTS = [
        'academy_name' => 'Soccer Academy',
    ];

    /**
     * Lookup rows of this club with no non-empty translation for $locale.
     * Rows a club typed itself are left out; rows declared locale-invariant
     * (`LookupTranslationSeeds::LOCALE_INVARIANT_ROWS`) are not gaps.
     *
     * @return list<array{id:int, lookup_type:string, name:string, origin:string}>
     */
    public static function lookupGaps( string $locale = 'nl_NL' ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.id, l.lookup_type, l.name
               FROM {$p}tt_lookups l
               LEFT JOIN {$p}tt_translations t
                      ON t.entity_type = 'lookup'
                     AND t.entity_id = l.id
                     AND t.field = 'name'
                     AND t.locale = %s
                     AND t.value <> ''
              WHERE l.club_id = %d
                AND l.name IS NOT NULL AND l.name <> ''
                AND t.id IS NULL
              ORDER BY l.lookup_type, l.sort_order, l.id",
            $locale,
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $type = (string) $row->lookup_type;
            $name = (string) $row->name;
            if ( in_array( $name, LookupTranslationSeeds::LOCALE_INVARIANT_ROWS[ $type ] ?? [], true ) ) continue;

            $canonical = LookupCanonicalSeeds::canonicalFor( $type );
            if ( $canonical !== [] && ! in_array( $name, $canonical, true ) ) continue;

            $out[] = [
                'id'          => (int) $row->id,
                'lookup_type' => $type,
                'name'        => $name,
                'origin'      => $canonical === [] ? self::ORIGIN_UNCATALOGUED : self::ORIGIN_SHIPPED,
            ];
        }
        return $out;
    }

    /**
     * `tt_config` text values of this club that still read as the
     * installer's English default.
     *
     * @return list<array{key:string, value:string}>
     */
    public static function configGaps(): array {
        global $wpdb;
        $out = [];
        foreach ( self::SHIPPED_TEXT_DEFAULTS as $key => $english ) {
            $value = $wpdb->get_var( $wpdb->prepare(
                "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE club_id = %d AND config_key = %s",
                CurrentClub::id(),
                $key
            ) );
            if ( $value !== null && (string) $value === $english ) {
                $out[] = [ 'key' => $key, 'value' => (string) $value ];
            }
        }
        return $out;
    }

    /**
     * Demo generators whose source writes English sentences and carries
     * no Dutch variant. A generator with both reads the language the demo
     * operator picked, so its English copy is the en_US half.
     *
     * @return list<string> paths relative to the plugin root
     */
    public static function demoGaps( string $plugin_dir ): array {
        $dir = rtrim( $plugin_dir, '/\\' ) . '/src/Modules/DemoData/Generators';
        $out = [];
        foreach ( glob( $dir . '/*.php' ) ?: [] as $file ) {
            $source = (string) file_get_contents( $file );
            if ( strpos( $source, "'nl_NL'" ) !== false ) continue;
            if ( self::hasEnglishSentence( $source ) ) {
                $out[] = 'src/Modules/DemoData/Generators/' . basename( $file );
            }
        }
        return $out;
    }

    /** Whether a PHP source carries a string literal that reads as an English sentence. */
    public static function hasEnglishSentence( string $source ): bool {
        $function_words = [ 'the', 'and', 'of', 'your', 'will', 'with', 'has', 'is', 'to', 'for', 'on', 'in', 'was', 'not', 'this', 'that' ];
        foreach ( token_get_all( $source ) as $token ) {
            if ( ! is_array( $token ) || $token[0] !== T_CONSTANT_ENCAPSED_STRING ) continue;
            $words = preg_split( '/[^a-z]+/', strtolower( substr( $token[1], 1, -1 ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
            if ( count( $words ) >= 4 && count( array_intersect( array_unique( $words ), $function_words ) ) >= 2 ) {
                return true;
            }
        }
        return false;
    }

    /** The full report as text, one list per source. */
    public static function report( string $locale, string $plugin_dir ): string {
        $lookups = self::lookupGaps( $locale );
        $shipped = array_values( array_filter( $lookups, static fn ( array $g ): bool => $g['origin'] === self::ORIGIN_SHIPPED ) );
        $other   = array_values( array_filter( $lookups, static fn ( array $g ): bool => $g['origin'] === self::ORIGIN_UNCATALOGUED ) );
        $config  = self::configGaps();
        $demo    = self::demoGaps( $plugin_dir );

        $lines   = [];
        $lines[] = 'Lookups of a shipped vocabulary with no ' . $locale . ' label: ' . count( $shipped );
        foreach ( $shipped as $g ) {
            $lines[] = sprintf( '  #%d  %s / %s', $g['id'], $g['lookup_type'], $g['name'] );
        }
        $lines[] = 'Lookups of a type the canonical list does not know, with no ' . $locale . ' label: ' . count( $other );
        foreach ( $other as $g ) {
            $lines[] = sprintf( '  #%d  %s / %s', $g['id'], $g['lookup_type'], $g['name'] );
        }
        $lines[] = 'tt_config text still at the shipped English default: ' . count( $config );
        foreach ( $config as $c ) {
            $lines[] = sprintf( '  %s = "%s"', $c['key'], $c['value'] );
        }
        $lines[] = 'Demo generators writing English with no Dutch variant: ' . count( $demo );
        foreach ( $demo as $file ) {
            $lines[] = '  ' . $file;
        }
        return implode( "\n", $lines ) . "\n";
    }
}
