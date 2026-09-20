<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\CurrentClub;

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
    /**
     * #3802 — seed a default set of windows when none has ever been saved.
     *
     * The write route has existed since #3610 and the report has depended on
     * these windows for longer than that, but nothing ever created one. So a
     * fresh or demo install had none, `EvalCoverageService::coverage()`
     * reported a structural zero for every player, and the head of
     * development read a clean bill of health while nobody had been
     * evaluated for weeks. The feature was working and invisible.
     *
     * The seed is derived from the club's own season rather than an invented
     * calendar: four equal windows across it, so "chase the coaches" has
     * something real to measure against on day one. It is a **starting
     * point, not a policy** — the head of development edits or replaces them
     * through the route that already exists.
     *
     * Only ever fills an absent key. An install that has configured windows,
     * or has deliberately saved an empty list, is left alone.
     *
     * @return list<array{name:string,start:string,end:string}> The windows now configured.
     */
    public function seedDefaultsIfAbsent(): array {
        // "Never set" and "set to an empty list" must be told apart:
        // `getJson()` returns [] for both, and re-seeding the second would
        // undo a deliberate clearing — an administrator who emptied the
        // windows must not find four of them back tomorrow.
        //
        // A sentinel default through `ConfigService::get()` does NOT work
        // for this. It memoises per key, so any earlier read of the key in
        // the same request — `all()` on the line above a caller, say —
        // caches the '' default and the sentinel never comes back. The
        // existence question goes straight to the table instead.
        if ( self::keyExists() ) {
            return $this->all();
        }

        [ $start, $end ] = self::seasonSpan();
        $from = strtotime( $start );
        $to   = strtotime( $end );
        if ( $from === false || $to === false || $to <= $from ) return [];

        $step    = (int) floor( ( $to - $from ) / 4 );
        $windows = [];
        for ( $i = 0; $i < 4; $i++ ) {
            $w_start = $from + ( $step * $i );
            $w_end   = $i === 3 ? $to : ( $from + ( $step * ( $i + 1 ) ) - DAY_IN_SECONDS );
            $windows[] = [
                'name'  => sprintf(
                    /* translators: %d: the quarter of the season, 1-4. */
                    __( 'Round %d', 'talenttrack' ),
                    $i + 1
                ),
                'start' => gmdate( 'Y-m-d', $w_start ),
                'end'   => gmdate( 'Y-m-d', $w_end ),
            ];
        }

        return $this->save( $windows );
    }

    /**
     * Has this club ever saved a window list? Reads the config table
     * directly, bypassing `ConfigService`'s per-key memo, which is what
     * makes this answer "never set" rather than "reads as empty".
     */
    private static function keyExists(): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}tt_config
              WHERE club_id = %d AND config_key = %s
              LIMIT 1",
            CurrentClub::id(),
            self::CONFIG_KEY
        ) );
        return $found !== null;
    }

    /**
     * The club's season, or a plausible academy year when no season is
     * configured. Returns [start, end] as Y-m-d.
     *
     * @return array{0:string,1:string}
     */
    private static function seasonSpan(): array {
        if ( class_exists( '\\TT\\Modules\\Pdp\\Repositories\\SeasonsRepository' ) ) {
            $season = ( new \TT\Modules\Pdp\Repositories\SeasonsRepository() )->current();
            if ( $season && ! empty( $season->start_date ) && ! empty( $season->end_date ) ) {
                return [ (string) $season->start_date, (string) $season->end_date ];
            }
        }

        // No season configured: the northern-hemisphere academy year the
        // rest of the product assumes, anchored on today so a mid-season
        // install seeds the season it is actually in.
        $year = (int) gmdate( 'n' ) >= 8 ? (int) gmdate( 'Y' ) : (int) gmdate( 'Y' ) - 1;
        return [ sprintf( '%d-08-01', $year ), sprintf( '%d-06-30', $year + 1 ) ];
    }

    /**
     * Every configured window, earliest first. Invalid entries are dropped.
     *
     * The shape is spelled out rather than named: `EvalWindow` is declared
     * in EvalCoverageService and is not visible from here.
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
