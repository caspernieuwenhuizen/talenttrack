<?php
namespace TT\Modules\TeamDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FitResult — value object returned by CompatibilityEngine. Carries the
 * fit score, the per-category breakdown that produced it, and a
 * human-readable rationale string for UI tooltips.
 *
 * Traceability principle (#0018): every score must be explainable. The
 * `breakdown` field surfaces the per-category contributions so a
 * tooltip can show "Technical 4.2 × 0.35 = 1.47 → 4.07 overall".
 */
final class FitResult {

    public function __construct(
        public readonly float $score,
        /** @var array<string, array{rating:float, weight:float, contribution:float}> */
        public readonly array $breakdown,
        public readonly string $rationale,
        public readonly float $sidePreferenceModifier = 0.0
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'score'                    => round( $this->score, 2 ),
            'breakdown'                => array_map(
                static fn ( $b ) => [
                    'rating'       => round( (float) $b['rating'], 2 ),
                    'weight'       => round( (float) $b['weight'], 2 ),
                    'contribution' => round( (float) $b['contribution'], 2 ),
                ],
                $this->breakdown
            ),
            'rationale'                => $this->rationale,
            'side_preference_modifier' => round( $this->sidePreferenceModifier, 2 ),
        ];
    }
}
