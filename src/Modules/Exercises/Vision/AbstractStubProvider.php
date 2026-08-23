<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AbstractStubProvider (#0016 Sprint 1) — shared base for the three
 * Sprint-1 stub adapters (Claude Sonnet, Gemini Pro, OpenAI).
 *
 * Each concrete provider declares its key + label + EU endpoint
 * default; the actual API calls land in Sprint 4 after the
 * provider shootout picks the production default. Until then,
 * `extractSessionFromImage()` throws so consumers can register
 * the routing without accidentally pretending the flow works.
 *
 * `isConfigured()` reports whether the wp-config constants are set and
 * the active `TT_VISION_PROVIDER` matches this provider's key.
 *
 * Since #2695 that includes the data-region declaration: a provider
 * with a key but no stated destination is **not** configured. That is
 * what makes the refusal quiet and safe rather than a fatal error —
 * `resolveProvider()` returns null, and the caller falls back to manual
 * entry exactly as it would with no API key at all.
 */
abstract class AbstractStubProvider implements VisionProviderInterface {

    public function extractSessionFromImage( string $image_bytes, array $context = [] ): ExtractedSession {
        throw new \RuntimeException( sprintf(
            'Vision provider "%s" is registered but the extraction implementation lands in #0016 Sprint 4. Until then this stub throws so callers don\'t silently no-op.',
            $this->key()
        ) );
    }

    public function isConfigured(): bool {
        if ( ! defined( 'TT_VISION_PROVIDER' ) || (string) constant( 'TT_VISION_PROVIDER' ) !== $this->key() ) {
            return false;
        }
        if ( ! defined( 'TT_VISION_API_KEY' ) || (string) constant( 'TT_VISION_API_KEY' ) === '' ) {
            return false;
        }

        // #2695 — no endpoint default, and no processing until the
        // operator has stated where these photographs go. An install
        // that merely switched the feature on is not configured.
        return VisionDataRegion::isDeclared();
    }
}
