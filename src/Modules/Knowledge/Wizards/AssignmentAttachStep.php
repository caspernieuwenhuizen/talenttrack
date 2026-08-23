<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\SubmissionAttachments;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — supporting documents (#2648).
 *
 * Optional, and says so. Most assignments in the periodisation course are
 * answered in the box on the previous step; a document is for the coach who
 * built their twelve-week plan in a spreadsheet and would rather hand that
 * in than retype it.
 *
 * Documents only — no photographs, no video. A submission hangs off no
 * player and no team, so an image attached here would sit outside the
 * consent and visibility rules that govern player media, and a photograph
 * taken at a training can hold minors. `MediaAttachmentPolicy` is where
 * that is decided and enforced; the uploader reads it, which is why it
 * offers no camera on this step.
 *
 * Uploads commit as they happen, against the draft opened in step 1 — see
 * `SubmitAssignmentWizard` for why they cannot wait for the end.
 */
final class AssignmentAttachStep implements WizardStepInterface {

    public function slug(): string { return 'attach'; }

    public function label(): string { return __( 'Documents', 'talenttrack' ); }

    public function render( array $state ): void {
        $submission_id = (int) ( $state['submission_id'] ?? 0 );

        if ( $submission_id <= 0 ) {
            echo '<p>' . esc_html__( 'Go back and write your answer first.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<p>' . esc_html__( 'Attach a plan, a spreadsheet or any other document that supports your answer. This step is optional.', 'talenttrack' ) . '</p>';

        $existing = SubmissionAttachments::renderList( $submission_id );
        if ( $existing !== '' ) {
            echo $existing; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the component.
        }

        echo SubmissionAttachments::renderUploader( $submission_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the component.

        echo '<p class="description">'
            . esc_html__( 'Documents are saved as soon as they finish uploading, so you can go back a step without losing them.', 'talenttrack' )
            . '</p>';
    }

    /**
     * Nothing to validate: the step is optional and the uploads have
     * already committed. The count is read from the database rather than
     * from the form, so the confirm step reports what is actually attached
     * rather than what a hidden field claims.
     */
    public function validate( array $post, array $state ) {
        $submission_id = (int) ( $state['submission_id'] ?? 0 );

        if ( $submission_id <= 0 ) {
            return new \WP_Error(
                'no_draft',
                __( 'Go back and write your answer first.', 'talenttrack' )
            );
        }

        return [ 'attachment_count' => SubmissionAttachments::countFor( $submission_id ) ];
    }

    public function nextStep( array $state ): ?string { return 'confirm'; }

    public function submit( array $state ) { return null; }
}
