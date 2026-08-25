<?php
namespace TT\Modules\Documentation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DocsRestController — /wp-json/talenttrack/v1/docs
 *
 * Backs the context-aware help drawer (#0016 part B). Two endpoints:
 *
 *   GET /docs              — list of accessible topic slugs + titles
 *   GET /docs/(?P<slug>…)  — rendered HTML body for one topic
 *
 * The slug pattern is constrained to a-z 0-9 - so it can't escape the
 * docs directory.
 *
 * ## Status codes for a topic the reader cannot open
 *
 * The two "no" answers are deliberately different, and the difference is
 * observable:
 *
 *   404 — the install does not have this. A disabled module, a switched-off
 *         feature, a tier above the licence. Answering 403 here would
 *         confirm the topic exists on this install, which is the thing
 *         hiding it was for.
 *   403 — the topic is here and a colleague can read it; this reader lacks
 *         the capability. Nothing is leaked by saying so, and pretending
 *         the topic does not exist would send the reader looking for a
 *         missing doc rather than asking for access.
 *
 * The audience marker keeps its existing 403 — it describes who a topic is
 * *written for*, not what the install runs.
 */
class DocsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/docs', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );
        register_rest_route( self::NS, '/docs/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'getOne' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
            'args'                => [
                'slug' => [
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => static fn( $v ) => is_string( $v ) && (bool) preg_match( '/^[a-z0-9-]+$/', $v ),
                ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return is_user_logged_in();
    }

    public static function list(): \WP_REST_Response {
        $out = [];
        foreach ( HelpTopics::visibleFor( get_current_user_id() ) as $slug => $t ) {
            $out[] = [
                'slug'    => (string) $slug,
                'title'   => (string) $t['title'],
                'group'   => (string) $t['group'],
                'summary' => (string) ( $t['summary'] ?? '' ),
            ];
        }
        return new \WP_REST_Response( [ 'topics' => $out ] );
    }

    public static function getOne( \WP_REST_Request $req ): \WP_REST_Response {
        $slug    = (string) $req->get_param( 'slug' );
        $topics  = HelpTopics::all();
        if ( ! isset( $topics[ $slug ] ) ) {
            return new \WP_REST_Response( [ 'message' => __( 'Topic not found.', 'talenttrack' ) ], 404 );
        }

        $user_id = get_current_user_id();

        $allowed = AudienceResolver::allowedFor( $user_id );
        if ( ! AudienceResolver::isVisible( $topics[ $slug ]['audience'] ?? [], $allowed ) ) {
            return new \WP_REST_Response( [ 'message' => __( 'Not authorised for this topic.', 'talenttrack' ) ], 403 );
        }

        $verdict = HelpTopics::verdictFor( $slug, $user_id );
        if ( $verdict->isUnavailable() ) {
            return new \WP_REST_Response( [ 'message' => __( 'Topic not found.', 'talenttrack' ) ], 404 );
        }
        if ( ! $verdict->isAvailable() ) {
            return new \WP_REST_Response( [ 'message' => __( 'Not authorised for this topic.', 'talenttrack' ) ], 403 );
        }

        $path = HelpTopics::filePath( $slug );
        $body = '';
        if ( $path !== null ) {
            $source = (string) file_get_contents( $path );
            if ( $source !== '' ) $body = Markdown::render( $source, $slug );
        }

        // #2550 — the in-app help reader consumes this, so the notice has to
        // travel with the body rather than being added by one surface. The
        // boolean rides alongside it for a client that would rather render
        // its own affordance than our paragraph.
        $untranslated = HelpTopics::isUntranslatedFallback( $slug );
        if ( $untranslated && $body !== '' ) {
            $body = HelpTopics::untranslatedNoticeHtml( $slug ) . $body;
        }

        return new \WP_REST_Response( [
            'slug'         => $slug,
            'title'        => (string) $topics[ $slug ]['title'],
            'group'        => (string) $topics[ $slug ]['group'],
            'html'         => $body,
            'untranslated' => $untranslated,
        ] );
    }
}
