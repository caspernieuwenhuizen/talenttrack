<?php
namespace TT\Modules\ReportStudio\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Document\StudioDocument;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportSnapshots;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ReportSnapshotsRestController (#5021, epic #4564) — frozen copies of a
 * Report Studio report.
 *
 *   GET  /reports/{id}/snapshots                     the report's copies I may open
 *   POST /reports/{id}/snapshots                     freeze the published version
 *   GET  /report-snapshots/{uuid}                    one copy, with its document
 *   PUT  /report-snapshots/{uuid}/notes/{block}      the note on one block
 *
 * A copy answers to its own subject, by the rules that subject's snapshots
 * answer to, never to the report: it stays readable after the report changes.
 * An unknown uuid and one the caller may not read are the same 403, so a uuid
 * cannot be probed. Every route answers 402 without the `report_studio` plan
 * feature.
 */
final class ReportSnapshotsRestController extends BaseController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $view = ReportsRestController::permReadStudio();

        register_rest_route( self::NS, '/reports/(?P<id>\d+)/snapshots', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_snapshots' ],
                'permission_callback' => $view,
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/snapshots', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_snapshot' ],
                'permission_callback' => $view,
                'args'                => self::createArgs(),
            ],
        ] );
        // A uuid route answers to the snapshot's own subject, decided in the
        // callback on the one read of the row: an unknown uuid and one the
        // caller may not open are the same 403.
        register_rest_route( self::NS, '/report-snapshots/(?P<uuid>[0-9a-f-]{36})', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_snapshot' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            ],
        ] );
        register_rest_route( self::NS, '/report-snapshots/(?P<uuid>[0-9a-f-]{36})/notes/(?P<block>[a-z0-9_-]+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_note' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
                'args'                => [
                    'body' => [ 'type' => 'string', 'required' => false, 'description' => 'The note. Required; an empty string removes it.' ],
                ],
            ],
        ] );
    }

    /** @return array<string, array<string, mixed>> */
    private static function createArgs(): array {
        return [
            'player_id' => [ 'type' => 'integer', 'description' => 'The player, for a report about a player chosen when opened. A fixed subject cannot be changed.' ],
            'team_id'   => [ 'type' => 'integer', 'description' => 'The team, for a report about a team chosen when opened, or one of the viewer\'s teams.' ],
            'from'      => [ 'type' => 'string', 'description' => 'Y-m-d. Only for a period chosen when opened, or by a builder.' ],
            'to'        => [ 'type' => 'string', 'description' => 'Y-m-d. As from.' ],
            'period'    => [ 'type' => 'string', 'description' => 'last_week, last_month, this_month or this_season, computed today, instead of from and to.' ],
            'title'     => [ 'type' => 'string', 'description' => 'Omit for the report name and the period.' ],
        ];
    }

    public static function list_snapshots( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $report = ( new ReportsRepository() )->find( (int) $r['id'] );
        if ( $report === null ) return RestResponse::notFound();
        $reader = ReportReader::for( get_current_user_id() );
        if ( ! ReportAccess::canRead( $reader, $report ) ) return self::notShared();

        return RestResponse::success( [ 'snapshots' => ReportSnapshots::listFor( $report, $reader->userId ) ] );
    }

    /**
     * Freeze the published version for the subject and period asked, under
     * the report's own rules for both. Group and academy reports have no
     * snapshots (400).
     */
    public static function create_snapshot( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, self::createArgs() );
        if ( $bad ) return $bad;

        $report = ( new ReportsRepository() )->find( (int) $r['id'] );
        if ( $report === null ) return RestResponse::notFound();
        $reader = ReportReader::for( get_current_user_id() );
        if ( ! ReportAccess::canRead( $reader, $report ) ) return self::notShared();

        $text = static fn( mixed $value ): string => is_string( $value ) ? trim( $value ) : '';
        $id   = static fn( mixed $value ): ?int => is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;

        $from   = $text( $r->get_param( 'from' ) );
        $to     = $text( $r->get_param( 'to' ) );
        $period = $text( $r->get_param( 'period' ) );
        if ( $period !== '' ) {
            if ( ReportFilters::sanitizePeriod( $period ) === '' ) {
                return RestResponse::error( 'bad_filter', __( 'That filter value cannot be read.', 'talenttrack' ), 400, [ 'parameter' => 'period' ] );
            }
            $window = ReportFilters::periodWindow( $period, (string) current_time( 'Y-m-d' ) );
            if ( $window === null ) {
                return RestResponse::error( 'no_period', ReportFilters::noPeriodMessage(), 422, [ 'fields' => [ 'from', 'to' ] ] );
            }
            $from = $window['from'];
            $to   = $window['to'];
        }

        $uuid = ReportSnapshots::take( $report, [
            'player_id' => $id( $r->get_param( 'player_id' ) ),
            'team_id'   => $id( $r->get_param( 'team_id' ) ),
            'from'      => $from,
            'to'        => $to,
        ], $reader, sanitize_text_field( $text( $r->get_param( 'title' ) ) ) );
        if ( $uuid instanceof StudioError ) {
            return RestResponse::error( $uuid->code, $uuid->message, $uuid->status, $uuid->details );
        }

        return self::snapshot( $uuid, $reader->userId, 201 );
    }

    public static function get_snapshot( WP_REST_Request $r ): WP_REST_Response {
        $read = ReportSnapshots::read( (string) $r['uuid'], get_current_user_id() );
        if ( $read === null ) return self::notShared();
        // After the read, so a 402 does not say whether a uuid exists.
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        return self::payload( $read );
    }

    /**
     * The note on one block of the copy. `body` is required: a request that
     * leaves it out changes nothing (`400 missing_fields`), and an empty one
     * removes the note. A block that is not on the copy is 400; a copy shared
     * with the family, and a reader who may not make a record from its
     * subject's report, are 403. The row is read once.
     */
    public static function put_note( WP_REST_Request $r ): WP_REST_Response {
        $user_id = get_current_user_id();
        $loaded  = ReportSnapshots::load( (string) $r['uuid'] );
        $read    = $loaded !== null ? ReportSnapshots::readLoaded( $loaded, $user_id ) : null;
        if ( $loaded === null || $read === null ) return self::notShared();
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;
        if ( ! $read['can_note'] ) {
            return RestResponse::error( 'forbidden', __( 'You may read this snapshot, not write on it.', 'talenttrack' ), 403 );
        }

        $bad = self::checkBody( $r, [ 'body' => [] ] );
        if ( $bad ) return $bad;
        $body = $r->get_json_params();
        if ( ! is_array( $body ) || ! array_key_exists( 'body', $body ) ) $body = $r->get_body_params();
        if ( ! is_array( $body ) || ! array_key_exists( 'body', $body ) || ! is_string( $body['body'] ) ) {
            return RestResponse::error(
                'missing_fields',
                /* translators: %s: comma-separated field names */
                sprintf( __( 'These fields are required: %s.', 'talenttrack' ), 'body' ),
                400,
                [ 'fields' => [ 'body' ] ]
            );
        }

        $block = sanitize_key( (string) $r['block'] );
        $ids   = array_map( static fn( array $b ): string => sanitize_key( (string) ( $b['id'] ?? '' ) ), $read['document']['blocks'] );
        if ( ! in_array( $block, $ids, true ) ) {
            return RestResponse::error( 'unknown_section', __( 'This block is not on the snapshot.', 'talenttrack' ), 400, [ 'block' => $block ] );
        }

        $after = ReportSnapshots::noteLoaded( $loaded, $block, sanitize_textarea_field( $body['body'] ), $user_id );
        if ( $after === null ) {
            return RestResponse::error( 'note_failed', __( 'The note could not be saved.', 'talenttrack' ), 500 );
        }
        return self::payload( $after );
    }

    /** One copy as the reader may read it, with its document's HTML. */
    private static function snapshot( string $uuid, int $user_id, int $status = 200 ): WP_REST_Response {
        $read = ReportSnapshots::read( $uuid, $user_id );
        if ( $read === null ) return self::notShared();
        return self::payload( $read, $status );
    }

    /**
     * @param array{uuid:string, report_id:int, scope:string, player_id:int, team_id:int, title:string, audience:string,
     *     created_by:int, created_at:string, document:array{context:array<string,mixed>, blocks:list<array<string,mixed>>, page_fit:array<string,mixed>},
     *     notes:array<string,array{body:string, author:int, updated_at:string}>, can_note:bool} $read
     */
    private static function payload( array $read, int $status = 200 ): WP_REST_Response {
        $user_id = get_current_user_id();
        return RestResponse::success( [
            'uuid'       => $read['uuid'],
            'report_id'  => $read['report_id'],
            'scope'      => $read['scope'],
            'player_id'  => $read['player_id'] ?: null,
            'team_id'    => $read['team_id'] ?: null,
            'title'      => $read['title'],
            'audience'   => $read['audience'],
            'created_by' => $read['created_by'],
            'created_at' => $read['created_at'],
            'context'    => $read['document']['context'],
            'blocks'     => $read['document']['blocks'],
            'page_fit'   => $read['document']['page_fit'],
            'notes'      => (object) $read['notes'],
            'can_note'   => $read['can_note'],
            'html'       => StudioDocument::body( $read['document'], $user_id, $read['notes'] ),
        ], $status );
    }

    private static function notShared(): WP_REST_Response {
        return RestResponse::error( 'forbidden', __( 'You do not have access to this snapshot.', 'talenttrack' ), 403 );
    }
}
