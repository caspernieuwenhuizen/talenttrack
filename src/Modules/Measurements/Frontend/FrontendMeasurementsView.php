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

        // #3477 — the crumb said "Measurements" and the heading beneath it
        // "Measurements — Bas Willems": two answers to whose page this is,
        // one line apart. The voice gives one, used for both.
        $voice = \TT\Shared\Frontend\Components\SubjectVoice::forPlayer( $player );
        $title = $voice->pick(
            __( 'My measurements', 'talenttrack' ),
            /* translators: %s: player name */
            sprintf( __( 'Measurements — %s', 'talenttrack' ), $voice->name() )
        );

        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

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

        // #3526 — BMI is a row in the category it is derived from, not a
        // second card system above the tab. Resolved once, here, so the
        // standalone view and the player-profile tab show it identically —
        // the tab used to render it itself and the standalone view never did.
        $bmi     = self::bmiRow( $player_id );
        $bmi_cat = $bmi === null ? -1 : self::bmiCategoryIndex( $profile );

        $any_without_target = false;

        echo '<div class="tt-meas">';
        foreach ( $profile as $index => $cat ) {
            $rows = [];
            foreach ( (array) ( $cat['tests'] ?? [] ) as $test ) {
                $rows[] = self::registerRow( (array) $test );
            }
            if ( $bmi !== null && $index === $bmi_cat ) {
                $rows[] = $bmi;
            }
            if ( $rows === [] ) continue;

            foreach ( $rows as $row ) {
                if ( $row['target_absent'] ) $any_without_target = true;
            }

            self::renderCategory( (string) $cat['category'], $rows );
        }

        // Once per surface, not once per card: four categories repeating the
        // same sentence is cause #3 in a new costume.
        if ( $any_without_target ) {
            echo '<p class="tt-meas-note">'
                . esc_html__( '"No target" means the test has no better or worse — the reading is recorded, not judged.', 'talenttrack' )
                . '</p>';
        }

        echo '</div>';
    }

    /**
     * One category as a register: a head that counts what is in it, one table
     * with one row shape for every test, and a footer carrying the statements
     * that used to repeat per row.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function renderCategory( string $category, array $rows ): void {
        $never   = [];
        $overdue = [];
        $single  = 0;
        $latest  = '';

        foreach ( $rows as $row ) {
            if ( $row['never_measured'] ) {
                $never[] = $row['name'];
            } elseif ( $row['overdue'] ) {
                // Name and frequency joined with punctuation rather than a
                // translated format: "%1$s (%2$s)" is not a sentence, and a
                // msgid that generic collides with every other bracketed pair
                // in the catalogue.
                $overdue[] = $row['frequency_label'] !== ''
                    ? $row['name'] . ' (' . $row['frequency_label'] . ')'
                    : $row['name'];
            }
            if ( $row['reading_count'] === 1 ) $single++;
            if ( $row['date'] !== '' && $row['date'] > $latest ) $latest = $row['date'];
        }

        echo '<section class="tt-meas-cat">';

        echo '<div class="tt-meas-cat__head">';
        echo '<h3 class="tt-meas-cat-title">' . esc_html( $category ) . '</h3>';
        echo '<p class="tt-meas-cat__count">';
        echo esc_html( sprintf(
            /* translators: %d: number of tests in this category. */
            _n( '%d test', '%d tests', count( $rows ), 'talenttrack' ),
            count( $rows )
        ) );
        if ( $latest !== '' ) {
            echo ' <span class="tt-meas-cat__sep" aria-hidden="true">·</span> ';
            echo esc_html( sprintf(
                /* translators: %s: the most recent measuring date in this category. */
                __( 'last measured %s', 'talenttrack' ),
                self::formatDate( $latest )
            ) );
        }
        echo '</p>';
        echo '</div>';

        echo '<div class="tt-meas-reg-wrap">';
        echo '<table class="tt-meas-reg">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Test', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html_x( 'Latest', 'most recent reading of a test', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Measured · standing', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html_x( 'Target', 'the value a test is measured against', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html_x( 'Trend', 'movement of a test over time', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            self::renderRegisterRow( $row );
        }

        echo '</tbody></table></div>';

        if ( $single > 0 || $never !== [] || $overdue !== [] ) {
            echo '<div class="tt-meas-cat__foot">';
            if ( $single > 0 ) {
                echo '<p>' . esc_html( sprintf(
                    /* translators: %d: how many tests have exactly one reading. */
                    _n(
                        'One reading on %d test — a trend needs at least two.',
                        'One reading on %d tests — a trend needs at least two.',
                        $single,
                        'talenttrack'
                    ),
                    $single
                ) ) . '</p>';
            }
            if ( $never !== [] ) {
                echo '<p>' . esc_html( sprintf(
                    /* translators: %s: comma-separated list of test names. */
                    __( 'Never measured: %s.', 'talenttrack' ),
                    implode( ', ', $never )
                ) ) . '</p>';
            }
            if ( $overdue !== [] ) {
                echo '<p>' . esc_html( sprintf(
                    /* translators: %s: comma-separated list of test names with their frequency. */
                    __( 'Overdue: %s.', 'talenttrack' ),
                    implode( ', ', $overdue )
                ) ) . '</p>';
            }
            echo '</div>';
        }

        echo '</section>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderRegisterRow( array $row ): void {
        echo '<tr class="tt-meas-reg__row">';

        echo '<th scope="row" class="tt-meas-reg__test">';
        echo '<span class="tt-meas-name">' . esc_html( (string) $row['name'] ) . '</span>';
        if ( $row['frequency_label'] !== '' ) {
            echo '<span class="tt-meas-freq">' . esc_html( (string) $row['frequency_label'] ) . '</span>';
        }
        echo '</th>';

        echo '<td class="tt-meas-reg__value">';
        echo $row['value'] !== '' ? esc_html( (string) $row['value'] ) : '&mdash;';
        echo '</td>';

        echo '<td class="tt-meas-reg__standing">';
        if ( $row['date'] !== '' ) {
            echo '<span class="tt-meas-reg__date">' . esc_html( self::formatDate( (string) $row['date'] ) ) . '</span>';
        }
        if ( $row['chip_label'] !== '' ) {
            echo '<span class="tt-meas-chip ' . esc_attr( (string) $row['chip_class'] ) . '">'
                . esc_html( (string) $row['chip_label'] ) . '</span>';
        }
        echo '</td>';

        echo '<td class="tt-meas-reg__target">';
        echo $row['target'] !== '' ? esc_html( (string) $row['target'] ) : '&mdash;';
        echo '</td>';

        echo '<td class="tt-meas-reg__trend">';
        echo (string) $row['spark'];
        if ( $row['delta'] !== '' ) {
            echo '<span class="tt-meas-reg__delta">' . esc_html( (string) $row['delta'] ) . '</span>';
        }
        echo '</td>';

        echo '</tr>';

        // The history opens across the register rather than inside the trend
        // column: a chart squeezed into a fifth of the table is not readable,
        // and the disclosure is the same `<details>` it has always been.
        if ( $row['history'] !== '' ) {
            echo '<tr class="tt-meas-reg__historyrow"><td colspan="5">';
            echo '<details class="tt-meas-trend">';
            echo '<summary>' . esc_html_x( 'Trend', 'movement of a test over time', 'talenttrack' ) . '</summary>';
            echo '<div class="tt-meas-trend__body">' . $row['history'] . '</div>';
            echo '</details>';
            echo '</td></tr>';
        }
    }

    /**
     * One test as the register renders it: the presentation decisions in one
     * place, so the row markup stays markup.
     *
     * Nothing here derives a verdict — the flag, the direction and the band
     * all come from `PlayerMeasurementProfile`, which is what the REST
     * controller serves. This puts words beside the colour (CLAUDE.md §2:
     * colour never carries a meaning alone) and nothing more.
     *
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private static function registerRow( array $t ): array {
        $type      = (string) ( $t['value_type'] ?? '' );
        $direction = (string) ( $t['direction'] ?? '' );
        $flag      = (string) ( $t['flag'] ?? '' );
        $unit      = (string) ( $t['unit'] ?? '' );
        $value     = (string) ( $t['latest_value'] ?? '' );
        $series    = is_array( $t['series'] ?? null ) ? (array) $t['series'] : [];

        $readings = 0;
        foreach ( $series as $point ) {
            $p = (array) $point;
            if ( ( $p['value'] ?? null ) !== null || (string) ( $p['text'] ?? '' ) !== '' ) $readings++;
        }
        $never = ( $value === '' && $readings === 0 );

        [ $chip_label, $chip_class ] = self::verdict( $t, $never );
        [ $target, $target_absent ]  = self::target( $t );

        $history = '';
        if ( ! $never && count( $series ) >= 2 ) {
            $history = self::trendBody( $t, $series );
        }

        return [
            'name'            => (string) ( $t['name'] ?? '' ),
            'frequency_label' => self::frequencyLabel( (string) ( $t['frequency'] ?? '' ) ),
            'value'           => $value !== '' && $unit !== '' && self::isNumericType( $type )
                ? $value . ' ' . $unit
                : $value,
            'date'            => (string) ( $t['latest_date'] ?? '' ),
            'chip_label'      => $chip_label,
            'chip_class'      => $chip_class,
            'target'          => $target,
            'target_absent'   => $target_absent,
            'spark'           => self::sparkline( $series ),
            'delta'           => self::signedDelta( $series, $direction, $unit ),
            'history'         => $history,
            'reading_count'   => $readings,
            'never_measured'  => $never,
            'overdue'         => ! empty( $t['overdue'] ),
        ];
    }

    /**
     * The verdict, in words. The table is fixed (#3526, locked decision 3) —
     * colour restates it, never replaces it.
     *
     * A direction-less test gets no chip at all: there is no better or worse
     * to report, and the target column says so once.
     *
     * @param array<string, mixed> $t
     * @return array{0:string, 1:string}
     */
    private static function verdict( array $t, bool $never ): array {
        // Every label here goes through `_x()`. They are two or three words
        // long, which is exactly the length at which a msgid picks up another
        // surface's sense: "on target" was already in the catalogue as "op
        // schema" — on *schedule* — which is a different statement about a
        // different thing, and reusing it would have printed it on a sprint
        // time.
        if ( $never ) {
            return [
                _x( 'not measured yet', 'a test that has never been measured', 'talenttrack' ),
                'tt-meas-chip--none',
            ];
        }

        $type = (string) ( $t['value_type'] ?? '' );

        // A level is its own verdict, already named and already coloured by
        // the operator's own palette. Restating it as "on target" would be
        // inventing a judgement the level vocabulary does not make.
        if ( $type === 'status' ) {
            $token = (string) ( $t['level_token'] ?? '' );
            // The shipped level palette already paints `.tt-meas-value--status`
            // at =4.5:1 across the curated swatches; the chip reuses it rather
            // than opening a second set of level colours to keep in step.
            $class = $token !== ''
                ? 'tt-meas-value--status ' . \TT\Modules\Measurements\Levels\MeasurementLevelPalette::cssClass( $token )
                : 'tt-meas-chip--none';
            return [ (string) ( $t['latest_value'] ?? '' ), $class ];
        }

        if ( $type === 'passfail' ) {
            return [ '', '' ];
        }

        $direction = (string) ( $t['direction'] ?? '' );
        if ( $direction !== 'higher' && $direction !== 'lower' ) {
            return [ '', '' ];
        }

        switch ( (string) ( $t['flag'] ?? '' ) ) {
            case 'ok':
                return [
                    _x( 'on target', 'a test reading meets its target', 'talenttrack' ),
                    'tt-meas-chip--ok',
                ];
            case 'warn':
                return $direction === 'higher'
                    ? [ _x( 'just under target', 'a reading a little below a higher-is-better target', 'talenttrack' ), 'tt-meas-chip--warn' ]
                    : [ _x( 'just over target', 'a reading a little above a lower-is-better target', 'talenttrack' ), 'tt-meas-chip--warn' ];
            case 'bad':
                return $direction === 'higher'
                    ? [ _x( 'well under target', 'a reading far below a higher-is-better target', 'talenttrack' ), 'tt-meas-chip--bad' ]
                    : [ _x( 'well over target', 'a reading far above a lower-is-better target', 'talenttrack' ), 'tt-meas-chip--bad' ];
        }

        return [ '', '' ];
    }

    /**
     * The target the flag is computed against, rendered from the same band the
     * flag uses — so a coach can finally check the colour against something.
     *
     * The band is open on the better side (#3028), which is why it renders as
     * a floor or a ceiling rather than a range. A level-banded test has
     * several thresholds and no single cell can hold them, so it renders an em
     * dash and lets the chip carry the level name (locked decision 2).
     *
     * @param array<string, mixed> $t
     * @return array{0:string, 1:bool} the cell, and whether it means "no target"
     */
    private static function target( array $t ): array {
        $type = (string) ( $t['value_type'] ?? '' );
        if ( $type === 'status' || $type === 'passfail' ) {
            return [ '', false ];
        }

        $direction = (string) ( $t['direction'] ?? '' );
        if ( $direction !== 'higher' && $direction !== 'lower' ) {
            return [ _x( 'no target', 'a test with no better or worse value', 'talenttrack' ), true ];
        }

        $band = is_array( $t['band'] ?? null ) ? (array) $t['band'] : null;
        if ( $band === null ) return [ '', false ];

        $unit = (string) ( $t['unit'] ?? '' );
        $edge = $direction === 'higher' ? ( $band['min'] ?? null ) : ( $band['max'] ?? null );
        if ( $edge === null ) return [ '', false ];

        $number = self::formatNumber( (float) $edge ) . ( $unit !== '' ? ' ' . $unit : '' );

        return [ ( $direction === 'higher' ? '≥ ' : '≤ ' ) . $number, false ];
    }

    /**
     * The movement between the first and last reading, signed, with its sense
     * named rather than left to the slope.
     *
     * `−47 s` on a lower-is-better test is progress and `+420 m` on a
     * higher-is-better test is progress; nothing about the sign says which,
     * which is exactly how a falling line gets read as decline. A test with no
     * direction gets the number and no word — the change is a fact there, not
     * an achievement.
     *
     * @param array<int, mixed> $series
     */
    private static function signedDelta( array $series, string $direction, string $unit ): string {
        $values = [];
        foreach ( $series as $point ) {
            $v = ( (array) $point )['value'] ?? null;
            if ( $v !== null && $v !== '' ) $values[] = (float) $v;
        }
        if ( count( $values ) < 2 ) return '';

        $delta = $values[ count( $values ) - 1 ] - $values[0];
        if ( abs( $delta ) < 0.0001 ) return '0' . ( $unit !== '' ? ' ' . $unit : '' );

        $out = ( $delta > 0 ? '+' : '−' ) . self::formatNumber( abs( $delta ) );
        if ( $unit !== '' ) $out .= ' ' . $unit;

        if ( $direction === 'higher' || $direction === 'lower' ) {
            $forward = $direction === 'higher' ? $delta > 0 : $delta < 0;
            $out .= ' · ' . ( $forward
                ? _x( 'forward', 'a test reading has improved', 'talenttrack' )
                : _x( 'backward', 'a test reading has worsened', 'talenttrack' ) );
        }

        return $out;
    }

    /** Numeric-ish types whose value reads with its unit appended. */
    private static function isNumericType( string $type ): bool {
        return in_array( $type, [ 'numeric', 'scale' ], true );
    }

    /**
     * #3526 — BMI as one register row.
     *
     * Both gates travel with it rather than staying on the player-profile tab:
     * the `report_player_bmi` feature toggle, and #3393's rule that a screening
     * figure about a child's body does not reach the child or their family
     * from a tab. The standalone `?tt_view=measurements` view never applied
     * either, because it never rendered BMI at all.
     *
     * `BmiBlock::renderStanding()` is untouched — the roster report still uses
     * it. This tab simply stops calling it.
     *
     * @return array<string, mixed>|null
     */
    private static function bmiRow( int $player_id ): ?array {
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'report_player_bmi' ) ) return null;
        if ( self::viewerIsFamilyOf( $player_id ) ) return null;

        $series = ( new \TT\Modules\Measurements\Reports\BmiQuery() )->playerSeries( $player_id );
        if ( $series === [] ) return null;

        // `BmiQuery::playerSeries()` only emits a point once it has a usable
        // height and weight pair, so `bmi` is a float by the time it is here;
        // the percentile is the part that can be missing, when the growth
        // reference does not cover this age and sex.
        $latest  = $series[ count( $series ) - 1 ];
        $covered = $latest['sds'] !== null && $latest['percentile'] !== null;

        return [
            'name'            => __( 'BMI-for-age', 'talenttrack' ),
            'frequency_label' => _x( 'derived', 'a value calculated from other measurements', 'talenttrack' ),
            'value'           => number_format_i18n( $latest['bmi'], 1 ),
            'date'            => $latest['date'],
            'chip_label'      => '',
            'chip_class'      => '',
            // The percentile is the standing this row can be read against, so
            // it belongs in the target column. Where the growth reference does
            // not cover the age and sex, saying so there is the whole content
            // of what used to be a 2rem figure followed by an apology.
            'target'          => $covered
                ? sprintf(
                    /* translators: %s: an ordinal percentile, e.g. "62nd". */
                    __( '%s percentile', 'talenttrack' ),
                    \TT\Modules\Measurements\Frontend\BmiBlock::ordinal( (float) $latest['percentile'] )
                )
                : _x( 'no percentile', 'the growth reference does not cover this player', 'talenttrack' ),
            'target_absent'   => false,
            'spark'           => '',
            'delta'           => '',
            'history'         => '',
            'reading_count'   => count( $series ),
            'never_measured'  => false,
            'overdue'         => false,
        ];
    }

    /**
     * Which category the BMI row joins: the one holding the height test it is
     * derived from, so the figure sits beside its own inputs. Falls back to
     * the first category rather than inventing one.
     *
     * @param array<int, array<string, mixed>> $profile
     */
    private static function bmiCategoryIndex( array $profile ): int {
        foreach ( $profile as $index => $cat ) {
            foreach ( (array) ( $cat['tests'] ?? [] ) as $test ) {
                $name = strtolower( trim( (string) ( ( (array) $test )['name'] ?? '' ) ) );
                if ( in_array( $name, \TT\Modules\Measurements\Growth\BmiSeriesBuilder::HEIGHT_NAMES, true ) ) {
                    return (int) $index;
                }
            }
        }
        return 0;
    }

    /**
     * Is the reader this player, or their parent? The same question
     * `FrontendPlayerDetailView` asked before the BMI gate moved onto the row.
     */
    private static function viewerIsFamilyOf( int $player_id ): bool {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return false;

        if ( \TT\Infrastructure\Players\ParentChildResolver::isParentOf( $user_id, $player_id ) ) {
            return true;
        }

        global $wpdb;
        $linked = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id,
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );

        return $linked === $user_id;
    }

    private static function formatNumber( float $v ): string {
        $rounded  = round( $v, 2 );
        $decimals = ( floor( $rounded ) === $rounded ) ? 0 : ( round( $rounded, 1 ) === $rounded ? 1 : 2 );
        return number_format_i18n( $rounded, $decimals );
    }

    /**
     * The expandable history for one test, as markup for the register's
     * disclosure row. The sparkline in the Trend column stays the
     * at-a-glance shape; this is the readable version — dated axis, values,
     * and the age-group target band where one exists.
     *
     * Which body is rendered depends entirely on the test's type: a level or
     * a pass has no numeric axis to plot. Returns '' when there is nothing
     * worth opening, and the caller then renders no disclosure at all.
     *
     * #3526 — the "one result so far" line left this method. It used to be
     * emitted per row, so a player with three single-reading tests met the
     * same apology three times. It is counted once in the category footer now.
     *
     * @param array<string, mixed> $t
     * @param array<int, mixed>    $series
     */
    private static function trendBody( array $t, array $series ): string {
        $type = (string) ( $t['value_type'] ?? '' );

        if ( $type === 'status' ) {
            return self::statusHistory( $series, (int) ( $t['definition_id'] ?? 0 ) );
        }
        if ( $type === 'passfail' ) {
            return self::passFailHistory( $series );
        }
        return self::numericHistory( $t, $series );
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
