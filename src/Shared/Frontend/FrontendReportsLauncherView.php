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
            [ 'label' => __( 'Recruitment', 'talenttrack' ),               'slugs' => [ 'prospects_logged_per_scout', 'season-trial-funnel', 'scout-report-card' ] ],
            [ 'label' => __( 'Staff & quality', 'talenttrack' ),           'slugs' => [ 'coach_activity', 'coach-evaluation-quality' ] ],
            [ 'label' => __( 'Season overview', 'talenttrack' ),           'slugs' => [ 'season-summary' ] ],
        ];

        $by_slug = [];
        foreach ( $tiles as $tile ) {
            $by_slug[ (string) ( $tile['slug'] ?? '' ) ] = $tile;
        }
        $placed = [];

        foreach ( $groups as $group ) {
            $group_tiles = [];
            foreach ( $group['slugs'] as $slug ) {
                if ( isset( $by_slug[ $slug ] ) ) {
                    $group_tiles[] = $by_slug[ $slug ];
                    $placed[ $slug ] = true;
                }
            }
            if ( empty( $group_tiles ) ) continue;
            self::renderReportSection( (string) $group['label'], $group_tiles );
        }

        // Trailing safety net for any tile not assigned to a group.
        $leftover = array_values( array_filter(
            $tiles,
            static fn( array $t ): bool => ! isset( $placed[ (string) ( $t['slug'] ?? '' ) ] )
        ) );
        if ( ! empty( $leftover ) ) {
            self::renderReportSection( __( 'Other reports', 'talenttrack' ), $leftover );
        }
    }

    /**
     * #1503 — render one report section: a small-caps heading above the
     * existing tile grid. Kept mobile-first — the grid wraps to a single
     * column at 360px and tap targets are unchanged.
     *
     * @param list<array{label:string,desc:string,url:string}> $tiles
     */
    private static function renderReportSection( string $label, array $tiles ): void {
        echo '<h3 class="tt-reports-section" style="margin:18px 0 8px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#6b7280;">'
            . esc_html( $label ) . '</h3>';
        echo '<div class="tt-cfg-tile-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px;">';
        foreach ( $tiles as $tile ) {
            ?>
            <a class="tt-cfg-tile" href="<?php echo esc_url( (string) $tile['url'] ); ?>"
               style="display:block; background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:14px; text-decoration:none; color:#1a1d21; min-height:76px;">
                <div style="font-weight:600; font-size:14px; line-height:1.25; margin-bottom:4px;"><?php echo esc_html( (string) $tile['label'] ); ?></div>
                <div style="color:#6b7280; font-size:12px; line-height:1.35;"><?php echo esc_html( (string) $tile['desc'] ); ?></div>
            </a>
            <?php
        }
        echo '</div>';
    }
}
