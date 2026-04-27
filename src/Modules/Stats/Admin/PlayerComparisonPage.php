<?php
namespace TT\Modules\Stats\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Shared\Admin\BackButton;

/**
 * PlayerComparisonPage — Analytics → Player Comparison (v2.20.0).
 *
 * 4-slot side-by-side comparison across any players. Cross-team is
 * fine and is the whole point of the feature: comparing a U15 LB
 * against a U13 ST is valid for scouting, transfer decisions, and
 * development conversations. Reuses PlayerStatsService helpers so
 * each player's headline numbers and breakdowns reflect the same
 * filters applied to all 4 slots.
 *
 * Age-group caveat: category weights are per-age-group, so weighted
 * overall ratings aren't perfectly apples-to-apples when comparing
 * players in different age groups. The UI shows the weighted overall
 * (honest — that's the real number the player's coaches use) with a
 * tooltip/note explaining the weighting when mixed age groups appear.
 */
class PlayerComparisonPage {

    private const CAP = 'tt_view_reports';

    private const COLORS = [ '#2271b1', '#00a32a', '#e8b624', '#b32d2e' ];

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        // Enqueue card + Chart.js assets we'll use below.
        PlayerCardView::enqueueStyles();
        wp_enqueue_script( 'tt-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [], '4.4.0', true );

        // Picked player ids (up to 4 slots). Accept ?p1=...&p2=... .
        $picked = [];
        for ( $i = 1; $i <= 4; $i++ ) {
            $pid = isset( $_GET[ "p{$i}" ] ) ? absint( $_GET[ "p{$i}" ] ) : 0;
            if ( $pid > 0 ) $picked[] = $pid;
        }
        $picked = array_values( array_unique( $picked ) );
        $picked = array_slice( $picked, 0, 4 );

        $filters = PlayerStatsService::sanitizeFilters( $_GET );

        // Resolve player objects for picked ids.
        $players = [];
        foreach ( $picked as $pid ) {
            $pl = QueryHelpers::get_player( $pid );
            if ( $pl ) $players[] = $pl;
        }

        // All players list for the slot selectors.
        global $wpdb;
        $p = $wpdb->prefix;
        $all_players = $wpdb->get_results(
            "SELECT pl.id, pl.first_name, pl.last_name, pl.team_id, t.name AS team_name, t.age_group
             FROM {$p}tt_players pl
             LEFT JOIN {$p}tt_teams t ON pl.team_id = t.id
             WHERE pl.status = 'active' AND pl.archived_at IS NULL
             ORDER BY pl.last_name, pl.first_name ASC"
        );

        ?>
        <div class="wrap">
            <?php BackButton::render( admin_url( 'admin.php?page=talenttrack' ) ); ?>
            <h1>
                <?php esc_html_e( 'Player Comparison', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-docs&topic=player-comparison' ) ); ?>" style="margin-left:12px; font-size:12px; font-weight:normal; color:#2271b1; text-decoration:none;">
                    <?php esc_html_e( '? Help on this topic', 'talenttrack' ); ?>
                </a>
            </h1>
            <p style="color:#666; max-width:760px;">
                <?php esc_html_e( 'Compare up to 4 players side-by-side. Cross-team is supported — pick any players from any team or age group.', 'talenttrack' ); ?>
            </p>

            <!-- Picker -->
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="background:#fff; border:1px solid #dcdcde; padding:16px 20px; margin:16px 0;">
                <input type="hidden" name="page" value="tt-compare" />
                <h2 style="margin:0 0 10px; font-size:15px;"><?php esc_html_e( 'Select players', 'talenttrack' ); ?></h2>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
                    <?php for ( $i = 1; $i <= 4; $i++ ) :
                        $current = $picked[ $i - 1 ] ?? 0;
                        ?>
                        <div>
                            <label style="font-size:12px; color:#555;">
                                <?php
                                /* translators: %d is slot number */
                                printf( esc_html__( 'Slot %d', 'talenttrack' ), $i );
                                ?>
                            </label>
                            <select name="p<?php echo $i; ?>" style="width:100%;">
                                <option value="0"><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                                <?php foreach ( $all_players as $pl ) :
                                    $label = sprintf(
                                        '%s, %s — %s%s',
                                        $pl->last_name,
                                        $pl->first_name,
                                        (string) ( $pl->team_name ?: __( 'No team', 'talenttrack' ) ),
                                        $pl->age_group ? " ({$pl->age_group})" : ''
                                    );
                                    ?>
                                    <option value="<?php echo (int) $pl->id; ?>" <?php selected( $current, (int) $pl->id ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Filter row -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; margin-top:12px;">
                    <div>
                        <label style="font-size:12px; color:#555;"><?php esc_html_e( 'Date from', 'talenttrack' ); ?></label>
                        <input type="date" name="date_from" value="<?php echo esc_attr( (string) ( $filters['date_from'] ?? '' ) ); ?>" style="width:100%;" />
                    </div>
                    <div>
                        <label style="font-size:12px; color:#555;"><?php esc_html_e( 'Date to', 'talenttrack' ); ?></label>
                        <input type="date" name="date_to" value="<?php echo esc_attr( (string) ( $filters['date_to'] ?? '' ) ); ?>" style="width:100%;" />
                    </div>
                    <div>
                        <label style="font-size:12px; color:#555;"><?php esc_html_e( 'Evaluation Type', 'talenttrack' ); ?></label>
                        <select name="eval_type_id" style="width:100%;">
                            <option value="0"><?php esc_html_e( 'All types', 'talenttrack' ); ?></option>
                            <?php foreach ( QueryHelpers::get_eval_types() as $t ) : ?>
                                <option value="<?php echo (int) $t->id; ?>" <?php selected( (int) ( $filters['eval_type_id'] ?? 0 ), (int) $t->id ); ?>>
                                    <?php echo esc_html( (string) $t->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p style="margin:12px 0 0;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Compare', 'talenttrack' ); ?></button>
                </p>
            </form>

            <?php if ( empty( $players ) ) : ?>
                <p style="color:#666;"><em><?php esc_html_e( 'Pick at least one player above and click Compare.', 'talenttrack' ); ?></em></p>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <?php
            // Gather data for each picked player.
            $service = new PlayerStatsService();
            $headlines   = [];
            $mains       = [];
            $trends      = [];
            $radar_sets  = [];
            $age_groups  = [];

            foreach ( $players as $pl ) {
                $pid = (int) $pl->id;
                $team = $pl->team_id ? QueryHelpers::get_team( (int) $pl->team_id ) : null;
                $age_groups[ $pid ] = $team && ! empty( $team->age_group ) ? $team->age_group : '';
                $headlines[ $pid ] = $service->getHeadlineNumbers( $pid, $filters, 5 );
                $mains[ $pid ]     = $service->getMainCategoryBreakdown( $pid, $filters );
                $trends[ $pid ]    = $service->getTrendSeries( $pid, $filters );
                $radar_sets[ $pid ]= $service->getRadarSnapshots( $pid, $filters, 1 );
            }

            $unique_ages = array_values( array_unique( array_filter( $age_groups ) ) );
            $mixed_ages = count( $unique_ages ) > 1;
            ?>

            <?php if ( $mixed_ages ) : ?>
                <div class="notice notice-info" style="margin:16px 0;">
                    <p>
                        <?php esc_html_e( 'Mixed age groups in this comparison. Overall ratings use age-group-specific category weights, so the numbers below are not perfectly apples-to-apples — they reflect what each player\'s own coaching staff uses.', 'talenttrack' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Row of FIFA cards -->
            <h2 style="margin:20px 0 10px; font-size:16px;"><?php esc_html_e( 'Cards', 'talenttrack' ); ?></h2>
            <div style="display:flex; gap:14px; flex-wrap:wrap;">
                <?php foreach ( $players as $pl ) : ?>
                    <div style="flex:0 0 auto;">
                        <?php PlayerCardView::renderCard( (int) $pl->id, 'sm', true ); ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Basic facts table -->
            <h2 style="margin:24px 0 10px; font-size:16px;"><?php esc_html_e( 'Basic facts', 'talenttrack' ); ?></h2>
            <table class="widefat striped" style="max-width:100%;">
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
                        [ __( 'Team', 'talenttrack' ),         function( $pl ) {
                            $t = $pl->team_id ? QueryHelpers::get_team( (int) $pl->team_id ) : null;
                            return $t ? (string) $t->name : '—';
                        } ],
                        [ __( 'Age group', 'talenttrack' ), function( $pl ) use ( $age_groups ) {
                            return (string) ( $age_groups[ (int) $pl->id ] ?: '—' );
                        } ],
                        [ __( 'Position(s)', 'talenttrack' ), function( $pl ) {
                            $pos = json_decode( (string) $pl->preferred_positions, true );
                            return is_array( $pos ) ? implode( ', ', $pos ) : '—';
                        } ],
                        [ __( 'Foot', 'talenttrack' ), function( $pl ) {
                            return (string) ( $pl->preferred_foot ?: '—' );
                        } ],
                        [ __( 'Jersey', 'talenttrack' ), function( $pl ) {
                            return $pl->jersey_number ? '#' . (int) $pl->jersey_number : '—';
                        } ],
                        [ __( 'Height', 'talenttrack' ), function( $pl ) {
                            return $pl->height_cm ? ( (int) $pl->height_cm . ' cm' ) : '—';
                        } ],
                        [ __( 'Weight', 'talenttrack' ), function( $pl ) {
                            return $pl->weight_kg ? ( (int) $pl->weight_kg . ' kg' ) : '—';
                        } ],
                        [ __( 'Date of birth', 'talenttrack' ), function( $pl ) {
                            return (string) ( $pl->date_of_birth ?: '—' );
                        } ],
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

            <!-- Headline numbers -->
            <h2 style="margin:24px 0 10px; font-size:16px;"><?php esc_html_e( 'Headline numbers', 'talenttrack' ); ?></h2>
            <table class="widefat striped">
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
                                $h = $headlines[ $pid ] ?? [];
                                $val = $h[ $key ] ?? null;
                                $display = $val === null ? '—' : ( is_numeric( $val ) ? (string) $val : esc_html( (string) $val ) );
                                ?>
                                <td style="font-variant-numeric:tabular-nums; font-weight:600;"><?php echo $display; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Main category breakdown -->
            <h2 style="margin:24px 0 10px; font-size:16px;"><?php esc_html_e( 'Main category averages', 'talenttrack' ); ?></h2>
            <?php self::renderMainBreakdownTable( $players, $mains ); ?>

            <!-- Overlay radar chart -->
            <h2 style="margin:24px 0 10px; font-size:16px;"><?php esc_html_e( 'Radar overlay', 'talenttrack' ); ?></h2>
            <div style="background:#fff; border:1px solid #dcdcde; padding:18px; max-width:700px;">
                <div style="position:relative; height:400px;">
                    <canvas id="tt-compare-radar"></canvas>
                </div>
            </div>

            <!-- Overlay trend chart -->
            <h2 style="margin:24px 0 10px; font-size:16px;"><?php esc_html_e( 'Trend overlay', 'talenttrack' ); ?></h2>
            <div style="background:#fff; border:1px solid #dcdcde; padding:18px;">
                <div style="position:relative; height:320px;">
                    <canvas id="tt-compare-trend"></canvas>
                </div>
            </div>

        </div>

        <?php self::renderChartScripts( $players, $trends, $radar_sets ); ?>
        <?php
    }

    // Helpers

    private static function renderMainBreakdownTable( array $players, array $mains ): void {
        // Collect union of category keys across all players (each player's
        // breakdown may reference different categories if they've been rated
        // on different subsets).
        $all_keys = [];
        foreach ( $mains as $rows ) {
            foreach ( (array) $rows as $row ) {
                $key = (string) ( $row['category_key'] ?? $row['key'] ?? '' );
                if ( $key === '' ) continue;
                if ( ! isset( $all_keys[ $key ] ) ) {
                    $all_keys[ $key ] = (string) ( $row['label'] ?? $key );
                }
            }
        }

        if ( empty( $all_keys ) ) {
            echo '<p><em>' . esc_html__( 'No rated evaluations in the current filter window.', 'talenttrack' ) . '</em></p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Category', 'talenttrack' ); ?></th>
                    <?php foreach ( $players as $pl ) : ?>
                        <th><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $all_keys as $key => $label ) :
                    $translated_label = EvalCategoriesRepository::displayLabel( $label );
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?php echo esc_html( $translated_label ); ?></td>
                        <?php foreach ( $players as $pl ) :
                            $pid = (int) $pl->id;
                            $row_for_player = null;
                            foreach ( (array) ( $mains[ $pid ] ?? [] ) as $r ) {
                                $rk = (string) ( $r['category_key'] ?? $r['key'] ?? '' );
                                if ( $rk === $key ) { $row_for_player = $r; break; }
                            }
                            $val = $row_for_player['alltime'] ?? ( $row_for_player['value'] ?? null );
                            ?>
                            <td style="font-variant-numeric:tabular-nums;"><?php echo $val === null ? '—' : esc_html( (string) $val ); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderChartScripts( array $players, array $trends, array $radar_sets ): void {
        // Build radar dataset: union of labels across players, one series
        // per player.
        $radar_labels_union = [];
        $radar_per_player = [];
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            $snap = $radar_sets[ $pid ][0] ?? null;
            if ( $snap && ! empty( $snap['labels'] ) ) {
                foreach ( (array) $snap['labels'] as $lbl ) {
                    $key = (string) $lbl;
                    if ( ! in_array( $key, $radar_labels_union, true ) ) {
                        $radar_labels_union[] = $key;
                    }
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

        // Translate radar labels once via repository display helper.
        $radar_labels_display = [];
        foreach ( $radar_labels_union as $lbl ) {
            $radar_labels_display[] = EvalCategoriesRepository::displayLabel( (string) $lbl );
        }

        // Build trend dataset: union of date labels, line per player.
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
                // t['series'] is an array of series; we want the "overall"
                // one. Use first series as fallback.
                $overall = $t['series'][0] ?? null;
                if ( $overall && ! empty( $overall['points'] ) ) {
                    $map = array_combine( $t['labels'], $overall['points'] );
                    foreach ( $trend_labels_union as $d ) {
                        $points[] = isset( $map[ $d ] ) ? $map[ $d ] : null;
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

            // Radar
            var radarLabels = <?php echo wp_json_encode( $radar_labels_display ); ?>;
            var radarSets = <?php echo wp_json_encode( $radar_datasets ); ?>;
            if (radarLabels.length > 0 && radarSets.length > 0) {
                new Chart(document.getElementById('tt-compare-radar').getContext('2d'), {
                    type: 'radar',
                    data: {
                        labels: radarLabels,
                        datasets: radarSets.map(function(s){
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

            // Trend
            var trendLabels = <?php echo wp_json_encode( $trend_labels_union ); ?>;
            var trendSets = <?php echo wp_json_encode( $trend_datasets ); ?>;
            if (trendLabels.length > 0 && trendSets.length > 0) {
                new Chart(document.getElementById('tt-compare-trend').getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: trendSets.map(function(s){
                            return {
                                label: s.label,
                                data: s.points,
                                borderColor: s.color,
                                backgroundColor: s.color,
                                tension: 0.25,
                                spanGaps: true,
                                pointRadius: 3
                            };
                        })
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        scales: {
                            y: { min: 0, max: ratingMax, ticks: { stepSize: 1 } },
                            x: { ticks: { maxTicksLimit: 10, autoSkip: true } }
                        },
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }
        })();
        </script>
        <?php
    }
}
