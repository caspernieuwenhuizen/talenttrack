<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
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
        ?>
        <form class="tt-ajax-form" data-rest-path="test-trainings" data-rest-method="POST" data-redirect-after-save="dashboard">
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
        <?php
    }
}
