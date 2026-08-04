<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * ActivityCompletionResolver (#2245) — decides where the "Complete
 * activity" action routes for a given activity, and keeps that
 * type-branch decision in the domain layer (§4) so the list-card button
 * and the detail-view button both resolve it identically.
 *
 * The three cases:
 *
 *   - Training (non-match) → the unified evaluation wizard,
 *     `mode=activity` (attendance only + optional rating).
 *   - Match with NO match-execution ("paper match") → the same wizard;
 *     its AttendanceStep collects per-player minutes for match types.
 *   - Match WITH a match-execution → the match-execution Resume /
 *     Finalize view; minutes come from finalize, so the wizard is not
 *     re-asked for them.
 *
 * Status flips to `completed` only at the flow's final save (wizard
 * Review / RateConfirm skip, or match-execution finalize). This
 * resolver only produces the entry URL; it never mutates status.
 */
final class ActivityCompletionResolver {

    /** Match-family activity types (both taxonomies). */
    private const MATCH_TYPES = [ 'game', 'match', 'friendly', 'tournament' ];

    /**
     * Resolve the "Complete activity" destination URL for an activity.
     *
     * @param int    $activity_id
     * @param string $type_key   `tt_activities.activity_type_key`
     * @param string $back_url   Optional back-target to carry through so
     *                           the destination can render a `← Back`
     *                           pill (CLAUDE.md §5). Empty = dashboard.
     */
    public static function completionUrl( int $activity_id, string $type_key, string $back_url = '' ): string {
        if ( $activity_id <= 0 ) {
            return RecordLink::dashboardUrl();
        }

        if ( self::routesToMatchExecution( $activity_id, $type_key ) ) {
            $url = add_query_arg(
                [ 'tt_view' => 'match-execution', 'activity_id' => $activity_id ],
                RecordLink::dashboardUrl()
            );
            return $back_url !== '' ? BackLink::appendTo( $url, $back_url ) : $url;
        }

        // Training + paper match → unified wizard, activity branch.
        $extra = [ 'mode' => 'activity', 'activity_id' => $activity_id, 'restart' => 1 ];
        $url   = WizardEntryPoint::buildUrl( 'new-evaluation', $extra );
        return $back_url !== '' ? BackLink::appendTo( $url, $back_url ) : $url;
    }

    /**
     * Whether `$user_id` can actually reach the completion flow for this
     * activity — mirrors the gate the resolved destination enforces, so a
     * caller can hide the "Complete activity" affordance instead of
     * rendering a button whose URL resolves to empty (§7, #2325).
     *
     *  - Match WITH a match-execution → the finalize view (tt_edit_activities).
     *  - Training / paper match → the evaluation wizard, whose availability
     *    already folds in its `tt_edit_evaluations` cap + the wizard config.
     */
    public static function canComplete( int $activity_id, string $type_key, int $user_id ): bool {
        if ( $activity_id <= 0 || $user_id <= 0 ) {
            return false;
        }
        if ( self::routesToMatchExecution( $activity_id, $type_key ) ) {
            return \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_activities' );
        }
        return \TT\Shared\Wizards\WizardRegistry::isAvailable( 'new-evaluation', $user_id );
    }

    /**
     * True when the activity is a match type AND a match-execution row
     * already exists — in which case completion runs through the
     * execution's Resume/Finalize flow (the minutes source) rather than
     * the attendance wizard.
     */
    public static function routesToMatchExecution( int $activity_id, string $type_key ): bool {
        if ( ! self::isMatchType( $type_key ) ) return false;
        if ( $activity_id <= 0 ) return false;
        $exec = ( new MatchExecutionRepository() )->findByActivity( $activity_id );
        return $exec !== null;
    }

    public static function isMatchType( string $type_key ): bool {
        return in_array( strtolower( trim( $type_key ) ), self::MATCH_TYPES, true );
    }
}
