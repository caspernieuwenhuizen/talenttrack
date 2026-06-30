<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Services\MeasurementResultsBrowse;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
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

        self::renderHeader( $title );

        // Team scope: global readers see all teams; team-scoped readers only
        // their own (mirrors FrontendMeasurementCoverageView).
        $see_all = $is_admin || MatrixGate::can( $user_id, 'measurements', 'read', 'global' );
        $teams   = $see_all ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        $teams   = is_array( $teams ) ? $teams : [];

        $definitions = ( new MeasurementDefinitionsRepository() )->listAll();
        if ( $definitions === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No tests are defined yet. Add a test under Manage tests to start recording results.', 'talenttrack' ) . '</p>';
            return;
        }

        $allowed_ids = array_map( static fn ( $t ) => (int) $t->id, $teams );

        // Selected filters.
        $definition_id = isset( $_GET['definition_id'] ) ? absint( $_GET['definition_id'] ) : 0;
        $team_id       = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $age_group     = isset( $_GET['age_group'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['age_group'] ) ) : '';
        $date_from     = isset( $_GET['from'] ) ? self::safeDate( (string) $_GET['from'] ) : '';
        $date_to       = isset( $_GET['to'] ) ? self::safeDate( (string) $_GET['to'] ) : '';

        // A non-global reader may only filter by — and see results from — a
        // team in their scope. An out-of-scope team_id is rejected.
        if ( ! $see_all && $team_id > 0 && ! in_array( $team_id, $allowed_ids, true ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this team.', 'talenttrack' ) . '</p>';
            return;
        }

        $age_groups = self::ageGroupsFrom( $teams );

        self::renderFilters( $definitions, $teams, $age_groups, $definition_id, $team_id, $age_group, $date_from, $date_to );

        if ( $definition_id <= 0 ) {
            echo '<p class="tt-tr-hint">' . esc_html__( 'Choose a test to see every player\'s latest result.', 'talenttrack' ) . '</p>';
            return;
        }

        // For a non-global reader with no team chosen, constrain the query to
        // their teams so the grid never leaks out-of-scope players.
        $filters = [
            'team_id'   => $team_id,
            'age_group' => $age_group,
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ];
        // A non-global reader with a chosen team is already validated above,
        // so the team-scoped query is in scope. With no team chosen, re-run
        // the query per allowed team so out-of-scope players never load.
        if ( ! $see_all && $team_id <= 0 ) {
            $rows = self::limitToAllowedTeams( $definition_id, $filters, $allowed_ids );
        } else {
            $rows = ( new MeasurementResultsBrowse() )->rows( $definition_id, $filters );
        }

        self::renderExportLink( $definition_id, $team_id, $date_from, $date_to );

        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No results match these filters yet.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderGrid( $rows );
    }

    /**
     * Re-run the browse query once per allowed team and merge — keeps the
     * grid inside a non-global reader's team scope when they pick no team.
     *
     * @param array<string, mixed> $filters
     * @param array<int, int>      $allowed_ids
     * @return array<int, array<string, mixed>>
     */
    private static function limitToAllowedTeams( int $definition_id, array $filters, array $allowed_ids ): array {
        if ( $allowed_ids === [] ) return [];
        $browse = new MeasurementResultsBrowse();
        $merged = [];
        foreach ( $allowed_ids as $tid ) {
            $scoped            = $filters;
            $scoped['team_id'] = $tid;
            foreach ( $browse->rows( $definition_id, $scoped ) as $row ) {
                $merged[ (int) $row['player_id'] ] = $row;
            }
        }
        usort( $merged, static fn ( $a, $b ) => strcasecmp( (string) $a['name'], (string) $b['name'] ) );
        return array_values( $merged );
    }

    /**
     * @param array<int, object>      $definitions
     * @param array<int, object>      $teams
     * @param array<int, string>      $age_groups
     */
    private static function renderFilters(
        array $definitions, array $teams, array $age_groups,
        int $definition_id, int $team_id, string $age_group, string $date_from, string $date_to
    ): void {
        echo '<form method="get" class="tt-tr-filters">';
        echo '<input type="hidden" name="tt_view" value="test-results" />';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'Test', 'talenttrack' ) . '</span>';
        echo '<select name="definition_id" class="tt-input">';
        echo '<option value="0">' . esc_html__( '— Choose a test —', 'talenttrack' ) . '</option>';
        foreach ( $definitions as $def ) {
            $label = (string) $def->name;
            if ( ! empty( $def->category_label ) ) {
                $label = (string) $def->category_label . ' · ' . $label;
            }
            echo '<option value="' . (int) $def->id . '" ' . selected( $definition_id, (int) $def->id, false ) . '>'
                . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id" class="tt-input">';
        echo '<option value="0">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( $teams as $t ) {
            echo '<option value="' . (int) $t->id . '" ' . selected( $team_id, (int) $t->id, false ) . '>'
                . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'Age group', 'talenttrack' ) . '</span>';
        echo '<select name="age_group" class="tt-input">';
        echo '<option value="">' . esc_html__( 'All age groups', 'talenttrack' ) . '</option>';
        foreach ( $age_groups as $ag ) {
            echo '<option value="' . esc_attr( $ag ) . '" ' . selected( $age_group, $ag, false ) . '>'
                . esc_html( $ag ) . '</option>';
        }
        echo '</select></label>';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="from" class="tt-input" inputmode="numeric" value="' . esc_attr( $date_from ) . '" />';
        echo '</label>';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="to" class="tt-input" inputmode="numeric" value="' . esc_attr( $date_to ) . '" />';
        echo '</label>';

        echo '<div class="tt-tr-filters__actions">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Show', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
    }

    /**
     * The export affordance for the current test + filters — a small POST to
     * admin-post.php (the #2139 measurement_results_xlsx exporter). The
     * pipeline re-enforces `measurements/read` server-side; this is the
     * trigger only. Back-aware so a failed export round-trips here.
     */
    private static function renderExportLink( int $definition_id, int $team_id, string $date_from, string $date_to ): void {
        if ( $definition_id <= 0 ) return;

        $return_url = BackLink::appendTo( add_query_arg(
            [ 'tt_view' => 'test-results', 'definition_id' => $definition_id ],
            RecordLink::dashboardUrl()
        ) );

        echo '<form method="POST" class="tt-tr-export" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tt_export', '_tt_export_nonce' );
        echo '<input type="hidden" name="action" value="tt_export" />';
        echo '<input type="hidden" name="tt_export_key" value="measurement_results_xlsx" />';
        echo '<input type="hidden" name="format" value="xlsx" />';
        echo '<input type="hidden" name="definition_id" value="' . esc_attr( (string) $definition_id ) . '" />';
        if ( $team_id > 0 ) {
            echo '<input type="hidden" name="team_id" value="' . esc_attr( (string) $team_id ) . '" />';
        }
        if ( $date_from !== '' ) {
            echo '<input type="hidden" name="date_from" value="' . esc_attr( $date_from ) . '" />';
        }
        if ( $date_to !== '' ) {
            echo '<input type="hidden" name="date_to" value="' . esc_attr( $date_to ) . '" />';
        }
        echo '<input type="hidden" name="tt_export_return_url" value="' . esc_attr( $return_url ) . '" />';
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Export to Excel', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function renderGrid( array $rows ): void {
        echo '<div class="tt-tr-grid">';
        echo '<table class="tt-tr-table tt-table-sortable" data-tt-table-search="off">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Age group', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Result', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Trend', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Date', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $player_url = RecordLink::detailUrlForWithBack( 'players', (int) $row['player_id'] );
            echo '<tr>';

            echo '<td data-label="' . esc_attr__( 'Player', 'talenttrack' ) . '" class="tt-tr-cell--name">';
            if ( $player_url !== '' ) {
                echo '<a class="tt-record-link" href="' . esc_url( $player_url ) . '">' . esc_html( (string) $row['name'] ) . '</a>';
            } else {
                echo esc_html( (string) $row['name'] );
            }
            echo '</td>';

            echo '<td data-label="' . esc_attr__( 'Team', 'talenttrack' ) . '">' . esc_html( (string) $row['team_name'] ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'Age group', 'talenttrack' ) . '">' . esc_html( (string) $row['age_group'] ) . '</td>';

            // Result: status chip, or value + flag.
            echo '<td data-label="' . esc_attr__( 'Result', 'talenttrack' ) . '" class="tt-tr-cell--result" data-tt-sort-value="' . esc_attr( (string) ( $row['value_sort'] ?? '' ) ) . '">';
            echo self::resultCell( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — resultCell() escapes internally.
            echo '</td>';

            echo '<td data-label="' . esc_attr__( 'Trend', 'talenttrack' ) . '">' . self::trendCell( (string) $row['trend'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trendCell() escapes internally.
            echo '<td data-label="' . esc_attr__( 'Date', 'talenttrack' ) . '">' . esc_html( (string) $row['recorded_date'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** @param array<string, mixed> $row */
    private static function resultCell( array $row ): string {
        $value_type = (string) $row['value_type'];
        if ( $value_type === 'status' ) {
            $token = (string) $row['level_token'];
            $label = (string) $row['level_label'];
            if ( $label === '' ) {
                return '<span class="tt-tr-empty">—</span>';
            }
            return '<span class="tt-tr-level">'
                . '<span class="tt-mlvl-swatch ' . esc_attr( MeasurementLevelPalette::cssClass( $token ) ) . '" aria-hidden="true"></span>'
                . '<span class="tt-tr-level__label">' . esc_html( $label ) . '</span></span>';
        }

        $value = (string) $row['value'];
        if ( $value === '' ) {
            return '<span class="tt-tr-empty">—</span>';
        }
        $flag = (string) $row['flag'];
        $out  = '<span class="tt-tr-value">' . esc_html( $value ) . '</span>';
        if ( $flag !== '' ) {
            $out .= ' <span class="tt-tr-flag tt-tr-flag--' . esc_attr( sanitize_html_class( $flag ) ) . '" title="'
                . esc_attr( self::flagLabel( $flag ) ) . '">'
                . '<span class="tt-tr-sr">' . esc_html( self::flagLabel( $flag ) ) . '</span></span>';
        }
        return $out;
    }

    private static function trendCell( string $trend ): string {
        if ( $trend === '' ) {
            return '<span class="tt-tr-empty">—</span>';
        }
        $map = [
            'up'   => [ '▲', __( 'Improved', 'talenttrack' ) ],
            'down' => [ '▼', __( 'Declined', 'talenttrack' ) ],
            'flat' => [ '▬', __( 'Unchanged', 'talenttrack' ) ],
        ];
        [ $glyph, $label ] = $map[ $trend ] ?? $map['flat'];
        return '<span class="tt-tr-trend tt-tr-trend--' . esc_attr( sanitize_html_class( $trend ) ) . '" title="' . esc_attr( $label ) . '">'
            . '<span aria-hidden="true">' . esc_html( $glyph ) . '</span>'
            . '<span class="tt-tr-sr">' . esc_html( $label ) . '</span></span>';
    }

    private static function flagLabel( string $flag ): string {
        switch ( $flag ) {
            case 'ok':   return __( 'On target', 'talenttrack' );
            case 'warn': return __( 'Below target', 'talenttrack' );
            case 'bad':  return __( 'Well below target', 'talenttrack' );
            default:     return '';
        }
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

    /** Accept only a YYYY-MM-DD date; anything else collapses to ''. */
    private static function safeDate( string $value ): string {
        $value = sanitize_text_field( wp_unslash( $value ) );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
    }
}
