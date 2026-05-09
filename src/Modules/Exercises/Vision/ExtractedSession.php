<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ExtractedSession (#0016 Sprint 1) — value object representing what
 * a `VisionProviderInterface` returned from a training-plan photo.
 *
 * Carries an ordered list of `ExtractedExercise` rows + optional
 * attendance markings (Sprint 5 extracts those; Sprint 1-4 leave
 * it empty). The Sprint 4 review wizard consumes this shape: each
 * row gets a confidence-coloured edit cell, the operator accepts /
 * corrects / removes per row, then commits the session.
 *
 * Immutable by convention (no setters). Construct fresh; don't
 * mutate after the provider returns.
 */
final class ExtractedSession {

    /** @var list<ExtractedExercise> */
    public array $exercises;

    /** @var list<array{player_name:string,marking:string,confidence:float}> */
    public array $attendance;

    /**
     * Provider's overall confidence in the extraction (0.0-1.0).
     * Sprint 4 wizard uses this to choose the auto-accept threshold
     * (rows with >0.85 are auto-accepted; <0.6 are flagged for
     * review).
     */
    public float $overall_confidence;

    /**
     * Free-text notes the provider extracted that don't fit the
     * structured fields — handwritten margin notes, weather, mood,
     * etc. The wizard surfaces these as a single editable note
     * field on the activity.
     */
    public string $notes;

    /**
     * @param list<ExtractedExercise> $exercises
     * @param list<array{player_name:string,marking:string,confidence:float}> $attendance
     */
    public function __construct(
        array $exercises,
        array $attendance = [],
        float $overall_confidence = 0.0,
        string $notes = ''
    ) {
        $this->exercises          = $exercises;
        $this->attendance         = $attendance;
        $this->overall_confidence = max( 0.0, min( 1.0, $overall_confidence ) );
        $this->notes              = $notes;
    }
}
