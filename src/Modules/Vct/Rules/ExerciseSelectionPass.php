<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctExercisesRepository;
use TT\Modules\Vct\Rules\Providers\RecentPicksProvider;

/**
 * ExerciseSelectionPass — Pass 6.
 *
 * For each slot in the composed template, query the candidate set
 * (`VctExercisesRepository::findCandidates`) and pick the best
 * exercise. Scoring:
 *
 *   - age-fit centeredness: prefer exercises whose age range is
 *     centred on the player's age (penalise edge fits)
 *   - variety: penalise exercises in $recent_picks
 *   - Verheijen weight: prefer exercises whose verheijen_classification
 *     matches the slot's conditioning intent (sorted ASC by the
 *     repo's findCandidates, so the first non-recently-picked
 *     candidate is already the best Verheijen fit for the band)
 *
 * Coach can `custom_label` a block instead of picking an exercise —
 * supported in the schema (exercise_id NULL, custom_label NOT NULL).
 * This pass never overwrites a coach's hand-fill: it only runs in
 * `compose()` mode, not `validate()`.
 *
 * If a slot has zero candidates (e.g. catalogue gap at this band /
 * theme / age), the pass leaves `exercise_id = null` and emits a
 * `caution` warning. The coach can hand-fill the block.
 */
class ExerciseSelectionPass implements RulePass {

    private VctExercisesRepository $exercises;
    private RecentPicksProvider $recent_picks;

    public function __construct( VctExercisesRepository $exercises, RecentPicksProvider $recent_picks ) {
        $this->exercises    = $exercises;
        $this->recent_picks = $recent_picks;
    }

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        $age = $this->ageNumeric( $ctx->age_group );
        if ( $age === null ) {
            $ctx->addWarning( 'unrecognised_age_group_for_selection', 'block', [
                'age_group' => $ctx->age_group,
            ] );
            return $ctx;
        }

        $recent = $this->recent_picks->recentExerciseIds( $ctx->team_id, 21 );
        $recent_set = array_flip( $recent );

        $blocks = [];
        foreach ( $ctx->slots as $slot ) {
            $candidates = $this->exercises->findCandidates(
                (string) $slot['category'],
                (int)    $slot['intensity_band_min'],
                (int)    $slot['intensity_band_max'],
                $age,
                $ctx->md_context,
                $slot['effective_theme'] ?? null
            );

            $pick = $this->pickBest( $candidates, $recent_set );

            $duration = (int) ( $slot['duration_target'] ?? 0 );
            $intensity_band = $pick !== null
                ? (int) $pick['intensity_band']
                : (int) ( ( (int) $slot['intensity_band_min'] + (int) $slot['intensity_band_max'] ) / 2 );

            $block = [
                'sequence'         => (int) ( $slot['sequence'] ?? count( $blocks ) + 1 ),
                'slot_category'    => (string) $slot['category'],
                'exercise_id'      => $pick !== null ? (int) $pick['id'] : null,
                'custom_label'     => null,
                'duration_minutes' => $duration,
                'intensity_band'   => $intensity_band,
            ];

            if ( $pick === null ) {
                $ctx->addWarning( 'no_candidate_for_slot', 'caution', [
                    'slot_sequence' => $block['sequence'],
                    'category'      => $block['slot_category'],
                    'age_group'     => $ctx->age_group,
                    'md_context'    => $ctx->md_context,
                    'theme'         => $slot['effective_theme'] ?? null,
                ] );
            }

            $blocks[] = $block;
        }

        $ctx->blocks = $blocks;
        return $ctx;
    }

    /**
     * Pick the first candidate not in $recent_set (variety bias). If
     * every candidate is recently used, fall through to the first
     * candidate anyway — better than nothing.
     *
     * @param list<array<string,mixed>> $candidates
     * @param array<int,int> $recent_set
     * @return array<string,mixed>|null
     */
    private function pickBest( array $candidates, array $recent_set ): ?array {
        foreach ( $candidates as $c ) {
            if ( ! isset( $recent_set[ (int) $c['id'] ] ) ) return $c;
        }
        return $candidates[0] ?? null;
    }

    private function ageNumeric( string $age_group ): ?int {
        if ( preg_match( '/^U(\d{2})$/', $age_group, $m ) ) {
            $n = (int) $m[1];
            if ( $n >= 6 && $n <= 19 ) return $n - 1; // U10 ≈ 9-year-olds
        }
        return null;
    }
}
