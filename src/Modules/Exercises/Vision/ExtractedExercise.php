<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ExtractedExercise (#0016 Sprint 1) — one drill the provider read
 * off the photo. Sprint 4's review wizard renders one row per
 * extracted exercise with confidence-coloured controls.
 */
final class ExtractedExercise {

    /**
     * Raw extracted name as written on the photo (before any
     * fuzzy-matching against the exercise library). The Sprint 4
     * matcher will Levenshtein this against `tt_exercises.name`
     * within the active club; matches above ~0.6 similarity surface
     * the existing library exercise + an option to replace.
     */
    public string $name;

    /** Duration the operator wrote on the plan, in minutes. */
    public int $duration_minutes;

    /** Free-text notes scribbled next to the drill on the plan. */
    public string $notes;

    /**
     * Provider's confidence score for this row (0.0-1.0). Sprint 4
     * wizard styling:
     *   >0.85  green, auto-accepted
     *   0.6-0.85  yellow, review recommended
     *   <0.6   red, flagged for manual review or deletion
     */
    public float $confidence;

    /**
     * If the matcher found a likely library hit, the matched
     * `tt_exercises.id`. Null when no library entry resembles the
     * extracted name closely enough; the wizard offers
     * "save-as-new-library-entry" in that case.
     */
    public ?int $matched_exercise_id;

    public function __construct(
        string $name,
        int $duration_minutes = 0,
        string $notes = '',
        float $confidence = 0.0,
        ?int $matched_exercise_id = null
    ) {
        $this->name                = $name;
        $this->duration_minutes    = max( 0, $duration_minutes );
        $this->notes               = $notes;
        $this->confidence          = max( 0.0, min( 1.0, $confidence ) );
        $this->matched_exercise_id = $matched_exercise_id;
    }
}
