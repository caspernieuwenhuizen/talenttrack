<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PdpFormsRestController (#4976, epic #4975) — which PDP conversation forms
 * the academy uses.
 *
 *   GET /talenttrack/v1/pdp-forms   the set in use and the sets on offer
 *   PUT /talenttrack/v1/pdp-forms   `{ set: "<set key>" | "" }`
 *
 * Gated on `tt_edit_settings`, the right the PDP settings ask. Saving a set
 * first checks the academy can use it (a five-step rating scale, the four
 * main categories), then creates its categories and types
 * (`PdpFormCategorySeeder`), and only then stores the choice, so a refused
 * save changes nothing. An empty `set` switches the forms off; the
 * categories and types stay, because evaluations may already use them.
 */
final class PdpFormsRestController extends BaseController {

    public const CAP = 'tt_edit_settings';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( self::NS, '/pdp-forms', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'show' ],
                'permission_callback' => self::permCan( self::CAP ),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update' ],
                'permission_callback' => self::permCan( self::CAP ),
                'args'                => self::updateArgs(),
            ],
        ] );
    }

    /** @return array<string, array<string,mixed>> */
    private static function updateArgs(): array {
        // Not `required`: an empty `set` is a real value (switch the forms
        // off), and `checkBody()` reads an empty required string as missing.
        // `update()` refuses a body without the key itself.
        return [
            'set' => [ 'type' => 'string' ],
        ];
    }

    public static function show( WP_REST_Request $request ): WP_REST_Response {
        return RestResponse::success( self::payload() );
    }

    public static function update( WP_REST_Request $request ): WP_REST_Response {
        $refused = self::checkBody( $request, self::updateArgs() );
        if ( $refused !== null ) return $refused;
        if ( ! $request->has_param( 'set' ) ) {
            return RestResponse::error( 'missing_fields', __( 'Say which set of conversation forms to use, or none.', 'talenttrack' ), 400, [ 'fields' => [ 'set' ] ] );
        }

        $set_key = sanitize_key( (string) $request->get_param( 'set' ) );
        if ( $set_key === '' ) {
            QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, '' );
            return RestResponse::success( self::payload() );
        }

        $sets = PdpFormRegistry::sets();
        if ( ! isset( $sets[ $set_key ] ) ) {
            return RestResponse::error(
                'invalid_field',
                __( 'That set of conversation forms is not available.', 'talenttrack' ),
                400,
                [ 'field' => 'set', 'allowed' => array_keys( $sets ) ]
            );
        }

        $forms  = $sets[ $set_key ]['forms'];
        $seeded = PdpFormCategorySeeder::ensure( $forms );
        if ( $seeded instanceof \WP_Error ) {
            return RestResponse::error( (string) $seeded->get_error_code(), $seeded->get_error_message(), 422 );
        }

        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, (string) wp_json_encode( $forms ) );
        return RestResponse::success( self::payload() );
    }

    /** @return array<string,mixed> */
    private static function payload(): array {
        $sets = [];
        foreach ( PdpFormRegistry::sets() as $set ) {
            $sets[] = [ 'key' => $set['key'], 'label' => $set['label'], 'forms' => $set['forms'] ];
        }
        return [
            'set'   => PdpFormRegistry::setForClub(),
            'forms' => PdpFormRegistry::formsForClub(),
            'sets'  => $sets,
        ];
    }
}
