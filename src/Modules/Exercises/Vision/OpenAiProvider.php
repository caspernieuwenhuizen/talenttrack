<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OpenAiProvider (#0016 Sprint 1 stub).
 *
 * Concrete implementation lands in Sprint 4 if/when OpenAI publishes
 * an EU-resident inference offering that meets the DPIA hard
 * requirement. As of v3.110.35, OpenAI's vision endpoint is
 * US-routed only — keeping this adapter in tree as a forward-
 * compatibility hook, but `isConfigured()` will only ever return
 * true on installs that explicitly opted out of the EU residency
 * gate (not recommended for clubs with minor athletes).
 *
 * Active when `TT_VISION_PROVIDER === 'openai'` and
 * `TT_VISION_API_KEY` is set.
 */
final class OpenAiProvider extends AbstractStubProvider {

    public function key(): string {
        return 'openai';
    }

    public function label(): string {
        return __( 'OpenAI 4o (US — DPIA-incompatible for EU clubs)', 'talenttrack' );
    }
}
