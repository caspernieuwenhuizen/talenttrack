<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Prospects\Domain\ArrangeTestTrainingService;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrontendTestTrainingsView (v3.110.113) — minimal create surface for
 * test trainings, reached via `?tt_view=test-trainings&action=new`
 * from the HoD dashboard's `+ New test training` action card.
 *
 * Test trainings (`tt_test_trainings`) are one-off training sessions a
 * prospect is invited to so the academy can observe them in action
 * (separate from the multi-week `tt_trial_cases` evaluation period).
 * Until v3.110.113 there was no frontend create form — sessions were
 * scheduled implicitly via the `InviteToTestTrainingForm` workflow
 * task. The HoD dashboard CTA needed a direct entry point.
 *
 * Scope deliberately minimal: create-form only. Listing + edit live
 * on the onboarding-pipeline surface for now; this view only renders
 * the create form (`action=new`) — the bare list path redirects there.
 *
 * #3932 — the form carries a prospect, prefilled from `prospect_id` in
 * the URL. That is the head of development's route in from the pipeline:
 * somebody who may issue the invitation themselves comes straight here
 * rather than addressing a task to themselves, and until this the child
 * they were looking at did not come with them.
 */
class FrontendTestTrainingsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_prospects' )
             && ! current_user_can( 'tt_manage_prospects' )
             && ! $is_admin
        ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'Test trainings', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to create test trainings.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-test-trainings',
            TT_PLUGIN_URL . 'assets/css/frontend-test-trainings.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            __( 'New test training', 'talenttrack' )
        );
        self::renderHeader( __( 'New test training', 'talenttrack' ) );

        // Default date — Saturday afternoon of the current week (the
        // most common slot for test trainings). Operator can override.
        $default_date = date( 'Y-m-d', strtotime( 'next Saturday' ) );

        // Age group dropdown options — sourced from the `age_group`
        // lookup vocabulary. Optional (test trainings can target a
        // mixed-age group), so the empty option is the default.
        $age_groups = [];
        foreach ( QueryHelpers::get_lookups( 'age_group' ) as $ag ) {
            $label = (string) ( $ag->label ?? '' );
            if ( $label === '' ) $label = (string) ( $ag->name ?? '' );
            $age_groups[ (int) $ag->id ] = $label;
        }
        // #3932 — who the session is being arranged for. The head of
        // development arrives here from a prospect's card with the id in
        // the URL; #3710 shipped that deep link against a form that had
        // no prospect on it, so the training they created was not linked
        // to the child they were looking at.
        //
        // An id that is missing, invalid, or outside what this viewer may
        // see all land in the same place — the field opens empty. It never
        // says "that prospect does not exist", because for a viewer who
        // may not see the child those are the same answer and only one of
        // them is safe to give.
        $pickable       = ArrangeTestTrainingService::pickableFor( $user_id );
        $wanted         = isset( $_GET['prospect_id'] ) ? absint( $_GET['prospect_id'] ) : 0;
        $selected_pid   = ( $wanted > 0 && ArrangeTestTrainingService::canArrange( $user_id, $wanted ) )
            ? $wanted
            : 0;
        ?>
        <div class="tt-test-trainings">
        <form class="tt-ajax-form" data-rest-path="test-trainings" data-rest-method="POST" data-redirect-after-save="list">
            <?php if ( $pickable !== [] ) : ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-tt-prospect"><?php esc_html_e( 'Prospect', 'talenttrack' ); ?></label>
                    <select id="tt-tt-prospect" class="tt-input" name="prospect_id">
                        <option value=""><?php esc_html_e( 'Nobody yet', 'talenttrack' ); ?></option>
                        <?php foreach ( $pickable as $p ) : ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php selected( $selected_pid, (int) $p['id'] ); ?>>
                                <?php echo esc_html( $p['label'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="tt-field-hint"><?php esc_html_e( 'Pick the prospect this session is being arranged for, and they move to Invited when you save. Leave it empty to schedule an open session.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>
            <div class="tt-grid tt-grid-2">
                <?php echo DateInputComponent::render( [
                    'name'     => 'date',
                    'label'    => __( 'Date', 'talenttrack' ),
                    'required' => true,
                    'value'    => $default_date,
                ] ); ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-tt-location"><?php esc_html_e( 'Location', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-tt-location" class="tt-input" name="location" placeholder="<?php esc_attr_e( 'e.g. Main pitch', 'talenttrack' ); ?>" />
                </div>
                <?php if ( ! empty( $age_groups ) ) : ?>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-tt-age-group"><?php esc_html_e( 'Age group', 'talenttrack' ); ?></label>
                        <select id="tt-tt-age-group" class="tt-input" name="age_group_lookup_id">
                            <option value=""><?php esc_html_e( 'Any age group', 'talenttrack' ); ?></option>
                            <?php foreach ( $age_groups as $aid => $alabel ) : ?>
                                <option value="<?php echo (int) $aid; ?>"><?php echo esc_html( $alabel ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-tt-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                <textarea id="tt-tt-notes" class="tt-input" name="notes" rows="3"
                          placeholder="<?php esc_attr_e( 'Logistics, what to bring, contact instructions…', 'talenttrack' ); ?>"></textarea>
            </div>
            <?php
            $dash_url = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
            $back     = \TT\Shared\Frontend\Components\BackLink::resolve();
            $cancel_url = $back !== null ? $back['url'] : $dash_url;
            echo FormSaveButton::render( [
                'label'      => __( 'Schedule test training', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
            <div class="tt-form-msg"></div>
        </form>
        </div>
        <?php
    }
}
