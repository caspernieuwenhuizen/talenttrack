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

    /**
     * #4694 — `withoutPotential()` leaves the potential input as its weight.
     *
     * @var array<string,array{value?:?float,weight:int,score?:?float}>
     */
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

    /**
     * #4694 — the verdict as this reader may read it for this player.
     *
     * Where the potential band is hidden from them (the `potential_judgement`
     * rule, `PotentialTrajectory::bandHiddenFrom()`), see
     * {@see self::withoutPotential()}. Every other reader gets the verdict
     * as it was computed.
     */
    public function forReader( int $reader_id, int $player_id ): self {
        if ( ! isset( $this->inputs['potential'] ) ) return $this;
        if ( ! \TT\Modules\Players\Services\PotentialTrajectory::bandHiddenFrom( $reader_id, $player_id ) ) return $this;
        return $this->withoutPotential();
    }

    /**
     * The verdict without the band, by the rule {@see self::maskedParts()}
     * states once for the object and for its array form.
     */
    public function withoutPotential(): self {
        if ( ! isset( $this->inputs['potential'] ) ) return $this;

        $parts = self::maskedParts( $this->inputs['potential'], $this->reasons );
        $copy  = clone $this;
        $copy->inputs['potential'] = $parts['potential'];
        $copy->reasons             = $parts['reasons'];
        if ( $parts['hide_score'] ) $copy->score = null;
        return $copy;
    }

    /**
     * {@see self::withoutPotential()} on a verdict payload (`toArray()`), as a
     * report section or a snapshot holds it. The same rule, not a copy of it.
     *
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public static function payloadWithoutPotential( array $status ): array {
        $inputs    = is_array( $status['inputs'] ?? null ) ? $status['inputs'] : [];
        $potential = is_array( $inputs['potential'] ?? null ) ? $inputs['potential'] : null;
        if ( $potential === null ) return $status;

        $reasons = [];
        foreach ( is_array( $status['reasons'] ?? null ) ? $status['reasons'] : [] as $reason ) {
            if ( is_string( $reason ) ) $reasons[] = $reason;
        }

        $parts               = self::maskedParts( $potential, $reasons );
        $inputs['potential'] = $parts['potential'];
        $status['inputs']    = $inputs;
        $status['reasons']   = $parts['reasons'];
        if ( $parts['hide_score'] ) $status['score'] = null;
        return $status;
    }

    /**
     * #4694 — the rule. The potential input keeps its weight and leaves out
     * its value and score, which encode the band. A potential that was never
     * set is still named in `missing_inputs`, so a reader can tell the two
     * apart. When the band counted towards the composite, the composite goes
     * as well, because with the other inputs' scores it gives the band back
     * by arithmetic: the `score` is null, and a reason that names the
     * composite against a threshold keeps the threshold and loses the
     * number. The colour, the other reasons, the coverage and the missing
     * inputs stay.
     *
     * @param array<string,mixed> $potential the potential input
     * @param list<string>        $reasons
     * @return array{potential: array{weight:int}, hide_score: bool, reasons: list<string>}
     */
    private static function maskedParts( array $potential, array $reasons ): array {
        $weight     = (int) ( $potential['weight'] ?? 0 );
        $hide_score = ( $potential['score'] ?? null ) !== null && $weight > 0;

        return [
            'potential'  => [ 'weight' => $weight ],
            'hide_score' => $hide_score,
            'reasons'    => $hide_score
                ? array_map( static fn( string $r ): string => self::reasonWithoutComposite( $r ), $reasons )
                : $reasons,
        ];
    }

    /**
     * A threshold reason the calculator wrote ("Composite 55 below amber
     * threshold 60."), without the composite, or the reason as it came. Read
     * against the translated format and the English one, so a reason stored
     * in another language is still found.
     */
    private static function reasonWithoutComposite( string $reason ): string {
        $formats = [
            [
                'Composite %s below red threshold %s.',
                /* translators: %s: the red threshold, e.g. 40 */
                __( 'Composite below the red threshold (%s).', 'talenttrack' ),
            ],
            [
                'Composite %s below amber threshold %s.',
                /* translators: %s: the amber threshold, e.g. 60 */
                __( 'Composite below the amber threshold (%s).', 'talenttrack' ),
            ],
        ];
        foreach ( $formats as [ $msgid, $masked ] ) {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText — the calculator's own msgids, read back.
            foreach ( array_unique( [ __( $msgid, 'talenttrack' ), $msgid ] ) as $format ) {
                $threshold = self::argumentIn( $format, $reason, 2 );
                if ( $threshold !== null ) return sprintf( $masked, $threshold );
            }
        }
        return $reason;
    }

    /**
     * The `$position`-th argument (1-based) a `%s` / `%1$s` format put into
     * `$text`, or null when the text was not made from the format.
     */
    private static function argumentIn( string $format, string $text, int $position ): ?string {
        $parts = preg_split( '/(%(?:\d+\$)?s)/', $format, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) return null;

        $pattern = '';
        $order   = [];
        $next    = 1;
        foreach ( $parts as $part ) {
            if ( preg_match( '/^%(?:(\d+)\$)?s$/', $part, $m ) === 1 ) {
                $order[]  = ( $m[1] ?? '' ) !== '' ? (int) $m[1] : $next++;
                $pattern .= '(.+?)';
            } else {
                $pattern .= preg_quote( $part, '/' );
            }
        }
        if ( preg_match( '/^' . $pattern . '$/u', $text, $found ) !== 1 ) return null;

        $index = array_search( $position, $order, true );
        return $index === false ? null : (string) ( $found[ $index + 1 ] ?? '' );
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
