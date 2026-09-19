<?php
namespace TT\Modules\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EvaluationDateRule (#3583) — which date an evaluation may carry.
 *
 * An evaluation records something that happened. A head coach saved two
 * training evaluations dated three days ahead; until he re-dated them they
 * sat in the player's rating trend and in evaluation coverage as if the
 * session had been held. Nothing checked, and nothing checked the format
 * either — whatever `sanitize_text_field` let through reached a DATE
 * column.
 *
 * So, on every write path — the REST routes, the wizard, the ratings grid,
 * the row endpoint, the Excel import, the wp-admin form:
 *
 *   - the date is a real `Y-m-d` date (`invalid_date`);
 *   - it is not after today in the site's timezone (`future_date`) — there
 *     is no forward-dated case: a multi-day tournament has one activity per
 *     game, so each game is evaluated on its own day;
 *   - when it is about an activity, the activity has happened
 *     (`future_date`), and the evaluation is not dated before it
 *     (`before_activity`).
 *
 * One place so every path gives the same answer.
 */
final class EvaluationDateRule {

    public const INVALID         = 'invalid_date';
    public const FUTURE          = 'future_date';
    public const BEFORE_ACTIVITY = 'before_activity';

    /**
     * Null when the date may be used, otherwise a 400 `WP_Error` saying why.
     *
     * @param string $eval_date     The date the evaluation would carry.
     * @param string $activity_date The linked activity's `session_date`, or ''.
     */
    public static function check( string $eval_date, string $activity_date = '' ): ?\WP_Error {
        if ( ! self::isDate( $eval_date ) ) {
            return new \WP_Error(
                self::INVALID,
                __( 'The evaluation date must be a real date, written as YYYY-MM-DD.', 'talenttrack' ),
                [ 'status' => 400 ]
            );
        }

        $today = current_time( 'Y-m-d' );

        if ( self::isDate( $activity_date ) && $activity_date > $today ) {
            return new \WP_Error(
                self::FUTURE,
                __( 'This activity has not happened yet, so it cannot be evaluated.', 'talenttrack' ),
                [ 'status' => 400 ]
            );
        }

        if ( $eval_date > $today ) {
            return new \WP_Error(
                self::FUTURE,
                __( 'An evaluation cannot be dated in the future.', 'talenttrack' ),
                [ 'status' => 400 ]
            );
        }

        if ( self::isDate( $activity_date ) && $eval_date < $activity_date ) {
            return new \WP_Error(
                self::BEFORE_ACTIVITY,
                __( 'An evaluation cannot be dated before the activity it is about.', 'talenttrack' ),
                [ 'status' => 400 ]
            );
        }

        return null;
    }

    /** A real calendar date written as `Y-m-d`. */
    public static function isDate( string $date ): bool {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /** The activity's `session_date` in this club, or '' when there is none. */
    public static function activityDate( int $activity_id ): string {
        if ( $activity_id <= 0 ) return '';
        global $wpdb;
        $p = $wpdb->prefix;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT session_date FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id,
            CurrentClub::id()
        ) );
    }
}
