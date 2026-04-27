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
 * Capability-gated per #0006: topics whose audience set doesn't
 * intersect the viewer's allowed audiences return 403. The slug
 * pattern is constrained to a-z 0-9 - so it can't escape the docs
 * directory.
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
        $topics  = HelpTopics::all();
        $allowed = AudienceResolver::allowedFor( get_current_user_id() );

        $out = [];
        foreach ( $topics as $slug => $t ) {
            $aud = AudienceResolver::readFromFile( HelpTopics::filePath( $slug ) );
            if ( ! AudienceResolver::isVisible( $aud, $allowed ) ) continue;
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

        $allowed = AudienceResolver::allowedFor( get_current_user_id() );
        $aud     = AudienceResolver::readFromFile( HelpTopics::filePath( $slug ) );
        if ( ! AudienceResolver::isVisible( $aud, $allowed ) ) {
            return new \WP_REST_Response( [ 'message' => __( 'Not authorised for this topic.', 'talenttrack' ) ], 403 );
        }

        $path = HelpTopics::filePath( $slug );
        $body = '';
        if ( $path !== null ) {
            $source = (string) file_get_contents( $path );
            if ( $source !== '' ) $body = Markdown::render( $source );
        }

        return new \WP_REST_Response( [
            'slug'  => $slug,
            'title' => (string) $topics[ $slug ]['title'],
            'group' => (string) $topics[ $slug ]['group'],
            'html'  => $body,
        ] );
    }
}
