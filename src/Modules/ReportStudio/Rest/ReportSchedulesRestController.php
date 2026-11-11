<?php
namespace TT\Modules\ReportStudio\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportSchedules;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ReportSchedulesRestController (#4963, epic #4564) — schedules of a
 * published Report Studio report.
 *
 *   GET    /reports/{id}/schedules          the report's schedules the caller may manage
 *   POST   /reports/{id}/schedules          a new schedule
 *   GET    /reports/{id}/schedules/preview  what these recipients would get, before Save
 *   GET    /scheduled-reports/{id}          one Studio schedule, with what its recipients get now
 *   PATCH  /scheduled-reports/{id}          change only the fields sent
 *   DELETE /scheduled-reports/{id}          archive it: it never runs again
 *
 * Every route needs `tt_build_reports` with the Studio and scheduled reports
 * in the plan, then the record checks in `ReportSchedules`: someone who may
 * change the report, and for a schedule its owner or someone who publishes
 * reports. Only Studio schedules are served here; the other scheduled
 * reports keep their own screens.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class ReportSchedulesRestController extends BaseController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $build = self::permCanFeature( 'tt_build_reports', 'report_studio' );
        $id    = [ 'id' => [ 'type' => 'integer', 'required' => true, 'validate_callback' => [ self::class, 'isPositiveInt' ] ] ];

        register_rest_route( self::NS, '/reports/(?P<id>\d+)/schedules', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_schedules' ],
                'permission_callback' => $build,
                'args'                => $id,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_schedule' ],
                'permission_callback' => $build,
                'args'                => self::writeArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/schedules/preview', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'preview' ],
                'permission_callback' => $build,
                'args'                => $id + [
                    'recipients' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Recipient entries: user:<id> or a staff role key.' ],
                    'period'     => [ 'type' => 'string', 'description' => 'last_week, last_month, this_month or this_season; only for a report whose period is chosen when opened.' ],
                ],
            ],
        ] );
        register_rest_route( self::NS, '/scheduled-reports/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_schedule' ],
                'permission_callback' => $build,
                'args'                => $id,
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patch_schedule' ],
                'permission_callback' => $build,
                'args'                => self::writeArgs() + [
                    'status' => [ 'type' => 'string', 'description' => 'active or paused.' ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive_schedule' ],
                'permission_callback' => $build,
                'args'                => $id,
            ],
        ] );
    }

    /** @return array<string, array<string,mixed>> */
    private static function writeArgs(): array {
        return [
            'id'         => [ 'type' => 'integer', 'required' => true, 'validate_callback' => [ self::class, 'isPositiveInt' ] ],
            'name'       => [ 'type' => 'string', 'description' => 'The schedule\'s name; the report\'s name when empty.' ],
            'recipients' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Accounts (user:<id>) and staff role keys only. Anyone but yourself needs the right to publish reports.' ],
            'frequency'  => [ 'type' => 'string', 'description' => 'weekly_monday, monthly_first (default) or season_end.' ],
            'period'     => [ 'type' => 'string', 'description' => 'last_week, last_month (default), this_month or this_season; only kept for a report whose period is chosen when opened.' ],
        ];
    }

    public static function list_schedules( WP_REST_Request $r ): WP_REST_Response {
        $blocked = self::gate();
        if ( $blocked ) return $blocked;
        $reader = ReportReader::for( get_current_user_id() );
        $report = self::report( (int) $r['id'], $reader );
        if ( $report instanceof WP_REST_Response ) return $report;

        $rows = [];
        foreach ( ReportSchedules::listFor( $report, $reader ) as $schedule ) {
            $rows[] = ReportSchedules::present( $schedule, $report, false );
        }
        return RestResponse::success( [ 'rows' => $rows, 'schedulable' => ReportSchedules::refusal( $report ) === null, 'refusal' => ReportSchedules::refusal( $report ) ] );
    }

    public static function create_schedule( WP_REST_Request $r ): WP_REST_Response {
        $blocked = self::gate();
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, self::writeArgs() );
        if ( $bad ) return $bad;

        $reader = ReportReader::for( get_current_user_id() );
        $report = self::report( (int) $r['id'], $reader );
        if ( $report instanceof WP_REST_Response ) return $report;

        $id = ReportSchedules::create( $report, self::body( $r ), $reader );
        if ( $id instanceof StudioError ) return self::refuse( $id );
        $found = ReportSchedules::findForReader( $id, $reader );
        if ( $found instanceof StudioError ) return self::refuse( $found );
        return RestResponse::success( ReportSchedules::present( $found['schedule'], $found['report'], true ), 201 );
    }

    public static function preview( WP_REST_Request $r ): WP_REST_Response {
        $blocked = self::gate();
        if ( $blocked ) return $blocked;
        $reader = ReportReader::for( get_current_user_id() );
        $report = self::report( (int) $r['id'], $reader );
        if ( $report instanceof WP_REST_Response ) return $report;
        $refusal = ReportSchedules::refusal( $report );
        if ( $refusal !== null ) return RestResponse::error( 'not_schedulable', $refusal, 400, [ 'field' => 'report_id' ] );

        $recipients = ReportSchedules::recipients( $r->get_param( 'recipients' ) ?? [], $reader );
        if ( $recipients instanceof StudioError ) return self::refuse( $recipients );
        $period = ReportSchedules::period( $r->get_param( 'period' ), $report );
        if ( $period instanceof StudioError ) return self::refuse( $period );

        return RestResponse::success( ReportSchedules::preview( $report, $recipients, $reader->userId, [ 'report_id' => $report['id'], 'period' => $period ] ) );
    }

    public static function get_schedule( WP_REST_Request $r ): WP_REST_Response {
        $blocked = self::gate();
        if ( $blocked ) return $blocked;
        $found = ReportSchedules::findForReader( (int) $r['id'], ReportReader::for( get_current_user_id() ) );
        if ( $found instanceof StudioError ) return self::refuse( $found );
        return RestResponse::success( ReportSchedules::present( $found['schedule'], $found['report'], true ) );
    }

    public static function patch_schedule( WP_REST_Request $r ): WP_REST_Response {
        $blocked = self::gate();
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, self::writeArgs() + [ 'status' => [] ] );
        if ( $bad ) return $bad;

        $reader = ReportReader::for( get_current_user_id() );
        $found  = ReportSchedules::findForReader( (int) $r['id'], $reader );
        if ( $found instanceof StudioError ) return self::refuse( $found );

        $done = ReportSchedules::update( $found, self::body( $r ), $reader );
        if ( $done instanceof StudioError ) return self::refuse( $done );
        $fresh = ReportSchedules::findForReader( (int) $r['id'], $reader );
        if ( $fresh instanceof StudioError ) return self::refuse( $fresh );
        return RestResponse::success( ReportSchedules::present( $fresh['schedule'], $fresh['report'], true ) );
    }

    public static function archive_schedule( WP_REST_Request $r ): WP_REST_Response {
        $blocked = self::gate();
        if ( $blocked ) return $blocked;
        $found = ReportSchedules::findForReader( (int) $r['id'], ReportReader::for( get_current_user_id() ) );
        if ( $found instanceof StudioError ) return self::refuse( $found );
        $done = ReportSchedules::archive( $found );
        if ( $done instanceof StudioError ) return self::refuse( $done );
        return RestResponse::success( [ 'id' => (int) $found['schedule']['id'], 'status' => 'archived' ] );
    }

    /** Both plan features: the Studio, and scheduled reports. */
    private static function gate(): ?WP_REST_Response {
        return LicenseGate::enforceFeatureRest( 'report_studio' ) ?? LicenseGate::enforceFeatureRest( 'scheduled_reports' );
    }

    /**
     * The report, when this caller may schedule it.
     *
     * @return Report|WP_REST_Response
     */
    private static function report( int $id, ReportReader $reader ): array|WP_REST_Response {
        $report = ( new ReportsRepository() )->find( $id );
        if ( $report === null ) return RestResponse::error( 'not_found', __( 'There is no such report.', 'talenttrack' ), 404 );
        if ( ! ReportSchedules::canSchedule( $reader, $report ) ) {
            return RestResponse::error( 'forbidden', __( 'You may not change this report.', 'talenttrack' ), 403 );
        }
        return $report;
    }

    /**
     * The fields a caller sent, by name; a field left out is absent, so an
     * update leaves it alone.
     *
     * @return array<string,mixed>
     */
    private static function body( WP_REST_Request $r ): array {
        $body = $r->get_json_params();
        if ( ! $body ) $body = $r->get_body_params();
        $out = [];
        foreach ( [ 'name', 'recipients', 'frequency', 'period', 'status' ] as $key ) {
            if ( array_key_exists( $key, (array) $body ) ) $out[ $key ] = $body[ $key ];
        }
        return $out;
    }

    private static function refuse( StudioError $e ): WP_REST_Response {
        return RestResponse::error( $e->code, $e->message, $e->status, $e->details );
    }
}
