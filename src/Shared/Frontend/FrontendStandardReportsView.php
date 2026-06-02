<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\ExplorerUrl;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendStandardReportsView — six curated reports per the
 * `.local-mockups/standard-reports/` design-of-record (#1090-#1095).
 *
 * Reached via `?tt_view=standard-report&slug=<key>&...`. Dispatches
 * to one of six private renderers; everything else (chrome, filter
 * bar, KPI strip, empty state) is shared.
 *
 * The reports re-use existing tables: `tt_attendance.minutes_played`
 * for player-minutes, `tt_evaluations` + `tt_eval_ratings` for the
 * squad eval summary, `tt_trial_cases` for trial funnel, etc. No
 * new schema lands with this slice.
 *
 * Cap-gated on `tt_view_reports`. Each renderer additionally
 * verifies the entity filter (player_id, team_id) is in scope.
 *
 * Companion to #1063 explorer presets (#1096-#1101) which shipped
 * v4.19.0. Each curated view links into the explorer with the same
 * filter pre-applied, so a user can drill from the curated view to
 * the same query in the dimension explorer.
 */
final class FrontendStandardReportsView extends FrontendViewBase {

    /** @var array<string,string> slug => label */
    private const REPORTS = [
        'player-minutes-played'        => 'Player · Minutes played',
        'team-minutes-distribution'    => 'Team · Minutes distribution',
        'team-squad-evaluation-summary' => 'Team · Squad evaluation summary',
        'season-summary'               => 'Season · Summary',
        'season-trial-funnel'          => 'Season · Trial funnel',
        'scout-report-card'            => 'Scout · Report card',
    ];

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to reports.', 'talenttrack' ) . '</p>';
            return;
        }
        $slug = isset( $_GET['slug'] ) ? sanitize_key( (string) $_GET['slug'] ) : '';
        if ( ! array_key_exists( $slug, self::REPORTS ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Standard report', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
            );
            self::renderHeader( __( 'Standard report', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Unknown standard report. Pick one from the Reports launcher.', 'talenttrack' ) . '</p>';
            return;
        }
        self::renderStyles();
        FrontendBreadcrumbs::fromDashboard(
            self::REPORTS[ $slug ],
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );
        switch ( $slug ) {
            case 'player-minutes-played':        self::renderPlayerMinutesPlayed(); break;
            case 'team-minutes-distribution':    self::renderTeamMinutesDistribution(); break;
            case 'team-squad-evaluation-summary': self::renderSquadEvaluationSummary(); break;
            case 'season-summary':               self::renderSeasonSummary(); break;
            case 'season-trial-funnel':          self::renderSeasonTrialFunnel(); break;
            case 'scout-report-card':            self::renderScoutReportCard(); break;
        }
    }

    /** Mockup tokens, kept inline for surface-scoped CSS. */
    private static function renderStyles(): void {
        echo '<style>
        .tt-rep-page { font-size: 16px; }
        .tt-rep-page-head { background: #fff; padding: 16px; border-radius: 12px; border: 1px solid #d6dadd; margin: 0 0 16px; }
        .tt-rep-page-head h1 { margin: 0 0 4px; font-size: 22px; font-weight: 800; }
        .tt-rep-page-head__sub { margin: 0; font-size: 13px; color: #5b6e75; }
        .tt-rep-page-head__actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
        .tt-rep-btn { display: inline-block; background: transparent; border: 1px solid #d6dadd; color: #5b6e75; text-decoration: none; padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; min-height: 36px; box-sizing: border-box; }
        .tt-rep-btn:hover { color: #1d7874; border-color: #1d7874; }
        .tt-rep-kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px; margin: 0 0 16px; }
        .tt-rep-kpi { background: #fff; border: 1px solid #d6dadd; border-radius: 12px; padding: 14px 16px; }
        .tt-rep-kpi__num { font-size: 28px; font-weight: 800; color: #1a1d21; line-height: 1.1; }
        .tt-rep-kpi__label { font-size: 12px; color: #5b6e75; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-top: 4px; }
        .tt-rep-kpi__sub { font-size: 11px; color: #5b6e75; margin-top: 4px; }
        .tt-rep-kpi__sub--warn { color: #c75c1f; }
        .tt-rep-section { background: #fff; border: 1px solid #d6dadd; border-radius: 12px; padding: 14px 16px; margin: 0 0 16px; }
        .tt-rep-section__head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
        .tt-rep-section__title { margin: 0; font-size: 16px; font-weight: 700; }
        .tt-rep-section__hint { font-size: 12px; color: #5b6e75; }
        .tt-rep-table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        .tt-rep-table th, .tt-rep-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #f0f3f2; }
        .tt-rep-table th { font-size: 12px; color: #5b6e75; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
        .tt-rep-table td.num, .tt-rep-table th.num { text-align: right; }
        .tt-rep-bar-row { display: grid; grid-template-columns: minmax(120px, 1fr) 2fr 56px; gap: 12px; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f3f2; font-size: 14px; }
        .tt-rep-bar-track { background: #f0f3f2; border-radius: 999px; height: 10px; overflow: hidden; }
        .tt-rep-bar-fill { background: #1d7874; height: 100%; border-radius: 999px; }
        .tt-rep-bar-fill[data-warn="1"] { background: #c75c1f; }
        .tt-rep-bar-row .num { text-align: right; font-weight: 600; }
        .tt-rep-empty { text-align: center; padding: 30px 16px; color: #5b6e75; }
        .tt-rep-empty strong { display: block; color: #1a1d21; margin-bottom: 6px; }
        </style>';
    }

    /**
     * Page header with title, sub line, and the three standard
     * action affordances (Explore →, Export, Plan).
     */
    private static function renderPageHead( string $title, string $sub, string $explore_url, ?string $export_url = null, ?string $schedule_url = null ): void {
        echo '<header class="tt-rep-page-head">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        if ( $sub !== '' ) {
            echo '<p class="tt-rep-page-head__sub">' . esc_html( $sub ) . '</p>';
        }
        echo '<div class="tt-rep-page-head__actions">';
        echo '<a class="tt-rep-btn" href="' . esc_url( $explore_url ) . '">' . esc_html__( 'Explorer →', 'talenttrack' ) . '</a>';
        if ( $export_url !== null ) {
            echo '<a class="tt-rep-btn" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export (CSV)', 'talenttrack' ) . '</a>';
        }
        if ( $schedule_url !== null ) {
            echo '<a class="tt-rep-btn" href="' . esc_url( $schedule_url ) . '">' . esc_html__( 'Schedule', 'talenttrack' ) . '</a>';
        }
        echo '</div>';
        echo '</header>';
    }

    /**
     * Render a KPI strip from `[ [ 'num' => N, 'label' => 'X', 'sub' => '…' (optional), 'warn' => bool ], … ]`.
     *
     * @param array<int,array<string,mixed>> $kpis
     */
    private static function renderKpiStrip( array $kpis ): void {
        if ( ! $kpis ) return;
        echo '<div class="tt-rep-kpi-row">';
        foreach ( $kpis as $k ) {
            echo '<div class="tt-rep-kpi">';
            echo '<div class="tt-rep-kpi__num">' . esc_html( (string) ( $k['num'] ?? '0' ) ) . '</div>';
            echo '<div class="tt-rep-kpi__label">' . esc_html( (string) ( $k['label'] ?? '' ) ) . '</div>';
            if ( ! empty( $k['sub'] ) ) {
                $cls = ! empty( $k['warn'] ) ? ' tt-rep-kpi__sub--warn' : '';
                echo '<div class="tt-rep-kpi__sub' . $cls . '">' . esc_html( (string) $k['sub'] ) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private static function renderEmpty(): void {
        echo '<div class="tt-rep-section"><div class="tt-rep-empty">';
        echo '<strong>' . esc_html__( 'No data for this selection', 'talenttrack' ) . '</strong>';
        echo esc_html__( 'Adjust a filter and try again.', 'talenttrack' );
        echo '</div></div>';
    }

    // ── #1090 Player · Minutes played ────────────────────────────────

    private static function renderPlayerMinutesPlayed(): void {
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
        if ( $player === null ) {
            self::renderHeader( __( 'Player · Minutes played', 'talenttrack' ) );
            self::renderPlayerPicker( 'player-minutes-played' );
            return;
        }
        $name = QueryHelpers::player_display_name( $player );
        $team = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $team_name = $team ? (string) $team->name : '';

        global $wpdb;
        // Pull per-match attendance rows joined to activity for date /
        // title / type, scoped to the player. Limit 50 most recent.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id AS activity_id, a.session_date, a.title, a.activity_type_key,
                    att.minutes_played, att.status
               FROM {$wpdb->prefix}tt_attendance att
               JOIN {$wpdb->prefix}tt_activities a ON a.id = att.session_id
              WHERE att.player_id = %d
                AND a.activity_type_key IN ('match','tournament')
              ORDER BY a.session_date DESC
              LIMIT 50",
            $player_id
        ) );
        $rows = is_array( $rows ) ? $rows : [];

        $apps = 0; $minutes = 0;
        foreach ( $rows as $r ) {
            if ( (int) ( $r->minutes_played ?? 0 ) > 0 ) $apps++;
            $minutes += (int) ( $r->minutes_played ?? 0 );
        }
        $avg = $apps > 0 ? (int) round( $minutes / $apps ) : 0;

        $explore_url = ExplorerUrl::build(
            'attendance_vs_squad',
            [ 'player_id' => (string) $player_id, 'date_after' => '-12 months' ],
            'month'
        );
        self::renderPageHead(
            sprintf( /* translators: %s = player name */ __( 'Minutes played — %s', 'talenttrack' ), $name ),
            $team_name,
            $explore_url
        );
        self::renderKpiStrip( [
            [ 'num' => (string) $apps,    'label' => __( 'Appearances', 'talenttrack' ) ],
            [ 'num' => (string) $minutes, 'label' => __( 'Total minutes', 'talenttrack' ) ],
            [ 'num' => (string) $avg,     'label' => __( 'Avg min / appearance', 'talenttrack' ) ],
            [ 'num' => (string) count( $rows ), 'label' => __( 'Matches in roster', 'talenttrack' ) ],
        ] );
        if ( ! $rows ) { self::renderEmpty(); return; }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per match', 'talenttrack' ) . '</h2></div>';
        echo '<table class="tt-rep-table"><thead><tr><th>' . esc_html__( 'Date', 'talenttrack' ) . '</th><th>' . esc_html__( 'Match', 'talenttrack' ) . '</th><th>' . esc_html__( 'Type', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Min', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $url = RecordLink::detailUrlForWithBack( 'activities', (int) $r->activity_id );
            echo '<tr>';
            echo '<td>' . esc_html( (string) $r->session_date ) . '</td>';
            echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $r->title ?? '—' ) ) . '</a></td>';
            echo '<td>' . esc_html( (string) ( $r->activity_type_key ?? '' ) ) . '</td>';
            $min = (int) ( $r->minutes_played ?? 0 );
            echo '<td class="num">' . ( $min > 0 ? esc_html( (string) $min ) : '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }

    // ── #1091 Team · Minutes distribution ────────────────────────────

    private static function renderTeamMinutesDistribution(): void {
        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $team = $team_id > 0 ? QueryHelpers::get_team( $team_id ) : null;
        if ( $team === null ) {
            self::renderHeader( __( 'Team · Minutes distribution', 'talenttrack' ) );
            self::renderTeamPicker( 'team-minutes-distribution' );
            return;
        }

        global $wpdb;
        // Aggregate match minutes per player on this team over the
        // last 12 months. Players on the team's active roster only.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id AS player_id, p.name, p.jersey_number,
                    COALESCE( SUM( att.minutes_played ), 0 ) AS total_minutes,
                    COUNT( DISTINCT CASE WHEN att.minutes_played > 0 THEN a.id END ) AS apps
               FROM {$wpdb->prefix}tt_players p
          LEFT JOIN {$wpdb->prefix}tt_attendance att ON att.player_id = p.id
          LEFT JOIN {$wpdb->prefix}tt_activities a ON a.id = att.session_id
                AND a.activity_type_key IN ('match','tournament')
                AND a.session_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
              WHERE p.team_id = %d AND p.archived_at IS NULL
              GROUP BY p.id, p.name, p.jersey_number
              ORDER BY total_minutes DESC, p.name ASC
              LIMIT 60",
            $team_id
        ) );
        $rows = is_array( $rows ) ? $rows : [];

        $match_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d AND activity_type_key IN ('match','tournament')
                AND session_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)",
            $team_id
        ) );
        $top = $rows ? (int) $rows[0]->total_minutes : 0;
        $bottom = $rows ? (int) $rows[ count( $rows ) - 1 ]->total_minutes : 0;
        $spread_pct = $top > 0 ? (int) round( ( ( $top - $bottom ) / $top ) * 100 ) : 0;

        $explore_url = ExplorerUrl::build(
            'attendance_vs_squad',
            [ 'team_id' => (string) $team_id, 'date_after' => '-12 months' ],
            'player_id'
        );
        self::renderPageHead(
            sprintf( /* translators: %s = team name */ __( 'Minutes distribution — %s', 'talenttrack' ), (string) $team->name ),
            sprintf( /* translators: %d = match count */ _n( '%d match in the window', '%d matches in the window', $match_count, 'talenttrack' ), $match_count ),
            $explore_url
        );
        self::renderKpiStrip( [
            [ 'num' => (string) count( $rows ), 'label' => __( 'Players in selection', 'talenttrack' ) ],
            [ 'num' => (string) $match_count,   'label' => __( 'Matches', 'talenttrack' ) ],
            [ 'num' => (string) $top,           'label' => __( 'Max minutes / player', 'talenttrack' ) ],
            [
                'num'   => $spread_pct . '%',
                'label' => __( 'Spread (top vs bottom)', 'talenttrack' ),
                'sub'   => $spread_pct > 30 ? __( '⚠ Above 30% — imbalance', 'talenttrack' ) : __( 'Balanced selection', 'talenttrack' ),
                'warn'  => $spread_pct > 30,
            ],
        ] );
        if ( ! $rows ) { self::renderEmpty(); return; }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per player', 'talenttrack' ) . '</h2><span class="tt-rep-section__hint">' . esc_html__( 'Sorted by minutes, high to low.', 'talenttrack' ) . '</span></div>';
        $threshold = $top > 0 ? (int) round( $top * 0.5 ) : 0;
        foreach ( $rows as $r ) {
            $mins = (int) $r->total_minutes;
            $pct  = $top > 0 ? max( 5, (int) round( ( $mins / $top ) * 100 ) ) : 0;
            $warn = $mins < $threshold ? '1' : '0';
            echo '<div class="tt-rep-bar-row">';
            echo '<span>' . esc_html( (string) $r->name ) . '</span>';
            echo '<div class="tt-rep-bar-track"><div class="tt-rep-bar-fill" data-warn="' . $warn . '" style="width:' . (int) $pct . '%;"></div></div>';
            echo '<span class="num">' . esc_html( (string) $mins ) . '</span>';
            echo '</div>';
        }
        echo '</section>';
        if ( $spread_pct > 30 ) {
            echo '<section class="tt-rep-section">';
            echo '<h2 class="tt-rep-section__title" style="margin:0 0 4px;">' . esc_html__( 'Imbalance signal', 'talenttrack' ) . '</h2>';
            echo '<p style="margin:0; font-size:13px; color: #c75c1f;">';
            printf(
                /* translators: %d = spread percentage */
                esc_html__( 'Spread of %d%% — bottom-half players have less than half of the leading minutes. Consider rotation in upcoming matches.', 'talenttrack' ),
                $spread_pct
            );
            echo '</p></section>';
        }
    }

    // ── #1092 Team · Squad evaluation summary ───────────────────────

    private static function renderSquadEvaluationSummary(): void {
        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $team = $team_id > 0 ? QueryHelpers::get_team( $team_id ) : null;
        if ( $team === null ) {
            self::renderHeader( __( 'Team · Squad evaluation summary', 'talenttrack' ) );
            self::renderTeamPicker( 'team-squad-evaluation-summary' );
            return;
        }
        global $wpdb;
        // Per-player average rating across all categories, last 6 mo.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id AS player_id, p.name,
                    AVG( r.rating ) AS avg_rating,
                    COUNT( DISTINCT e.id ) AS eval_count
               FROM {$wpdb->prefix}tt_players p
          LEFT JOIN {$wpdb->prefix}tt_evaluations e ON e.player_id = p.id
                AND e.archived_at IS NULL
                AND e.eval_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          LEFT JOIN {$wpdb->prefix}tt_eval_ratings r ON r.evaluation_id = e.id
              WHERE p.team_id = %d AND p.archived_at IS NULL
              GROUP BY p.id, p.name
              ORDER BY avg_rating DESC, p.name ASC
              LIMIT 60",
            $team_id
        ) );
        $rows = is_array( $rows ) ? $rows : [];
        $rated = array_filter( $rows, static fn( $r ): bool => $r->eval_count > 0 );
        $sum_avg = 0.0;
        foreach ( $rated as $r ) $sum_avg += (float) $r->avg_rating;
        $squad_avg = $rated ? round( $sum_avg / count( $rated ), 1 ) : 0;
        $coverage = count( $rows ) > 0 ? (int) round( ( count( $rated ) / count( $rows ) ) * 100 ) : 0;

        $explore_url = ExplorerUrl::build(
            'evaluations_received',
            [ 'team_id' => (string) $team_id, 'date_after' => '-6 months' ],
            'month'
        );
        self::renderPageHead(
            sprintf( /* translators: %s = team name */ __( 'Squad evaluation summary — %s', 'talenttrack' ), (string) $team->name ),
            __( 'Last 6 months', 'talenttrack' ),
            $explore_url
        );
        self::renderKpiStrip( [
            [ 'num' => (string) count( $rows ),  'label' => __( 'Players', 'talenttrack' ) ],
            [ 'num' => (string) count( $rated ), 'label' => __( 'Evaluated', 'talenttrack' ) ],
            [ 'num' => (string) $squad_avg,      'label' => __( 'Squad average rating', 'talenttrack' ) ],
            [ 'num' => $coverage . '%',          'label' => __( 'Coverage', 'talenttrack' ) ],
        ] );
        if ( ! $rows ) { self::renderEmpty(); return; }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per player', 'talenttrack' ) . '</h2><span class="tt-rep-section__hint">' . esc_html__( 'Sorted by average rating, high to low.', 'talenttrack' ) . '</span></div>';
        echo '<table class="tt-rep-table"><thead><tr><th>' . esc_html__( 'Player', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Avg rating', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Evaluations', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $url = RecordLink::detailUrlForWithBack( 'players', (int) $r->player_id );
            $avg = $r->avg_rating !== null ? round( (float) $r->avg_rating, 1 ) : null;
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $r->name ) . '</a></td>';
            echo '<td class="num">' . ( $avg !== null ? esc_html( (string) $avg ) : '—' ) . '</td>';
            echo '<td class="num">' . esc_html( (string) (int) $r->eval_count ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }

    // ── #1093 Season summary ────────────────────────────────────────

    private static function renderSeasonSummary(): void {
        global $wpdb;
        $club_id = CurrentClub::id();
        $players_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players WHERE club_id=%d AND archived_at IS NULL", $club_id ) );
        $teams_total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_teams WHERE club_id=%d AND archived_at IS NULL", $club_id ) );
        $matches_12m   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities WHERE club_id=%d AND activity_type_key IN ('match','tournament') AND session_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", $club_id ) );
        $evals_12m     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE club_id=%d AND archived_at IS NULL AND eval_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", $club_id ) );
        $prospects_12m = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospects WHERE club_id=%d AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", $club_id ) );
        $trial_decisions_12m = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases WHERE club_id=%d AND decided_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", $club_id ) );

        $explore_url = ExplorerUrl::build(
            'evaluations_received',
            [ 'date_after' => '-12 months' ],
            'month'
        );
        self::renderPageHead(
            __( 'Season summary — annual review', 'talenttrack' ),
            __( 'Academy-wide signals over the last 12 months', 'talenttrack' ),
            $explore_url
        );
        self::renderKpiStrip( [
            [ 'num' => (string) $players_total, 'label' => __( 'Active players', 'talenttrack' ) ],
            [ 'num' => (string) $teams_total,   'label' => __( 'Active teams', 'talenttrack' ) ],
            [ 'num' => (string) $matches_12m,   'label' => __( 'Matches (12 mo)', 'talenttrack' ) ],
            [ 'num' => (string) $evals_12m,     'label' => __( 'Evaluations (12 mo)', 'talenttrack' ) ],
            [ 'num' => (string) $prospects_12m, 'label' => __( 'Prospects logged (12 mo)', 'talenttrack' ) ],
            [ 'num' => (string) $trial_decisions_12m, 'label' => __( 'Trial decisions (12 mo)', 'talenttrack' ) ],
        ] );

        $by_team = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.name,
                    COUNT( DISTINCT p.id ) AS player_count,
                    COUNT( DISTINCT CASE WHEN a.activity_type_key IN ('match','tournament')
                                          AND a.session_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                         THEN a.id END ) AS match_count
               FROM {$wpdb->prefix}tt_teams t
          LEFT JOIN {$wpdb->prefix}tt_players p ON p.team_id = t.id AND p.archived_at IS NULL
          LEFT JOIN {$wpdb->prefix}tt_activities a ON a.team_id = t.id
              WHERE t.club_id = %d AND t.archived_at IS NULL
              GROUP BY t.id, t.name
              ORDER BY t.name ASC",
            $club_id
        ) );
        if ( ! is_array( $by_team ) || ! $by_team ) { return; }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per team', 'talenttrack' ) . '</h2></div>';
        echo '<table class="tt-rep-table"><thead><tr><th>' . esc_html__( 'Team', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Players', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Matches (12 mo)', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $by_team as $r ) {
            $url = RecordLink::detailUrlForWithBack( 'teams', (int) $r->id );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $r->name ) . '</a></td>';
            echo '<td class="num">' . esc_html( (string) (int) $r->player_count ) . '</td>';
            echo '<td class="num">' . esc_html( (string) (int) $r->match_count ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }

    // ── #1094 Season · Trial funnel ─────────────────────────────────

    private static function renderSeasonTrialFunnel(): void {
        global $wpdb;
        $club_id = CurrentClub::id();
        // Funnel stages: prospects → trial_cases opened → decided.
        $prospects     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospects WHERE club_id=%d AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", $club_id ) );
        $cases_opened  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases WHERE club_id=%d AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", $club_id ) );
        $cases_decided = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases WHERE club_id=%d AND decided_at IS NOT NULL AND decided_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", $club_id ) );
        $by_scout = $wpdb->get_results( $wpdb->prepare(
            "SELECT u.display_name, COUNT(*) AS opened
               FROM {$wpdb->prefix}tt_trial_cases tc
          LEFT JOIN {$wpdb->users} u ON u.ID = tc.opened_by
              WHERE tc.club_id = %d AND tc.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
              GROUP BY u.ID, u.display_name
              ORDER BY opened DESC
              LIMIT 30",
            $club_id
        ) );
        $by_decision = $wpdb->get_results( $wpdb->prepare(
            "SELECT decision, COUNT(*) AS n
               FROM {$wpdb->prefix}tt_trial_cases
              WHERE club_id = %d AND decided_at IS NOT NULL
                AND decided_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
              GROUP BY decision
              ORDER BY n DESC",
            $club_id
        ) );
        $by_scout = is_array( $by_scout ) ? $by_scout : [];
        $by_decision = is_array( $by_decision ) ? $by_decision : [];

        $explore_url = ExplorerUrl::build(
            'prospects_logged_per_scout',
            [ 'date_after' => '-12 months' ],
            'discovered_by_user_id'
        );
        self::renderPageHead(
            __( 'Trial funnel — per scout, per period', 'talenttrack' ),
            __( 'Last 12 months', 'talenttrack' ),
            $explore_url
        );
        self::renderKpiStrip( [
            [ 'num' => (string) $prospects,     'label' => __( 'Prospects logged', 'talenttrack' ) ],
            [ 'num' => (string) $cases_opened,  'label' => __( 'Trial cases opened', 'talenttrack' ) ],
            [ 'num' => (string) $cases_decided, 'label' => __( 'Decided', 'talenttrack' ) ],
            [
                'num'   => $cases_opened > 0 ? (int) round( ( $cases_decided / $cases_opened ) * 100 ) . '%' : '0%',
                'label' => __( 'Decision rate', 'talenttrack' ),
            ],
        ] );
        if ( $by_scout ) {
            echo '<section class="tt-rep-section">';
            echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per scout', 'talenttrack' ) . '</h2></div>';
            echo '<table class="tt-rep-table"><thead><tr><th>' . esc_html__( 'Scout', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Cases opened', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $by_scout as $r ) {
                echo '<tr><td>' . esc_html( (string) ( $r->display_name ?? __( '—', 'talenttrack' ) ) ) . '</td><td class="num">' . esc_html( (string) (int) $r->opened ) . '</td></tr>';
            }
            echo '</tbody></table></section>';
        }
        if ( $by_decision ) {
            echo '<section class="tt-rep-section">';
            echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per decision', 'talenttrack' ) . '</h2></div>';
            echo '<table class="tt-rep-table"><thead><tr><th>' . esc_html__( 'Decision', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Cases', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $by_decision as $r ) {
                echo '<tr><td>' . esc_html( (string) ( $r->decision ?? __( '—', 'talenttrack' ) ) ) . '</td><td class="num">' . esc_html( (string) (int) $r->n ) . '</td></tr>';
            }
            echo '</tbody></table></section>';
        }
        if ( ! $by_scout && ! $by_decision ) {
            self::renderEmpty();
        }
    }

    // ── #1095 Scout · Report card ───────────────────────────────────

    private static function renderScoutReportCard(): void {
        $scout_id = isset( $_GET['scout_id'] ) ? absint( $_GET['scout_id'] ) : (int) get_current_user_id();
        if ( $scout_id <= 0 ) {
            self::renderHeader( __( 'Scout report card', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Pick a scout from the Reports launcher.', 'talenttrack' ) . '</p>';
            return;
        }
        global $wpdb;
        $user = get_userdata( $scout_id );
        $name = $user ? (string) $user->display_name : sprintf( __( 'Scout #%d', 'talenttrack' ), $scout_id );
        $club_id = CurrentClub::id();
        $prospects_logged = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospects
              WHERE club_id = %d AND discovered_by_user_id = %d
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)",
            $club_id, $scout_id
        ) );
        $cases_opened = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases
              WHERE club_id = %d AND opened_by = %d
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)",
            $club_id, $scout_id
        ) );
        $cases_admitted = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases
              WHERE club_id = %d AND opened_by = %d AND decision = 'admit'
                AND decided_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)",
            $club_id, $scout_id
        ) );
        $hit_rate = $cases_opened > 0 ? (int) round( ( $cases_admitted / $cases_opened ) * 100 ) : 0;

        $recent_prospects = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, current_club, created_at
               FROM {$wpdb->prefix}tt_prospects
              WHERE club_id = %d AND discovered_by_user_id = %d
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
              ORDER BY created_at DESC LIMIT 20",
            $club_id, $scout_id
        ) );
        $recent_prospects = is_array( $recent_prospects ) ? $recent_prospects : [];

        $explore_url = ExplorerUrl::build(
            'prospects_logged_per_scout',
            [ 'discovered_by_user_id' => (string) $scout_id, 'date_after' => '-12 months' ],
            'month'
        );
        self::renderPageHead(
            sprintf( /* translators: %s = scout name */ __( 'Scout report card — %s', 'talenttrack' ), $name ),
            __( 'Last 12 months', 'talenttrack' ),
            $explore_url
        );
        self::renderKpiStrip( [
            [ 'num' => (string) $prospects_logged, 'label' => __( 'Prospects logged', 'talenttrack' ) ],
            [ 'num' => (string) $cases_opened,     'label' => __( 'Trial cases opened', 'talenttrack' ) ],
            [ 'num' => (string) $cases_admitted,   'label' => __( 'Admitted', 'talenttrack' ) ],
            [ 'num' => $hit_rate . '%',            'label' => __( 'Hit rate', 'talenttrack' ) ],
        ] );
        if ( ! $recent_prospects ) { self::renderEmpty(); return; }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Recent prospects', 'talenttrack' ) . '</h2></div>';
        echo '<table class="tt-rep-table"><thead><tr><th>' . esc_html__( 'Date', 'talenttrack' ) . '</th><th>' . esc_html__( 'Prospect', 'talenttrack' ) . '</th><th>' . esc_html__( 'Current club', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $recent_prospects as $r ) {
            $full = trim( ( (string) ( $r->first_name ?? '' ) ) . ' ' . ( (string) ( $r->last_name ?? '' ) ) );
            echo '<tr>';
            echo '<td>' . esc_html( gmdate( 'Y-m-d', strtotime( (string) ( $r->created_at ?? '' ) ) ?: time() ) ) . '</td>';
            echo '<td>' . esc_html( $full !== '' ? $full : '—' ) . '</td>';
            echo '<td>' . esc_html( (string) ( $r->current_club ?? '' ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }

    /**
     * Render a "pick a player" landing for entity-scoped reports that
     * arrive without `player_id`. Lists active players (cap-scoped via
     * `QueryHelpers::get_players()`).
     */
    private static function renderPlayerPicker( string $slug ): void {
        $base_url = remove_query_arg( [ 'player_id' ] );
        $players  = QueryHelpers::get_players( 0 );
        if ( ! $players ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players available — add players first to enable this report.', 'talenttrack' ) . '</p>';
            return;
        }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Pick a player', 'talenttrack' ) . '</h2></div>';
        echo '<ul style="margin:0; padding:0; list-style:none; display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:6px;">';
        foreach ( $players as $p ) {
            $pid = (int) ( $p->id ?? 0 );
            if ( $pid <= 0 ) continue;
            $name = QueryHelpers::player_display_name( $p );
            $url  = add_query_arg( [ 'slug' => $slug, 'player_id' => $pid ], $base_url );
            echo '<li><a class="tt-rep-btn" style="display:block;" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></li>';
        }
        echo '</ul></section>';
    }

    /**
     * Render a "pick a team" landing for entity-scoped reports that
     * arrive without `team_id`.
     */
    private static function renderTeamPicker( string $slug ): void {
        $base_url = remove_query_arg( [ 'team_id' ] );
        $teams    = QueryHelpers::get_teams();
        if ( ! $teams ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams configured yet.', 'talenttrack' ) . '</p>';
            return;
        }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Pick a team', 'talenttrack' ) . '</h2></div>';
        echo '<ul style="margin:0; padding:0; list-style:none; display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:6px;">';
        foreach ( $teams as $t ) {
            $tid = (int) ( $t->id ?? 0 );
            if ( $tid <= 0 ) continue;
            $url = add_query_arg( [ 'slug' => $slug, 'team_id' => $tid ], $base_url );
            $label = (string) ( $t->name ?? '' );
            if ( ! empty( $t->age_group ) ) $label .= ' (' . (string) $t->age_group . ')';
            echo '<li><a class="tt-rep-btn" style="display:block;" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
        }
        echo '</ul></section>';
    }
}
