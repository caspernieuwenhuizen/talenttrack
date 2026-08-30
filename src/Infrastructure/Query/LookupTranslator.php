<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

/**
 * LookupTranslator — picks the right display text for a `tt_lookups` row
 * in the current user's locale.
 *
 * Resolution chain (#0090 Phase 6 — v3.110.30; gettext step retired in
 * #3082):
 *   1. `tt_translations` row for `(entity_type='lookup', entity_id, field, locale)`
 *      — the canonical store, populated by migrations 0082 (JSON
 *      backfill, Phase 2) and 0086 (gettext backfill, Phase 6) and
 *      maintained by the seed-review Excel round-trip (Phase 5) +
 *      `ConfigurationPage::handle_save_lookup()`.
 *   2. The canonical `tt_lookups` column itself.
 *
 * There is no gettext step. There used to be: `__( $lookup->name,
 * 'talenttrack' )`, described by migration 0086's own docblock as
 * something Phase 6 was "preparing to drop". It was never dropped, and
 * #3082 finally does it.
 *
 * Handing a runtime value to `__()` asks the catalogue for whatever
 * msgid happens to match that string, in whatever sense it was written.
 * A lookup name is a label; a bare msgid is usually mid-sentence prose.
 * The loud failure was the `foot_option` row `Left` rendering as
 * *Vertrokken* ("departed") on Dutch installs (#3031), because the only
 * `msgid "Left"` in the catalogue belonged to the media-retention
 * departure column. The quiet failures are case and part of speech:
 * `Technical` matching an adjective, `overdue` matching a lowercase
 * `te laat` inside a status pill.
 *
 * The trade made in #3082: an unseeded lookup now renders its canonical
 * English value. English that is obviously untranslated gets reported by
 * an operator; a real Dutch word meaning the wrong thing does not.
 * Curated labels belong in `LookupTranslationSeeds` and reach the
 * database through a migration — that is the one place a label is
 * reviewable from the source tree.
 *
 * The legacy `tt_lookups.translations` JSON column was dropped by
 * migration 0087 in this same ship — its contents are fully
 * preserved in `tt_translations`.
 *
 * The chain never returns empty — the canonical column on
 * `tt_lookups` is the immovable backstop.
 */
class LookupTranslator {

    private static ?TranslationsRepository $repo = null;

    /**
     * Resolve the best display name for a lookup row.
     *
     * @param object|null $lookup Row from `tt_lookups` (or null-safe).
     */
    public static function name( ?object $lookup ): string {
        if ( ! $lookup ) return '';
        $raw = (string) ( $lookup->name ?? '' );
        if ( $raw === '' ) return '';

        $id = (int) ( $lookup->id ?? 0 );
        if ( $id > 0 ) {
            // #2571-adjacent (#2568) — pass `$raw` as the fallback, not ''.
            // `translate()` falls back to the `en_US` row when the requested
            // locale has none, and migration 0131 gave EVERY lookup an
            // `en_US` row copied verbatim from `tt_lookups.name`. Asking for
            // '' therefore never returned '' — it returned the English echo,
            // which shadowed the gettext step below and made it dead code for
            // the whole table. Comparing against `$raw` tells the two apart:
            // an `en_US` row that merely echoes the canonical column carries
            // no operator intent, so the chain continues; a rebranded one
            // differs and still wins, which is why step 2 exists.
            $tx = self::repo()->translate(
                TranslatableFieldRegistry::ENTITY_LOOKUP,
                $id,
                'name',
                self::currentLocale(),
                $raw
            );
            if ( $tx !== '' && $tx !== $raw ) return $tx;
        }

        return $raw;
    }

    /**
     * Resolve the description text, same resolution chain as `name()`.
     */
    public static function description( ?object $lookup ): string {
        if ( ! $lookup ) return '';
        $raw = (string) ( $lookup->description ?? '' );
        if ( $raw === '' ) return '';

        $id = (int) ( $lookup->id ?? 0 );
        if ( $id > 0 ) {
            // #2568 — same en_US-echo guard as `name()`; see the note there.
            $tx = self::repo()->translate(
                TranslatableFieldRegistry::ENTITY_LOOKUP,
                $id,
                'description',
                self::currentLocale(),
                $raw
            );
            if ( $tx !== '' && $tx !== $raw ) return $tx;
        }

        return $raw;
    }

    /**
     * Resolve the short code a compact surface prints instead of the
     * full label — the position chips on the player form, and whatever
     * else asks for one later (#3246).
     *
     * Same chain as `name()` with one deliberate difference: this
     * returns **empty** when no abbreviation is set anywhere, rather
     * than falling through to something. The caller decides what an
     * unset code means, and for every caller so far the answer is "show
     * the translated label" — never the internal key, which is what
     * leaked `linker_middenvelder` onto the player form.
     */
    public static function abbreviation( ?object $lookup ): string {
        if ( ! $lookup ) return '';
        $raw = trim( (string) ( $lookup->abbreviation ?? '' ) );

        $id = (int) ( $lookup->id ?? 0 );
        if ( $id > 0 ) {
            // #2568 — same en_US-echo guard as `name()`; see the note there.
            $tx = self::repo()->translate(
                TranslatableFieldRegistry::ENTITY_LOOKUP,
                $id,
                'abbreviation',
                self::currentLocale(),
                $raw
            );
            if ( $tx !== '' && $tx !== $raw ) return $tx;
        }

        return $raw;
    }

    /**
     * Translate a lookup value addressed by (type, stored-name) without
     * the caller needing to fetch the row. Handy for consumers that
     * store the lookup name (e.g. `tt_players.preferred_foot = 'Right'`)
     * and want to render the translated version.
     *
     * Results are cached per-request — all consumers calling this in the
     * same page load share one `get_lookups()` query per lookup type.
     */
    public static function byTypeAndName( string $type, string $stored_name ): string {
        $row = self::rowByTypeAndName( $type, $stored_name );
        if ( $row === null ) {
            if ( $stored_name === '' ) return '';
            // Stored value doesn't match any current lookup row —
            // probably renamed. Best-effort: hand it to __() so the
            // .po can still translate seeded values.
            //
            // #3082 retired the gettext step from `name()` and
            // `description()` but deliberately left it here. Those two
            // resolve a lookup ROW, which has a `tt_translations` slot
            // and a curated seed to be right from; this branch fires
            // only when there is no row at all, so gettext is the sole
            // remaining source rather than a shortcut past a better one.
            // The wrong-sense risk is the same, but it is bounded to
            // orphaned stored values — data drift, which #2863's
            // normaliser and the `tools/` normalisation pass exist to
            // remove — instead of applying to the whole vocabulary.
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
            return (string) __( $stored_name, 'talenttrack' );
        }
        return self::name( $row );
    }

    /**
     * v3.110.210 (#844) — sibling of `byTypeAndName()` that returns the
     * description translation for the matching lookup row. Empty string
     * when the row doesn't exist or carries no description.
     */
    public static function descriptionByTypeAndName( string $type, string $stored_name ): string {
        $row = self::rowByTypeAndName( $type, $stored_name );
        if ( $row === null ) return '';
        return self::description( $row );
    }

    /**
     * Shared row lookup for `byTypeAndName()` + `descriptionByTypeAndName()`.
     * Cached per-request via the same shape `byTypeAndName()` used inline.
     *
     * #2863 — exact match first, then a normalised fallback. One column can
     * hold two casings of the same value when two writers disagree:
     * `tt_attendance.status` stores `Present` from the attendance wizard and
     * `present` from the planned-roster path, while the seeded lookup rows
     * are Title Case. Exact matching resolved the first and missed the
     * second, which fell through to `__()`, found no msgid, and printed the
     * raw key — so one column on one screen showed *Aanwezig* on some rows
     * and *present* on others.
     *
     * `LookupPill::resolveRow()` has carried this fallback since v3.71.2 for
     * the same class of mismatch. `LookupTranslator` never got one, which
     * meant which surface you happened to look at decided whether a value
     * translated. Both now answer the same way.
     *
     * This makes *reading* robust. It does not make the stored data
     * consistent — that is a migration, tracked separately.
     */
    private static function rowByTypeAndName( string $type, string $stored_name ): ?object {
        if ( $stored_name === '' ) return null;
        static $cache = [];
        static $normalised_cache = [];
        if ( ! isset( $cache[ $type ] ) ) {
            $cache[ $type ]            = [];
            $normalised_cache[ $type ] = [];
            foreach ( QueryHelpers::get_lookups( $type ) as $row ) {
                // Read once and reuse: the PHPStan baseline records exactly
                // one `$row->name` access in this file, and a second would
                // fail the gate on the count rather than on anything real.
                $name = (string) $row->name;
                $cache[ $type ][ $name ] = $row;
                $normalised_cache[ $type ][ self::normaliseName( $name ) ] = $row;
            }
        }
        if ( isset( $cache[ $type ][ $stored_name ] ) ) {
            return $cache[ $type ][ $stored_name ];
        }
        return $normalised_cache[ $type ][ self::normaliseName( $stored_name ) ] ?? null;
    }

    /**
     * #2863 — collapse the casing / separator difference between stored
     * values and lookup-row names. Same rule as
     * `LookupPill::normaliseName()`, deliberately: two normalisers that
     * disagree would reintroduce the split this fixes, one surface at a
     * time.
     */
    private static function normaliseName( string $name ): string {
        $n = strtolower( str_replace( [ '_', '-' ], ' ', $name ) );
        return trim( (string) preg_replace( '/\s+/', ' ', $n ) );
    }

    /**
     * List of WP locales that actually have a .mo installed on the site,
     * plus the site's default locale. Guaranteed to include at least
     * en_US as a canonical option even on English-only installs.
     *
     * @return string[]
     */
    public static function installedLocales(): array {
        $available = function_exists( 'get_available_languages' ) ? (array) get_available_languages() : [];
        $site      = (string) ( function_exists( 'get_locale' ) ? get_locale() : 'en_US' );
        // v3.110.191 (#798) — also include locales the PLUGIN ships .po
        // files for, even when WordPress hasn't activated them via
        // Settings → General → Site Language. Operators expect the
        // lookup admin to expose fields for every language the plugin
        // ships translations for; before this fix the locale set
        // collapsed to just whatever WP had explicitly installed
        // (typically one or two), hiding the rest.
        $shipped   = self::shippedLocales();
        $locales   = array_unique( array_filter( array_merge( [ 'en_US', $site ], $available, $shipped ) ) );
        sort( $locales );
        return $locales;
    }

    /**
     * v3.110.191 (#798) — locales the plugin ships `.po` files for,
     * derived by scanning `TT_PLUGIN_DIR/languages/*.po`. Cached for the
     * request lifetime so we don't restat the directory on every
     * lookup-form render. The `talenttrack.pot` template file is
     * excluded.
     *
     * @return list<string>
     */
    public static function shippedLocales(): array {
        if ( self::$shipped_locales !== null ) return self::$shipped_locales;
        $out = [];
        if ( defined( 'TT_PLUGIN_DIR' ) ) {
            $files = glob( TT_PLUGIN_DIR . 'languages/talenttrack-*.po' );
            if ( is_array( $files ) ) {
                foreach ( $files as $path ) {
                    $base = basename( $path, '.po' );
                    // basename is e.g. `talenttrack-nl_NL`; strip the prefix.
                    $locale = preg_replace( '/^talenttrack-/', '', $base );
                    if ( is_string( $locale ) && $locale !== '' ) {
                        $out[] = $locale;
                    }
                }
            }
        }
        sort( $out );
        self::$shipped_locales = array_values( array_unique( $out ) );
        return self::$shipped_locales;
    }

    /** @var list<string>|null */
    private static ?array $shipped_locales = null;

    private static function currentLocale(): string {
        if ( function_exists( 'determine_locale' ) ) return (string) determine_locale();
        if ( function_exists( 'get_locale' ) ) return (string) get_locale();
        return 'en_US';
    }

    private static function repo(): TranslationsRepository {
        if ( self::$repo === null ) self::$repo = new TranslationsRepository();
        return self::$repo;
    }
}
