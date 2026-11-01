<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The sections of the settings screen, in the order they show. A block
 * names one of these as its group.
 */
final class WeekBlockGroup {

    public const SCOPE    = 'scope';
    public const ROW      = 'row';
    public const TRAINING = 'training';
    public const MATCH    = 'match';
    public const ACTIONS  = 'actions';

    /** @return array<string, string> group key => translated heading, in display order */
    public static function labels(): array {
        return [
            self::SCOPE    => __( 'Which week, which teams', 'talenttrack' ),
            self::ROW      => __( 'On every row', 'talenttrack' ),
            self::TRAINING => _x( 'Trainings', 'This week panel settings group', 'talenttrack' ),
            self::MATCH    => __( 'Matches', 'talenttrack' ),
            self::ACTIONS  => __( 'Buttons on a row', 'talenttrack' ),
        ];
    }

    /** @return list<string> */
    public static function keys(): array {
        return array_keys( self::labels() );
    }
}
