<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;

/**
 * ActivityHeaderActions (#2685) — which run action an activity's detail
 * header offers, and what it is called.
 *
 * The header used to decide this inline, and decided it before it knew
 * the activity's status: Edit, match prep, the match-day CTA and the
 * training-run CTA were all pushed onto the list roughly a hundred lines
 * above the point where `activity_status_key` was read. A completed
 * training therefore invited a coach to run it again, and a played match
 * still offered "Start match".
 *
 * The rule the resolvers below share: **planned is the only status that
 * may mutate the record.** Anything else gets the read affordance for
 * work that exists, and nothing at all for work that doesn't — a
 * "View this training" on a training that never had a plan attached
 * leads to the attach form, which is the same invitation wearing a
 * different label.
 *
 * These are pure decisions on values the caller already holds, kept out
 * of the render method so the view composes rather than decides
 * (CLAUDE.md §4) and so each branch is testable without a page render.
 */
final class ActivityHeaderActions {

    /**
     * Match-prep label. The destination is the same in every case —
     * `FrontendMatchPrepView` renders the filled form or its own empty
     * state — so only the copy varies, and before #2685 it didn't:
     * "Plan match prep" was fixed, whether or not a prep row existed and
     * whether or not the match had already been played.
     */
    public static function matchPrepLabel( bool $is_planned, bool $has_prep ): string {
        if ( ! $is_planned ) return __( 'View match prep', 'talenttrack' );

        return $has_prep
            ? __( 'Match prep', 'talenttrack' )
            : __( 'Plan match prep', 'talenttrack' );
    }

    /**
     * Match-execution label, or `null` when no execution action should
     * render at all.
     *
     * On a planned match this is #1520's state machine unchanged:
     * live → resume, post-live → view, otherwise start but only on the
     * day (an off-day "Start match" would be a misleading CTA). Once the
     * activity leaves `planned` only the read label survives — "Start
     * match" used to fall through on any completed match whose date
     * still matched, offering a second kick-off.
     */
    public static function matchExecutionLabel( bool $is_planned, string $exec_state, bool $is_match_day ): ?string {
        if ( MatchExecutionState::isPostLive( $exec_state ) ) {
            return __( 'View match', 'talenttrack' );
        }
        if ( ! $is_planned ) {
            return null;
        }
        if ( MatchExecutionState::isLive( $exec_state ) ) {
            return __( 'Resume match', 'talenttrack' );
        }

        return $is_match_day ? __( 'Start match', 'talenttrack' ) : null;
    }

    /**
     * Training-run label, or `null` when the action should not render.
     *
     * A finished training with a plan attached is worth opening — that
     * is the plan that was run. A finished training with none has
     * nothing behind the button but the attach form, so it renders
     * nothing rather than inviting work on a closed record.
     */
    public static function trainingRunLabel( bool $is_planned, bool $has_run ): ?string {
        if ( ! $is_planned ) {
            return $has_run ? __( 'View this training', 'talenttrack' ) : null;
        }

        return $has_run
            ? __( 'Continue this training', 'talenttrack' )
            : __( 'Run this training', 'talenttrack' );
    }

    /**
     * Rating label for a completed activity, or `null` once there is
     * nothing left to rate — review then happens through the "Ratings
     * grid" button that sits in the same header.
     *
     * Takes an {@see ActivityRatingProgress} state rather than the
     * activity's status: `completed` says nothing about whether anyone
     * has been rated.
     */
    public static function ratingLabel( string $rating_state ): ?string {
        switch ( $rating_state ) {
            case ActivityRatingProgress::PARTIAL:
                return __( 'Continue rating', 'talenttrack' );
            case ActivityRatingProgress::NONE:
                return __( 'Rate players', 'talenttrack' );
            default:
                return null;
        }
    }
}
