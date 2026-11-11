<?php
namespace TT\Modules\ReportStudio\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Domain\ReportTemplate;
use TT\Modules\ReportStudio\Domain\ReportTemplateRegistry;
use WP_REST_Response;

/**
 * ReportTemplatesRestController (#5022, epic #4564).
 *
 *   GET /report-templates   the templates I may start a report from
 *
 * The build right, as `POST /reports`; each template is listed only when the
 * starting choices it fixes are ones `ReportStartRules` allows this builder.
 */
final class ReportTemplatesRestController extends BaseController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/report-templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_templates' ],
                'permission_callback' => self::permCanFeature( 'tt_build_reports', 'report_studio' ),
                'args'                => [],
            ],
        ] );
    }

    public static function list_templates(): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $templates = array_map(
            static fn( ReportTemplate $t ): array => $t->toArray(),
            ReportTemplateRegistry::startableBy( get_current_user_id() )
        );
        return RestResponse::success( [ 'templates' => $templates ] );
    }
}
