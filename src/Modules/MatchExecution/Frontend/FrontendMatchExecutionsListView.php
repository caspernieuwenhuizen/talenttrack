<?php
namespace TT\Modules\MatchExecution\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
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
 * v1 ships a flat sortable table. The mockup at
 * `.local-mockups/match-executions-list/` proposes a bucketed shape
 * (Needs review / Live / Finalized) — folded into a follow-up if
 * the pilot reports the flat table reads worse.
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

        $teams = self::listTeamsForUser( $user_id, $is_admin );
        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams visible to you yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( $team_id > 0 && ! self::teamExists( $team_id, $teams ) ) {
            $team_id = 0; // "all teams" fallback if the user lost access.
        }

        $state_filter = isset( $_GET['state'] ) ? sanitize_key( (string) wp_unslash( $_GET['state'] ) ) : 'all';
        if ( ! in_array( $state_filter, [ 'all', 'live', MatchExecutionState::PENDING_REVIEW, MatchExecutionState::FINALIZED ], true ) ) {
            $state_filter = 'all';
        }

        $defaults = self::defaultWindow();
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : $defaults['from'];
        $to   = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) )   : $defaults['to'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $defaults['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $defaults['to'];

        self::renderFilterForm( $teams, $team_id, $from, $to, $state_filter );

        $rows = self::query( $team_id, $teams, $from, $to, $state_filter );
        if ( empty( $rows ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No match executions match the current filters.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-table-wrap tt-mex-table-wrap"><table class="tt-table tt-table-sortable tt-mex-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Opponent', 'talenttrack' ) . '</th>';
        echo '<th class="tt-mex-score">' . esc_html__( 'Score', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'State', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $state = (string) ( $r->state ?? '' );
            $row_url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'match-execution', 'activity_id' => (int) $r->activity_id ],
                RecordLink::dashboardUrl()
            ) );

            $opp = trim( (string) ( $r->opponent ?? '' ) );
            if ( $opp === '' ) $opp = '—';
            $team_name = (string) ( $r->team_name ?? ( '#' . (int) $r->team_id ) );
            $score = sprintf( '%d–%d', (int) $r->home_score, (int) $r->away_score );

            // #1472 — whole-row click target. The inner date link stays
            // the keyboard / AT path (no nested role=link on the <tr>);
            // tt-table-tools.js wires the pointer navigation.
            echo '<tr class="is-row-link" data-row-href="' . esc_url( $row_url ) . '">';
            echo '<td><a class="tt-record-link" href="' . esc_url( $row_url ) . '">' . esc_html( \TT\Shared\Dates\TTDate::date( (string) $r->session_date ) ) . '</a></td>';
            echo '<td>' . esc_html( $team_name ) . '</td>';
            echo '<td>' . esc_html( $opp ) . '</td>';
            echo '<td class="tt-mex-score">' . esc_html( $score ) . '</td>';
            echo '<td>' . self::statePill( $state ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Coach sees executions on teams they own (via the existing
     * tt_user_team_link join used by `coach_owns_player`). HoD/Admin
     * see club-wide.
     *
     * @return list<object>
     */
    private static function listTeamsForUser( int $user_id, bool $is_admin ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        if ( $is_admin || current_user_can( 'tt_edit_settings' ) || current_user_can( 'tt_view_all_teams' ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$p}tt_teams
                  WHERE club_id = %d AND archived_at IS NULL
                  ORDER BY name ASC",
                $club_id
            ) );
            return is_array( $rows ) ? $rows : [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT t.id, t.name
               FROM {$p}tt_teams t
               JOIN {$p}tt_user_team_link l ON l.team_id = t.id
              WHERE t.club_id = %d AND t.archived_at IS NULL
                AND l.user_id = %d
              ORDER BY t.name ASC",
            $club_id, $user_id
        ) );
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
     * @return list<object>
     */
    private static function query( int $team_id, array $teams, string $from, string $to, string $state_filter ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $team_ids = $team_id > 0
            ? [ $team_id ]
            : array_map( static fn( $t ) => (int) $t->id, $teams );
        if ( empty( $team_ids ) ) return [];

        $team_in = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

        $state_clause = '';
        $state_params = [];
        if ( $state_filter === 'live' ) {
            $live = MatchExecutionState::LIVE;
            $state_in = implode( ',', array_fill( 0, count( $live ), '%s' ) );
            $state_clause = "AND e.state IN ($state_in)";
            $state_params = $live;
        } elseif ( $state_filter === MatchExecutionState::PENDING_REVIEW || $state_filter === MatchExecutionState::FINALIZED ) {
            $state_clause = 'AND e.state = %s';
            $state_params = [ $state_filter ];
        }
        // 'all' → no state clause.

        $sql = "SELECT
                    e.id AS execution_id,
                    e.state,
                    e.home_score,
                    e.away_score,
                    a.id AS activity_id,
                    a.session_date,
                    a.opponent,
                    a.team_id,
                    t.name AS team_name
                  FROM {$p}tt_match_execution e
                  INNER JOIN {$p}tt_activities a ON a.id = e.activity_id AND a.club_id = e.club_id
                  LEFT JOIN  {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                 WHERE e.club_id = %d
                   AND a.team_id IN ($team_in)
                   AND a.session_date BETWEEN %s AND %s
                   $state_clause
                 ORDER BY a.session_date DESC, e.id DESC
                 LIMIT 200";

        $params = array_merge( [ $club_id ], $team_ids, [ $from, $to ], $state_params );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param list<object> $teams
     */
    private static function renderFilterForm( array $teams, int $team_id, string $from, string $to, string $state_filter ): void {
        echo '<form method="get" class="tt-filter-row tt-mex-filter">';
        echo '<input type="hidden" name="tt_view" value="match-executions" />';

        echo '<label><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id">';
        $sel_all = ( $team_id === 0 ) ? ' selected' : '';
        echo '<option value="0"' . $sel_all . '>' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( $teams as $t ) {
            $sel = ( (int) $t->id === $team_id ) ? ' selected' : '';
            echo '<option value="' . (int) $t->id . '"' . $sel . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="from" value="' . esc_attr( $from ) . '" /></label>';
        echo '<label><span>' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="to" value="' . esc_attr( $to ) . '" /></label>';

        $states = [
            'all'                              => __( 'All states', 'talenttrack' ),
            'live'                             => __( 'Live', 'talenttrack' ),
            MatchExecutionState::PENDING_REVIEW => __( 'Pending review', 'talenttrack' ),
            MatchExecutionState::FINALIZED     => __( 'Finalized', 'talenttrack' ),
        ];
        echo '<label><span>' . esc_html__( 'State', 'talenttrack' ) . '</span>';
        echo '<select name="state">';
        foreach ( $states as $key => $label ) {
            $sel = ( $key === $state_filter ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $key ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    private static function statePill( string $state ): string {
        if ( MatchExecutionState::isLive( $state ) ) {
            $label = __( 'Live', 'talenttrack' );
            $modifier = ' tt-mex-chip--live';
        } elseif ( $state === MatchExecutionState::PENDING_REVIEW ) {
            $label = __( 'Pending review', 'talenttrack' );
            $modifier = ' tt-mex-chip--review';
        } elseif ( $state === MatchExecutionState::FINALIZED ) {
            $label = __( 'Finalized', 'talenttrack' );
            $modifier = ' tt-mex-chip--done';
        } else {
            $label = __( 'Not started', 'talenttrack' );
            $modifier = '';
        }
        return '<span class="tt-mex-chip' . $modifier . '">' . esc_html( $label ) . '</span>';
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
