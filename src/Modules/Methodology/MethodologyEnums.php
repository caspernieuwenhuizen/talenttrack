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

    public const SIDE_ATTACKING = 'attacking';
    public const SIDE_DEFENDING = 'defending';

    public const STYLE_AANVALLEND_POSITIESPEL  = 'aanvallend_positiespel';
    public const STYLE_DOMINANT_BALBEZIT       = 'dominant_balbezit';
    public const STYLE_REACTIEF_COUNTER        = 'reactief_counter';
    public const STYLE_HIGH_PRESS              = 'high_press';
    public const STYLE_PRAGMATISCH             = 'pragmatisch';

    public const LINE_AANVALLERS    = 'aanvallers';
    public const LINE_MIDDENVELDERS = 'middenvelders';
    public const LINE_VERDEDIGERS   = 'verdedigers';
    public const LINE_KEEPER        = 'keeper';

    /** @return array<string,string> slug => translated label */
    public static function teamFunctions(): array {
        return [
            self::FUNCTION_AANVALLEN              => __( 'Aanvallen',                       'talenttrack' ),
            self::FUNCTION_OMSCHAKELEN_VERDEDIGEN => __( 'Omschakelen naar verdedigen',     'talenttrack' ),
            self::FUNCTION_OMSCHAKELEN_AANVALLEN  => __( 'Omschakelen naar aanvallen',      'talenttrack' ),
            self::FUNCTION_VERDEDIGEN             => __( 'Verdedigen',                      'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function teamTasks(): array {
        return [
            self::TASK_OPBOUWEN              => __( 'Opbouwen',              'talenttrack' ),
            self::TASK_SCOREN                => __( 'Scoren',                'talenttrack' ),
            self::TASK_OVERGANG_BALVERLIES   => __( 'Overgang na balverlies','talenttrack' ),
            self::TASK_OVERGANG_BALWINST     => __( 'Overgang na balwinst',  'talenttrack' ),
            self::TASK_STOREN                => __( 'Storen',                'talenttrack' ),
            self::TASK_DOELPUNTEN_VOORKOMEN  => __( 'Doelpunten voorkomen',  'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function setPieceKinds(): array {
        return [
            self::SET_PIECE_CORNER           => __( 'Corner',          'talenttrack' ),
            self::SET_PIECE_FREE_KICK_DIRECT => __( 'Vrije trap (direct)', 'talenttrack' ),
            self::SET_PIECE_FREE_KICK_PASS   => __( 'Vrije trap (voorzet)', 'talenttrack' ),
            self::SET_PIECE_PENALTY          => __( 'Penalty',         'talenttrack' ),
            self::SET_PIECE_THROW_IN         => __( 'Inworp',          'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function sides(): array {
        return [
            self::SIDE_ATTACKING => __( 'Aanvallend', 'talenttrack' ),
            self::SIDE_DEFENDING => __( 'Verdedigend', 'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function stylesOfPlay(): array {
        return [
            self::STYLE_AANVALLEND_POSITIESPEL => __( 'Aanvallend positiespel', 'talenttrack' ),
            self::STYLE_DOMINANT_BALBEZIT      => __( 'Dominant balbezit',      'talenttrack' ),
            self::STYLE_REACTIEF_COUNTER       => __( 'Reactief / counter',     'talenttrack' ),
            self::STYLE_HIGH_PRESS             => __( 'High press',             'talenttrack' ),
            self::STYLE_PRAGMATISCH            => __( 'Pragmatisch',            'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    public static function lines(): array {
        return [
            self::LINE_AANVALLERS    => __( 'Aanvallers',    'talenttrack' ),
            self::LINE_MIDDENVELDERS => __( 'Middenvelders', 'talenttrack' ),
            self::LINE_VERDEDIGERS   => __( 'Verdedigers',   'talenttrack' ),
            self::LINE_KEEPER        => __( 'Keeper',        'talenttrack' ),
        ];
    }

    public static function isValidFunction( string $key ): bool { return array_key_exists( $key, self::teamFunctions() ); }
    public static function isValidTask( string $key ): bool     { return array_key_exists( $key, self::teamTasks() ); }
    public static function isValidKind( string $key ): bool     { return array_key_exists( $key, self::setPieceKinds() ); }
    public static function isValidSide( string $key ): bool     { return array_key_exists( $key, self::sides() ); }
    public static function isValidStyle( string $key ): bool    { return array_key_exists( $key, self::stylesOfPlay() ); }
}
