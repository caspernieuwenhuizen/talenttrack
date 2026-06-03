<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMinutesTeamReportView (#1034) — standard report: minutes
 * played per player on a team over a date window, split by match type
 * (game_subtype_key — League / Cup / Friendly).
 *
 * URL: `?tt_view=minutes-report-team&team_id=N&from=YYYY-MM-DD&to=YYYY-MM-DD&type=all|<key>`
 *
 * Reached via the "Standard reports" section on the central Analytics
 * surface (`?tt_view=analytics`). Sortable table — one row per player
 * who actually played in the window. Columns: Player | Total | Matches
 * | Starts | Subs in | Subs off | Avg | League | Cup | Friendly | %
 * available.
 *
 * Cap-gated on `tt_view_analytics` (same as the parent Analytics view).
 *
 * v1 scope: team report only. A player-detail variant (`tt_view=
 * minutes-report-player`) is a #1034 follow-up; the existing PDP-style
 * spotlight from the mockup builds on the same `MinutesQuery` service.
 */
final class FrontendMinutesTeamReportView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Not authorized', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'analytics', __( 'Analytics', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this report.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Minutes played (team)', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'analytics', __( 'Analytics', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Minutes played per player', 'talenttrack' ) );

        // v4.20.34 (#1193) — same scope pattern as the two attendance
        // views (v4.20.4 / #1147). Admins + `tt_view_all_teams` holders
        // keep the club-wide list; everyone else only sees teams they
        // coach. Both the dropdown AND the downstream `MinutesQuery`
        // call guard against URL tampering.
        $is_scope_admin = $is_admin || current_user_can( 'tt_view_all_teams' );
        $allowed_team_ids = $is_scope_admin
            ? null
            : array_values( array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' ) ) );

        if ( ! $is_scope_admin && $allowed_team_ids === [] ) {
            echo '<p class="tt-notice">' . esc_html__( "You don't coach any teams yet, so there is no minutes data to show. Ask an administrator to assign you to a team.", 'talenttrack' ) . '</p>';
            return;
        }

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $teams = self::listTeams( $allowed_team_ids );
        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams yet — add one to enable this report.', 'talenttrack' ) . '</p>';
            return;
        }
        // URL tampering: requested team_id not in the scoped list →
        // empty state instead of forging through to MinutesQuery.
        if ( $allowed_team_ids !== null && $team_id > 0 && ! in_array( $team_id, $allowed_team_ids, true ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No data for the selected team in your scope.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $team_id <= 0 || ! self::teamExists( $team_id, $teams ) ) {
            $team_id = (int) $teams[0]->id;
        }

        $defaults = self::defaultWindow();
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : $defaults['from'];
        $to   = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) )   : $defaults['to'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $defaults['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $defaults['to'];

        $type_filter = isset( $_GET['type'] ) ? sanitize_key( (string) wp_unslash( $_GET['type'] ) ) : 'all';

        self::renderFilterForm( $teams, $team_id, $from, $to, $type_filter );

        $rows = ( new MinutesQuery() )->forTeam( $team_id, $from, $to );

        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No minutes recorded for this team in the selected window.', 'talenttrack' ) . '</p>';
            return;
        }

        // Collect every match-type key that actually appears (so we
        // only show columns the operator has data for, plus the
        // canonical League / Cup / Friendly even when empty).
        $type_labels = [
            'League'   => __( 'League',   'talenttrack' ),
            'Cup'      => __( 'Cup',      'talenttrack' ),
            'Friendly' => __( 'Friendly', 'talenttrack' ),
        ];
        $type_keys = array_keys( $type_labels );
        foreach ( $rows as $r ) {
            foreach ( array_keys( $r['by_type'] ) as $k ) {
                if ( ! isset( $type_labels[ $k ] ) ) {
                    $type_labels[ $k ] = $k;
                    $type_keys[]       = $k;
                }
            }
        }
        $type_keys = array_values( array_unique( $type_keys ) );

        echo '<div class="tt-table-wrap"><table class="tt-table tt-table-sortable" style="width:100%; margin-top:12px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Total', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Matches', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Starts', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Subs in', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Subs off', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Avg / match', 'talenttrack' ) . '</th>';
        foreach ( $type_keys as $key ) {
            echo '<th style="text-align:right;">' . esc_html( $type_labels[ $key ] ) . '</th>';
        }
        echo '<th style="text-align:right;">' . esc_html__( '% available', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        $type_match = ( $type_filter !== 'all' && isset( $type_labels[ $type_filter ] ) );

        foreach ( $rows as $r ) {
            // When the operator filters to one match type, the visible
            // Total + Avg only count that bucket. Other columns stay
            // informational so a coach can compare league-only vs
            // overall in one glance.
            $effective_total = $type_match
                ? (int) ( $r['by_type'][ $type_filter ] ?? 0 )
                : (int) $r['total_minutes'];
            if ( $type_match && $effective_total <= 0 ) continue;

            $matches = max( 1, (int) $r['matches'] );
            $avg = $effective_total > 0 ? (int) round( $effective_total / $matches ) : 0;
            $pct_avail = $r['available_minutes'] > 0
                ? min( 100, (int) round( ( $effective_total / $r['available_minutes'] ) * 100 ) )
                : 0;

            $name = trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
            if ( $name === '' ) $name = '#' . (int) $r['player_id'];
            $player_url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'players', 'id' => (int) $r['player_id'] ],
                RecordLink::dashboardUrl()
            ) );

            echo '<tr>';
            echo '<td><a class="tt-record-link" href="' . esc_url( $player_url ) . '">' . esc_html( $name ) . '</a></td>';
            echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $effective_total ) ) . '</td>';
            echo '<td style="text-align:right;">' . (int) $r['matches'] . '</td>';
            echo '<td style="text-align:right;">' . (int) $r['starts'] . '</td>';
            echo '<td style="text-align:right;">' . (int) $r['subs_in'] . '</td>';
            echo '<td style="text-align:right;">' . (int) $r['subs_off'] . '</td>';
            echo '<td style="text-align:right;">' . (int) $avg . '</td>';
            foreach ( $type_keys as $key ) {
                $v = (int) ( $r['by_type'][ $key ] ?? 0 );
                echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $v ) ) . '</td>';
            }
            echo '<td style="text-align:right;">' . (int) $pct_avail . '%</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * v4.20.34 (#1193) — accepts an `$allowed_team_ids` scope filter.
     * `null` means unrestricted (admin / view_all_teams); a list narrows
     * the SQL `IN (...)` clause.
     *
     * @param list<int>|null $allowed_team_ids
     * @return list<object>  id, name
     */
    private static function listTeams( ?array $allowed_team_ids = null ): array {
        global $wpdb;
        if ( $allowed_team_ids !== null ) {
            if ( $allowed_team_ids === [] ) return [];
            $placeholders = implode( ',', array_fill( 0, count( $allowed_team_ids ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}tt_teams
                  WHERE club_id = %d
                    AND archived_at IS NULL
                    AND id IN ($placeholders)
                  ORDER BY name ASC",
                CurrentClub::id(), ...$allowed_team_ids
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}tt_teams
                  WHERE club_id = %d AND archived_at IS NULL
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

    /**
     * Default window: 365 days back from today (one season).
     * @return array{from:string,to:string}
     */
    private static function defaultWindow(): array {
        return [
            'from' => gmdate( 'Y-m-d', strtotime( '-365 days' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }

    /**
     * @param list<object> $teams
     */
    private static function renderFilterForm( array $teams, int $team_id, string $from, string $to, string $type_filter ): void {
        $action = remove_query_arg( [ 'team_id', 'from', 'to', 'type' ] );
        echo '<form method="get" class="tt-filter-row" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:12px;">';
        echo '<input type="hidden" name="tt_view" value="minutes-report-team" />';

        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id">';
        foreach ( $teams as $t ) {
            $sel = ( (int) $t->id === $team_id ) ? ' selected' : '';
            echo '<option value="' . (int) $t->id . '"' . $sel . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="from" value="' . esc_attr( $from ) . '" /></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="to" value="' . esc_attr( $to ) . '" /></label>';

        $types = [
            'all'      => __( 'All types', 'talenttrack' ),
            'League'   => __( 'League',    'talenttrack' ),
            'Cup'      => __( 'Cup',       'talenttrack' ),
            'Friendly' => __( 'Friendly',  'talenttrack' ),
        ];
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'Match type', 'talenttrack' ) . '</span>';
        echo '<select name="type">';
        foreach ( $types as $key => $label ) {
            $sel = ( $key === $type_filter ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $key ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';
    }
}
