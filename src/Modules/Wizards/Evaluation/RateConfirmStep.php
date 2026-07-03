<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * RateConfirmStep (#0092, relocated to the Evaluation namespace in
 * #2249) — the "rate now?" fork on the unified activity path.
 *
 * Sits between AttendanceStep and RateActorsStep. The coach has already
 * saved attendance (AttendanceStep persisted real `tt_attendance` rows
 * in its validate()). This step asks the only useful question left:
 * "do you want to rate the players who were here, or are we done?"
 *
 *   - "Rate the present players" → nextStep returns `rate-actors`,
 *     dropping the coach into the roster-style rating UX.
 *   - "Skip rating" → nextStep returns `null` so the framework calls
 *     submit(), which flips the activity to completed, clears wizard
 *     state, and redirects. No `tt_evaluations` rows are written.
 *
 * No persistence happens in this step's validate(). Attendance was
 * already written by AttendanceStep; evaluations are written by the
 * downstream ReviewStep if the coach proceeds.
 *
 * Applies only on the activity path (`_path = 'activity-first'`); the
 * player path never routes through here.
 */
final class RateConfirmStep implements WizardStepInterface {

    public function slug(): string  { return 'rate-confirm'; }
    public function label(): string { return __( 'Rate now?', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        return ( $state['_path'] ?? '' ) !== 'activity-first';
    }

    public function render( array $state ): void {
        if ( defined( 'TT_PLUGIN_URL' ) && defined( 'TT_VERSION' ) ) {
            wp_enqueue_style( 'tt-rate-confirm', TT_PLUGIN_URL . 'assets/css/rate-confirm.css', [], TT_VERSION );
        }
        $aid     = (int) ( $state['activity_id'] ?? 0 );
        $present = self::countRatable( $aid );
        ?>
        <p class="tt-rate-confirm-intro">
            <?php esc_html_e( "Attendance is saved. While you're here, do you want to rate the players who were present?", 'talenttrack' ); ?>
        </p>
        <?php if ( $present > 0 ) : ?>
            <p class="tt-rate-confirm-count">
                <?php
                printf(
                    /* translators: %d: number of players present or late on the activity */
                    esc_html( _n( '%d player marked Present or Late.', '%d players marked Present or Late.', $present, 'talenttrack' ) ),
                    $present
                );
                ?>
            </p>
        <?php endif; ?>

        <div class="tt-rate-confirm-actions">
            <button type="submit" name="_rate_choice" value="yes" class="tt-button tt-button-primary tt-rate-confirm-btn">
                <?php esc_html_e( 'Rate the present players', 'talenttrack' ); ?>
            </button>
            <button type="submit" name="_rate_choice" value="skip_open" class="tt-button tt-button-secondary tt-rate-confirm-btn" formnovalidate>
                <?php esc_html_e( "Skip rating — I'll rate later", 'talenttrack' ); ?>
            </button>
            <button type="submit" name="_rate_choice" value="skip_closed" class="tt-button tt-button-secondary tt-rate-confirm-btn" formnovalidate>
                <?php esc_html_e( 'Skip rating — no rating needed', 'talenttrack' ); ?>
            </button>
            <p class="tt-rate-confirm-hint">
                <?php esc_html_e( "I'll rate later: activity stays available for rating from the eval wizard. No rating needed: activity is closed and won't appear in the rating picker anymore (can be re-opened from the activity detail).", 'talenttrack' ); ?>
            </p>
        </div>
        <?php if ( $present === 0 ) : ?>
            <p class="tt-notice" role="status">
                <?php esc_html_e( 'Nobody was marked Present or Late, so there is nothing to rate. You can still proceed and finish without ratings.', 'talenttrack' ); ?>
            </p>
        <?php endif;
    }

    public function validate( array $post, array $state ) {
        $choice = isset( $post['_rate_choice'] ) ? sanitize_key( (string) $post['_rate_choice'] ) : '';
        // Three-way choice: "skip_closed" sets `evaluation_skipped=1` on
        // the activity so the eval-wizard's picker filters it out;
        // "skip_open" keeps the activity rateable; "yes" advances.
        $closed = $choice === 'skip_closed';
        $skip   = $choice !== 'yes';
        return [
            '_skip_rating'        => $skip ? 1 : 0,
            '_skip_closes_rating' => $closed ? 1 : 0,
        ];
    }

    public function nextStep( array $state ): ?string {
        if ( ! empty( $state['_skip_rating'] ) ) return null;
        return 'rate-actors';
    }

    /**
     * Skip path — wizard exits here. Attendance was already persisted by
     * AttendanceStep; no evaluation rows to write. Flips the activity to
     * completed and redirects.
     *
     * @return array<string,mixed>
     */
    public function submit( array $state ) {
        $aid = (int) ( $state['activity_id'] ?? 0 );

        if ( $aid > 0 ) {
            AttendanceStep::completeActivityIfNotTerminal( $aid );

            if ( ! empty( $state['_skip_closes_rating'] ) ) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'tt_activities',
                    [ 'evaluation_skipped' => 1 ],
                    [ 'id' => $aid, 'club_id' => CurrentClub::id() ]
                );
            }
        }

        $override = isset( $state['_done_redirect'] ) ? (string) $state['_done_redirect'] : '';
        if ( $override !== '' ) {
            return [ 'redirect_url' => $override ];
        }
        $url = $aid > 0
            ? add_query_arg( [ 'tt_view' => 'activities', 'id' => $aid ], WizardEntryPoint::currentDashboardUrl() )
            : WizardEntryPoint::currentDashboardUrl();
        return [ 'redirect_url' => $url ];
    }

    private static function countRatable( int $activity_id ): int {
        if ( $activity_id <= 0 ) return 0;
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_attendance
              WHERE activity_id = %d AND club_id = %d
                AND LOWER(status) IN ( 'present', 'late' )",
            $activity_id, CurrentClub::id()
        ) );
    }
}
