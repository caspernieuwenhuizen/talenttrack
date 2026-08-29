<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use TT\Shared\Modules\ProfileRegistry;
use TT\Shared\Modules\ProfileService;

/**
 * ProfilesRestController — /wp-json/talenttrack/v1/profiles
 *
 * Install profiles over REST, so a front end that is not WordPress can
 * read the shape an install is on and move it to another one
 * (CLAUDE.md §4).
 *
 * A new file rather than a home alongside `/modules` and `/features`,
 * which are registered inside `FrontendModulesView` — a view file
 * registering REST routes is exactly the coupling §4 asks new code not
 * to extend. Moving those two is worth doing and is not this class's job.
 *
 * Three routes, and the shape of them is the argument:
 *
 *   GET  /profiles              what is shipped, what this install is on
 *   GET  /profiles/{slug}       that profile plus its full diff — this
 *                               **is** the preview; a diff is a pure read,
 *                               so a GET is the honest verb and there is
 *                               no separate preview route to keep in step
 *   POST /profiles/{slug}/apply the only route that writes
 */
class ProfilesRestController extends BaseController {

    /** Same capability the modules and features endpoints use. */
    private const CAP = 'tt_manage_modules';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/profiles', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'listProfiles' ],
                'permission_callback' => self::permCan( self::CAP ),
            ],
        ] );

        register_rest_route( self::NS, '/profiles/(?P<slug>[a-z0-9_-]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'show' ],
                'permission_callback' => self::permCan( self::CAP ),
                'args'                => [
                    'slug' => [ 'required' => true, 'type' => 'string' ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/profiles/(?P<slug>[a-z0-9_-]+)/apply', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'apply' ],
                'permission_callback' => self::permCan( self::CAP ),
                'args'                => [
                    'slug'    => [ 'required' => true, 'type' => 'string' ],
                    'exclude' => [ 'required' => false, 'type' => 'array' ],
                ],
            ],
        ] );
    }

    /**
     * Shipped profiles, plus which one this install is on and how far it
     * has drifted from it.
     */
    public static function listProfiles(): WP_REST_Response {
        $current = ProfileService::current();

        $profiles = [];
        foreach ( ProfileRegistry::all() as $slug => $profile ) {
            $profiles[] = [
                'slug'        => $slug,
                'label'       => $profile['label'],
                'description' => $profile['description'],
                'is_current'  => $slug === $current,
            ];
        }

        return new WP_REST_Response( [
            'profiles'   => $profiles,
            // Null for an install that predates profiles or was never put
            // on one — a neutral state, not an error.
            'current'    => $current,
            'divergence' => $current === null ? null : ProfileService::divergence( $current ),
        ], 200 );
    }

    /**
     * One profile and the full diff against live state. Writes nothing.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function show( WP_REST_Request $request ) {
        $slug    = (string) $request['slug'];
        $profile = ProfileRegistry::get( $slug );
        if ( $profile === null ) return self::unknownProfile( $slug );

        $diff = ProfileService::diff( $slug );

        return new WP_REST_Response( [
            'slug'        => $slug,
            'label'       => $profile['label'],
            'description' => $profile['description'],
            'is_current'  => $slug === ProfileService::current(),
            'divergence'  => ProfileService::divergence( $slug ),
            'changes'     => array_map( [ __CLASS__, 'presentRow' ], $diff ),
        ], 200 );
    }

    /**
     * Apply the profile, honouring the caller's exclusions.
     *
     * A request whose `exclude` covers every row is a no-op returning an
     * empty applied list and 200 — the caller asked for nothing to
     * happen, and got it. That is not an error.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function apply( WP_REST_Request $request ) {
        $slug = (string) $request['slug'];
        if ( ! ProfileRegistry::exists( $slug ) ) return self::unknownProfile( $slug );

        $exclude = $request['exclude'];
        $exclude = is_array( $exclude ) ? array_map( 'strval', $exclude ) : [];

        $summary = ProfileService::apply( $slug, $exclude );

        return new WP_REST_Response( [
            'profile'    => $summary['profile'],
            'applied'    => array_map( [ __CLASS__, 'presentApplied' ], $summary['applied'] ),
            'skipped'    => array_map( [ __CLASS__, 'presentSkipped' ], $summary['skipped'] ),
            'divergence' => ProfileService::divergence( $slug ),
        ], 200 );
    }

    /**
     * One diff row as the API presents it.
     *
     * `skipped_reason` travels with the row rather than being dropped, so
     * a consumer can say why a change will not happen instead of silently
     * under-applying and leaving the operator to notice.
     *
     * @param array{kind:string, id:string, key:string, label:string, from:bool, to:bool, skipped_reason:?string} $row
     * @return array<string, mixed>
     */
    private static function presentRow( array $row ): array {
        return [
            'id'             => $row['id'],
            'kind'           => $row['kind'],
            'label'          => $row['label'],
            'from'           => $row['from'],
            'to'             => $row['to'],
            'skipped_reason' => $row['skipped_reason'],
        ];
    }

    /**
     * @param array{kind:string, id:string, label:string, from:bool, to:bool} $row
     * @return array<string, mixed>
     */
    private static function presentApplied( array $row ): array {
        return [
            'id'    => $row['id'],
            'kind'  => $row['kind'],
            'label' => $row['label'],
            'from'  => $row['from'],
            'to'    => $row['to'],
        ];
    }

    /**
     * @param array{kind:string, id:string, label:string, from:bool, to:bool, reason:string} $row
     * @return array<string, mixed>
     */
    private static function presentSkipped( array $row ): array {
        $out           = self::presentApplied( $row );
        $out['reason'] = $row['reason'];
        return $out;
    }

    private static function unknownProfile( string $slug ): WP_Error {
        return new WP_Error(
            'tt_profile_not_found',
            sprintf(
                /* translators: %s is the requested install-profile slug. */
                __( 'No install profile named "%s".', 'talenttrack' ),
                $slug
            ),
            [ 'status' => 404 ]
        );
    }
}
