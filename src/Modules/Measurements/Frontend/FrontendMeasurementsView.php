<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Modules\Measurements\Services\PlayerMeasurementProfile;

/**
 * FrontendMeasurementsView (#1856) — the player "Metingen" surface.
 *
 * Routed via ?tt_view=measurements (own player, or ?player_id=N for a
 * parent's child / a coach's team player, gated by canViewPlayer in the
 * dispatcher). Renders the player's tests grouped by category, each with
 * its latest value, a green/amber/red flag against the age-group target,
 * and a sparkline trend — straight from the shared PlayerMeasurementProfile
 * service, so the screen shows exactly what the REST API returns.
 *
 * Read-only and server-rendered (the sparkline is inline SVG; no client
 * JS / REST round-trip needed for the read path).
 */
class FrontendMeasurementsView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();

        $name = trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) );

        FrontendBreadcrumbs::fromDashboard( __( 'Measurements', 'talenttrack' ) );
        self::renderHeader(
            $name !== ''
                ? sprintf( /* translators: %s: player name */ __( 'Measurements — %s', 'talenttrack' ), $name )
                : __( 'Measurements', 'talenttrack' )
        );

        self::renderBody( (int) $player->id );
    }

    /**
     * The measurement profile body — categories → tests → latest value +
     * flag + sparkline. Shared by the standalone view and the player-
     * profile Measurements tab so both render identically. Enqueues its
     * own stylesheet (idempotent).
     */
    public static function renderBody( int $player_id ): void {
        wp_enqueue_style(
            'tt-frontend-measurements',
            TT_PLUGIN_URL . 'assets/css/frontend-measurements.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-frontend-measurement-levels',
            TT_PLUGIN_URL . 'assets/css/frontend-measurement-levels.css',
            [ 'tt-frontend-measurements' ],
            TT_VERSION
        );
        // #2536 — the expandable history: chart, columns table, status
        // strip. Loaded after the level palette so a level's colour class
        // still wins inside a status block.
        wp_enqueue_style(
            'tt-frontend-trend-chart',
            TT_PLUGIN_URL . 'assets/css/frontend-trend-chart.css',
            [ 'tt-frontend-measurement-levels' ],
            TT_VERSION
        );

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $player_id );

        if ( empty( $profile ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No tests have been set up yet.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-meas">';
        foreach ( $profile as $cat ) {
            echo '<section class="tt-meas-cat">';
            echo '<h3 class="tt-meas-cat-title">' . esc_html( (string) $cat['category'] ) . '</h3>';

            // #2536 — a trend only means something in the terms of its own
            // test. Tests without a direction (height, weight, shoe size)
            // have no better or worse, so they are pulled out of the row
            // list and shown together as values per date: no chart, no
            // target band, no verdict. Everything else keeps the row shape
            // and gains an expandable chart.
            [ $neutral, $rows ] = self::splitByPresentation( (array) $cat['tests'] );

            if ( $rows !== [] ) {
                echo '<ul class="tt-meas-list">';
                foreach ( $rows as $test ) {
                    self::renderTestRow( (array) $test );
                }
                echo '</ul>';
            }
            if ( $neutral !== [] ) {
                self::renderNeutralTable( $neutral );
            }
            echo '</section>';
        }
        echo '</div>';
    }

    /**
     * Split a category's tests into the ones shown as a shared values-per-date
     * table (numeric/scale tests with `direction = neutral`) and the ones that
     * keep the per-test row.
     *
     * @param array<int, mixed> $tests
     * @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>}
     */
    private static function splitByPresentation( array $tests ): array {
        $neutral = [];
        $rows    = [];
        foreach ( $tests as $test ) {
            $t = (array) $test;
            if ( self::isNeutralNumeric( $t ) ) {
                $neutral[] = $t;
            } else {
                $rows[] = $t;
            }
        }
        return [ $neutral, $rows ];
    }

    /**
     * A numeric or scale test whose `direction` is `neutral`: measured and
     * tracked, but with no better or worse. Status and pass/fail tests are
     * excluded — they are not numeric and have their own presentation.
     *
     * @param array<string, mixed> $t
     */
    private static function isNeutralNumeric( array $t ): bool {
        $type = (string) ( $t['value_type'] ?? '' );
        if ( ! in_array( $type, [ 'numeric', 'scale' ], true ) ) return false;
        return (string) ( $t['direction'] ?? '' ) === 'neutral';
    }

    /**
     * Values per date for the direction-less tests of one category, sharing
     * one set of date columns because they share the measuring moments.
     *
     * Deliberately not a chart: a rising line would imply progress, a shaded
     * band would imply a norm and a ranking would imply the tallest player is
     * performing best. The change column is stated in plain text for the same
     * reason — it is a fact, not a verdict.
     *
     * @param array<int, array<string, mixed>> $tests
     */
    private static function renderNeutralTable( array $tests ): void {
        $dates = [];
        foreach ( $tests as $t ) {
            foreach ( (array) ( $t['series'] ?? [] ) as $point ) {
                $d = (string) ( $point['date'] ?? '' );
                if ( $d !== '' && ( $point['value'] ?? null ) !== null ) $dates[ $d ] = true;
            }
        }
        if ( $dates === [] ) return;
        $dates = array_keys( $dates );
        sort( $dates );

        echo '<p class="tt-meas-cols__caption">'
            . esc_html__( 'These tests have no target and no direction — a higher or lower value is not better. The readings are shown per date, without a verdict.', 'talenttrack' )
            . '</p>';

        echo '<div class="tt-meas-cols">';
        echo '<table>';
        echo '<thead><tr><th scope="col">' . esc_html__( 'Test', 'talenttrack' ) . '</th>';
        foreach ( $dates as $d ) {
            echo '<th scope="col">' . esc_html( self::formatDate( $d ) ) . '</th>';
        }
        echo '<th scope="col">' . esc_html__( 'Change', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $tests as $t ) {
            $by_date = [];
            foreach ( (array) ( $t['series'] ?? [] ) as $point ) {
                $v = $point['value'] ?? null;
                if ( $v === null ) continue;
                $by_date[ (string) ( $point['date'] ?? '' ) ] = (float) $v;
            }

            $unit = (string) ( $t['unit'] ?? '' );
            $name = (string) ( $t['name'] ?? '' );
            echo '<tr><th scope="row">' . esc_html( $unit !== '' ? $name . ' (' . $unit . ')' : $name ) . '</th>';

            foreach ( $dates as $d ) {
                if ( isset( $by_date[ $d ] ) ) {
                    echo '<td>' . esc_html( self::formatNumber( $by_date[ $d ] ) ) . '</td>';
                } else {
                    // A missed measuring moment is a gap, never a zero.
                    echo '<td class="tt-meas-cols__none">&mdash;</td>';
                }
            }

            echo '<td class="tt-meas-cols__change">' . esc_html( self::changeOver( $by_date, $dates ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * The plain difference between the first and last reading a test
     * actually has, signed so it reads as a movement. Computed over the
     * dates present, so a gap does not distort it. Returns an em dash when
     * there is nothing to compare.
     *
     * @param array<string, float> $by_date
     * @param array<int, string>   $dates
     */
    private static function changeOver( array $by_date, array $dates ): string {
        $present = [];
        foreach ( $dates as $d ) {
            if ( isset( $by_date[ $d ] ) ) $present[] = $by_date[ $d ];
        }
        if ( count( $present ) < 2 ) return '—';
        $delta = $present[ count( $present ) - 1 ] - $present[0];
        if ( abs( $delta ) < 0.0001 ) return '0';
        return ( $delta > 0 ? '+' : '−' ) . self::formatNumber( abs( $delta ) );
    }

    private static function formatNumber( float $v ): string {
        $rounded  = round( $v, 2 );
        $decimals = ( floor( $rounded ) === $rounded ) ? 0 : ( round( $rounded, 1 ) === $rounded ? 1 : 2 );
        return number_format_i18n( $rounded, $decimals );
    }

    /**
     * @param array<string, mixed> $t
     */
    private static function renderTestRow( array $t ): void {
        $is_status  = (string) ( $t['value_type'] ?? '' ) === 'status';
        $flag       = (string) ( $t['flag'] ?? '' );
        $level_tok  = (string) ( $t['level_token'] ?? '' );
        // Status colour comes from the picked level's token (a curated
        // swatch); numeric/scale colour comes from the green/amber flag.
        if ( $is_status && $level_tok !== '' ) {
            $flag_class = ' tt-meas-value--status ' . \TT\Modules\Measurements\Levels\MeasurementLevelPalette::cssClass( $level_tok );
        } else {
            $flag_class = in_array( $flag, [ 'ok', 'warn', 'bad' ], true ) ? ' tt-meas-flag-' . $flag : '';
        }
        $freq       = self::frequencyLabel( (string) ( $t['frequency'] ?? '' ) );
        $value      = (string) ( $t['latest_value'] ?? '' );
        $date       = (string) ( $t['latest_date'] ?? '' );

        echo '<li class="tt-meas-row">';

        echo '<div class="tt-meas-row-head">';
        echo '<span class="tt-meas-name">' . esc_html( (string) ( $t['name'] ?? '' ) ) . '</span>';
        if ( $freq !== '' ) {
            echo '<span class="tt-meas-freq">' . esc_html( $freq ) . '</span>';
        }
        echo '</div>';

        echo '<div class="tt-meas-row-data">';
        echo self::sparkline( is_array( $t['series'] ?? null ) ? $t['series'] : [] );
        echo '<span class="tt-meas-value' . $flag_class . '">';
        echo $value !== '' ? esc_html( $value ) : '&mdash;';
        echo '</span>';
        echo '</div>';

        if ( $date !== '' ) {
            echo '<div class="tt-meas-row-meta">' . esc_html( self::formatDate( $date ) ) . '</div>';
        }

        // #2536 — the history, in the form this test's type can carry.
        self::renderTrend( $t );

        echo '</li>';
    }

    /**
     * The expandable history for one test. The sparkline above stays as the
     * at-a-glance shape; this is the readable version — dated axis, values,
     * and the age-group target band where one exists.
     *
     * Collapsed by default via `<details>`: native disclosure, so it is
     * keyboard-reachable, announces its own expanded state and needs no
     * script. Which body is rendered depends entirely on the test's type —
     * a level or a pass has no numeric axis to plot.
     *
     * @param array<string, mixed> $t
     */
    private static function renderTrend( array $t ): void {
        $type   = (string) ( $t['value_type'] ?? '' );
        $series = is_array( $t['series'] ?? null ) ? (array) $t['series'] : [];
        if ( count( $series ) < 2 ) {
            // One reading is a starting position, not a trend. Say that,
            // rather than drawing an axis around a single dot.
            if ( count( $series ) === 1 ) {
                echo '<p class="tt-trend-empty">'
                    . esc_html__( 'One result so far — a trend needs at least two.', 'talenttrack' )
                    . '</p>';
            }
            return;
        }

        $body = '';
        if ( $type === 'status' ) {
            $body = self::statusHistory( $series, (int) ( $t['definition_id'] ?? 0 ) );
        } elseif ( $type === 'passfail' ) {
            $body = self::passFailHistory( $series );
        } else {
            $body = self::numericHistory( $t, $series );
        }
        if ( $body === '' ) return;

        echo '<details class="tt-meas-trend">';
        echo '<summary>' . esc_html__( 'Show history', 'talenttrack' ) . '</summary>';
        echo '<div class="tt-meas-trend__body">' . $body . '</div>';
        echo '</details>';
    }

    /**
     * Numeric / scale history: the chart, the target band, and — in words —
     * which direction counts as improvement. The slope alone must never be
     * the only cue: on a `lower is better` test the improving line descends,
     * which reads as decline to anyone who has not been told.
     *
     * @param array<string, mixed> $t
     * @param array<int, mixed> $series
     */
    private static function numericHistory( array $t, array $series ): string {
        $direction = (string) ( $t['direction'] ?? '' );
        $band      = is_array( $t['band'] ?? null ) ? (array) $t['band'] : null;
        if ( $band !== null ) {
            $band['label'] = __( 'On target for this age group', 'talenttrack' );
        }

        $svg = \TT\Shared\Frontend\Components\TrendChart::render( [
            'series'    => $series,
            'unit'      => (string) ( $t['unit'] ?? '' ),
            'direction' => $direction,
            'band'      => $band,
            'title'     => (string) ( $t['name'] ?? '' ),
        ] );
        if ( $svg === '' ) return '';

        $out = $svg;

        if ( $direction === 'lower' ) {
            $out .= '<p class="tt-trend-direction">'
                . esc_html__( '↓ A lower value is better on this test — the falling line is progress.', 'talenttrack' )
                . '</p>';
        } elseif ( $direction === 'higher' ) {
            $out .= '<p class="tt-trend-direction">'
                . esc_html__( '↑ A higher value is better on this test.', 'talenttrack' )
                . '</p>';
        }

        $out .= '<p class="tt-trend-legend">';
        $out .= '<span><i></i>' . esc_html__( 'recorded value', 'talenttrack' ) . '</span>';
        if ( $band !== null ) {
            // Same msgid as the band caption above — one string, translated
            // once, so the legend and the chart can never disagree.
            $out .= '<span><i class="is-band"></i>' . esc_html__( 'On target for this age group', 'talenttrack' ) . '</span>';
        }
        $out .= '</p>';

        return $out;
    }

    /**
     * Status history: one block per recorded date in that level's own colour.
     * No line — the levels are named states, not distances, and joining them
     * with a slope would invent a precision the data does not have.
     *
     * @param array<int, mixed> $series
     */
    private static function statusHistory( array $series, int $definition_id ): string {
        $out = '<div class="tt-meas-cols"><div class="tt-meas-steps">';
        $any = false;
        foreach ( $series as $point ) {
            $p     = (array) $point;
            $label = trim( (string) ( $p['text'] ?? '' ) );
            if ( $label === '' ) continue;
            $any = true;

            $token = \TT\Modules\Measurements\Levels\MeasurementLevelPalette::DEFAULT_TOKEN;
            // Resolve the stored label back to its CURRENT level, so
            // recolouring a level repaints the whole history rather than
            // leaving old entries in a retired colour.
            $level = self::levelsRepo()->findByLabel( $definition_id, $label );
            if ( $level ) {
                $token = \TT\Modules\Measurements\Levels\MeasurementLevelPalette::safe( (string) $level->color_token );
            }

            $out .= '<div class="tt-meas-steps__step '
                . esc_attr( \TT\Modules\Measurements\Levels\MeasurementLevelPalette::cssClass( $token ) ) . '">';
            $out .= '<span class="tt-meas-steps__date">' . esc_html( self::formatDate( (string) ( $p['date'] ?? '' ) ) ) . '</span>';
            $out .= '<span class="tt-meas-steps__label">' . esc_html( $label ) . '</span>';
            $out .= '</div>';
        }
        $out .= '</div></div>';
        return $any ? $out : '';
    }

    /**
     * Pass / fail history: a glyph per date plus the tally. Two outcomes are
     * not a scale, so they get no axis — and the glyph carries the meaning so
     * the colour is never doing the work alone.
     *
     * @param array<int, mixed> $series
     */
    private static function passFailHistory( array $series ): string {
        $passed = 0;
        $total  = 0;
        $cells  = '';

        foreach ( $series as $point ) {
            $p   = (array) $point;
            $raw = $p['value'] ?? null;
            $txt = strtolower( trim( (string) ( $p['text'] ?? '' ) ) );

            if ( $raw === null && $txt === '' ) {
                $cells .= '<td class="tt-meas-mark tt-meas-mark--none">&mdash;</td>';
                continue;
            }
            $is_pass = $raw !== null
                ? (float) $raw > 0
                : in_array( $txt, [ 'pass', 'passed', 'yes', 'true', '1', 'gehaald' ], true );

            $total++;
            if ( $is_pass ) $passed++;

            $cells .= '<td class="tt-meas-mark ' . ( $is_pass ? 'tt-meas-mark--pass' : 'tt-meas-mark--fail' ) . '">'
                . ( $is_pass ? '✓' : '✗' ) . '</td>';
        }
        if ( $total === 0 ) return '';

        $head = '';
        foreach ( $series as $point ) {
            $p = (array) $point;
            $head .= '<th scope="col">' . esc_html( self::formatDate( (string) ( $p['date'] ?? '' ) ) ) . '</th>';
        }

        return '<div class="tt-meas-cols"><table>'
            . '<thead><tr><th scope="col">' . esc_html__( 'Result', 'talenttrack' ) . '</th>' . $head
            . '<th scope="col">' . esc_html__( 'Passed', 'talenttrack' ) . '</th></tr></thead>'
            . '<tbody><tr><th scope="row">' . esc_html__( 'Outcome', 'talenttrack' ) . '</th>' . $cells
            . '<td class="tt-meas-cols__change">'
            . esc_html( sprintf(
                /* translators: 1: number of passes, 2: number of attempts */
                __( '%1$d of %2$d', 'talenttrack' ),
                $passed,
                $total
            ) )
            . '</td></tr></tbody></table></div>';
    }

    /** Lazily-built levels repository, shared across rows of one render. */
    private static function levelsRepo(): \TT\Modules\Measurements\Repositories\MeasurementLevelsRepository {
        static $repo = null;
        if ( $repo === null ) {
            $repo = new \TT\Modules\Measurements\Repositories\MeasurementLevelsRepository();
        }
        return $repo;
    }

    /**
     * Inline-SVG sparkline of the numeric series. Returns '' when there
     * are fewer than two numeric points (nothing to trend). Presentation
     * uses SVG attributes, never inline `style`, to satisfy the #1389 lint.
     *
     * @param array<int, array<string, mixed>> $series
     */
    private static function sparkline( array $series ): string {
        $values = [];
        foreach ( $series as $point ) {
            $v = $point['value'] ?? null;
            if ( $v !== null && $v !== '' ) {
                $values[] = (float) $v;
            }
        }
        $n = count( $values );
        if ( $n < 2 ) return '';

        $min = min( $values );
        $max = max( $values );
        $span = $max - $min;

        $w = 64;
        $h = 20;
        $pad = 2;
        $step = $n > 1 ? ( $w - 2 * $pad ) / ( $n - 1 ) : 0;

        $points = [];
        foreach ( $values as $i => $v ) {
            $x = $pad + $i * $step;
            $ratio = $span > 0 ? ( $v - $min ) / $span : 0.5;
            // SVG y grows downward; invert so a higher value sits higher.
            $y = $pad + ( 1 - $ratio ) * ( $h - 2 * $pad );
            $points[] = round( $x, 1 ) . ',' . round( $y, 1 );
        }

        return '<svg class="tt-meas-spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h
            . '" role="img" aria-hidden="true" focusable="false">'
            . '<polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="'
            . esc_attr( implode( ' ', $points ) ) . '"/></svg>';
    }

    private static function frequencyLabel( string $frequency ): string {
        switch ( $frequency ) {
            case 'annual':    return __( 'annually', 'talenttrack' );
            case 'biannual':  return __( 'twice a year', 'talenttrack' );
            case 'quarterly': return __( 'quarterly', 'talenttrack' );
            case 'monthly':   return __( 'monthly', 'talenttrack' );
            default:          return '';
        }
    }

    private static function formatDate( string $date ): string {
        $ts = strtotime( $date );
        if ( ! $ts ) return $date;
        return date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
    }
}
