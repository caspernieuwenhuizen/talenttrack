<?php
namespace TT\Modules\Media\Links;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * VideoLinkResolver (#2592, epic #2589) — turns a pasted URL into a
 * `video_link` row, without letting it become a request generator.
 *
 * A coach pastes a URL and the server may fetch metadata for it. That is
 * a server-side request driven by user input, which is the definition of
 * an SSRF surface: the URL could name `169.254.169.254`, or an internal
 * admin panel reachable only from the host. So the order here is fixed
 * and non-negotiable:
 *
 *   1. Parse and reject anything that is not plain http(s).
 *   2. Match the host against a **literal allowlist** of video providers.
 *   3. Only then, and only for providers with a documented oEmbed
 *      endpoint, make a request — to *our* endpoint URL with the video
 *      URL as a parameter, never to the pasted URL itself.
 *
 * A provider we do not recognise is stored as `other` and **never
 * fetched**. The coach types their own title. That is a small cost, and
 * it means an unknown host can never cause an outbound request.
 *
 * Veo and Hudl — the two an academy is most likely to use — have no
 * public oEmbed endpoint, so they are allowlisted for storage but are
 * also never fetched.
 */
final class VideoLinkResolver {

    public const PROVIDER_YOUTUBE = 'youtube';
    public const PROVIDER_VIMEO   = 'vimeo';
    public const PROVIDER_VEO     = 'veo';
    public const PROVIDER_HUDL    = 'hudl';
    public const PROVIDER_OTHER   = 'other';

    /**
     * host suffix => provider. Matched against the parsed host, either
     * exactly or as a dot-suffix, so `evil-youtube.com` does not match
     * `youtube.com` and `www.youtube.com` does.
     */
    private const HOSTS = [
        'youtube.com'  => self::PROVIDER_YOUTUBE,
        'youtu.be'     => self::PROVIDER_YOUTUBE,
        'vimeo.com'    => self::PROVIDER_VIMEO,
        'veo.co'       => self::PROVIDER_VEO,
        'hudl.com'     => self::PROVIDER_HUDL,
    ];

    /** Providers with a documented oEmbed endpoint. Only these are ever fetched. */
    private const OEMBED = [
        self::PROVIDER_YOUTUBE => 'https://www.youtube.com/oembed',
        self::PROVIDER_VIMEO   => 'https://vimeo.com/api/oembed.json',
    ];

    /** Cap on a fetched thumbnail. A provider thumbnail is tens of KB. */
    private const MAX_THUMB_BYTES = 2097152; // 2MB

    /**
     * Provider for a URL, or `other`. Never makes a request.
     *
     * `other` is also the answer for a URL that is not usable at all —
     * callers validate with `isAcceptable()` first.
     */
    public static function detectProvider( string $url ): string {
        $host = self::hostOf( $url );
        if ( $host === '' ) return self::PROVIDER_OTHER;

        foreach ( self::HOSTS as $suffix => $provider ) {
            if ( $host === $suffix || substr( $host, -( strlen( $suffix ) + 1 ) ) === '.' . $suffix ) {
                return $provider;
            }
        }

        return self::PROVIDER_OTHER;
    }

    /**
     * Is this a URL we are willing to store at all?
     *
     * Storage is broader than fetching: an academy may legitimately keep a
     * link to a provider we have never heard of. What is refused is
     * anything that is not a plain http(s) URL with a real hostname —
     * `javascript:`, `data:`, `file:`, and bare IPs, which have no reason
     * to appear and are the shape an attack takes.
     */
    public static function isAcceptable( string $url ): bool {
        $url = trim( $url );
        if ( $url === '' || strlen( $url ) > 1000 ) return false;

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) return false;

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        if ( $scheme !== 'http' && $scheme !== 'https' ) return false;

        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        if ( $host === '' ) return false;

        // Bare IP literals: no video provider is addressed this way, and
        // it is how an internal address would be reached.
        if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) return false;
        if ( strpos( $host, ':' ) !== false ) return false; // bracketed IPv6

        // A hostname with no dot is a local name (`localhost`, `intranet`).
        if ( strpos( $host, '.' ) === false ) return false;

        return true;
    }

    /**
     * Metadata for a link, fetching only from allowlisted providers.
     *
     * Always returns an array — a provider we cannot query yields empty
     * strings rather than an error, because a link with no title is a
     * perfectly good record once the coach types one.
     *
     * @return array{provider:string, title:string, thumbnail_url:string}
     */
    public static function resolve( string $url ): array {
        $provider = self::detectProvider( $url );
        $out      = [ 'provider' => $provider, 'title' => '', 'thumbnail_url' => '' ];

        if ( ! isset( self::OEMBED[ $provider ] ) ) return $out;
        if ( ! self::isAcceptable( $url ) ) return $out;

        // Note the shape: we request OUR endpoint constant with the video
        // URL as a query parameter. The pasted URL is never itself the
        // request target, so a redirect chain in it cannot be followed.
        $endpoint = add_query_arg(
            [ 'url' => rawurlencode( $url ), 'format' => 'json' ],
            self::OEMBED[ $provider ]
        );

        $response = wp_remote_get( $endpoint, [
            'timeout'     => 5,
            'redirection' => 2,
            'user-agent'  => 'TalentTrack',
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return $out;
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) return $out;

        $out['title'] = isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : '';

        $thumb = isset( $body['thumbnail_url'] ) ? (string) $body['thumbnail_url'] : '';
        if ( $thumb !== '' && self::isAcceptable( $thumb ) && self::detectProvider( $thumb ) !== self::PROVIDER_OTHER ) {
            $out['thumbnail_url'] = $thumb;
        }

        return $out;
    }

    /**
     * Download a provider thumbnail into our own store.
     *
     * Deliberate: the alternative is rendering a remote `<img src>` in the
     * gallery, which would tell the provider which coach looked at which
     * player's clip and when. Holding the thumbnail ourselves keeps the
     * page free of third-party requests, and reuses the same ingest path
     * (and therefore the same type whitelist and metadata stripping) as
     * every other image.
     *
     * @return string|null Storage key, or null if it could not be fetched.
     */
    public static function fetchThumbnail( string $thumbnail_url ): ?string {
        if ( ! self::isAcceptable( $thumbnail_url ) ) return null;
        if ( self::detectProvider( $thumbnail_url ) === self::PROVIDER_OTHER ) return null;

        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temp = download_url( $thumbnail_url, 10 );
        if ( is_wp_error( $temp ) ) return null;

        if ( (int) filesize( $temp ) > self::MAX_THUMB_BYTES ) {
            @unlink( $temp );
            return null;
        }

        $mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo !== false ) {
                $mime = (string) finfo_file( $finfo, $temp );
                finfo_close( $finfo );
            }
        }

        $key = ( new \TT\Modules\Media\Ingest\MediaIngestService() )->storeThumbnail( $temp, strtolower( $mime ) );

        if ( file_exists( $temp ) ) @unlink( $temp );

        return $key;
    }

    /** @return list<string> */
    public static function providers(): array {
        return [
            self::PROVIDER_YOUTUBE,
            self::PROVIDER_VIMEO,
            self::PROVIDER_VEO,
            self::PROVIDER_HUDL,
            self::PROVIDER_OTHER,
        ];
    }

    private static function hostOf( string $url ): string {
        $parts = wp_parse_url( trim( $url ) );
        return is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
    }
}
