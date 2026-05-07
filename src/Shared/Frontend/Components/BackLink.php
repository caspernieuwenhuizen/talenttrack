<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackLink — URL-borne "back to where you came from" navigation.
 *
 * Survives page refresh, missing referers, and shared deep-links by
 * encoding the previous page's full URL into a `tt_back` query
 * parameter on outgoing cross-entity links. The receiving view decodes
 * it, validates same-origin + TalentTrack route, and renders an in-page
 * "← Back to <X>" pill.
 *
 * Multi-hop walk: every navigation captures the *current* URL (which
 * itself already carries any inherited `tt_back`), so the chain
 * naturally nests via URL encoding. Capped at MAX_DEPTH hops; deeper
 * journeys drop the oldest entry on each push.
 *
 * Stateless — no session storage, no transients, no cookies. Survives
 * any non-cross-origin navigation including external auth round-trips
 * because the value lives in the URL.
 */
final class BackLink {

    public const PARAM     = 'tt_back';
    public const MAX_DEPTH = 5;

    /**
     * Build the next-hop URL: append `tt_back=<urlencoded current
     * page URL>` to $target_url. The current URL is captured from
     * $_SERVER['REQUEST_URI'] so it includes any `tt_back` already
     * present, preserving the chain.
     *
     * Truncates the deepest hop when adding would exceed MAX_DEPTH.
     */
    public static function appendTo( string $target_url ): string {
        if ( $target_url === '' ) return '';
        $current = self::captureCurrent();
        if ( $current === '' ) return $target_url;
        $current = self::truncateChain( $current, self::MAX_DEPTH - 1 );
        return add_query_arg( self::PARAM, urlencode( $current ), $target_url );
    }

    /**
     * Render the "← Back to <X>" pill for the current request. Returns
     * empty string when no valid `tt_back` is present.
     */
    public static function renderPill(): string {
        $resolved = self::resolve();
        if ( $resolved === null ) return '';
        return sprintf(
            '<div class="tt-back-link-wrap"><a class="tt-back-link" href="%s"><span class="tt-back-link__arrow" aria-hidden="true">&larr;</span> %s</a></div>',
            esc_url( $resolved['url'] ),
            esc_html( $resolved['label'] )
        );
    }

    /**
     * Resolve the `tt_back` from the current request into a back URL +
     * label, or null when missing / invalid.
     *
     * @return array{url:string,label:string}|null
     */
    public static function resolve(): ?array {
        if ( empty( $_GET[ self::PARAM ] ) ) return null;
        $raw = wp_unslash( (string) $_GET[ self::PARAM ] );
        $url = self::sanitize( $raw );
        if ( $url === null ) return null;
        $label = BackLabelResolver::labelFor( $url );
        return [ 'url' => $url, 'label' => $label ];
    }

    /**
     * Capture the current request's full URL, suitable for embedding as
     * the next page's `tt_back` value.
     *
     * In REST contexts (`REST_REQUEST` constant set), REQUEST_URI points
     * at the REST endpoint, not the page the user is viewing. Fall back
     * to the same-origin HTTP Referer in that case so list-table cell
     * URLs built REST-side carry the page URL the user is on.
     */
    public static function captureCurrent(): string {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return self::sameOriginReferer();
        }
        if ( empty( $_SERVER['REQUEST_URI'] ) ) return '';
        $request_uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
        $home_host   = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $scheme      = is_ssl() ? 'https://' : 'http://';
        return $scheme . $home_host . $request_uri;
    }

    private static function sameOriginReferer(): string {
        if ( empty( $_SERVER['HTTP_REFERER'] ) ) return '';
        $ref = (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
        $ref_host  = (string) wp_parse_url( $ref, PHP_URL_HOST );
        $home_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        if ( strtolower( $ref_host ) !== strtolower( $home_host ) ) return '';
        return $ref;
    }

    /**
     * Validate a back URL: same-origin, parseable, returns escaped raw
     * URL or null when it should be rejected (cross-origin, malformed,
     * empty path).
     */
    private static function sanitize( string $url ): ?string {
        if ( $url === '' ) return null;
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) return null;
        $home = wp_parse_url( home_url( '/' ) );
        if ( ! is_array( $home ) || empty( $home['host'] ) ) return null;
        if ( strtolower( (string) $parsed['host'] ) !== strtolower( (string) $home['host'] ) ) return null;
        $clean = esc_url_raw( $url );
        return $clean !== '' ? $clean : null;
    }

    /**
     * If the URL's nested `tt_back` chain exceeds $max_depth levels,
     * drop the deepest entry. Walks the chain by recursive parse_str on
     * the inner `tt_back` value.
     */
    private static function truncateChain( string $url, int $max_depth ): string {
        if ( $max_depth <= 0 ) return self::stripBack( $url );
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || empty( $parsed['query'] ) ) return $url;
        parse_str( (string) $parsed['query'], $params );
        if ( empty( $params[ self::PARAM ] ) ) return $url;
        $inner = (string) $params[ self::PARAM ];
        $truncated_inner = self::truncateChain( $inner, $max_depth - 1 );
        if ( $truncated_inner === $inner ) return $url;
        return add_query_arg( self::PARAM, urlencode( $truncated_inner ), $url );
    }

    /**
     * Remove the `tt_back` query param from a URL entirely.
     */
    private static function stripBack( string $url ): string {
        $stripped = remove_query_arg( self::PARAM, $url );
        return is_string( $stripped ) ? $stripped : $url;
    }
}
