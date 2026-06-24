<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendAppChrome;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendAttendancePlayerReportView (v3.110.109) — standard report:
 * player attendance statistics over a date range, optionally scoped
 * to a single team.
 *
 * Reached via the "Standard reports" section on the central Analytics
 * surface. One row per player; columns: activities in window, present
 * %, absent %, late %, excused %, injured %. Click-through to the
 * player profile.
 *
 * Status lookups use `LOWER(att.status)` for case insensitivity
 * (legacy mixed-case rows aggregate into the same bucket as the
 * v3.110.78-onward lowercase rows). Cap-gated on `tt_view_analytics`.
 * Scope: club-wide, narrowed by an optional `team_id` filter.
 */
final class FrontendAttendancePlayerReportView extends FrontendViewBase {

    /**
     * #1695 — pull in the 2026 green/gold report stylesheet (KPI strip,
     * card table, inline attendance bars). Depends on the app-chrome
     * handle the base view registers.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-attendance-player-report',
            TT_PLUGIN_URL . 'assets/css/frontend-attendance-player-report.css',
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

        FrontendBreadcrumbs::fromDashboard(
            __( 'Player attendance', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Player attendance statistics', 'talenttrack' ) );

        $defaults = self::defaultWindow();
        $from    = isset( $_GET['from'] )    ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : $defaults['from'];
        $to      = isset( $_GET['to'] )      ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) )   : $defaults['to'];
        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $defaults['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $defaults['to'];

        // v4.20.4 (#1147) — analytics scope honours the user's team
        // assignment. Admins + holders of `tt_view_all_teams` keep the
        // club-wide view; everyone else (notably AC, scoped to
        // `team` per the #1060 trim) only ever sees teams they coach.
        // Routed through `QueryHelpers::get_teams_for_coach()` — the
        // same resolver the players list + teamplanner use, so analytics
        // can't drift from the rest of the app.
        $is_scope_admin = $is_admin || current_user_can( 'tt_view_all_teams' );
        $allowed_team_ids = $is_scope_admin
            ? null
            : array_values( array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' ) ) );

        if ( ! $is_scope_admin && $allowed_team_ids === [] ) {
            echo '<p class="tt-notice">' . esc_html__( "You don't coach any teams yet, so there is no attendance to show. Ask an administrator to assign you to a team.", 'talenttrack' ) . '</p>';
            return;
        }

        // If user picked a team they're not allowed to see, fall through
        // to empty — no row leak via URL tampering.
        if ( $allowed_team_ids !== null && $team_id > 0 && ! in_array( $team_id, $allowed_team_ids, true ) ) {
            self::renderFilterForm( $from, $to, $team_id, $allowed_team_ids );
            echo '<p class="tt-notice">' . esc_html__( 'No attendance recorded in the selected window.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderFilterForm( $from, $to, $team_id, $allowed_team_ids );

        // #1488 — ranking, the missed count, and the at-risk flag all
        // come from the shared AttendanceRankingQuery so the report, the
        // leaderboard, the REST surface, and the Comms cron can never
        // drift. Rows arrive worst-attendance-first; the table stays
        // client-side sortable on any column on top of that default.
        $rows = ( new AttendanceRankingQuery() )->rows( $from, $to, $team_id, $allowed_team_ids );
        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No attendance recorded in the selected window.', 'talenttrack' ) . '</p>';
            return;
        }

        $threshold = AttendanceFlagService::threshold();
        $at_risk   = array_values( array_filter( $rows, static fn( array $r ): bool => ! empty( $r['flagged'] ) ) );

        // #1695 — KPI summary strip computed from the already-fetched rows
        // (presentation-level aggregation only; the query stays the source).
        $player_count = count( $rows );
        $sum_present = 0; $sum_total = 0;
        foreach ( $rows as $r ) {
            $sum_present += (int) $r['present'];
            $sum_total   += (int) $r['total'];
        }
        $avg = $sum_total > 0 ? number_format_i18n( $sum_present / $sum_total * 100, 1 ) . '%' : '—';
        $at_risk_count = count( $at_risk );

        echo '<div class="tt-report-kpis">';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile() escapes internally.
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Players', 'talenttrack' ),         'value' => (string) $player_count ] );
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Avg. attendance', 'talenttrack' ), 'value' => $avg ] );
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'At-risk players', 'talenttrack' ), 'value' => (string) $at_risk_count, 'flag' => $at_risk_count > 0 ? 'red' : 'green' ] );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';

        if ( $at_risk !== [] ) {
            usort( $at_risk, static fn( $a, $b ) => (int) $b['missed'] <=> (int) $a['missed'] );
            echo '<div class="tt-atrisk-card">';
            echo '<h3 class="tt-atrisk-card__title">' . esc_html( sprintf(
                /* translators: %d is the missed-activities threshold */
                __( 'At-risk players (%d or more missed)', 'talenttrack' ),
                $threshold
            ) ) . '</h3>';
            echo '<ul class="tt-atrisk-list">';
            foreach ( $at_risk as $r ) {
                $nm = trim( ( (string) $r['first_name'] ) . ' ' . ( (string) $r['last_name'] ) );
                if ( $nm === '' ) $nm = '#' . (int) $r['player_id'];
                echo '<li>' . esc_html( $nm ) . ' <span class="missed">'
                    . esc_html( sprintf( /* translators: %d missed activities */ __( '%d missed', 'talenttrack' ), (int) $r['missed'] ) )
                    . '</span></li>';
            }
            echo '</ul></div>';
        }

        echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table tt-table-sortable" style="width:100%;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Player',    'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Team',      'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Activities', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Present %', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Late %',    'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Absent %',  'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Excused %', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Injured %', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $player_name = trim( ( (string) $r['first_name'] ) . ' ' . ( (string) $r['last_name'] ) );
            if ( $player_name === '' ) $player_name = '#' . (int) $r['player_id'];
            $player_url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'players', 'id' => (int) $r['player_id'] ],
                RecordLink::dashboardUrl()
            ) );
            $team_name = (string) $r['team_name'];
            // #1488 — inline at-risk badge for flagged players (#1695: chip styling).
            $badge = '';
            if ( ! empty( $r['flagged'] ) ) {
                $badge = ' <span class="tt-flag-badge" title="'
                    . esc_attr( sprintf( /* translators: %d missed activities */ __( '%d missed', 'talenttrack' ), (int) $r['missed'] ) )
                    . '">⚠ ' . (int) $r['missed'] . '</span>';
            }
            $present_pct = (int) $r['total'] > 0 ? ( (int) $r['present'] / (int) $r['total'] ) * 100 : null;
            echo '<tr' . ( ! empty( $r['flagged'] ) ? ' class="is-flagged"' : '' ) . '>';
            echo '<td><a class="tt-record-link" href="' . esc_url( $player_url ) . '">' . esc_html( $player_name ) . '</a>' . $badge . '</td>';
            echo '<td>' . ( $team_name !== '' ? esc_html( $team_name ) : '<span class="tt-muted">&mdash;</span>' ) . '</td>';
            echo '<td style="text-align:right;">' . (int) $r['activities'] . '</td>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — attendanceBar() escapes internally.
            echo '<td>' . self::attendanceBar( $present_pct ) . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r['late'],    $r['total'] ) ) . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r['absent'],  $r['total'] ) ) . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r['excused'], $r['total'] ) ) . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r['injured'], $r['total'] ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * Inline attendance bar for the Present % cell — value + a track that
     * fills proportionally, red below 70%. Returns escaped HTML.
     * Mirrors the team report's bar (#1688) for a consistent vocabulary.
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

    private static function pct( $part, $total ): string {
        $total = (int) $total;
        if ( $total <= 0 ) return '—';
        return number_format_i18n( ( (int) $part / $total ) * 100, 1 ) . '%';
    }

    /** @return array{from:string,to:string} */
    private static function defaultWindow(): array {
        return [
            'from' => gmdate( 'Y-m-d', strtotime( '-90 days' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }

    /**
     * @param list<int>|null $allowed_team_ids
     */
    private static function renderFilterForm( string $from, string $to, int $team_id, ?array $allowed_team_ids ): void {
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
        echo '<input type="hidden" name="tt_view" value="attendance-report-player" />';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id">';
        echo '<option value="0">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( (array) $teams as $t ) {
            $sel = selected( $team_id, (int) $t->id, false );
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . $sel . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="from" value="' . esc_attr( $from ) . '" /></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="to" value="' . esc_attr( $to ) . '" /></label>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';
    }
}
