<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\CohortBoardRestController;
use TT\Modules\Analytics\CohortBoardService;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\TeamPickerComponent;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendCohortBoardView (#1383) — read-only end-of-season decision
 * board. The HoD picks a team / age group and gets one row per active
 * player with status, rolling rating + trend, attendance %, PDP
 * conversation count, and the current PDP verdict, each linking into the
 * player's PDP file.
 *
 * Read-only v1 — verdicts stay set in the PDP file. Sortable (server-side
 * via ?sort=&dir=, works without JS) + CSV export (?export=csv, nonce +
 * cap gated). All data comes from CohortBoardService (CLAUDE.md §4).
 */
final class FrontendCohortBoardView extends FrontendViewBase {

    private const NONCE_ACTION = 'tt_cohort_board_export';

    /** @var array<string,string> sortable column => row key */
    private const SORT_KEYS = [
        'name'           => 'name',
        'status'         => 'status_label',
        'rolling'        => 'rolling',
        'trend'          => 'trend',
        'attendance'     => 'attendance_pct',
        'conversations'  => 'pdp_conversations',
        'verdict'        => 'verdict_label',
    ];

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-cohort-board',
            TT_PLUGIN_URL . 'assets/css/frontend-cohort-board.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view the cohort decision board.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;

        // CSV export short-circuits before any HTML when requested.
        if ( $team_id > 0 && isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
            self::exportCsv( $team_id );
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Cohort decision board', 'talenttrack' ) );
        self::renderHeader( __( 'Cohort decision board', 'talenttrack' ) );

        $season = ( new SeasonsRepository() )->current();
        if ( $season === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'No current season is set. Configure a season under Configuration → Seasons before using the decision board.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<p class="tt-cb-intro">' . esc_html( sprintf(
            /* translators: %s = season name */
            __( 'End-of-season decisions for the current season (%s). Verdicts are set in each PDP file; this board is read-only.', 'talenttrack' ),
            (string) $season->name
        ) ) . '</p>';

        self::renderTeamPicker( $user_id, $is_admin, $team_id );

        if ( $team_id <= 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'Pick a team or age group to see its players.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( ! CohortBoardRestController::callerCanReadTeam( $team_id ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this team.', 'talenttrack' ) . '</p>';
            return;
        }

        $rows = ( new CohortBoardService() )->rowsForTeam( $team_id );
        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No active players on this team.', 'talenttrack' ) . '</p>';
            return;
        }

        [ $sort, $dir ] = self::resolveSort();
        $rows = self::sortRows( $rows, $sort, $dir );

        self::renderExportLink( $team_id );
        self::renderTable( $rows, $team_id, $sort, $dir );
    }

    private static function renderTeamPicker( int $user_id, bool $is_admin, int $team_id ): void {
        $options = TeamPickerComponent::filterOptions( $user_id, $is_admin );
        $action  = remove_query_arg( [ 'team_id', 'sort', 'dir', 'export' ] );

        echo '<form method="get" class="tt-cb-picker">';
        echo '<input type="hidden" name="tt_view" value="cohort-board" />';
        echo '<label class="tt-cb-picker__field" for="tt-cb-team">';
        echo '<span class="tt-cb-picker__label">' . esc_html__( 'Team / age group', 'talenttrack' ) . '</span>';
        echo '<select id="tt-cb-team" name="team_id" class="tt-input">';
        echo '<option value="0">' . esc_html__( '— Select a team —', 'talenttrack' ) . '</option>';
        foreach ( $options as $tid => $tname ) {
            echo '<option value="' . (int) $tid . '" ' . selected( $team_id, (int) $tid, false ) . '>'
                . esc_html( (string) $tname ) . '</option>';
        }
        echo '</select>';
        echo '</label>';
        echo '<button type="submit" class="tt-btn tt-btn-primary tt-cb-picker__submit">' . esc_html__( 'Show', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    private static function renderExportLink( int $team_id ): void {
        $url = wp_nonce_url(
            add_query_arg(
                [ 'tt_view' => 'cohort-board', 'team_id' => $team_id, 'export' => 'csv' ],
                RecordLink::dashboardUrl()
            ),
            self::NONCE_ACTION,
            '_tt_csv_nonce'
        );
        echo '<p class="tt-cb-actions"><a class="tt-btn tt-btn-secondary" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Export CSV', 'talenttrack' ) . '</a></p>';
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    private static function renderTable( array $rows, int $team_id, string $sort, string $dir ): void {
        echo '<div class="tt-cb-card"><div class="tt-cb-scroll"><table class="tt-cb-table">';
        echo '<thead><tr>';
        self::sortableTh( 'name',          __( 'Player', 'talenttrack' ),        $team_id, $sort, $dir );
        self::sortableTh( 'status',        __( 'Status', 'talenttrack' ),        $team_id, $sort, $dir );
        self::sortableTh( 'rolling',       __( 'Rating', 'talenttrack' ),        $team_id, $sort, $dir, true );
        self::sortableTh( 'trend',         __( 'Trend', 'talenttrack' ),         $team_id, $sort, $dir );
        self::sortableTh( 'attendance',    __( 'Attendance', 'talenttrack' ),    $team_id, $sort, $dir, true );
        self::sortableTh( 'conversations', __( 'PDP talks', 'talenttrack' ),     $team_id, $sort, $dir, true );
        self::sortableTh( 'verdict',       __( 'Verdict', 'talenttrack' ),       $team_id, $sort, $dir );
        echo '<th class="tt-cb-th">' . esc_html__( 'PDP file', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $player_url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'players', 'id' => (int) $row['player_id'] ],
                RecordLink::dashboardUrl()
            ) );

            echo '<tr>';
            echo '<td class="tt-cb-cell tt-cb-cell--name"><a class="tt-record-link" href="' . esc_url( $player_url ) . '">'
                . esc_html( (string) $row['name'] ) . '</a></td>';
            $rolling = $row['rolling'] !== null ? (float) $row['rolling'] : null;
            $att_pct = $row['attendance_pct'] !== null ? (float) $row['attendance_pct'] : null;
            $verdict = $row['verdict'] !== null ? (string) $row['verdict'] : null;
            echo '<td class="tt-cb-cell">' . self::statusDot( (string) $row['status'], (string) $row['status_label'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — statusDot() escapes internally.
            echo '<td class="tt-cb-cell tt-cb-cell--num">' . esc_html( self::ratingText( $rolling ) ) . '</td>';
            echo '<td class="tt-cb-cell">' . self::trendArrow( (string) $row['trend'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trendArrow() escapes internally.
            echo '<td class="tt-cb-cell tt-cb-cell--num">' . self::attendanceText( $att_pct, (bool) $row['attendance_low_confidence'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — attendanceText() escapes internally.
            echo '<td class="tt-cb-cell tt-cb-cell--num">' . (int) $row['pdp_conversations'] . '</td>';
            echo '<td class="tt-cb-cell">' . self::verdictPill( $verdict, (string) $row['verdict_label'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — verdictPill() escapes internally.
            $pdp_label = $row['pdp_file_id'] !== null
                ? __( 'Open file', 'talenttrack' )
                : __( 'Start PDP', 'talenttrack' );
            echo '<td class="tt-cb-cell"><a class="tt-cb-link" href="' . esc_url( (string) $row['pdp_url'] ) . '">'
                . esc_html( $pdp_label ) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function sortableTh( string $key, string $label, int $team_id, string $sort, string $dir, bool $numeric = false ): void {
        $next_dir = ( $sort === $key && $dir === 'asc' ) ? 'desc' : 'asc';
        $url      = add_query_arg(
            [ 'tt_view' => 'cohort-board', 'team_id' => $team_id, 'sort' => $key, 'dir' => $next_dir ],
            RecordLink::dashboardUrl()
        );
        $is_active = ( $sort === $key );
        $arrow     = '';
        if ( $is_active ) {
            $arrow = $dir === 'asc' ? ' ▲' : ' ▼';
        }
        $cls = 'tt-cb-th' . ( $numeric ? ' tt-cb-th--num' : '' ) . ( $is_active ? ' is-sorted' : '' );
        $aria = $is_active ? ( $dir === 'asc' ? 'ascending' : 'descending' ) : 'none';
        echo '<th class="' . esc_attr( $cls ) . '" aria-sort="' . esc_attr( $aria ) . '">';
        echo '<a class="tt-cb-sort" href="' . esc_url( $url ) . '">' . esc_html( $label . $arrow ) . '</a>';
        echo '</th>';
    }

    private static function statusDot( string $status, string $label ): string {
        $key = sanitize_html_class( $status );
        return '<span class="tt-cb-status tt-cb-status--' . esc_attr( $key ) . '">'
            . '<span class="tt-cb-dot" aria-hidden="true"></span>'
            . '<span class="tt-cb-status__text">' . esc_html( $label ) . '</span></span>';
    }

    private static function trendArrow( string $trend ): string {
        $map = [
            'up'   => [ '▲', __( 'Trending up', 'talenttrack' ) ],
            'down' => [ '▼', __( 'Trending down', 'talenttrack' ) ],
            'flat' => [ '▬', __( 'Stable', 'talenttrack' ) ],
        ];
        [ $glyph, $label ] = $map[ $trend ] ?? $map['flat'];
        $key = sanitize_html_class( $trend !== '' ? $trend : 'flat' );
        return '<span class="tt-cb-trend tt-cb-trend--' . esc_attr( $key ) . '" title="' . esc_attr( $label ) . '">'
            . '<span aria-hidden="true">' . esc_html( $glyph ) . '</span>'
            . '<span class="tt-cb-sr">' . esc_html( $label ) . '</span></span>';
    }

    private static function verdictPill( ?string $verdict, string $label ): string {
        $key = $verdict !== null && $verdict !== '' ? sanitize_html_class( $verdict ) : 'pending';
        return '<span class="tt-cb-verdict tt-cb-verdict--' . esc_attr( $key ) . '">' . esc_html( $label ) . '</span>';
    }

    private static function ratingText( ?float $rolling ): string {
        if ( $rolling === null ) return '—';
        return number_format_i18n( $rolling, 1 );
    }

    private static function attendanceText( ?float $pct, bool $low_confidence ): string {
        if ( $pct === null ) return '<span class="tt-cb-muted">—</span>';
        $text = number_format_i18n( $pct, 1 ) . '%';
        $low  = $pct < 70;
        $cls  = 'tt-cb-att' . ( $low ? ' is-low' : '' );
        $out  = '<span class="' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</span>';
        if ( $low_confidence ) {
            $out .= ' <span class="tt-cb-att-note" title="' . esc_attr__( 'Based on few activities', 'talenttrack' ) . '">'
                . esc_html__( '(low)', 'talenttrack' ) . '</span>';
        }
        return $out;
    }

    /**
     * @return array{0:string,1:string} [sort_key, dir]
     */
    private static function resolveSort(): array {
        $sort = isset( $_GET['sort'] ) ? sanitize_key( (string) $_GET['sort'] ) : 'name';
        if ( ! isset( self::SORT_KEYS[ $sort ] ) ) $sort = 'name';
        $dir = isset( $_GET['dir'] ) && strtolower( (string) $_GET['dir'] ) === 'desc' ? 'desc' : 'asc';
        return [ $sort, $dir ];
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>>
     */
    private static function sortRows( array $rows, string $sort, string $dir ): array {
        $key     = self::SORT_KEYS[ $sort ] ?? 'name';
        $numeric = in_array( $sort, [ 'rolling', 'attendance', 'conversations' ], true );
        usort( $rows, static function ( array $a, array $b ) use ( $key, $numeric ): int {
            $av = $a[ $key ] ?? null;
            $bv = $b[ $key ] ?? null;
            if ( $numeric ) {
                // Nulls sort last on asc.
                $an = $av === null ? -INF : (float) $av;
                $bn = $bv === null ? -INF : (float) $bv;
                return $an <=> $bn;
            }
            return strcasecmp( (string) $av, (string) $bv );
        } );
        if ( $dir === 'desc' ) $rows = array_reverse( $rows );
        return array_values( $rows );
    }

    /**
     * Stream the cohort board as a CSV download. Cap + nonce gated; never
     * emits HTML, so the breadcrumb contract doesn't apply (it's a file
     * response, not a routable view body).
     */
    private static function exportCsv( int $team_id ): void {
        if ( ! current_user_can( 'tt_view_analytics' )
            || ! CohortBoardRestController::callerCanReadTeam( $team_id ) ) {
            wp_die( esc_html__( 'You do not have access to this export.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }
        $nonce = isset( $_GET['_tt_csv_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_tt_csv_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'This export link has expired. Reload the board and try again.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }

        $rows     = ( new CohortBoardService() )->rowsForTeam( $team_id );
        $filename = 'cohort-board-team-' . $team_id . '-' . gmdate( 'Y-m-d' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        if ( $out === false ) return;

        $trend_words = [
            'up'   => __( 'Up', 'talenttrack' ),
            'down' => __( 'Down', 'talenttrack' ),
            'flat' => __( 'Stable', 'talenttrack' ),
        ];

        fputcsv( $out, [
            __( 'Player', 'talenttrack' ),
            __( 'Status', 'talenttrack' ),
            __( 'Rating', 'talenttrack' ),
            __( 'Trend', 'talenttrack' ),
            __( 'Attendance %', 'talenttrack' ),
            __( 'PDP talks', 'talenttrack' ),
            __( 'Verdict', 'talenttrack' ),
        ] );

        foreach ( $rows as $row ) {
            fputcsv( $out, [
                (string) $row['name'],
                (string) $row['status_label'],
                $row['rolling'] === null ? '' : (string) $row['rolling'],
                $trend_words[ (string) $row['trend'] ] ?? $trend_words['flat'],
                $row['attendance_pct'] === null ? '' : (string) $row['attendance_pct'],
                (string) (int) $row['pdp_conversations'],
                (string) $row['verdict_label'],
            ] );
        }
        fclose( $out );
    }
}
