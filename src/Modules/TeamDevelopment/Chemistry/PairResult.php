<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PairResult (#1017 Phase 3) — the orchestrated chemistry of one player
 * pair: the 0–100 weighted score, its spec category, the per-component
 * breakdown (for explainability), and whether any component had real data.
 */
final class PairResult {

    /**
     * @param int                          $player_a_id
     * @param int                          $player_b_id
     * @param float                        $score       0–100
     * @param string                       $category    exceptional|strong|good|moderate|weak|poor
     * @param array<string, ComponentScore> $components  component key → score
     * @param list<string>                 $reasons
     * @param bool                         $has_data    any component had real inputs
     */
    public function __construct(
        public readonly int $player_a_id,
        public readonly int $player_b_id,
        public readonly float $score,
        public readonly string $category,
        public readonly array $components,
        public readonly array $reasons,
        public readonly bool $has_data
    ) {}

    /** Spec §10 category buckets. */
    public static function categoryFor( float $score ): string {
        if ( $score >= 90 ) return 'exceptional';
        if ( $score >= 80 ) return 'strong';
        if ( $score >= 70 ) return 'good';
        if ( $score >= 60 ) return 'moderate';
        if ( $score >= 50 ) return 'weak';
        return 'poor';
    }
}
