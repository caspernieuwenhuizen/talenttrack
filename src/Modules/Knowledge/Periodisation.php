<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Periodisation — the numbers behind the football-periodisation model.
 *
 * Supercompensation times, training-method parameters and the pitch-size
 * rule of thumb, in one place. Three interactive lesson blocks read them
 * (`tt-zeropoint`, `tt-weekplanner`, `tt-pitchsize`), and the Training
 * module will want the same numbers when it plans a session (#2493).
 *
 * The reason this is a class and not three arrays scattered across three
 * renderers: a course that teaches "4v4 needs 72 hours" and a planner that
 * warns at 48 would be worse than either alone. When these numbers move —
 * and a methodology set may well move them (#2316) — they move once.
 *
 * Values follow Raymond Verheijen, *Football Periodisation* (World Football
 * Academy, 2014), which is the methodology the shipped course teaches.
 */
final class Periodisation {

    /**
     * Recovery required after each conditioning exercise, in hours.
     *
     * Two numbers, not one: the source gives 11v11/8v8 as a 24–48 hour
     * range. `min` is what the body needs at the low end; `max` is the
     * conservative figure a planner should warn against violating. Where
     * the source gives a single figure, both are equal.
     *
     * @return array<string, array{label: string, min: int, max: int}>
     */
    public static function supercompensation(): array {
        return [
            'explosivity_prep' => [
                'label' => __( 'Explosivity preparation exercises', 'talenttrack' ),
                'min'   => 24,
                'max'   => 24,
            ],
            'games_large' => [
                'label' => __( 'Games 11v11 – 8v8', 'talenttrack' ),
                'min'   => 24,
                'max'   => 48,
            ],
            'sprints_max_rest' => [
                'label' => __( 'Football sprints with maximum rest', 'talenttrack' ),
                'min'   => 48,
                'max'   => 48,
            ],
            'games_medium' => [
                'label' => __( 'Games 7v7 – 5v5', 'talenttrack' ),
                'min'   => 48,
                'max'   => 48,
            ],
            'sprints_min_rest' => [
                'label' => __( 'Football sprints with minimum rest', 'talenttrack' ),
                'min'   => 72,
                'max'   => 72,
            ],
            'games_small' => [
                'label' => __( 'Games 4v4 – 3v3', 'talenttrack' ),
                'min'   => 72,
                'max'   => 72,
            ],
        ];
    }

    /**
     * The three blocks of the six-week model, in order.
     *
     * Each block runs two weeks and belongs to one phase of the movement
     * from volume to intensity — which is what the block's `accent` keys
     * the colour to, so the reader shows a progression rather than three
     * arbitrary panels.
     *
     * @return list<array{weeks: string, accent: string, exercises: list<string>, methods: list<string>}>
     */
    public static function model(): array {
        return [
            [
                'weeks'     => __( 'Week 1–2', 'talenttrack' ),
                'accent'    => 'volume',
                'exercises' => [
                    __( 'Explosivity preparation exercises', 'talenttrack' ),
                    __( 'Games 11v11 – 8v8', 'talenttrack' ),
                ],
                'methods'   => [
                    __( 'Acceleration runs', 'talenttrack' ),
                    __( 'Extensive endurance method', 'talenttrack' ),
                ],
            ],
            [
                'weeks'     => __( 'Week 3–4', 'talenttrack' ),
                'accent'    => 'transition',
                'exercises' => [
                    __( 'Football sprints with minimum rest', 'talenttrack' ),
                    __( 'Games 7v7 – 5v5', 'talenttrack' ),
                ],
                'methods'   => [
                    __( 'Repeated short sprinting', 'talenttrack' ),
                    __( 'Intensive endurance method', 'talenttrack' ),
                ],
            ],
            [
                'weeks'     => __( 'Week 5–6', 'talenttrack' ),
                'accent'    => 'intensity',
                'exercises' => [
                    __( 'Football sprints with maximum rest', 'talenttrack' ),
                    __( 'Games 4v4 – 3v3', 'talenttrack' ),
                ],
                'methods'   => [
                    __( 'Start and acceleration speed', 'talenttrack' ),
                    __( 'Extensive interval method', 'talenttrack' ),
                ],
            ],
        ];
    }

    /**
     * Overload steps for the methods a zero-point measurement resolves.
     *
     * A measurement returns minutes played; the step is the entry whose
     * `total` those minutes reach. Steps are listed in ascending load, so
     * the resolver walks until the total exceeds what was measured.
     *
     * Only the two endurance methods are listed. The interval method is
     * measured on work-to-rest ratio rather than on elapsed minutes, so a
     * minutes-in-step-out lookup would be the wrong shape for it — the
     * course teaches that one by observation instead.
     *
     * @return array<string, array{label: string, block: int, steps: list<array{step: int, games: int, minutes: float, total: float}>}>
     */
    public static function overloadSteps(): array {
        return [
            'extensive_endurance' => [
                'label' => __( 'Extensive endurance method (11v11 – 8v8)', 'talenttrack' ),
                'block' => 10,
                'steps' => self::expand( [
                    // games => durations, in ascending load. Note that each
                    // game count starts one minute higher than the last one
                    // began: after 2 × 15 the next step is 3 × 11, not
                    // 3 × 10. A generated rectangular grid gets this wrong
                    // and shifts every step number after the sixth.
                    [ 2, [ 10, 11, 12, 13, 14, 15 ] ],
                    [ 3, [ 11, 12, 13, 14, 15 ] ],
                    [ 4, [ 12, 13, 14, 15 ] ],
                    [ 5, [ 13, 14, 15 ] ],
                    [ 6, [ 13, 14, 15 ] ],
                ] ),
            ],
            'intensive_endurance' => [
                'label' => __( 'Intensive endurance method (7v7 – 5v5)', 'talenttrack' ),
                'block' => 4,
                'steps' => self::expand( [
                    // Half-minute increments: these games are more intensive,
                    // so a whole minute is too big a jump.
                    [ 4, [ 4, 4.5, 5, 5.5, 6, 6.5, 7, 7.5, 8 ] ],
                    [ 5, [ 7, 7.5, 8 ] ],
                    [ 6, [ 7, 7.5, 8 ] ],
                ] ),
            ],
        ];
    }

    /**
     * Pitch dimensions per game format.
     *
     * The rule of thumb is 10 by 6 metres per outfield player, taken from
     * a full pitch divided by ten. It breaks down at 7v7 and below, where
     * the computed width comes out narrower than a penalty area — so those
     * formats carry a practical minimum, and a pitch narrower than that
     * silently turns the intended method into a more intensive one.
     *
     * @return list<array{format: string, outfield: int, length: int, width: int, min_width: int}>
     */
    public static function pitchSizes(): array {
        $sizes = [];

        // 1v1 through 11v11. Outfield players is squad size minus the
        // keeper for the formats that have one; below 4v4 the source
        // treats every player as an outfield player.
        $formats = [
            11 => 10, 10 => 9, 9 => 8, 8 => 7,
            7  => 6,  6  => 5, 5 => 4, 4 => 3,
            3  => 2,  2  => 1, 1 => 1,
        ];

        // Formats whose computed width is unusably narrow in practice.
        $minimums = [ 7 => 40, 6 => 40, 5 => 30, 2 => 10 ];

        foreach ( $formats as $per_side => $outfield ) {
            $width = $outfield * 6;
            $sizes[] = [
                'format'    => sprintf( '%dv%d', $per_side, $per_side ),
                'outfield'  => $outfield,
                'length'    => $outfield * 10,
                'width'     => $width,
                'min_width' => max( $width, $minimums[ $per_side ] ?? 0 ),
            ];
        }

        return $sizes;
    }

    /**
     * Metres of length and width per outfield player. Exposed so the
     * pitch-size block can show the derivation rather than a magic table.
     *
     * @return array{length: int, width: int}
     */
    public static function metresPerOutfieldPlayer(): array {
        return [ 'length' => 10, 'width' => 6 ];
    }

    /**
     * Session types a week plan can hold, with the exercise key whose
     * recovery time each one incurs. A session with a null key costs no
     * recovery — that is what makes underload plannable on any day.
     *
     * @return array<string, array{label: string, exercise: ?string}>
     */
    public static function sessionTypes(): array {
        return [
            'match'       => [ 'label' => __( 'Match', 'talenttrack' ),                        'exercise' => null ],
            'recovery'    => [ 'label' => __( 'Recovery training', 'talenttrack' ),            'exercise' => null ],
            'technical'   => [ 'label' => __( 'Technical training', 'talenttrack' ),           'exercise' => null ],
            'tactical'    => [ 'label' => __( 'Tactical training', 'talenttrack' ),            'exercise' => null ],
            'games_large' => [ 'label' => __( 'Conditioning 11v11 – 8v8', 'talenttrack' ),     'exercise' => 'games_large' ],
            'games_medium'=> [ 'label' => __( 'Conditioning 7v7 – 5v5', 'talenttrack' ),       'exercise' => 'games_medium' ],
            'games_small' => [ 'label' => __( 'Conditioning 4v4 – 3v3', 'talenttrack' ),       'exercise' => 'games_small' ],
            'sprints_min' => [ 'label' => __( 'Sprints with minimum rest', 'talenttrack' ),    'exercise' => 'sprints_min_rest' ],
            'sprints_max' => [ 'label' => __( 'Sprints with maximum rest', 'talenttrack' ),    'exercise' => 'sprints_max_rest' ],
            'off'         => [ 'label' => __( 'Day off', 'talenttrack' ),                      'exercise' => null ],
        ];
    }

    /**
     * Everything the block scripts need, as one JSON-serialisable array.
     * Localised once per lesson rather than duplicated per block.
     *
     * @return array<string, mixed>
     */
    public static function forScript(): array {
        return [
            'supercompensation' => self::supercompensation(),
            'overloadSteps'     => self::overloadSteps(),
            'pitchSizes'        => self::pitchSizes(),
            'perPlayer'         => self::metresPerOutfieldPlayer(),
            'sessionTypes'      => self::sessionTypes(),
        ];
    }

    /**
     * Number a method's step table and pre-compute each step's total.
     *
     * Duration rises before game count throughout, which is the law of
     * durability expressed as an ordering: a longer game is a smaller ask
     * than another game.
     *
     * @param list<array{0: int, 1: list<int|float>}> $table games => durations
     * @return list<array{step: int, games: int, minutes: float, total: float}>
     */
    private static function expand( array $table ): array {
        $steps = [];
        $n     = 1;

        foreach ( $table as [ $games, $durations ] ) {
            foreach ( $durations as $minutes ) {
                $steps[] = [
                    'step'    => $n++,
                    'games'   => $games,
                    'minutes' => (float) $minutes,
                    'total'   => round( $games * $minutes, 1 ),
                ];
            }
        }

        return $steps;
    }
}
