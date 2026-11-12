<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PresentationSurfaces (#5088): which surfaces may show one record as a
 * room display, without the app's chrome.
 *
 * Two answers, kept apart on purpose. `claims()` is static: the slug is
 * listed in `config/presentation_surfaces.php`, so it **may** present.
 * `isPresenting()` is per request: the view reached its success path and
 * declared it through `present()`. Only the declaration changes what the
 * page draws, so a reader refused on a claimed slug still gets the shell
 * and the chain. The view calls `present()` on its success path, after every
 * access and record check has passed: from then on the chain stays silent
 * for the rest of the request, so a refusal drawn after it would land in
 * the bare wrapper with no way back.
 *
 * Three readers, and a view is none of them:
 *
 *   - `FrontendAccessControl::hideAdminBar()` reads `claims()`. WordPress
 *     settles the admin bar before the shortcode runs, so this reader can
 *     only go by the slug: an administrator refused on a claimed slug gets
 *     the TalentTrack shell and the chain, but no WordPress admin bar.
 *   - `DashboardShortcode` reads `claims()` to hold the body back, then
 *     `isPresenting()` to choose between the presentation wrapper and the
 *     normal chrome.
 *   - `FrontendBreadcrumbs::render()` reads `isPresenting()` and draws
 *     nothing once the request presents.
 *
 * On a claimed slug the chrome is written after the view has run. So a
 * claimed view must not queue a flash message, and must not rely on any
 * state set while the chrome renders.
 *
 * The resolver fails closed: a missing or unreadable file, a file that does
 * not return an array, and an entry without a reason all leave the slug
 * unclaimed, which is today's behaviour everywhere.
 */
final class PresentationSurfaces {

    /**
     * Query keys a close URL may carry beside `id`, each the address of a
     * child record whose page has no URL of its own (a talk inside a PDP
     * file). Real sub-record addresses only, never view state: tabs,
     * filters, modes and `action` stay out. Adding a key changes the
     * presentation contract and needs an architect's review.
     */
    private const SUB_RECORD_KEYS = [ 'conv' ];

    /** @var array<string, string>|null resolved once per request */
    private static ?array $map = null;

    /** Test seam: read this file instead of the shipped config. */
    private static ?string $file = null;

    private static bool $presenting = false;

    /** True when the slug may present as a room display. */
    public static function claims( string $slug ): bool {
        $slug = trim( $slug );
        if ( $slug === '' ) return false;

        return isset( self::map()[ $slug ] );
    }

    /** Why a surface may present, or '' when it may not. */
    public static function reason( string $slug ): string {
        return (string) ( self::map()[ trim( $slug ) ] ?? '' );
    }

    /** @return array<string, string> slug => reason */
    public static function map(): array {
        if ( self::$map !== null ) {
            return self::$map;
        }

        self::$map = [];

        $file = self::$file ?? TT_PLUGIN_DIR . 'config/presentation_surfaces.php';
        if ( ! is_readable( $file ) ) {
            return self::$map;
        }

        $raw = require $file;
        if ( ! is_array( $raw ) ) {
            return self::$map;
        }

        foreach ( $raw as $slug => $reason ) {
            if ( ! is_string( $slug ) || ! is_string( $reason ) || trim( $reason ) === '' ) continue;
            $key = sanitize_key( $slug );
            if ( $key === '' || $key !== $slug ) continue;
            self::$map[ $key ] = $reason;
        }

        return self::$map;
    }

    /**
     * The view's declaration, on its success path, after every access and
     * record check has passed, and before any other output: this request
     * presents. Draws the one close control, back to
     * the record's page, and returns true.
     *
     * Returns false, draws nothing and leaves the request a normal view
     * when the current `tt_view` is not claimed, when the request already
     * presents, when a breadcrumb chain has already been drawn, or when the
     * close URL is not that record's page (see `isRecordUrl()`).
     */
    public static function present( string $close_url ): bool {
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_view'] ) ) : '';

        if ( ! self::claims( $view ) ) return false;
        if ( self::$presenting ) return false;
        if ( FrontendBreadcrumbs::hasRendered() ) return false;
        if ( ! self::isRecordUrl( $close_url ) ) return false;

        self::$presenting = true;

        echo '<a class="tt-btn tt-btn-secondary tt-presentation-close" data-tt-presentation-close href="' . esc_url( $close_url ) . '">'
            . esc_html_x( 'Close', 'presentation surface: leave the room display', 'talenttrack' )
            . '</a>';

        return true;
    }

    public static function isPresenting(): bool {
        return self::$presenting;
    }

    /**
     * A same-site record page: the dashboard's own address carrying exactly
     * one `tt_view=<slug>` and one `id=<n>`, plus at most one key from
     * `SUB_RECORD_KEYS` where the record is a child with no page of its own.
     * Each number is a positive integer without a leading zero, and the slug
     * is a sanitised key that is not itself a presentation surface. A
     * fragment is allowed; any other query argument, or an array value, is
     * not.
     *
     * The close URL is written by the view, never taken from the request, so
     * this shape is the boundary. That the slug is routable is a property of
     * each listed view, asserted by a test rather than checked here.
     */
    public static function isRecordUrl( string $url ): bool {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) return false;

        $home = wp_parse_url( RecordLink::dashboardUrl() );
        if ( ! is_array( $home ) ) return false;

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        if ( $scheme !== 'http' && $scheme !== 'https' ) return false;
        if ( strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) return false;
        if ( ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) return false;
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) return false;
        if ( untrailingslashit( (string) ( $parts['path'] ?? '' ) ) !== untrailingslashit( (string) ( $home['path'] ?? '' ) ) ) return false;

        $query = (string) ( $parts['query'] ?? '' );
        parse_str( $query, $args );
        if ( ! isset( $args['tt_view'], $args['id'] ) ) return false;

        // Each key once: `parse_str()` keeps the last of a repeated key,
        // which would let a second `id` through unseen.
        $raw_keys = array_map( static fn( string $pair ): string => rawurldecode( (string) strtok( $pair, '=' ) ), array_filter( explode( '&', $query ), static fn( string $pair ): bool => $pair !== '' ) );
        if ( count( $raw_keys ) !== count( array_unique( $raw_keys ) ) || count( $raw_keys ) !== count( $args ) ) return false;

        $extra = array_diff( array_keys( $args ), [ 'tt_view', 'id' ] );
        if ( count( $extra ) > 1 ) return false;
        foreach ( $extra as $key ) {
            if ( ! in_array( $key, self::SUB_RECORD_KEYS, true ) || ! self::isPositiveId( $args[ $key ] ) ) return false;
        }

        if ( ! self::isPositiveId( $args['id'] ) ) return false;

        $slug = $args['tt_view'];
        if ( ! is_string( $slug ) || $slug === '' || sanitize_key( $slug ) !== $slug ) return false;

        return ! self::claims( $slug );
    }

    /** @param mixed $value a positive integer as a string, without a leading zero */
    private static function isPositiveId( $value ): bool {
        return is_string( $value ) && ctype_digit( $value ) && $value[0] !== '0';
    }

    /** Test seam: drops the resolved map and the request state. */
    public static function flush(): void {
        self::$map        = null;
        self::$presenting = false;
    }

    /** Test seam: read another config file, or the shipped one with null. */
    public static function useFile( ?string $file ): void {
        self::$file = $file;
        self::flush();
    }
}
