<?php
namespace TT\Modules\Activities\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Shared\Frontend\Components\FilterBar;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMinutesGridView (#2386, epic #2381) — the desktop minutes-entry
 * grid, the sibling of the attendance grid restricted to match activities.
 * Players in rows, matches in columns, a minutes box per squad cell, one
 * explicit Save. Reads/writes the same effective minutes the Minutes-audit
 * matrix and the Minutes-played report use, so all three reconcile.
 *
 * URL: `?tt_view=minutes-grid&team_id=N&from=YYYY-MM-DD&to=YYYY-MM-DD`
 *
 * Write-capable → gated on `tt_edit_activities` (the cap the bulk endpoint
 * enforces) plus the `minutes_grid` feature. Only squad players (a non-guest
 * attendance row) are editable; the bulk write routes each edit through the
 * minutes-ownership arbiter server-side (override vs. direct minutes).
 */
final class FrontendMinutesGridView extends FrontendViewBase {

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        // Reuse the attendance grid's chrome sheet, add the minutes specifics.
        wp_enqueue_style(
            'tt-frontend-attendance-grid',
            TT_PLUGIN_URL . 'assets/css/frontend-attendance-grid.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-frontend-minutes-grid',
            TT_PLUGIN_URL . 'assets/css/frontend-minutes-grid.css',
            [ 'tt-frontend-attendance-grid' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-minutes-grid',
            TT_PLUGIN_URL . 'assets/js/frontend-minutes-grid.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-minutes-grid', 'TTMinutesGrid', [
            'restBulk' => esc_url_raw( rest_url( 'talenttrack/v1/minutes/bulk' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'i18n'     => [
                'saving'    => __( 'Saving…', 'talenttrack' ),
                'saved'     => __( 'All changes saved', 'talenttrack' ),
                'error'     => __( 'Could not save — try again', 'talenttrack' ),
                'noChanges' => __( 'No unsaved changes', 'talenttrack' ),
                /* translators: %d is the number of unsaved cell changes. */
                'unsaved'   => __( '%d unsaved change(s)', 'talenttrack' ),
                'confirm'   => __( 'You have unsaved changes. Leave without saving?', 'talenttrack' ),
            ],
        ] );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        if ( ! current_user_can( 'tt_edit_activities' ) ) {
            self::crumbs();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to record minutes.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'minutes_grid' ) ) {
            self::crumbs();
            echo '<p class="tt-notice">' . esc_html__( 'The minutes grid has been switched off for your academy.', 'talenttrack' ) . '</p>';
            return;
        }

        self::crumbs();
        self::renderHeader( __( 'Minutes grid', 'talenttrack' ) );
        echo '<p class="tt-agrid-lead">' . esc_html__(
            'Record match minutes for a whole period at once. Rows are players, columns are matches; type the minutes per cell and Save. Only players in a match squad can be edited.',
            'talenttrack'
        ) . '</p>';

        self::renderModeNav();

        $is_scope_admin = $is_admin
            || \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsActivities( $user_id );
        $allowed_team_ids = $is_scope_admin
            ? null
            : array_values( array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' ) ) );

        if ( ! $is_scope_admin && $allowed_team_ids === [] ) {
            echo '<p class="tt-notice">' . esc_html__( "You don't coach any teams yet, so there are no minutes to record. Ask an administrator to assign you to a team.", 'talenttrack' ) . '</p>';
            return;
        }

        $teams = self::listTeams( $allowed_team_ids );
        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams yet — add one to record minutes.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( $allowed_team_ids !== null && $team_id > 0 && ! in_array( $team_id, $allowed_team_ids, true ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'That team is not in your scope.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $team_id <= 0 || ! self::teamExists( $team_id, $teams ) ) {
            $team_id = (int) $teams[0]->id;
        }

        $defaults = ReportFilters::seasonDefaultWindow();
        $period = ReportFilters::sanitizePeriod( isset( $_GET['period'] ) ? sanitize_key( (string) $_GET['period'] ) : '' );
        $has_manual_from = isset( $_GET['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['from'] );
        $has_manual_to   = isset( $_GET['to'] )   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['to'] );
        $window = $period !== '' ? ReportFilters::periodWindow( $period, gmdate( 'Y-m-d' ) ) : null;
        $from = $has_manual_from ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : ( $window['from'] ?? $defaults['from'] );
        $to   = $has_manual_to   ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) )   : ( $window['to'] ?? $defaults['to'] );

        self::renderFilterForm( $teams, $team_id, $from, $to, $period );

        $matrix = ( new MinutesGridQuery() )->matrix( $team_id, $from, $to );

        if ( $matrix['summary']['total_players'] === 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'No active players on this team yet. Add players to the roster first.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $matrix['summary']['total_activities'] === 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'No matches for this team in the selected window. Widen the date range or pick another team.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderGrid( $matrix );
    }

    private static function crumbs(): void {
        FrontendBreadcrumbs::fromDashboard(
            __( 'Minutes grid', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) ) ]
        );
    }

    /** Segmented Attendance | Minutes toggle across the two grid surfaces. */
    private static function renderModeNav(): void {
        $dash = RecordLink::dashboardUrl();
        $team = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $att_args = [ 'tt_view' => 'attendance-grid' ]; /* tt-xview-ok — sibling grid, gated by the attendance_grid feature below */
        if ( $team > 0 ) $att_args['team_id'] = $team;
        echo '<div class="tt-agrid-modes" role="tablist">';
        if ( \TT\Core\FeatureRegistry::isEnabled( 'attendance_grid' ) ) {
            echo '<a class="tt-agrid-mode" href="' . esc_url( add_query_arg( $att_args, $dash ) ) . '">' . esc_html__( 'Attendance', 'talenttrack' ) . '</a>';
        }
        echo '<span class="tt-agrid-mode is-on" aria-current="page">' . esc_html__( 'Minutes', 'talenttrack' ) . '</span>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $matrix
     */
    private static function renderGrid( array $matrix ): void {
        /** @var list<array<string,mixed>> $players */
        $players = $matrix['players'];
        /** @var list<array<string,mixed>> $activities */
        $activities = $matrix['activities'];
        /** @var array<int,array<int,array{minutes:int,squad:bool}>> $cells */
        $cells = $matrix['cells'];

        echo '<div class="tt-agrid-card">';
        echo '<div class="tt-agrid-card__head">';
        echo '<h2 class="tt-agrid-card__title">' . esc_html__( 'Minutes', 'talenttrack' ) . '</h2>';
        echo '<span class="tt-agrid-card__hint">' . esc_html__( 'type minutes per match, then Save', 'talenttrack' ) . '</span>';
        echo '</div>';

        echo '<div class="tt-agrid-scroll">';
        echo '<table class="tt-agrid">';
        echo '<thead><tr>';
        echo '<th class="tt-agrid__player" scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        foreach ( $activities as $a ) {
            $date  = $a['session_date'] !== '' ? date_i18n( 'j M', strtotime( (string) $a['session_date'] ) ) : '';
            $label = (string) $a['title'] !== '' ? (string) $a['title'] : __( 'Match', 'talenttrack' );
            $owned = ! empty( $a['owned_by_execution'] );
            echo '<th class="tt-agrid__act is-match" scope="col" title="' . esc_attr( $label ) . '">';
            echo '<span class="tt-agrid__act-date">' . esc_html( $date ) . '</span>';
            echo '<span class="tt-agrid__act-type" aria-hidden="true">⚽</span>';
            if ( $owned ) {
                echo '<span class="tt-agrid__act-owned" title="' . esc_attr__( 'Minutes come from the match sheet; your entry is kept as a correction.', 'talenttrack' ) . '">' . esc_html__( 'live', 'talenttrack' ) . '</span>';
            }
            echo '</th>';
        }
        echo '<th class="tt-agrid__rate" scope="col">' . esc_html__( 'Total', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $players as $pl ) {
            $pid    = (int) $pl['player_id'];
            $jersey = $pl['jersey_number'] !== null ? (int) $pl['jersey_number'] : null;
            $name   = trim( (string) $pl['first_name'] . ' ' . (string) $pl['last_name'] );
            if ( $name === '' ) $name = '#' . $pid;

            echo '<tr>';
            echo '<th class="tt-agrid__player" scope="row"><span class="tt-agrid__who">';
            if ( $jersey !== null ) echo '<span class="tt-agrid__no">' . esc_html( (string) $jersey ) . '</span>';
            echo '<span class="tt-agrid__nm">' . esc_html( $name ) . '</span>';
            echo '</span></th>';

            $total = 0;
            foreach ( $activities as $a ) {
                $aid  = (int) $a['activity_id'];
                $cell = $cells[ $pid ][ $aid ] ?? null;
                if ( $cell === null ) {
                    // Not in this match's squad — informational, not editable.
                    echo '<td class="tt-agrid-cell tt-agrid-cell--na" aria-label="' . esc_attr__( 'Not in squad', 'talenttrack' ) . '">&mdash;</td>';
                    continue;
                }
                $mins = (int) $cell['minutes'];
                $total += $mins;
                echo '<td class="tt-agrid-cell tt-agrid-min" data-player="' . esc_attr( (string) $pid ) . '" data-activity="' . esc_attr( (string) $aid ) . '">';
                echo '<input class="tt-agrid-min-in" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="' . esc_attr( (string) $mins ) . '" data-player="' . esc_attr( (string) $pid ) . '" data-activity="' . esc_attr( (string) $aid ) . '" aria-label="' . esc_attr( sprintf(
                    /* translators: 1: player name, 2: match date. */
                    __( 'Minutes for %1$s on %2$s', 'talenttrack' ),
                    $name,
                    $a['session_date'] !== '' ? date_i18n( 'j M', strtotime( (string) $a['session_date'] ) ) : ''
                ) ) . '">';
                echo '</td>';
            }
            echo '<td class="tt-agrid__rate">' . esc_html( (string) number_format_i18n( $total ) ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        $cancel_url = add_query_arg( [ 'tt_view' => 'activities' ], RecordLink::dashboardUrl() ); /* tt-xview-ok */
        $back = \TT\Shared\Frontend\Components\BackLink::resolve();
        if ( $back !== null ) {
            $cancel_url = $back['url'];
        }
        echo '<div class="tt-agrid-savebar">';
        echo '<span class="tt-agrid-savebar__status" data-agrid-status role="status" aria-live="polite">' . esc_html__( 'No unsaved changes', 'talenttrack' ) . '</span>';
        echo '<div class="tt-form-actions tt-agrid-savebar__actions">';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel', 'talenttrack' ) . '</a>';
        echo '<button type="button" class="tt-btn tt-btn-primary" data-agrid-save disabled>' . esc_html__( 'Save', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .tt-agrid-card
    }

    /**
     * @param list<object> $teams
     */
    private static function renderFilterForm( array $teams, int $team_id, string $from, string $to, string $period ): void {
        $dash_url      = RecordLink::dashboardUrl();
        $period_labels = ReportFilters::periodLabels();

        $team_options = [];
        foreach ( $teams as $t ) {
            $team_options[ (string) (int) $t->id ] = (string) $t->name;
        }

        $pill_base = [ 'tt_view' => 'minutes-grid', 'team_id' => $team_id ];
        if ( ! empty( $_GET['tt_back'] ) ) $pill_base['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) );

        $period_options = [];
        foreach ( $period_labels as $key => $label ) {
            $args = $pill_base;
            if ( $key !== '' ) $args['period'] = $key;
            $period_options[] = [
                'value'  => $key,
                'label'  => $label,
                'url'    => add_query_arg( $args, $dash_url ),
                'active' => ( $period === $key ),
            ];
        }

        $hidden = [ 'tt_view' => 'minutes-grid' ];
        if ( $period !== '' )              $hidden['period']  = $period;
        if ( ! empty( $_GET['tt_back'] ) ) $hidden['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) );

        $active_count = 0;
        $chips = [];
        if ( $period !== '' ) { $active_count++; $chips[] = (string) ( $period_labels[ $period ] ?? '' ); }

        $reset_args = [ 'tt_view' => 'minutes-grid', 'team_id' => $team_id ];
        if ( ! empty( $_GET['tt_back'] ) ) $reset_args['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) );

        FilterBar::render( [
            'hidden'       => $hidden,
            'active_count' => $active_count,
            'chips'        => $chips,
            'reset_url'    => add_query_arg( $reset_args, $dash_url ),
            'groups'       => [
                [
                    'type'     => 'select',
                    'key'      => 'team',
                    'label'    => __( 'Team', 'talenttrack' ),
                    'name'     => 'team_id',
                    'selected' => (string) $team_id,
                    'options'  => $team_options,
                ],
                [
                    'type'         => 'period',
                    'key'          => 'period',
                    'label'        => __( 'Period', 'talenttrack' ),
                    'active_label' => (string) ( $period_labels[ $period ] ?? $period_labels[''] ),
                    'options'      => $period_options,
                ],
                [
                    'type'       => 'date_range',
                    'key'        => 'range',
                    'label'      => __( 'Date range', 'talenttrack' ),
                    'label_from' => __( 'From', 'talenttrack' ),
                    'label_to'   => __( 'To', 'talenttrack' ),
                    'from'       => [ 'name' => 'from', 'value' => $from ],
                    'to'         => [ 'name' => 'to', 'value' => $to ],
                ],
            ],
        ] );
    }

    /**
     * @param list<int>|null $allowed_team_ids
     * @return list<object>
     */
    private static function listTeams( ?array $allowed_team_ids = null ): array {
        global $wpdb;
        if ( $allowed_team_ids !== null ) {
            if ( $allowed_team_ids === [] ) return [];
            $placeholders = implode( ',', array_fill( 0, count( $allowed_team_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}tt_teams
                  WHERE club_id = %d AND " . \TT\Infrastructure\Archive\ArchiveRepository::filterClause( 'active' ) . " AND id IN ($placeholders)
                  ORDER BY name ASC",
                CurrentClub::id(), ...$allowed_team_ids
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}tt_teams
                  WHERE club_id = %d AND " . \TT\Infrastructure\Archive\ArchiveRepository::filterClause( 'active' ) . "
                  ORDER BY name ASC",
                CurrentClub::id()
            ) );
        }
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param list<object> $teams
     */
    private static function teamExists( int $team_id, array $teams ): bool {
        foreach ( $teams as $t ) {
            if ( (int) $t->id === $team_id ) return true;
        }
        return false;
    }
}
