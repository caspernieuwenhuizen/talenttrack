<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Domain\ProspectCreationService;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — confirm + create.
 *
 * `submit()` hands the collected state to `ProspectCreationService`, which
 * writes the row and dispatches `InviteToTestTrainingTemplate` for the HoD
 * with the fresh `prospect_id` on the context. The chain effectively starts
 * at "Invite" rather than at "LogProspect" — the wizard IS the form that
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

        $rows = [
            [ __( 'Name',          'talenttrack' ), trim( $first . ' ' . $last ), false ],
            [ __( 'Date of birth', 'talenttrack' ), $dob,                          false ],
            [ __( 'Current club',  'talenttrack' ), $club,                         false ],
            [ __( 'Discovered at', 'talenttrack' ), $event,                        false ],
            [ __( 'Notes',         'talenttrack' ), $notes,                        true  ],
            [ __( 'Parent name',   'talenttrack' ), $pname,                        false ],
            [ __( 'Parent email',  'talenttrack' ), $pmail,                        false ],
            [ __( 'Parent phone',  'talenttrack' ), $pphone,                       false ],
        ];
        ?>
        <p><?php esc_html_e( 'Confirm and create the prospect:', 'talenttrack' ); ?></p>
        <div class="tt-table-wrap">
            <table class="tt-table tt-wizard-review-table">
                <tbody>
                    <?php foreach ( $rows as [ $label, $value, $multiline ] ) :
                        if ( $value === '' ) continue; ?>
                        <tr>
                            <th scope="row" style="width:35%;"><?php echo esc_html( $label ); ?></th>
                            <td><?php echo $multiline ? nl2br( esc_html( $value ) ) : esc_html( $value ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
        // #4015 — the field map, the duplicate rule and the follow-on
        // invite task all live in `ProspectCreationService` now. This step
        // had its own copy of the first two, `LogProspectForm` had another,
        // and `POST /prospects` would have been a third. The step's job is
        // to confirm, not to decide what a prospect is.
        //
        // `duplicate_override` rides along from `IdentityStep`, so a scout
        // who already answered "yes, this is a different child" is not asked
        // again on the last screen.
        $prospect_id = ( new ProspectCreationService() )->create( $state );
        if ( $prospect_id instanceof \WP_Error ) return $prospect_id;

        return [
            'redirect_url' => add_query_arg(
                [ 'tt_view' => 'onboarding-pipeline' ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            ),
        ];
    }
}
