<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\StaffDevelopment\Repositories\StaffEvaluationsRepository;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyStaffEvaluationsView — list + create form for the staff
 * member's evaluations. v1 ships only the `self` and `top_down` kinds;
 * a non-manager user only sees the `self` option.
 *
 * The category-rating step is omitted from this v1 form — the staff
 * eval-category tree (`is_staff_tree=1`) is seeded with five mains and
 * no subcategories, so the editor would be a flat list of five cells.
 * Use the REST endpoint `POST /staff/{person_id}/evaluations` with the
 * `ratings` payload for that until the dedicated category form lands.
 */
class FrontendMyStaffEvaluationsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_staff_development' ) ) {
            self::renderHeader( __( 'My evaluations', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }
        $person = StaffPersonHelper::personForUser( $user_id );
        if ( ! $person ) {
            self::renderHeader( __( 'My evaluations', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This section is only available for staff members linked to a People record.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( (int) $person->id );
        self::renderHeader( __( 'My evaluations', 'talenttrack' ) );

        $repo  = new StaffEvaluationsRepository();
        $rows  = $repo->listForPerson( (int) $person->id );

        if ( ! $rows ) {
            echo '<p>' . esc_html__( 'No evaluations recorded yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table" style="width:100%;"><thead><tr>';
            echo '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Kind', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Reviewer', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Notes', 'talenttrack' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $rows as $r ) {
                $u = get_userdata( (int) $r->reviewer_user_id );
                $reviewer = $u ? (string) $u->display_name : '#' . (int) $r->reviewer_user_id;
                echo '<tr>';
                echo '<td>' . esc_html( (string) $r->eval_date ) . '</td>';
                echo '<td>' . esc_html( (string) $r->review_kind ) . '</td>';
                echo '<td>' . esc_html( $reviewer ) . '</td>';
                echo '<td>' . esc_html( (string) $r->notes ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        $can_top_down = current_user_can( 'tt_manage_staff_development' );
        echo '<h3 style="margin-top:24px;">' . esc_html__( 'Record a new evaluation', 'talenttrack' ) . '</h3>';
        echo '<form method="post" class="tt-form" style="max-width:720px;">';
        wp_nonce_field( 'tt_staff_eval_save', 'tt_staff_eval_nonce' );

        echo '<div class="tt-grid tt-grid-2">';
        echo '<div class="tt-field"><label class="tt-field-label tt-field-required" for="tt-staff-eval-date">' . esc_html__( 'Date', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-staff-eval-date" name="eval_date" class="tt-input" required value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-eval-kind">' . esc_html__( 'Kind', 'talenttrack' ) . '</label>';
        echo '<select id="tt-staff-eval-kind" name="review_kind" class="tt-input">';
        echo '<option value="self">' . esc_html__( 'Self-evaluation', 'talenttrack' ) . '</option>';
        if ( $can_top_down ) {
            echo '<option value="top_down">' . esc_html__( 'Top-down (head of development)', 'talenttrack' ) . '</option>';
        }
        echo '</select></div>';
        echo '</div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-eval-notes">' . esc_html__( 'Notes', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-staff-eval-notes" name="notes" class="tt-input" rows="3"></textarea></div>';

        echo '<div class="tt-form-actions"><button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save evaluation', 'talenttrack' ) . '</button></div>';
        echo '</form>';
    }

    private static function handlePost( int $person_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        if ( ! isset( $_POST['tt_staff_eval_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_staff_eval_nonce'] ) ), 'tt_staff_eval_save' ) ) return;

        $kind = sanitize_key( (string) ( $_POST['review_kind'] ?? 'self' ) );
        if ( $kind === 'top_down' && ! current_user_can( 'tt_manage_staff_development' ) ) {
            $kind = 'self';
        }

        $repo = new StaffEvaluationsRepository();
        $repo->create( [
            'person_id'        => $person_id,
            'reviewer_user_id' => get_current_user_id(),
            'review_kind'      => $kind,
            'eval_date'        => sanitize_text_field( wp_unslash( (string) ( $_POST['eval_date'] ?? gmdate( 'Y-m-d' ) ) ) ),
            'notes'            => sanitize_textarea_field( wp_unslash( (string) ( $_POST['notes'] ?? '' ) ) ),
        ] );
    }
}
