<?php
namespace TT\Modules\Vct\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LoadRestriction — the vocabulary of a player's load restriction.
 *
 * `tt_player_phv_flags` records that a player must carry less load than
 * the plan asks for, with a reason and an optional intensity ceiling.
 * A growth spurt (peak height velocity) is **one** reason for that; the
 * table was named after it and the UI followed, so an ankle sprain came
 * out on the hero labelled "PHV". The restriction is what the flag
 * states; the growth spurt sits in the reason list beside the others.
 *
 * The keys are the ones already in the column — `growth_spurt` is added
 * to the list, nothing is renamed, so no row had to move.
 *
 * The reason picker and the write-path whitelist were two hardcoded
 * copies of the same enum, in two methods, 140 lines apart. They read
 * this one array now.
 */
final class LoadRestriction {

    /**
     * Reason keys in picker order, each with its label.
     *
     * @return array<string, string>
     */
    public static function reasons(): array {
        return [
            'growth_spurt'  => __( 'Growth spurt (PHV)', 'talenttrack' ),
            'injury_knee'   => __( 'Injury — knee', 'talenttrack' ),
            'injury_ankle'  => __( 'Injury — ankle', 'talenttrack' ),
            'asthma'        => __( 'Asthma', 'talenttrack' ),
            'cardiac'       => __( 'Cardiac condition', 'talenttrack' ),
            'other_medical' => __( 'Other medical reason', 'talenttrack' ),
            'temp_fatigue'  => __( 'Temporary fatigue', 'talenttrack' ),
        ];
    }

    /**
     * The write-path whitelist: exactly the keys the picker offers.
     *
     * @return list<string>
     */
    public static function reasonKeys(): array {
        return array_keys( self::reasons() );
    }

    public static function isReason( string $key ): bool {
        return $key !== '' && isset( self::reasons()[ $key ] );
    }

    /**
     * The reason in words a coach recognises, or an empty string for a
     * key that is not offered any more (a stored value we no longer
     * render is better left unlabelled than shown raw).
     */
    public static function reasonLabel( string $key ): string {
        return self::reasons()[ $key ] ?? '';
    }

    /**
     * The intensity bands a restriction may cap a player at.
     *
     * @return array<int, string>
     */
    public static function ceilings(): array {
        return [
            1 => __( '1 — recovery only', 'talenttrack' ),
            2 => __( '2 — low', 'talenttrack' ),
            3 => __( '3 — medium', 'talenttrack' ),
            4 => __( '4 — high', 'talenttrack' ),
        ];
    }

    /**
     * The neutral name of the flag, used on the panel, the hero pill and
     * the sideline banner so one screen never contradicts another.
     */
    public static function label(): string {
        return _x( 'Load restriction', 'player load flag', 'talenttrack' );
    }
}
