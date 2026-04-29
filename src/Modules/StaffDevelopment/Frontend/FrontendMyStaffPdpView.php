<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\StaffDevelopment\Repositories\StaffPdpRepository;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyStaffPdpView — staff member's personal-development plan.
 * One row per (person, season). Four fields: strengths,
 * development_areas, actions_next_quarter, narrative.
 *
 * POST writes through the form submit handler (nonce-guarded) and
 * upserts via `StaffPdpRepository::upsert()`. The same data shape is
 * available via REST (`PUT /staff/{person_id}/pdp`) per CLAUDE.md § 3.
 */
class FrontendMyStaffPdpView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_staff_development' ) ) {
            self::renderHeader( __( 'My PDP', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }
        $person = StaffPersonHelper::personForUser( $user_id );
        if ( ! $person ) {
            self::renderHeader( __( 'My PDP', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This section is only available for staff members linked to a People record.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( (int) $person->id );
        self::renderHeader( __( 'My PDP', 'talenttrack' ) );

        $repo    = new StaffPdpRepository();
        $season  = StaffPersonHelper::currentSeasonId();
        $pdp     = $repo->findForPersonSeason( (int) $person->id, $season );

        echo '<form method="post" class="tt-form tt-staff-pdp-form" style="max-width:760px;">';
        wp_nonce_field( 'tt_staff_pdp_save', 'tt_staff_pdp_nonce' );
        echo '<input type="hidden" name="season_id" value="' . esc_attr( $season ? (string) $season : '' ) . '">';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-pdp-strengths">' . esc_html__( 'Strengths', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-staff-pdp-strengths" name="strengths" class="tt-input" rows="3">' . esc_textarea( (string) ( $pdp->strengths ?? '' ) ) . '</textarea></div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-pdp-dev">' . esc_html__( 'Development areas', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-staff-pdp-dev" name="development_areas" class="tt-input" rows="3">' . esc_textarea( (string) ( $pdp->development_areas ?? '' ) ) . '</textarea></div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-pdp-actions">' . esc_html__( 'Actions next quarter', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-staff-pdp-actions" name="actions_next_quarter" class="tt-input" rows="3">' . esc_textarea( (string) ( $pdp->actions_next_quarter ?? '' ) ) . '</textarea></div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-pdp-narrative">' . esc_html__( 'Narrative (optional)', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-staff-pdp-narrative" name="narrative" class="tt-input" rows="4">' . esc_textarea( (string) ( $pdp->narrative ?? '' ) ) . '</textarea></div>';

        echo '<div class="tt-form-actions" style="margin-top:16px;">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save PDP', 'talenttrack' ) . '</button>';
        echo '</div>';
        if ( $pdp && $pdp->last_reviewed_at ) {
            echo '<p class="tt-field-hint" style="margin-top:8px;">' . esc_html__( 'Last reviewed:', 'talenttrack' ) . ' ' . esc_html( (string) $pdp->last_reviewed_at ) . '</p>';
        }
        echo '</form>';
    }

    private static function handlePost( int $person_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        if ( ! isset( $_POST['tt_staff_pdp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_staff_pdp_nonce'] ) ), 'tt_staff_pdp_save' ) ) return;

        $repo = new StaffPdpRepository();
        $season = isset( $_POST['season_id'] ) && (string) $_POST['season_id'] !== '' ? (int) $_POST['season_id'] : null;

        $repo->upsert( $person_id, $season, [
            'strengths'             => sanitize_textarea_field( wp_unslash( (string) ( $_POST['strengths'] ?? '' ) ) ),
            'development_areas'     => sanitize_textarea_field( wp_unslash( (string) ( $_POST['development_areas'] ?? '' ) ) ),
            'actions_next_quarter'  => sanitize_textarea_field( wp_unslash( (string) ( $_POST['actions_next_quarter'] ?? '' ) ) ),
            'narrative'             => sanitize_textarea_field( wp_unslash( (string) ( $_POST['narrative'] ?? '' ) ) ),
        ], get_current_user_id() );
    }
}
