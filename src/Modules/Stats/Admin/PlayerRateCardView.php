<?php
namespace TT\Modules\Stats\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;

/**
 * PlayerRateCardView — renders the rate card for a single player.
 *
 * Sprint 2A (v2.14.0). Used by two entry points:
 *   - TalentTrack → Player Rate Cards (standalone, player picker on top)
 *   - TalentTrack → Players → edit → "Rate Card" tab (embedded)
 *
 * The view is stateless — render() reads filters from the $filters
 * argument and a base URL from $base_url (for the filter form action,
 * since the two entry points post to different URLs). Call it from
 * inside a `<div class="wrap">` — it renders children only.
 */
class PlayerRateCardView {

    private const ROLLING_N = 5;
    private const RADAR_N   = 3;

    /**
     * @param array{date_from:string,date_to:string,eval_type_id:int} $filters
     * @param string $base_url  Absolute URL to re-target filter GETs at.
     *                          The view appends ?player_id=...&... as needed.
     */
    public static function render( int $player_id, array $filters, string $base_url ): void {
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            echo '<p><em>' . esc_html__( 'Player not found.', 'talenttrack' ) . '</em></p>';
            return;
        }

        // NOTE: v2.17.0 removed the ?print=1 short-circuit here — the
        // PrintRouter now intercepts print requests at admin_init before
        // this view renders. The inline renderCardView() path remains
        // for the ?view=card toggle.

        // v2.16.0 — responsive styles scoped to the rate card page.
        // Done as inline <style> rather than a separate stylesheet because
        // the existing markup uses inline styles everywhere; a stylesheet
        // would need to fight specificity. These rules use !important
        // defensively against the inline attribute values.
        self::renderResponsiveStyles();

        // v2.15.0: Standard vs Card view toggle. Card view is a visual
        // presentation of the same data; Standard view is the analytical
        // surface built in Sprint 2A.
        $view_mode = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'standard';
        if ( $view_mode !== 'card' ) $view_mode = 'standard';

        self::renderViewToggle( $view_mode, $base_url, $player_id );

        if ( $view_mode === 'card' ) {
            PlayerCardView::enqueueStyles();
            self::renderCardView( $player_id );
            return;
        }

        $svc        = new PlayerStatsService();
        $evals      = $svc->getEvaluationsForPlayer( $player_id, $filters );
        $headline   = $svc->getHeadlineNumbers( $player_id, $filters, self::ROLLING_N );
        $mains      = $svc->getMainCategoryBreakdown( $player_id, $filters );
        $subs       = $svc->getSubcategoryBreakdown( $player_id, $filters );
        $trend      = $svc->getTrendSeries( $player_id, $filters );
        $radar      = $svc->getRadarSnapshots( $player_id, $filters, self::RADAR_N );
        $eval_types = QueryHelpers::get_eval_types();

        self::renderFilters( $filters, $eval_types, $base_url, $player_id );

        if ( $headline['eval_count'] === 0 ) {
            echo '<p style="margin-top:20px;"><em>' . esc_html__( 'No evaluations in this range.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::renderHeadline( $headline );
        self::renderMainBreakdown( $mains, $subs );
        self::renderCharts( $trend, $radar, (float) QueryHelpers::get_config( 'rating_max', '5' ) );
    }

    private static function renderViewToggle( string $current, string $base_url, int $player_id ): void {
        $standard_url = remove_query_arg( 'view', $base_url );
        $standard_url = add_query_arg( [ 'player_id' => $player_id ], $standard_url );
        $card_url     = add_query_arg( [ 'player_id' => $player_id, 'view' => 'card' ], remove_query_arg( 'view', $base_url ) );

        // v2.17.0: print URL routes through the isolated PrintRouter
        // (?tt_report=1) instead of embedding in the admin shell. The
        // router emits a standalone <html> page with visible Print +
        // Download PDF buttons; no auto-fire printing.
        $print_args = [ 'tt_report' => '1', 'player_id' => $player_id ];
        foreach ( [ 'date_from', 'date_to', 'eval_type_id' ] as $k ) {
            if ( isset( $_GET[ $k ] ) && $_GET[ $k ] !== '' && $_GET[ $k ] !== '0' ) {
                $print_args[ $k ] = sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) );
            }
        }
        $print_url = add_query_arg( $print_args, admin_url( 'admin.php' ) );

        $btn_base = 'display:inline-block;padding:6px 14px;text-decoration:none;border:1px solid #c3c4c7;border-radius:4px;font-size:13px;';
        $btn_on   = 'background:#2271b1;color:#fff;border-color:#2271b1;';
        $btn_off  = 'background:#fff;color:#2271b1;';
        $btn_print = 'background:#fff;color:#1a1d21;border-color:#c3c4c7;';
        ?>
        <div style="margin:10px 0 14px; display:flex; gap:6px; flex-wrap:wrap;">
            <a href="<?php echo esc_url( $standard_url ); ?>"
               style="<?php echo $btn_base . ( $current === 'standard' ? $btn_on : $btn_off ); ?>">
                <?php esc_html_e( 'Standard view', 'talenttrack' ); ?>
            </a>
            <a href="<?php echo esc_url( $card_url ); ?>"
               style="<?php echo $btn_base . ( $current === 'card' ? $btn_on : $btn_off ); ?>">
                <?php esc_html_e( 'Card view', 'talenttrack' ); ?>
            </a>
            <a href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener"
               style="<?php echo $btn_base . $btn_print; ?>; margin-left:auto;">
                <?php echo esc_html_x( '🖨 Print report', 'button label', 'talenttrack' ); ?>
            </a>
        </div>
        <?php
    }

    private static function renderCardView( int $player_id ): void {
        ?>
        <div style="padding:20px 0; display:flex; justify-content:center; background:#f0f1f3; border-radius:6px;">
            <?php PlayerCardView::renderCard( $player_id, 'lg', true ); ?>
        </div>
        <?php
    }

    /**
     * v2.16.0 — responsive rules for the rate card page. Inline style
     * block so they ship alongside the view regardless of whether a
     * separate stylesheet got enqueued. Uses !important defensively
     * against inline styles already present in the existing markup.
     */
    private static function renderResponsiveStyles(): void {
        static $emitted = false;
        if ( $emitted ) return;
        $emitted = true;
        ?>
        <style>
        .tt-rc-responsive-anchor { display: none; }

        /* Tablet & phone */
        @media (max-width: 820px) {
            .tt-rc-filters {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 10px !important;
            }
            .tt-rc-filters label {
                margin-right: 0 !important;
                display: block;
            }
            .tt-rc-filters input[type="date"],
            .tt-rc-filters select {
                width: 100%;
                max-width: none;
                min-height: 36px;
                font-size: 15px;
            }
            .tt-rc-headline {
                flex-direction: column !important;
                gap: 10px !important;
            }
            .tt-rc-headline > div {
                flex: 1 1 auto !important;
                min-width: 0 !important;
            }
            .tt-rc-charts {
                flex-direction: column !important;
            }
            .tt-rc-charts > div {
                flex: 1 1 auto !important;
                min-width: 0 !important;
                width: 100%;
            }
        }

        /* Phone */
        @media (max-width: 640px) {
            /* Breakdown table: collapse to stacked cards. Each row becomes
               a mini-card with the category name on top, stats in a 3-col
               grid below. Subs stay as rows inside expansion. */
            table.tt-main-breakdown,
            table.tt-main-breakdown tbody,
            table.tt-main-breakdown tr,
            table.tt-main-breakdown td {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box;
            }
            table.tt-main-breakdown thead { display: none !important; }
            table.tt-main-breakdown tr.tt-main-row {
                border: 1px solid #dcdcde;
                border-radius: 6px;
                margin-bottom: 10px;
                padding: 10px 12px;
                background: #fff;
            }
            table.tt-main-breakdown tr.tt-main-row td {
                padding: 4px 0 !important;
                text-align: left !important;
                border: none !important;
            }
            table.tt-main-breakdown tr.tt-main-row td:first-child {
                font-size: 16px;
                padding-bottom: 8px !important;
                border-bottom: 1px solid #f0f0f1 !important;
                margin-bottom: 6px;
            }
            table.tt-main-breakdown tr.tt-main-row td:nth-child(2)::before { content: "All-time: "; color: #666; font-weight: 400; }
            table.tt-main-breakdown tr.tt-main-row td:nth-child(3)::before { content: "Recent: "; color: #666; font-weight: 400; }
            table.tt-main-breakdown tr.tt-main-row td:nth-child(4) { display: inline-block !important; width: auto !important; margin-left: 0; }
            table.tt-main-breakdown tr.tt-main-row td:nth-child(5) { display: inline-block !important; width: auto !important; float: right; }
            table.tt-main-breakdown tr.tt-subs-row td {
                padding: 8px 10px !important;
            }

            /* Filter form inputs grow touch-friendly */
            .tt-rc-filters button {
                width: 100%;
                padding: 10px !important;
                font-size: 15px !important;
            }
        }

        /* Print — the full report layout */
        @media print {
            body * { visibility: hidden; }
            .tt-report-wrap, .tt-report-wrap * { visibility: visible; }
            .tt-report-wrap {
                position: absolute;
                left: 0; top: 0;
                width: 100%;
            }
            .tt-pc__shine { display: none; }
        }
        </style>
        <?php
    }

    // Filters

    private static function renderFilters( array $filters, array $eval_types, string $base_url, int $player_id ): void {
        $from = $filters['date_from'] ?? '';
        $to   = $filters['date_to']   ?? '';
        $tid  = (int) ( $filters['eval_type_id'] ?? 0 );
        ?>
        <form method="get" action="<?php echo esc_url( $base_url ); ?>" class="tt-rc-filters" style="margin:16px 0; padding:12px 16px; background:#f6f7f7; border:1px solid #dcdcde; display:flex; flex-wrap:wrap; align-items:center;">
            <?php
            // Preserve page + other GET params the entry-point adds.
            $parsed = wp_parse_url( $base_url );
            if ( ! empty( $parsed['query'] ) ) {
                parse_str( $parsed['query'], $q );
                foreach ( $q as $k => $v ) {
                    if ( in_array( $k, [ 'date_from', 'date_to', 'eval_type_id' ], true ) ) continue;
                    if ( is_array( $v ) ) continue;
                    echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
                }
            }
            ?>
            <input type="hidden" name="player_id" value="<?php echo $player_id; ?>" />

            <label style="margin-right:16px;">
                <?php esc_html_e( 'From', 'talenttrack' ); ?>
                <input type="date" name="date_from" value="<?php echo esc_attr( $from ); ?>" />
            </label>
            <label style="margin-right:16px;">
                <?php esc_html_e( 'To', 'talenttrack' ); ?>
                <input type="date" name="date_to" value="<?php echo esc_attr( $to ); ?>" />
            </label>
            <label style="margin-right:16px;">
                <?php esc_html_e( 'Type', 'talenttrack' ); ?>
                <select name="eval_type_id">
                    <option value="0"><?php esc_html_e( 'All types', 'talenttrack' ); ?></option>
                    <?php foreach ( $eval_types as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>" <?php selected( $tid, (int) $t->id ); ?>>
                            <?php echo esc_html( (string) $t->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply filters', 'talenttrack' ); ?></button>
            <?php if ( $from !== '' || $to !== '' || $tid > 0 ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'player_id' => $player_id ], $base_url ) ); ?>" class="button" style="margin-left:6px;">
                    <?php esc_html_e( 'Clear', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>
        </form>
        <?php
    }

    // Headline

    private static function renderHeadline( array $h ): void {
        ?>
        <div class="tt-rc-headline" style="display:flex; gap:16px; flex-wrap:wrap; margin:10px 0 20px;">
            <?php
            self::renderHeadlineCard(
                __( 'Most recent', 'talenttrack' ),
                $h['latest'],
                $h['latest_date'] ? esc_html( (string) $h['latest_date'] ) : ''
            );
            $rolling_sub = $h['rolling_count'] > 0
                ? sprintf(
                    /* translators: %d is number of evaluations included in the rolling average. */
                    _n( 'Last %d evaluation', 'Last %d evaluations', (int) $h['rolling_count'], 'talenttrack' ),
                    (int) $h['rolling_count']
                )
                : '';
            self::renderHeadlineCard( __( 'Rolling average', 'talenttrack' ), $h['rolling'], esc_html( $rolling_sub ) );

            $alltime_sub = $h['alltime_count'] > 0
                ? sprintf(
                    /* translators: %d is number of evaluations in all-time mean. */
                    _n( 'Based on %d evaluation', 'Based on %d evaluations', (int) $h['alltime_count'], 'talenttrack' ),
                    (int) $h['alltime_count']
                )
                : '';
            self::renderHeadlineCard( __( 'All-time average', 'talenttrack' ), $h['alltime'], esc_html( $alltime_sub ) );
            ?>
        </div>
        <?php
    }

    private static function renderHeadlineCard( string $title, ?float $value, string $subtext ): void {
        ?>
        <div style="flex:1; min-width:180px; background:#fff; border:1px solid #dcdcde; border-left:4px solid #2271b1; padding:12px 16px;">
            <div style="font-size:12px; text-transform:uppercase; color:#666; letter-spacing:0.04em;"><?php echo esc_html( $title ); ?></div>
            <div style="font-size:30px; font-weight:700; color:#2271b1; margin:4px 0 2px;">
                <?php echo $value === null ? '—' : esc_html( (string) $value ); ?>
            </div>
            <div style="font-size:12px; color:#666; min-height:16px;"><?php echo $subtext; // already escaped ?></div>
        </div>
        <?php
    }

    // Main category breakdown

    private static function renderMainBreakdown( array $mains, array $subs ): void {
        ?>
        <h3 style="margin-top:20px;"><?php esc_html_e( 'Main category breakdown', 'talenttrack' ); ?></h3>
        <table class="widefat striped tt-main-breakdown" style="max-width:820px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Category', 'talenttrack' ); ?></th>
                    <th style="width:100px; text-align:right;"><?php esc_html_e( 'All-time', 'talenttrack' ); ?></th>
                    <th style="width:100px; text-align:right;"><?php esc_html_e( 'Most recent', 'talenttrack' ); ?></th>
                    <th style="width:120px; text-align:center;"><?php esc_html_e( 'Trend', 'talenttrack' ); ?></th>
                    <th style="width:50px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $mains as $row ) :
                $mid     = (int) $row['main_id'];
                $label   = EvalCategoriesRepository::displayLabel( (string) $row['label'] );
                $has_sub = isset( $subs[ $mid ] ) && ! empty( $subs[ $mid ]['subs'] );
                $trend   = $row['trend'];
                $trend_arrow = '—';
                $trend_text  = __( 'Stable', 'talenttrack' );
                $trend_color = '#888';
                if ( $trend === 'up' ) {
                    $trend_arrow = '↑'; $trend_text = __( 'Improving', 'talenttrack' ); $trend_color = '#00a32a';
                } elseif ( $trend === 'down' ) {
                    $trend_arrow = '↓'; $trend_text = __( 'Declining', 'talenttrack' ); $trend_color = '#b32d2e';
                } elseif ( $trend === 'flat' ) {
                    $trend_arrow = '→'; $trend_text = __( 'Stable', 'talenttrack' ); $trend_color = '#2271b1';
                } else {
                    $trend_text = __( 'Not enough data', 'talenttrack' );
                }
                ?>
                <tr class="tt-main-row" data-main-id="<?php echo $mid; ?>">
                    <td><strong><?php echo esc_html( $label ); ?></strong></td>
                    <td style="text-align:right;">
                        <?php echo $row['alltime'] === null ? '—' : esc_html( (string) $row['alltime'] ); ?>
                    </td>
                    <td style="text-align:right;">
                        <?php echo $row['latest'] === null ? '—' : esc_html( (string) $row['latest'] ); ?>
                    </td>
                    <td style="text-align:center;">
                        <span style="color:<?php echo esc_attr( $trend_color ); ?>; font-weight:600; font-size:18px;"><?php echo esc_html( $trend_arrow ); ?></span>
                        <span style="color:#666; font-size:11px; margin-left:4px;"><?php echo esc_html( $trend_text ); ?></span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ( $has_sub ) : ?>
                            <a href="#" class="tt-toggle-subs" data-target="tt-subs-<?php echo $mid; ?>" style="text-decoration:none;">▸</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ( $has_sub ) : ?>
                    <tr class="tt-subs-row" id="tt-subs-<?php echo $mid; ?>" style="display:none;">
                        <td colspan="5" style="background:#fafafa; padding:8px 16px 12px;">
                            <div style="color:#666; font-size:12px; margin-bottom:6px;">
                                <?php esc_html_e( 'Subcategory breakdown', 'talenttrack' ); ?>
                            </div>
                            <table style="width:100%;">
                                <tbody>
                                <?php foreach ( $subs[ $mid ]['subs'] as $sub ) : ?>
                                    <tr>
                                        <td style="padding:3px 12px 3px 16px; color:#555;">
                                            <span style="color:#bbb;">↳</span>
                                            <?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $sub['label'] ) ); ?>
                                        </td>
                                        <td style="padding:3px 0; width:80px; text-align:right;">
                                            <strong><?php echo esc_html( (string) $sub['mean'] ); ?></strong>
                                        </td>
                                        <td style="padding:3px 0 3px 10px; width:160px; color:#888; font-size:11px;">
                                            <?php printf(
                                                /* translators: %d is number of evaluations this subcategory appeared in. */
                                                esc_html( _n( '%d evaluation counted', '%d evaluations counted', (int) $sub['count'], 'talenttrack' ) ),
                                                (int) $sub['count']
                                            ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <script>
        (function(){
            var toggles = document.querySelectorAll('.tt-toggle-subs');
            toggles.forEach(function(a){
                a.addEventListener('click', function(e){
                    e.preventDefault();
                    var id = a.getAttribute('data-target');
                    var tr = document.getElementById(id);
                    if (!tr) return;
                    if (tr.style.display === 'none') {
                        tr.style.display = '';
                        a.textContent = '▾';
                    } else {
                        tr.style.display = 'none';
                        a.textContent = '▸';
                    }
                });
            });
        })();
        </script>
        <?php
    }

    // Charts

    private static function renderCharts( array $trend, array $radar, float $rating_max ): void {
        $has_trend = ! empty( $trend['labels'] );
        $has_radar = ! empty( $radar['datasets'] );
        if ( ! $has_trend && ! $has_radar ) return;

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

        $chart_data = [
            'trend' => [
                'labels'      => $trend['labels'],
                'series'      => $trend_series,
                'rating_max'  => $rating_max,
            ],
            'radar' => [
                'labels'     => $radar_labels,
                'datasets'   => $radar['datasets'],
                'rating_max' => $rating_max,
            ],
        ];
        $payload_id = 'tt_ratecard_data_' . wp_generate_uuid4();
        ?>
        <div class="tt-rc-charts" style="display:flex; gap:20px; flex-wrap:wrap; margin-top:24px;">
            <?php if ( $has_trend ) : ?>
                <div style="flex:2; min-width:360px; background:#fff; border:1px solid #dcdcde; padding:12px 16px;">
                    <h3 style="margin:0 0 8px;"><?php esc_html_e( 'Trend over time', 'talenttrack' ); ?></h3>
                    <div style="position:relative; height:300px;">
                        <canvas class="tt-ratecard-trend" data-payload-id="<?php echo esc_attr( $payload_id ); ?>"></canvas>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ( $has_radar ) : ?>
                <div style="flex:1; min-width:300px; background:#fff; border:1px solid #dcdcde; padding:12px 16px;">
                    <h3 style="margin:0 0 8px;">
                        <?php printf(
                            /* translators: %d is the number of evaluations overlaid in the radar chart. */
                            esc_html( _n( 'Shape over last %d evaluation', 'Shape over last %d evaluations', count( $radar['datasets'] ), 'talenttrack' ) ),
                            count( $radar['datasets'] )
                        ); ?>
                    </h3>
                    <div style="position:relative; height:300px;">
                        <canvas class="tt-ratecard-radar" data-payload-id="<?php echo esc_attr( $payload_id ); ?>"></canvas>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script type="application/json" id="<?php echo esc_attr( $payload_id ); ?>">
            <?php echo wp_json_encode( $chart_data ); ?>
        </script>

        <script>
        (function(){
            // Chart.js is enqueued in the footer; wait for DOMContentLoaded
            // so the <script src=chart.js> tag has executed before we
            // check for `window.Chart`. Running synchronously here would
            // fire the fallback branch every time on the frontend.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bootRateCardCharts);
            } else {
                bootRateCardCharts();
            }

            function bootRateCardCharts() {
            if (typeof Chart === 'undefined') {
                // Chart.js didn't load — show placeholders in place of canvases.
                var msg = <?php echo wp_json_encode( __( 'Chart library unavailable. Data tables above still show the same information.', 'talenttrack' ) ); ?>;
                document.querySelectorAll('.tt-ratecard-trend, .tt-ratecard-radar').forEach(function(c){
                    var wrap = c.parentNode;
                    wrap.innerHTML = '<p style="color:#888; padding:20px;">' + msg + '</p>';
                });
                return;
            }
            var payload = JSON.parse(document.getElementById(<?php echo wp_json_encode( $payload_id ); ?>).textContent);
            var colors = ['#e8b624', '#3a86ff', '#ff595e', '#8ac926', '#6a4c93'];

            // Trend line chart.
            document.querySelectorAll('.tt-ratecard-trend').forEach(function(c){
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
                new Chart(c.getContext('2d'), {
                    type: 'line',
                    data: { labels: payload.trend.labels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { min: 0, max: payload.trend.rating_max, ticks: { stepSize: 1 } }
                        },
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });
            });

            // Radar snapshots chart.
            document.querySelectorAll('.tt-ratecard-radar').forEach(function(c){
                var datasets = payload.radar.datasets.map(function(d, i){
                    var color = colors[i % colors.length];
                    return {
                        label: d.label,
                        data: d.values,
                        borderColor: color,
                        backgroundColor: color + '33', // 20% alpha in hex
                        pointBackgroundColor: color
                    };
                });
                new Chart(c.getContext('2d'), {
                    type: 'radar',
                    data: { labels: payload.radar.labels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            r: { min: 0, max: payload.radar.rating_max, ticks: { stepSize: 1 } }
                        },
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });
            });
            } // end bootRateCardCharts
        })();
        </script>
        <?php
    }

    /**
     * Enqueue Chart.js from CDN. Enqueued in the footer so the library
     * lands before `</body>` even when called from a shortcode during
     * `the_content` (which fires after `wp_head` has already flushed).
     * The inline chart-init script below waits for DOMContentLoaded
     * before reading `window.Chart`, so a footer-placed library still
     * resolves in time.
     */
    public static function enqueueChartLibrary(): void {
        wp_enqueue_script(
            'tt-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );
    }
}
