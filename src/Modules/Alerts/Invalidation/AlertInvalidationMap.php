<?php
namespace TT\Modules\Alerts\Invalidation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AlertInvalidationMap (#2731, epic #2629) — which domain event touches
 * which subject.
 *
 * The engine resolves an alert when a sweep no longer sees the condition.
 * Waiting an hour for that sweep is what this class removes: a domain event
 * says "activity 41 changed", the map turns that into
 * `('activity', [41])`, and only the definitions about activities re-run,
 * against only that activity.
 *
 * ## Why a central map rather than a method on the definition
 *
 * Both were on the table. A per-definition `invalidatedBy()` is more
 * self-contained, but it spreads the answer to "is every alert in this
 * catalogue actually covered?" across sixteen files, and that is the
 * question anyone maintaining this will ask. One table answers it by
 * reading. Definitions stay untouched.
 *
 * The tradeoff is that the default table below names other modules' hooks,
 * which is coupling the definitions would not have had. The
 * `tt_alert_invalidation_map` filter is what pays it back: a module — or a
 * plugin registering its own alert through `tt_register_alerts` — adds its
 * triggers the same way it adds the definition, and nothing here changes.
 *
 * ## The extractor contract
 *
 * A value is a callable receiving the hook's own arguments and returning
 * either one `[ subjectType, ids ]` pair or a list of them. Returning
 * several is normal: saving an evaluation changes both an evaluation and
 * the player it is about, and definitions exist for both subjects.
 *
 * An extractor MUST be cheap — it runs inside the save request, before the
 * response, and its only job is to name ids. Anything that needs a query
 * belongs in the definition's `evaluate()`, which runs after the response
 * has been flushed.
 *
 * Returning an empty list is the way to say "nothing to invalidate". An
 * extractor that throws is caught and logged; one bad extractor must not
 * be able to break the save it was listening to.
 */
final class AlertInvalidationMap {

    /** @var array<string,callable>|null */
    private static $cache = null;

    /**
     * The hook => extractor table, filtered.
     *
     * @return array<string,callable>
     */
    public static function all(): array {
        if ( self::$cache !== null ) return self::$cache;

        $map = apply_filters( 'tt_alert_invalidation_map', self::defaults() );
        $out = [];
        foreach ( is_array( $map ) ? $map : [] as $hook => $extractor ) {
            if ( ! is_string( $hook ) || $hook === '' ) continue;
            if ( ! is_callable( $extractor ) ) continue;
            $out[ $hook ] = $extractor;
        }

        self::$cache = $out;
        return $out;
    }

    /** Drop the per-request cache. Tests use this. */
    public static function flush(): void {
        self::$cache = null;
    }

    /**
     * Everything the shipped catalogue needs.
     *
     * Each entry is annotated with the definitions it keeps fresh, because
     * the failure mode of this table is silent: delete a row and the alert
     * it served simply goes back to being an hour stale, with nothing
     * failing anywhere.
     *
     * Argument shapes vary — `tt_evaluation_saved` passes the player first
     * and the evaluation second, `tt_activity_completed` passes a
     * `TaskContext` object — which is exactly why this is a table of
     * callables and not a table of positions.
     *
     * @return array<string,callable>
     */
    private static function defaults(): array {
        return [

            // ── Activities ────────────────────────────────────────────
            // activities.past_still_planned, activities.attendance_unrecorded,
            // activities.no_coach_assigned.

            /** @param object $ctx TaskContext */
            'tt_activity_completed' => static function ( $ctx ): array {
                $id = is_object( $ctx ) ? (int) ( $ctx->activity_id ?? 0 ) : (int) $ctx;
                return [ [ 'activity', [ $id ] ] ];
            },

            'tt_activity_status_changed' => static function ( $activity_id ): array {
                return [ [ 'activity', [ (int) $activity_id ] ] ];
            },

            // #2731 — added with this issue; the activity form is where a
            // coach gets assigned and where a date moves.
            'tt_activity_saved' => static function ( $activity_id ): array {
                return [ [ 'activity', [ (int) $activity_id ] ] ];
            },

            // #2731 — added with this issue. The driver case: recording
            // attendance is the single most-performed fix in the catalogue.
            'tt_activity_attendance_changed' => static function ( $activity_id ): array {
                return [ [ 'activity', [ (int) $activity_id ] ] ];
            },

            // ── Evaluations ───────────────────────────────────────────
            // evaluations.player_not_evaluated and .window_closing are about
            // the player; .saved_not_shared is about the evaluation row.

            'tt_evaluation_saved' => static function ( $player_id, $evaluation_id = 0 ): array {
                return [
                    [ 'player',     [ (int) $player_id ] ],
                    [ 'evaluation', [ (int) $evaluation_id ] ],
                ];
            },

            // ── Goals ─────────────────────────────────────────────────
            // goals.past_target_date.

            'tt_goal_saved' => static function ( $player_id, $goal_id = 0 ): array {
                return [
                    [ 'goal',   [ (int) $goal_id ] ],
                    [ 'player', [ (int) $player_id ] ],
                ];
            },

            'tt_goal_status_changed' => static function ( $goal_id ): array {
                return [ [ 'goal', [ (int) $goal_id ] ] ];
            },

            // ── PDP ───────────────────────────────────────────────────
            // pdp.no_conversation_this_cycle. Added with this issue.

            'tt_pdp_conversation_saved' => static function ( $conversation_id, $pdp_file_id = 0 ): array {
                return [ [ 'pdp_file', [ (int) $pdp_file_id ] ] ];
            },

            // ── People / onboarding ───────────────────────────────────
            // people.parent_never_activated, onboarding.invitation_stale
            // (both subject `invitation`); people.player_turns_18 and
            // dataquality.player_without_team (subject `player`).

            'tt_invitation_accepted' => static function ( $invitation_id ): array {
                return [ [ 'invitation', [ (int) $invitation_id ] ] ];
            },

            'tt_invitation_revoked' => static function ( $invitation_id ): array {
                return [ [ 'invitation', [ (int) $invitation_id ] ] ];
            },

            'tt_after_player_save' => static function ( $player_id ): array {
                return [ [ 'player', [ (int) $player_id ] ] ];
            },

            // people.staff_certificate_expiring. Added with this issue.
            'tt_staff_certification_saved' => static function ( $certification_id ): array {
                return [ [ 'staff_certification', [ (int) $certification_id ] ] ];
            },

            // ── Measurements ──────────────────────────────────────────
            // measurements.none_this_season — subject is the player, not
            // the result. Added with this issue.

            'tt_measurement_result_saved' => static function ( $result_id, $player_id = 0 ): array {
                return [ [ 'player', [ (int) $player_id ] ] ];
            },

            // ── Data quality ──────────────────────────────────────────
            // dataquality.team_without_head_coach. The existing hook passes
            // the team first; assigning a head coach is what clears it.

            'tt_person_assigned_to_team' => static function ( $team_id ): array {
                return [ [ 'team', [ (int) $team_id ] ] ];
            },
        ];
    }
}
