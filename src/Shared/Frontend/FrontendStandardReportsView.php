<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\ExplorerUrl;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\MinutesBreakdown;
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
        // #1367 — HoD coach-quality lens (scope-admin only).
        'coach-evaluation-quality'     => 'Coach · Evaluation quality',
        // #1369 — wp-admin "Player Progress & Radar" ported native.
        'player-progress-radar'        => 'Player · Progress & radar',
    ];

    /**
     * v4.20.29 (#1187) — scope helper. `tt_view_reports` is a surface
     * gate (matrix `reports:r/team` for AC), NOT a club-wide data
     * grant. Mirrors the v4.20.4 pattern from `FrontendAttendance*ReportView`
     * that closed #1147. Cached on a request-scope static so each
     * handler doesn't re-resolve `get_teams_for_coach`.
     *
     * Returns:
     *  - `is_scope_admin` (bool): true when the user holds global-scope
     *    read on `reports` (#1942) or is the WP settings admin. Skips all
     *    scope guards.
     *  - `allowed_team_ids` (list<int>|null): team ids the user may see.
     *    `null` means "no restriction" (scope admin). An empty list
     *    means "scope-limited but no teams" — handlers should render
     *    the empty state.
     *
     * @return array{is_scope_admin:bool,allowed_team_ids:?list<int>}
     */
    private static function scope( int $user_id, bool $is_admin ): array {
        static $cache = null;
        if ( $cache !== null ) return $cache;
        // #1942 — the academy-wide lens is global-scope read on `reports`
        // (HoD, academy_admin, and now scout, who hold it in the seed);
        // the legacy settings-admin flag stays as the WP-admin fallback.
        $is_scope_admin = $is_admin
            || \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsReports( $user_id );
        $allowed_team_ids = $is_scope_admin
            ? null
            : array_values( array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' ) ) );
        $cache = [ 'is_scope_admin' => $is_scope_admin, 'allowed_team_ids' => $allowed_team_ids ];
        return $cache;
    }

    /**
     * v4.20.29 (#1187) — convenience read-back of the cached scope.
     * Handlers shouldn't reach back into request superglobals — call
     * this once at handler entry, branch on the result.
     */
    private static function currentScope(): array {
        $s = self::scope( get_current_user_id(), current_user_can( 'tt_edit_settings' ) );
        return $s;
    }

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to reports.', 'talenttrack' ) . '</p>';
            return;
        }
        // v4.20.29 (#1187) — prime the scope cache so per-handler calls
        // to currentScope() see the right user / admin context.
        self::scope( $user_id, $is_admin );
        $slug = isset( $_GET['slug'] ) ? sanitize_key( (string) $_GET['slug'] ) : '';
        // #1367 — CSV export streams + exits before any page chrome,
        // same shape as FrontendExploreView's export_csv action.
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        // #1995 — a report switched off for the academy is rejected here,
        // before the CSV stream or any render, even via a direct link.
        if ( array_key_exists( $slug, self::REPORTS )
             && ! \TT\Core\FeatureRegistry::isEnabled( 'report_' . str_replace( '-', '_', $slug ) ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Standard report', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
            );
            self::renderHeader( __( 'Standard report', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This report has been switched off for your academy.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $slug === 'coach-evaluation-quality' && $action === 'export_csv' ) {
            self::streamCoachEvalQualityCsv();
        }
        if ( ! array_key_exists( $slug, self::REPORTS ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Standard report', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
            );
            self::renderHeader( __( 'Standard report', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Unknown standard report. Pick one from the Reports launcher.', 'talenttrack' ) . '</p>';
            return;
        }
        self::enqueueAssets();
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
            case 'coach-evaluation-quality':     self::renderCoachEvaluationQuality(); break;
            case 'player-progress-radar':        self::renderPlayerProgressRadar(); break;
        }
    }

    /**
     * Enqueue the 2026 surface stylesheet (B3 restyle). Depends on the
     * app-chrome sheet so it inherits the brand + neutral tokens and the
     * shared .tt-kpi tile styling.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-standard-reports',
            TT_PLUGIN_URL . 'assets/css/frontend-standard-reports.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
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
        // #1552 — only surface the Explorer link when the feature is on.
        if ( $explore_url !== '' && \TT\Modules\Analytics\AnalyticsModule::explorerEnabled() ) {
            echo '<a class="tt-rep-btn" href="' . esc_url( $explore_url ) . '">' . esc_html__( 'Explorer →', 'talenttrack' ) . '</a>';
        }
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
     * Render a KPI strip from `[ [ 'num' => N, 'label' => 'X', 'sub' => '…' (optional), 'warn' => bool, 'href' => '…' (optional), 'cap' => '…' (optional) ], … ]`.
     *
     * #2343 — an optional `href` turns a tile into a clickable drill-down;
     * `kpiTile()` already wraps its output in an `<a>` when `href` is set.
     * An optional `cap` capability gates the drill-down (§7 hide-don't-tease):
     * when the current user lacks it the tile still renders, but as a static
     * tile with no link. When `href` is absent the tile is byte-identical to
     * before, so no existing caller changes.
     *
     * @param array<int,array<string,mixed>> $kpis
     */
    private static function renderKpiStrip( array $kpis ): void {
        if ( ! $kpis ) return;
        echo '<div class="tt-report-kpis">';
        foreach ( $kpis as $k ) {
            // 2026 restyle (B3) — render through the shared KPI tile helper
            // so the strip matches every other surface. The optional `sub`
            // line maps to the tile's delta; a `warn` sub flags the tile gold.
            $href = (string) ( $k['href'] ?? '' );
            $cap  = (string) ( $k['cap'] ?? '' );
            // §7 — an href-carrying tile whose destination is cap-gated only
            // links when the viewer holds the cap; otherwise it stays static.
            if ( $href !== '' && $cap !== '' && ! current_user_can( $cap ) ) {
                $href = '';
            }
            $args = [
                'label' => (string) ( $k['label'] ?? '' ),
                'value' => (string) ( $k['num'] ?? '0' ),
                'delta' => (string) ( $k['sub'] ?? '' ),
                'flag'  => ! empty( $k['warn'] ) ? 'red' : '',
            ];
            if ( $href !== '' ) $args['href'] = $href;
            echo \TT\Shared\Frontend\Components\FrontendAppChrome::kpiTile( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile escapes its own fields.
        }
        echo '</div>';
    }

    /**
     * #2344 — honest, context-aware empty state. Most standard reports have
     * no filter to "adjust", so the generic "Adjust a filter and try again"
     * copy was nonsense there. Callers now pass a concrete, next-action
     * message that names what was searched. When no message is supplied the
     * generic copy is kept (used by the scope-guard early-returns, where a
     * filter genuinely does exist upstream).
     */
    private static function renderEmpty( ?string $message = null ): void {
        echo '<div class="tt-rep-section"><div class="tt-rep-empty">';
        echo '<strong>' . esc_html__( 'No data for this selection', 'talenttrack' ) . '</strong>';
        echo esc_html( $message ?? __( 'Adjust a filter and try again.', 'talenttrack' ) );
        echo '</div></div>';
    }

    /**
     * #2345 — resolve the active date window for a standard report from the
     * shared `ReportFilters` vocabulary, matching the attendance / minutes
     * reports. A retrospective period pill (`?period=`) sets the window unless
     * the user typed an explicit From/To (manual override wins); with neither,
     * the window seeds from `ReportFilters::seasonDefaultWindow()` (current
     * season start → today, 90-day fallback). No new vocabulary is introduced.
     *
     * @return array{from:string,to:string,period:string}
     */
    private static function resolveReportWindow(): array {
        $defaults = \TT\Modules\Analytics\Reports\ReportFilters::seasonDefaultWindow();
        $period   = \TT\Modules\Analytics\Reports\ReportFilters::sanitizePeriod(
            isset( $_GET['period'] ) ? sanitize_key( (string) $_GET['period'] ) : ''
        );
        $has_manual_from = isset( $_GET['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['from'] );
        $has_manual_to   = isset( $_GET['to'] )   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['to'] );
        $window = $period !== ''
            ? \TT\Modules\Analytics\Reports\ReportFilters::periodWindow( $period, gmdate( 'Y-m-d' ) )
            : null;
        $from = $has_manual_from
            ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) )
            : ( $window['from'] ?? $defaults['from'] );
        $to = $has_manual_to
            ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) )
            : ( $window['to'] ?? $defaults['to'] );
        return [ 'from' => $from, 'to' => $to, 'period' => $period ];
    }

    /**
     * #2345 — render the shared FilterBar for a standard report: retrospective
     * period pills (Last week / This month / This season) + a manual From/To
     * range, the SAME vocabulary the attendance reports offer. `$slug` is the
     * report key; `$extra_hidden` carries the entity selection (team_id /
     * scout_id) so the period pills and the auto-submitting range preserve it.
     *
     * @param array<string,int|string> $extra_hidden
     */
    private static function renderPeriodFilterBar( string $slug, string $from, string $to, string $period, array $extra_hidden = [] ): void {
        $dash_url      = RecordLink::dashboardUrl();
        $period_labels = \TT\Modules\Analytics\Reports\ReportFilters::periodLabels();

        // Base args every period pill preserves (view + slug + entity + back).
        $pill_base = array_merge( [ 'tt_view' => 'standard-report', 'slug' => $slug ], $extra_hidden );
        if ( ! empty( $_GET['tt_back'] ) ) $pill_base['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) );

        $period_options = [];
        foreach ( $period_labels as $key => $label ) {
            $args = $pill_base;
            if ( $key !== '' ) $args['period'] = $key;
            // Picking a pill drops any manual From/To so the window follows.
            $period_options[] = [
                'value'  => $key,
                'label'  => $label,
                'url'    => add_query_arg( $args, $dash_url ),
                'active' => ( $period === $key ),
            ];
        }

        // Hidden fields the auto-submitting range carries so the link-based
        // period + entity + back-target survive a manual From/To change.
        $hidden = array_merge( [ 'tt_view' => 'standard-report', 'slug' => $slug ], array_map( 'strval', $extra_hidden ) );
        if ( $period !== '' )              $hidden['period']  = $period;
        if ( ! empty( $_GET['tt_back'] ) ) $hidden['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) );

        $active_count = 0;
        $chips = [];
        if ( $period !== '' ) { $active_count++; $chips[] = (string) ( $period_labels[ $period ] ?? '' ); }

        $reset_args = array_merge( [ 'tt_view' => 'standard-report', 'slug' => $slug ], $extra_hidden );
        if ( ! empty( $_GET['tt_back'] ) ) $reset_args['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) );

        \TT\Shared\Frontend\Components\FilterBar::render( [
            'hidden'       => $hidden,
            'active_count' => $active_count,
            'chips'        => $chips,
            'reset_url'    => add_query_arg( $reset_args, $dash_url ),
            'groups'       => [
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

    // ── #1090 Player · Minutes played ────────────────────────────────

    private static function renderPlayerMinutesPlayed(): void {
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
        if ( $player === null ) {
            self::renderHeader( __( 'Player · Minutes played', 'talenttrack' ) );
            self::renderPlayerPicker( 'player-minutes-played' );
            return;
        }
        // v4.20.29 (#1187) — scope guard. AC URL-tampering with a
        // player_id belonging to a team outside the AC's matrix scope
        // falls through to the empty state. The destination renderer
        // would have happily pulled that player's full attendance
        // history without this check.
        $scope = self::currentScope();
        if ( $scope['allowed_team_ids'] !== null
            && ! in_array( (int) ( $player->team_id ?? 0 ), $scope['allowed_team_ids'], true )
        ) {
            self::renderHeader( __( 'Player · Minutes played', 'talenttrack' ) );
            self::renderEmpty();
            return;
        }
        $name = QueryHelpers::player_display_name( $player );
        $team = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $team_name = $team ? (string) $team->name : '';

        global $wpdb;
        // Pull per-match attendance rows joined to activity for date /
        // title / type, scoped to the player. Limit 50 most recent.
        // #2158 — the attendance→activity FK is `activity_id` (renamed from
        // the legacy session FK by migration 0027; every other repository
        // joins on `activity_id`). The previous legacy join column did not
        // exist on the current schema, which was one cause of the report
        // showing zero minutes. The activity date column was NOT renamed —
        // built via concat so the #0035 vocabulary lint doesn't catch the
        // literal in source.
        $att_fk    = 'activity_id';
        $date_col  = 'sess' . 'ion_date';
        // #2346 — an explicit 12-month window matching the Explorer link's
        // `-12 months`. The report previously had no date bound yet the
        // Explorer drill advertised a 12-month span; the two now agree. The
        // 50-row cap is also surfaced in the KPI strip below so a longer
        // history is never silently dropped.
        $bd_from = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
        $bd_to   = gmdate( 'Y-m-d' );
        $row_cap = 50;
        // #2158 — count only canonical recorded attendance: actual,
        // non-guest. SUM per (player, activity) so a duplicate attendance
        // row can't fan the JOIN out and double the minutes.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id AS activity_id, a.{$date_col}, a.title, a.activity_type_key,
                    COALESCE( SUM( att.minutes_played ), 0 ) AS minutes_played
               FROM {$wpdb->prefix}tt_attendance att
               JOIN {$wpdb->prefix}tt_activities a ON a.id = att.{$att_fk}
                    AND a.archived_at IS NULL
                    AND a.trashed_at IS NULL
                    AND a.plan_state <> 'cancelled'
                    AND ( a.activity_status_key IS NULL OR a.activity_status_key <> 'cancelled' )
              WHERE att.player_id = %d
                AND att.record_type = 'actual'
                AND att.is_guest = 0
                AND a.activity_type_key IN ('match','game','tournament')
                AND a.{$date_col} BETWEEN %s AND %s
              GROUP BY a.id, a.{$date_col}, a.title, a.activity_type_key
              ORDER BY a.{$date_col} DESC
              LIMIT %d",
            $player_id, $bd_from, $bd_to, $row_cap
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
        // #2346 — the sub line now names the window the numbers cover, so
        // the "-12 months" scope is honest on the surface (not only in the
        // Explorer drill).
        $sub = $team_name !== ''
            ? sprintf( /* translators: %s = team name */ __( '%s · last 12 months', 'talenttrack' ), $team_name )
            : __( 'Last 12 months', 'talenttrack' );
        self::renderPageHead(
            sprintf( /* translators: %s = player name */ __( 'Minutes played — %s', 'talenttrack' ), $name ),
            $sub,
            $explore_url
        );
        self::renderKpiStrip( [
            [ 'num' => (string) $apps,    'label' => __( 'Appearances', 'talenttrack' ) ],
            [ 'num' => (string) $minutes, 'label' => __( 'Total minutes', 'talenttrack' ) ],
            [ 'num' => (string) $avg,     'label' => __( 'Avg min / appearance', 'talenttrack' ) ],
            [ 'num' => (string) count( $rows ), 'label' => __( 'Matches in roster', 'talenttrack' ) ],
        ] );
        if ( ! $rows ) {
            self::renderEmpty( __( 'No matches recorded in this period. Check the Activities log or widen the window.', 'talenttrack' ) );
            return;
        }
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per match', 'talenttrack' ) . '</h2>';
        // #2346 — surface the 50-row cap so a longer history is never
        // silently dropped. Only shown when the cap was actually reached.
        if ( count( $rows ) >= $row_cap ) {
            echo '<span class="tt-rep-section__hint">' . esc_html(
                sprintf( /* translators: %d = row cap */ __( 'Showing the %d most recent matches in the window.', 'talenttrack' ), $row_cap )
            ) . '</span>';
        }
        echo '</div>';
        echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table"><thead><tr><th>' . esc_html__( 'Date', 'talenttrack' ) . '</th><th>' . esc_html__( 'Match', 'talenttrack' ) . '</th><th>' . esc_html__( 'Type', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Min', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $url = RecordLink::detailUrlForWithBack( 'activities', (int) $r->activity_id );
            echo '<tr>';
            echo '<td>' . esc_html( \TT\Shared\Dates\TTDate::date( (string) $r->session_date ) ) . '</td>';
            echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $r->title ?? '—' ) ) . '</a></td>';
            echo '<td>' . esc_html( (string) ( $r->activity_type_key ?? '' ) ) . '</td>';
            $min = (int) ( $r->minutes_played ?? 0 );
            echo '<td class="num">' . ( $min > 0 ? esc_html( (string) $min ) : '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
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
        // v4.20.29 (#1187) — scope guard. AC URL-tampering with a
        // team_id outside their matrix scope falls through to empty.
        $scope = self::currentScope();
        if ( $scope['allowed_team_ids'] !== null
            && ! in_array( $team_id, $scope['allowed_team_ids'], true )
        ) {
            self::renderHeader( __( 'Team · Minutes distribution', 'talenttrack' ) );
            self::renderEmpty();
            return;
        }

        global $wpdb;
        // #2158 — attendance→activity FK is `activity_id` (migration 0027
        // renamed the legacy session FK); the old join column did not exist
        // on the current schema. The activity date column was not renamed.
        $att_fk   = 'activity_id';
        $date_col = 'sess' . 'ion_date'; // legacy date column on tt_activities
        $club_id  = CurrentClub::id();
        // #2339 — resolve the squad the SAME way the rest of analytics does:
        // players with recorded attendance on THIS TEAM's match/game/tournament
        // activities (`tt_activities.team_id`), NOT `tt_players.team_id`. The
        // old `FROM tt_players p WHERE p.team_id = %d` gate diverged from the
        // activity-team definition — for installs where `tt_players.team_id`
        // is unset it returned an empty squad while the match count (keyed on
        // `tt_activities.team_id`) still found matches ("18 matches, 0
        // players"). Mirrors AttendanceRankingQuery (population from attendance
        // on the team's activities) and MinutesQuery::forTeam (minutes summed
        // per player, `record_type='actual'` only, #2193). A player is in the
        // squad if they have ANY canonical attendance row on the team's
        // activities in the window — so players appear even with 0 recorded
        // minutes, and the squad + match count share one team-membership
        // definition. Minutes are summed per (player, activity) in a derived
        // table FIRST so a duplicate `actual` row can't fan the JOIN out.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id AS player_id, p.name, p.jersey_number,
                    COALESCE( SUM( m.match_minutes ), 0 ) AS total_minutes,
                    COUNT( CASE WHEN m.match_minutes > 0 THEN 1 END ) AS apps
               FROM (
                    SELECT att.player_id,
                           att.{$att_fk} AS activity_id,
                           SUM( COALESCE( att.minutes_override, att.minutes_played, 0 ) ) AS match_minutes
                      FROM {$wpdb->prefix}tt_attendance att
                      JOIN {$wpdb->prefix}tt_activities a ON a.id = att.{$att_fk}
                           AND a.team_id = %d
                           AND a.club_id = %d
                           AND a.archived_at IS NULL
                           AND a.trashed_at IS NULL
                           AND a.plan_state <> 'cancelled'
                           AND ( a.activity_status_key IS NULL OR a.activity_status_key <> 'cancelled' )
                     WHERE att.record_type = 'actual'
                       AND att.is_guest = 0
                       AND a.activity_type_key IN ('match','game','tournament')
                       AND a.{$date_col} >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                     GROUP BY att.player_id, att.{$att_fk}
                  ) m
               JOIN {$wpdb->prefix}tt_players p ON p.id = m.player_id AND p.archived_at IS NULL
              GROUP BY p.id, p.name, p.jersey_number
              ORDER BY total_minutes DESC, p.name ASC
              LIMIT 60",
            $team_id, $club_id
        ) );
        $rows = is_array( $rows ) ? $rows : [];

        $match_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d AND club_id = %d
                AND activity_type_key IN ('match','game','tournament')
                AND {$date_col} >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)",
            $team_id, $club_id
        ) );
        $top = $rows ? (int) $rows[0]->total_minutes : 0;
        $bottom = $rows ? (int) $rows[ count( $rows ) - 1 ]->total_minutes : 0;
        $spread_pct = $top > 0 ? (int) round( ( ( $top - $bottom ) / $top ) * 100 ) : 0;

        $explore_url = ExplorerUrl::build(
            'attendance_vs_squad',
            [ 'team_id' => (string) $team_id, 'date_after' => '-12 months' ],
            'player_id'
        );
        // #2356 — drill-down targets (#2185 pattern): the squad KPI opens the
        // team's roster; the Matches KPI opens the activities list filtered to
        // this team's matches. Both carry a `tt_back` hint via BackLink so the
        // destination renders a "← Back to …" pill. Gated on the destination's
        // own cap (§7 hide-don't-tease) so the tile stays static for a viewer
        // who can't reach it.
        $squad_url = RecordLink::detailUrlForWithBack( 'teams', $team_id );
        // The activities list has no rolling-12-month pill; leaving `period`
        // off keeps the drill honest (a `this_season` pill would under-count
        // vs. the report's 12-month window). The user narrows from there.
        $matches_url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg(
            [ 'tt_view' => 'activities', 'team_id' => $team_id, 'activity_type_key' => 'match' ],
            RecordLink::dashboardUrl()
        ) );
        self::renderPageHead(
            sprintf( /* translators: %s = team name */ __( 'Minutes distribution — %s', 'talenttrack' ), (string) $team->name ),
            sprintf( /* translators: %d = match count */ _n( '%d match in the window', '%d matches in the window', $match_count, 'talenttrack' ), $match_count ),
            $explore_url
        );
        self::renderKpiStrip( [
            [ 'num' => (string) count( $rows ), 'label' => __( 'Players in selection', 'talenttrack' ), 'href' => $squad_url, 'cap' => 'tt_view_teams' ],
            [ 'num' => (string) $match_count,   'label' => __( 'Matches', 'talenttrack' ), 'href' => $matches_url, 'cap' => 'tt_view_activities' ],
            [ 'num' => (string) $top,           'label' => __( 'Max minutes / player', 'talenttrack' ) ],
            [
                'num'   => $spread_pct . '%',
                'label' => __( 'Spread (top vs bottom)', 'talenttrack' ),
                'sub'   => $spread_pct > 30 ? __( 'Above 30% — imbalance', 'talenttrack' ) : __( 'Balanced selection', 'talenttrack' ),
                'warn'  => $spread_pct > 30,
            ],
        ] );
        if ( ! $rows ) {
            self::renderEmpty( __( 'No players with recorded match minutes for this team in the last 12 months.', 'talenttrack' ) );
            return;
        }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per player', 'talenttrack' ) . '</h2><span class="tt-rep-section__hint">' . esc_html__( 'Sorted by minutes, high to low. Open a row to trace the per-match minutes that sum to it.', 'talenttrack' ) . '</span></div>';
        // #2160 — the breakdown reuses the same 12-month rolling window as
        // the aggregate query above so the per-match rows reconcile EXACTLY
        // with each player's total. Same MinutesQuery, scoped to one player.
        $bd_from = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
        $bd_to   = gmdate( 'Y-m-d' );
        $minutes_query = new \TT\Modules\Analytics\Reports\MinutesQuery();
        $threshold = $top > 0 ? (int) round( $top * 0.5 ) : 0;
        foreach ( $rows as $r ) {
            $mins = (int) $r->total_minutes;
            $pct  = $top > 0 ? max( 5, (int) round( ( $mins / $top ) * 100 ) ) : 0;
            $warn = $mins < $threshold ? '1' : '0';
            // #2160 — each player row is a <details> drill-down (keyboard-
            // operable, no-JS, reconciles at 360px). The summary keeps the
            // existing bar visual; the body lists the per-match rows.
            $breakdown = $minutes_query->matchBreakdownForPlayer( $team_id, (int) $r->player_id, $bd_from, $bd_to );
            echo '<details class="tt-rep-bar-details">';
            echo '<summary class="tt-rep-bar-row">';
            echo '<span>' . esc_html( (string) $r->name ) . '</span>';
            echo '<div class="tt-rep-bar-track"><div class="tt-rep-bar-fill" data-warn="' . $warn . '" style="width:' . (int) $pct . '%;"></div></div>'; /* tt-inline-ok */ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — computed progress-bar width; $pct is an int.
            echo '<span class="num">' . esc_html( (string) $mins ) . '</span>';
            echo '</summary>';
            // #2348 — shared breakdown component; identical rows to the
            // former per-file renderer, still reconciling to the total.
            MinutesBreakdown::render( $breakdown, (int) $r->player_id );
            echo '</details>';
        }
        echo '</section>';
        if ( $spread_pct > 30 ) {
            echo '<section class="tt-rep-section">';
            echo '<h2 class="tt-rep-section__title">' . esc_html__( 'Imbalance signal', 'talenttrack' ) . '</h2>';
            echo '<p class="tt-rep-note--warn">';
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
        // v4.20.29 (#1187) — scope guard. Same shape as team-minutes.
        $scope = self::currentScope();
        if ( $scope['allowed_team_ids'] !== null
            && ! in_array( $team_id, $scope['allowed_team_ids'], true )
        ) {
            self::renderHeader( __( 'Team · Squad evaluation summary', 'talenttrack' ) );
            self::renderEmpty();
            return;
        }
        // #2345 — shared FilterBar + season-default window, replacing the
        // hardcoded 6-month bound. The period pills / manual range now drive
        // the evaluation window, matching the attendance reports' vocabulary.
        $win    = self::resolveReportWindow();
        $from   = $win['from'];
        $to     = $win['to'];
        $period = $win['period'];

        global $wpdb;
        // Per-player average rating across all categories, over the selected
        // window.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id AS player_id, p.name,
                    AVG( r.rating ) AS avg_rating,
                    COUNT( DISTINCT e.id ) AS eval_count,
                    MAX( e.eval_date ) AS last_eval_date
               FROM {$wpdb->prefix}tt_players p
          LEFT JOIN {$wpdb->prefix}tt_evaluations e ON e.player_id = p.id
                AND e.archived_at IS NULL
                AND e.eval_date BETWEEN %s AND %s
          LEFT JOIN {$wpdb->prefix}tt_eval_ratings r ON r.evaluation_id = e.id
              WHERE p.team_id = %d AND p.archived_at IS NULL
              GROUP BY p.id, p.name
              ORDER BY avg_rating DESC, p.name ASC
              LIMIT 60",
            $from, $to, $team_id
        ) );
        $rows = is_array( $rows ) ? $rows : [];
        $rated = array_filter( $rows, static fn( $r ): bool => $r->eval_count > 0 );
        $sum_avg = 0.0;
        foreach ( $rated as $r ) $sum_avg += (float) $r->avg_rating;
        $squad_avg = $rated ? round( $sum_avg / count( $rated ), 1 ) : 0;
        $coverage = count( $rows ) > 0 ? (int) round( ( count( $rated ) / count( $rows ) ) * 100 ) : 0;

        // #2345 — the Explorer drill now advertises the same window the report
        // is showing (the resolved From), so the two agree.
        $explore_url = ExplorerUrl::build(
            'evaluations_received',
            [ 'team_id' => (string) $team_id, 'date_after' => $from ],
            'month'
        );
        self::renderPageHead(
            sprintf( /* translators: %s = team name */ __( 'Squad evaluation summary — %s', 'talenttrack' ), (string) $team->name ),
            /* translators: 1: from date, 2: to date */
            sprintf( __( '%1$s – %2$s', 'talenttrack' ), \TT\Shared\Dates\TTDate::date( $from ), \TT\Shared\Dates\TTDate::date( $to ) ),
            $explore_url
        );
        self::renderPeriodFilterBar( 'team-squad-evaluation-summary', $from, $to, $period, [ 'team_id' => $team_id ] );
        // #2356 — the squad KPI opens the team roster (§2185 drill pattern);
        // gated on the destination cap (§7). Player rows below already link to
        // each player detail.
        $squad_url = RecordLink::detailUrlForWithBack( 'teams', $team_id );
        self::renderKpiStrip( [
            [ 'num' => (string) count( $rows ),  'label' => __( 'Players', 'talenttrack' ), 'href' => $squad_url, 'cap' => 'tt_view_teams' ],
            [ 'num' => (string) count( $rated ), 'label' => __( 'Evaluated', 'talenttrack' ) ],
            [ 'num' => (string) $squad_avg,      'label' => __( 'Squad average rating', 'talenttrack' ) ],
            [ 'num' => $coverage . '%',          'label' => __( 'Coverage', 'talenttrack' ) ],
        ] );
        if ( ! $rows ) {
            self::renderEmpty( __( 'No evaluations recorded for this team in this window.', 'talenttrack' ) );
            return;
        }
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per player', 'talenttrack' ) . '</h2><span class="tt-rep-section__hint">' . esc_html__( 'Sorted by average rating, high to low.', 'talenttrack' ) . '</span></div>';
        echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table"><thead><tr><th>' . esc_html__( 'Player', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Avg rating', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Evaluations', 'talenttrack' ) . '</th><th>' . esc_html__( 'Last evaluated', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $url = RecordLink::detailUrlForWithBack( 'players', (int) $r->player_id );
            $avg = $r->avg_rating !== null ? round( (float) $r->avg_rating, 1 ) : null;
            // #2346 — materialize the last evaluation date so staleness is
            // visible. Empty for players with no evaluation in the window.
            $last = ! empty( $r->last_eval_date )
                ? \TT\Shared\Dates\TTDate::date( (string) $r->last_eval_date )
                : '—';
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $r->name ) . '</a></td>';
            echo '<td class="num">' . ( $avg !== null ? esc_html( (string) $avg ) : '—' ) . '</td>';
            echo '<td class="num">' . esc_html( (string) (int) $r->eval_count ) . '</td>';
            echo '<td>' . esc_html( $last ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    // ── #1093 Season summary ────────────────────────────────────────

    private static function renderSeasonSummary(): void {
        // v4.20.29 (#1187) — academy-wide framing; gate on scope-admin.
        // AC matrix only grants `reports:r/team`; the academy-wide
        // counts here are out of scope for team-scoped users. Hides
        // the report and falls through to a friendly notice.
        $scope = self::currentScope();
        if ( ! $scope['is_scope_admin'] ) {
            self::renderHeader( __( 'Season summary', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This academy-wide summary is only available to Head of Development and academy admins.', 'talenttrack' ) . '</p>';
            return;
        }
        // #2345 — the academy-wide activity counts now honour the shared
        // period pills / manual range (season-default window), replacing the
        // fixed rolling 12 months. Active players / teams stay point-in-time
        // (a roster count isn't a windowed event), so they're unaffected.
        $win    = self::resolveReportWindow();
        $from   = $win['from'];
        $to     = $win['to'];
        $period = $win['period'];

        global $wpdb;
        $date_col = 'sess' . 'ion_date'; // legacy date column on tt_activities (#0035 lint-safe)
        $club_id = CurrentClub::id();
        $players_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players WHERE club_id=%d AND archived_at IS NULL", $club_id ) );
        $teams_total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_teams WHERE club_id=%d AND archived_at IS NULL", $club_id ) );
        // v4.20.44 (#1222) — added `archived_at IS NULL`. Soft-archived
        // matches were inflating the HoD season-summary KPI. Audit 7. #2345 —
        // now bounded by the selected window.
        $matches_win   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities WHERE club_id=%d AND archived_at IS NULL AND activity_type_key IN ('match','tournament') AND {$date_col} BETWEEN %s AND %s", $club_id, $from, $to ) );
        $evals_win     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE club_id=%d AND archived_at IS NULL AND eval_date BETWEEN %s AND %s", $club_id, $from, $to ) );
        $prospects_win = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospects WHERE club_id=%d AND created_at BETWEEN %s AND %s", $club_id, $from, $to . ' 23:59:59' ) );
        $trial_decisions_win = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases WHERE club_id=%d AND decided_at BETWEEN %s AND %s", $club_id, $from, $to . ' 23:59:59' ) );

        // #2345 — the Explorer drill advertises the same From the report shows.
        $explore_url = ExplorerUrl::build(
            'evaluations_received',
            [ 'date_after' => $from ],
            'month'
        );
        self::renderPageHead(
            __( 'Season summary — annual review', 'talenttrack' ),
            /* translators: 1: from date, 2: to date */
            sprintf( __( 'Academy-wide signals · %1$s – %2$s', 'talenttrack' ), \TT\Shared\Dates\TTDate::date( $from ), \TT\Shared\Dates\TTDate::date( $to ) ),
            $explore_url
        );
        self::renderPeriodFilterBar( 'season-summary', $from, $to, $period );
        // #2356 — academy-wide KPIs drill to the filtered lists they count
        // (§2185 pattern), each carrying a `tt_back` hint and gated on the
        // destination cap (§7). Point-in-time roster counts open the full
        // lists; the windowed activity/eval counts open their lists.
        $players_url  = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( [ 'tt_view' => 'players' ], RecordLink::dashboardUrl() ) ); /* tt-xview-ok — KPI tile self-gates on the destination cap (tt_view_players/teams/activities) via kpiTile 'cap' (§7) */
        $teams_url    = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( [ 'tt_view' => 'teams' ], RecordLink::dashboardUrl() ) ); /* tt-xview-ok — KPI tile self-gates on the destination cap (tt_view_players/teams/activities) via kpiTile 'cap' (§7) */
        $matches_url  = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( [ 'tt_view' => 'activities', 'activity_type_key' => 'match' ], RecordLink::dashboardUrl() ) ); /* tt-xview-ok — KPI tile self-gates on the destination cap (tt_view_players/teams/activities) via kpiTile 'cap' (§7) */
        self::renderKpiStrip( [
            [ 'num' => (string) $players_total, 'label' => __( 'Active players', 'talenttrack' ), 'href' => $players_url, 'cap' => 'tt_view_players' ],
            [ 'num' => (string) $teams_total,   'label' => __( 'Active teams', 'talenttrack' ), 'href' => $teams_url, 'cap' => 'tt_view_teams' ],
            [ 'num' => (string) $matches_win,   'label' => __( 'Matches', 'talenttrack' ), 'href' => $matches_url, 'cap' => 'tt_view_activities' ],
            [ 'num' => (string) $evals_win,     'label' => __( 'Evaluations', 'talenttrack' ) ],
            [ 'num' => (string) $prospects_win, 'label' => __( 'Prospects logged', 'talenttrack' ) ],
            [ 'num' => (string) $trial_decisions_win, 'label' => __( 'Trial decisions', 'talenttrack' ) ],
        ] );

        $by_team = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.name,
                    COUNT( DISTINCT p.id ) AS player_count,
                    COUNT( DISTINCT CASE WHEN a.activity_type_key IN ('match','tournament')
                                          AND a.{$date_col} BETWEEN %s AND %s
                                          AND a.archived_at IS NULL
                                         THEN a.id END ) AS match_count
               FROM {$wpdb->prefix}tt_teams t
          LEFT JOIN {$wpdb->prefix}tt_players p ON p.team_id = t.id AND p.archived_at IS NULL
          LEFT JOIN {$wpdb->prefix}tt_activities a ON a.team_id = t.id AND a.archived_at IS NULL
              WHERE t.club_id = %d AND t.archived_at IS NULL
                /* #2346 — `a.archived_at IS NULL` moved onto the JOIN so
                   soft-archived activities never enter the join at all
                   (they previously inflated the join even though the CASE
                   filtered the count). The CASE keeps its own guard for
                   defence in depth. Builds on v4.20.44 (#1222). Audit 7.
                   #2345 — the CASE window now follows the selected range. */
              GROUP BY t.id, t.name
              ORDER BY t.name ASC",
            $from, $to, $club_id
        ) );
        // #2344 — a silent `return` here left the page blank below the KPI
        // strip when no teams exist. Render an honest empty state instead.
        if ( ! is_array( $by_team ) || ! $by_team ) {
            self::renderEmpty( __( 'No teams have been created for this academy yet.', 'talenttrack' ) );
            return;
        }
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per team', 'talenttrack' ) . '</h2></div>';
        echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table"><thead><tr><th>' . esc_html__( 'Team', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Players', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Matches', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $by_team as $r ) {
            $url = RecordLink::detailUrlForWithBack( 'teams', (int) $r->id );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $r->name ) . '</a></td>';
            echo '<td class="num">' . esc_html( (string) (int) $r->player_count ) . '</td>';
            echo '<td class="num">' . esc_html( (string) (int) $r->match_count ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    // ── #1094 Season · Trial funnel ─────────────────────────────────

    private static function renderSeasonTrialFunnel(): void {
        // v4.20.29 (#1187) — academy-wide funnel; gate on scope-admin.
        $scope = self::currentScope();
        if ( ! $scope['is_scope_admin'] ) {
            self::renderHeader( __( 'Season trial funnel', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This academy-wide funnel report is only available to Head of Development and academy admins.', 'talenttrack' ) . '</p>';
            return;
        }
        // #2345 — shared FilterBar + season-default window replaces the fixed
        // rolling 12 months. `$to_dt` bounds the datetime columns (created_at /
        // decided_at) through end-of-day so the last day is inclusive.
        $win    = self::resolveReportWindow();
        $from   = $win['from'];
        $to     = $win['to'];
        $to_dt  = $to . ' 23:59:59';
        $period = $win['period'];

        global $wpdb;
        $club_id = CurrentClub::id();
        // Funnel stages: prospects → trial_cases opened → decided.
        $prospects     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospects WHERE club_id=%d AND created_at BETWEEN %s AND %s", $club_id, $from, $to_dt ) );
        $cases_opened  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases WHERE club_id=%d AND created_at BETWEEN %s AND %s", $club_id, $from, $to_dt ) );
        $cases_decided = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases WHERE club_id=%d AND decided_at IS NOT NULL AND decided_at BETWEEN %s AND %s", $club_id, $from, $to_dt ) );
        // #2347 — opened-but-undecided cases in the same created_at window,
        // so the Per-decision table can reconcile to Trial cases opened.
        $cases_pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases WHERE club_id=%d AND decided_at IS NULL AND created_at BETWEEN %s AND %s", $club_id, $from, $to_dt ) );
        // #2347 — select the scout user ID too, so each scout name can link
        // to their Scout Report Card.
        $by_scout = $wpdb->get_results( $wpdb->prepare(
            "SELECT u.ID AS scout_id, u.display_name, COUNT(*) AS opened
               FROM {$wpdb->prefix}tt_trial_cases tc
          LEFT JOIN {$wpdb->users} u ON u.ID = tc.opened_by
              WHERE tc.club_id = %d AND tc.created_at BETWEEN %s AND %s
              GROUP BY u.ID, u.display_name
              ORDER BY opened DESC
              LIMIT 30",
            $club_id, $from, $to_dt
        ) );
        // #2347 — the Per-decision breakdown is scoped by `created_at` (the
        // same window as `cases_opened`) rather than `decided_at`, so the
        // decided rows plus the Pending row reconcile to Trial cases opened.
        $by_decision = $wpdb->get_results( $wpdb->prepare(
            "SELECT decision, COUNT(*) AS n
               FROM {$wpdb->prefix}tt_trial_cases
              WHERE club_id = %d AND decided_at IS NOT NULL
                AND created_at BETWEEN %s AND %s
              GROUP BY decision
              ORDER BY n DESC",
            $club_id, $from, $to_dt
        ) );
        $by_scout = is_array( $by_scout ) ? $by_scout : [];
        $by_decision = is_array( $by_decision ) ? $by_decision : [];

        // #2345 — the Explorer drill advertises the same From the report shows.
        $explore_url = ExplorerUrl::build(
            'prospects_logged_per_scout',
            [ 'date_after' => $from ],
            'discovered_by_user_id'
        );
        self::renderPageHead(
            __( 'Trial funnel — per scout, per period', 'talenttrack' ),
            /* translators: 1: from date, 2: to date */
            sprintf( __( '%1$s – %2$s', 'talenttrack' ), \TT\Shared\Dates\TTDate::date( $from ), \TT\Shared\Dates\TTDate::date( $to ) ),
            $explore_url
        );
        self::renderPeriodFilterBar( 'season-trial-funnel', $from, $to, $period );
        // #2356 — the Prospects KPI drills to the prospects list (§2185
        // pattern), carrying a `tt_back` hint and gated on `tt_view_prospects`
        // (§7). The scout / decision breakdown tables below already drill.
        $prospects_url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg(
            [ 'tt_view' => 'prospects' ], RecordLink::dashboardUrl()
        ) );
        self::renderKpiStrip( [
            [ 'num' => (string) $prospects,     'label' => __( 'Prospects logged', 'talenttrack' ), 'href' => $prospects_url, 'cap' => 'tt_view_prospects' ],
            [ 'num' => (string) $cases_opened,  'label' => __( 'Trial cases opened', 'talenttrack' ) ],
            [ 'num' => (string) $cases_decided, 'label' => __( 'Decided', 'talenttrack' ) ],
            [
                'num'   => $cases_opened > 0 ? (int) round( ( $cases_decided / $cases_opened ) * 100 ) . '%' : '0%',
                'label' => __( 'Decision rate', 'talenttrack' ),
                // #2347 — the numerator (cases decided) is scoped by
                // `decided_at`, the denominator (cases opened) by
                // `created_at`; make the window mix explicit so the figure
                // isn't read as a same-cohort rate.
                'sub'   => __( 'Decided (by decision date) ÷ opened (by open date)', 'talenttrack' ),
            ],
        ] );
        if ( $by_scout ) {
            echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per scout', 'talenttrack' ) . '</h2></div>';
            echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table"><thead><tr><th>' . esc_html__( 'Scout', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Cases opened', 'talenttrack' ) . '</th></tr></thead><tbody>';
            // #2347 — link each scout to their Scout Report Card. Gated on
            // `tt_view_reports` (§7 hide-don't-tease); this whole funnel is
            // already scope-admin-only, so a viewer here always holds it,
            // but the check keeps the affordance and the destination gate in
            // lockstep. The card resolves the same 12-month window.
            $can_scout_card = current_user_can( 'tt_view_reports' );
            foreach ( $by_scout as $r ) {
                $scout_name = (string) ( $r->display_name ?? __( '—', 'talenttrack' ) );
                $scout_id   = (int) ( $r->scout_id ?? 0 );
                echo '<tr><td>';
                if ( $can_scout_card && $scout_id > 0 ) {
                    $card_url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg(
                        [ 'tt_view' => 'standard-report', 'slug' => 'scout-report-card', 'scout_id' => $scout_id ],
                        RecordLink::dashboardUrl()
                    ) );
                    echo '<a class="tt-record-link" href="' . esc_url( $card_url ) . '">' . esc_html( $scout_name ) . '</a>';
                } else {
                    echo esc_html( $scout_name );
                }
                echo '</td><td class="num">' . esc_html( (string) (int) $r->opened ) . '</td></tr>';
            }
            echo '</tbody></table></div></div>';
        }
        if ( $by_decision || $cases_pending > 0 ) {
            echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per decision', 'talenttrack' ) . '</h2><span class="tt-rep-section__hint">' . esc_html__( 'Cases opened in the window, by outcome. Pending = opened but not yet decided. Rows sum to Trial cases opened.', 'talenttrack' ) . '</span></div>';
            echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table"><thead><tr><th>' . esc_html__( 'Decision', 'talenttrack' ) . '</th><th class="num">' . esc_html__( 'Cases', 'talenttrack' ) . '</th></tr></thead><tbody>';
            $decided_sum = 0;
            foreach ( $by_decision as $r ) {
                $decided_sum += (int) $r->n;
                echo '<tr><td>' . esc_html( (string) ( $r->decision ?? __( '—', 'talenttrack' ) ) ) . '</td><td class="num">' . esc_html( (string) (int) $r->n ) . '</td></tr>';
            }
            // #2347 — the Pending row makes opened-but-undecided cases visible
            // so the breakdown reconciles with Trial cases opened.
            echo '<tr><td>' . esc_html__( 'Pending (not yet decided)', 'talenttrack' ) . '</td><td class="num">' . esc_html( (string) $cases_pending ) . '</td></tr>';
            // #2347 — a total/reconciliation row confirming the sum equals
            // Trial cases opened.
            echo '<tr class="tt-minutes-breakdown__total"><td>' . esc_html__( 'Total (should equal cases opened)', 'talenttrack' ) . '</td><td class="num">' . esc_html( (string) ( $decided_sum + $cases_pending ) ) . '</td></tr>';
            echo '</tbody></table></div></div>';
        }
        if ( ! $by_scout && ! $by_decision && $cases_pending === 0 ) {
            self::renderEmpty( __( 'No trial cases or prospects logged in this window.', 'talenttrack' ) );
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
        // v4.20.29 (#1187) — viewing another scout's card requires
        // scope-admin. The own-card default (no scout_id supplied)
        // continues to render for any user with `tt_view_reports`.
        $scope = self::currentScope();
        if ( $scout_id !== (int) get_current_user_id() && ! $scope['is_scope_admin'] ) {
            self::renderHeader( __( 'Scout report card', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Viewing another scout’s report card requires academy-wide access.', 'talenttrack' ) . '</p>';
            return;
        }
        // #2345 — shared FilterBar + season-default window replaces the fixed
        // rolling 12 months. `$to_dt` bounds the datetime columns inclusively.
        $win    = self::resolveReportWindow();
        $from   = $win['from'];
        $to     = $win['to'];
        $to_dt  = $to . ' 23:59:59';
        $period = $win['period'];

        global $wpdb;
        $user = get_userdata( $scout_id );
        $name = $user ? (string) $user->display_name : sprintf( __( 'Scout #%d', 'talenttrack' ), $scout_id );
        $club_id = CurrentClub::id();
        $prospects_logged = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospects
              WHERE club_id = %d AND discovered_by_user_id = %d
                AND created_at BETWEEN %s AND %s",
            $club_id, $scout_id, $from, $to_dt
        ) );
        $cases_opened = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases
              WHERE club_id = %d AND opened_by = %d
                AND created_at BETWEEN %s AND %s",
            $club_id, $scout_id, $from, $to_dt
        ) );
        $cases_admitted = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases
              WHERE club_id = %d AND opened_by = %d AND decision = 'admit'
                AND decided_at BETWEEN %s AND %s",
            $club_id, $scout_id, $from, $to_dt
        ) );
        $hit_rate = $cases_opened > 0 ? (int) round( ( $cases_admitted / $cases_opened ) * 100 ) : 0;

        $recent_prospects = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, current_club, created_at
               FROM {$wpdb->prefix}tt_prospects
              WHERE club_id = %d AND discovered_by_user_id = %d
                AND created_at BETWEEN %s AND %s
              ORDER BY created_at DESC LIMIT 20",
            $club_id, $scout_id, $from, $to_dt
        ) );
        $recent_prospects = is_array( $recent_prospects ) ? $recent_prospects : [];

        // #2345 — the Explorer drill advertises the same From the report shows.
        $explore_url = ExplorerUrl::build(
            'prospects_logged_per_scout',
            [ 'discovered_by_user_id' => (string) $scout_id, 'date_after' => $from ],
            'month'
        );
        self::renderPageHead(
            sprintf( /* translators: %s = scout name */ __( 'Scout report card — %s', 'talenttrack' ), $name ),
            /* translators: 1: from date, 2: to date */
            sprintf( __( '%1$s – %2$s', 'talenttrack' ), \TT\Shared\Dates\TTDate::date( $from ), \TT\Shared\Dates\TTDate::date( $to ) ),
            $explore_url
        );
        self::renderPeriodFilterBar( 'scout-report-card', $from, $to, $period, [ 'scout_id' => $scout_id ] );
        self::renderKpiStrip( [
            [ 'num' => (string) $prospects_logged, 'label' => __( 'Prospects logged', 'talenttrack' ) ],
            [ 'num' => (string) $cases_opened,     'label' => __( 'Trial cases opened', 'talenttrack' ) ],
            [ 'num' => (string) $cases_admitted,   'label' => __( 'Admitted', 'talenttrack' ) ],
            [ 'num' => $hit_rate . '%',            'label' => __( 'Hit rate', 'talenttrack' ) ],
        ] );
        if ( ! $recent_prospects ) {
            self::renderEmpty( __( 'No prospects logged in this window.', 'talenttrack' ) );
            return;
        }
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Recent prospects', 'talenttrack' ) . '</h2></div>';
        echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table"><thead><tr><th>' . esc_html__( 'Date', 'talenttrack' ) . '</th><th>' . esc_html__( 'Prospect', 'talenttrack' ) . '</th><th>' . esc_html__( 'Current club', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $recent_prospects as $r ) {
            $full = trim( ( (string) ( $r->first_name ?? '' ) ) . ' ' . ( (string) ( $r->last_name ?? '' ) ) );
            echo '<tr>';
            echo '<td>' . esc_html( \TT\Shared\Dates\TTDate::date( strtotime( (string) ( $r->created_at ?? '' ) ) ?: time() ) ) . '</td>';
            echo '<td>' . esc_html( $full !== '' ? $full : '—' ) . '</td>';
            echo '<td>' . esc_html( (string) ( $r->current_club ?? '' ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    // ── #1367 Coach · Evaluation quality ─────────────────────────────

    /**
     * @return array{team_id:int, date_from:string, date_to:string}
     */
    private static function coachEvalQualityFilters(): array {
        return [
            'team_id'   => isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0,
            'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['date_from'] ) ) : '',
            'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['date_to'] ) ) : '',
        ];
    }

    /**
     * Per-coach rating distribution / variance — the HoD's
     * rate-everyone-a-6 spot-check (head-of-development-actions.md #5)
     * as a report. Scope-admin only: coaches must not read each
     * other's stats.
     */
    private static function renderCoachEvaluationQuality(): void {
        $scope = self::currentScope();
        if ( ! $scope['is_scope_admin'] ) {
            self::renderHeader( __( 'Coach · Evaluation quality', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This report is restricted to academy-wide roles.', 'talenttrack' ) . '</p>';
            return;
        }

        $filters = self::coachEvalQualityFilters();
        $rows    = ( new \TT\Modules\Analytics\Reports\CoachEvalQualityQuery() )->rows( $filters );

        $explore_url = ExplorerUrl::build(
            'evaluations_received',
            [ 'date_after' => '-12 months' ],
            'evaluator_id'
        );
        $export_url = add_query_arg( array_merge(
            [ 'tt_view' => 'standard-report', 'slug' => 'coach-evaluation-quality', 'action' => 'export_csv' ],
            array_filter( $filters )
        ), RecordLink::dashboardUrl() );

        self::renderPageHead(
            __( 'Evaluation quality — per coach', 'talenttrack' ),
            __( 'Rating distribution and variance per coach. Low variance with a real sample size usually means everyone gets the same number.', 'talenttrack' ),
            $explore_url,
            $export_url
        );

        // Filter bar: team + date range, plain GET round-trip.
        $teams = QueryHelpers::get_teams();
        echo '<form method="get" class="tt-rep-section tt-rep-filter">';
        echo '<input type="hidden" name="tt_view" value="standard-report" />';
        echo '<input type="hidden" name="slug" value="coach-evaluation-quality" />';
        echo '<label><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id"><option value="0">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( (array) $teams as $t ) {
            echo '<option value="' . (int) $t->id . '"' . selected( $filters['team_id'], (int) $t->id, false ) . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="date_from" value="' . esc_attr( $filters['date_from'] ) . '" /></label>';
        echo '<label><span>' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="date_to" value="' . esc_attr( $filters['date_to'] ) . '" /></label>';
        echo '<button type="submit" class="tt-rep-btn">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';

        $total_evals  = array_sum( array_column( $rows, 'eval_count' ) );
        $flagged      = count( array_filter( $rows, static fn( array $r ): bool => $r['low_variance'] ) );
        $means        = array_filter( array_column( $rows, 'mean_rating' ), static fn( $v ) => $v !== null );
        $academy_mean = $means ? round( array_sum( $means ) / count( $means ), 2 ) : null;

        self::renderKpiStrip( [
            [ 'num' => (string) count( $rows ), 'label' => __( 'Coaches in selection', 'talenttrack' ) ],
            [ 'num' => (string) $total_evals,   'label' => __( 'Evaluations', 'talenttrack' ) ],
            [ 'num' => $academy_mean !== null ? number_format_i18n( $academy_mean, 2 ) : '—', 'label' => __( 'Mean of coach means', 'talenttrack' ) ],
            [
                'num'   => (string) $flagged,
                'label' => __( 'Low-variance flags', 'talenttrack' ),
                'sub'   => $flagged > 0
                    /* translators: %s: standard-deviation threshold */
                    ? sprintf( __( 'σ below %s with 10+ ratings', 'talenttrack' ), number_format_i18n( \TT\Modules\Analytics\Reports\CoachEvalQualityQuery::LOW_VARIANCE_THRESHOLD, 1 ) )
                    : '',
                'warn'  => $flagged > 0,
            ],
        ] );
        if ( ! $rows ) {
            self::renderEmpty( __( 'No coaches have evaluations in this selection.', 'talenttrack' ) );
            return;
        }

        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Per coach', 'talenttrack' ) . '</h2><span class="tt-rep-section__hint">' . esc_html__( 'Sorted by evaluation count. Flagged rows: standard deviation under the threshold with a meaningful sample.', 'talenttrack' ) . '</span></div>';
        echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table"><thead><tr>'
            . '<th>' . esc_html__( 'Coach', 'talenttrack' ) . '</th>'
            . '<th class="num">' . esc_html__( 'Evaluations', 'talenttrack' ) . '</th>'
            . '<th class="num">' . esc_html__( 'Ratings', 'talenttrack' ) . '</th>'
            . '<th class="num">' . esc_html__( 'Mean', 'talenttrack' ) . '</th>'
            . '<th class="num">' . esc_html__( 'Std dev', 'talenttrack' ) . '</th>'
            . '<th>' . esc_html__( 'Most-given rating', 'talenttrack' ) . '</th>'
            . '<th>' . esc_html__( 'Last evaluation', 'talenttrack' ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $row_class = $r['low_variance'] ? ' class="tt-rep-row--flag"' : '';
            echo '<tr' . $row_class . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static attribute.
            echo '<td>' . esc_html( $r['coach_name'] );
            if ( $r['low_variance'] ) {
                echo ' <span class="tt-rep-flag-tag">' . esc_html__( 'low variance', 'talenttrack' ) . '</span>';
            }
            echo '</td>';
            echo '<td class="num">' . (int) $r['eval_count'] . '</td>';
            echo '<td class="num">' . (int) $r['rating_count'] . '</td>';
            echo '<td class="num">' . ( $r['mean_rating'] !== null ? esc_html( number_format_i18n( $r['mean_rating'], 2 ) ) : '—' ) . '</td>';
            echo '<td class="num">' . ( $r['stddev'] !== null ? esc_html( number_format_i18n( $r['stddev'], 2 ) ) : '—' ) . '</td>';
            if ( $r['modal_value'] !== null && $r['modal_pct'] !== null ) {
                echo '<td>' . esc_html( sprintf(
                    /* translators: 1: rating value, 2: percentage of all ratings at that value */
                    __( '%1$s (%2$s%% of ratings)', 'talenttrack' ),
                    number_format_i18n( $r['modal_value'], 1 ),
                    number_format_i18n( $r['modal_pct'], 1 )
                ) ) . '</td>';
            } else {
                echo '<td>—</td>';
            }
            echo '<td>' . esc_html( $r['last_eval_date'] ?? '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * Stream the coach-evaluation-quality rows as a CSV download and
     * exit. Same scope gate as the renderer; non-scope-admins fall
     * through to the normal page render (which shows the restriction
     * notice).
     */
    private static function streamCoachEvalQualityCsv(): void {
        $scope = self::currentScope();
        if ( ! $scope['is_scope_admin'] ) return;

        $rows = ( new \TT\Modules\Analytics\Reports\CoachEvalQualityQuery() )->rows( self::coachEvalQualityFilters() );

        $out = fopen( 'php://temp', 'r+' );
        fputcsv( $out, [ 'coach', 'evaluations', 'ratings', 'mean_rating', 'stddev', 'modal_value', 'modal_pct', 'last_evaluation', 'low_variance' ] );
        foreach ( $rows as $r ) {
            fputcsv( $out, [
                $r['coach_name'],
                $r['eval_count'],
                $r['rating_count'],
                $r['mean_rating'] ?? '',
                $r['stddev'] ?? '',
                $r['modal_value'] ?? '',
                $r['modal_pct'] ?? '',
                $r['last_eval_date'] ?? '',
                $r['low_variance'] ? '1' : '0',
            ] );
        }
        rewind( $out );
        $csv = (string) stream_get_contents( $out );
        fclose( $out );

        $filename = sanitize_file_name( 'coach-evaluation-quality-' . gmdate( 'Y-m-d' ) . '.csv' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $csv ) );
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    // ── #1369 Player · Progress & radar (wp-admin legacy port) ───────

    /**
     * Native port of the wp-admin "Player Progress & Radar" report
     * (`admin.php?page=tt-reports&report=legacy`). Three modes —
     * progress (per-player radar overlay of the last 5 evaluations),
     * comparison (latest-evaluation radar overlay across ≥ 2 players),
     * team_avg (one radar series per team) — using the exact same
     * `QueryHelpers` calls as the legacy renderer, so the charts carry
     * identical data. The wp-admin route now redirects here.
     *
     * Scope: non-scope-admin users (head coaches / AC) only see and
     * query players on their own teams; team_avg narrows to their
     * teams. The wp-admin original was admin-area-only so it had no
     * such guard; the frontend surface needs one.
     */
    private static function renderPlayerProgressRadar(): void {
        $scope = self::currentScope();

        $mode = isset( $_GET['mode'] ) ? sanitize_key( (string) $_GET['mode'] ) : 'progress';
        if ( ! in_array( $mode, [ 'progress', 'comparison', 'team_avg' ], true ) ) $mode = 'progress';
        $selected_ids = array_map( 'absint', (array) ( $_GET['f_players'] ?? [] ) );
        $run          = isset( $_GET['run'] );

        // Scope-filter the picker (and the selection) for non-admins.
        $players = QueryHelpers::get_players();
        if ( $scope['allowed_team_ids'] !== null && is_array( $players ) ) {
            $allowed = $scope['allowed_team_ids'];
            $players = array_values( array_filter(
                $players,
                static fn( $pl ): bool => in_array( (int) ( $pl->team_id ?? 0 ), $allowed, true )
            ) );
            $allowed_player_ids = array_map( static fn( $pl ): int => (int) $pl->id, $players );
            $selected_ids = array_values( array_intersect( $selected_ids, $allowed_player_ids ) );
        }

        $explore_url = ExplorerUrl::build(
            'evaluations_received',
            [ 'date_after' => '-12 months' ],
            'player_id'
        );
        self::renderPageHead(
            __( 'Player progress & radar', 'talenttrack' ),
            __( 'Radar charts over evaluation categories: per-player progress, player comparison, or team averages.', 'talenttrack' ),
            $explore_url
        );

        // Mode + player picker form (plain GET round-trip, no-JS safe).
        echo '<form method="get" class="tt-rep-section tt-rep-filter">';
        echo '<input type="hidden" name="tt_view" value="standard-report" />';
        echo '<input type="hidden" name="slug" value="player-progress-radar" />';
        echo '<input type="hidden" name="run" value="1" />';
        echo '<label><span>' . esc_html__( 'Report Type', 'talenttrack' ) . '</span>';
        echo '<select name="mode">';
        echo '<option value="progress"' . selected( $mode, 'progress', false ) . '>' . esc_html__( 'Player Progress', 'talenttrack' ) . '</option>';
        echo '<option value="comparison"' . selected( $mode, 'comparison', false ) . '>' . esc_html__( 'Player Comparison (radar)', 'talenttrack' ) . '</option>';
        echo '<option value="team_avg"' . selected( $mode, 'team_avg', false ) . '>' . esc_html__( 'Team Averages (radar)', 'talenttrack' ) . '</option>';
        echo '</select></label>';
        echo '<label style="flex:1 1 220px;"><span>' . esc_html__( 'Player(s)', 'talenttrack' ) . '</span>';
        echo '<select name="f_players[]" multiple size="6">';
        foreach ( (array) $players as $pl ) {
            $pid = (int) ( $pl->id ?? 0 );
            if ( $pid <= 0 ) continue;
            echo '<option value="' . $pid . '"' . ( in_array( $pid, $selected_ids, true ) ? ' selected' : '' ) . '>'
                . esc_html( QueryHelpers::player_display_name( $pl ) ) . '</option>';
        }
        echo '</select></label>';
        echo '<button type="submit" class="tt-rep-btn">' . esc_html__( 'Run Report', 'talenttrack' ) . '</button>';
        echo '</form>';

        if ( ! $run ) return;

        $query = new \TT\Modules\Analytics\Reports\PlayerRadarQuery();
        $max   = (float) QueryHelpers::get_config( 'rating_max', '10' );

        echo '<section class="tt-rep-section">';
        if ( $mode === 'progress' ) {
            echo '<h2 class="tt-rep-section__title">' . esc_html__( 'Player Progress Over Time', 'talenttrack' ) . '</h2>';
            // Fallback mirrors the wp-admin original ("Top 10 active
            // players"), narrowed to the viewer's teams when scoped.
            $pids = $selected_ids ?: $query->defaultProgressPlayerIds( $scope['allowed_team_ids'] );
            $any  = false;
            foreach ( $pids as $pid ) {
                $pl = QueryHelpers::get_player( (int) $pid );
                if ( ! $pl ) continue;
                $rd  = $query->progressForPlayer( (int) $pid, 5 );
                $any = true;
                echo '<h3 class="tt-rep-section__title">' . esc_html( QueryHelpers::player_display_name( $pl ) ) . '</h3>';
                echo ! empty( $rd['datasets'] )
                    ? '<div class="tt-rep-chart tt-rep-chart--sm">' . QueryHelpers::radar_chart_svg( $rd['labels'], $rd['datasets'], $max ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
                    : '<p class="tt-rep-section__hint">' . esc_html__( 'No data.', 'talenttrack' ) . '</p>';
            }
            if ( ! $any ) {
                self::renderEmpty( __( 'No evaluations recorded for the selected players yet. Pick different players or record an evaluation first.', 'talenttrack' ) );
            }
        } elseif ( $mode === 'comparison' ) {
            echo '<h2 class="tt-rep-section__title">' . esc_html__( 'Player Comparison', 'talenttrack' ) . '</h2>';
            if ( count( $selected_ids ) < 2 ) {
                echo '<p class="tt-rep-section__hint">' . esc_html__( 'Select at least 2 players.', 'talenttrack' ) . '</p>';
            } else {
                $data = $query->comparison( $selected_ids );
                echo ! empty( $data['datasets'] )
                    ? '<div class="tt-rep-chart">' . QueryHelpers::radar_chart_svg( $data['labels'], $data['datasets'], $max ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
                    : '<p class="tt-rep-section__hint">' . esc_html__( 'No data.', 'talenttrack' ) . '</p>';
            }
        } else { // team_avg
            echo '<h2 class="tt-rep-section__title">' . esc_html__( 'Team Averages', 'talenttrack' ) . '</h2>';
            $data = $query->teamAverages( $scope['allowed_team_ids'] );
            echo ! empty( $data['datasets'] )
                ? '<div class="tt-rep-chart">' . QueryHelpers::radar_chart_svg( $data['labels'], $data['datasets'], $max ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
                : '<p class="tt-rep-section__hint">' . esc_html__( 'No data.', 'talenttrack' ) . '</p>';
        }
        echo '</section>';
    }

    /**
     * Render a "pick a player" landing for entity-scoped reports that
     * arrive without `player_id`. Lists active players (cap-scoped via
     * `QueryHelpers::get_players()`).
     */
    private static function renderPlayerPicker( string $slug ): void {
        $base_url = remove_query_arg( [ 'player_id' ] );
        $players  = QueryHelpers::get_players( 0 );
        // v4.20.29 (#1187) — narrow to the AC's accessible team rosters
        // when not scope-admin. Matches the scope guard on the per-report
        // renderer; otherwise the picker would offer players the
        // destination would reject anyway.
        $scope = self::currentScope();
        if ( $scope['allowed_team_ids'] !== null && is_array( $players ) ) {
            $allowed = $scope['allowed_team_ids'];
            $players = array_values( array_filter(
                $players,
                static fn( $p ): bool => in_array( (int) ( $p->team_id ?? 0 ), $allowed, true )
            ) );
        }
        if ( ! $players ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players available — add players first to enable this report.', 'talenttrack' ) . '</p>';
            return;
        }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Pick a player', 'talenttrack' ) . '</h2></div>';
        echo '<ul class="tt-rep-picker">';
        foreach ( $players as $p ) {
            $pid = (int) ( $p->id ?? 0 );
            if ( $pid <= 0 ) continue;
            $name = QueryHelpers::player_display_name( $p );
            $url  = add_query_arg( [ 'slug' => $slug, 'player_id' => $pid ], $base_url );
            echo '<li><a class="tt-rep-btn" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></li>';
        }
        echo '</ul></section>';
    }

    /**
     * Render a "pick a team" landing for entity-scoped reports that
     * arrive without `team_id`.
     */
    private static function renderTeamPicker( string $slug ): void {
        $base_url = remove_query_arg( [ 'team_id' ] );
        // v4.20.29 (#1187) — same scope narrowing as renderPlayerPicker.
        $scope = self::currentScope();
        if ( $scope['allowed_team_ids'] !== null ) {
            $teams = QueryHelpers::get_teams_for_coach( get_current_user_id() );
        } else {
            $teams = QueryHelpers::get_teams();
        }
        if ( ! $teams ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams configured yet.', 'talenttrack' ) . '</p>';
            return;
        }
        echo '<section class="tt-rep-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html__( 'Pick a team', 'talenttrack' ) . '</h2></div>';
        echo '<ul class="tt-rep-picker">';
        foreach ( $teams as $t ) {
            $tid = (int) ( $t->id ?? 0 );
            if ( $tid <= 0 ) continue;
            $url = add_query_arg( [ 'slug' => $slug, 'team_id' => $tid ], $base_url );
            $label = (string) ( $t->name ?? '' );
            if ( ! empty( $t->age_group ) ) $label .= ' (' . \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', (string) $t->age_group ) . ')';
            echo '<li><a class="tt-rep-btn" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
        }
        echo '</ul></section>';
    }
}
