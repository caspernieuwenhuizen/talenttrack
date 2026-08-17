<?php
namespace TT\Infrastructure\Filters;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SavedViewsDefaults (#2450) — applies a user's default saved view when they
 * open a surface with no filters of their own.
 *
 * Runs on `template_redirect`, which fires before any output, so this can
 * issue a real redirect. #2450's spec assumed it could not — the views render
 * inside a shortcode, by which point headers are sent — and proposed merging
 * the default's params into the view's state in place instead. Redirecting is
 * better: the URL ends up describing what the user is looking at, so it stays
 * shareable and bookmarkable, and nothing has to reach into `$_GET` behind the
 * view's back.
 *
 * The rules, in order:
 *
 *   1. Only for a logged-in user on a `?tt_view=` request.
 *   2. Only when the surface is registered here AND in SavedViewsRegistry.
 *   3. Never when the request already carries one of the surface's own filter
 *      params — a deep link, a `tt_back` return, or a shared URL already says
 *      what to show. `tt_view`, `slug` and `tt_back` are routing, not filters.
 *   4. Never when `tt_views=off` is present — that is the escape hatch the
 *      bar's Clear link uses. Without it, clearing filters would land on the
 *      param-free URL, which is exactly the condition that re-applies the
 *      default, and the user could never get back to an unfiltered list.
 *   5. Never when the user has no default for that surface.
 */
final class SavedViewsDefaults {

    /**
     * The escape-hatch param. Present → the default is not applied. Added to
     * the filter bar's reset URL by FilterBar.
     */
    public const OFF_PARAM = 'tt_views';
    public const OFF_VALUE = 'off';

    /**
     * view_key => how to recognise the surface in a request, and which params
     * count as "the user already chose filters".
     *
     * `tt_view` is the route slug; `slug` discriminates the standard reports,
     * which all share `?tt_view=standard-report`. `params` is the surface's
     * filter vocabulary — the same list FilterBar::paramNames() derives at
     * render time, restated here because this runs long before the bar exists.
     *
     * @var array<string,array{tt_view:string,slug?:string,params:array<int,string>}>
     */
    private const ROUTES = [
        'attendance_team' => [
            'tt_view' => 'attendance-report-team',
            'params'  => [ 'period', 'activity_type_key', 'from', 'to' ],
        ],
        'attendance_player' => [
            'tt_view' => 'attendance-report-player',
            'params'  => [ 'period', 'activity_type_key', 'from', 'to', 'team_id' ],
        ],
        'attendance_leaderboard' => [
            'tt_view' => 'attendance-leaderboard',
            'params'  => [ 'period', 'activity_type_key', 'from', 'to', 'team_id', 'n' ],
        ],
        'minutes_team' => [
            'tt_view' => 'minutes-report-team',
            'params'  => [ 'period', 'from', 'to', 'team_id' ],
        ],
        'minutes_audit' => [
            'tt_view' => 'minutes-audit',
            'params'  => [ 'period', 'from', 'to', 'team_id' ],
        ],
    ];

    public static function init(): void {
        add_action( 'template_redirect', [ self::class, 'maybeApply' ], 5 );
    }

    /**
     * The surfaces that support auto-apply, filterable so a module can add
     * one without editing this file.
     *
     * @return array<string,array{tt_view:string,slug?:string,params:array<int,string>}>
     */
    public static function routes(): array {
        $routes = apply_filters( 'tt_saved_views_default_routes', self::ROUTES );
        return is_array( $routes ) ? $routes : self::ROUTES;
    }

    /** True when this request opted out of default application. */
    public static function suppressed(): bool {
        return isset( $_GET[ self::OFF_PARAM ] )
            && sanitize_key( (string) $_GET[ self::OFF_PARAM ] ) === self::OFF_VALUE;
    }

    /**
     * Resolve the surface key this request is for, or '' when the request is
     * not one of the registered surfaces.
     */
    public static function surfaceForRequest(): string {
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        if ( $view === '' ) return '';
        $slug = isset( $_GET['slug'] ) ? sanitize_key( (string) $_GET['slug'] ) : '';

        foreach ( self::routes() as $key => $route ) {
            if ( ( $route['tt_view'] ?? '' ) !== $view ) continue;
            // Standard reports share one route and differ by `slug`.
            if ( isset( $route['slug'] ) && $route['slug'] !== $slug ) continue;
            return (string) $key;
        }
        return '';
    }

    /** True when the request already carries any of the surface's filters. */
    public static function hasOwnFilters( string $view_key ): bool {
        $params = self::routes()[ $view_key ]['params'] ?? [];
        foreach ( (array) $params as $param ) {
            if ( isset( $_GET[ (string) $param ] ) && (string) $_GET[ (string) $param ] !== '' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Redirect to the user's default view when every rule above allows it.
     * A no-op otherwise — including for logged-out visitors, whose requests
     * must stay cacheable.
     */
    public static function maybeApply(): void {
        if ( is_admin() || wp_doing_ajax() ) return;
        if ( ! is_user_logged_in() ) return;
        if ( self::suppressed() ) return;

        $view_key = self::surfaceForRequest();
        if ( $view_key === '' ) return;
        if ( ! SavedViewsRegistry::currentUserCan( $view_key ) ) return;
        if ( self::hasOwnFilters( $view_key ) ) return;

        $default = ( new SavedViewsRepository() )->findDefault( get_current_user_id(), $view_key );
        if ( $default === null ) return;

        $filters = json_decode( (string) ( $default->filters_json ?? '' ), true );
        if ( ! is_array( $filters ) || $filters === [] ) return;

        // Only the surface's own params — a stored key the surface no longer
        // uses must not be pushed back into the URL.
        $allowed = (array) ( self::routes()[ $view_key ]['params'] ?? [] );
        $apply   = [];
        foreach ( $filters as $key => $value ) {
            if ( in_array( (string) $key, $allowed, true ) && is_scalar( $value ) ) {
                $apply[ (string) $key ] = (string) $value;
            }
        }
        if ( $apply === [] ) return;

        $target = add_query_arg( $apply, self::currentUrl() );
        wp_safe_redirect( $target, 302 );
        exit;
    }

    private static function currentUrl(): string {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
        return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
    }
}
