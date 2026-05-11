<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * AttendanceStep (#0072) — captures attendance for the activity if it
 * isn't already recorded. Skipped silently when `tt_attendance` already
 * has rows for the picked activity.
 *
 * Writes are real `tt_attendance` rows — not a wizard-only side store —
 * so revisiting the activity later shows the attendance as expected.
 * Only `present` and `late` players flow forward to RateActorsStep.
 */
final class AttendanceStep implements WizardStepInterface {

    public function slug(): string  { return 'attendance'; }
    public function label(): string { return __( 'Attendance', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        if ( ( $state['_path'] ?? '' ) !== 'activity-first' ) return true;
        $aid = (int) ( $state['activity_id'] ?? 0 );
        if ( $aid <= 0 ) return true;
        // v3.110.73 — mark-attendance wizard sets this so the step always
        // renders (coach explicitly wants the roster, even if rows exist).
        // The eval wizard leaves it unset and keeps the original
        // "skip when already recorded" optimisation.
        if ( ! empty( $state['_attendance_force_render'] ) ) return false;
        return self::activityHasAttendance( $aid );
    }

    public function render( array $state ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $aid = (int) ( $state['activity_id'] ?? 0 );

        $team_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $aid, CurrentClub::id()
        ) );

        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name FROM {$p}tt_players
              WHERE team_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY last_name, first_name",
            $team_id, CurrentClub::id()
        ) );

        $statuses = $wpdb->get_results( $wpdb->prepare(
            "SELECT name FROM {$p}tt_lookups WHERE lookup_type = 'attendance_status' AND club_id = %d ORDER BY sort_order",
            CurrentClub::id()
        ) );
        $names = array_map( static fn( $r ) => (string) $r->name, (array) $statuses );
        if ( empty( $names ) ) $names = [ 'present', 'late', 'absent', 'excused' ];

        // v3.110.73 — pre-fill the radio matrix from existing `tt_attendance`
        // rows so a coach who re-enters the mark-attendance wizard for an
        // activity that's already been processed sees the current saved
        // state, not a roster reset to "all present". Wizard state takes
        // precedence (in-flight edits within the same wizard run); the
        // existing rows are the fallback.
        $existing_by_player = [];
        $existing_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT player_id, status FROM {$p}tt_attendance
              WHERE activity_id = %d AND club_id = %d",
            $aid, CurrentClub::id()
        ) );
        foreach ( (array) $existing_rows as $row ) {
            $existing_by_player[ (int) $row->player_id ] = (string) $row->status;
        }
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( 'Mark each player\'s attendance. Only present + late players will appear in the rating step. This step writes real attendance rows for the activity.', 'talenttrack' ); ?>
        </p>
        <p style="margin: 0 0 var(--tt-sp-2);">
            <?php
            // v3.92.4 — operator asked for a single-click "Mark all present"
            // affordance. Default is already 'present', but coaches who
            // have started clicking individual rows need a fast way to
            // reset everyone before submitting. Pure JS toggle, scoped
            // to this step's radio matrix.
            ?>
            <button type="button" class="tt-button tt-button-secondary" data-tt-mark-all-present>
                <?php esc_html_e( 'Mark all present', 'talenttrack' ); ?>
            </button>
        </p>
        <script>
        (function () {
            var btn = document.querySelector( '[data-tt-mark-all-present]' );
            if ( ! btn ) return;
            btn.addEventListener( 'click', function () {
                var radios = document.querySelectorAll( 'input[type=radio][name^="attendance["][value="present"]' );
                for ( var i = 0; i < radios.length; i++ ) {
                    radios[ i ].checked = true;
                }
            } );
        })();
        </script>
        <table class="tt-table" style="width:100%;">
            <thead><tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><?php foreach ( $names as $n ) : ?><th style="text-align:center;"><?php echo esc_html( \TT\Infrastructure\Query\LabelTranslator::attendanceStatus( ucfirst( $n ) ) ); ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ( (array) $players as $pl ) :
                    // v3.110.73 — fallback chain: in-flight wizard state →
                    // existing `tt_attendance` row → 'present' default.
                    $pid_int = (int) $pl->id;
                    $row_default = (string) (
                        $state['attendance'][ $pid_int ]
                        ?? $existing_by_player[ $pid_int ]
                        ?? 'present'
                    );
                    ?>
                    <tr>
                        <td><?php echo esc_html( trim( (string) $pl->first_name . ' ' . (string) $pl->last_name ) ); ?></td>
                        <?php foreach ( $names as $n ) : ?>
                            <td style="text-align:center;">
                                <input type="radio" name="attendance[<?php echo (int) $pl->id; ?>]" value="<?php echo esc_attr( $n ); ?>" <?php checked( $row_default, $n ); ?> />
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function validate( array $post, array $state ) {
        $att = isset( $post['attendance'] ) && is_array( $post['attendance'] )
            ? array_map( 'sanitize_key', wp_unslash( $post['attendance'] ) )
            : [];

        // Persist attendance rows now — this is a real attendance update.
        $aid = (int) ( $state['activity_id'] ?? 0 );
        if ( $aid > 0 && ! empty( $att ) ) {
            global $wpdb;
            $p = $wpdb->prefix;
            foreach ( $att as $player_id => $status ) {
                $player_id = (int) $player_id;
                if ( $player_id <= 0 ) continue;
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_attendance WHERE activity_id = %d AND player_id = %d AND club_id = %d LIMIT 1",
                    $aid, $player_id, CurrentClub::id()
                ) );
                if ( $existing ) {
                    $wpdb->update( "{$p}tt_attendance", [ 'status' => $status ], [ 'id' => (int) $existing ] );
                } else {
                    $wpdb->insert( "{$p}tt_attendance", [
                        'club_id'     => CurrentClub::id(),
                        'activity_id' => $aid,
                        'player_id'   => $player_id,
                        'status'      => $status,
                    ] );
                }
            }

            // v3.110.73 — recording attendance implies the activity
            // happened. Flip `activity_status_key` and `plan_state` to
            // 'completed' so:
            //   - the activity edit form's attendance section is no
            //     longer hidden (gated on `activity_status_key === 'completed'`)
            //   - the planner module's "what happened this week" query
            //     sees the row (mirrors the auto-transition in
            //     `ActivitiesRestController` at line 626-640)
            // Skip the flip if the activity is already `completed` or
            // `cancelled` — coach has been explicit about the state and
            // we shouldn't override.
            $current_status = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT activity_status_key FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
                $aid, CurrentClub::id()
            ) );
            if ( $current_status !== 'completed' && $current_status !== 'cancelled' ) {
                $wpdb->update(
                    "{$p}tt_activities",
                    [
                        'activity_status_key' => 'completed',
                        'plan_state'          => 'completed',
                    ],
                    [ 'id' => $aid, 'club_id' => CurrentClub::id() ]
                );
            }
        }

        return [ 'attendance' => $att ];
    }

    public function nextStep( array $state ): ?string {
        // #0092 — wizards using this step can route the next step via
        // state. Default 'rate-actors' keeps the new-evaluation chain
        // unchanged; mark-attendance sets `_attendance_next = 'rate-confirm'`.
        $hint = isset( $state['_attendance_next'] ) ? (string) $state['_attendance_next'] : '';
        return $hint !== '' ? $hint : 'rate-actors';
    }
    public function submit( array $state ) { return null; }

    private static function activityHasAttendance( int $activity_id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}tt_attendance WHERE activity_id = %d AND club_id = %d LIMIT 1",
            $activity_id, CurrentClub::id()
        ) );
    }
}
