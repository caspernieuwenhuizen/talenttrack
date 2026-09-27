<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Units\DurationFormat;
use TT\Modules\Measurements\Units\UnitContext;

/**
 * TestVerdict (#4093) — how a test reading stands against its age-group
 * target, in words, and what that target is.
 *
 * The player profile's register (#3526) and the team monthly report both
 * print a target and a standing per reading. They used to be worked out in
 * the profile's view, where the report could not reach them, so a report
 * would have grown its own copy and the two would have drifted. Both call
 * this instead (CLAUDE.md §4): the flag itself still comes from
 * `MeasurementTargetsRepository::flagFor()`, and this names it.
 *
 * The target prints through the test's `UnitContext`, so a timed test reads
 * `≤ 12:30` like its readings do, not `≤ 12,5 min`.
 */
final class TestVerdict {

    /** Tones a verdict can carry; the chip's colour follows it. */
    public const TONE_OK    = 'ok';
    public const TONE_WARN  = 'warn';
    public const TONE_BAD   = 'bad';
    public const TONE_NONE  = 'none';
    public const TONE_LEVEL = 'level';

    /**
     * The verdict on a reading, in words, with the tone its chip is drawn
     * in. The table is fixed (#3526): colour restates the words, never
     * replaces them.
     *
     * A direction-less test gets no verdict at all: there is no better or
     * worse to report, and the target says "no target" instead. A pass/fail
     * test gets none either. A level-banded test's verdict is its level.
     *
     * @return array{label:string, tone:string}
     */
    public static function verdict( string $value_type, string $direction, string $flag, bool $never = false, string $level_label = '' ): array {
        // Every label goes through `_x()`. They are two or three words long,
        // which is exactly the length at which a msgid picks up another
        // surface's sense: "on target" was already in the catalogue as "op
        // schema" — on *schedule* — which is a different statement about a
        // different thing.
        if ( $never ) {
            return [ 'label' => _x( 'not measured yet', 'a test that has never been measured', 'talenttrack' ), 'tone' => self::TONE_NONE ];
        }

        // A level is its own verdict, already named and coloured by the
        // operator's palette. Restating it as "on target" would invent a
        // judgement the level vocabulary does not make.
        if ( $value_type === 'status' ) {
            return [ 'label' => $level_label, 'tone' => $level_label !== '' ? self::TONE_LEVEL : self::TONE_NONE ];
        }

        if ( $value_type === 'passfail' || ( $direction !== 'higher' && $direction !== 'lower' ) ) {
            return [ 'label' => '', 'tone' => '' ];
        }

        switch ( $flag ) {
            case 'ok':
                return [ 'label' => _x( 'on target', 'a test reading meets its target', 'talenttrack' ), 'tone' => self::TONE_OK ];
            case 'warn':
                return $direction === 'higher'
                    ? [ 'label' => _x( 'just under target', 'a reading a little below a higher-is-better target', 'talenttrack' ), 'tone' => self::TONE_WARN ]
                    : [ 'label' => _x( 'just over target', 'a reading a little above a lower-is-better target', 'talenttrack' ), 'tone' => self::TONE_WARN ];
            case 'bad':
                return $direction === 'higher'
                    ? [ 'label' => _x( 'well under target', 'a reading far below a higher-is-better target', 'talenttrack' ), 'tone' => self::TONE_BAD ]
                    : [ 'label' => _x( 'well over target', 'a reading far above a lower-is-better target', 'talenttrack' ), 'tone' => self::TONE_BAD ];
        }

        return [ 'label' => '', 'tone' => '' ];
    }

    /**
     * The chip's classes for a verdict's tone. A level reuses the shipped
     * level palette, which already paints `.tt-meas-value--status` at 4.5:1,
     * rather than opening a second set of level colours to keep in step.
     */
    public static function chipClass( string $tone, string $level_token = '' ): string {
        switch ( $tone ) {
            case self::TONE_OK:   return 'tt-meas-chip--ok';
            case self::TONE_WARN: return 'tt-meas-chip--warn';
            case self::TONE_BAD:  return 'tt-meas-chip--bad';
            case self::TONE_NONE: return 'tt-meas-chip--none';
            case self::TONE_LEVEL:
                return $level_token !== ''
                    ? 'tt-meas-value--status ' . MeasurementLevelPalette::cssClass( $level_token )
                    : 'tt-meas-chip--none';
        }
        return '';
    }

    /**
     * The target a reading is judged against, as printed: the band's edge on
     * the better side (#3028), `≤ 12:30` or `≥ 45 cm`.
     *
     * - A level-banded or pass/fail test has no single edge: empty, and the
     *   chip carries the verdict.
     * - A test without a direction has nothing to reach: "no target", and
     *   `absent` so a surface can explain that once.
     * - A test with no band for the age group: empty, and not absent — it is
     *   a missing target row, not a test that cannot have one.
     *
     * @param object|null $target a `tt_measurement_targets` row; its edges
     *        are canonical (base-unit) values.
     * @return array{text:string, absent:bool}
     */
    public static function target( string $value_type, string $direction, ?object $target, UnitContext $units ): array {
        if ( $value_type === 'status' || $value_type === 'passfail' ) {
            return [ 'text' => '', 'absent' => false ];
        }
        if ( $direction !== 'higher' && $direction !== 'lower' ) {
            return [ 'text' => _x( 'no target', 'a test with no better or worse value', 'talenttrack' ), 'absent' => true ];
        }

        $edge = self::edge( $target, $direction );
        if ( $edge === null ) return [ 'text' => '', 'absent' => false ];

        return [ 'text' => ( $direction === 'higher' ? '≥ ' : '≤ ' ) . self::formatEdge( $edge, $units ), 'absent' => false ];
    }

    /**
     * The edge a player should reach: the green floor on a higher-is-better
     * test, the green ceiling on a lower-is-better one. Base units.
     */
    public static function edge( ?object $target, string $direction ): ?float {
        if ( $target === null ) return null;
        $key   = $direction === 'higher' ? 'green_min' : 'green_max';
        $value = $target->{$key} ?? null;
        return is_numeric( $value ) ? (float) $value : null;
    }

    /**
     * A canonical value spelled the way the reader reads the test's readings:
     * `12:30` for a duration, a locale number and the unit otherwise.
     */
    public static function formatEdge( float $base, UnitContext $units ): string {
        if ( $units->isDuration() ) {
            return DurationFormat::format( round( $base, 2 ) );
        }
        $symbol = $units->symbol();
        $number = UnitContext::localeNumber( round( $units->fromBase( $base ), 2 ) );
        return $symbol !== '' ? $number . ' ' . $symbol : $number;
    }
}
