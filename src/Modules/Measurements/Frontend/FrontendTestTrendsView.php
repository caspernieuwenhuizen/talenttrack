<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementLevelsRepository;
use TT\Modules\Measurements\Reports\TestTrendsQuery;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\TrendChart;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTestTrendsView (#2537) — the Test trends report.
 *
 * One test, every player in scope, over a window: who is developing and who
 * is stalling. The longitudinal view existed only inside the Excel export's
 * Trends sheet; this is that sheet on screen.
 *
 * Composition only — the series, the change figures, the verdicts and the
 * rankings all come from TestTrendsQuery (§4), so this view and
 * `GET /reports/test-trends` cannot drift apart.
 *
 * The report's SHAPE follows the test's own definition. A test with a
 * direction gets a chart, a ranking and a verdict column; a `neutral` test
 * (height, weight) gets values per date and nothing else, because there is no
 * better or worse to rank. Status and pass/fail have no numeric axis at all.
 * Slug: `test-trends`.
 */
final class FrontendTestTrendsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Test trends', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard(
            $title,
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );

        if ( ! $is_admin && ! MatrixGate::canAnyScope( $user_id, 'measurements', 'read' ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to browse test results.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'report_test_trends' ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'This report has been switched off for your academy.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        foreach ( [ 'frontend-test-results', 'frontend-measurement-levels', 'frontend-trend-chart' ] as $sheet ) {
            wp_enqueue_style(
                'tt-' . $sheet,
                TT_PLUGIN_URL . 'assets/css/' . $sheet . '.css',
                [ 'tt-frontend-app-chrome' ],
                TT_VERSION
            );
        }

        self::renderHeader( $title );

        $see_all = $is_admin || MatrixGate::can( $user_id, 'measurements', 'read', 'global' );
        $teams   = $see_all ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        $teams   = is_array( $teams ) ? $teams : [];

        $definitions = ( new MeasurementDefinitionsRepository() )->listAll();
        if ( $definitions === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No tests are defined yet. Add a test under Manage tests to start recording results.', 'talenttrack' ) . '</p>';
            return;
        }

        $allowed_ids   = array_map( static fn ( $t ) => (int) $t->id, $teams );
        $definition_id = isset( $_GET['definition_id'] ) ? absint( $_GET['definition_id'] ) : 0;
        $team_id       = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $age_group     = isset( $_GET['age_group'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['age_group'] ) ) : '';
        $from          = isset( $_GET['from'] ) ? self::safeDate( (string) $_GET['from'] ) : '';
        $to            = isset( $_GET['to'] ) ? self::safeDate( (string) $_GET['to'] ) : '';

        if ( ! $see_all && $team_id > 0 && ! in_array( $team_id, $allowed_ids, true ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this team.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderFilters( $definitions, $teams, $definition_id, $team_id, $age_group, $from, $to );

        if ( $definition_id <= 0 ) {
            echo '<p class="tt-tr-hint">' . esc_html__( 'Choose a test to see how every player has developed.', 'talenttrack' ) . '</p>';
            return;
        }

        $data = ( new TestTrendsQuery() )->forDefinition(
            $definition_id,
            [ 'team_id' => $team_id, 'age_group' => $age_group, 'date_from' => $from, 'date_to' => $to ],
            $see_all ? null : $allowed_ids
        );

        if ( $data['definition'] === null || $data['players'] === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No results match these filters yet.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderReport( $data );
    }

    /** @param array<string, mixed> $data */
    private static function renderReport( array $data ): void {
        $def   = (array) $data['definition'];
        $dates = (array) $data['dates'];
        $type  = (string) $def['value_type'];

        echo '<section class="tt-card">';
        echo '<h2 class="tt-card__title">' . esc_html( (string) $def['name'] ) . '</h2>';
        echo '<p class="tt-tr-meta">' . esc_html( sprintf(
            /* translators: 1: number of players, 2: number of measuring moments */
            _n( '%1$d player · %2$d measuring moment', '%1$d players · %2$d measuring moments', count( $dates ), 'talenttrack' ),
            count( (array) $data['players'] ),
            count( $dates )
        ) ) . '</p>';

        if ( $type === 'status' ) {
            self::renderStatusMatrix( $data );
        } elseif ( $type === 'passfail' ) {
            self::renderPassFailMatrix( $data );
        } elseif ( ! $data['has_direction'] ) {
            // Neutral: values per date and nothing that implies a judgement.
            self::renderNeutralNotice();
            self::renderValueTable( $data, false );
        } else {
            self::renderChart( $data );
            self::renderRankings( $data );
            self::renderValueTable( $data, true );
        }
        echo '</section>';
    }

    private static function renderNeutralNotice(): void {
        echo '<p class="tt-tr-hint">'
            . esc_html__( 'This test has no direction: a higher value is not better than a lower one. There is no target, no verdict and no ranking — only the readings per date.', 'talenttrack' )
            . '</p>';
    }

    /** @param array<string, mixed> $data */
    private static function renderChart( array $data ): void {
        $def    = (array) $data['definition'];
        $series = [];

        foreach ( (array) $data['players'] as $p ) {
            $series[] = [
                'label'   => (string) $p['name'],
                'values'  => (array) $p['values'],
                'variant' => 'player',
            ];
        }
        if ( $data['average'] !== [] ) {
            $series[] = [
                'label'   => __( 'Squad average', 'talenttrack' ),
                'values'  => (array) $data['average'],
                'variant' => 'average',
            ];
        }

        $svg = TrendChart::renderMulti( [
            'dates'  => (array) $data['dates'],
            'series' => $series,
            'unit'   => (string) $def['unit'],
            'title'  => (string) $def['name'],
        ] );
        if ( $svg === '' ) return;

        echo $svg;

        if ( (string) $def['direction'] === 'lower' ) {
            echo '<p class="tt-trend-direction">'
                . esc_html__( '↓ A lower value is better on this test — a falling line is progress.', 'talenttrack' )
                . '</p>';
        } else {
            echo '<p class="tt-trend-direction">'
                . esc_html__( '↑ A higher value is better on this test.', 'talenttrack' )
                . '</p>';
        }

        echo '<p class="tt-trend-legend">';
        echo '<span><i></i>' . esc_html__( 'player', 'talenttrack' ) . '</span>';
        if ( $data['average'] !== [] ) {
            echo '<span><i class="is-average"></i>' . esc_html__( 'squad average', 'talenttrack' ) . '</span>';
        }
        echo '</p>';
    }

    /**
     * Most improved / most declined. A player whose change is inside the
     * flat band appears in neither column — putting a small improvement
     * under "declined" because the number is negative is exactly the error
     * this report exists to avoid.
     *
     * @param array<string, mixed> $data
     */
    private static function renderRankings( array $data ): void {
        $ranks = ( new TestTrendsQuery() )->rankings( (array) $data['players'] );
        if ( $ranks['improved'] === [] && $ranks['declined'] === [] ) return;

        $unit = (string) ( (array) $data['definition'] )['unit'];

        echo '<div class="tt-tr-ranks">';
        foreach ( [
            'improved' => __( 'Most improved', 'talenttrack' ),
            'declined' => __( 'Fallen back', 'talenttrack' ),
        ] as $key => $heading ) {
            echo '<div class="tt-tr-rank">';
            echo '<h3 class="tt-tr-rank__title">' . esc_html( $heading ) . '</h3>';
            if ( $ranks[ $key ] === [] ) {
                echo '<p class="tt-tr-hint">' . esc_html__( 'Nobody in this window.', 'talenttrack' ) . '</p>';
            } else {
                echo '<ol class="tt-tr-rank__list">';
                foreach ( $ranks[ $key ] as $p ) {
                    echo '<li>' . esc_html( (string) $p['name'] ) . ' '
                        . '<span class="' . ( $key === 'improved' ? 'tt-tr-up' : 'tt-tr-down' ) . '">'
                        . esc_html( self::signed( (float) $p['delta'], $unit ) )
                        . '</span></li>';
                }
                echo '</ol>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * One row per player: their value on each measuring moment, then the
     * change over the window. The verdict column only exists where the test
     * has a direction.
     *
     * @param array<string, mixed> $data
     */
    private static function renderValueTable( array $data, bool $with_verdict ): void {
        $dates = (array) $data['dates'];
        $unit  = (string) ( (array) $data['definition'] )['unit'];

        echo '<div class="tt-meas-cols">';
        echo '<table>';
        echo '<thead><tr><th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        foreach ( $dates as $d ) {
            echo '<th scope="col">' . esc_html( self::shortDate( $d ) ) . '</th>';
        }
        echo '<th scope="col">' . esc_html__( 'Change', 'talenttrack' ) . '</th>';
        if ( $with_verdict ) {
            echo '<th scope="col">' . esc_html__( 'Verdict', 'talenttrack' ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( (array) $data['players'] as $p ) {
            echo '<tr><th scope="row">' . self::playerLink( $p ) . '</th>';
            foreach ( $dates as $d ) {
                if ( isset( $p['values'][ $d ] ) ) {
                    echo '<td>' . esc_html( self::num( (float) $p['values'][ $d ] ) ) . '</td>';
                } else {
                    echo '<td class="tt-meas-cols__none">&mdash;</td>';
                }
            }

            $delta = $p['delta'] ?? null;
            $cls   = 'tt-meas-cols__change';
            if ( $with_verdict && $p['verdict'] === 'improved' ) $cls .= ' tt-tr-up';
            if ( $with_verdict && $p['verdict'] === 'declined' ) $cls .= ' tt-tr-down';
            echo '<td class="' . esc_attr( $cls ) . '">'
                . ( $delta === null ? '&mdash;' : esc_html( self::signed( (float) $delta, $unit ) ) )
                . '</td>';

            if ( $with_verdict ) {
                echo '<td>' . self::verdictChip( (string) ( $p['verdict'] ?? '' ) ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ( $with_verdict ) {
            echo '<p class="tt-tr-hint">'
                . esc_html__( 'The change is read in the direction of the test: on a test where lower is better, a negative change is progress and is ranked as such.', 'talenttrack' )
                . '</p>';
        }
    }

    /**
     * Status test: a player × date matrix of level chips, plus the spread per
     * date. No line — the levels are named states, not distances.
     *
     * @param array<string, mixed> $data
     */
    private static function renderStatusMatrix( array $data ): void {
        $dates  = (array) $data['dates'];
        $def_id = (int) ( (array) $data['definition'] )['id'];
        $levels = new MeasurementLevelsRepository();

        echo '<p class="tt-tr-hint">'
            . esc_html__( 'Levels have no numeric axis, so there is no line. Each cell shows the recorded level in its own colour.', 'talenttrack' )
            . '</p>';

        echo '<div class="tt-meas-cols"><table>';
        echo '<thead><tr><th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        foreach ( $dates as $d ) {
            echo '<th scope="col">' . esc_html( self::shortDate( $d ) ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( (array) $data['players'] as $p ) {
            echo '<tr><th scope="row">' . self::playerLink( $p ) . '</th>';
            foreach ( $dates as $d ) {
                $label = (string) ( $p['texts'][ $d ] ?? '' );
                if ( $label === '' ) {
                    echo '<td class="tt-meas-cols__none">&mdash;</td>';
                    continue;
                }
                $level = $levels->findByLabel( $def_id, $label );
                $token = $level ? MeasurementLevelPalette::safe( (string) $level->color_token ) : MeasurementLevelPalette::DEFAULT_TOKEN;
                echo '<td><span class="tt-meas-level ' . esc_attr( MeasurementLevelPalette::cssClass( $token ) ) . '">'
                    . esc_html( $label ) . '</span></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Pass / fail: a glyph per date and the pass rate per round. Two outcomes
     * are not a scale, so the rate over time is the only honest trend.
     *
     * @param array<string, mixed> $data
     */
    private static function renderPassFailMatrix( array $data ): void {
        $dates = (array) $data['dates'];

        echo '<div class="tt-meas-cols"><table>';
        echo '<thead><tr><th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        foreach ( $dates as $d ) {
            echo '<th scope="col">' . esc_html( self::shortDate( $d ) ) . '</th>';
        }
        echo '<th scope="col">' . esc_html__( 'Passed', 'talenttrack' ) . '</th></tr></thead><tbody>';

        $per_date = array_fill_keys( $dates, [ 'pass' => 0, 'total' => 0 ] );

        foreach ( (array) $data['players'] as $p ) {
            echo '<tr><th scope="row">' . self::playerLink( $p ) . '</th>';
            $passed = 0;
            $total  = 0;
            foreach ( $dates as $d ) {
                $has = isset( $p['values'][ $d ] ) || isset( $p['texts'][ $d ] );
                if ( ! $has ) {
                    echo '<td class="tt-meas-mark tt-meas-mark--none">&mdash;</td>';
                    continue;
                }
                $is_pass = isset( $p['values'][ $d ] )
                    ? (float) $p['values'][ $d ] > 0
                    : in_array( strtolower( trim( (string) $p['texts'][ $d ] ) ), [ 'pass', 'passed', 'yes', 'true', '1', 'gehaald' ], true );

                $total++;
                $per_date[ $d ]['total']++;
                if ( $is_pass ) { $passed++; $per_date[ $d ]['pass']++; }

                echo '<td class="tt-meas-mark ' . ( $is_pass ? 'tt-meas-mark--pass' : 'tt-meas-mark--fail' ) . '">'
                    . ( $is_pass ? '✓' : '✗' ) . '</td>';
            }
            echo '<td class="tt-meas-cols__change">' . esc_html( sprintf(
                /* translators: 1: number of passes, 2: number of attempts */
                __( '%1$d of %2$d', 'talenttrack' ),
                $passed,
                $total
            ) ) . '</td></tr>';
        }

        echo '</tbody><tfoot><tr><th scope="row">' . esc_html__( 'Pass rate', 'talenttrack' ) . '</th>';
        foreach ( $dates as $d ) {
            $t = $per_date[ $d ]['total'];
            echo '<td>' . ( $t > 0 ? esc_html( round( $per_date[ $d ]['pass'] / $t * 100 ) . '%' ) : '&mdash;' ) . '</td>';
        }
        echo '<td></td></tr></tfoot></table></div>';
    }

    /* ---- small helpers -------------------------------------------------- */

    /** @param array<string, mixed> $p */
    private static function playerLink( array $p ): string {
        $url = RecordLink::detailUrlForWithBack( 'players', (int) $p['player_id'] );
        return '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $p['name'] ) . '</a>';
    }

    private static function verdictChip( string $verdict ): string {
        $map = [
            'improved' => [ 'tt-meas-flag-ok',   __( 'improved', 'talenttrack' ) ],
            'declined' => [ 'tt-meas-flag-bad',  __( 'fallen back', 'talenttrack' ) ],
            'flat'     => [ 'tt-meas-flag-flat', __( 'about the same', 'talenttrack' ) ],
        ];
        if ( ! isset( $map[ $verdict ] ) ) return '<span class="tt-meas-cols__none">&mdash;</span>';
        return '<span class="tt-tr-verdict ' . esc_attr( $map[ $verdict ][0] ) . '">'
            . esc_html( $map[ $verdict ][1] ) . '</span>';
    }

    /** A change, signed so it reads as a movement, with its unit. */
    private static function signed( float $delta, string $unit ): string {
        $sign = $delta > 0 ? '+' : ( $delta < 0 ? '−' : '' );
        $out  = $sign . self::num( abs( $delta ) );
        return $unit !== '' ? $out . ' ' . $unit : $out;
    }

    private static function num( float $v ): string {
        $r = round( $v, 2 );
        $d = ( floor( $r ) === $r ) ? 0 : ( round( $r, 1 ) === $r ? 1 : 2 );
        return number_format_i18n( $r, $d );
    }

    private static function shortDate( string $date ): string {
        $ts = $date !== '' ? strtotime( $date ) : false;
        return $ts ? date_i18n( 'j M', $ts ) : $date;
    }

    private static function safeDate( string $raw ): string {
        $raw = trim( $raw );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
    }

    /**
     * @param array<int, object> $definitions
     * @param array<int, object> $teams
     */
    private static function renderFilters(
        array $definitions, array $teams,
        int $definition_id, int $team_id, string $age_group, string $from, string $to
    ): void {
        echo '<form method="get" class="tt-tr-filters">';
        echo '<input type="hidden" name="tt_view" value="test-trends" />';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'Test', 'talenttrack' ) . '</span>';
        echo '<select name="definition_id" class="tt-input">';
        echo '<option value="0">' . esc_html__( '— Choose a test —', 'talenttrack' ) . '</option>';
        foreach ( $definitions as $def ) {
            $label = (string) $def->name;
            if ( ! empty( $def->category_label ) ) {
                $label = (string) $def->category_label . ' · ' . $label;
            }
            echo '<option value="' . (int) $def->id . '" ' . selected( $definition_id, (int) $def->id, false ) . '>'
                . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id" class="tt-input">';
        echo '<option value="0">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( $teams as $t ) {
            echo '<option value="' . (int) $t->id . '" ' . selected( $team_id, (int) $t->id, false ) . '>'
                . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="from" class="tt-input" value="' . esc_attr( $from ) . '" />';
        echo '</label>';

        echo '<label class="tt-tr-filters__field">';
        echo '<span class="tt-tr-filters__label">' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="to" class="tt-input" value="' . esc_attr( $to ) . '" />';
        echo '</label>';

        if ( $age_group !== '' ) {
            echo '<input type="hidden" name="age_group" value="' . esc_attr( $age_group ) . '" />';
        }

        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Show', 'talenttrack' ) . '</button>';
        echo '</form>';
    }
}
