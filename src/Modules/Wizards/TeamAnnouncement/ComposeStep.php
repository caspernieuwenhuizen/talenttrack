<?php
namespace TT\Modules\Wizards\TeamAnnouncement;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Send\MassAnnouncementSender;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — the words.
 *
 * Shows who it is going to, above the fields, because a sender who has
 * forgotten which squad they picked writes the wrong message. The count
 * belongs on the confirm step, not here: it is the thing being agreed
 * to, and repeating it beside a half-written draft makes it furniture.
 */
final class ComposeStep implements WizardStepInterface {

    public function slug(): string { return 'compose'; }

    public function label(): string { return __( 'Message', 'talenttrack' ); }

    public function render( array $state ): void {
        $sender  = new MassAnnouncementSender();
        $subject = (string) ( $state['subject'] ?? '' );
        $body    = (string) ( $state['body'] ?? '' );

        echo '<p class="tt-field-hint">' . esc_html( $sender->audienceLabel(
            (string) ( $state['scope'] ?? '' ),
            (int) ( $state['team_id'] ?? 0 ),
            (string) ( $state['age_group'] ?? '' )
        ) ) . '</p>';
        ?>
        <div class="tt-field">
            <label class="tt-field-label tt-field-required" for="tt-announce-subject">
                <?php esc_html_e( 'Subject', 'talenttrack' ); ?>
            </label>
            <input type="text" id="tt-announce-subject" class="tt-input" name="subject" required
                   autocomplete="off" enterkeyhint="next"
                   maxlength="<?php echo esc_attr( (string) MassAnnouncementSender::MAX_SUBJECT ); ?>"
                   value="<?php echo esc_attr( $subject ); ?>" />
            <small class="tt-field-hint"><?php esc_html_e( 'This is the line a parent sees in their inbox. Say what happened, not that there is news.', 'talenttrack' ); ?></small>
        </div>

        <div class="tt-field">
            <label class="tt-field-label tt-field-required" for="tt-announce-body">
                <?php esc_html_e( 'Message', 'talenttrack' ); ?>
            </label>
            <textarea id="tt-announce-body" class="tt-input" name="body" rows="10" required><?php echo esc_textarea( $body ); ?></textarea>
            <small class="tt-field-hint"><?php esc_html_e( 'Plain text. Nobody can reply to it, so include anything they would ask.', 'talenttrack' ); ?></small>
        </div>
        <?php
    }

    public function validate( array $post, array $state ) {
        $subject = isset( $post['subject'] ) ? sanitize_text_field( wp_unslash( (string) $post['subject'] ) ) : '';
        $body    = isset( $post['body'] ) ? sanitize_textarea_field( wp_unslash( (string) $post['body'] ) ) : '';

        $subject = trim( $subject );
        $body    = trim( $body );

        if ( $subject === '' ) {
            return new \WP_Error( 'no_subject', __( 'Give the announcement a subject.', 'talenttrack' ) );
        }
        if ( mb_strlen( $subject ) > MassAnnouncementSender::MAX_SUBJECT ) {
            return new \WP_Error(
                'subject_too_long',
                sprintf(
                    /* translators: %d: the maximum number of characters allowed in a subject */
                    __( 'Keep the subject to %d characters or fewer.', 'talenttrack' ),
                    MassAnnouncementSender::MAX_SUBJECT
                )
            );
        }
        if ( $body === '' ) {
            return new \WP_Error( 'no_body', __( 'Write the message before continuing.', 'talenttrack' ) );
        }

        return [ 'subject' => $subject, 'body' => $body ];
    }

    public function nextStep( array $state ): ?string { return 'confirm'; }

    public function submit( array $state ) { return null; }
}
