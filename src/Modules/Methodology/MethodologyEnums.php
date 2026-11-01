<?php
namespace TT\Modules\Methodology;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MethodologyEnums — closed taxonomies for the methodology module.
 *
 * These are the structural categories of the methodology framework
 * itself. Adding a new team-function or a new set-piece kind isn't a
 * runtime extension — it's a code-level change because the framework
 * is opinionated. That's intentional: a club whose methodology
 * doesn't fit "aanvallen / verdedigen / omschakelen" is using a
 * different framework, and that should produce conscious change here
 * rather than silent drift in `tt_lookups`.
 */
final class MethodologyEnums {

    public const FUNCTION_AANVALLEN              = 'aanvallen';
    public const FUNCTION_OMSCHAKELEN_VERDEDIGEN = 'omschakelen_naar_verdedigen';
    public const FUNCTION_OMSCHAKELEN_AANVALLEN  = 'omschakelen_naar_aanvallen';
    public const FUNCTION_VERDEDIGEN             = 'verdedigen';

    public const TASK_OPBOUWEN              = 'opbouwen';
    public const TASK_SCOREN                = 'scoren';
    public const TASK_OVERGANG_BALVERLIES   = 'overgang_balverlies';
    public const TASK_OVERGANG_BALWINST     = 'overgang_balwinst';
    public const TASK_STOREN                = 'storen';
    public const TASK_DOELPUNTEN_VOORKOMEN  = 'doelpunten_voorkomen';

    public const SET_PIECE_CORNER          = 'corner';
    public const SET_PIECE_FREE_KICK_DIRECT = 'free_kick_direct';
    public const SET_PIECE_FREE_KICK_PASS   = 'free_kick_pass';
    public const SET_PIECE_PENALTY          = 'penalty';
    public const SET_PIECE_THROW_IN         = 'throw_in';

    public const SIDE_ATTACKING  = 'attacking';
    public const SIDE_DEFENDING  = 'defending';
    public const SIDE_TRANSITION = 'transition';

    public const STYLE_AANVALLEND_POSITIESPEL  = 'aanvallend_positiespel';
    public const STYLE_DOMINANT_BALBEZIT       = 'dominant_balbezit';
    public const STYLE_REACTIEF_COUNTER        = 'reactief_counter';
    public const STYLE_HIGH_PRESS              = 'high_press';
    public const STYLE_PRAGMATISCH             = 'pragmatisch';

    public const LINE_AANVALLERS    = 'aanvallers';
    public const LINE_MIDDENVELDERS = 'middenvelders';
    public const LINE_VERDEDIGERS   = 'verdedigers';
    public const LINE_KEEPER        = 'keeper';
    public const LINE_ALGEMEEN      = 'algemeen';

    /** @return array<string,string> slug => translated label */
    public static function teamFunctions(): array {
        return [
            self::FUNCTION_AANVALLEN              => _x( 'Attacking', 'methodology team function', 'talenttrack' ),
            self::FUNCTION_OMSCHAKELEN_VERDEDIGEN => _x( 'Transition to defending', 'methodology team function', 'talenttrack' ),
            self::FUNCTION_OMSCHAKELEN_AANVALLEN  => _x( 'Transition to attacking', 'methodology team function', 'talenttrack' ),
            self::FUNCTION_VERDEDIGEN             => _x( 'Defending', 'methodology team function', 'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function teamTasks(): array {
        return [
            self::TASK_OPBOUWEN              => _x( 'Building up', 'methodology team task', 'talenttrack' ),
            self::TASK_SCOREN                => _x( 'Scoring', 'methodology team task', 'talenttrack' ),
            self::TASK_OVERGANG_BALVERLIES   => _x( 'Transition after losing the ball', 'methodology team task', 'talenttrack' ),
            self::TASK_OVERGANG_BALWINST     => _x( 'Transition after winning the ball', 'methodology team task', 'talenttrack' ),
            self::TASK_STOREN                => _x( 'Disrupting', 'methodology team task', 'talenttrack' ),
            self::TASK_DOELPUNTEN_VOORKOMEN  => _x( 'Preventing goals', 'methodology team task', 'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function setPieceKinds(): array {
        return [
            self::SET_PIECE_CORNER           => __( 'Corner',          'talenttrack' ),
            self::SET_PIECE_FREE_KICK_DIRECT => _x( 'Free kick (direct)', 'methodology set piece', 'talenttrack' ),
            self::SET_PIECE_FREE_KICK_PASS   => _x( 'Free kick (cross)', 'methodology set piece', 'talenttrack' ),
            self::SET_PIECE_PENALTY          => __( 'Penalty',         'talenttrack' ),
            self::SET_PIECE_THROW_IN         => _x( 'Throw-in', 'methodology set piece', 'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function sides(): array {
        return [
            self::SIDE_ATTACKING  => _x( 'Attacking', 'methodology side', 'talenttrack' ),
            self::SIDE_DEFENDING  => _x( 'Defending', 'methodology side', 'talenttrack' ),
            self::SIDE_TRANSITION => _x( 'Transition', 'methodology side', 'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function stylesOfPlay(): array {
        return [
            self::STYLE_AANVALLEND_POSITIESPEL => _x( 'Attacking positional play', 'methodology style of play', 'talenttrack' ),
            self::STYLE_DOMINANT_BALBEZIT      => _x( 'Dominant possession', 'methodology style of play', 'talenttrack' ),
            self::STYLE_REACTIEF_COUNTER       => _x( 'Reactive / counter', 'methodology style of play', 'talenttrack' ),
            self::STYLE_HIGH_PRESS             => __( 'High press',             'talenttrack' ),
            self::STYLE_PRAGMATISCH            => _x( 'Pragmatic', 'methodology style of play', 'talenttrack' ),
        ];
    }

    /**
     * The lines a sub-principle can be scoped to. `algemeen` (#2369)
     * carries phase-wide points that apply to the whole team rather than
     * a single line; the shipped JO13 sub-principles use it for the
     * "algemeen" bullets that the PPT lists above the per-line columns.
     *
     * @return array<string,string>
     */
    public static function lines(): array {
        return [
            self::LINE_AANVALLERS    => _x( 'Attackers', 'methodology line', 'talenttrack' ),
            self::LINE_MIDDENVELDERS => _x( 'Midfielders', 'methodology line', 'talenttrack' ),
            self::LINE_VERDEDIGERS   => _x( 'Defenders', 'methodology line', 'talenttrack' ),
            self::LINE_KEEPER        => _x( 'Goalkeeper', 'methodology line', 'talenttrack' ),
            self::LINE_ALGEMEEN      => _x( 'General', 'methodology line', 'talenttrack' ),
        ];
    }

    public static function isValidFunction( string $key ): bool { return array_key_exists( $key, self::teamFunctions() ); }
    public static function isValidTask( string $key ): bool     { return array_key_exists( $key, self::teamTasks() ); }
    public static function isValidKind( string $key ): bool     { return array_key_exists( $key, self::setPieceKinds() ); }
    public static function isValidSide( string $key ): bool     { return array_key_exists( $key, self::sides() ); }
    public static function isValidStyle( string $key ): bool    { return array_key_exists( $key, self::stylesOfPlay() ); }
    public static function isValidLine( string $key ): bool     { return array_key_exists( $key, self::lines() ); }
}
