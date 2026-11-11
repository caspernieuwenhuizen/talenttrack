<?php
namespace TT\Modules\ReportStudio\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Authorization\PersonaLabels;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Domain\StudioHome;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * StudioHomesRestController (#4962) — which Report Studio dashboard a persona
 * lands on after logging in.
 *
 *   GET    /studio-homes             every persona that can have one, with its dashboard
 *   PUT    /studio-homes/{persona}   make a dashboard that persona's home
 *   DELETE /studio-homes/{persona}   back to the persona's dashboard template
 *
 * Choosing a persona's home is editing what everyone holding it sees first,
 * so it takes the persona dashboard editor's capability,
 * `tt_edit_persona_templates`, with the Studio switched on and in the plan.
 * The caller must also be able to open the dashboard. Whether it may be a
 * home is `StudioHome`'s answer. The editor's own routes
 * (`/personas/{slug}/template*`) are untouched.
 */
final class StudioHomesRestController extends BaseController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $edit = self::permCanFeature( 'tt_edit_persona_templates', 'report_studio' );

        register_rest_route( self::NS, '/studio-homes', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_homes' ],
                'permission_callback' => $edit,
            ],
        ] );
        register_rest_route( self::NS, '/studio-homes/(?P<persona>[a-z_]+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'set_home' ],
                'permission_callback' => $edit,
                'args'                => self::putArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/studio-homes/(?P<persona>[a-z_]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'clear_home' ],
                'permission_callback' => $edit,
                'args'                => [],
            ],
        ] );
    }

    /** @return array<string, array<string, mixed>> */
    private static function putArgs(): array {
        return [
            'report_id' => [ 'type' => 'integer', 'description' => 'The dashboard: published to this persona, with a subject chosen when opened or following the viewer.' ],
        ];
    }

    public static function list_homes( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $reader = ReportReader::for( get_current_user_id() );
        $rows   = [];
        foreach ( ReportAccess::PUBLISHABLE_PERSONAS as $persona ) {
            $report = StudioHome::reportFor( $persona );
            $row    = self::row( $persona, $report );
            // A dashboard the caller cannot open is named by its id only.
            if ( $report !== null && ! ReportAccess::canRead( $reader, $report ) ) $row['report_name'] = null;
            $rows[] = $row;
        }
        return RestResponse::success( [ 'homes' => $rows ] );
    }

    public static function set_home( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, self::putArgs() );
        if ( $bad ) return $bad;

        $persona = (string) $r['persona'];
        $body    = $r->get_json_params();
        if ( ! $body ) $body = $r->get_body_params();
        $raw = $body['report_id'] ?? null;
        $id  = is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ? (int) $raw : 0;
        if ( $id <= 0 ) {
            return RestResponse::error(
                'missing_fields',
                /* translators: %s: comma-separated field names */
                sprintf( __( 'These fields are required: %s.', 'talenttrack' ), 'report_id' ),
                400,
                [ 'fields' => [ 'report_id' ] ]
            );
        }

        // A dashboard the caller cannot open is not one they choose for others.
        $report = ( new ReportsRepository() )->find( $id );
        if ( $report !== null && ! ReportAccess::canRead( ReportReader::for( get_current_user_id() ), $report ) ) {
            return RestResponse::error( 'forbidden', __( 'This report is not shared with you.', 'talenttrack' ), 403 );
        }

        $set = StudioHome::set( $persona, $id );
        if ( $set instanceof StudioError ) {
            return RestResponse::error( $set->code, $set->message, $set->status, $set->details );
        }
        return RestResponse::success( self::row( $persona, $set ) );
    }

    public static function clear_home( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $persona = (string) $r['persona'];
        $refused = StudioHome::personaRefusal( $persona );
        if ( $refused !== null ) {
            return RestResponse::error( $refused->code, $refused->message, $refused->status, $refused->details );
        }
        StudioHome::clear( $persona );
        return RestResponse::success( self::row( $persona, null ) );
    }

    /**
     * @param array{id:int, name:string}|null $report
     * @return array{persona:string, label:string, report_id:?int, report_name:?string}
     */
    private static function row( string $persona, ?array $report ): array {
        return [
            'persona'     => $persona,
            'label'       => PersonaLabels::label( $persona ),
            'report_id'   => $report !== null ? $report['id'] : null,
            'report_name' => $report !== null ? $report['name'] : null,
        ];
    }
}
