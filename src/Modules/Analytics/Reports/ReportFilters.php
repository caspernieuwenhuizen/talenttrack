<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * ReportFilters (#2136) — the single source of truth for the attendance
 * reports' period quick-pills and activity-type filter, so the team report
 * and the player report can't drift.
 *
 * The attendance reports are retrospective, so the period vocabulary is
 * past-oriented (Last week / This month / This season) — distinct from the
 * forward-looking pills the activities list (`FrontendActivitiesManageView`)
 * offers. The resolution still mirrors that view's `periodWindow()`: each
 * key resolves to an inclusive `[from, to]` `Y-m-d` window in PHP, never in
 * SQL (CLAUDE.md §4 keeps date math out of the query).
 */
final class ReportFilters {

    /** Period keys this surface accepts. The empty string = manual range. */
    public const PERIODS = [ 'last_week', 'this_month', 'this_season' ];

    /**
     * Human labels for the period pills, keyed by period key. The empty
     * key is the "custom range" / manual fallback.
     *
     * @return array<string,string>
     */
    public static function periodLabels(): array {
        return [
            ''            => __( 'Custom range', 'talenttrack' ),
            'last_week'   => __( 'Last week', 'talenttrack' ),
            'this_month'  => __( 'This month', 'talenttrack' ),
            'this_season' => __( 'This season', 'talenttrack' ),
        ];
    }

    /**
     * Sanitize a raw `?period=` value to a known key (or '').
     */
    public static function sanitizePeriod( string $period ): string {
        return in_array( $period, self::PERIODS, true ) ? $period : '';
    }

    /**
     * Resolve a period key to an inclusive [from, to] Y-m-d window. Most
     * keys are past-oriented; `this_season` spans the whole configured
     * season (start through the season's own end date, which may be in the
     * future). Returns null for the empty / unknown key (caller keeps the
     * manual From/To range).
     *
     * @return array{from:string,to:string}|null
     */
    public static function periodWindow( string $period, string $today ): ?array {
        if ( $period === '' ) return null;
        $base = strtotime( $today );
        if ( $base === false ) return null;

        switch ( $period ) {
            case 'last_week':
                // The full ISO week (Mon–Sun) immediately before this one.
                $dow         = (int) gmdate( 'N', $base ); // 1 = Monday
                $this_monday = $base - ( $dow - 1 ) * DAY_IN_SECONDS;
                $last_monday = $this_monday - 7 * DAY_IN_SECONDS;
                return [
                    'from' => gmdate( 'Y-m-d', $last_monday ),
                    'to'   => gmdate( 'Y-m-d', $last_monday + 6 * DAY_IN_SECONDS ),
                ];

            case 'this_month':
                // Month-to-date: first of the month through today.
                return [ 'from' => gmdate( 'Y-m-01', $base ), 'to' => gmdate( 'Y-m-d', $base ) ];

            case 'this_season':
                if ( ! class_exists( '\\TT\\Modules\\Pdp\\Repositories\\SeasonsRepository' ) ) return null;
                $season = ( new \TT\Modules\Pdp\Repositories\SeasonsRepository() )->current();
                if ( ! $season || empty( $season->start_date ) || empty( $season->end_date ) ) return null;
                // Span the whole season — start through the season's own end
                // date, not clamped to today. The silent default window
                // (seasonDefaultWindow) stays today-bounded for the
                // retrospective default; the explicit pill is full-season.
                return [ 'from' => (string) $season->start_date, 'to' => (string) $season->end_date ];
        }

        return null;
    }

    /**
     * The default From/To window a report seeds when the user has picked
     * neither a period pill nor a manual range. Starts at the current
     * season's start date and ends today, so the default matches how the
     * academy thinks about the year (and equals the `This season` pill's
     * From — it reuses the same SeasonsRepository resolver as
     * `periodWindow('this_season', …)`). Falls back to a 90-day rolling
     * window when the seasons repository is unavailable, no current season
     * exists, or its start date is empty, so a report never fatals or
     * renders empty just because no season is configured.
     *
     * @return array{from:string,to:string}
     */
    public static function seasonDefaultWindow(): array {
        $to = gmdate( 'Y-m-d' );
        if ( class_exists( '\\TT\\Modules\\Pdp\\Repositories\\SeasonsRepository' ) ) {
            $season = ( new \TT\Modules\Pdp\Repositories\SeasonsRepository() )->current();
            if ( $season && ! empty( $season->start_date ) ) {
                return [ 'from' => (string) $season->start_date, 'to' => $to ];
            }
        }
        return [ 'from' => gmdate( 'Y-m-d', strtotime( '-90 days' ) ), 'to' => $to ]; // fallback
    }

    /**
     * Activity-type select options (lookup name => translated label),
     * matching the activities list's Type filter.
     *
     * @return array<string,string>
     */
    public static function activityTypeOptions(): array {
        $options = [];
        foreach ( QueryHelpers::get_lookups( 'activity_type' ) as $row ) {
            $name = (string) ( $row->name ?? '' );
            if ( $name === '' ) continue;
            $options[ $name ] = (string) LookupTranslator::name( $row );
        }
        return $options;
    }

    /**
     * Sanitize a raw `?activity_type_key=` value to a known lookup name
     * (or ''), so an arbitrary URL value can't reach the query.
     */
    public static function sanitizeActivityType( string $key ): string {
        $key = sanitize_key( $key );
        if ( $key === '' ) return '';
        return array_key_exists( $key, self::activityTypeOptions() ) ? $key : '';
    }
}
