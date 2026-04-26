<?php
namespace TT\Modules\Translations\Engines;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TranslationEngineInterface — the contract a backend translation
 * provider implements (#0025).
 *
 * Implementations:
 *   - DeepLEngine (primary)
 *   - GoogleTranslateEngine (fallback)
 *
 * Adding a new engine: implement this interface, register it in
 * `TranslationLayer::engineFactory()`, and the rest of the layer
 * stays untouched.
 *
 * All methods may throw TranslationEngineException on transport /
 * auth / quota errors. The layer catches those, falls through to
 * the configured fallback engine, and ultimately returns the source
 * string unchanged when both engines fail.
 */
interface TranslationEngineInterface {

    /**
     * Translate `$source` from `$source_lang` to `$target_lang`.
     * Both language codes are short ISO codes (`nl`, `en`, `fr`,
     * `de`, `es`, …). The engine adapter is responsible for
     * upper-casing or capability-mapping if its API requires it.
     *
     * @throws TranslationEngineException
     */
    public function translate( string $source, string $source_lang, string $target_lang ): string;

    /**
     * Detect the language of `$source`. Return shape:
     *
     *   [ 'lang' => 'nl', 'confidence' => 0.92 ]
     *
     * Confidence is 0..1. The layer falls through to the site-locale
     * default when confidence is below 0.6.
     *
     * @return array{lang:string, confidence:float}
     * @throws TranslationEngineException
     */
    public function detect( string $source ): array;

    /**
     * Indicative price per 1,000 characters in EUR. Used by the
     * Configuration tab to surface an estimated monthly cost given
     * the configured cap. Approximate is fine.
     */
    public function pricePer1000Chars(): float;

    /**
     * Stable machine name. Used as the `engine` value in cache keys
     * and usage counters — change only with a migration.
     */
    public function name(): string;
}
