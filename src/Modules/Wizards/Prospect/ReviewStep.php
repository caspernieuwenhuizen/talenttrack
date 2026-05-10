<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\WorkflowModule;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — confirm + create.
 *
 * `submit()` writes the prospect row via `ProspectsRepository::create()`
 * and dispatches `InviteToTestTrainingTemplate` for the HoD with the
 * fresh `prospect_id` on the context. The chain effectively starts at
 * "Invite" rather than at "LogProspect" — the wizard IS the form that
 * the legacy `LogProspectTemplate` task used to wrap, so creating a
 * task to capture data the wizard already collected would be a
 * redundant "tap once to begin, tap again to fill in" detour.
 *
 * Redirects back to `?tt_view=onboarding-pipeline` so the new prospect
 * card appears in the Invited column on the next page render. The
 * pipeline cache (`tt_persona_dashboard`) is invalidated by the chain
 * task creation so the count updates immediately.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $first  = (string) ( $state['first_name']          ?? '' );
        $last   = (string) ( $state['last_name']           ?? '' );
        $dob    = (string) ( $state['date_of_birth']       ?? '' );
        $club   = (string) ( $state['current_club']        ?? '' );
        $event  = (string) ( $state['discovered_at_event'] ?? '' );
        $notes  = (string) ( $state['scouting_notes']      ?? '' );
        $pname  = (string) ( $state['parent_name']         ?? '' );
        $pmail  = (string) ( $state['parent_email']        ?? '' );
        $pphone = (string) ( $state['parent_phone']        ?? '' );
        ?>
        <p><?php esc_html_e( 'Confirm and create the prospect:', 'talenttrack' ); ?></p>
        <dl class="tt-profile-dl">
            <dt><?php esc_html_e( 'Name', 'talenttrack' ); ?></dt>
            <dd><?php echo esc_html( trim( $first . ' ' . $last ) ); ?></dd>
            <?php if ( $dob !== '' ) : ?>
                <dt><?php esc_html_e( 'Date of birth', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $dob ); ?></dd>
            <?php endif; ?>
            <?php if ( $club !== '' ) : ?>
                <dt><?php esc_html_e( 'Current club', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $club ); ?></dd>
            <?php endif; ?>
            <?php if ( $event !== '' ) : ?>
                <dt><?php esc_html_e( 'Discovered at', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $event ); ?></dd>
            <?php endif; ?>
            <?php if ( $notes !== '' ) : ?>
                <dt><?php esc_html_e( 'Notes', 'talenttrack' ); ?></dt>
                <dd><?php echo nl2br( esc_html( $notes ) ); ?></dd>
            <?php endif; ?>
            <?php if ( $pname !== '' ) : ?>
                <dt><?php esc_html_e( 'Parent name', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $pname ); ?></dd>
            <?php endif; ?>
            <?php if ( $pmail !== '' ) : ?>
                <dt><?php esc_html_e( 'Parent email', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $pmail ); ?></dd>
            <?php endif; ?>
            <?php if ( $pphone !== '' ) : ?>
                <dt><?php esc_html_e( 'Parent phone', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $pphone ); ?></dd>
            <?php endif; ?>
        </dl>
        <p>
            <small>
                <?php esc_html_e( 'After saving, the Head of Development gets a task to invite this prospect to a test training.', 'talenttrack' ); ?>
            </small>
        </p>
        <?php
    }

    public function validate( array $post, array $state ) {
        // Confirmation surface — nothing to collect.
        return [];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $repo = new ProspectsRepository();
        $consent_at = ! empty( $state['consent_given'] ) ? current_time( 'mysql', true ) : null;

        $prospect_id = $repo->create( [
            'first_name'              => (string) ( $state['first_name']          ?? '' ),
            'last_name'               => (string) ( $state['last_name']           ?? '' ),
            'date_of_birth'           => trim( (string) ( $state['date_of_birth'] ?? '' ) ) ?: null,
            'discovered_at'           => gmdate( 'Y-m-d' ),
            'discovered_by_user_id'   => get_current_user_id(),
            'discovered_at_event'     => trim( (string) ( $state['discovered_at_event'] ?? '' ) ) ?: null,
            'current_club'            => trim( (string) ( $state['current_club']  ?? '' ) ) ?: null,
            'scouting_notes'          => trim( (string) ( $state['scouting_notes'] ?? '' ) ) ?: null,
            'parent_name'             => trim( (string) ( $state['parent_name']   ?? '' ) ) ?: null,
            'parent_email'            => trim( (string) ( $state['parent_email']  ?? '' ) ) ?: null,
            'parent_phone'            => trim( (string) ( $state['parent_phone']  ?? '' ) ) ?: null,
            'consent_given_at'        => $consent_at,
        ] );

        if ( ! $prospect_id ) {
            return new \WP_Error( 'prospect_create_failed', __( 'Could not create the prospect record.', 'talenttrack' ) );
        }

        // Dispatch the next chain step — the HoD-facing invitation
        // task. Skips `LogProspectTemplate` entirely; the wizard
        // already collected the data that template would have asked
        // for in its form.
        if ( class_exists( WorkflowModule::class ) && class_exists( InviteToTestTrainingTemplate::class ) ) {
            $context = new TaskContext(
                null, null, null, null, null, null, null,
                $prospect_id
            );
            WorkflowModule::engine()->dispatch( InviteToTestTrainingTemplate::KEY, $context );
        }

        return [
            'redirect_url' => add_query_arg(
                [ 'tt_view' => 'onboarding-pipeline' ],
                home_url( '/' )
            ),
        ];
    }
}
