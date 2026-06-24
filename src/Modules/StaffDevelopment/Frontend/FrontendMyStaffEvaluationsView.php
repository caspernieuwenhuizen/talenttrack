<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\StaffDevelopment\Repositories\StaffEvaluationsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendAppChrome;
use TT\Shared\Frontend\Components\RecordLink;
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

    /**
     * B4 2026 restyle — enqueue the shared staff-development card
     * stylesheet on top of the shared frontend assets. Loaded here (not in
     * FrontendViewBase) because only the staff-development surfaces use it;
     * depends on the global app-chrome sheet for the shared brand tokens.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-staff-development',
            TT_PLUGIN_URL . 'assets/css/frontend-staff-development.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'My staff evaluations', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_staff_development' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'My evaluations', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }
        $person = StaffPersonHelper::personForUser( $user_id );
        if ( ! $person ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( __( 'My evaluations', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This section is only available for staff members linked to a People record.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( (int) $person->id );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( __( 'My evaluations', 'talenttrack' ) );

        $repo  = new StaffEvaluationsRepository();
        $rows  = $repo->listForPerson( (int) $person->id );

        // KPI strip — counts derived from the already-fetched list (no new
        // query).
        $total    = count( $rows );
        $self_ct  = 0;
        foreach ( $rows as $r ) {
            if ( (string) $r->review_kind === 'self' ) $self_ct++;
        }
        echo '<div class="tt-sdev-kpis">';
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Evaluations', 'talenttrack' ), 'value' => (string) $total ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Self', 'talenttrack' ), 'value' => (string) $self_ct ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Top-down', 'talenttrack' ), 'value' => (string) ( $total - $self_ct ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
        echo '</div>';

        if ( ! $rows ) {
            echo '<p class="tt-sdev-empty">' . esc_html__( 'No evaluations recorded yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-sdev-list">';
            foreach ( $rows as $r ) {
                $u = get_userdata( (int) $r->reviewer_user_id );
                $reviewer = $u ? (string) $u->display_name : '#' . (int) $r->reviewer_user_id;
                $is_self  = (string) $r->review_kind === 'self';
                $kind_lbl = $is_self ? __( 'Self', 'talenttrack' ) : __( 'Top-down', 'talenttrack' );
                $kind_cls = $is_self ? 'tt-sdev-chip--ghost' : 'tt-sdev-chip--gold';
                echo '<li class="tt-sdev-card">';
                echo '<div class="tt-sdev-card__head">';
                echo '<h4 class="tt-sdev-card__title">' . esc_html( \TT\Shared\Dates\TTDate::date( (string) $r->eval_date ) ) . '</h4>';
                echo '<span class="tt-sdev-chip ' . esc_attr( $kind_cls ) . '">' . esc_html( $kind_lbl ) . '</span>';
                echo '</div>';
                echo '<div class="tt-sdev-card__meta"><span>' . esc_html__( 'Reviewer', 'talenttrack' ) . ': <b>' . esc_html( $reviewer ) . '</b></span></div>';
                if ( (string) $r->notes !== '' ) {
                    echo '<p class="tt-sdev-card__notes">' . esc_html( (string) $r->notes ) . '</p>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        $can_top_down = current_user_can( 'tt_manage_staff_development' );
        echo '<h3 class="tt-sdev-section-title">' . esc_html__( 'Record a new evaluation', 'talenttrack' ) . '</h3>';
        echo '<form method="post" class="tt-form tt-sdev-form">';
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

        // CLAUDE.md §6 — Save + Cancel on a record-creating form. Cancel
        // returns to this same list view; a tt_back hint on the entry URL
        // overrides that destination.
        $back       = BackLink::resolve();
        $cancel_url = $back !== null
            ? $back['url']
            : add_query_arg( 'tt_view', 'my-staff-evaluations', RecordLink::dashboardUrl() );
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
            'label'      => __( 'Save evaluation', 'talenttrack' ),
            'cancel_url' => $cancel_url,
        ] );
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
