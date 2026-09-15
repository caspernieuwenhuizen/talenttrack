<?php
namespace TT\Infrastructure\PlayerStatus;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StatusVerdict (#0057 Sprint 2) — output of the status calculator.
 *
 * The four colors:
 *   - `green`   — on track.
 *   - `amber`   — needs extra attention; the data says check in soon.
 *   - `red`     — the data signals this player needs an intervention
 *                 conversation (PDP meeting), urgently.
 *   - `unknown` — sparse data; new player or insufficient signal.
 *
 * `score` is the composite numeric (0-100). `inputs` holds each input
 * value + weight + normalised score so the breakdown panel can render
 * it transparently. `reasons` is a short list of human-readable strings
 * explaining which thresholds / floor rules fired. `as_of` is the
 * timestamp the calculation ran (UTC, Y-m-d H:i:s).
 *
 * ## Coverage (#3413)
 *
 * The composer renormalises over the inputs that *have* a value, which
 * is the right arithmetic and was silent about itself. On a demo academy
 * 25 of 64 active players carried a potential row, so 39 dots were a
 * 40/25/20 blend and their team-mates' a 40/25/20/15 one — rendered as
 * the same coloured circle on the same squad table, as though the two
 * were directly comparable.
 *
 * `coverage` is the share of the enabled, weighted inputs that actually
 * contributed: `weight_total / enabled_weight_total`, 1.0 when every
 * input was present and 0.0 for `COLOR_UNKNOWN`. `missing_inputs` names
 * the ones that did not, so a surface can say *which* evidence is absent
 * rather than only that some is.
 *
 * It lives on the verdict rather than being derived per surface so the
 * REST controller and the rendered view answer the same way (CLAUDE.md
 * §4), and so a squad-level read can sort by it.
 */
final class StatusVerdict {

    public const COLOR_GREEN   = 'green';
    public const COLOR_AMBER   = 'amber';
    public const COLOR_RED     = 'red';
    public const COLOR_UNKNOWN = 'unknown';

    public string $color;
    public ?float $score;

    /** @var array<string,array{value:?float,weight:int,score:?float}> */
    public array $inputs;

    /** @var list<string> */
    public array $reasons;

    public string $as_of;
    public string $methodology_version;

    /**
     * Share of the enabled, weighted inputs that contributed, 0.0–1.0.
     * 1.0 when nothing was missing; 0.0 for `COLOR_UNKNOWN`.
     */
    public float $coverage;

    /** @var list<string> input keys that were enabled and weighted but had no value */
    public array $missing_inputs;

    /**
     * @param array<string,array{value:?float,weight:int,score:?float}> $inputs
     * @param list<string> $reasons
     * @param list<string> $missing_inputs
     */
    public function __construct(
        string $color,
        ?float $score,
        array $inputs,
        array $reasons,
        string $as_of,
        string $methodology_version,
        float $coverage = 1.0,
        array $missing_inputs = []
    ) {
        $this->color               = $color;
        $this->score               = $score;
        $this->inputs              = $inputs;
        $this->reasons             = $reasons;
        $this->as_of               = $as_of;
        $this->methodology_version = $methodology_version;
        $this->coverage            = $coverage;
        $this->missing_inputs      = $missing_inputs;
    }

    /**
     * Was every enabled, weighted input present?
     *
     * False for a partial verdict AND for `COLOR_UNKNOWN` — both were
     * computed on less than the methodology asks for, they differ only in
     * how much less.
     */
    public function isComplete(): bool {
        return $this->missing_inputs === [];
    }

    /** Coverage as a whole percentage, for a label or a sort key. */
    public function coveragePercent(): int {
        return (int) round( $this->coverage * 100 );
    }

    /**
     * Human label for one input key. The keys are the calculator's, and
     * they are the vocabulary the methodology screen already uses.
     *
     * `_x()` rather than `__()`: these are single lower-case words that
     * only make sense inside "Computed without …", and a bare one-word
     * msgid is exactly the kind that picks up the wrong sense in
     * translation.
     */
    public static function inputLabel( string $key ): string {
        switch ( $key ) {
            case 'ratings':    return _x( 'evaluations', 'status input, as in "Computed without potential."', 'talenttrack' );
            case 'behaviour':  return _x( 'behaviour',   'status input, as in "Computed without potential."', 'talenttrack' );
            case 'attendance': return _x( 'attendance',  'status input, as in "Computed without potential."', 'talenttrack' );
            case 'potential':  return _x( 'potential',   'status input, as in "Computed without potential."', 'talenttrack' );
            default:           return $key;
        }
    }

    /** @return list<string> */
    public function missingInputLabels(): array {
        return array_values( array_map(
            static fn( string $key ): string => self::inputLabel( $key ),
            $this->missing_inputs
        ) );
    }

    /**
     * One sentence naming the evidence this verdict was computed without,
     * or '' when it was computed on everything.
     *
     * This is what makes the dot's accessible name honest: a colour alone
     * cannot say that two players showing the same amber were judged on
     * different evidence.
     */
    public function coverageNote(): string {
        if ( $this->isComplete() ) return '';
        return sprintf(
            /* translators: %s is a comma-separated list of missing inputs, e.g. "potential". */
            __( 'Computed without %s.', 'talenttrack' ),
            implode( ', ', $this->missingInputLabels() )
        );
    }

    /**
     * Soft label for parent-facing surfaces. Never reveals numerics.
     * #1377 — growth-framed wording: a child's bad quarter reads as
     * "needs support", not as a risk assessment about them.
     */
    public function softLabel(): string {
        switch ( $this->color ) {
            case self::COLOR_GREEN:   return __( 'On track', 'talenttrack' );
            case self::COLOR_AMBER:   return __( 'Extra attention', 'talenttrack' );
            case self::COLOR_RED:     return __( 'Could use extra support right now', 'talenttrack' );
            default:                  return __( 'Building first picture', 'talenttrack' );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return [
            'color'               => $this->color,
            'score'               => $this->score,
            'inputs'              => $this->inputs,
            'reasons'             => $this->reasons,
            'as_of'               => $this->as_of,
            'methodology_version' => $this->methodology_version,
            // #3413 — the honesty fields. A consumer reading two verdicts
            // needs to know they were computed on different evidence.
            'coverage'            => $this->coverage,
            'missing_inputs'      => $this->missing_inputs,
            'coverage_note'       => $this->coverageNote(),
        ];
    }
}
