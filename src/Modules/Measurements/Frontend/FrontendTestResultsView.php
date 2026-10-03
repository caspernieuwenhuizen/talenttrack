<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTestResultsView (#2145) — the "Test results" analysis surface.
 *
 * Browse every measurement result in one place: pick a test (+ optional
 * team / age group / date window) and read each player's latest value with
 * its status-level colour chip, or its green/amber flag and ▲/▼ trend
 * against the previous result, sortable and clickable through to the player.
 *
 * Player-centric (§1): one row per player, name links to their profile.
 * Composition only — the rows, flags, levels and trend come from
 * MeasurementResultsBrowse (§4 — business logic out of the view). Slug:
 * `test-results`.
 *
 * #4194 — the rows are drawn by the shared list table from
 * `GET /measurement-results`, the same route a SaaS front end reads, so the
 * list gets the shared sort, paging and phone layout. The cell markup lives
 * in `TestResultCells`.
 */
final class FrontendTestResultsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Test results', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $title );

        if ( ! $is_admin && ! MatrixGate::canAnyScope( $user_id, 'measurements', 'read' ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to browse test results.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-test-results',
            TT_PLUGIN_URL . 'assets/css/frontend-test-results.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-measurement-levels',
            TT_PLUGIN_URL . 'assets/css/frontend-measurement-levels.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-test-results',
            TT_PLUGIN_URL . 'assets/js/frontend-test-results.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-test-results', 'ttTestResultsI18n', [
            'chooseTest' => __( 'Choose a test first, then export.', 'talenttrack' ),
        ] );

        self::renderHeader( $title );

        // Team scope: global readers see all teams; everyone else only the
        // teams the gate admits them to.
        //
        // #3433 — that is no longer the same as "the teams you are attached
        // to". Measurement access follows the functional role held on a
        // squad, so a staff member who is the physio of one team and the kit
        // manager of another is scoped to both and may read one. The REST
        // route narrows to the same set, so the list never shows a player
        // from a team that is not offered here.
        $see_all = $is_admin || MatrixGate::can( $user_id, 'measurements', 'read', 'global' );
        $teams   = QueryHelpers::get_permitted_teams( $user_id, 'measurements', 'read', $see_all );

        $definitions = ( new MeasurementDefinitionsRepository() )->listAll();
        if ( $definitions === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No tests are defined yet. Add a test under Manage tests to start recording results.', 'talenttrack' ) . '</p>';
            return;
        }

        $definition_options = [];
        foreach ( $definitions as $def ) {
            $label = (string) $def->name;
            if ( ! empty( $def->category_label ) ) {
                $label = (string) $def->category_label . ' · ' . $label;
            }
            $definition_options[ (string) (int) $def->id ] = $label;
        }
        $team_options = [];
        foreach ( $teams as $t ) {
            $team_options[ (string) (int) $t->id ] = (string) $t->name;
        }
        $age_options = [];
        foreach ( self::ageGroupsFrom( $teams ) as $ag ) {
            $age_options[ $ag ] = $ag;
        }

        $filters = [
            'definition_id' => [ 'type' => 'select', 'label' => __( 'Test', 'talenttrack' ), 'options' => $definition_options ],
            'team_id'       => [ 'type' => 'select', 'label' => __( 'Team', 'talenttrack' ), 'options' => $team_options ],
            'age_group'     => [ 'type' => 'select', 'label' => __( 'Age group', 'talenttrack' ), 'options' => $age_options ],
            'date'          => [
                'type'       => 'date_range',
                'label'      => _x( 'Date', 'list date filter', 'talenttrack' ),
                'param_from' => 'date_from',
                'param_to'   => 'date_to',
                'label_from' => __( 'From', 'talenttrack' ),
                'label_to'   => __( 'To', 'talenttrack' ),
            ],
        ];

        // Links written before the list table carry `?definition_id=` and
        // `?team_id=`; they open with that test and team chosen.
        foreach ( [ 'definition_id' => $definition_options, 'team_id' => $team_options ] as $key => $options ) {
            $legacy = isset( $_GET[ $key ] ) ? (string) absint( $_GET[ $key ] ) : '';
            if ( isset( $options[ $legacy ] ) ) $filters[ $key ]['default'] = $legacy;
        }

        self::renderExportForm();

        echo '<div class="tt-tr-list">';
        echo FrontendListTable::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the component escapes internally.
            'rest_path'    => 'measurement-results',
            // `mobile` roles (#4192): on a phone a row reads as the player,
            // with team and age group under the name and the result and
            // trend at the right.
            'columns'      => [
                'name'          => [ 'label' => __( 'Player', 'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'player_html', 'mobile' => 'primary' ],
                'team_name'     => [ 'label' => __( 'Team', 'talenttrack' ), 'sortable' => true, 'mobile' => 'secondary' ],
                'age_group'     => [ 'label' => __( 'Age group', 'talenttrack' ), 'sortable' => true, 'mobile' => 'secondary' ],
                'value'         => [ 'label' => __( 'Result', 'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'result_html', 'mobile' => 'badge' ],
                'trend'         => [ 'label' => __( 'Trend', 'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'trend_html', 'mobile' => 'badge' ],
                'recorded_date' => [ 'label' => __( 'Date', 'talenttrack' ), 'sortable' => true, 'mobile' => 'detail' ],
            ],
            'filters'      => $filters,
            'default_sort' => [ 'orderby' => 'name', 'order' => 'asc' ],
            'empty_state'  => __( 'No results match. Check that a test is chosen, or widen the filters.', 'talenttrack' ),
            'empty_state_card' => [
                'headline'  => __( 'Choose a test', 'talenttrack' ),
                'explainer' => __( 'Choose a test in the filters to see every player\'s latest result.', 'talenttrack' ),
            ],
        ] );
        echo '</div>';
    }

    /**
     * The export trigger — a small POST to admin-post.php (the #2139
     * measurement_results_xlsx exporter), which re-enforces
     * `measurements/read` server-side. The list filters live in the URL and
     * change without a reload, so frontend-test-results.js copies the
     * chosen test, team and dates into the hidden fields when the button is
     * pressed, and asks for a test first when none is chosen.
     */
    private static function renderExportForm(): void {
        echo '<form method="POST" class="tt-tr-export" data-tt-tr-export="1" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tt_export', '_tt_export_nonce' );
        echo '<input type="hidden" name="action" value="tt_export" />';
        echo '<input type="hidden" name="tt_export_key" value="measurement_results_xlsx" />';
        echo '<input type="hidden" name="format" value="xlsx" />';
        echo '<input type="hidden" name="definition_id" value="" />';
        echo '<input type="hidden" name="team_id" value="" />';
        echo '<input type="hidden" name="date_from" value="" />';
        echo '<input type="hidden" name="date_to" value="" />';
        echo '<input type="hidden" name="tt_export_return_url" value="" />';
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Export Excel', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    /**
     * Distinct, sorted age groups across the visible teams.
     *
     * @param array<int, object> $teams
     * @return array<int, string>
     */
    private static function ageGroupsFrom( array $teams ): array {
        $set = [];
        foreach ( $teams as $t ) {
            $ag = trim( (string) ( $t->age_group ?? '' ) );
            if ( $ag !== '' ) $set[ $ag ] = true;
        }
        $groups = array_keys( $set );
        sort( $groups );
        return $groups;
    }
}
