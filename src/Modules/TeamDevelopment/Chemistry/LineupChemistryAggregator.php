<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Repositories\ChemistryPositionMatrixRepository;

/**
 * LineupChemistryAggregator (#1017 Phase 4) — rolls the Phase-3 pair scores
 * for a lineup up into Unit and Lineup chemistry (spec §7/§8).
 *
 * Every filled-slot pair is scored (spec's all-pairs model), then weighted
 * by the configurable Position Relationship Matrix (resolved at the
 * gk/def/mid/att line-group level the default matrix is seeded for):
 *
 *   - Lineup chemistry = matrix-weighted average of all pair scores (0–100).
 *   - Unit chemistry   = plain average of the pairs whose two slots sit in
 *                        the same line group (0–100), per gk/def/mid/att.
 *
 * Pure of HTTP; the loader does the DB work. The old BlueprintChemistryEngine
 * is untouched — Phase 4 only surfaces this v2 result behind a toggle.
 */
final class LineupChemistryAggregator {

    private ChemistryProfileLoader $loader;
    private PairChemistryEngine $engine;
    private ChemistryPositionMatrixRepository $matrix;

    public function __construct(
        ?ChemistryProfileLoader $loader = null,
        ?PairChemistryEngine $engine = null,
        ?ChemistryPositionMatrixRepository $matrix = null
    ) {
        $this->loader = $loader ?? new ChemistryProfileLoader();
        $this->engine = $engine ?? new PairChemistryEngine();
        $this->matrix = $matrix ?? new ChemistryPositionMatrixRepository();
    }

    /**
     * @param int                       $team_id
     * @param list<array<string,mixed>> $slots   label + pos.y
     * @param array<string, ?int>       $lineup  slot_label → player_id|null
     *
     * @return array{
     *   lineup_score: ?int,
     *   unit_scores: array<string, ?int>,
     *   pair_count: int,
     *   scored_pair_count: int,
     *   pairs: list<array<string,mixed>>
     * }
     */
    public function aggregate( int $team_id, array $slots, array $lineup ): array {
        $slotGroup = [];   // label → gk/def/mid/att
        foreach ( $slots as $s ) {
            $label = (string) ( $s['label'] ?? '' );
            if ( $label === '' ) continue;
            $slotGroup[ $label ] = self::lineBand( (float) ( $s['pos']['y'] ?? 0.5 ) );
        }

        // Filled slots only.
        $filled = [];
        foreach ( $lineup as $label => $pid ) {
            $label = (string) $label;
            if ( isset( $slotGroup[ $label ] ) && (int) $pid > 0 ) {
                $filled[ $label ] = (int) $pid;
            }
        }
        if ( count( $filled ) < 2 ) {
            return [
                'lineup_score' => null, 'unit_scores' => [],
                'pair_count' => 0, 'scored_pair_count' => 0, 'pairs' => [],
            ];
        }

        $this->loader->load( array_values( $filled ) );

        $labels = array_keys( $filled );
        $pairs        = [];
        $weighted_sum = 0.0;
        $weight_total = 0.0;
        $scored       = 0;
        $unit_acc     = []; // group → list<float>

        $n = count( $labels );
        for ( $i = 0; $i < $n; $i++ ) {
            for ( $j = $i + 1; $j < $n; $j++ ) {
                $la = $labels[ $i ];
                $lb = $labels[ $j ];
                $pa = $filled[ $la ];
                $pb = $filled[ $lb ];

                $result = $this->engine->scorePair(
                    $this->loader->profile( $pa ),
                    $this->loader->profile( $pb ),
                    $this->loader->pairContext( $pa, $pb )
                );

                $ga = $slotGroup[ $la ];
                $gb = $slotGroup[ $lb ];
                $weight = $this->matrix->weightFor( $ga, $gb );
                if ( $weight === null ) $weight = $ga === $gb ? 1.0 : 0.5;

                $weighted_sum += $weight * $result->score;
                $weight_total += $weight;
                $scored++;

                if ( $ga === $gb ) {
                    $unit_acc[ $ga ][] = $result->score;
                }

                $pairs[] = [
                    'a_slot'      => $la,
                    'b_slot'      => $lb,
                    'a_player_id' => $pa,
                    'b_player_id' => $pb,
                    'score'       => $result->score,
                    'category'    => $result->category,
                    'has_data'    => $result->has_data,
                    'reasons'     => $result->reasons,
                ];
            }
        }

        $lineup_score = $weight_total > 0 ? (int) round( $weighted_sum / $weight_total ) : null;

        $unit_scores = [];
        foreach ( [ 'gk', 'def', 'mid', 'att' ] as $g ) {
            $vals = $unit_acc[ $g ] ?? [];
            $unit_scores[ $g ] = empty( $vals ) ? null : (int) round( array_sum( $vals ) / count( $vals ) );
        }

        return [
            'lineup_score'      => $lineup_score,
            'unit_scores'       => $unit_scores,
            'pair_count'        => count( $pairs ),
            'scored_pair_count' => $scored,
            'pairs'             => $pairs,
        ];
    }

    /** Slot.y → line group, mirroring the seeded-formation bands. */
    private static function lineBand( float $y ): string {
        if ( $y < 0.30 ) return 'att';
        if ( $y < 0.65 ) return 'mid';
        if ( $y < 0.90 ) return 'def';
        return 'gk';
    }
}
