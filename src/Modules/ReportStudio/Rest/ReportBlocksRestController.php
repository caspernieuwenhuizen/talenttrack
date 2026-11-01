<?php
namespace TT\Modules\ReportStudio\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\StudioError;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ReportBlocksRestController (#4568) — the two routes a Studio canvas reads.
 *
 *   GET  /report-blocks                 the blocks this reader may place
 *   POST /report-blocks/{key}/preview   one block's data for a context
 *
 * Both answer 402 without the `report_studio` plan feature: the Studio holds
 * no stored records yet, so there is nothing to keep readable and the whole
 * surface locks. The preview writes nothing; the subject is checked on every
 * call by the engine.
 */
final class ReportBlocksRestController extends BaseController {

    const NS = 'talenttrack/v1';

    /** The largest preview body accepted, in bytes. */
    public const MAX_BODY_BYTES = 16384;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/report-blocks', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_blocks' ],
                'permission_callback' => self::permCanFeature( 'tt_view_reports', 'report_studio' ),
                'args'                => [
                    'scope'    => [ 'type' => 'string', 'description' => 'me, player, team, group or academy. Omit for every scope. Same as filter[scope]. Any other value is refused with 400 bad_filter.' ],
                    'audience' => [ 'type' => 'string', 'description' => 'internal, scout or family; internal when omitted. Same as filter[audience]. Any other value is refused with 400 bad_filter.' ],
                    'filter'   => [ 'description' => 'Nested filters: scope, audience. A nested value wins over the plain parameter of the same name.' ],
                ],
            ],
        ] );
        register_rest_route( self::NS, '/report-blocks/(?P<key>[a-z0-9_]+)/preview', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'preview' ],
                'permission_callback' => self::permCanFeature( 'tt_view_reports', 'report_studio' ),
                'args'                => self::previewArgs(),
            ],
        ] );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function previewArgs(): array {
        return [
            'key'     => [ 'type' => 'string', 'description' => 'The block key, from GET /report-blocks.' ],
            'context' => [ 'type' => 'object', 'required' => true, 'description' => 'scope (me, player, team, group, academy), player_id, team_id or player_ids (at most 60) for the subject, from and to (Y-m-d, at most 396 days), audience (internal, scout, family).' ],
            'options' => [ 'type' => 'object', 'description' => 'The block\'s options, keyed as its schema declares. An undeclared key or value is refused.' ],
        ];
    }

    public static function list_blocks( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $filter   = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        $scope    = self::filterValue( $filter, $r, 'scope' );
        $audience = self::filterValue( $filter, $r, 'audience' );

        if ( $scope !== '' && ! in_array( $scope, ReportContext::SCOPES, true ) ) {
            return self::badFilter( 'scope' );
        }
        if ( $audience !== '' && ! PlayerReportAudience::isValid( $audience ) ) {
            return self::badFilter( 'audience' );
        }
        if ( $audience === '' ) $audience = PlayerReportAudience::INTERNAL;

        $user_id = get_current_user_id();
        $scopes  = $scope !== '' ? [ $scope ] : ReportContext::SCOPES;
        $out     = [];
        foreach ( $scopes as $s ) {
            $context = ReportContext::forListing( $s, $audience, $user_id );
            foreach ( BlockRegistry::availableFor( $context ) as $block ) {
                if ( isset( $out[ $block->key() ] ) ) {
                    $out[ $block->key() ]['available_scopes'][] = $s;
                    continue;
                }
                $out[ $block->key() ] = self::describe( $block, $context ) + [ 'available_scopes' => [ $s ] ];
            }
        }

        return RestResponse::success( [ 'blocks' => array_values( $out ) ] );
    }

    public static function preview( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        if ( strlen( (string) $r->get_body() ) > self::MAX_BODY_BYTES ) {
            return RestResponse::error(
                'body_too_large',
                /* translators: %d: maximum size in bytes */
                sprintf( __( 'A preview request may be at most %d bytes.', 'talenttrack' ), self::MAX_BODY_BYTES ),
                413,
                [ 'max_bytes' => self::MAX_BODY_BYTES ]
            );
        }

        $bad = self::checkBody( $r, self::previewArgs() );
        if ( $bad ) return $bad;

        $context = $r->get_param( 'context' );
        $options = $r->get_param( 'options' );
        if ( ! is_array( $context ) ) {
            return RestResponse::error( 'invalid_field', __( 'The context must be an object.', 'talenttrack' ), 400, [ 'field' => 'context' ] );
        }
        if ( $options !== null && ! is_array( $options ) ) {
            return RestResponse::error( 'invalid_field', __( 'The options must be an object.', 'talenttrack' ), 400, [ 'field' => 'options' ] );
        }

        $result = BlockEngine::preview( (string) $r['key'], $context, is_array( $options ) ? $options : [], get_current_user_id() );
        if ( $result instanceof StudioError ) {
            return RestResponse::error( $result->code, $result->message, $result->status, $result->details );
        }
        return RestResponse::success( $result );
    }

    /**
     * @return array<string,mixed>
     */
    private static function describe( ReportBlock $block, ReportContext $context ): array {
        return [
            'key'        => $block->key(),
            'label'      => $block->label(),
            'scopes'     => $block->scopes(),
            'audiences'  => $block->audiences(),
            'capability' => $block->capability(),
            'options'    => array_map( static fn( $o ) => $o->toArray(), $block->options( $context ) ),
        ];
    }

    /**
     * @param array<mixed> $filter
     */
    private static function filterValue( array $filter, WP_REST_Request $r, string $key ): string {
        $nested = $filter[ $key ] ?? null;
        if ( is_scalar( $nested ) && (string) $nested !== '' ) return trim( (string) $nested );
        $plain = $r->get_param( $key );
        return is_scalar( $plain ) ? trim( (string) $plain ) : '';
    }

    private static function badFilter( string $param ): WP_REST_Response {
        return RestResponse::error( 'bad_filter', __( 'That filter value cannot be read.', 'talenttrack' ), 400, [ 'parameter' => $param ] );
    }
}
