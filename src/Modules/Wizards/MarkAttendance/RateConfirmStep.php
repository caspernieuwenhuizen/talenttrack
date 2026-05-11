<?php
namespace TT\Modules\Wizards\MarkAttendance;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * RateConfirmStep (#0092) — the at-the-pitch fork.
 *
 * Sits between AttendanceStep and RateActorsStep in the
 * `mark-attendance` wizard. The coach has already saved attendance
 * (AttendanceStep persisted real `tt_attendance` rows in its
 * validate()). This step asks the only useful question that's left:
 * "do you want to rate the players who were here, or are we done?"
 *
 *   - "Rate the present players" → nextStep returns `rate-actors`,
 *     dropping the coach into the existing roster-style rating UX.
 *   - "Skip rating, save attendance" → nextStep returns `null` so
 *     the framework calls submit(), which clears wizard state and
 *     redirects back to the activity detail page. No `tt_evaluations`
 *     rows are written.
 *
 * No persistence happens in this step's validate(). Attendance was
 * already written by AttendanceStep; evaluations are written by the
 * downstream ReviewStep if the coach proceeds.
 */
final class RateConfirmStep implements WizardStepInterface {

    public function slug(): string  { return 'rate-confirm'; }
    public function label(): string { return __( 'Rate now?', 'talenttrack' ); }

    public function render( array $state ): void {
        $aid     = (int) ( $state['activity_id'] ?? 0 );
        $present = self::countRatable( $aid );
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( "Attendance is saved. While you're here, do you want to rate the players who were present?", 'talenttrack' ); ?>
        </p>
        <?php if ( $present > 0 ) : ?>
            <p style="margin: var(--tt-sp-2, 12px) 0; color: var(--tt-muted);">
                <?php
                printf(
                    /* translators: %d: number of players present or late on the activity */
                    esc_html( _n( '%d player marked Present or Late.', '%d players marked Present or Late.', $present, 'talenttrack' ) ),
                    $present
                );
                ?>
            </p>
        <?php endif; ?>

        <div class="tt-rate-confirm-actions" style="display:flex; flex-direction:column; gap:12px; margin: var(--tt-sp-3, 16px) 0;">
            <button type="submit" name="_rate_choice" value="yes" class="tt-button tt-button-primary" style="min-height:56px; font-size:1.05rem;">
                <?php esc_html_e( 'Rate the present players', 'talenttrack' ); ?>
            </button>
            <button type="submit" name="_rate_choice" value="skip" class="tt-button tt-button-secondary" style="min-height:56px;" formnovalidate>
                <?php esc_html_e( 'Skip rating, save attendance', 'talenttrack' ); ?>
            </button>
        </div>
        <?php if ( $present === 0 ) : ?>
            <p class="tt-notice" role="status">
                <?php esc_html_e( 'Nobody was marked Present or Late, so there is nothing to rate. You can still proceed and finish without ratings.', 'talenttrack' ); ?>
            </p>
        <?php endif;
    }

    public function validate( array $post, array $state ) {
        $choice = isset( $post['_rate_choice'] ) ? sanitize_key( (string) $post['_rate_choice'] ) : '';
        $skip   = $choice !== 'yes';
        return [ '_skip_rating' => $skip ? 1 : 0 ];
    }

    public function nextStep( array $state ): ?string {
        if ( ! empty( $state['_skip_rating'] ) ) return null;
        return 'rate-actors';
    }

    /**
     * Skip path — wizard exits here. Attendance was already persisted by
     * AttendanceStep; no evaluation rows to write. Returns a redirect to
     * the activity's detail page so the coach lands on the surface
     * where they can edit attendance after the fact if needed.
     *
     * @return array<string,mixed>
     */
    public function submit( array $state ) {
        // v3.110.73 — respect `_done_redirect` from the wizard's initial
        // state so the coach returns to where they started the flow
        // (the dashboard hero, per MarkAttendanceWizard::initialState).
        // Falls back to the activity detail page when the hint isn't
        // set — keeps a sensible "see what you just saved" landing for
        // any future caller that uses this step without the hint.
        $override = isset( $state['_done_redirect'] ) ? (string) $state['_done_redirect'] : '';
        if ( $override !== '' ) {
            return [ 'redirect_url' => $override ];
        }
        $aid = (int) ( $state['activity_id'] ?? 0 );
        $url = $aid > 0
            ? add_query_arg( [ 'tt_view' => 'activities', 'id' => $aid ], WizardEntryPoint::dashboardBaseUrl() )
            : WizardEntryPoint::dashboardBaseUrl();
        return [ 'redirect_url' => $url ];
    }

    private static function countRatable( int $activity_id ): int {
        if ( $activity_id <= 0 ) return 0;
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_attendance
              WHERE activity_id = %d AND club_id = %d
                AND status IN ( 'present', 'late' )",
            $activity_id, CurrentClub::id()
        ) );
    }
}
