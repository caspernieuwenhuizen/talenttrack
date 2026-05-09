<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ClaudeSonnetProvider (#0016 Sprint 1 stub).
 *
 * Concrete implementation lands in Sprint 4 after the shootout.
 * Production endpoint default: AWS Bedrock `eu-central-1` for EU
 * data residency (DPIA hard requirement — minor athletes' data
 * cannot leave the EU).
 *
 * Active when `TT_VISION_PROVIDER === 'claude_sonnet'` and
 * `TT_VISION_API_KEY` is set. Optional `TT_VISION_ENDPOINT`
 * overrides the default Bedrock endpoint.
 */
final class ClaudeSonnetProvider extends AbstractStubProvider {

    public function key(): string {
        return 'claude_sonnet';
    }

    public function label(): string {
        return __( 'Claude Sonnet (via Bedrock, EU-Central)', 'talenttrack' );
    }
}
