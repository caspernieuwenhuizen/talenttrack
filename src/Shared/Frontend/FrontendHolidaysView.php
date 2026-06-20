<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendHolidaysView (#1480) — academy-wide holiday management at
 * `?tt_view=holidays`. Lists holidays + the wizard-first create CTA +
 * a gated delete. Read-gated on `tt_view_holidays`; create / delete on
 * `tt_manage_holidays` (the row action + CTA carry their own cap).
 */
final class FrontendHolidaysView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_holidays' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view holidays.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Holidays', 'talenttrack' ) );
        self::renderHeader( __( 'Academy holidays', 'talenttrack' ) );

        echo '<p class="tt-muted" style="max-width:640px; margin:0 0 12px;">'
            . esc_html__( 'Academy-wide holiday periods. They show as a banner across the affected days on every team planner.', 'talenttrack' )
            . '</p>';

        $wizard_url = add_query_arg(
            [ 'tt_view' => 'wizard', 'slug' => 'holiday' ],
            RecordLink::dashboardUrl()
        );

        echo FrontendListTable::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
            'rest_path' => 'holidays',
            'columns'   => [
                'name'       => [ 'label' => __( 'Name', 'talenttrack' ),  'sortable' => true ],
                'start_date' => [ 'label' => __( 'Start', 'talenttrack' ), 'sortable' => true, 'render' => 'date' ],
                'end_date'   => [ 'label' => __( 'End', 'talenttrack' ),   'sortable' => true, 'render' => 'date' ],
                'note'       => [ 'label' => __( 'Note', 'talenttrack' ) ],
            ],
            'row_actions' => [
                'delete' => [
                    'label'       => __( 'Delete', 'talenttrack' ),
                    'rest_method' => 'DELETE',
                    'rest_path'   => 'holidays/{id}',
                    'confirm'     => __( 'Delete this holiday?', 'talenttrack' ),
                    'cap'         => 'tt_manage_holidays',
                    'variant'     => 'danger',
                ],
            ],
            'search'       => [ 'placeholder' => __( 'Search holidays…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'start_date', 'order' => 'asc' ],
            'empty_state'  => __( 'No holidays match your search.', 'talenttrack' ),
            'empty_state_card' => [
                'icon'      => 'activities',
                'headline'  => __( 'No holidays yet', 'talenttrack' ),
                'explainer' => __( 'Add the academy holiday periods so coaches see them on the planner and avoid scheduling on closed days.', 'talenttrack' ),
                'cta_label' => __( 'Add first holiday', 'talenttrack' ),
                'cta_url'   => $wizard_url,
                'cta_cap'   => 'tt_manage_holidays',
            ],
        ] );
    }
}
