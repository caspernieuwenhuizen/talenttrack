<?php
namespace TT\Shared\Dates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * TTDate — single chokepoint for academy-wide date presentation (#1481).
 *
 * The academy picks a date-notation preset, a first-day-of-week, and
 * timezone/locale defaults in General settings (`?config_sub=general`),
 * stored club-scoped in `tt_config`. Every surface that prints a date
 * should resolve its format through here so the academy's choice is
 * honoured in one place rather than re-deciding the format at 100 call
 * sites.
 *
 * Backwards-compatible by default: the `system` preset resolves to the
 * WordPress Settings → date format, so an install that never touches the
 * setting renders exactly as before. Surfaces are migrated onto this
 * helper incrementally (the broad `wp_date()` retrofit is a follow-up
 * slice of #1481); until a surface adopts it, nothing changes.
 */
class TTDate {

    public const FORMAT_KEY    = 'tt_date_format';
    public const WEEK_START_KEY = 'tt_week_start';
    public const TIMEZONE_KEY  = 'tt_timezone';
    public const LOCALE_KEY    = 'tt_locale';

    /**
     * Preset slug → PHP date() format string. `system` is null — it
     * defers to the WordPress date-format option.
     *
     * @return array<string, ?string>
     */
    public static function presets(): array {
        return [
            'system'    => null,
            'dmy_dash'  => 'd-m-Y',
            'dmy_slash' => 'd/m/Y',
            'dmy_dot'   => 'd.m.Y',
            'mdy_slash' => 'm/d/Y',
            'iso'       => 'Y-m-d',
            'long'      => 'j F Y',
        ];
    }

    /**
     * Human labels for the preset picker, each with a worked example so
     * the operator sees exactly what they're choosing.
     *
     * @return array<string, string>
     */
    public static function presetLabels(): array {
        return [
            'system'    => __( 'System default (WordPress setting)', 'talenttrack' ),
            'dmy_dash'  => __( 'Day-Month-Year — 31-12-2026', 'talenttrack' ),
            'dmy_slash' => __( 'Day/Month/Year — 31/12/2026', 'talenttrack' ),
            'dmy_dot'   => __( 'Day.Month.Year — 31.12.2026', 'talenttrack' ),
            'mdy_slash' => __( 'Month/Day/Year — 12/31/2026', 'talenttrack' ),
            'iso'       => __( 'ISO — 2026-12-31', 'talenttrack' ),
            'long'      => __( 'Long — 31 December 2026', 'talenttrack' ),
        ];
    }

    /** The configured preset slug (defaults to `system`). */
    public static function preset(): string {
        $preset = ( new ConfigService() )->get( self::FORMAT_KEY, 'system' );
        return isset( self::presets()[ $preset ] ) ? $preset : 'system';
    }

    /**
     * The PHP date() format string to feed `wp_date()`. Resolves the
     * configured preset, falling back to the WordPress date-format
     * option for `system`.
     */
    public static function dateFormat(): string {
        $fmt = self::presets()[ self::preset() ] ?? null;
        if ( $fmt !== null ) return $fmt;
        $opt = get_option( 'date_format' );
        return ( is_string( $opt ) && $opt !== '' ) ? $opt : 'Y-m-d';
    }

    /**
     * Format a date for display per the academy preset. Accepts a unix
     * timestamp, a `Y-m-d` (or any strtotime-parseable) string, or a
     * \DateTimeInterface. Returns '' for unparseable input.
     *
     * @param int|string|\DateTimeInterface|null $when
     */
    public static function date( $when ): string {
        $ts = self::ts( $when );
        if ( $ts === null ) return '';
        return wp_date( self::dateFormat(), $ts );
    }

    /** True when the academy week starts on Monday (the default). */
    public static function weekStartsMonday(): bool {
        return ( new ConfigService() )->get( self::WEEK_START_KEY, 'mon' ) !== 'sun';
    }

    /**
     * A sample of every preset for today's date, for the live preview in
     * the settings form. Keyed by preset slug.
     *
     * @return array<string, string>
     */
    public static function presetSamples(): array {
        $ts = self::ts( current_time( 'timestamp' ) ) ?? time();
        $out = [];
        foreach ( self::presets() as $slug => $fmt ) {
            if ( $fmt === null ) {
                $opt = get_option( 'date_format' );
                $fmt = ( is_string( $opt ) && $opt !== '' ) ? $opt : 'Y-m-d';
            }
            $out[ $slug ] = wp_date( $fmt, $ts );
        }
        return $out;
    }

    /**
     * @param int|string|\DateTimeInterface|null $when
     */
    private static function ts( $when ): ?int {
        if ( $when instanceof \DateTimeInterface ) return $when->getTimestamp();
        if ( is_int( $when ) ) return $when;
        if ( is_numeric( $when ) ) return (int) $when;
        if ( is_string( $when ) && $when !== '' ) {
            $t = strtotime( $when );
            return $t !== false ? $t : null;
        }
        return null;
    }
}
