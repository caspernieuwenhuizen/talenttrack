<?php
namespace TT\Modules\Stats\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;

/**
 * PlayerReportView — printable A4 player report.
 *
 * Sprint 2C (v2.16.0). Single-page A4 portrait layout containing the
 * full rate card content plus the FIFA-style player card at the top.
 *
 * Triggered via ?print=1 on any rate-card URL. When present, the
 * renderer short-circuits the admin chrome and emits a stripped-down
 * HTML report that auto-invokes window.print() on load. The browser's
 * print dialog handles PDF export.
 *
 * Permissions are enforced by the callers that route to this view
 * (PlayerRateCardsPage::render, PlayersPage::render_form tab=ratecard).
 * This class assumes the caller has already verified access.
 */
class PlayerReportView {

    private const ROLLING_N = 5;
    private const RADAR_N   = 3;

    public static function render( int $player_id, array $filters ): void {
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            echo '<p>' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
            return;
        }

        // Enqueue card styles for the embedded card (the plugin's other
        // enqueue sites don't fire on this minimal render path, so we
        // dedupe-safely call it here too).
        PlayerCardView::enqueueStyles();

        $svc      = new PlayerStatsService();
        $evals    = $svc->getEvaluationsForPlayer( $player_id, $filters );
        $headline = $svc->getHeadlineNumbers( $player_id, $filters, self::ROLLING_N );
        $mains    = $svc->getMainCategoryBreakdown( $player_id, $filters );
        $subs     = $svc->getSubcategoryBreakdown( $player_id, $filters );
        $trend    = $svc->getTrendSeries( $player_id, $filters );
        $radar    = $svc->getRadarSnapshots( $player_id, $filters, self::RADAR_N );

        $club_name  = self::resolveClubName();
        $club_logo  = self::resolveClubLogoUrl();
        $player_name = QueryHelpers::player_display_name( $player );
        $report_date = date_i18n( get_option( 'date_format' ) ?: 'Y-m-d' );

        $period = self::formatFilterPeriod( $filters );

        self::renderPrintStyles();

        ?>
        <div class="tt-report-wrap">

            <header class="tt-report-header">
                <?php if ( $club_logo ) : ?>
                    <div class="tt-report-logo">
                        <img src="<?php echo esc_url( $club_logo ); ?>" alt="" />
                    </div>
                <?php endif; ?>
                <div class="tt-report-title-block">
                    <?php if ( $club_name !== '' ) : ?>
                        <div class="tt-report-club"><?php echo esc_html( $club_name ); ?></div>
                    <?php endif; ?>
                    <h1 class="tt-report-title">
                        <?php printf(
                            /* translators: %s is player name */
                            esc_html__( 'Player Report — %s', 'talenttrack' ),
                            esc_html( $player_name )
                        ); ?>
                    </h1>
                    <div class="tt-report-meta">
                        <span><?php esc_html_e( 'Generated:', 'talenttrack' ); ?> <?php echo esc_html( $report_date ); ?></span>
                        <span>&nbsp;·&nbsp;</span>
                        <span><?php esc_html_e( 'Period:', 'talenttrack' ); ?> <?php echo esc_html( $period ); ?></span>
                    </div>
                </div>
            </header>

            <section class="tt-report-card-section">
                <?php PlayerCardView::renderCard( $player_id, 'md', false, null ); ?>
            </section>

            <?php if ( $headline['eval_count'] === 0 ) : ?>
                <p><em><?php esc_html_e( 'No evaluations in this range.', 'talenttrack' ); ?></em></p>
            <?php else : ?>

                <section class="tt-report-headline">
                    <?php
                    self::renderHeadlineTile(
                        __( 'Most recent', 'talenttrack' ),
                        $headline['latest'],
                        $headline['latest_date'] ?? ''
                    );
                    self::renderHeadlineTile(
                        __( 'Rolling average', 'talenttrack' ),
                        $headline['rolling'],
                        $headline['rolling_count'] > 0
                            ? sprintf(
                                /* translators: %d is number of evaluations */
                                _n( 'Last %d evaluation', 'Last %d evaluations', (int) $headline['rolling_count'], 'talenttrack' ),
                                (int) $headline['rolling_count']
                            ) : ''
                    );
                    self::renderHeadlineTile(
                        __( 'All-time average', 'talenttrack' ),
                        $headline['alltime'],
                        $headline['alltime_count'] > 0
                            ? sprintf(
                                /* translators: %d is number of evaluations */
                                _n( 'Based on %d evaluation', 'Based on %d evaluations', (int) $headline['alltime_count'], 'talenttrack' ),
                                (int) $headline['alltime_count']
                            ) : ''
                    );
                    ?>
                </section>

                <section class="tt-report-breakdown">
                    <h2><?php esc_html_e( 'Main category breakdown', 'talenttrack' ); ?></h2>
                    <table>
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Category', 'talenttrack' ); ?></th>
                                <th><?php esc_html_e( 'All-time', 'talenttrack' ); ?></th>
                                <th><?php esc_html_e( 'Most recent', 'talenttrack' ); ?></th>
                                <th><?php esc_html_e( 'Trend', 'talenttrack' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $mains as $row ) :
                            $label = EvalCategoriesRepository::displayLabel( (string) $row['label'] );
                            $trend_txt = self::trendText( (string) $row['trend'] );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $label ); ?></strong></td>
                                <td><?php echo $row['alltime'] === null ? '—' : esc_html( (string) $row['alltime'] ); ?></td>
                                <td><?php echo $row['latest'] === null ? '—' : esc_html( (string) $row['latest'] ); ?></td>
                                <td><?php echo esc_html( $trend_txt ); ?></td>
                            </tr>
                            <?php
                            $mid = (int) $row['main_id'];
                            if ( isset( $subs[ $mid ] ) && ! empty( $subs[ $mid ]['subs'] ) ) :
                                foreach ( $subs[ $mid ]['subs'] as $sub ) : ?>
                                    <tr class="tt-report-sub-row">
                                        <td>&nbsp;&nbsp;&nbsp;↳ <?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $sub['label'] ) ); ?></td>
                                        <td><?php echo esc_html( (string) $sub['mean'] ); ?></td>
                                        <td>—</td>
                                        <td><?php printf(
                                            /* translators: %d is number of evaluations */
                                            esc_html( _n( '(%d eval)', '(%d evals)', (int) $sub['count'], 'talenttrack' ) ),
                                            (int) $sub['count']
                                        ); ?></td>
                                    </tr>
                                <?php endforeach;
                            endif;
                        endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="tt-report-charts">
                    <div class="tt-report-chart">
                        <h3><?php esc_html_e( 'Trend over time', 'talenttrack' ); ?></h3>
                        <canvas id="tt-report-trend" width="500" height="260"></canvas>
                    </div>
                    <div class="tt-report-chart">
                        <h3><?php esc_html_e( 'Recent shape', 'talenttrack' ); ?></h3>
                        <canvas id="tt-report-radar" width="280" height="260"></canvas>
                    </div>
                </section>

            <?php endif; ?>

            <footer class="tt-report-footer">
                <div class="tt-report-sig">
                    <span class="tt-report-sig-label"><?php esc_html_e( 'Coach:', 'talenttrack' ); ?></span>
                    <span class="tt-report-sig-line"></span>
                </div>
                <div class="tt-report-sig">
                    <span class="tt-report-sig-label"><?php esc_html_e( 'Date:', 'talenttrack' ); ?></span>
                    <span class="tt-report-sig-line"></span>
                </div>
            </footer>

        </div>

        <?php
        // Chart.js + auto-print bootstrap. Charts render first, then we
        // wait for them to paint before calling window.print().
        $rating_max = (float) QueryHelpers::get_config( 'rating_max', '5' );

        // Translate main labels for chart legends.
        $trend_series = [];
        foreach ( $trend['series'] as $s ) {
            $trend_series[] = [
                'label'  => EvalCategoriesRepository::displayLabel( (string) $s['label'] ),
                'points' => $s['points'],
            ];
        }
        $radar_labels = [];
        foreach ( $radar['labels'] as $lbl ) {
            $radar_labels[] = EvalCategoriesRepository::displayLabel( (string) $lbl );
        }
        $chart_payload = [
            'trend' => [
                'labels'     => $trend['labels'],
                'series'     => $trend_series,
                'rating_max' => $rating_max,
            ],
            'radar' => [
                'labels'     => $radar_labels,
                'datasets'   => $radar['datasets'],
                'rating_max' => $rating_max,
            ],
        ];
        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
        (function(){
            // v2.17.0: no auto-print. Charts render normally; user
            // triggers print via the visible button on the print page.
            var payload = <?php echo wp_json_encode( $chart_payload ); ?>;
            var colors = ['#e8b624', '#3a86ff', '#ff595e', '#8ac926', '#6a4c93'];

            if (typeof Chart === 'undefined') {
                return;
            }

            var trendEl = document.getElementById('tt-report-trend');
            if (trendEl && payload.trend.labels.length > 0) {
                var datasets = payload.trend.series.map(function(s, i){
                    return {
                        label: s.label,
                        data: s.points,
                        borderColor: colors[i % colors.length],
                        backgroundColor: colors[i % colors.length],
                        spanGaps: true,
                        tension: 0.25
                    };
                });
                new Chart(trendEl.getContext('2d'), {
                    type: 'line',
                    data: { labels: payload.trend.labels, datasets: datasets },
                    options: {
                        responsive: false,
                        scales: { y: { min: 0, max: payload.trend.rating_max, ticks: { stepSize: 1 } } },
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            var radarEl = document.getElementById('tt-report-radar');
            if (radarEl && payload.radar.datasets.length > 0) {
                var rdatasets = payload.radar.datasets.map(function(d, i){
                    var c = colors[i % colors.length];
                    return {
                        label: d.label,
                        data: d.values,
                        borderColor: c,
                        backgroundColor: c + '33',
                        pointBackgroundColor: c
                    };
                });
                new Chart(radarEl.getContext('2d'), {
                    type: 'radar',
                    data: { labels: payload.radar.labels, datasets: rdatasets },
                    options: {
                        responsive: false,
                        scales: { r: { min: 0, max: payload.radar.rating_max, ticks: { stepSize: 1 } } },
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }
        })();
        </script>
        <?php
    }

    /* ═══════════════ Helpers ═══════════════ */

    private static function renderHeadlineTile( string $title, ?float $value, string $subtext ): void {
        ?>
        <div class="tt-report-headline-tile">
            <div class="tt-rht-title"><?php echo esc_html( $title ); ?></div>
            <div class="tt-rht-value"><?php echo $value === null ? '—' : esc_html( (string) $value ); ?></div>
            <?php if ( $subtext !== '' ) : ?>
                <div class="tt-rht-sub"><?php echo esc_html( $subtext ); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function trendText( string $trend ): string {
        switch ( $trend ) {
            case 'up':   return '↑ ' . __( 'Improving', 'talenttrack' );
            case 'down': return '↓ ' . __( 'Declining', 'talenttrack' );
            case 'flat': return '→ ' . __( 'Stable', 'talenttrack' );
            default:     return '— ' . __( 'Not enough data', 'talenttrack' );
        }
    }

    private static function formatFilterPeriod( array $filters ): string {
        $from = $filters['date_from'] ?? '';
        $to   = $filters['date_to']   ?? '';
        if ( $from === '' && $to === '' ) return __( 'All evaluations', 'talenttrack' );
        if ( $from !== '' && $to !== '' ) return $from . ' — ' . $to;
        if ( $from !== '' ) return __( 'From', 'talenttrack' ) . ' ' . $from;
        return __( 'Until', 'talenttrack' ) . ' ' . $to;
    }

    private static function resolveClubName(): string {
        // Reuse existing Configuration → Academy → Academy Name setting.
        $v = (string) QueryHelpers::get_config( 'academy_name', '' );
        return trim( $v );
    }

    private static function resolveClubLogoUrl(): string {
        // Reuse existing Configuration → Academy → Logo URL setting.
        // This is a plain URL field on the configuration page, not a
        // media-attachment id — simpler to configure, works immediately.
        $url = (string) QueryHelpers::get_config( 'logo_url', '' );
        return trim( $url );
    }

    /**
     * Print-report CSS. Inline for deployment simplicity — the report is
     * a standalone route that doesn't benefit from external stylesheet
     * caching.
     */
    private static function renderPrintStyles(): void {
        ?>
        <style>
        /* ═════ Screen preview + print ═════ */
        .tt-report-wrap {
            max-width: 780px;
            margin: 24px auto;
            padding: 28px 32px;
            background: #fff;
            color: #1a1d21;
            font-family: 'Manrope', system-ui, sans-serif;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            box-sizing: border-box;
        }

        .tt-report-header {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            padding-bottom: 18px;
            border-bottom: 2px solid #1a1d21;
            margin-bottom: 20px;
        }
        .tt-report-logo img {
            max-width: 90px;
            max-height: 90px;
            display: block;
        }
        .tt-report-title-block { flex: 1; }
        .tt-report-club {
            font-size: 11px;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #555;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .tt-report-title {
            font-family: 'Oswald', sans-serif;
            font-weight: 600;
            font-size: 26px;
            margin: 0 0 4px;
            line-height: 1.15;
            color: #1a1d21;
        }
        .tt-report-meta {
            font-size: 12px;
            color: #666;
        }

        .tt-report-card-section {
            display: flex;
            justify-content: center;
            padding: 8px 0 20px;
        }

        .tt-report-headline {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }
        .tt-report-headline-tile {
            border: 1px solid #dcdcde;
            border-left: 4px solid #1a2332;
            padding: 10px 14px;
            background: #fafbfc;
            box-sizing: border-box;
        }
        .tt-rht-title {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #666;
            font-weight: 600;
        }
        .tt-rht-value {
            font-family: 'Oswald', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: #1a2332;
            line-height: 1.1;
            margin: 2px 0;
        }
        .tt-rht-sub {
            font-size: 10px;
            color: #777;
        }

        .tt-report-breakdown h2 {
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 8px;
        }
        .tt-report-breakdown table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .tt-report-breakdown th {
            text-align: left;
            padding: 6px 8px;
            background: #f2f3f5;
            border-bottom: 2px solid #ccc;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #444;
        }
        .tt-report-breakdown th:nth-child(2),
        .tt-report-breakdown th:nth-child(3) { text-align: right; width: 80px; }
        .tt-report-breakdown th:nth-child(4) { width: 140px; }
        .tt-report-breakdown td {
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
        }
        .tt-report-breakdown td:nth-child(2),
        .tt-report-breakdown td:nth-child(3) { text-align: right; }
        .tt-report-breakdown tr.tt-report-sub-row td {
            background: #fafafa;
            color: #555;
            font-size: 11px;
        }

        .tt-report-charts {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 14px;
            margin-top: 20px;
            margin-bottom: 24px;
        }
        .tt-report-chart {
            border: 1px solid #dcdcde;
            padding: 10px 12px;
            background: #fff;
            box-sizing: border-box;
        }
        .tt-report-chart h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 600;
            margin: 0 0 6px;
        }
        .tt-report-chart canvas {
            max-width: 100%;
            height: auto !important;
        }

        .tt-report-footer {
            display: flex;
            gap: 30px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dcdcde;
        }
        .tt-report-sig {
            flex: 1;
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }
        .tt-report-sig-label {
            font-size: 11px;
            color: #555;
            white-space: nowrap;
            padding-bottom: 2px;
        }
        .tt-report-sig-line {
            flex: 1;
            border-bottom: 1px solid #333;
            height: 20px;
        }

        /* ═════ A4 print ═════ */
        @media print {
            @page {
                size: A4 portrait;
                margin: 15mm 15mm 15mm 15mm;
            }
            body {
                background: #fff !important;
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .tt-report-wrap {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
                width: 100%;
            }
            .tt-report-breakdown,
            .tt-report-charts,
            .tt-report-card-section {
                page-break-inside: avoid;
            }
            .tt-report-footer {
                page-break-inside: avoid;
            }
            .tt-pc:hover {
                transform: none;
            }
            /* Hide the print button if somehow still in DOM */
            .tt-report-print-btn { display: none; }
        }

        /* ═════ Responsive preview on mobile ═════ */
        @media (max-width: 640px) {
            .tt-report-wrap { padding: 16px; margin: 10px; }
            .tt-report-headline { grid-template-columns: 1fr; }
            .tt-report-charts { grid-template-columns: 1fr; }
            .tt-report-header { flex-direction: column; gap: 10px; }
            .tt-report-footer { flex-direction: column; gap: 20px; }
        }
        </style>
        <?php
    }
}
