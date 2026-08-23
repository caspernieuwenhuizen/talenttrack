<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Wizards\Evaluation\RateActorsStep;

/**
 * ActivityRatingProgress (#2685) — how far the rating of one activity has
 * got, expressed as the three states the header action needs.
 *
 * `completed` does not imply rated: the wizard's rate step is per-player
 * skippable, match-execution finalize writes minutes and no ratings, and
 * the wizard-off "Mark completed" path is a bare status flip. So a
 * completed activity can hold anything from zero to a full set of
 * evaluations, and a header button that always reads "Continue rating"
 * claims work is in progress when none is.
 *
 * The rate roster itself already answers half of it —
 * `RateActorsStep::ratablePlayersForActivity()` returns the attending
 * (or on-pitch) players who have no evaluation row yet. Pairing that
 * count with a `COUNT(*)` of the activity's evaluations gives the three
 * states without a second roster materialisation. The decision lives
 * here rather than in the view so the label and any future consumer
 * (the list card, REST) can't drift (CLAUDE.md §4).
 */
final class ActivityRatingProgress {

    /** Nobody rated yet — there is rating work and none of it is done. */
    public const NONE = 'none';

    /** Some rated, some still outstanding. */
    public const PARTIAL = 'partial';

    /**
     * Nothing left to rate. Either every attending player carries an
     * evaluation, or there was never anyone to rate (no attendance
     * recorded, no lineup). Both cases want the same answer from the
     * header: don't offer a rating CTA.
     */
    public const COMPLETE = 'complete';

    /**
     * Per-request memo. The detail render asks once, but the same
     * activity can be composed twice on a page, and the answer costs
     * two queries.
     *
     * @var array<int, string>
     */
    private static array $memo = [];

    public static function state( int $activity_id ): string {
        if ( $activity_id <= 0 ) return self::COMPLETE;
        if ( isset( self::$memo[ $activity_id ] ) ) return self::$memo[ $activity_id ];

        $outstanding = count( RateActorsStep::ratablePlayersForActivity( $activity_id ) );
        if ( $outstanding === 0 ) {
            return self::$memo[ $activity_id ] = self::COMPLETE;
        }

        return self::$memo[ $activity_id ] = self::ratedCount( $activity_id ) > 0
            ? self::PARTIAL
            : self::NONE;
    }

    /** Whether a rating CTA should be offered at all. */
    public static function hasWorkLeft( int $activity_id ): bool {
        return self::state( $activity_id ) !== self::COMPLETE;
    }

    /**
     * Evaluations already written against this activity. Club-scoped
     * like every activity-side lookup; a `COUNT(*)` rather than a row
     * fetch because only "any?" is being asked.
     */
    private static function ratedCount( int $activity_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_evaluations
              WHERE activity_id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
    }
}
