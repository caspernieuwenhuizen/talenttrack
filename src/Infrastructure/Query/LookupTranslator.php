<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

/**
 * LookupTranslator — picks the right display text for a `tt_lookups` row
 * in the current user's locale.
 *
 * Resolution chain (#0090 Phase 6 — v3.110.30):
 *   1. `tt_translations` row for `(entity_type='lookup', entity_id, field, locale)`
 *      — the canonical store, populated by migrations 0082 (JSON
 *      backfill, Phase 2) and 0086 (gettext backfill, Phase 6) and
 *      maintained by the seed-review Excel round-trip (Phase 5) +
 *      `ConfigurationPage::handle_save_lookup()`.
 *   2. `__( $lookup->name, 'talenttrack' )` — vestigial gettext path.
 *      Migration 0086 backfilled every gettext-resolvable label into
 *      `tt_translations`, so this fallback fires only when the
 *      migration hasn't run yet (mid-deploy upgrade window) or when
 *      a brand-new lookup row was just created in a session whose
 *      cache hasn't bumped through the resolver yet. Phase 8 will
 *      strip the migrated msgids from `nl_NL.po`; this fallback
 *      remains for non-migrated msgids forever.
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
            $tx = self::repo()->translate(
                TranslatableFieldRegistry::ENTITY_LOOKUP,
                $id,
                'name',
                self::currentLocale(),
                ''
            );
            if ( $tx !== '' ) return $tx;
        }

        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        return (string) __( $raw, 'talenttrack' );
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
            $tx = self::repo()->translate(
                TranslatableFieldRegistry::ENTITY_LOOKUP,
                $id,
                'description',
                self::currentLocale(),
                ''
            );
            if ( $tx !== '' ) return $tx;
        }

        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        return (string) __( $raw, 'talenttrack' );
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
     */
    private static function rowByTypeAndName( string $type, string $stored_name ): ?object {
        if ( $stored_name === '' ) return null;
        static $cache = [];
        if ( ! isset( $cache[ $type ] ) ) {
            $cache[ $type ] = [];
            foreach ( QueryHelpers::get_lookups( $type ) as $row ) {
                $cache[ $type ][ (string) $row->name ] = $row;
            }
        }
        return $cache[ $type ][ $stored_name ] ?? null;
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
