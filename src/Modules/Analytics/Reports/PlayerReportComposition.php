<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerReportComposition (#3872, epic #3871) — what a player report is made
 * of, as one plain array: which player, which window, which blocks.
 *
 * The same shape arrives from a URL, a saved view stored months ago and a
 * schedule's own copy, so it is forgiving the way the team composition is: an
 * unknown block is dropped, a malformed window falls back to the default. A
 * composition saved last season must still open a report.
 *
 * The default window is **season to date** — the current season's start
 * through today — rather than the team report's last month. A conversation
 * with a player is about the campaign so far. It is the same window, from the
 * same helper, that the attendance and minutes reports open on, and it is
 * carried as an empty period so a saved view keeps meaning "this season so
 * far" next month.
 *
 * #3989 adds `options`: a map of block key => option bag, for blocks that can
 * be told more than whether to appear, as on the team report (#3514). Which
 * block owns which options is `PlayerReportBlockOptions`.
 *
 * @phpstan-type Composition array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}
 */
final class PlayerReportComposition {

    /** The URL / saved-view parameters a composition is carried in. */
    public const PARAMS = [ 'player_id', 'period', 'from', 'to', 'layout', 'blocks', 'options' ];

    /** Season start through today. */
    public const DEFAULT_PERIOD = '';

    /**
     * @param array<string,mixed> $raw
     * @return Composition
     */
    public static function normalise( array $raw ): array {
        $ymd  = '/^\d{4}-\d{2}-\d{2}$/';
        $from = is_scalar( $raw['from'] ?? null ) ? (string) $raw['from'] : '';
        $to   = is_scalar( $raw['to'] ?? null ) ? (string) $raw['to'] : '';

        $period = is_scalar( $raw['period'] ?? null ) ? sanitize_key( (string) $raw['period'] ) : '';
        if ( preg_match( $ymd, $from ) && preg_match( $ymd, $to ) && $from <= $to ) {
            // An explicit window wins; a period alongside it is stale.
            $period = '';
        } else {
            $from = '';
            $to   = '';
            if ( ! in_array( $period, ReportFilters::PERIODS, true ) ) {
                $period = self::DEFAULT_PERIOD;
            }
        }

        $layout = is_scalar( $raw['layout'] ?? null ) ? strtoupper( trim( (string) $raw['layout'] ) ) : '';
        if ( ! PlayerReportLayout::isValid( $layout ) ) {
            $layout = PlayerReportLayout::DEFAULT;
        }

        $blocks = self::blocks( $raw['blocks'] ?? [] );

        return [
            'player_id' => is_numeric( $raw['player_id'] ?? null ) ? max( 0, (int) $raw['player_id'] ) : 0,
            'period'    => $period,
            'from'      => $from,
            'to'        => $to,
            'layout'    => $layout,
            'blocks'    => $blocks,
            'options'   => self::normaliseOptions( $raw['options'] ?? null, $blocks ),
        ];
    }

    /**
     * #3989 — the option bags, keyed by block. Accepts the JSON a URL carries
     * as well as a decoded array. Options for a block that is not selected
     * are dropped; an empty block list means the default sections, which is
     * where they are looked for then.
     *
     * @param mixed        $raw
     * @param list<string> $blocks
     * @return array<string,array<string,mixed>>
     */
    public static function normaliseOptions( $raw, array $blocks ): array {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $raw     = is_array( $decoded ) ? $decoded : [];
        }
        if ( ! is_array( $raw ) ) return [];

        $selected = $blocks === [] ? PlayerReportBlock::DEFAULT_BLOCKS : $blocks;

        $out = [];
        foreach ( $raw as $block => $bag ) {
            $key = sanitize_key( (string) $block );
            if ( ! PlayerReportBlock::isValid( $key ) ) continue;
            if ( ! in_array( $key, $selected, true ) ) continue;
            if ( ! is_array( $bag ) ) continue;

            $bag = PlayerReportBlockOptions::normalise( $key, $bag );
            if ( $bag !== [] ) $out[ $key ] = $bag;
        }

        ksort( $out );
        return $out;
    }

    /**
     * #3989 — what a strict caller refuses: option keys no block recognises,
     * as `block.key`, and blocks that do not exist. The screen drops these.
     *
     * @param mixed $options the `options` value, JSON or decoded
     * @return list<string>
     */
    public static function unknownOptions( $options ): array {
        if ( is_string( $options ) ) {
            if ( trim( $options ) === '' ) return [];
            $decoded = json_decode( $options, true );
            if ( ! is_array( $decoded ) ) return [ 'options' ];
            $options = $decoded;
        }
        if ( $options === null ) return [];
        if ( ! is_array( $options ) ) return [ 'options' ];

        $unknown = [];
        foreach ( $options as $block => $bag ) {
            $key = sanitize_key( (string) $block );
            if ( ! PlayerReportBlock::isValid( $key ) ) {
                $unknown[] = (string) $block;
                continue;
            }
            if ( ! is_array( $bag ) ) {
                $unknown[] = $key;
                continue;
            }
            foreach ( PlayerReportBlockOptions::unknownKeys( $key, $bag ) as $bad ) {
                $unknown[] = $bad;
            }
        }
        return $unknown;
    }

    /**
     * The options for one block. Always an array, so a block never has to
     * ask whether it was given anything.
     *
     * @param array<string,array<string,mixed>> $options a composition's `options`
     * @return array<string,mixed>
     */
    public static function optionsFor( array $options, string $block ): array {
        return is_array( $options[ $block ] ?? null ) ? $options[ $block ] : [];
    }

    /**
     * Block keys from a list or a comma-separated string, lower-cased and
     * trimmed but **not** validated — a strict caller refuses the unknown ones
     * with `PlayerReportBlock::unknown()`, a forgiving one drops them.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function rawBlocks( $raw ): array {
        $keys = is_array( $raw ) ? $raw : explode( ',', is_scalar( $raw ) ? (string) $raw : '' );
        $out  = [];
        foreach ( $keys as $key ) {
            $key = is_scalar( $key ) ? sanitize_key( trim( (string) $key ) ) : '';
            if ( $key !== '' && ! in_array( $key, $out, true ) ) $out[] = $key;
        }
        return $out;
    }

    /**
     * The window a composition covers, resolved when it is read — so "last
     * month" saved in August means September when it runs in October, and the
     * default means this season so far whenever it is opened.
     *
     * @param Composition $composition
     * @return array{from:string, to:string, period:string}
     */
    public static function window( array $composition, string $today ): array {
        if ( $composition['from'] !== '' && $composition['to'] !== '' ) {
            return [ 'from' => $composition['from'], 'to' => $composition['to'], 'period' => '' ];
        }
        if ( $composition['period'] !== '' ) {
            $window = ReportFilters::periodWindow( $composition['period'], $today );
            if ( $window !== null ) {
                return [ 'from' => $window['from'], 'to' => $window['to'], 'period' => $composition['period'] ];
            }
        }
        $default = ReportFilters::seasonDefaultWindow();
        return [ 'from' => $default['from'], 'to' => $default['to'], 'period' => self::DEFAULT_PERIOD ];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function blocks( $raw ): array {
        return array_values( array_filter( self::rawBlocks( $raw ), [ PlayerReportBlock::class, 'isValid' ] ) );
    }
}
