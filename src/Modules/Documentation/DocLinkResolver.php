<?php
namespace TT\Modules\Documentation;

use TT\Core\FeatureRegistry;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Tiles\TileRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DocLinkResolver — turns a link written inside a help topic into a URL
 * the reader can actually follow.
 *
 * Documentation used to be unable to point at the thing it documents. A
 * `?tt_view=` link matched no branch of the markdown renderer and came out
 * as plain text, so no doc contained one; a `?page=` link was rewritten to
 * wp-admin unconditionally, which for a coach or a parent is a dead end.
 *
 * Two rules govern every link this class produces:
 *
 *  - **Never send a reader somewhere they cannot go.** A link to a view
 *    whose module is off, whose feature flag is off, or which the reader
 *    lacks the capability for is not rendered as a link at all. A dead
 *    link that lands on "permission denied" is worse than plain text.
 *  - **Leaving the application is never silent.** wp-admin destinations are
 *    admin-only and carry a visible marker.
 *
 * Links carry a `tt_back` hint pointing at the topic the reader came from,
 * so the destination renders the standard back-pill (CLAUDE.md §5a) and the
 * reader can get back to what they were reading.
 */
final class DocLinkResolver {

    /** Capability that decides whether wp-admin links render at all. */
    private const ADMIN_CAP = 'tt_edit_settings';

    /** @var array<int, list<string>> memoised tile slugs per user */
    private static $tile_slugs = [];

    /**
     * Resolve `?tt_view=<slug>&…` to a frontend URL, or null when the
     * reader cannot open the destination.
     *
     * `$back_topic` is the slug of the topic being rendered. It is passed
     * explicitly rather than captured from the request because the drawer
     * renders through the REST API, where the request URI is the endpoint
     * and not the page — the reader would get a back-pill to a JSON route.
     */
    public static function frontend( string $query, string $back_topic = '' ): ?string {
        $args = self::queryArgs( $query );
        $slug = (string) ( $args['tt_view'] ?? '' );
        if ( $slug === '' || ! self::canOpen( $slug ) ) {
            return null;
        }

        $url = add_query_arg( $args, home_url( '/' ) );
        return self::withBackTo( $url, $back_topic );
    }

    /**
     * Resolve a wp-admin destination — `?page=…`, `admin.php?page=…` or an
     * absolute `/wp-admin/…` path. Returns null for a reader without the
     * settings capability, so coaches, players and parents never see a
     * wp-admin URL.
     */
    public static function admin( string $url ): ?string {
        if ( ! current_user_can( self::ADMIN_CAP ) ) {
            return null;
        }
        if ( strpos( $url, '/wp-admin' ) === 0 ) {
            return site_url( $url );
        }
        if ( strpos( $url, 'admin.php' ) === 0 ) {
            return admin_url( $url );
        }
        return admin_url( 'admin.php' . $url );
    }

    /**
     * The in-product docs URL for a topic, in whichever viewer the reader
     * is currently using.
     */
    public static function topic( string $slug ): string {
        if ( is_admin() ) {
            return admin_url( 'admin.php?page=tt-docs&topic=' . rawurlencode( $slug ) );
        }
        return add_query_arg( [ 'tt_view' => 'docs', 'topic' => $slug ], home_url( '/' ) );
    }

    /**
     * Whether this reader can open a `tt_view` destination.
     *
     * Module and feature gates apply to every slug. The capability rung
     * only applies to slugs that are registered tiles, because that is
     * where the capability is declared; sub-views reached from within a
     * record (a teammate, one activity) carry no tile of their own and are
     * governed by the surface that links to them.
     */
    public static function canOpen( string $slug, ?int $user_id = null ): bool {
        if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
            return false;
        }
        if ( class_exists( TileRegistry::class ) && TileRegistry::isViewSlugDisabled( $slug ) ) {
            return false;
        }
        if ( class_exists( FeatureRegistry::class ) && FeatureRegistry::viewSlugDisabled( $slug ) ) {
            return false;
        }

        $user_id = $user_id ?? get_current_user_id();
        $tiles   = self::tileSlugsFor( $user_id );
        if ( $tiles === null ) {
            return true;
        }
        // Not a tile at all — no capability is declared for it here.
        if ( ! in_array( $slug, self::allTileSlugs(), true ) ) {
            return true;
        }
        return in_array( $slug, $tiles, true );
    }

    /** Drop the per-user memo. Tests use this between scenarios. */
    public static function flushCache(): void {
        self::$tile_slugs = [];
    }

    /**
     * View slugs this user may reach, or null when the tile registry is
     * unavailable (then the capability rung is skipped rather than
     * hiding every link).
     *
     * @return list<string>|null
     */
    private static function tileSlugsFor( int $user_id ): ?array {
        if ( ! class_exists( TileRegistry::class ) ) {
            return null;
        }
        if ( isset( self::$tile_slugs[ $user_id ] ) ) {
            return self::$tile_slugs[ $user_id ];
        }

        $slugs  = [];
        $buckets = TileRegistry::tilesForUser( $user_id );
        foreach ( $buckets as $bucket ) {
            foreach ( $bucket as $tile ) {
                $view = (string) ( $tile['view_slug'] ?? '' );
                if ( $view !== '' ) {
                    $slugs[] = $view;
                }
            }
        }

        self::$tile_slugs[ $user_id ] = array_values( array_unique( $slugs ) );
        return self::$tile_slugs[ $user_id ];
    }

    /**
     * Every registered tile's view slug, ignoring visibility — used to
     * tell "hidden from this reader" apart from "not a tile".
     *
     * @return list<string>
     */
    private static function allTileSlugs(): array {
        $slugs = [];
        foreach ( TileRegistry::allRegistered() as $tile ) {
            $view = (string) ( $tile['view_slug'] ?? '' );
            if ( $view !== '' ) {
                $slugs[] = $view;
            }
        }
        return array_values( array_unique( $slugs ) );
    }

    /**
     * Parse `?a=1&b=2` into a sanitised arg map. Values are kept as
     * strings; add_query_arg escapes them on the way out.
     *
     * @return array<string, string>
     */
    private static function queryArgs( string $query ): array {
        $raw = ltrim( $query, '?' );
        if ( $raw === '' ) {
            return [];
        }
        $parsed = [];
        parse_str( $raw, $parsed );

        $args = [];
        foreach ( $parsed as $k => $v ) {
            if ( ! is_string( $k ) || ! is_scalar( $v ) ) {
                continue;
            }
            $key = sanitize_key( $k );
            if ( $key === '' ) {
                continue;
            }
            $args[ $key ] = sanitize_text_field( (string) $v );
        }
        return $args;
    }

    /**
     * Attach the back hint. Falls back to the request-derived chain when
     * no topic is known, which is what the full docs page wants.
     */
    private static function withBackTo( string $url, string $back_topic ): string {
        if ( $back_topic === '' ) {
            return class_exists( BackLink::class ) ? BackLink::appendTo( $url ) : $url;
        }
        return add_query_arg(
            BackLink::PARAM,
            urlencode( self::topic( $back_topic ) ),
            $url
        );
    }
}
