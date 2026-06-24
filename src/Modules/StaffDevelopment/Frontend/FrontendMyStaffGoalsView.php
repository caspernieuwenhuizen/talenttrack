<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\StaffDevelopment\Repositories\StaffGoalsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendAppChrome;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyStaffGoalsView — list + create + status-edit form for the
 * staff member's personal-development goals. Goal can optionally be
 * linked to a `cert_type` lookup (e.g. "Take UEFA-B"); that link
 * surfaces on the goal row and on the certifications view.
 */
class FrontendMyStaffGoalsView extends FrontendViewBase {

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
        $title = __( 'My staff goals', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_staff_development' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'My goals', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }
        $person = StaffPersonHelper::personForUser( $user_id );
        if ( ! $person ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( __( 'My goals', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This section is only available for staff members linked to a People record.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( (int) $person->id );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( __( 'My goals', 'talenttrack' ) );

        $repo  = new StaffGoalsRepository();
        $goals = $repo->listForPerson( (int) $person->id );
        $cert_types = QueryHelpers::get_lookups( 'cert_type' );
        $cert_by_id = [];
        foreach ( $cert_types as $c ) { $cert_by_id[ (int) $c->id ] = (string) $c->name; }

        // KPI strip — counts derived from the already-fetched list (no new
        // query). $open counts goals not in the completed status.
        $total = count( $goals );
        $open  = 0;
        foreach ( $goals as $g ) {
            if ( (string) ( $g->status ?? '' ) !== StaffGoalsRepository::STATUS_COMPLETED ) $open++;
        }
        echo '<div class="tt-sdev-kpis">';
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Total goals', 'talenttrack' ), 'value' => (string) $total ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Open', 'talenttrack' ), 'value' => (string) $open, 'flag' => $open > 0 ? 'green' : '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Completed', 'talenttrack' ), 'value' => (string) ( $total - $open ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
        echo '</div>';

        if ( ! $goals ) {
            echo '<p class="tt-sdev-empty">' . esc_html__( 'No goals yet. Add one below.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-sdev-list">';
            foreach ( $goals as $g ) {
                $prio_key = (string) ( $g->priority ?? '' );
                $prio_cls = $prio_key === 'high' ? 'tt-sdev-chip--red' : ( $prio_key === 'low' ? 'tt-sdev-chip--ghost' : 'tt-sdev-chip--gold' );
                $status_key = (string) ( $g->status ?? '' );
                $status_cls = $status_key === StaffGoalsRepository::STATUS_COMPLETED ? 'tt-sdev-chip--green' : 'tt-sdev-chip--ghost';
                $cert_name  = (string) ( $cert_by_id[ (int) $g->cert_type_lookup_id ] ?? '' );
                echo '<li class="tt-sdev-card">';
                echo '<div class="tt-sdev-card__head">';
                echo '<h4 class="tt-sdev-card__title">' . esc_html( (string) $g->title ) . '</h4>';
                echo '<span class="tt-sdev-chip ' . esc_attr( $status_cls ) . '">' . esc_html( LookupTranslator::byTypeAndName( 'goal_status', $status_key ) ) . '</span>';
                echo '</div>';
                echo '<div class="tt-sdev-card__meta">';
                echo '<span><span class="tt-sdev-chip ' . esc_attr( $prio_cls ) . '">' . esc_html( LookupTranslator::byTypeAndName( 'goal_priority', $prio_key ) ) . '</span></span>';
                echo '<span>' . esc_html__( 'Due', 'talenttrack' ) . ': <b>' . esc_html( ( $g->due_date ?? '' ) !== '' ? \TT\Shared\Dates\TTDate::date( (string) $g->due_date ) : '—' ) . '</b></span>';
                if ( $cert_name !== '' ) {
                    echo '<span>' . esc_html__( 'Targets cert', 'talenttrack' ) . ': <b>' . esc_html( $cert_name ) . '</b></span>';
                }
                echo '</div>';
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '<h3 class="tt-sdev-section-title">' . esc_html__( 'Add a goal', 'talenttrack' ) . '</h3>';
        echo '<form method="post" class="tt-form tt-sdev-form">';
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

        // CLAUDE.md §6 — Save + Cancel on a record-creating form. Cancel
        // returns to this same list view; a tt_back hint on the entry URL
        // overrides that destination.
        $back       = BackLink::resolve();
        $cancel_url = $back !== null
            ? $back['url']
            : add_query_arg( 'tt_view', 'my-staff-goals', RecordLink::dashboardUrl() );
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
            'label'      => __( 'Add goal', 'talenttrack' ),
            'cancel_url' => $cancel_url,
        ] );
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
