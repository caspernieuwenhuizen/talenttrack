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
 * @phpstan-type Composition array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>}
 */
final class PlayerReportComposition {

    /** The URL / saved-view parameters a composition is carried in. */
    public const PARAMS = [ 'player_id', 'period', 'from', 'to', 'layout', 'blocks' ];

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

        return [
            'player_id' => is_numeric( $raw['player_id'] ?? null ) ? max( 0, (int) $raw['player_id'] ) : 0,
            'period'    => $period,
            'from'      => $from,
            'to'        => $to,
            'layout'    => $layout,
            'blocks'    => self::blocks( $raw['blocks'] ?? [] ),
        ];
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
