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
 *
 * Timezone convention (#2437): a bare `Y-m-d H:i:s` string handed to this
 * class is read as **site-local** — the timezone `current_time( 'mysql' )`
 * writes in, which is what almost every tt_ column stores. That matches
 * core's own `mysql2date()`. Columns that deviate and store UTC (a
 * `gmdate()` / `DateTimeImmutable` write, e.g. `tt_player_reports.expires_at`)
 * render through `dateTimeFromGmt()` instead, which converts first.
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
    /** Request-scoped cache — the retrofit calls these on every date render. */
    private static ?string $preset_cache = null;
    private static ?bool $week_monday_cache = null;

    public static function preset(): string {
        if ( self::$preset_cache !== null ) return self::$preset_cache;
        $preset = ( new ConfigService() )->get( self::FORMAT_KEY, 'system' );
        self::$preset_cache = isset( self::presets()[ $preset ] ) ? $preset : 'system';
        return self::$preset_cache;
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

    /**
     * Format a date *with* its clock time per the academy preset — used
     * for DATETIME values (created/updated stamps, sign-offs). The date
     * part follows the preset; the time is appended as 24-hour `H:i`.
     *
     * @param int|string|\DateTimeInterface|null $when
     */
    public static function dateTime( $when ): string {
        $ts = self::ts( $when );
        if ( $ts === null ) return '';
        return wp_date( self::dateFormat() . ', H:i', $ts );
    }

    /**
     * Format a UTC-stored DATETIME for display (#2437). The plugin's
     * convention is site-local DB strings; this is the escape hatch for
     * the few columns written in UTC — `tt_player_reports.expires_at`,
     * set from a `DateTimeImmutable` under WordPress' UTC default — so
     * the conversion happens once here rather than at each caller.
     */
    public static function dateTimeFromGmt( string $utc_datetime ): string {
        $utc_datetime = trim( $utc_datetime );
        if ( $utc_datetime === '' || $utc_datetime === '0000-00-00 00:00:00' ) return '';
        return self::dateTime( get_date_from_gmt( $utc_datetime ) );
    }

    /** True when the academy week starts on Monday (the default). */
    public static function weekStartsMonday(): bool {
        if ( self::$week_monday_cache !== null ) return self::$week_monday_cache;
        self::$week_monday_cache = ( new ConfigService() )->get( self::WEEK_START_KEY, 'mon' ) !== 'sun';
        return self::$week_monday_cache;
    }

    /**
     * A sample of every preset for today's date, for the live preview in
     * the settings form. Keyed by preset slug.
     *
     * @return array<string, string>
     */
    public static function presetSamples(): array {
        // Plain time(): wp_date() applies the site offset itself, so the
        // offset-shifted current_time( 'timestamp' ) used to double it and
        // could preview tomorrow's date late in the evening (#2437).
        $ts = time();
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
            // A DATETIME out of the database carries no offset, and
            // WordPress pins PHP's default timezone to UTC — so a plain
            // strtotime() reads a site-local stamp as UTC and the wp_date()
            // below then adds the offset a second time (a Spond sync at
            // 20:20 CEST printing as 22:20, #2437). Parsing against
            // wp_timezone() keeps the round-trip honest and mirrors core's
            // mysql2date(). A string that carries its own offset or a
            // trailing Z still wins — date_create() only falls back to the
            // supplied zone when the input names none.
            $dt = date_create( $when, wp_timezone() );
            return $dt !== false ? $dt->getTimestamp() : null;
        }
        return null;
    }
}
