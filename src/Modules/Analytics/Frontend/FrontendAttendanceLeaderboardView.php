<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendAttendanceLeaderboardView (#1488) — dedicated attendance
 * leaderboard reachable at `?tt_view=attendance-leaderboard` from the
 * Reports launcher.
 *
 * Two league tables over the window: the **bottom N** (lowest present %
 * — who needs attention) and the **top N** (best attenders). The
 * ranking + the rows themselves come from `AttendanceRankingQuery`, the
 * same service the player report and the REST surface use — the view
 * only composes (CLAUDE.md §4).
 *
 * Cap-gated on `tt_view_analytics`; scope follows the analytics
 * team-scope rule (global-scope read on `activities` sees the club,
 * coaches see their own teams — #1942).
 */
final class FrontendAttendanceLeaderboardView extends FrontendViewBase {

    private const DEFAULT_N = 10;

    /**
     * #1695 — pull in the 2026 green/gold leaderboard stylesheet (card
     * tables, inline present-% bars, flag chips). Depends on the
     * app-chrome handle the base view registers.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-attendance-leaderboard',
            TT_PLUGIN_URL . 'assets/css/frontend-attendance-leaderboard.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Not authorized', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this report.', 'talenttrack' ) . '</p>';
            return;
        }

        // #2126 — per-report toggle: reject even a direct link when the
        // Attendance leaderboard has been switched off for this academy.
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'report_attendance_leaderboard' ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Attendance leaderboard', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'This report has been switched off for your academy.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Attendance leaderboard', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Attendance leaderboard', 'talenttrack' ) );

        $defaults = self::defaultWindow();
        $from    = isset( $_GET['from'] )    ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : $defaults['from'];
        $to      = isset( $_GET['to'] )      ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) )   : $defaults['to'];
        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $n       = isset( $_GET['n'] )       ? absint( $_GET['n'] ) : self::DEFAULT_N;
        $n       = max( 1, min( 50, $n ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $defaults['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $defaults['to'];

        // #1942 — academy-wide = global-scope read on `activities`; the
        // settings-admin flag stays as the WP-admin fallback.
        $is_scope_admin = $is_admin
            || \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsActivities( $user_id );
        $allowed_team_ids = $is_scope_admin
            ? null
            : array_values( array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' ) ) );

        if ( ! $is_scope_admin && $allowed_team_ids === [] ) {
            echo '<p class="tt-notice">' . esc_html__( "You don't coach any teams yet, so there is no attendance to show. Ask an administrator to assign you to a team.", 'talenttrack' ) . '</p>';
            return;
        }

        // URL-tamper guard: a team the coach isn't allowed to see → empty.
        if ( $allowed_team_ids !== null && $team_id > 0 && ! in_array( $team_id, $allowed_team_ids, true ) ) {
            self::renderFilterForm( $from, $to, $team_id, $n, $allowed_team_ids );
            echo '<p class="tt-notice">' . esc_html__( 'No attendance recorded in the selected window.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderFilterForm( $from, $to, $team_id, $n, $allowed_team_ids );

        $board = ( new AttendanceRankingQuery() )->leaderboard( $from, $to, $n, $team_id, $allowed_team_ids );
        if ( ( $board['total'] ?? 0 ) === 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'No attendance recorded in the selected window.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-leaderboard-grid">';
        self::renderTable(
            __( 'Needs attention — lowest attendance', 'talenttrack' ),
            $board['bottom'],
            true
        );
        self::renderTable(
            __( 'Most reliable — highest attendance', 'talenttrack' ),
            $board['top'],
            false
        );
        echo '</div>';
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function renderTable( string $heading, array $rows, bool $is_bottom ): void {
        echo '<section class="tt-leaderboard-card">';
        echo '<h2 class="tt-leaderboard-title">' . esc_html( $heading ) . '</h2>';
        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players to rank yet.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }
        echo '<div class="tt-table-wrap"><table class="tt-table tt-table-sortable" data-tt-table-search="off" style="width:100%;">';
        echo '<thead><tr>';
        echo '<th style="text-align:right;width:3rem;" data-tt-sort="off">' . esc_html__( '#', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Activities', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Present %', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        $rank = 1;
        foreach ( $rows as $r ) {
            $name = trim( ( (string) $r['first_name'] ) . ' ' . ( (string) $r['last_name'] ) );
            if ( $name === '' ) $name = '#' . (int) $r['player_id'];
            $player_url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'players', 'id' => (int) $r['player_id'] ],
                RecordLink::dashboardUrl()
            ) );
            $team   = (string) $r['team_name'];
            $present_pct = $r['present_pct'] !== null ? (float) $r['present_pct'] : null;
            $badge  = '';
            if ( $is_bottom && ! empty( $r['flagged'] ) ) {
                $badge = ' <span class="tt-flag-badge" title="'
                    . esc_attr( sprintf( /* translators: %d missed activities */ __( '%d missed', 'talenttrack' ), (int) $r['missed'] ) )
                    . '">⚠ ' . (int) $r['missed'] . '</span>';
            }
            echo '<tr' . ( $is_bottom && ! empty( $r['flagged'] ) ? ' class="is-flagged"' : '' ) . '>';
            echo '<td style="text-align:right;">' . (int) $rank . '</td>';
            echo '<td><a class="tt-record-link" href="' . esc_url( $player_url ) . '">' . esc_html( $name ) . '</a>' . $badge . '</td>';
            echo '<td>' . ( $team !== '' ? esc_html( $team ) : '<span class="tt-muted">&mdash;</span>' ) . '</td>';
            echo '<td style="text-align:right;">' . (int) $r['activities'] . '</td>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — attendanceBar() escapes internally.
            echo '<td>' . self::attendanceBar( $present_pct ) . '</td>';
            echo '</tr>';
            $rank++;
        }
        echo '</tbody></table></div>';
        echo '</section>';
    }

    /**
     * @param list<int>|null $allowed_team_ids
     */
    private static function renderFilterForm( string $from, string $to, int $team_id, int $n, ?array $allowed_team_ids ): void {
        global $wpdb;
        if ( $allowed_team_ids !== null ) {
            if ( $allowed_team_ids === [] ) {
                $teams = [];
            } else {
                $placeholders = implode( ',', array_fill( 0, count( $allowed_team_ids ), '%d' ) );
                /** @var object[] $teams */
                $teams = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, name FROM {$wpdb->prefix}tt_teams
                      WHERE club_id = %d
                        AND ( archived_at IS NULL OR archived_at = '' )
                        AND id IN ($placeholders)
                      ORDER BY name ASC",
                    CurrentClub::id(), ...$allowed_team_ids
                ) );
            }
        } else {
            /** @var object[] $teams */
            $teams = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}tt_teams
                  WHERE club_id = %d AND ( archived_at IS NULL OR archived_at = '' )
                  ORDER BY name ASC",
                CurrentClub::id()
            ) );
        }

        echo '<form method="get" class="tt-filter-row" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:12px;">';
        echo '<input type="hidden" name="tt_view" value="attendance-leaderboard" />';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id">';
        echo '<option value="0">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( (array) $teams as $t ) {
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . selected( $team_id, (int) $t->id, false ) . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="from" value="' . esc_attr( $from ) . '" /></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="to" value="' . esc_attr( $to ) . '" /></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'How many', 'talenttrack' ) . '</span>';
        echo '<input type="number" name="n" inputmode="numeric" min="1" max="50" step="1" value="' . esc_attr( (string) $n ) . '" /></label>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    /** @return array{from:string,to:string} */
    private static function defaultWindow(): array {
        return [
            'from' => gmdate( 'Y-m-d', strtotime( '-90 days' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }

    /**
     * Inline present-% bar — value + a proportional track, red below 70%.
     * Returns escaped HTML. Shares the .tt-att-bar vocabulary with the
     * team + player attendance reports (#1688 / #1695).
     */
    private static function attendanceBar( ?float $pct ): string {
        if ( $pct === null ) {
            return '<span class="tt-att-bar"><span class="v">—</span></span>';
        }
        $low = $pct < 70;
        $w   = max( 0, min( 100, (int) round( $pct ) ) );
        return '<span class="tt-att-bar' . ( $low ? ' is-low' : '' ) . '">'
            . '<span class="v">' . esc_html( number_format_i18n( $pct, 1 ) . '%' ) . '</span>'
            . '<span class="track"><i style="width:' . (int) $w . '%;"></i></span>'
            . '</span>';
    }
}
