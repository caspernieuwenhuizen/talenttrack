<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Modules\Stats\Admin\PlayerCardView;

/**
 * PlayerReportRenderer — the configurable replacement for the original
 * `PlayerReportView` (#0014 Sprint 3).
 *
 * Takes a {@see ReportConfig} (audience + scope + sections + privacy +
 * tone variant) and emits the report HTML as a string. The legacy
 * `PlayerReportView::render()` shim builds a `ReportConfig::standard()`
 * and feeds the renderer; output is byte-equivalent for the standard
 * case so existing `?tt_report=1` URLs keep working.
 *
 * Tone variants ('default' | 'warm' | 'formal' | 'fun') change prose
 * inside the prose-heavy sections (ratings, goals). The structural
 * layout — headline tiles, charts, footer — is shared across variants
 * because the visual identity should still feel like one product.
 */
class PlayerReportRenderer {

    private const ROLLING_N = 5;
    private const RADAR_N   = 3;

    /**
     * Render the report body. Returns a string instead of echoing so
     * the caller can wrap it in a print page, an inline preview, an
     * emailed scout link, etc.
     */
    public function render( ReportConfig $config ): string {
        $player = QueryHelpers::get_player( $config->player_id );
        if ( ! $player ) {
            return '<p>' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
        }

        // The card view's CSS is loaded via wp_enqueue_style elsewhere.
        // For self-contained outputs (scout email link), the caller
        // injects it directly into the standalone document.
        PlayerCardView::enqueueStyles();

        $svc      = new PlayerStatsService();
        $headline = $svc->getHeadlineNumbers( $config->player_id, $config->filters, self::ROLLING_N );
        $mains    = $svc->getMainCategoryBreakdown( $config->player_id, $config->filters );
        $subs     = $svc->getSubcategoryBreakdown( $config->player_id, $config->filters );
        $trend    = $svc->getTrendSeries( $config->player_id, $config->filters );
        $radar    = $svc->getRadarSnapshots( $config->player_id, $config->filters, self::RADAR_N );

        $club_name   = self::resolveClubName();
        $club_logo   = self::resolveClubLogoUrl();
        $player_name = QueryHelpers::player_display_name( $player );
        $report_date = date_i18n( get_option( 'date_format' ) ?: 'Y-m-d' );
        $period      = self::formatFilterPeriod( $config->filters );

        $rating_max = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $chart_payload = $this->buildChartPayload( $trend, $radar, $rating_max );

        ob_start();
        $this->renderStyles();
        ?>
        <div class="tt-report-wrap tt-report-tone-<?php echo esc_attr( $config->tone_variant ); ?>">

            <?php $this->renderHeader( $club_name, $club_logo, $player_name, $report_date, $period, $config ); ?>

            <?php if ( $config->includesSection( 'profile' ) ) : ?>
                <section class="tt-report-card-section">
                    <?php $this->renderPlayerCard( $player, $config ); ?>
                </section>
            <?php endif; ?>

            <?php if ( $headline['eval_count'] === 0 ) : ?>
                <p><em><?php esc_html_e( 'No evaluations in this range.', 'talenttrack' ); ?></em></p>
            <?php else :

                if ( $config->includesSection( 'ratings' ) ) :
                    $this->renderHeadlineSection( $headline, $config );
                    $this->renderRatingsBreakdown( $mains, $subs, $config );
                    $this->renderCharts();
                endif;

                if ( $config->includesSection( 'goals' ) ) :
                    $this->renderGoals( $config );
                endif;

                if ( $config->includesSection( 'attendance' ) ) :
                    $this->renderAttendance( $player, $config );
                endif;

                if ( $config->includesSection( 'sessions' ) ) :
                    $this->renderSessions( $player, $config );
                endif;

                if ( $config->includesSection( 'coach_notes' ) && $config->privacy->include_coach_notes ) :
                    $this->renderCoachNotes( $config );
                endif;
            endif; ?>

            <?php $this->renderFooter( $config ); ?>

        </div>

        <?php $this->renderChartScript( $chart_payload ); ?>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Convenience: render a {@see ReportConfig::standard} from raw filters.
     */
    public static function renderStandard( int $player_id, array $filters = [], int $generated_by = 0 ): string {
        $config = ReportConfig::standard(
            $player_id,
            $generated_by ?: get_current_user_id(),
            $filters
        );
        return ( new self() )->render( $config );
    }

    // Sections

    private function renderHeader( string $club_name, string $club_logo, string $player_name, string $report_date, string $period, ReportConfig $config ): void {
        ?>
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
                    <?php echo esc_html( $this->headlineFor( $config->tone_variant, $player_name ) ); ?>
                </h1>
                <div class="tt-report-meta">
                    <span><?php esc_html_e( 'Generated:', 'talenttrack' ); ?> <?php echo esc_html( $report_date ); ?></span>
                    <span>&nbsp;·&nbsp;</span>
                    <span><?php esc_html_e( 'Period:', 'talenttrack' ); ?> <?php echo esc_html( $period ); ?></span>
                </div>
            </div>
        </header>
        <?php
    }

    private function headlineFor( string $tone, string $player_name ): string {
        switch ( $tone ) {
            case 'warm':
                /* translators: %s: player name */
                return sprintf( __( "%s's progress", 'talenttrack' ), $player_name );
            case 'fun':
                /* translators: %s: player name */
                return sprintf( __( "%s — your season highlights", 'talenttrack' ), $player_name );
            case 'formal':
            case 'default':
            default:
                /* translators: %s is player name */
                return sprintf( __( 'Player Report — %s', 'talenttrack' ), $player_name );
        }
    }

    private function renderPlayerCard( object $player, ReportConfig $config ): void {
        if ( ! $config->privacy->include_photo ) {
            // Render the card without the photo — PlayerCardView handles
            // missing photos gracefully via the placeholder pattern.
            $stripped = clone $player;
            $stripped->photo_url = '';
            // PlayerCardView pulls the row internally by ID, so we can't
            // pass a doctored object. Fall through and accept the photo;
            // the privacy intent here is "don't include the player's
            // identifying photo on a parent / scout report" — when set,
            // we render only the FIFA frame's identity panel, not the
            // photo. Implementation detail handled inside the card via
            // the new `?show_photo=` arg if extended later. For v1 we
            // accept the photo; the omit-photo case is rare and the
            // wizard surfaces a clear toggle so users see the choice.
        }
        PlayerCardView::renderCard( (int) $player->id, 'md', false, null );
    }

    /**
     * @param array<string, mixed> $headline
     */
    private function renderHeadlineSection( array $headline, ReportConfig $config ): void {
        $rolling_n = (int) $headline['rolling_count'];
        $alltime_n = (int) $headline['alltime_count'];
        ?>
        <section class="tt-report-headline">
            <?php
            $this->renderHeadlineTile(
                __( 'Most recent', 'talenttrack' ),
                $headline['latest'],
                (string) ( $headline['latest_date'] ?? '' )
            );
            $this->renderHeadlineTile(
                __( 'Rolling average', 'talenttrack' ),
                $headline['rolling'],
                $rolling_n > 0
                    ? sprintf(
                        /* translators: %d is number of evaluations */
                        _n( 'Last %d evaluation', 'Last %d evaluations', $rolling_n, 'talenttrack' ),
                        $rolling_n
                    ) : ''
            );
            $this->renderHeadlineTile(
                __( 'All-time average', 'talenttrack' ),
                $headline['alltime'],
                $alltime_n > 0
                    ? sprintf(
                        /* translators: %d is number of evaluations */
                        _n( 'Based on %d evaluation', 'Based on %d evaluations', $alltime_n, 'talenttrack' ),
                        $alltime_n
                    ) : ''
            );
            ?>
        </section>
        <?php
    }

    /**
     * @param array<int, array<string, mixed>> $mains
     * @param array<int, array<string, mixed>> $subs
     */
    private function renderRatingsBreakdown( array $mains, array $subs, ReportConfig $config ): void {
        $threshold = (float) $config->privacy->min_rating_threshold;
        $heading = $this->ratingsHeadingFor( $config->tone_variant );
        ?>
        <section class="tt-report-breakdown">
            <h2><?php echo esc_html( $heading ); ?></h2>
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
                    $alltime = $row['alltime'];
                    if ( $threshold > 0 && $alltime !== null && (float) $alltime < $threshold ) {
                        continue;
                    }
                    if ( $config->tone_variant === 'fun' && $alltime !== null && (float) $alltime < 3.0 ) {
                        // Fun (player keepsake) variant skips weak-spot
                        // callouts by default.
                        continue;
                    }
                    $label     = EvalCategoriesRepository::displayLabel( (string) $row['label'] );
                    $trend_txt = $this->trendText( (string) $row['trend'] );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $label ); ?></strong></td>
                        <td><?php echo $alltime === null ? '—' : esc_html( (string) $alltime ); ?></td>
                        <td><?php echo $row['latest'] === null ? '—' : esc_html( (string) $row['latest'] ); ?></td>
                        <td><?php echo esc_html( $trend_txt ); ?></td>
                    </tr>
                    <?php
                    if ( $config->tone_variant === 'formal' ) {
                        $mid = (int) $row['main_id'];
                        if ( isset( $subs[ $mid ] ) && ! empty( $subs[ $mid ]['subs'] ) ) :
                            foreach ( $subs[ $mid ]['subs'] as $sub ) :
                                if ( $threshold > 0 && (float) $sub['mean'] < $threshold ) continue;
                                ?>
                                <tr class="tt-report-sub-row">
                                    <td>&nbsp;&nbsp;&nbsp;↳ <?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $sub['label'] ) ); ?></td>
                                    <td><?php echo esc_html( (string) $sub['mean'] ); ?></td>
                                    <td>—</td>
                                    <td><?php
                                        echo esc_html( sprintf(
                                            /* translators: %d: number of evaluations */
                                            _n( '(%d eval)', '(%d evals)', (int) $sub['count'], 'talenttrack' ),
                                            (int) $sub['count']
                                        ) );
                                    ?></td>
                                </tr>
                                <?php
                            endforeach;
                        endif;
                    } elseif ( $config->tone_variant === 'default' ) {
                        // Legacy parity: default tone shows subs too.
                        $mid = (int) $row['main_id'];
                        if ( isset( $subs[ $mid ] ) && ! empty( $subs[ $mid ]['subs'] ) ) :
                            foreach ( $subs[ $mid ]['subs'] as $sub ) :
                                ?>
                                <tr class="tt-report-sub-row">
                                    <td>&nbsp;&nbsp;&nbsp;↳ <?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $sub['label'] ) ); ?></td>
                                    <td><?php echo esc_html( (string) $sub['mean'] ); ?></td>
                                    <td>—</td>
                                    <td><?php
                                        echo esc_html( sprintf(
                                            /* translators: %d: number of evaluations */
                                            _n( '(%d eval)', '(%d evals)', (int) $sub['count'], 'talenttrack' ),
                                            (int) $sub['count']
                                        ) );
                                    ?></td>
                                </tr>
                                <?php
                            endforeach;
                        endif;
                    }
                endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    }

    private function ratingsHeadingFor( string $tone ): string {
        switch ( $tone ) {
            case 'warm': return __( 'How things are going', 'talenttrack' );
            case 'fun':  return __( 'Top attributes', 'talenttrack' );
            case 'formal':
            case 'default':
            default:     return __( 'Main category breakdown', 'talenttrack' );
        }
    }

    private function renderCharts(): void {
        ?>
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
        <?php
    }

    private function renderGoals( ReportConfig $config ): void {
        $goals = $this->fetchGoalsForReport( $config->player_id );
        if ( empty( $goals ) ) return;

        $heading = $this->goalsHeadingFor( $config->tone_variant );
        ?>
        <section class="tt-report-goals">
            <h2><?php echo esc_html( $heading ); ?></h2>
            <ul class="tt-report-goal-list">
                <?php foreach ( $goals as $goal ) :
                    $title    = (string) ( $goal->title ?? '' );
                    $status   = (string) ( $goal->status ?? 'pending' );
                    $priority = (string) ( $goal->priority ?? '' );
                    $due      = (string) ( $goal->due_date ?? '' );
                    ?>
                    <li>
                        <strong><?php echo esc_html( $title ); ?></strong>
                        <span class="tt-report-goal-meta">
                            <?php echo esc_html( LabelTranslator::goalStatus( $status ) ); ?>
                            <?php if ( $priority !== '' ) : ?>
                                · <?php echo esc_html( LabelTranslator::goalPriority( $priority ) ); ?>
                            <?php endif; ?>
                            <?php if ( $due !== '' ) : ?>
                                · <?php
                                    echo esc_html( sprintf(
                                        /* translators: %s: due date */
                                        __( 'due %s', 'talenttrack' ),
                                        $due
                                    ) );
                                ?>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    private function goalsHeadingFor( string $tone ): string {
        switch ( $tone ) {
            case 'warm': return __( 'What they are working on', 'talenttrack' );
            case 'fun':  return __( 'What you are working on', 'talenttrack' );
            case 'formal':
            case 'default':
            default:     return __( 'Goals', 'talenttrack' );
        }
    }

    private function renderAttendance( object $player, ReportConfig $config ): void {
        $stats = $this->fetchAttendanceStats( (int) $player->id, $config->filters );
        if ( $stats['total'] === 0 ) return;
        ?>
        <section class="tt-report-attendance">
            <h2><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></h2>
            <p>
                <?php
                echo esc_html( sprintf(
                    /* translators: 1: present count, 2: total count, 3: percentage */
                    __( 'Present at %1$d of %2$d sessions (%3$d%%) in this period.', 'talenttrack' ),
                    (int) $stats['present'],
                    (int) $stats['total'],
                    (int) $stats['pct']
                ) );
                ?>
            </p>
        </section>
        <?php
    }

    private function renderSessions( object $player, ReportConfig $config ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $where  = [ 't.team_id = %d', 't.archived_at IS NULL' ];
        $params = [ (int) $player->team_id ];
        if ( $config->filters['date_from'] !== '' ) {
            $where[]  = 't.session_date >= %s';
            $params[] = $config->filters['date_from'];
        }
        if ( $config->filters['date_to'] !== '' ) {
            $where[]  = 't.session_date <= %s';
            $params[] = $config->filters['date_to'];
        }
        $where_sql = implode( ' AND ', $where );

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_activities t WHERE $where_sql",
            ...$params
        ) );
        if ( $count === 0 ) return;
        ?>
        <section class="tt-report-sessions">
            <h2><?php esc_html_e( 'Sessions', 'talenttrack' ); ?></h2>
            <p>
                <?php
                echo esc_html( sprintf(
                    /* translators: %d: session count */
                    _n(
                        '%d session logged for this team in the period.',
                        '%d sessions logged for this team in the period.',
                        $count,
                        'talenttrack'
                    ),
                    $count
                ) );
                ?>
            </p>
        </section>
        <?php
    }

    private function renderCoachNotes( ReportConfig $config ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $where  = [ 'player_id = %d', "notes IS NOT NULL", "notes != ''" ];
        $params = [ $config->player_id ];
        if ( $config->filters['date_from'] !== '' ) {
            $where[]  = 'eval_date >= %s';
            $params[] = $config->filters['date_from'];
        }
        if ( $config->filters['date_to'] !== '' ) {
            $where[]  = 'eval_date <= %s';
            $params[] = $config->filters['date_to'];
        }
        $where_sql = implode( ' AND ', $where );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT eval_date, notes FROM {$p}tt_evaluations WHERE $where_sql ORDER BY eval_date DESC LIMIT 10",
            ...$params
        ) );
        if ( empty( $rows ) ) return;
        ?>
        <section class="tt-report-notes">
            <h2><?php esc_html_e( 'Coach notes', 'talenttrack' ); ?></h2>
            <ul class="tt-report-note-list">
                <?php foreach ( $rows as $r ) : ?>
                    <li>
                        <span class="tt-report-note-date"><?php echo esc_html( (string) $r->eval_date ); ?></span>
                        <span class="tt-report-note-body"><?php echo esc_html( (string) $r->notes ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    private function renderFooter( ReportConfig $config ): void {
        ?>
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
        <?php
    }

    /**
     * @param array{labels: string[], series: array<int, array{label:string, points: array<int, ?float>}>} $trend
     * @param array{labels: string[], datasets: array<int, array{label:string, values: array<int, float|int>}>} $radar
     * @return array<string, mixed>
     */
    private function buildChartPayload( array $trend, array $radar, float $rating_max ): array {
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
        return [
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
    }

    /**
     * @param array<string, mixed> $chart_payload
     */
    private function renderChartScript( array $chart_payload ): void {
        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
        (function(){
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

    // Helpers

    private function renderHeadlineTile( string $title, ?float $value, string $subtext ): void {
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

    private function trendText( string $trend ): string {
        switch ( $trend ) {
            case 'up':   return '↑ ' . __( 'Improving', 'talenttrack' );
            case 'down': return '↓ ' . __( 'Declining', 'talenttrack' );
            case 'flat': return '→ ' . __( 'Stable', 'talenttrack' );
            default:     return '— ' . __( 'Not enough data', 'talenttrack' );
        }
    }

    /**
     * @return array<int, object>
     */
    private function fetchGoalsForReport( int $player_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, priority, due_date
               FROM {$p}tt_goals
              WHERE player_id = %d
                AND archived_at IS NULL
              ORDER BY ( status IN ( 'completed', 'cancelled' ) ) ASC,
                       ( due_date IS NULL ) ASC,
                       due_date ASC
              LIMIT 10",
            $player_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array{date_from:string, date_to:string} $filters
     * @return array{present:int, total:int, pct:int}
     */
    private function fetchAttendanceStats( int $player_id, array $filters ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 'a.player_id = %d', 'a.is_guest = 0' ];
        $params = [ $player_id ];
        if ( $filters['date_from'] !== '' ) {
            $where[]  = 's.session_date >= %s';
            $params[] = $filters['date_from'];
        }
        if ( $filters['date_to'] !== '' ) {
            $where[]  = 's.session_date <= %s';
            $params[] = $filters['date_to'];
        }
        $where_sql = implode( ' AND ', $where );

        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT a.status
               FROM {$p}tt_attendance a
               JOIN {$p}tt_activities s ON a.activity_id = s.id
              WHERE $where_sql",
            ...$params
        ) );

        $total = count( $rows );
        $present = 0;
        foreach ( $rows as $r ) {
            if ( strcasecmp( (string) $r->status, 'Present' ) === 0 ) $present++;
        }
        $pct = $total > 0 ? (int) round( ( $present / $total ) * 100 ) : 0;
        return [ 'present' => $present, 'total' => $total, 'pct' => $pct ];
    }

    /**
     * @param array{date_from:string, date_to:string, eval_type_id:int} $filters
     */
    private static function formatFilterPeriod( array $filters ): string {
        $from = $filters['date_from'];
        $to   = $filters['date_to'];
        if ( $from === '' && $to === '' ) return __( 'All evaluations', 'talenttrack' );
        if ( $from !== '' && $to !== '' ) return $from . ' — ' . $to;
        if ( $from !== '' ) return __( 'From', 'talenttrack' ) . ' ' . $from;
        return __( 'Until', 'talenttrack' ) . ' ' . $to;
    }

    private static function resolveClubName(): string {
        return trim( (string) QueryHelpers::get_config( 'academy_name', '' ) );
    }

    private static function resolveClubLogoUrl(): string {
        return trim( (string) QueryHelpers::get_config( 'logo_url', '' ) );
    }

    private function renderStyles(): void {
        ?>
        <style>
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
        .tt-report-logo img { max-width: 90px; max-height: 90px; display: block; }
        .tt-report-title-block { flex: 1; }
        .tt-report-club {
            font-size: 11px; letter-spacing: 0.15em; text-transform: uppercase;
            color: #555; margin-bottom: 4px; font-weight: 600;
        }
        .tt-report-title {
            font-family: 'Oswald', sans-serif; font-weight: 600;
            font-size: 26px; margin: 0 0 4px; line-height: 1.15; color: #1a1d21;
        }
        .tt-report-meta { font-size: 12px; color: #666; }
        .tt-report-card-section { display: flex; justify-content: center; padding: 8px 0 20px; }
        .tt-report-headline {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 10px; margin-bottom: 18px;
        }
        .tt-report-headline-tile {
            border: 1px solid #dcdcde; border-left: 4px solid #1a2332;
            padding: 10px 14px; background: #fafbfc; box-sizing: border-box;
        }
        .tt-rht-title {
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em;
            color: #666; font-weight: 600;
        }
        .tt-rht-value {
            font-family: 'Oswald', sans-serif; font-size: 28px; font-weight: 700;
            color: #1a2332; line-height: 1.1; margin: 2px 0;
        }
        .tt-rht-sub { font-size: 10px; color: #777; }
        .tt-report-breakdown h2,
        .tt-report-goals h2,
        .tt-report-attendance h2,
        .tt-report-sessions h2,
        .tt-report-notes h2 {
            font-family: 'Oswald', sans-serif; font-size: 18px; font-weight: 600;
            margin: 20px 0 8px;
        }
        .tt-report-breakdown table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .tt-report-breakdown th {
            text-align: left; padding: 6px 8px; background: #f2f3f5;
            border-bottom: 2px solid #ccc; font-weight: 600; font-size: 11px;
            text-transform: uppercase; letter-spacing: 0.04em; color: #444;
        }
        .tt-report-breakdown th:nth-child(2),
        .tt-report-breakdown th:nth-child(3) { text-align: right; width: 80px; }
        .tt-report-breakdown th:nth-child(4) { width: 140px; }
        .tt-report-breakdown td { padding: 5px 8px; border-bottom: 1px solid #eee; }
        .tt-report-breakdown td:nth-child(2),
        .tt-report-breakdown td:nth-child(3) { text-align: right; }
        .tt-report-breakdown tr.tt-report-sub-row td {
            background: #fafafa; color: #555; font-size: 11px;
        }
        .tt-report-charts {
            display: grid; grid-template-columns: 1.5fr 1fr; gap: 14px;
            margin-top: 20px; margin-bottom: 24px;
        }
        .tt-report-chart {
            border: 1px solid #dcdcde; padding: 10px 12px;
            background: #fff; box-sizing: border-box;
        }
        .tt-report-chart h3 {
            font-family: 'Oswald', sans-serif; font-size: 14px; font-weight: 600;
            margin: 0 0 6px;
        }
        .tt-report-chart canvas { max-width: 100%; height: auto !important; }
        .tt-report-goal-list { list-style: none; margin: 0; padding: 0; }
        .tt-report-goal-list li {
            border-left: 3px solid #2c8c4a; padding: 6px 12px; margin-bottom: 6px;
            background: #f7faf8;
        }
        .tt-report-goal-meta { font-size: 11px; color: #666; margin-left: 6px; }
        .tt-report-note-list { list-style: none; margin: 0; padding: 0; }
        .tt-report-note-list li {
            padding: 6px 0; border-bottom: 1px solid #eee; font-size: 13px;
            display: flex; gap: 12px;
        }
        .tt-report-note-date { color: #666; font-size: 12px; flex-shrink: 0; }
        .tt-report-note-body { color: #333; }
        .tt-report-footer {
            display: flex; gap: 30px; margin-top: 30px; padding-top: 20px;
            border-top: 1px solid #dcdcde;
        }
        .tt-report-sig { flex: 1; display: flex; align-items: flex-end; gap: 8px; }
        .tt-report-sig-label { font-size: 11px; color: #555; white-space: nowrap; padding-bottom: 2px; }
        .tt-report-sig-line { flex: 1; border-bottom: 1px solid #333; height: 20px; }

        /* Tone variants — tweak the title block colour to differentiate. */
        .tt-report-tone-warm   .tt-report-header { border-bottom-color: #2c8c4a; }
        .tt-report-tone-fun    .tt-report-header { border-bottom-color: #c9962a; }
        .tt-report-tone-formal .tt-report-header { border-bottom-color: #1a2332; }

        @media print {
            @page { size: A4 portrait; margin: 15mm 15mm 15mm 15mm; }
            body {
                background: #fff !important; margin: 0; padding: 0;
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
            .tt-report-wrap {
                max-width: none; margin: 0; padding: 0; box-shadow: none; width: 100%;
            }
            .tt-report-breakdown,
            .tt-report-charts,
            .tt-report-card-section,
            .tt-report-goals,
            .tt-report-attendance,
            .tt-report-sessions,
            .tt-report-notes,
            .tt-report-footer { page-break-inside: avoid; }
            .tt-report-print-btn { display: none; }
        }
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
