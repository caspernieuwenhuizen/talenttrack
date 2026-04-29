<?php
namespace TT\Infrastructure\PlayerStatus;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StatusVerdict (#0057 Sprint 2) — output of the status calculator.
 *
 * The four colors:
 *   - `green`   — on track.
 *   - `amber`   — on the edge; data signal that a replacement may be warranted.
 *   - `red`     — termination intent is data-supported.
 *   - `unknown` — sparse data; new player or insufficient signal.
 *
 * `score` is the composite numeric (0-100). `inputs` holds each input
 * value + weight + normalised score so the breakdown panel can render
 * it transparently. `reasons` is a short list of human-readable strings
 * explaining which thresholds / floor rules fired. `as_of` is the
 * timestamp the calculation ran (UTC, Y-m-d H:i:s).
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
     * @param array<string,array{value:?float,weight:int,score:?float}> $inputs
     * @param list<string> $reasons
     */
    public function __construct(
        string $color,
        ?float $score,
        array $inputs,
        array $reasons,
        string $as_of,
        string $methodology_version
    ) {
        $this->color               = $color;
        $this->score               = $score;
        $this->inputs              = $inputs;
        $this->reasons             = $reasons;
        $this->as_of               = $as_of;
        $this->methodology_version = $methodology_version;
    }

    /**
     * Soft label for parent-facing surfaces. Never reveals numerics or
     * the word "termination".
     */
    public function softLabel(): string {
        switch ( $this->color ) {
            case self::COLOR_GREEN:   return __( 'On track', 'talenttrack' );
            case self::COLOR_AMBER:   return __( 'At risk', 'talenttrack' );
            case self::COLOR_RED:     return __( 'Needs significant development support', 'talenttrack' );
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
        ];
    }
}
