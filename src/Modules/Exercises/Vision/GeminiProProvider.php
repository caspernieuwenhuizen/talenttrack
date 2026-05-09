<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GeminiProProvider (#0016 Sprint 1 stub).
 *
 * Concrete implementation lands in Sprint 4 after the shootout.
 * Production endpoint default: Vertex AI `europe-west` for EU
 * data residency.
 *
 * Active when `TT_VISION_PROVIDER === 'gemini_pro'` and
 * `TT_VISION_API_KEY` is set.
 */
final class GeminiProProvider extends AbstractStubProvider {

    public function key(): string {
        return 'gemini_pro';
    }

    public function label(): string {
        return __( 'Gemini Pro (via Vertex AI, EU-West)', 'talenttrack' );
    }
}
