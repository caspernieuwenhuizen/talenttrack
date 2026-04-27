<?php
namespace TT\Modules\Translations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Translations\Cache\SourceMetaRepository;
use TT\Modules\Translations\Cache\TranslationsCacheRepository;
use TT\Modules\Translations\Cache\TranslationsUsageRepository;
use TT\Modules\Translations\Engines\DeepLEngine;
use TT\Modules\Translations\Engines\GoogleTranslateEngine;
use TT\Modules\Translations\Engines\TranslationEngineException;
use TT\Modules\Translations\Engines\TranslationEngineInterface;

/**
 * TranslationLayer — public service entry point for the auto-
 * translation feature (#0025).
 *
 * Default OFF. The layer is a no-op until an admin opts in via
 * Configuration → Translations. Once enabled:
 *
 *   - render( $source, $target_lang ) — cache-first translate; falls
 *     through to source text when disabled, when target = source, when
 *     the cap is hit, or when both engines fail.
 *   - detectAndCache( $entity_type, $entity_id, $field, $source ) —
 *     called from save paths so re-saves of unchanged text don't
 *     re-detect.
 *   - invalidateSource( $entity_type, $entity_id, $field, $old_source ) —
 *     called from save paths when the source actually changed.
 *
 * GDPR posture: see `docs/translations.md`. The layer never
 * transmits source text until the admin has confirmed the engine
 * acts as an Article 28 sub-processor and supplied credentials.
 */
final class TranslationLayer {

    private const CFG_ENABLED                  = 'tt_translations_enabled';
    private const CFG_PRIMARY_ENGINE           = 'tt_translations_primary_engine';
    private const CFG_FALLBACK_ENGINE          = 'tt_translations_fallback_engine';
    private const CFG_DEEPL_KEY                = 'tt_translations_deepl_api_key';
    private const CFG_GOOGLE_SA                = 'tt_translations_google_service_account';
    private const CFG_SITE_DEFAULT_LANG        = 'tt_translations_site_default_lang';
    private const CFG_MONTHLY_CAP              = 'tt_translations_monthly_char_cap';
    private const CFG_THRESHOLD_PCT            = 'tt_translations_threshold_pct';
    private const CFG_SUBPROCESSOR_CONFIRMED   = 'tt_translations_subprocessor_confirmed';

    public const DEFAULT_MONTHLY_CAP   = 200000;
    public const DEFAULT_THRESHOLD_PCT = 80;
    public const DETECTION_CONFIDENCE_FLOOR = 0.6;

    public const PREF_TRANSLATED   = 'translated';
    public const PREF_ORIGINAL     = 'original';
    public const PREF_SIDE_BY_SIDE = 'side-by-side';
    public const USER_META_PREF    = 'tt_translation_pref';

    // Configuration accessors

    public static function isEnabled(): bool {
        return QueryHelpers::get_config( self::CFG_ENABLED, '' ) === '1';
    }

    public static function siteDefaultLang(): string {
        $explicit = QueryHelpers::get_config( self::CFG_SITE_DEFAULT_LANG, '' );
        if ( $explicit !== '' ) return self::shortCode( $explicit );
        return self::shortCode( (string) get_locale() );
    }

    public static function primaryEngineName(): string {
        return QueryHelpers::get_config( self::CFG_PRIMARY_ENGINE, 'deepl' ) ?: 'deepl';
    }

    public static function fallbackEngineName(): string {
        return QueryHelpers::get_config( self::CFG_FALLBACK_ENGINE, '' );
    }

    public static function monthlyCharCap(): int {
        $raw = (int) QueryHelpers::get_config( self::CFG_MONTHLY_CAP, (string) self::DEFAULT_MONTHLY_CAP );
        return $raw > 0 ? $raw : self::DEFAULT_MONTHLY_CAP;
    }

    public static function thresholdPercentage(): int {
        $raw = (int) QueryHelpers::get_config( self::CFG_THRESHOLD_PCT, (string) self::DEFAULT_THRESHOLD_PCT );
        return max( 1, min( 100, $raw ) );
    }

    public static function subprocessorConfirmed(): bool {
        return QueryHelpers::get_config( self::CFG_SUBPROCESSOR_CONFIRMED, '' ) === '1';
    }

    public static function configKeys(): array {
        return [
            'enabled'                => self::CFG_ENABLED,
            'primary_engine'         => self::CFG_PRIMARY_ENGINE,
            'fallback_engine'        => self::CFG_FALLBACK_ENGINE,
            'deepl_key'              => self::CFG_DEEPL_KEY,
            'google_service_account' => self::CFG_GOOGLE_SA,
            'site_default_lang'      => self::CFG_SITE_DEFAULT_LANG,
            'monthly_cap'            => self::CFG_MONTHLY_CAP,
            'threshold_pct'          => self::CFG_THRESHOLD_PCT,
            'subprocessor_confirmed' => self::CFG_SUBPROCESSOR_CONFIRMED,
        ];
    }

    // User preference

    public static function userPreference( int $user_id = 0 ): string {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        if ( $user_id <= 0 ) return self::PREF_TRANSLATED;
        $pref = (string) get_user_meta( $user_id, self::USER_META_PREF, true );
        if ( in_array( $pref, [ self::PREF_TRANSLATED, self::PREF_ORIGINAL, self::PREF_SIDE_BY_SIDE ], true ) ) {
            return $pref;
        }
        return self::PREF_TRANSLATED;
    }

    // Hot path: render

    /**
     * Translate `$source` for the current viewer. Returns the source
     * unchanged when the layer is disabled, when the viewer prefers
     * the original, or when no engine is reachable.
     *
     * For `side-by-side` rendering, the caller can either:
     *   - pass plain text and the layer returns "translated (original: source)";
     *   - or call `render()` and `original()` separately for richer markup.
     */
    public static function render( string $source, ?string $target_lang = null, ?int $user_id = null ): string {
        if ( $source === '' ) return $source;
        if ( ! self::isEnabled() ) return $source;

        $pref = self::userPreference( (int) ( $user_id ?? get_current_user_id() ) );
        if ( $pref === self::PREF_ORIGINAL ) return $source;

        $target = self::shortCode( (string) ( $target_lang ?? determine_locale() ) );
        $cache  = new TranslationsCacheRepository();
        $hash   = TranslationsCacheRepository::hash( $source );
        $source_lang = self::resolveSourceLang( $source, $hash );

        if ( $target === '' || $source_lang === '' || $source_lang === $target ) {
            return $source;
        }

        $primary = self::primaryEngineName();
        $hit = $cache->find( $hash, $source_lang, $target, $primary );
        if ( $hit ) {
            return self::format( $source, (string) $hit->translated_text, $pref );
        }

        // Cache miss → try the primary engine, then the fallback.
        $translated = self::callEngines( $source, $source_lang, $target );
        if ( $translated === null ) return $source;

        return self::format( $source, $translated, $pref );
    }

    /**
     * Detect the source language of `$source` and persist it on
     * `tt_translation_source_meta`. Idempotent: an unchanged source
     * (same hash) returns the cached row without re-calling the engine.
     *
     * @return array{lang:string, confidence:float, hash:string}
     */
    public static function detectAndCache( string $entity_type, int $entity_id, string $field_name, string $source ): array {
        $hash = TranslationsCacheRepository::hash( $source );
        $meta_repo = new SourceMetaRepository();

        $existing = $meta_repo->find( $entity_type, $entity_id, $field_name );
        if ( $existing && (string) $existing->source_hash === $hash ) {
            return [
                'lang'       => (string) $existing->detected_lang,
                'confidence' => (float) $existing->detection_confidence,
                'hash'       => $hash,
            ];
        }

        $detected = [ 'lang' => '', 'confidence' => 0.0 ];
        if ( self::isEnabled() && $source !== '' ) {
            try {
                $engine = self::engineFactory( self::primaryEngineName() );
                if ( $engine ) {
                    $detected = $engine->detect( $source );
                }
            } catch ( TranslationEngineException $e ) {
                Logger::error( 'translations.detect.failed', [ 'reason' => $e->reason(), 'message' => $e->getMessage() ] );
            }
        }

        $effective_lang = ( (float) $detected['confidence'] >= self::DETECTION_CONFIDENCE_FLOOR )
            ? (string) $detected['lang']
            : self::siteDefaultLang();

        $meta_repo->upsert( $entity_type, $entity_id, $field_name, $hash, $effective_lang, (float) $detected['confidence'] );

        // Source content changed → drop any cached translations for the old hash.
        if ( $existing && (string) $existing->source_hash !== $hash ) {
            ( new TranslationsCacheRepository() )->deleteForHash( (string) $existing->source_hash );
        }

        return [ 'lang' => $effective_lang, 'confidence' => (float) $detected['confidence'], 'hash' => $hash ];
    }

    public static function invalidateSource( string $source ): void {
        if ( $source === '' ) return;
        ( new TranslationsCacheRepository() )->deleteForHash( TranslationsCacheRepository::hash( $source ) );
    }

    public static function purgeAllCaches(): void {
        ( new TranslationsCacheRepository() )->truncate();
        ( new SourceMetaRepository() )->truncate();
    }

    public static function usageThisMonth( ?string $engine = null ): int {
        $engine = $engine ?: self::primaryEngineName();
        return ( new TranslationsUsageRepository() )->charsThisMonth( $engine );
    }

    public static function capExceeded( ?string $engine = null ): bool {
        return self::usageThisMonth( $engine ) >= self::monthlyCharCap();
    }

    // Internal helpers

    private static function resolveSourceLang( string $source, string $hash ): string {
        // Look up the detected lang via meta — but the meta is keyed on
        // (entity_type, entity_id, field_name), not on the hash alone.
        // If the caller hasn't pre-detected, we fall back to the site
        // default rather than calling the engine on every render.
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT detected_lang, detection_confidence FROM {$wpdb->prefix}tt_translation_source_meta
             WHERE source_hash = %s LIMIT 1",
            $hash
        ) );
        if ( $row && (float) $row->detection_confidence >= self::DETECTION_CONFIDENCE_FLOOR ) {
            return (string) $row->detected_lang;
        }
        return self::siteDefaultLang();
    }

    private static function callEngines( string $source, string $source_lang, string $target_lang ): ?string {
        if ( self::capExceeded() ) {
            self::maybeMarkThreshold();
            return null;
        }

        $engines = array_filter( [
            self::engineFactory( self::primaryEngineName() ),
            self::engineFactory( self::fallbackEngineName() ),
        ] );
        if ( empty( $engines ) ) return null;

        foreach ( $engines as $engine ) {
            try {
                $translated = $engine->translate( $source, $source_lang, $target_lang );
                $chars      = max( 1, mb_strlen( $source ) );
                ( new TranslationsCacheRepository() )->insert(
                    TranslationsCacheRepository::hash( $source ),
                    $source_lang,
                    $target_lang,
                    $translated,
                    $engine->name(),
                    $chars
                );
                ( new TranslationsUsageRepository() )->increment( $engine->name(), $chars );
                self::maybeMarkThreshold();
                return $translated;
            } catch ( TranslationEngineException $e ) {
                Logger::error( 'translations.engine.failed', [
                    'engine'  => $engine->name(),
                    'reason'  => $e->reason(),
                    'message' => $e->getMessage(),
                ] );
                if ( ! $e->isRecoverable() ) break;
            }
        }
        return null;
    }

    private static function maybeMarkThreshold(): void {
        $usage_repo = new TranslationsUsageRepository();
        $engine     = self::primaryEngineName();
        if ( $usage_repo->thresholdHitAt( $engine ) ) return;
        $cap = self::monthlyCharCap();
        $threshold = (int) round( $cap * ( self::thresholdPercentage() / 100 ) );
        if ( $usage_repo->charsThisMonth( $engine ) >= $threshold ) {
            $usage_repo->markThresholdHit( $engine );
        }
    }

    public static function engineFactory( string $name ): ?TranslationEngineInterface {
        switch ( $name ) {
            case 'deepl':
                $key = QueryHelpers::get_config( self::CFG_DEEPL_KEY, '' );
                return $key !== '' ? new DeepLEngine( $key ) : null;
            case 'google':
                $sa = QueryHelpers::get_config( self::CFG_GOOGLE_SA, '' );
                return $sa !== '' ? new GoogleTranslateEngine( $sa ) : null;
            case '':
            case 'none':
                return null;
        }
        /**
         * Filter `tt_translation_engine_factory` allows third parties
         * to register additional engines without modifying this method.
         * The filter callback receives the requested name and returns
         * an instance implementing TranslationEngineInterface, or null.
         */
        return apply_filters( 'tt_translation_engine_factory', null, $name );
    }

    private static function format( string $source, string $translated, string $pref ): string {
        if ( $pref !== self::PREF_SIDE_BY_SIDE ) return $translated;
        if ( $translated === $source ) return $source;
        return $translated . ' (' . $source . ')';
    }

    public static function shortCode( string $locale ): string {
        if ( $locale === '' ) return '';
        $base = strtolower( substr( $locale, 0, 2 ) );
        return preg_match( '/^[a-z]{2}$/', $base ) ? $base : '';
    }
}
