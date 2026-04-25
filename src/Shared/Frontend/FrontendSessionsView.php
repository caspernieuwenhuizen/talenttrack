<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\FrontendListTable;

/**
 * FrontendSessionsView — the "Sessions" tile destination (coaching group).
 *
 * #0019 Sprint 2 session 2: in addition to the existing create form,
 * this view now renders a `FrontendListTable` validator for the new
 * component. The list reads `GET /sessions` (added in Sprint 2 session
 * 1) and proves the table/filter/sort/pagination contract end-to-end.
 *
 * Sprint 2 session 2.3 will replace this view with a dedicated
 * `FrontendSessionsManageView` that does full list+edit+delete; the
 * one-liner table embed below is deliberately minimal — its job is to
 * exercise the component, not to ship the final UX.
 */
class FrontendSessionsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'Sessions', 'talenttrack' ) );

        $teams        = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        $team_options = [];
        foreach ( $teams as $t ) {
            $team_options[ (int) $t->id ] = (string) $t->name;
        }

        echo FrontendListTable::render( [
            'rest_path' => 'sessions',
            'columns'   => [
                'session_date' => [ 'label' => __( 'Date',    'talenttrack' ), 'sortable' => true ],
                'team_name'    => [ 'label' => __( 'Team',    'talenttrack' ), 'sortable' => true ],
                'title'        => [ 'label' => __( 'Title',   'talenttrack' ), 'sortable' => true ],
                'attendance'   => [ 'label' => __( 'Att. %',  'talenttrack' ), 'sortable' => true, 'render' => 'percent', 'value_key' => 'attendance_pct' ],
            ],
            'filters' => [
                'team_id' => [ 'type' => 'select',     'label' => __( 'Team', 'talenttrack' ), 'options' => $team_options ],
                'date'    => [ 'type' => 'date_range', 'param_from' => 'date_from', 'param_to' => 'date_to', 'label_from' => __( 'From', 'talenttrack' ), 'label_to' => __( 'To', 'talenttrack' ) ],
                'attendance' => [ 'type' => 'select', 'label' => __( 'Attendance', 'talenttrack' ), 'options' => [
                    'complete' => __( 'Complete', 'talenttrack' ),
                    'partial'  => __( 'Partial',  'talenttrack' ),
                    'none'     => __( 'None',     'talenttrack' ),
                ] ],
            ],
            'search'       => [ 'placeholder' => __( 'Search title, location, team…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'session_date', 'order' => 'desc' ],
            'empty_state'  => __( 'No sessions match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.

        echo '<h3 style="margin-top:24px;">' . esc_html__( 'Record Training Session', 'talenttrack' ) . '</h3>';
        CoachForms::renderSessionForm( $teams );
    }
}
