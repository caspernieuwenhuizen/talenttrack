<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * EvalWindowsRepository (#1380) — read / write the evaluation windows
 * stored as a JSON list in tt_config under `eval_windows`.
 *
 * A window is `{name, start (YYYY-MM-DD), end (YYYY-MM-DD)}`. The list
 * is club-scoped through ConfigService (which keys tt_config on
 * `club_id`), so each tenant carries its own set without code changes.
 *
 * Config-based by design: no new entity, no reminders. The windows
 * describe the current season's evaluation periods.
 */
final class EvalWindowsRepository {

    public const CONFIG_KEY = 'eval_windows';

    private ConfigService $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /**
     * Return the validated, chronologically-sorted window list.
     *
     * @return list<array{name:string,start:string,end:string}>
     */
    public function all(): array {
        $raw = $this->config->getJson( self::CONFIG_KEY, [] );
        $out = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) continue;
            $window = self::normalise(
                (string) ( $row['name']  ?? '' ),
                (string) ( $row['start'] ?? '' ),
                (string) ( $row['end']   ?? '' )
            );
            if ( $window !== null ) {
                $out[] = $window;
            }
        }
        usort( $out, static fn( array $a, array $b ): int => strcmp( $a['start'], $b['start'] ) );
        return $out;
    }

    /**
     * Replace the whole window list. Invalid entries are dropped.
     *
     * @param array<int,array<string,mixed>> $windows
     * @return list<array{name:string,start:string,end:string}> the stored set
     */
    public function save( array $windows ): array {
        $clean = [];
        foreach ( $windows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $window = self::normalise(
                (string) ( $row['name']  ?? '' ),
                (string) ( $row['start'] ?? '' ),
                (string) ( $row['end']   ?? '' )
            );
            if ( $window !== null ) {
                $clean[] = $window;
            }
        }
        usort( $clean, static fn( array $a, array $b ): int => strcmp( $a['start'], $b['start'] ) );
        $encoded = wp_json_encode( array_values( $clean ) );
        $this->config->set( self::CONFIG_KEY, $encoded === false ? '[]' : $encoded );
        return $clean;
    }

    /**
     * Validate a single window. Returns null when the name is empty, a
     * date is not YYYY-MM-DD, or end falls before start.
     *
     * @return array{name:string,start:string,end:string}|null
     */
    public static function normalise( string $name, string $start, string $end ): ?array {
        $name  = trim( wp_strip_all_tags( $name ) );
        $start = trim( $start );
        $end   = trim( $end );
        if ( $name === '' ) return null;
        if ( ! self::isDate( $start ) || ! self::isDate( $end ) ) return null;
        if ( $end < $start ) return null;
        return [ 'name' => $name, 'start' => $start, 'end' => $end ];
    }

    private static function isDate( string $value ): bool {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return false;
        [ $y, $m, $d ] = array_map( 'intval', explode( '-', $value ) );
        return checkdate( $m, $d, $y );
    }
}
