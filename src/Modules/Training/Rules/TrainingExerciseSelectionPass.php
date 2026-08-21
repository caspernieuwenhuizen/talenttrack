<?php
namespace TT\Modules\Training\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctExercisesRepository;
use TT\Modules\Vct\Rules\ExerciseSelectionPass;
use TT\Modules\Vct\Rules\Providers\RecentPicksProvider;
use TT\Modules\Vct\Rules\SessionPlanContext;

/**
 * TrainingExerciseSelectionPass (#2497) — pass 6, substituted.
 *
 * The VCT engine resolves the constraints a session must respect: the
 * age intensity ceiling, the match-day context, the slot skeleton, the
 * theme, the progression multiplier, the workload cap and the recovery
 * window. All of that is as true for a tactical training as for a
 * conditioning one, so the Training generator runs the same pipeline and
 * replaces only the step that picks which exercise fills each slot.
 *
 * The base pass picks the first candidate the team has not run recently.
 * That is a sensible conditioning heuristic and a poor development one:
 * it has no idea what this squad is currently trying to get better at.
 *
 * This pass scores instead, on three axes:
 *
 *   1. **Principle coverage** — how many players in the squad have an open
 *      goal on a principle this exercise trains. This is what makes the
 *      generator player-aware rather than merely age-safe, and it is the
 *      whole reason the Training module belongs in TalentTrack.
 *   2. **Variety** — a penalty for anything the team ran recently, so the
 *      same rondo does not come back every week.
 *   3. **Age fit** — a mild preference for exercises whose age window is
 *      centred on the squad rather than scraping its edge.
 *
 * The epic proposed these as two separate pipeline passes,
 * `PrincipleCoveragePass` and `VarietyPass`. They are folded into
 * selection here because both are *scoring* concerns: a pass that ran
 * after selection could only reject a pick, not choose a better one, and
 * one that ran before it would have nothing to score.
 *
 * Determinism matters — the same inputs on the same day must produce the
 * same plan (#2497 acceptance). Ties therefore break on exercise id, and
 * nothing here consults the clock or a random source.
 */
final class TrainingExerciseSelectionPass extends ExerciseSelectionPass {

    private VctExercisesRepository $exercises;
    private RecentPicksProvider $recent_picks;

    /** @var array<int,int> principle id => how many players have an open goal on it */
    private array $target_weights;

    /** @var array<int,list<int>> exercise id => principle ids it trains */
    private array $exercise_principles;

    /**
     * @param array<int,int>       $target_weights
     * @param array<int,list<int>> $exercise_principles
     */
    public function __construct(
        VctExercisesRepository $exercises,
        RecentPicksProvider $recent_picks,
        array $target_weights = [],
        array $exercise_principles = []
    ) {
        parent::__construct( $exercises, $recent_picks );
        $this->exercises           = $exercises;
        $this->recent_picks        = $recent_picks;
        $this->target_weights      = $target_weights;
        $this->exercise_principles = $exercise_principles;
    }

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        $age = self::ageNumericFor( $ctx->age_group );
        if ( $age === null ) {
            $ctx->addWarning( 'unrecognised_age_group_for_selection', 'block', [
                'age_group' => $ctx->age_group,
            ] );
            return $ctx;
        }

        $recent_set = array_flip( $this->recent_picks->recentExerciseIds( $ctx->team_id, 21 ) );

        // An exercise already chosen for an earlier slot must not be
        // chosen again for a later one — a session with the same drill
        // twice reads as a bug, not as emphasis.
        $used = [];

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

            $pick = $this->pickScored( $candidates, $recent_set, $used, $age );
            if ( $pick !== null ) $used[ (int) $pick['id'] ] = true;

            $duration = (int) ( $slot['duration_target'] ?? 0 );
            $band     = $pick !== null
                ? (int) $pick['intensity_band']
                : (int) ( ( (int) $slot['intensity_band_min'] + (int) $slot['intensity_band_max'] ) / 2 );

            $block = [
                'sequence'         => (int) ( $slot['sequence'] ?? count( $blocks ) + 1 ),
                'slot_category'    => (string) $slot['category'],
                'exercise_id'      => $pick !== null ? (int) $pick['id'] : null,
                'custom_label'     => null,
                'duration_minutes' => $duration,
                'intensity_band'   => $band,
                // Carried so the wizard's review step can say which
                // players' goals this block covers, without re-deriving it.
                'covers_principles' => $pick !== null
                    ? ( $this->exercise_principles[ (int) $pick['id'] ] ?? [] )
                    : [],
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
     * Highest score wins; ties break on the lowest exercise id so the
     * same inputs always produce the same plan.
     *
     * @param list<array<string,mixed>> $candidates
     * @param array<int,int>            $recent_set
     * @param array<int,bool>           $used
     * @return array<string,mixed>|null
     */
    private function pickScored( array $candidates, array $recent_set, array $used, int $age ): ?array {
        $best       = null;
        $best_score = null;

        foreach ( $candidates as $candidate ) {
            $id = (int) $candidate['id'];
            if ( isset( $used[ $id ] ) ) continue;

            $score = $this->scoreFor( $candidate, $recent_set, $age );

            if ( $best_score === null
                || $score > $best_score
                || ( $score === $best_score && $id < (int) $best['id'] ) ) {
                $best       = $candidate;
                $best_score = $score;
            }
        }

        // Every candidate already used earlier in this session: better to
        // repeat a drill than to leave the slot empty.
        if ( $best === null ) {
            foreach ( $candidates as $candidate ) {
                if ( $best === null || (int) $candidate['id'] < (int) $best['id'] ) $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<int,int>      $recent_set
     */
    private function scoreFor( array $candidate, array $recent_set, int $age ): int {
        $id    = (int) $candidate['id'];
        $score = 0;

        // 1. Coverage. Weighted by how many players want each principle,
        //    so a drill touching one principle six players need beats one
        //    touching three principles nobody is working on.
        foreach ( $this->exercise_principles[ $id ] ?? [] as $principle_id ) {
            $score += 10 * (int) ( $this->target_weights[ $principle_id ] ?? 0 );
        }

        // 2. Variety. A flat penalty rather than an exclusion — on a thin
        //    catalogue, repeating last week beats an empty slot.
        if ( isset( $recent_set[ $id ] ) ) $score -= 25;

        // 3. Age fit. Prefer a window centred on the squad; scraping the
        //    edge of a range is admissible but not ideal.
        $min = isset( $candidate['age_min'] ) ? (int) $candidate['age_min'] : null;
        $max = isset( $candidate['age_max'] ) ? (int) $candidate['age_max'] : null;
        if ( $min !== null && $max !== null && $max >= $min ) {
            $centre   = ( $min + $max ) / 2;
            $distance = abs( $age - $centre );
            $score   -= (int) round( $distance * 2 );
        }

        return $score;
    }

    /** U10 is roughly nine-year-olds, matching the base pass. */
    private static function ageNumericFor( string $age_group ): ?int {
        if ( preg_match( '/^U(\d{1,2})$/', $age_group, $m ) ) {
            $n = (int) $m[1];
            if ( $n >= 6 && $n <= 19 ) return $n - 1;
        }
        return null;
    }
}
