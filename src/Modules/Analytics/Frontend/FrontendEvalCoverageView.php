<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Analytics\EvalWindowsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendAppChrome;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendEvalCoverageView (#1380) — HoD evaluation-window coverage.
 *
 * Answers "which players have NOT been evaluated this window, and which
 * coach owns the gap?". Renders four surfaces, all fed by
 * EvalCoverageService (no coverage logic lives here):
 *
 *   1. Windows editor — a settings-style sub-form to add / remove the
 *      season's evaluation windows (Save-only per CLAUDE.md §6 (a):
 *      this is a config sub-form, not a record edit).
 *   2. Coverage matrix — players grouped by team × windows, each cell
 *      covered (✓) or a gap (•), with a per-coach gap header strip.
 *   3. Coach filter — a `?coach_id=` link-out to the evaluations list.
 *   4. Attendance-recording compliance strip — per team, the share of
 *      completed activities in each window with any attendance recorded.
 *
 * Cap-gated on `tt_view_analytics`. Scope: club-wide.
 *
 * @phpstan-import-type EvalWindow from EvalCoverageService
 * @phpstan-import-type CoverageData from EvalCoverageService
 * @phpstan-import-type CoachGap from EvalCoverageService
 * @phpstan-import-type AttendanceRow from EvalCoverageService
 * @phpstan-import-type Evaluator from EvalCoverageService
 */
final class FrontendEvalCoverageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-eval-coverage',
            TT_PLUGIN_URL . 'assets/css/frontend-eval-coverage.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Not authorized', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this report.', 'talenttrack' ) . '</p>';
            return;
        }

        $notice = self::maybeSaveWindows();

        FrontendBreadcrumbs::fromDashboard(
            __( 'Evaluation coverage', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Evaluation-window coverage', 'talenttrack' ) );

        if ( $notice !== '' ) {
            echo '<p class="tt-notice tt-notice--ok">' . esc_html( $notice ) . '</p>';
        }

        $service  = new EvalCoverageService();
        $coverage = $service->coverage();
        $windows  = $coverage['windows'];

        self::renderWindowsEditor( $windows );

        if ( $windows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'Add at least one evaluation window above to see coverage.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderSummary( $coverage );
        self::renderCoachGaps( $coverage['coach_gaps'] );
        self::renderCoachFilter( $service->evaluators() );
        self::renderMatrix( $coverage );
        self::renderAttendanceStrip( $service, $windows );
    }

    /**
     * Handle the windows sub-form POST. Returns a confirmation message
     * on a successful save, or '' when nothing was submitted.
     */
    private static function maybeSaveWindows(): string {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return '';
        if ( ! isset( $_POST['tt_ec_windows_nonce'] ) ) return '';
        $nonce = sanitize_text_field( wp_unslash( (string) $_POST['tt_ec_windows_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'tt_ec_save_windows' ) ) return '';
        if ( ! current_user_can( 'tt_view_analytics' ) ) return '';

        $names  = isset( $_POST['win_name'] )  && is_array( $_POST['win_name'] )  ? wp_unslash( $_POST['win_name'] )  : [];
        $starts = isset( $_POST['win_start'] ) && is_array( $_POST['win_start'] ) ? wp_unslash( $_POST['win_start'] ) : [];
        $ends   = isset( $_POST['win_end'] )   && is_array( $_POST['win_end'] )   ? wp_unslash( $_POST['win_end'] )   : [];

        $rows = [];
        $count = max( count( $names ), count( $starts ), count( $ends ) );
        for ( $i = 0; $i < $count; $i++ ) {
            $name  = sanitize_text_field( (string) ( $names[ $i ]  ?? '' ) );
            $start = sanitize_text_field( (string) ( $starts[ $i ] ?? '' ) );
            $end   = sanitize_text_field( (string) ( $ends[ $i ]   ?? '' ) );
            if ( $name === '' && $start === '' && $end === '' ) continue;
            $rows[] = [ 'name' => $name, 'start' => $start, 'end' => $end ];
        }

        ( new EvalWindowsRepository() )->save( $rows );
        return __( 'Evaluation windows saved.', 'talenttrack' );
    }

    /**
     * Settings-style windows editor. Save-only is allowed here per
     * CLAUDE.md §6 exemption (a) — this is a config sub-form, not a
     * record edit; "leaving without saving" is just navigating away.
     *
     * @param list<EvalWindow> $windows
     */
    private static function renderWindowsEditor( array $windows ): void {
        // One spare blank row so the HoD can always add a new window.
        $editable = $windows;
        $editable[] = [ 'name' => '', 'start' => '', 'end' => '' ];

        $action = remove_query_arg( [ 'coach_id' ] );

        echo '<section class="tt-ec-editor tt-ec-card" aria-labelledby="tt-ec-editor-title">';
        echo '<h2 id="tt-ec-editor-title" class="tt-ec-card__title">' . esc_html__( 'Evaluation windows', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-ec-help">' . esc_html__( 'Define the periods each player should be evaluated in this season. Leave a row blank to remove it.', 'talenttrack' ) . '</p>';

        echo '<form method="post" action="' . esc_url( $action ) . '" class="tt-ec-window-form">';
        wp_nonce_field( 'tt_ec_save_windows', 'tt_ec_windows_nonce' );

        echo '<div class="tt-ec-window-rows" role="group" aria-label="' . esc_attr__( 'Evaluation windows', 'talenttrack' ) . '">';
        foreach ( $editable as $w ) {
            echo '<div class="tt-ec-window-row">';
            echo '<label class="tt-ec-field"><span class="tt-ec-field__label">' . esc_html__( 'Name', 'talenttrack' ) . '</span>';
            echo '<input type="text" name="win_name[]" value="' . esc_attr( (string) $w['name'] ) . '" autocomplete="off" placeholder="' . esc_attr__( 'e.g. Autumn review', 'talenttrack' ) . '" /></label>';
            echo '<label class="tt-ec-field"><span class="tt-ec-field__label">' . esc_html__( 'Start', 'talenttrack' ) . '</span>';
            echo '<input type="date" name="win_start[]" value="' . esc_attr( (string) $w['start'] ) . '" /></label>';
            echo '<label class="tt-ec-field"><span class="tt-ec-field__label">' . esc_html__( 'End', 'talenttrack' ) . '</span>';
            echo '<input type="date" name="win_end[]" value="' . esc_attr( (string) $w['end'] ) . '" /></label>';
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="tt-ec-window-actions">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save windows', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</section>';
    }

    /**
     * KPI summary strip across the top of the matrix.
     *
     * @param CoverageData $coverage
     */
    private static function renderSummary( array $coverage ): void {
        $windows_count = count( $coverage['windows'] );
        $cells_total   = $coverage['total_players'] * $windows_count;
        $covered       = $cells_total - $coverage['total_gaps'];
        $pct           = $cells_total > 0 ? number_format_i18n( $covered / $cells_total * 100, 1 ) . '%' : '—';

        echo '<div class="tt-ec-kpis">';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile() escapes internally.
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Players', 'talenttrack' ),  'value' => (string) $coverage['total_players'] ] );
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Windows', 'talenttrack' ),  'value' => (string) $windows_count ] );
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Coverage', 'talenttrack' ), 'value' => $pct ] );
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Gaps', 'talenttrack' ),     'value' => (string) $coverage['total_gaps'], 'flag' => $coverage['total_gaps'] > 0 ? 'red' : 'green' ] );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    /**
     * Per-coach gap header strip — who owns the most uncovered cells.
     *
     * @param list<CoachGap> $coach_gaps
     */
    private static function renderCoachGaps( array $coach_gaps ): void {
        if ( $coach_gaps === [] ) {
            echo '<p class="tt-notice tt-notice--ok">' . esc_html__( 'Every player is covered in every window.', 'talenttrack' ) . '</p>';
            return;
        }
        echo '<section class="tt-ec-card" aria-labelledby="tt-ec-coach-gaps-title">';
        echo '<h2 id="tt-ec-coach-gaps-title" class="tt-ec-card__title">' . esc_html__( 'Gaps by coach', 'talenttrack' ) . '</h2>';
        echo '<ul class="tt-ec-coach-gaps">';
        foreach ( $coach_gaps as $g ) {
            $name = $g['coach_name'] !== '' ? $g['coach_name'] : __( 'Unassigned', 'talenttrack' );
            echo '<li class="tt-ec-coach-gap">';
            echo '<span class="tt-ec-coach-gap__name">' . esc_html( $name ) . '</span>';
            echo '<span class="tt-ec-coach-gap__count" aria-label="' . esc_attr(
                sprintf(
                    /* translators: %d is the number of evaluation gaps. */
                    _n( '%d gap', '%d gaps', $g['gap_count'], 'talenttrack' ),
                    $g['gap_count']
                )
            ) . '">' . esc_html( (string) $g['gap_count'] ) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }

    /**
     * Coach filter for the evaluations list — link-out per coach. The
     * surface itself is the coverage report; clicking a coach opens the
     * evaluations list scoped to that coach via `?coach_id=`.
     *
     * @param list<Evaluator> $evaluators
     */
    private static function renderCoachFilter( array $evaluators ): void {
        if ( $evaluators === [] ) return;
        echo '<section class="tt-ec-card" aria-labelledby="tt-ec-coach-filter-title">';
        echo '<h2 id="tt-ec-coach-filter-title" class="tt-ec-card__title">' . esc_html__( 'Open evaluations by coach', 'talenttrack' ) . '</h2>';
        echo '<div class="tt-ec-coach-filter">';
        foreach ( $evaluators as $e ) {
            if ( $e['coach_id'] <= 0 || $e['coach_name'] === '' ) continue;
            $url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'evaluations', 'filter' => [ 'coach_id' => $e['coach_id'] ] ],
                RecordLink::dashboardUrl()
            ) );
            echo '<a class="tt-btn tt-btn-secondary tt-ec-coach-chip" href="' . esc_url( $url ) . '">' . esc_html( $e['coach_name'] ) . '</a>';
        }
        echo '</div>';
        echo '</section>';
    }

    /**
     * The coverage matrix: rows = players grouped by team, columns = the
     * configured windows. Each cell conveys state by icon + text (not
     * colour alone): ✓ covered (title = evaluator) or • gap.
     *
     * @param CoverageData $coverage
     */
    private static function renderMatrix( array $coverage ): void {
        echo '<section class="tt-ec-card" aria-labelledby="tt-ec-matrix-title">';
        echo '<h2 id="tt-ec-matrix-title" class="tt-ec-card__title">' . esc_html__( 'Coverage matrix', 'talenttrack' ) . '</h2>';
        echo '<div class="tt-ec-matrix-scroll">';
        echo '<table class="tt-ec-matrix">';
        echo '<thead><tr>';
        echo '<th scope="col" class="tt-ec-matrix__player">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        foreach ( $coverage['windows'] as $w ) {
            echo '<th scope="col" class="tt-ec-matrix__win">'
                . '<span class="tt-ec-win-name">' . esc_html( (string) $w['name'] ) . '</span>'
                . '<span class="tt-ec-win-range">' . esc_html( (string) $w['start'] . ' – ' . (string) $w['end'] ) . '</span>'
                . '</th>';
        }
        echo '</tr></thead><tbody>';

        $covered_label = esc_html__( 'Evaluated', 'talenttrack' );
        $gap_label     = esc_html__( 'Not evaluated', 'talenttrack' );

        foreach ( $coverage['teams'] as $team ) {
            $team_name = $team['team_name'] !== '' ? $team['team_name'] : '#' . $team['team_id'];
            $colspan   = count( $coverage['windows'] ) + 1;
            echo '<tr class="tt-ec-matrix__team-row"><th scope="colgroup" colspan="' . (int) $colspan . '" class="tt-ec-matrix__team">'
                . esc_html( $team_name ) . '</th></tr>';

            foreach ( $team['players'] as $player ) {
                $player_url = RecordLink::detailUrlForWithBack( 'players', $player['player_id'] );
                echo '<tr>';
                echo '<th scope="row" class="tt-ec-matrix__player">'
                    . '<a class="tt-record-link" href="' . esc_url( $player_url ) . '">' . esc_html( $player['player_name'] ) . '</a>'
                    . '</th>';
                foreach ( $player['cells'] as $cell ) {
                    if ( $cell['covered'] ) {
                        $title = $cell['evaluator_name'] !== ''
                            ? sprintf(
                                /* translators: %s is the evaluating coach's name. */
                                __( 'Evaluated by %s', 'talenttrack' ),
                                $cell['evaluator_name']
                            )
                            : $covered_label;
                        echo '<td class="tt-ec-cell tt-ec-cell--ok" title="' . esc_attr( $title ) . '">'
                            . '<span class="tt-ec-cell__icon" aria-hidden="true">&#10003;</span>'
                            . '<span class="tt-screen-reader-text">' . $covered_label . '</span>'
                            . '</td>';
                    } else {
                        echo '<td class="tt-ec-cell tt-ec-cell--gap" title="' . esc_attr__( 'No evaluation in this window', 'talenttrack' ) . '">'
                            . '<span class="tt-ec-cell__icon" aria-hidden="true">&bull;</span>'
                            . '<span class="tt-screen-reader-text">' . $gap_label . '</span>'
                            . '</td>';
                    }
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    /**
     * Attendance-recording compliance strip — per team, the share of
     * completed activities in each window that have any attendance
     * recorded. A team that never records (low %) reads differently from
     * a team with no completed activity (shown as "no activity").
     *
     * @param list<EvalWindow> $windows
     */
    private static function renderAttendanceStrip( EvalCoverageService $service, array $windows ): void {
        echo '<section class="tt-ec-card" aria-labelledby="tt-ec-att-title">';
        echo '<h2 id="tt-ec-att-title" class="tt-ec-card__title">' . esc_html__( 'Attendance-recording compliance', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-ec-help">' . esc_html__( 'Share of completed activities in each window that have any attendance recorded.', 'talenttrack' ) . '</p>';
        echo '<div class="tt-ec-matrix-scroll">';
        echo '<table class="tt-ec-att">';
        echo '<thead><tr><th scope="col">' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        foreach ( $windows as $w ) {
            echo '<th scope="col">' . esc_html( (string) $w['name'] ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        // One column per window — gather each window's per-team rows, then
        // pivot to one row per team.
        /** @var array<int,array{team_name:string,cells:array<int,AttendanceRow>}> $by_team */
        $by_team = [];
        /** @var list<int> $team_order */
        $team_order = [];
        foreach ( $windows as $i => $w ) {
            foreach ( $service->attendanceCompliance( $w ) as $row ) {
                $tid = $row['team_id'];
                if ( ! isset( $by_team[ $tid ] ) ) {
                    $by_team[ $tid ] = [ 'team_name' => $row['team_name'], 'cells' => [] ];
                    $team_order[] = $tid;
                }
                $by_team[ $tid ]['cells'][ $i ] = $row;
            }
        }

        if ( $by_team === [] ) {
            $colspan = count( $windows ) + 1;
            echo '<tr><td colspan="' . (int) $colspan . '" class="tt-ec-att__empty">'
                . esc_html__( 'No teams to report.', 'talenttrack' ) . '</td></tr>';
        }

        foreach ( $team_order as $tid ) {
            $team = $by_team[ $tid ];
            $name = $team['team_name'] !== '' ? $team['team_name'] : '#' . $tid;
            echo '<tr><th scope="row">' . esc_html( $name ) . '</th>';
            foreach ( $windows as $i => $_w ) {
                $cell = $team['cells'][ $i ] ?? null;
                echo '<td class="tt-ec-att__cell">' . self::attendanceCellHtml( $cell ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — attendanceCellHtml escapes internally.
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    /**
     * Render a single attendance-compliance cell. Distinguishes:
     *   - no completed activity in window → "no activity" (neutral)
     *   - completed activity, % recorded  → percent + bar (red < 100%)
     *
     * @param array{team_id:int,team_name:string,completed:int,with_attendance:int,percent:float|null}|null $cell
     */
    private static function attendanceCellHtml( ?array $cell ): string {
        if ( $cell === null || $cell['completed'] <= 0 ) {
            return '<span class="tt-ec-att-val tt-ec-att-val--none">'
                . esc_html__( 'No activity', 'talenttrack' ) . '</span>';
        }
        $pct  = $cell['percent'] ?? 0.0;
        $low  = $pct < 100.0;
        $w    = max( 0, min( 100, (int) round( $pct ) ) );
        $text = number_format_i18n( $pct, 0 ) . '%';
        $detail = sprintf(
            /* translators: 1: activities with attendance, 2: completed activities. */
            __( '%1$d of %2$d activities', 'talenttrack' ),
            $cell['with_attendance'],
            $cell['completed']
        );
        return '<span class="tt-ec-att-bar' . ( $low ? ' is-low' : '' ) . '" title="' . esc_attr( $detail ) . '">'
            . '<span class="tt-ec-att-bar__v">' . esc_html( $text ) . '</span>'
            . '<span class="tt-ec-att-bar__track"><i style="width:' . (int) $w . '%;"></i></span>' /* tt-inline-ok: computed progress-bar width */
            . '</span>';
    }
}
