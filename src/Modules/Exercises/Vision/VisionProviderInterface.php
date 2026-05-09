<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * VisionProviderInterface (#0016 Sprint 1) — extracts a structured
 * session from a photograph of a coach's paper training plan.
 *
 * Implementations call out to a vision-capable LLM (Claude Sonnet,
 * Gemini Pro, OpenAI 4o) and translate the response into the shared
 * `ExtractedSession` value object. The provider is selected at call
 * site via the `tt_vision_provider` WP filter; default routing
 * happens in `ExercisesModule::resolveProvider()`.
 *
 * Sprint 1 ships the interface + three stub adapters that throw
 * `RuntimeException` on `extractSessionFromImage()`. Sprint 4 ships
 * the actual API calls + the provider shootout that picks the
 * production default.
 *
 * Configuration via `wp-config.php` constants:
 *   define( 'TT_VISION_PROVIDER', 'claude_sonnet' );  // or 'gemini_pro', 'openai'
 *   define( 'TT_VISION_API_KEY',  '...' );
 *   define( 'TT_VISION_ENDPOINT', 'https://eu-central-1.bedrock.amazonaws.com' ); // EU residency
 *
 * EU-only inference is a hard requirement per the DPIA scope (minor
 * athletes' photos cannot leave the EU). Each provider's stub
 * documents its EU endpoint.
 */
interface VisionProviderInterface {

    /**
     * Extract a structured session description from a photograph.
     *
     * @param string $image_bytes Raw image binary (jpg/png/heic).
     * @param array<string,mixed> $context Optional hints (team_id,
     *     activity_date, language). Providers may use these to
     *     improve extraction accuracy.
     *
     * @return ExtractedSession
     *
     * @throws \RuntimeException When the provider cannot reach the
     *     remote service, the API key is missing, the response is
     *     malformed beyond recovery, or the provider has been
     *     stubbed (Sprint 1 placeholder behaviour).
     */
    public function extractSessionFromImage( string $image_bytes, array $context = [] ): ExtractedSession;

    /**
     * Stable identifier — used by the `tt_vision_provider` filter +
     * the `TT_VISION_PROVIDER` constant to route requests. One of:
     *   - 'claude_sonnet'
     *   - 'gemini_pro'
     *   - 'openai'
     */
    public function key(): string;

    /**
     * Human-readable name surfaced in admin UIs (Sprint 4 settings
     * panel). Translatable.
     */
    public function label(): string;

    /**
     * Whether this provider has the wp-config constants it needs to
     * actually run (API key + endpoint). The configured-but-not-default
     * provider can be activated at any time without a deploy.
     */
    public function isConfigured(): bool;
}
