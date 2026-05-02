<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;

/**
 * FrontendComparisonView — the "Player comparison" tile destination
 * (analytics group).
 *
 * v3.0.0 slice 5. Streamlined mobile-first version of the admin
 * PlayerComparisonPage. Slot pickers (up to 4), FIFA card row, basic
 * facts table, headline numbers table, main-category breakdown.
 *
 * #0077 M6 — radar overlay + trend overlay charts brought to parity
 * with the admin PlayerComparisonPage. Same Chart.js dataset shape;
 * frontend now ships the multi-dataset radar so coaches don't have to
 * jump to wp-admin for at-a-glance multi-axis profile compare.
 *
 * Permission gate: tt_view_reports. Observer role has this cap.
 */
class FrontendComparisonView extends FrontendViewBase {

    /** Chart palette; matches admin PlayerComparisonPage. */
    private const COLORS = [ '#2271b1', '#00a32a', '#e8b624', '#b32d2e' ];

    public static function render(): void {
        self::enqueueAssets();
        \TT\Modules\Stats\Admin\PlayerCardView::enqueueStyles();
        // #0077 M6 — Chart.js for radar + trend overlay parity.
        wp_enqueue_script(
            'tt-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        self::renderHeader( __( 'Player comparison', 'talenttrack' ) );

        // #0011 — feature gate. Player comparison is a Standard-tier
        // feature; on Free, surface an upgrade nudge instead of the
        // analytics body.
        if ( \TT\Core\ModuleRegistry::isEnabled( 'TT\\Modules\\License\\LicenseModule' )
             && class_exists( '\TT\Modules\License\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::can( 'player_comparison' )
        ) {
            echo \TT\Modules\License\Admin\UpgradeNudge::inline(
                __( 'Player comparison', 'talenttrack' ),
                'standard'
            );
            return;
        }

        // Pick up to 4 players from ?p1, ?p2, ?p3, ?p4
        $picked = [];
        for ( $i = 1; $i <= 4; $i++ ) {
            $pid = isset( $_GET[ "p{$i}" ] ) ? absint( $_GET[ "p{$i}" ] ) : 0;
            if ( $pid > 0 ) $picked[] = $pid;
        }
        $picked = array_values( array_unique( $picked ) );
        $picked = array_slice( $picked, 0, 4 );

        $filters = PlayerStatsService::sanitizeFilters( $_GET );

        // Resolve picked players
        $players = [];
        foreach ( $picked as $pid ) {
            $pl = QueryHelpers::get_player( $pid );
            if ( $pl ) $players[] = $pl;
        }

        // All players for slot selectors (cross-club — observer's scope).
        // Demo-mode scope applied so demo runs only show demo-tagged players.
        global $wpdb;
        $p = $wpdb->prefix;
        $player_scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        $all_players = $wpdb->get_results(
            "SELECT pl.id, pl.first_name, pl.last_name, pl.team_id, t.name AS team_name, t.age_group
             FROM {$p}tt_players pl
             LEFT JOIN {$p}tt_teams t ON pl.team_id = t.id
             WHERE pl.status = 'active' AND pl.archived_at IS NULL {$player_scope}
             ORDER BY pl.last_name, pl.first_name ASC"
        );

        ?>
        <p style="color:#666; max-width:760px; margin:0 0 16px;">
            <?php esc_html_e( 'Compare up to 4 players side-by-side. Cross-team is supported — pick any players from any team or age group.', 'talenttrack' ); ?>
        </p>

        <form method="get" action="" style="background:#fff; border:1px solid #e5e7ea; border-radius:10px; padding:16px 20px; margin:16px 0;">
            <?php
            // Preserve tt_view + any other non-filter args
            foreach ( $_GET as $k => $v ) {
                if ( preg_match( '/^p[1-4]$/', (string) $k ) ) continue;
                if ( in_array( $k, [ 'date_from', 'date_to', 'eval_type_id' ], true ) ) continue;
                if ( is_string( $v ) ) {
                    echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( wp_unslash( $v ) ) . '" />';
                }
            }
            ?>
            <h3 style="margin:0 0 10px; font-size:15px;"><?php esc_html_e( 'Select players', 'talenttrack' ); ?></h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
                <?php for ( $i = 1; $i <= 4; $i++ ) :
                    $current = $picked[ $i - 1 ] ?? 0;
                    echo PlayerSearchPickerComponent::render( [
                        'name'        => 'p' . $i,
                        'label'       => sprintf( /* translators: %d is slot number */ __( 'Slot %d', 'talenttrack' ), $i ),
                        'players'     => $all_players,
                        'selected'    => (int) $current,
                        'placeholder' => __( 'Type a name to search…', 'talenttrack' ),
                    ] );
                endfor; ?>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; margin-top:12px;">
                <div>
                    <label style="font-size:12px; color:#555; display:block;"><?php esc_html_e( 'Date from', 'talenttrack' ); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( (string) ( $filters['date_from'] ?? '' ) ); ?>" style="width:100%; padding:6px;" />
                </div>
                <div>
                    <label style="font-size:12px; color:#555; display:block;"><?php esc_html_e( 'Date to', 'talenttrack' ); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( (string) ( $filters['date_to'] ?? '' ) ); ?>" style="width:100%; padding:6px;" />
                </div>
                <div>
                    <label style="font-size:12px; color:#555; display:block;"><?php esc_html_e( 'Evaluation Type', 'talenttrack' ); ?></label>
                    <select name="eval_type_id" style="width:100%; padding:6px;">
                        <option value="0"><?php esc_html_e( 'All types', 'talenttrack' ); ?></option>
                        <?php foreach ( QueryHelpers::get_eval_types() as $t ) : ?>
                            <option value="<?php echo (int) $t->id; ?>" <?php selected( (int) ( $filters['eval_type_id'] ?? 0 ), (int) $t->id ); ?>>
                                <?php echo esc_html( (string) $t->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p style="margin:14px 0 0;">
                <button type="submit" class="tt-btn tt-btn-primary" style="padding:8px 16px;"><?php esc_html_e( 'Compare', 'talenttrack' ); ?></button>
            </p>
        </form>

        <?php
        if ( empty( $players ) ) {
            echo '<p style="color:#666;"><em>' . esc_html__( 'Pick at least one player above and click Compare.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $service = new PlayerStatsService();
        $headlines = [];
        $mains = [];
        $age_groups = [];
        $trends = [];
        $radar_sets = [];
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            $team = $pl->team_id ? QueryHelpers::get_team( (int) $pl->team_id ) : null;
            $age_groups[ $pid ] = $team && ! empty( $team->age_group ) ? $team->age_group : '';
            $headlines[ $pid ] = $service->getHeadlineNumbers( $pid, $filters, 5 );
            $mains[ $pid ] = $service->getMainCategoryBreakdown( $pid, $filters );
            // #0077 M6 — radar + trend datasets, same calls as admin.
            $trends[ $pid ]     = $service->getTrendSeries( $pid, $filters );
            $radar_sets[ $pid ] = $service->getRadarSnapshots( $pid, $filters, 1 );
        }
        $unique_ages = array_values( array_unique( array_filter( $age_groups ) ) );
        $mixed_ages = count( $unique_ages ) > 1;
        ?>

        <?php if ( $mixed_ages ) : ?>
            <div style="background:#e7f0f9; border-left:4px solid #2271b1; padding:10px 14px; margin:16px 0; font-size:13px;">
                <?php esc_html_e( 'Mixed age groups in this comparison. Overall ratings use age-group-specific category weights, so the numbers below are not perfectly apples-to-apples — they reflect what each player\'s own coaching staff uses.', 'talenttrack' ); ?>
            </div>
        <?php endif; ?>

        <!-- FIFA card row -->
        <h3 style="margin:24px 0 10px; font-size:15px;"><?php esc_html_e( 'Cards', 'talenttrack' ); ?></h3>
        <div style="display:flex; gap:14px; flex-wrap:wrap; overflow-x:auto; padding-bottom:8px;">
            <?php foreach ( $players as $pl ) : ?>
                <div style="flex:0 0 auto;">
                    <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $pl->id, 'sm', true ); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Basic facts -->
        <h3 style="margin:28px 0 10px; font-size:15px;"><?php esc_html_e( 'Basic facts', 'talenttrack' ); ?></h3>
        <div style="overflow-x:auto;">
            <table class="tt-table" style="width:100%; background:#fff;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Attribute', 'talenttrack' ); ?></th>
                        <?php foreach ( $players as $pl ) : ?>
                            <th><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = [
                        [ __( 'Team', 'talenttrack' ),         function( $pl ) { $t = $pl->team_id ? QueryHelpers::get_team( (int) $pl->team_id ) : null; return $t ? (string) $t->name : '—'; } ],
                        [ __( 'Age group', 'talenttrack' ),    function( $pl ) use ( $age_groups ) { return (string) ( $age_groups[ (int) $pl->id ] ?: '—' ); } ],
                        [ __( 'Position(s)', 'talenttrack' ),  function( $pl ) { $pos = json_decode( (string) $pl->preferred_positions, true ); return is_array( $pos ) ? implode( ', ', $pos ) : '—'; } ],
                        [ __( 'Foot', 'talenttrack' ),         function( $pl ) { return (string) ( $pl->preferred_foot ?: '—' ); } ],
                        [ __( 'Jersey', 'talenttrack' ),       function( $pl ) { return $pl->jersey_number ? '#' . (int) $pl->jersey_number : '—'; } ],
                        [ __( 'Height', 'talenttrack' ),       function( $pl ) { return $pl->height_cm ? ( (int) $pl->height_cm . ' cm' ) : '—'; } ],
                    ];
                    foreach ( $rows as [ $label, $fn ] ) : ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo esc_html( $label ); ?></td>
                            <?php foreach ( $players as $pl ) : ?>
                                <td><?php echo esc_html( (string) $fn( $pl ) ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Headline numbers -->
        <h3 style="margin:28px 0 10px; font-size:15px;"><?php esc_html_e( 'Headline numbers', 'talenttrack' ); ?></h3>
        <div style="overflow-x:auto;">
            <table class="tt-table" style="width:100%; background:#fff;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Metric', 'talenttrack' ); ?></th>
                        <?php foreach ( $players as $pl ) : ?>
                            <th><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $metrics = [
                        [ __( 'Most recent', 'talenttrack' ), 'recent' ],
                        [ __( 'Rolling (last 5)', 'talenttrack' ), 'rolling' ],
                        [ __( 'All-time', 'talenttrack' ), 'alltime' ],
                        [ __( 'Evaluations', 'talenttrack' ), 'count' ],
                    ];
                    foreach ( $metrics as [ $label, $key ] ) : ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo esc_html( $label ); ?></td>
                            <?php foreach ( $players as $pl ) :
                                $pid = (int) $pl->id;
                                $val = $headlines[ $pid ][ $key ] ?? null;
                                $display = $val === null ? '—' : ( is_numeric( $val ) ? (string) $val : esc_html( (string) $val ) );
                                ?>
                                <td style="font-variant-numeric:tabular-nums; font-weight:600;"><?php echo $display; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Main category breakdown -->
        <h3 style="margin:28px 0 10px; font-size:15px;"><?php esc_html_e( 'Main category averages', 'talenttrack' ); ?></h3>
        <?php self::renderMainBreakdown( $players, $mains ); ?>

        <!-- #0077 M6 — radar overlay + trend overlay (parity with admin) -->
        <h3 style="margin:28px 0 10px; font-size:15px;"><?php esc_html_e( 'Radar profile', 'talenttrack' ); ?></h3>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:10px; padding:16px; height:340px;">
            <canvas id="tt-fcompare-radar"></canvas>
        </div>

        <h3 style="margin:28px 0 10px; font-size:15px;"><?php esc_html_e( 'Rating trend', 'talenttrack' ); ?></h3>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:10px; padding:16px; height:300px;">
            <canvas id="tt-fcompare-trend"></canvas>
        </div>

        <?php self::renderChartScripts( $players, $trends, $radar_sets ); ?>
        <?php
    }

    /**
     * #0077 M6 — radar + trend overlay scripts, mirrors
     * PlayerComparisonPage::renderChartScripts. Single-axis trend
     * (no per-category lines) to keep the frontend chart readable on
     * mobile; radar is multi-dataset so all picked players overlay.
     *
     * @param array<int,object> $players
     * @param array<int,array<string,mixed>> $trends
     * @param array<int,array<int,array<string,mixed>>> $radar_sets
     */
    private static function renderChartScripts( array $players, array $trends, array $radar_sets ): void {
        $radar_labels_union = [];
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            $snap = $radar_sets[ $pid ][0] ?? null;
            if ( $snap && ! empty( $snap['labels'] ) ) {
                foreach ( (array) $snap['labels'] as $lbl ) {
                    $key = (string) $lbl;
                    if ( ! in_array( $key, $radar_labels_union, true ) ) $radar_labels_union[] = $key;
                }
            }
        }
        $radar_datasets = [];
        foreach ( $players as $i => $pl ) {
            $pid = (int) $pl->id;
            $snap = $radar_sets[ $pid ][0] ?? null;
            $values = [];
            if ( $snap && ! empty( $snap['labels'] ) && ! empty( $snap['values'] ) ) {
                $map = array_combine( $snap['labels'], $snap['values'] );
                foreach ( $radar_labels_union as $lbl ) {
                    $values[] = isset( $map[ $lbl ] ) ? (float) $map[ $lbl ] : null;
                }
            } else {
                $values = array_fill( 0, count( $radar_labels_union ), null );
            }
            $radar_datasets[] = [
                'label'  => QueryHelpers::player_display_name( $pl ),
                'values' => $values,
                'color'  => self::COLORS[ $i % count( self::COLORS ) ],
            ];
        }
        $radar_labels_display = [];
        foreach ( $radar_labels_union as $lbl ) {
            $radar_labels_display[] = EvalCategoriesRepository::displayLabel( (string) $lbl );
        }

        $trend_labels_union = [];
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            $t = $trends[ $pid ] ?? [];
            if ( ! empty( $t['labels'] ) ) {
                foreach ( (array) $t['labels'] as $d ) {
                    if ( ! in_array( (string) $d, $trend_labels_union, true ) ) $trend_labels_union[] = (string) $d;
                }
            }
        }
        sort( $trend_labels_union );

        $trend_datasets = [];
        foreach ( $players as $i => $pl ) {
            $pid = (int) $pl->id;
            $t = $trends[ $pid ] ?? [];
            $points = [];
            if ( ! empty( $t['labels'] ) && ! empty( $t['series'] ) ) {
                $overall = $t['series'][0] ?? null;
                if ( $overall && ! empty( $overall['points'] ) ) {
                    $map = array_combine( $t['labels'], $overall['points'] );
                    foreach ( $trend_labels_union as $d ) {
                        $points[] = $map[ $d ] ?? null;
                    }
                }
            }
            if ( empty( $points ) ) {
                $points = array_fill( 0, count( $trend_labels_union ), null );
            }
            $trend_datasets[] = [
                'label'  => QueryHelpers::player_display_name( $pl ),
                'points' => $points,
                'color'  => self::COLORS[ $i % count( self::COLORS ) ],
            ];
        }

        $rating_max = (float) QueryHelpers::get_config( 'rating_max', '5' );
        ?>
        <script>
        (function(){
            if (typeof Chart === 'undefined') return;
            var ratingMax = <?php echo wp_json_encode( $rating_max ); ?>;

            var radarLabels = <?php echo wp_json_encode( $radar_labels_display ); ?>;
            var radarSets = <?php echo wp_json_encode( $radar_datasets ); ?>;
            var radarEl = document.getElementById('tt-fcompare-radar');
            if (radarEl && radarLabels.length > 0 && radarSets.length > 0) {
                new Chart(radarEl.getContext('2d'), {
                    type: 'radar',
                    data: {
                        labels: radarLabels,
                        datasets: radarSets.map(function (s) {
                            return {
                                label: s.label,
                                data: s.values,
                                borderColor: s.color,
                                backgroundColor: s.color + '22',
                                pointBackgroundColor: s.color,
                                spanGaps: true
                            };
                        })
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        scales: { r: { min: 0, max: ratingMax, ticks: { stepSize: 1 } } },
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            var trendLabels = <?php echo wp_json_encode( $trend_labels_union ); ?>;
            var trendSets = <?php echo wp_json_encode( $trend_datasets ); ?>;
            var trendEl = document.getElementById('tt-fcompare-trend');
            if (trendEl && trendLabels.length > 0 && trendSets.length > 0) {
                new Chart(trendEl.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: trendSets.map(function (s) {
                            return {
                                label: s.label,
                                data: s.points,
                                borderColor: s.color,
                                backgroundColor: s.color + '22',
                                pointBackgroundColor: s.color,
                                spanGaps: true,
                                pointRadius: 3
                            };
                        })
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        scales: {
                            y: { min: 0, max: ratingMax, ticks: { stepSize: 1 } },
                            x: { ticks: { maxTicksLimit: 8, autoSkip: true } }
                        },
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Main-category breakdown table. Collect all unique main-category
     * labels across the 4 players, then render one row per category
     * with per-player averages.
     */
    private static function renderMainBreakdown( array $players, array $mains ): void {
        // Collect unique main category labels across all players
        $all_cats = [];
        foreach ( $mains as $pid => $data ) {
            foreach ( $data as $row ) {
                $key = (string) ( $row->main_label ?? $row->label ?? '' );
                if ( $key !== '' ) $all_cats[ $key ] = $key;
            }
        }

        if ( empty( $all_cats ) ) {
            echo '<p><em>' . esc_html__( 'No category data yet for these filters.', 'talenttrack' ) . '</em></p>';
            return;
        }
        ?>
        <div style="overflow-x:auto;">
            <table class="tt-table" style="width:100%; background:#fff;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Category', 'talenttrack' ); ?></th>
                        <?php foreach ( $players as $pl ) : ?>
                            <th><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( array_keys( $all_cats ) as $cat_label ) : ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $cat_label ) ); ?></td>
                            <?php foreach ( $players as $pl ) :
                                $pid = (int) $pl->id;
                                $val = '—';
                                foreach ( ( $mains[ $pid ] ?? [] ) as $row ) {
                                    $key = (string) ( $row->main_label ?? $row->label ?? '' );
                                    if ( $key === $cat_label ) {
                                        $avg = $row->avg ?? $row->average ?? null;
                                        if ( $avg !== null ) $val = number_format( (float) $avg, 2 );
                                        break;
                                    }
                                }
                                ?>
                                <td style="font-variant-numeric:tabular-nums;"><?php echo esc_html( $val ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
