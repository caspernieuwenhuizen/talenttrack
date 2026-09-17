<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamMonthlyReportComposition (#3461, epic #3457) — what a team monthly report
 * is made of, as one plain array: which team, which window, which paper layout,
 * which sections.
 *
 * Three readers take a composition from somewhere untrusted and must agree on
 * what it means: the report page (the URL), a saved view (JSON stored months
 * ago, possibly before a section existed) and the PDF exporter (REST filters).
 * The monthly schedule (#3462) is the fourth, and the reason this is a plain
 * array rather than a reference: a schedule keeps its **own copy**. Saved views
 * are personal — a schedule pointing at one would mail a different document the
 * day its owner renames or deletes it.
 *
 * Forgiving by design. An unknown section is dropped, an unknown layout falls
 * back to the default, a missing section list means every section, and a
 * malformed window falls back to the default period. A composition saved last
 * season must still open a report, not an error.
 *
 * #3514 adds `options`: a map of block key => option bag, for blocks that can
 * be told *what* to show and not merely whether to appear. The composition
 * carries it and never reads it — each block owns the shape of its own
 * options (`TeamMonthlyReportBlockOptions`).
 *
 * @phpstan-type Composition array{team_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}
 */
final class TeamMonthlyReportComposition {

    /** The URL / saved-view parameters a composition is carried in. */
    public const PARAMS = [ 'team_id', 'period', 'from', 'to', 'layout', 'blocks', 'options' ];

    /** The saved-views surface key (`SavedViewsRegistry`). */
    public const VIEW_KEY = 'report-team-monthly';

    /**
     * Which of the reader's saved views this composition is, if any.
     *
     * `active` when a saved view describes exactly this report; `drifted` when
     * none does but the reader has a default view — they opened their usual
     * report and changed it; `none` otherwise.
     *
     * Personal by construction: the repository only returns the reader's own
     * views.
     *
     * @param Composition $current
     * @return array{state:string, name:string}
     */
    public static function savedViewStatus( int $user_id, array $current ): array {
        $views   = ( new \TT\Infrastructure\Filters\SavedViewsRepository() )->listForUser( $user_id, self::VIEW_KEY );
        $default = '';
        foreach ( $views as $view ) {
            $stored = json_decode( (string) ( $view->filters_json ?? '' ), true );
            if ( self::same( self::normalise( is_array( $stored ) ? $stored : [] ), $current ) ) {
                return [ 'state' => 'active', 'name' => (string) ( $view->name ?? '' ) ];
            }
            if ( ! empty( $view->is_default ) ) $default = (string) ( $view->name ?? '' );
        }
        return $default !== ''
            ? [ 'state' => 'drifted', 'name' => $default ]
            : [ 'state' => 'none', 'name' => '' ];
    }

    /**
     * Normalise a raw composition. `blocks` may be a list or a comma-separated
     * string; an empty list means "every section", as the composer reads it.
     *
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
                $period = TeamMonthlyReport::DEFAULT_PERIOD;
            }
        }

        $layout = is_scalar( $raw['layout'] ?? null ) ? strtoupper( trim( (string) $raw['layout'] ) ) : '';
        if ( ! TeamMonthlyReportLayout::isValid( $layout ) ) {
            $layout = TeamMonthlyReportLayout::DEFAULT;
        }

        $list   = $raw['blocks'] ?? [];
        $keys   = is_array( $list ) ? $list : explode( ',', is_scalar( $list ) ? (string) $list : '' );
        $blocks = [];
        foreach ( $keys as $key ) {
            $key = is_scalar( $key ) ? sanitize_key( trim( (string) $key ) ) : '';
            if ( TeamMonthlyReportBlock::isValid( $key ) && ! in_array( $key, $blocks, true ) ) {
                $blocks[] = $key;
            }
        }

        return [
            'team_id' => is_numeric( $raw['team_id'] ?? null ) ? max( 0, (int) $raw['team_id'] ) : 0,
            'period'  => $period,
            'from'    => $from,
            'to'      => $to,
            'layout'  => $layout,
            'blocks'  => $blocks,
            'options' => self::normaliseOptions( $raw['options'] ?? null, $blocks ),
        ];
    }

    /**
     * #3514 — the option bags, keyed by block.
     *
     * Accepts the JSON a URL carries as well as a decoded array, because the
     * same composition arrives from a query string, a saved view's JSON and a
     * schedule's stored copy.
     *
     * Options for a block that is not selected are **dropped**, not kept as
     * ghosts: a composition should say what it renders and nothing else. An
     * empty block list means every block, so nothing is dropped then.
     *
     * @param mixed $raw
     * @param list<string> $blocks
     * @return array<string,array<string,mixed>>
     */
    private static function normaliseOptions( $raw, array $blocks ): array {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $raw     = is_array( $decoded ) ? $decoded : [];
        }
        if ( ! is_array( $raw ) ) return [];

        $selected = $blocks === [] ? TeamMonthlyReportBlock::ALL : $blocks;

        $out = [];
        foreach ( $raw as $block => $bag ) {
            $key = is_scalar( $block ) ? sanitize_key( (string) $block ) : '';
            if ( ! TeamMonthlyReportBlock::isValid( $key ) ) continue;
            if ( ! in_array( $key, $selected, true ) ) continue;
            if ( ! is_array( $bag ) ) continue;

            $bag = TeamMonthlyReportBlockOptions::normalise( $key, $bag );
            if ( $bag !== [] ) $out[ $key ] = $bag;
        }

        ksort( $out );
        return $out;
    }

    /**
     * #3514 — what a strict caller should refuse: option keys no block
     * recognises, as `block.key`.
     *
     * The screen drops these; REST refuses them. A filter that is silently
     * ignored renders a document nobody asked for, which is worse on a report
     * than an error would be.
     *
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function unknownOptions( array $raw ): array {
        $options = $raw['options'] ?? null;
        if ( is_string( $options ) ) {
            $decoded = json_decode( $options, true );
            $options = is_array( $decoded ) ? $decoded : [];
        }
        if ( ! is_array( $options ) ) return [];

        $unknown = [];
        foreach ( $options as $block => $bag ) {
            $key = is_scalar( $block ) ? sanitize_key( (string) $block ) : '';
            if ( ! TeamMonthlyReportBlock::isValid( $key ) ) {
                $unknown[] = (string) $block;
                continue;
            }
            if ( ! is_array( $bag ) ) continue;

            foreach ( TeamMonthlyReportBlockOptions::unknownKeys( $key, $bag ) as $bad ) {
                $unknown[] = $bad;
            }
        }
        return $unknown;
    }

    /**
     * The options for one block, ready for the composer. Always an array, so a
     * block never has to ask whether it was given anything.
     *
     * @param Composition $composition
     * @return array<string,mixed>
     */
    public static function optionsFor( array $composition, string $block ): array {
        $options = $composition['options'] ?? [];
        return is_array( $options[ $block ] ?? null ) ? $options[ $block ] : [];
    }

    /**
     * The window a composition covers, resolved against `$today`. A period is
     * resolved at the moment it is read, so "last month" saved in August means
     * September when it runs in October.
     *
     * @param Composition $composition
     * @return array{from:string, to:string, period:string}
     */
    public static function window( array $composition, string $today ): array {
        if ( $composition['period'] === '' ) {
            return [ 'from' => $composition['from'], 'to' => $composition['to'], 'period' => '' ];
        }
        $window = ReportFilters::periodWindow( $composition['period'], $today );
        if ( $window === null ) {
            // Only a malformed `$today` gets here: normalise() admits known periods only.
            return [ 'from' => $today, 'to' => $today, 'period' => $composition['period'] ];
        }
        return [ 'from' => $window['from'], 'to' => $window['to'], 'period' => $composition['period'] ];
    }

    /**
     * Two compositions describe the same report. Section order carries no
     * meaning (the report prints in its own order), and an empty list is the
     * same as naming every section.
     *
     * @param Composition $a
     * @param Composition $b
     */
    public static function same( array $a, array $b ): bool {
        return self::key( $a ) === self::key( $b );
    }

    /** @param Composition $c */
    private static function key( array $c ): string {
        $blocks = TeamMonthlyReportBlock::normalise( $c['blocks'] );

        // #3514 — options are part of what a composition *is*. Without them a
        // saved view showing the sprint test would report itself "active"
        // while the reader looks at the jump test.
        $options = $c['options'] ?? [];
        $options = is_array( $options ) ? $options : [];
        ksort( $options );
        $encoded = (string) wp_json_encode( $options );

        return implode( '|', [ $c['team_id'], $c['period'], $c['from'], $c['to'], $c['layout'], implode( ',', $blocks ), $encoded ] );
    }
}
