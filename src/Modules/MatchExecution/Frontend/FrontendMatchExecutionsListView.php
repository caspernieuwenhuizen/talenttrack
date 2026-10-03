<?php
namespace TT\Modules\MatchExecution\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Modules\MatchExecution\Services\MatchExecutionListQuery;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMatchExecutionsListView (#1047) — dedicated list surface
 * for match executions at `?tt_view=match-executions`.
 *
 * Answers the original #1033 ask: "the match execution should move to
 * a separate place where they all are stored and where they can be
 * viewed and adjusted when needed." The activity list answers the
 * "what's happening / what's next" planning frame; this answers the
 * retrospective frame — find the row to finalise, scrub a late goal,
 * lock it.
 *
 * Scope per the analyst decisions on the issue (2026-05-30):
 *   - All states shown by default (live + pending_review + finalized).
 *   - Default sort = session_date DESC.
 *   - Entry point lives under Activities (not the analytics surface).
 *   - Cap-gated on `tt_view_activities`; coaches see own teams,
 *     HoD/Admin see club-wide.
 *
 * #4194 — the rows come from `GET /match-executions` through the shared
 * list table, so the list gets the same sort, paging and phone layout as
 * every other list. The team scope and the query live in
 * `MatchExecutionListQuery`; the view only composes.
 */
final class FrontendMatchExecutionsListView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_activities' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view match executions.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Match executions', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) ) ]
        );
        self::enqueueAssets();
        self::enqueueViewCss();
        self::renderHeader( __( 'Match executions', 'talenttrack' ) );

        $teams = MatchExecutionListQuery::teamsForUser( $user_id, $is_admin );
        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams visible to you yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_options = [];
        foreach ( $teams as $t ) {
            $team_options[ (string) (int) $t->id ] = (string) $t->name;
        }

        $state_options = [
            'live'                              => __( 'Live', 'talenttrack' ),
            MatchExecutionState::PENDING_REVIEW => __( 'Pending review', 'talenttrack' ),
            MatchExecutionState::FINALIZED      => __( 'Finalized', 'talenttrack' ),
        ];

        $filters = [
            'team_id' => [ 'type' => 'select', 'label' => __( 'Team', 'talenttrack' ), 'options' => $team_options ],
            'state'   => [ 'type' => 'select', 'label' => __( 'State', 'talenttrack' ), 'options' => $state_options ],
            'date'    => [
                'type'       => 'date_range',
                'label'      => _x( 'Date', 'list date filter', 'talenttrack' ),
                'param_from' => 'date_from',
                'param_to'   => 'date_to',
                'label_from' => __( 'From', 'talenttrack' ),
                'label_to'   => __( 'To', 'talenttrack' ),
            ],
        ];

        // Links written before the list table (the "Matches to review"
        // widget, bookmarks) carry `?team_id=` / `?state=`. They open with
        // that filter set.
        $legacy_team = isset( $_GET['team_id'] ) ? (string) absint( $_GET['team_id'] ) : '';
        if ( isset( $team_options[ $legacy_team ] ) ) {
            $filters['team_id']['default'] = $legacy_team;
        }
        $legacy_state = isset( $_GET['state'] ) ? sanitize_key( (string) wp_unslash( $_GET['state'] ) ) : '';
        if ( isset( $state_options[ $legacy_state ] ) ) {
            $filters['state']['default'] = $legacy_state;
        }

        echo '<p class="tt-mex-window-hint">' . esc_html__( 'Without dates the list shows the last 12 months.', 'talenttrack' ) . '</p>';

        echo FrontendListTable::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the component escapes internally.
            'rest_path'    => 'match-executions',
            'columns'      => [
                'session_date' => [ 'label' => __( 'Date', 'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'date_link_html' ],
                'team_name'    => [ 'label' => __( 'Team', 'talenttrack' ), 'sortable' => true ],
                'opponent'     => [ 'label' => __( 'Opponent', 'talenttrack' ), 'sortable' => true, 'value_key' => 'opponent_display' ],
                'score'        => [ 'label' => __( 'Score', 'talenttrack' ) ],
                'state'        => [ 'label' => __( 'State', 'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'state_pill_html' ],
            ],
            'filters'      => $filters,
            'row_url_key'  => 'detail_url',
            'default_sort' => [ 'orderby' => 'session_date', 'order' => 'desc' ],
            'empty_state'  => __( 'No match executions match the current filters.', 'talenttrack' ),
        ] );
    }

    private static function enqueueViewCss(): void {
        wp_enqueue_style(
            'tt-frontend-match-executions',
            TT_PLUGIN_URL . 'assets/css/frontend-match-executions.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }
}
