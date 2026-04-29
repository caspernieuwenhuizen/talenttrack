<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\StaffDevelopment\Repositories\StaffGoalsRepository;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyStaffGoalsView — list + create + status-edit form for the
 * staff member's personal-development goals. Goal can optionally be
 * linked to a `cert_type` lookup (e.g. "Take UEFA-B"); that link
 * surfaces on the goal row and on the certifications view.
 */
class FrontendMyStaffGoalsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_staff_development' ) ) {
            self::renderHeader( __( 'My goals', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }
        $person = StaffPersonHelper::personForUser( $user_id );
        if ( ! $person ) {
            self::renderHeader( __( 'My goals', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This section is only available for staff members linked to a People record.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( (int) $person->id );
        self::renderHeader( __( 'My goals', 'talenttrack' ) );

        $repo  = new StaffGoalsRepository();
        $goals = $repo->listForPerson( (int) $person->id );
        $cert_types = QueryHelpers::get_lookups( 'cert_type' );
        $cert_by_id = [];
        foreach ( $cert_types as $c ) { $cert_by_id[ (int) $c->id ] = (string) $c->name; }

        if ( ! $goals ) {
            echo '<p>' . esc_html__( 'No goals yet. Add one below.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table" style="width:100%;"><thead><tr>';
            echo '<th>' . esc_html__( 'Title', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Priority', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Due', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Targets cert', 'talenttrack' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $goals as $g ) {
                echo '<tr>';
                echo '<td>' . esc_html( (string) $g->title ) . '</td>';
                echo '<td>' . esc_html( (string) $g->priority ) . '</td>';
                echo '<td>' . esc_html( (string) $g->status ) . '</td>';
                echo '<td>' . esc_html( (string) ( $g->due_date ?? '—' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $cert_by_id[ (int) $g->cert_type_lookup_id ] ?? '—' ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3 style="margin-top:24px;">' . esc_html__( 'Add a goal', 'talenttrack' ) . '</h3>';
        echo '<form method="post" class="tt-form" style="max-width:720px;">';
        wp_nonce_field( 'tt_staff_goal_save', 'tt_staff_goal_nonce' );

        echo '<div class="tt-field"><label class="tt-field-label tt-field-required" for="tt-staff-goal-title">' . esc_html__( 'Title', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-staff-goal-title" name="title" class="tt-input" required maxlength="255"></div>';

        echo '<div class="tt-grid tt-grid-2">';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-goal-priority">' . esc_html__( 'Priority', 'talenttrack' ) . '</label>';
        echo '<select id="tt-staff-goal-priority" name="priority" class="tt-input">';
        foreach ( [ 'low', 'medium', 'high' ] as $p ) {
            echo '<option value="' . esc_attr( $p ) . '"' . ( $p === 'medium' ? ' selected' : '' ) . '>' . esc_html( ucfirst( $p ) ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-goal-due">' . esc_html__( 'Due date', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-staff-goal-due" name="due_date" class="tt-input"></div>';
        echo '</div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-goal-cert">' . esc_html__( 'Targets a certification (optional)', 'talenttrack' ) . '</label>';
        echo '<select id="tt-staff-goal-cert" name="cert_type_lookup_id" class="tt-input">';
        echo '<option value="0">' . esc_html__( '— none —', 'talenttrack' ) . '</option>';
        foreach ( $cert_types as $c ) {
            echo '<option value="' . esc_attr( (string) $c->id ) . '">' . esc_html( (string) $c->name ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-goal-desc">' . esc_html__( 'Description', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-staff-goal-desc" name="description" class="tt-input" rows="3"></textarea></div>';

        echo '<div class="tt-form-actions"><button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Add goal', 'talenttrack' ) . '</button></div>';
        echo '</form>';
    }

    private static function handlePost( int $person_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        if ( ! isset( $_POST['tt_staff_goal_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_staff_goal_nonce'] ) ), 'tt_staff_goal_save' ) ) return;

        $title = sanitize_text_field( wp_unslash( (string) ( $_POST['title'] ?? '' ) ) );
        if ( $title === '' ) return;

        $repo = new StaffGoalsRepository();
        $repo->create( [
            'person_id'           => $person_id,
            'title'               => $title,
            'description'         => sanitize_textarea_field( wp_unslash( (string) ( $_POST['description'] ?? '' ) ) ),
            'priority'            => sanitize_key( (string) ( $_POST['priority'] ?? 'medium' ) ),
            'due_date'            => isset( $_POST['due_date'] ) && (string) $_POST['due_date'] !== '' ? sanitize_text_field( wp_unslash( (string) $_POST['due_date'] ) ) : null,
            'cert_type_lookup_id' => isset( $_POST['cert_type_lookup_id'] ) && (int) $_POST['cert_type_lookup_id'] > 0 ? (int) $_POST['cert_type_lookup_id'] : null,
            'created_by'          => get_current_user_id(),
        ] );
    }
}
