<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendReportsLauncherView — frontend reports launcher (#0063).
 *
 * #0077 M11: team_ratings + coach_activity render on the frontend
 * natively (FrontendReportDetailView) with a Print/Save-as-PDF button.
 *
 * v3.91.5 — the legacy "Player Progress & Radar" tile that deep-linked
 * to wp-admin was removed from the launcher. Operator complaint: a
 * frontend tile must not punt the user to wp-admin. The legacy view
 * still exists at `wp-admin/admin.php?page=tt-reports&report=legacy`
 * for admins who navigate there directly; the frontend just doesn't
 * advertise it anymore. Porting it natively (Chart.js + form-submit
 * round-trip) is tracked separately if the operator asks.
 */
final class FrontendReportsLauncherView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to reports.', 'talenttrack' ) . '</p>';
            return;
        }

        // #0077 M11 — when ?type= is set, delegate to the detail view.
        $type = isset( $_GET['type'] ) ? sanitize_key( (string) wp_unslash( $_GET['type'] ) ) : '';
        $native_types = [ 'team_ratings', 'coach_activity' ];
        if ( in_array( $type, $native_types, true ) ) {
            FrontendReportDetailView::render( $type );
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Reports', 'talenttrack' ) );
        self::renderHeader( __( 'Reports', 'talenttrack' ) );

        // v4.20.29 (#1187) — three academy-wide tiles are hidden for
        // non-scope-admin users (AC etc.). The destination renderers
        // gate too, but suppressing the launcher tile prevents the
        // operator from clicking into an empty-state notice.
        $is_scope_admin = $is_admin || current_user_can( 'tt_view_all_teams' );

        $base_url = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        $tiles = [
            [
                'slug'  => 'team_ratings',
                'label' => __( 'Team rating averages', 'talenttrack' ),
                'desc'  => __( 'Average rating per team across all main categories.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'reports', 'type' => 'team_ratings' ], $base_url ),
            ],
            [
                'slug'  => 'coach_activity',
                'label' => __( 'Coach activity', 'talenttrack' ),
                'desc'  => __( 'Per-coach evaluation count and recent cadence.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'reports', 'type' => 'coach_activity' ], $base_url ),
            ],
            // #1101 — Season prospects-per-scout preset (Explorer →).
            // HoD-scoped season-wide signal; lives on the Reports
            // launcher because it has no per-entity entry point.
            [
                'slug'  => 'prospects_logged_per_scout',
                'label' => __( 'Prospects logged per scout', 'talenttrack' ),
                'desc'  => __( 'Season log of scout-logged prospects, grouped by who logged them.', 'talenttrack' ),
                'url'   => \TT\Modules\Analytics\Domain\ExplorerUrl::build(
                    'prospects_logged_per_scout',
                    [ 'date_after' => '-12 months' ],
                    'discovered_by_user_id'
                ),
            ],
            // #1063 standard-reports curated views (#1090-#1095). Six
            // tiles, each opens the slug-dispatched
            // ?tt_view=standard-report renderer. Entity-scoped reports
            // (player- / team- / scout-) render a picker when no entity
            // id is on the URL.
            [
                'slug'  => 'player-minutes-played',
                'label' => __( 'Player · Minutes played', 'talenttrack' ),
                'desc'  => __( 'Per-player report: KPI strip + per-match timeline of minutes played.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'player-minutes-played' ], $base_url ),
            ],
            [
                'slug'  => 'team-minutes-distribution',
                'label' => __( 'Team · Minutes distribution', 'talenttrack' ),
                'desc'  => __( 'Squad load balance — minutes per player with imbalance flag when spread > 30%.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'team-minutes-distribution' ], $base_url ),
            ],
            // #1592 — attendance reports were only reachable through the
            // flag-gated Analytics surface; surface them here next to the
            // minutes reports. Labels/descriptions reuse the existing
            // FrontendAnalyticsView msgids. The views self-gate on
            // tt_view_analytics + team scope.
            [
                'slug'  => 'attendance-report-team',
                'label' => __( 'Team attendance statistics', 'talenttrack' ),
                'desc'  => __( 'Present / late / absent / excused / injured percentages per team over a configurable date range.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'attendance-report-team' ], $base_url ),
            ],
            [
                'slug'  => 'attendance-report-player',
                'label' => __( 'Player attendance statistics', 'talenttrack' ),
                'desc'  => __( 'Same attendance percentages broken down per player, optionally narrowed to a single team.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'attendance-report-player' ], $base_url ),
            ],
            [
                'slug'  => 'attendance-leaderboard',
                'label' => __( 'Attendance leaderboard', 'talenttrack' ),
                'desc'  => __( 'League-table ranking of the best and worst attenders over a window, with at-risk players flagged.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'attendance-leaderboard' ], $base_url ),
            ],
            [
                'slug'  => 'team-squad-evaluation-summary',
                'label' => __( 'Team · Squad evaluation summary', 'talenttrack' ),
                'desc'  => __( 'Per-player average rating and evaluation count over the last 6 months.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'team-squad-evaluation-summary' ], $base_url ),
            ],
            [
                'slug'  => 'season-summary',
                'label' => __( 'Season summary — annual review', 'talenttrack' ),
                'desc'  => __( 'Academy-wide totals across players, matches, evaluations, prospects, and trial decisions over the last 12 months.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'season-summary' ], $base_url ),
            ],
            [
                'slug'  => 'season-trial-funnel',
                'label' => __( 'Trial funnel', 'talenttrack' ),
                'desc'  => __( 'Prospects → trial cases opened → decided, broken out per scout and per decision.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'season-trial-funnel' ], $base_url ),
            ],
            [
                'slug'  => 'scout-report-card',
                'label' => __( 'Scout report card', 'talenttrack' ),
                'desc'  => __( 'Per-scout dashboard — prospects logged, cases opened, admissions, hit rate.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'scout-report-card' ], $base_url ),
            ],
            // #1367 — HoD coach-quality lens. Academy-wide only (the
            // renderer gates too); coaches never see each other's stats.
            [
                'slug'  => 'coach-evaluation-quality',
                'label' => __( 'Coach · Evaluation quality', 'talenttrack' ),
                'desc'  => __( 'Per-coach rating distribution and variance — spots the rate-everyone-the-same pattern.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'coach-evaluation-quality' ], $base_url ),
            ],
            // #1369 — the legacy wp-admin Player Progress & Radar,
            // ported native. Coach-visible; the renderer scopes data
            // to the viewer's teams.
            [
                'slug'  => 'player-progress-radar',
                'label' => __( 'Player · Progress & radar', 'talenttrack' ),
                'desc'  => __( 'Radar charts over evaluation categories — per-player progress, player comparison, team averages.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'player-progress-radar' ], $base_url ),
            ],
            // #1487 — Rate cards, folded in from the former standalone
            // dashboard tile. The view self-gates the Standard tier
            // (upgrade nudge for Free), so the gating is preserved here.
            [
                'slug'  => 'rate-cards',
                'label' => __( 'Rate cards', 'talenttrack' ),
                'desc'  => __( 'Per-player rating cards with trends.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'rate-cards' ], $base_url ),
            ],
        ];

        echo '<p style="color:#5b6e75; margin-bottom:16px;">';
        esc_html_e( 'Pick a report.', 'talenttrack' );
        echo '</p>';

        // v4.20.29 (#1187) — filter the academy-wide tiles for
        // non-scope-admin users.
        if ( ! $is_scope_admin ) {
            $academy_only = [ 'season-summary', 'season-trial-funnel', 'scout-report-card', 'prospects_logged_per_scout', 'coach-evaluation-quality' ];
            $tiles = array_values( array_filter(
                $tiles,
                static fn( array $t ): bool => ! in_array( (string) ( $t['slug'] ?? '' ), $academy_only, true )
            ) );
        }

        // #1552 — the prospects-per-scout tile is an Explorer preset; drop
        // it when the Explorer feature is switched off so it doesn't link
        // into a disabled surface.
        if ( ! \TT\Modules\Analytics\AnalyticsModule::explorerEnabled() ) {
            $tiles = array_values( array_filter(
                $tiles,
                static fn( array $t ): bool => (string) ( $t['slug'] ?? '' ) !== 'prospects_logged_per_scout'
            ) );
        }

        // #1503 — group the tiles by purpose/theme instead of one flat
        // grid. Each group declares its tiles by slug in display order;
        // the scope filter above may have removed some, so a group with
        // no surviving tile renders no header (e.g. a regular coach sees
        // no Recruitment / Season overview section). Any tile not claimed
        // by a group falls through to a trailing "Other reports" section
        // so a future addition is never silently dropped.
        $groups = [
            [ 'label' => __( 'Development & performance', 'talenttrack' ), 'slugs' => [ 'player-progress-radar', 'rate-cards', 'team_ratings', 'team-squad-evaluation-summary' ] ],
            [ 'label' => __( 'Playing time', 'talenttrack' ),              'slugs' => [ 'player-minutes-played', 'team-minutes-distribution' ] ],
            [ 'label' => __( 'Attendance', 'talenttrack' ),                'slugs' => [ 'attendance-report-team', 'attendance-report-player', 'attendance-leaderboard' ] ],
            [ 'label' => __( 'Recruitment', 'talenttrack' ),               'slugs' => [ 'prospects_logged_per_scout', 'season-trial-funnel', 'scout-report-card' ] ],
            [ 'label' => __( 'Staff & quality', 'talenttrack' ),           'slugs' => [ 'coach_activity', 'coach-evaluation-quality' ] ],
            [ 'label' => __( 'Season overview', 'talenttrack' ),           'slugs' => [ 'season-summary' ] ],
        ];

        // #1543 — grouping + section rendering now live in the shared
        // FrontendSectionedTileGrid presenter (the #1503 renderReportSection
        // was folded into it). Output is visually unchanged: same heading +
        // .tt-cfg-tile-grid markup, same auto-hide-empty-section behaviour,
        // same trailing "Other reports" bucket for ungrouped tiles.
        $sections = \TT\Shared\Frontend\Components\FrontendSectionedTileGrid::fromGroups(
            $tiles,
            $groups,
            __( 'Other reports', 'talenttrack' )
        );
        \TT\Shared\Frontend\Components\FrontendSectionedTileGrid::render( $sections );
    }
}
