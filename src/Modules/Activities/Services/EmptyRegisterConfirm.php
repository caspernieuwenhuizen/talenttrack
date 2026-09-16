<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EmptyRegisterConfirm (#3446, epic #3442) — the one wording of the
 * "you are about to complete this with nobody on the register" prompt,
 * plus the remedy it offers instead.
 *
 * Warn and confirm, not a hard block. Legitimate empty registers exist —
 * an Excel import, a club-wide activity with no roster, a match played
 * with a borrowed squad — and a rule that cannot be overridden turns
 * those into support tickets. The guard informs; the coach decides.
 *
 * The copy lives here rather than at each call site because three
 * surfaces raise the same prompt through two different dialog
 * mechanisms, and a message that drifts between them reads as three
 * different warnings about three different things.
 *
 * Whether it applies at all is {@see ActivityRegisterProgress}: only a
 * wholly empty register raises it. A partial one does not — recording
 * eight of fourteen players is a legitimate end state, and interrupting
 * it would train coaches to dismiss the dialog without reading it.
 */
final class EmptyRegisterConfirm {

    /** Should the completion of this activity be interrupted? */
    public static function applies( int $activity_id ): bool {
        return ActivityRegisterProgress::isEmpty( $activity_id );
    }

    public static function title(): string {
        return __( 'Nobody is marked present', 'talenttrack' );
    }

    public static function message(): string {
        return __( 'No attendance has been recorded for this activity. Completing it now means every player\'s participation for this date is missing from their record.', 'talenttrack' );
    }

    public static function confirmLabel(): string {
        return __( 'Complete anyway', 'talenttrack' );
    }

    public static function recordLabel(): string {
        return __( 'Record attendance', 'talenttrack' );
    }

    public static function cancelLabel(): string {
        return __( 'Cancel', 'talenttrack' );
    }

    /**
     * Where "Record attendance" goes: the attendance grid for this
     * activity's team, narrowed to its date — the destination the
     * wizard-off completion path already resolves to.
     *
     * Empty when the grid is switched off, the user can't reach it, or
     * the activity has no team. The dialog then offers Cancel and
     * "Complete anyway" only, rather than a button that dead-clicks
     * (CLAUDE.md §7 — hide the affordance, never dead-click it).
     */
    public static function recordUrl( int $activity_id, int $user_id ): string {
        if ( ! ActivityGridLink::canUseAttendance( $activity_id, $user_id ) ) return '';
        return ActivityGridLink::attendanceUrl( $activity_id );
    }

    /**
     * The guard's `data-tt-empty-register-*` attributes, ready to echo
     * into a submit button. `assets/js/activity-complete-guard.js` reads
     * them; the decision they encode was taken server-side, so the client
     * never asks the question, only renders the answer.
     *
     * Returns a leading-space-prefixed attribute string, already escaped.
     */
    public static function guardAttributes( int $activity_id, int $user_id ): string {
        $attrs = [
            'data-tt-empty-register-guard'   => '1',
            'data-tt-empty-register-title'   => self::title(),
            'data-tt-empty-register-message' => self::message(),
            'data-tt-empty-register-confirm' => self::confirmLabel(),
            'data-tt-empty-register-cancel'  => self::cancelLabel(),
        ];

        $url = self::recordUrl( $activity_id, $user_id );
        if ( $url !== '' ) {
            $attrs['data-tt-empty-register-alt-label'] = self::recordLabel();
        }

        $out = '';
        foreach ( $attrs as $key => $value ) {
            $out .= ' ' . $key . '="' . esc_attr( $value ) . '"';
        }
        if ( $url !== '' ) {
            $out .= ' data-tt-empty-register-alt-href="' . esc_url( $url ) . '"';
        }
        return $out;
    }
}
