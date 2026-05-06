<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\TestTrainingsRepository;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * InviteToTestTrainingForm (#0081 child 2) — HoD picks or creates a
 * test-training session and composes the invitation to the parent.
 *
 * Two modes for the session:
 *   - "Pick an existing session" — dropdown of upcoming sessions
 *     (`tt_test_trainings.date >= today`).
 *   - "Schedule a new session" — date/time + location + age group +
 *     coach. Submits in the same POST so the HoD doesn't have to flip
 *     between forms.
 *
 * Message composer is a single textarea with sensible default copy
 * referencing the prospect's first name and the chosen session date.
 * Email/SMS dispatch is out of scope for child 2 — that ties into
 * #0066 (communication module). For now the form captures the
 * intended message and writes it to the response payload; PR 2b's
 * `ConfirmTestTrainingTemplate` will surface a copy-pasteable string
 * that the HoD can send manually until the comms module lands.
 */
class InviteToTestTrainingForm implements FormInterface {

    public function render( array $task ): string {
        $existing  = self::decodeResponse( $task );
        $disabled  = self::completedAttr( $task );
        $prospect  = self::prospectSummary( (int) ( $task['prospect_id'] ?? 0 ) );
        $sessions  = self::upcomingSessions();

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <?php if ( $prospect !== '' ) : ?>
                <p style="margin: 0 0 14px; font-weight: 600;">
                    <?php echo esc_html( sprintf( __( 'Prospect: %s', 'talenttrack' ), $prospect ) ); ?>
                </p>
            <?php endif; ?>

            <h3 style="margin:0 0 12px; font-size:1rem;"><?php esc_html_e( 'Choose or schedule a test-training session', 'talenttrack' ); ?></h3>

            <p style="margin: 0 0 6px;">
                <label for="tt-itt-existing"><?php esc_html_e( 'Existing upcoming session', 'talenttrack' ); ?></label>
            </p>
            <p>
                <select id="tt-itt-existing" name="test_training_id" <?php echo $disabled; ?> style="width:100%;">
                    <option value=""><?php esc_html_e( '— pick an upcoming session —', 'talenttrack' ); ?></option>
                    <?php foreach ( $sessions as $s ) : ?>
                        <option value="<?php echo esc_attr( (string) $s->id ); ?>"
                                <?php selected( (int) ( $existing['test_training_id'] ?? 0 ), (int) $s->id ); ?>>
                            <?php echo esc_html( self::sessionLabel( $s ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p style="margin: 12px 0 6px; color:#6b7280;">
                <?php esc_html_e( '— or schedule a new session below —', 'talenttrack' ); ?>
            </p>

            <p style="margin: 0 0 6px;">
                <label for="tt-itt-date"><?php esc_html_e( 'New session date + time', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="datetime-local" id="tt-itt-date" name="new_date"
                       value="<?php echo esc_attr( (string) ( $existing['new_date'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> />
            </p>

            <p style="margin: 12px 0 6px;">
                <label for="tt-itt-loc"><?php esc_html_e( 'Location', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="text" id="tt-itt-loc" name="new_location"
                       value="<?php echo esc_attr( (string) ( $existing['new_location'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> style="width:100%;" />
            </p>

            <h3 style="margin:18px 0 12px; font-size:1rem;"><?php esc_html_e( 'Invitation message to the parent', 'talenttrack' ); ?></h3>

            <p>
                <textarea name="invitation_message" rows="6" style="width:100%;"
                          <?php echo $disabled; ?>><?php
                    echo esc_textarea( (string) ( $existing['invitation_message'] ?? self::defaultMessage( $prospect ) ) );
                ?></textarea>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];

        $existing_id = isset( $raw['test_training_id'] ) ? (int) $raw['test_training_id'] : 0;
        $new_date    = trim( (string) ( $raw['new_date'] ?? '' ) );

        if ( $existing_id <= 0 && $new_date === '' ) {
            $errors['__form'] = __( 'Pick an existing session OR enter a new session date.', 'talenttrack' );
        }
        if ( $existing_id > 0 && $new_date !== '' ) {
            $errors['__form'] = __( 'Pick exactly one — an existing session or a new date, not both.', 'talenttrack' );
        }

        $msg = trim( (string) ( $raw['invitation_message'] ?? '' ) );
        if ( $msg === '' ) {
            $errors['invitation_message'] = __( 'Write a short message for the parent.', 'talenttrack' );
        }

        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $test_training_id = isset( $raw['test_training_id'] ) ? (int) $raw['test_training_id'] : 0;
        $new_date         = trim( (string) ( $raw['new_date'] ?? '' ) );

        // Schedule a new session if the HoD chose that path.
        if ( $test_training_id <= 0 && $new_date !== '' ) {
            $repo = new TestTrainingsRepository();
            $test_training_id = $repo->create( [
                'date'          => str_replace( 'T', ' ', $new_date ) . ':00',
                'location'      => sanitize_text_field( (string) ( $raw['new_location'] ?? '' ) ) ?: null,
                'coach_user_id' => (int) ( $task['assignee_user_id'] ?? get_current_user_id() ),
            ] );
        }

        return [
            'test_training_id'   => $test_training_id,
            'new_date'           => $new_date,
            'new_location'       => sanitize_text_field( (string) ( $raw['new_location'] ?? '' ) ),
            'invitation_message' => sanitize_textarea_field( (string) ( $raw['invitation_message'] ?? '' ) ),
        ];
    }

    /** @param array<string,mixed> $task */
    private static function decodeResponse( array $task ): array {
        $raw = (string) ( $task['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $task */
    private static function completedAttr( array $task ): string {
        return ( (string) ( $task['status'] ?? '' ) ) === 'completed' ? 'disabled' : '';
    }

    private static function prospectSummary( int $prospect_id ): string {
        if ( $prospect_id <= 0 ) return '';
        $repo = new ProspectsRepository();
        $row  = $repo->find( $prospect_id );
        if ( ! $row ) return '';
        $name = trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) );
        return $name . ( ! empty( $row->current_club ) ? ' (' . $row->current_club . ')' : '' );
    }

    /** @return object[] */
    private static function upcomingSessions(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, date, location FROM {$wpdb->prefix}tt_test_trainings
             WHERE club_id = %d AND archived_at IS NULL AND date >= %s
             ORDER BY date ASC LIMIT 50",
            CurrentClub::id(), gmdate( 'Y-m-d H:i:s' )
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function sessionLabel( object $row ): string {
        $when = isset( $row->date ) ? (string) $row->date : '';
        $where = isset( $row->location ) && $row->location !== '' ? ' — ' . $row->location : '';
        return $when . $where;
    }

    private static function defaultMessage( string $prospect_name ): string {
        // Translators: %s is the prospect's name.
        return sprintf(
            __( "Hi,\n\nWe'd like to invite %s to a test training session at our academy. Please let us know if you can attend.\n\nBest regards,\nThe academy", 'talenttrack' ),
            $prospect_name !== '' ? $prospect_name : __( 'your child', 'talenttrack' )
        );
    }
}
