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
 * `isConfigured()` reports whether the wp-config constants
 * (`TT_VISION_API_KEY`, `TT_VISION_ENDPOINT`) are set + the active
 * `TT_VISION_PROVIDER` matches this provider's key. Sprint 4
 * promotes the shootout winner to the default; clubs override
 * via the constant.
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
        // Endpoint defaults are documented per provider; explicit
        // override via TT_VISION_ENDPOINT supersedes the default.
        return true;
    }
}
