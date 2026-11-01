<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * The academy's "This week" settings, per persona (#4492): whether the panel
 * shows, also on phones, which week and which teams it covers, how past days
 * show, and which blocks are on for desktop and for phone.
 *
 * Stored in `tt_config` under `coach_week.config` as JSON keyed by persona.
 * When that key is absent, the older `coach_week.personas` list decides who
 * sees the panel and every persona takes its defaults: no schema migration.
 */
final class WeekSettings {

    public const CONFIG_KEY = 'coach_week.config';

    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_PHONE   = 'phone';
    public const DEVICES        = [ self::DEVICE_DESKTOP, self::DEVICE_PHONE ];

    public const WINDOW_WEEK    = 'week';
    public const WINDOW_ROLLING = 'rolling';

    public const TEAMS_COACHED = 'coached';
    public const TEAMS_ALL     = 'all';

    public const PAST_SHOW = 'show';
    public const PAST_FOLD = 'fold';
    public const PAST_HIDE = 'hide';

    /** Personas that see the panel by default once the academy saves the new settings. */
    private const ON_BY_DEFAULT = [ 'head_coach', 'assistant_coach', 'team_manager' ];

    /**
     * Every persona's settings, normalised: the stored value, or the legacy
     * persona list with defaults when nothing is stored.
     *
     * @param list<string> $personas     the personas the setting may name
     * @param list<string> $legacy_on    the personas the older list switches on
     * @return array<string, array{on:bool, phone_on:bool, window:string, teams:string, past:string, desktop:list<string>, phone:list<string>}>
     */
    public static function effective( array $personas, array $legacy_on ): array {
        $raw = trim( ( new ConfigService() )->get( self::CONFIG_KEY, '' ) );
        if ( $raw === '' ) {
            // Never saved: a persona the older list switched on keeps the
            // whole desktop panel it had, phones take the shorter defaults.
            $out = [];
            foreach ( $personas as $persona ) {
                $settings = self::defaultsFor( $persona );
                $on       = in_array( $persona, $legacy_on, true );
                $settings['on']       = $on;
                $settings['phone_on'] = $on;
                if ( $on ) $settings['desktop'] = self::fullDesktopFor( $persona, $personas );
                $out[ $persona ] = $settings;
            }
            return $out;
        }
        $decoded = json_decode( $raw, true );
        return self::normalise( is_array( $decoded ) ? $decoded : [], $personas );
    }

    /**
     * A persona's settings when the academy has not chosen.
     *
     * @return array{on:bool, phone_on:bool, window:string, teams:string, past:string, desktop:list<string>, phone:list<string>}
     */
    public static function defaultsFor( string $persona ): array {
        $on = in_array( $persona, self::ON_BY_DEFAULT, true );
        $blocks = [ self::DEVICE_DESKTOP => [], self::DEVICE_PHONE => [] ];
        foreach ( WeekBlockRegistry::all() as $key => $block ) {
            foreach ( self::DEVICES as $device ) {
                if ( $block->defaultFor( $persona, $device ) ) $blocks[ $device ][] = $key;
            }
        }
        return [
            'on'       => $on,
            'phone_on' => $on,
            'window'   => self::WINDOW_WEEK,
            'teams'    => self::TEAMS_COACHED,
            'past'     => self::PAST_SHOW,
            'desktop'  => $blocks[ self::DEVICE_DESKTOP ],
            'phone'    => $blocks[ self::DEVICE_PHONE ],
        ];
    }

    /**
     * The whole desktop panel for a persona: every block some persona has
     * on for desktop by default, less the blocks this persona must opt in
     * to. What a persona on the older list saw before the settings existed.
     *
     * @param list<string> $personas every persona the setting may name
     * @return list<string>
     */
    public static function fullDesktopFor( string $persona, array $personas ): array {
        $out = [];
        foreach ( WeekBlockRegistry::all() as $key => $block ) {
            if ( $block->optInFor( $persona ) ) continue;
            foreach ( $personas as $any ) {
                if ( $block->defaultFor( $any, self::DEVICE_DESKTOP ) ) {
                    $out[] = $key;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Whether a stored or posted value can be read as settings at all: a
     * JSON object. Anything else is refused rather than reset to defaults.
     */
    public static function isReadable( string $raw ): bool {
        $raw = trim( $raw );
        // `[]` decodes like `{}`, but it is a list, not settings: refusing it
        // keeps a stray empty list from resetting every persona.
        if ( str_starts_with( $raw, '[' ) ) return false;
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) && ( $decoded === [] || ! array_is_list( $decoded ) );
    }

    /**
     * Normalise a decoded value: known personas only, a missing persona or
     * field takes its default, unknown block keys are dropped and the
     * remaining ones are kept in registry order.
     *
     * @param array<mixed, mixed> $decoded
     * @param list<string> $personas
     * @return array<string, array{on:bool, phone_on:bool, window:string, teams:string, past:string, desktop:list<string>, phone:list<string>}>
     */
    public static function normalise( array $decoded, array $personas ): array {
        $out = [];
        foreach ( $personas as $persona ) {
            $defaults = self::defaultsFor( $persona );
            $given    = isset( $decoded[ $persona ] ) && is_array( $decoded[ $persona ] ) ? $decoded[ $persona ] : null;
            if ( $given === null ) {
                $out[ $persona ] = $defaults;
                continue;
            }
            $out[ $persona ] = [
                'on'       => self::flag( $given['on'] ?? null, $defaults['on'] ),
                'phone_on' => self::flag( $given['phone_on'] ?? null, $defaults['phone_on'] ),
                'window'   => self::choice( $given['window'] ?? null, [ self::WINDOW_WEEK, self::WINDOW_ROLLING ], $defaults['window'] ),
                'teams'    => self::choice( $given['teams'] ?? null, [ self::TEAMS_COACHED, self::TEAMS_ALL ], $defaults['teams'] ),
                'past'     => self::choice( $given['past'] ?? null, [ self::PAST_SHOW, self::PAST_FOLD, self::PAST_HIDE ], $defaults['past'] ),
                'desktop'  => isset( $given['desktop'] ) ? self::blockKeys( $given['desktop'] ) : $defaults['desktop'],
                'phone'    => isset( $given['phone'] ) ? self::blockKeys( $given['phone'] ) : $defaults['phone'],
            ];
        }
        return $out;
    }

    /**
     * The stored form: canonical JSON of the normalised settings.
     *
     * @param list<string> $personas
     */
    public static function normaliseStored( string $raw, array $personas ): string {
        $decoded = json_decode( trim( $raw ), true );
        return (string) wp_json_encode( self::normalise( is_array( $decoded ) ? $decoded : [], $personas ) );
    }

    /** @param mixed $value */
    private static function flag( $value, bool $fallback ): bool {
        if ( is_bool( $value ) ) return $value;
        if ( $value === 1 || $value === '1' || $value === 'true' ) return true;
        if ( $value === 0 || $value === '0' || $value === 'false' ) return false;
        return $fallback;
    }

    /**
     * @param mixed $value
     * @param list<string> $allowed
     */
    private static function choice( $value, array $allowed, string $fallback ): string {
        return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function blockKeys( $value ): array {
        if ( ! is_array( $value ) ) return [];
        $given = [];
        foreach ( $value as $key ) {
            if ( is_string( $key ) ) $given[ $key ] = true;
        }
        return array_values( array_filter( WeekBlockRegistry::keys(), static fn( string $key ): bool => isset( $given[ $key ] ) ) );
    }
}
