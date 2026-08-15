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
 * The cases:
 *
 *   - Match WITH a match-execution → the match-execution Resume /
 *     Finalize view; minutes come from finalize, so the wizard is not
 *     re-asked for them. Unaffected by the wizard toggle.
 *   - Training (non-match) → the unified evaluation wizard,
 *     `mode=activity` (attendance only + optional rating).
 *   - Match with NO match-execution ("paper match") → the same wizard;
 *     its AttendanceStep collects per-player minutes for match types.
 *   - #2401: either of the last two with the `new-evaluation` wizard
 *     switched OFF (`tt_wizards_enabled`) → the desktop attendance grid,
 *     filtered to this activity's column.
 *
 * That last branch is the fix for #2401. Before it, a wizard-off install
 * resolved to an EMPTY url, which — correctly per §7 — made every render
 * surface hide "Complete activity" entirely, leaving no completion
 * affordance at all. The wizard-off path now has a real destination.
 *
 * A paper match resolves to the ATTENDANCE grid, not the minutes grid:
 * attendance is what completion is about, and minutes stay reachable via
 * the detail page's own "Minutes grid" button.
 *
 * Status flips to `completed` at the flow's final save (wizard Review /
 * RateConfirm skip, or match-execution finalize) — or, on the wizard-off
 * path, via the explicit "Mark completed" action (#2407), because a grid
 * bulk-save spans many activities and must not complete them silently.
 * This resolver only produces the entry URL; it never mutates status.
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
            return $back_url !== '' ? BackLink::appendTo( $url ) : $url;
        }

        // #2401 — wizard off: the desktop attendance grid is the entry
        // surface, narrowed to this activity's column. Checked before the
        // wizard URL because `buildUrl()` would otherwise hand back an
        // empty string and the caller would hide the affordance.
        if ( ! self::wizardAvailable( get_current_user_id() ) ) {
            return ActivityGridLink::attendanceUrl( $activity_id, $back_url !== '' );
        }

        // Training + paper match → unified wizard, activity branch.
        $extra = [ 'mode' => 'activity', 'activity_id' => $activity_id, 'restart' => 1 ];
        $url   = WizardEntryPoint::buildUrl( 'new-evaluation', $extra );
        return $back_url !== '' ? BackLink::appendTo( $url ) : $url;
    }

    /**
     * Whether `$user_id` can actually reach the completion flow for this
     * activity — mirrors the gate the resolved destination enforces, so a
     * caller can hide the "Complete activity" affordance instead of
     * rendering a button whose URL resolves to empty (§7, #2325).
     *
     *  - Match WITH a match-execution → the finalize view (tt_edit_activities).
     *  - Training / paper match → the evaluation wizard, whose availability
     *    already folds in its `tt_edit_evaluations` cap + the wizard config;
     *    OR, when that wizard is switched off, the attendance grid (#2401).
     *
     * The `||` is the point: gating on the wizard alone meant switching
     * `tt_wizards_enabled` off hid the completion CTA on every surface.
     */
    public static function canComplete( int $activity_id, string $type_key, int $user_id ): bool {
        if ( $activity_id <= 0 || $user_id <= 0 ) {
            return false;
        }
        if ( self::routesToMatchExecution( $activity_id, $type_key ) ) {
            return \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_activities' );
        }
        if ( self::wizardAvailable( $user_id ) ) {
            return true;
        }
        return ActivityGridLink::canUseAttendance( $activity_id, $user_id );
    }

    /**
     * #2401 — the label for the completion affordance. With the wizard off
     * the button leads to the attendance grid, and "Complete activity"
     * would over-promise: recording attendance there does NOT complete the
     * activity (the separate "Mark completed" action does, #2407). Name the
     * action for what it actually does so the two buttons can't be
     * confused for each other.
     */
    public static function completionLabel( int $activity_id, string $type_key, int $user_id ): string {
        if ( self::routesToMatchExecution( $activity_id, $type_key ) || self::wizardAvailable( $user_id ) ) {
            return __( 'Complete activity', 'talenttrack' );
        }
        return __( 'Mark attendance', 'talenttrack' );
    }

    /**
     * #2401 — the single "is the guided completion flow on?" test. Folds
     * in both the `tt_edit_evaluations` cap and the `tt_wizards_enabled`
     * config. The REST status endpoint and the render surfaces share it so
     * the wizard-off completion path opens and closes in one place.
     */
    public static function wizardAvailable( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
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
