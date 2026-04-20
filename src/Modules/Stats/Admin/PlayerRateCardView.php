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

    /* ═══════════════ Filters ═══════════════ */

    private static function renderFilters( array $filters, array $eval_types, string $base_url, int $player_id ): void {
        $from = $filters['date_from'] ?? '';
        $to   = $filters['date_to']   ?? '';
        $tid  = (int) ( $filters['eval_type_id'] ?? 0 );
        ?>
        <form method="get" action="<?php echo esc_url( $base_url ); ?>" style="margin:16px 0; padding:12px 16px; background:#f6f7f7; border:1px solid #dcdcde;">
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

    /* ═══════════════ Headline ═══════════════ */

    private static function renderHeadline( array $h ): void {
        ?>
        <div style="display:flex; gap:16px; flex-wrap:wrap; margin:10px 0 20px;">
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

    /* ═══════════════ Main category breakdown ═══════════════ */

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

    /* ═══════════════ Charts ═══════════════ */

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
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:24px;">
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
        })();
        </script>
        <?php
    }

    /**
     * Enqueue Chart.js from CDN on admin pages that render the rate card.
     * Called by the two entry points via a shared helper.
     */
    public static function enqueueChartLibrary(): void {
        wp_enqueue_script(
            'tt-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            false
        );
    }
}
