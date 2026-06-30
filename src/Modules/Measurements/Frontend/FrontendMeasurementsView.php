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

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $player_id );

        if ( empty( $profile ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No tests have been set up yet.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-meas">';
        foreach ( $profile as $cat ) {
            echo '<section class="tt-meas-cat">';
            echo '<h3 class="tt-meas-cat-title">' . esc_html( (string) $cat['category'] ) . '</h3>';
            echo '<ul class="tt-meas-list">';
            foreach ( (array) $cat['tests'] as $test ) {
                self::renderTestRow( (array) $test );
            }
            echo '</ul>';
            echo '</section>';
        }
        echo '</div>';
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

        echo '</li>';
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
