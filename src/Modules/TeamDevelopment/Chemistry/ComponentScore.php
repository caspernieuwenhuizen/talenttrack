<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ComponentScore (#1912 Phase 2) — one sub-engine's result: a 0–100 value
 * plus short human reasons (for the explainability panel) and a `has_data`
 * flag distinguishing a real score from a neutral fallback (so the
 * orchestrator and UI can be honest about un-populated inputs).
 */
final class ComponentScore {

    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly float $value,    // 0–100
        public readonly bool $has_data,
        public readonly array $reasons = []
    ) {}

    /** Neutral 50 when the inputs to this component aren't available yet. */
    public static function neutral( string $reason = '' ): self {
        return new self( 50.0, false, $reason !== '' ? [ $reason ] : [] );
    }

    public static function clamp( float $value, bool $has_data, array $reasons = [] ): self {
        return new self( max( 0.0, min( 100.0, $value ) ), $has_data, $reasons );
    }
}
