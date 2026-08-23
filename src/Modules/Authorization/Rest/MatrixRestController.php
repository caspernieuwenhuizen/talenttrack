<?php
namespace TT\Modules\Authorization\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Authorization\Admin\MatrixEntityCatalog;
use TT\Modules\Authorization\Matrix\MatrixEditService;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * MatrixRestController (#2654) — the authorization matrix over REST.
 *
 *   GET   /authorization/matrix          personas, entities, grid, and what
 *                                        the caller may edit
 *   PUT   /authorization/matrix          apply a delta
 *   POST  /authorization/matrix/reset    reseed from the shipped defaults
 *
 * Every route gates on `tt_manage_authorization`. That is a capability,
 * not a role-name compare, so it stays meaningful to a front end with no
 * WordPress roles behind it (CLAUDE.md §4).
 *
 * The protected-cell guardrail is NOT enforced here. It lives inside
 * `MatrixEditService::applyGrid()`, which both this controller and the two
 * rendered surfaces call — a check placed in the controller would leave
 * the wp-admin path unguarded and would have to be written twice.
 *
 * ## Why PUT takes the same shape the form submits
 *
 * `cells` presence means granted; `scopes` keys declare which
 * persona/entity pairs the payload speaks for. A pair absent from
 * `scopes` is untouched, so a client that wants to change one cell sends
 * one pair rather than the whole grid — and cannot accidentally revoke
 * everything it forgot to include.
 *
 * Reset stays administrator-only: it discards every edit anybody ever
 * made, including the ones the caller was not allowed to make.
 */
class MatrixRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/authorization/matrix', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get' ],
                'permission_callback' => [ __CLASS__, 'canManage' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put' ],
                'permission_callback' => [ __CLASS__, 'canManage' ],
                'args'                => [
                    'cells'  => [ 'required' => false, 'type' => 'object' ],
                    'scopes' => [ 'required' => true,  'type' => 'object' ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/authorization/matrix/reset', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'reset' ],
                'permission_callback' => [ __CLASS__, 'canReset' ],
            ],
        ] );
    }

    public static function canManage(): bool {
        return current_user_can( 'tt_manage_authorization' );
    }

    /** A reset throws away work that was not the caller's to throw away. */
    public static function canReset(): bool {
        return current_user_can( 'manage_options' );
    }

    /** @return \WP_REST_Response */
    public static function get() {
        $repo     = new MatrixRepository();
        $entities = [];
        foreach ( $repo->entities() as $row ) {
            $entity     = (string) $row['entity'];
            $entities[] = [
                'entity'       => $entity,
                'label'        => MatrixEntityCatalog::entityLabel( $entity ),
                'module_class' => (string) $row['module_class'],
                'group'        => MatrixEntityCatalog::groupForEntity( $entity ),
            ];
        }

        $personas = [];
        foreach ( $repo->personas() as $persona ) {
            $personas[] = [
                'persona' => $persona,
                'label'   => MatrixEditService::personaLabel( $persona ),
            ];
        }

        return RestResponse::success( [
            'personas'   => $personas,
            'entities'   => $entities,
            'grid'       => $repo->asGrid(),
            'activities' => MatrixEditService::ACTIVITIES,
            'scopes'     => MatrixEditService::SCOPE_KINDS,
            'editable'   => MatrixEditService::editableFor( get_current_user_id() ),
        ] );
    }

    /** @return \WP_REST_Response */
    public static function put( \WP_REST_Request $req ) {
        $scopes = self::stringMap( $req->get_param( 'scopes' ) );
        if ( $scopes === [] ) {
            return RestResponse::error(
                'tt_no_scopes',
                __( 'Send at least one persona|entity pair in scopes — that is what says which cells this request speaks for.', 'talenttrack' ),
                400
            );
        }

        $summary = ( new MatrixEditService() )->applyGrid(
            self::stringMap( $req->get_param( 'cells' ) ),
            $scopes,
            get_current_user_id()
        );

        return RestResponse::success( $summary );
    }

    /** @return \WP_REST_Response */
    public static function reset() {
        ( new MatrixEditService() )->resetToDefaults( get_current_user_id() );

        return RestResponse::success( [ 'reset' => true ] );
    }

    /**
     * Flatten a JSON object into the `key => string` shape the service reads.
     * A nested value is dropped rather than coerced — `["a"]` as a scope is a
     * client bug, and silently reading it as "global" would hide it.
     *
     * @param mixed $raw
     * @return array<string, string>
     */
    private static function stringMap( $raw ): array {
        if ( ! is_array( $raw ) ) return [];

        $out = [];
        foreach ( $raw as $key => $value ) {
            if ( is_array( $value ) || is_object( $value ) ) continue;
            $out[ (string) $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value;
        }

        return $out;
    }
}
