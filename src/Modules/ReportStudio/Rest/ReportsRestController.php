<?php
namespace TT\Modules\ReportStudio\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportData;
use TT\Modules\ReportStudio\Domain\ReportDefinition;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ReportsRestController (#4569) — stored Studio reports.
 *
 *   GET    /reports                    mine, and the ones published to a persona I hold
 *   POST   /reports                    create, validated by ReportStartRules
 *   GET    /reports/{id}               one report, with my draft when I build it
 *   PATCH  /reports/{id}               my draft (definition) and the name; partial
 *   DELETE /reports/{id}               archive
 *   POST   /reports/{id}/restore       un-archive
 *   POST   /reports/{id}/draft         replace my draft with a version
 *   GET    /reports/{id}/versions      the published versions
 *   POST   /reports/{id}/versions      publish my draft
 *   GET    /reports/{id}/data          every block's data, for me
 *
 * Every route answers 402 without the `report_studio` plan feature. The
 * capabilities are club-wide, so each by-id route asks `ReportAccess` about
 * the report itself.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class ReportsRestController extends BaseController {

    const NS = 'talenttrack/v1';

    private const PER_PAGE = [ 10, 25, 50, 100 ];

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $view  = self::permCanFeature( 'tt_view_reports', 'report_studio' );
        $build = self::permCanFeature( 'tt_build_reports', 'report_studio' );

        register_rest_route( self::NS, '/reports', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_reports' ],
                'permission_callback' => $view,
                'args'                => [
                    'kind'     => [ 'type' => 'string', 'description' => 'report or dashboard. Same as filter[kind].' ],
                    'scope'    => [ 'type' => 'string', 'description' => 'me, player, team, group or academy. Same as filter[scope].' ],
                    'owner'    => [ 'type' => 'string', 'description' => 'me (reports I own) or published (reports published to a persona I hold); both when omitted. Same as filter[owner].' ],
                    'archived' => [ 'type' => 'string', 'description' => 'active (default) or archived. Same as filter[archived].' ],
                    'filter'   => [ 'description' => 'Nested filters: kind, scope, owner, archived. A nested value wins over the plain parameter.' ],
                    'page'     => [ 'type' => 'integer', 'description' => 'Page number, from 1.' ],
                    'per_page' => [ 'type' => 'integer', 'description' => '10, 25 (default), 50 or 100.' ],
                    'order'    => [ 'type' => 'string', 'description' => 'desc (default, newest first) or asc, on the report id.' ],
                ],
            ],
        ] );
        register_rest_route( self::NS, '/reports', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_report' ],
                'permission_callback' => $build,
                'args'                => self::createArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_report' ],
                'permission_callback' => $view,
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patch_report' ],
                'permission_callback' => $build,
                'args'                => self::patchArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive_report' ],
                'permission_callback' => $build,
                'args'                => [],
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/restore', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'restore_report' ],
                'permission_callback' => $build,
                'args'                => [],
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/draft', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'draft_from_version' ],
                'permission_callback' => $build,
                'args'                => self::draftArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/versions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_versions' ],
                'permission_callback' => $build,
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/versions', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'publish' ],
                'permission_callback' => $build,
                'args'                => self::publishArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/data', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_data' ],
                'permission_callback' => $view,
                'args'                => [
                    'source'    => [ 'type' => 'string', 'description' => 'draft (a builder\'s own, the default for builders) or published (the default for everyone else).' ],
                    'player_id' => [ 'type' => 'integer', 'description' => 'The player, for a report about a player chosen when opened. A recipient cannot change a fixed subject.' ],
                    'team_id'   => [ 'type' => 'integer', 'description' => 'The team, for a report about a team chosen when opened, or one of the viewer\'s teams.' ],
                    'from'      => [ 'type' => 'string', 'description' => 'Y-m-d. Only for a period chosen when opened, or by a builder.' ],
                    'to'        => [ 'type' => 'string', 'description' => 'Y-m-d. As from.' ],
                ],
            ],
        ] );
    }

    /** @return array<string, array<string, mixed>> */
    private static function createArgs(): array {
        return [
            'kind'         => [ 'type' => 'string', 'description' => 'report (default) or dashboard.' ],
            'scope'        => [ 'type' => 'string', 'description' => 'me, player, team, group or academy.' ],
            'binding'      => [ 'type' => 'string', 'description' => 'fixed (default), on_open or viewer.' ],
            'audience'     => [ 'type' => 'string', 'description' => 'internal (default), scout or family.' ],
            'period_mode'  => [ 'type' => 'string', 'description' => 'fixed, rolling or on_open (default).' ],
            'period_value' => [ 'type' => 'string', 'description' => 'from/to (Y-m-d/Y-m-d) for fixed; last_week, last_month, this_month or this_season for rolling.' ],
            'layout'       => [ 'type' => 'string', 'description' => 'rows (default) or grid.' ],
            'sharing'      => [ 'type' => 'string', 'description' => 'me (default), role, schedule or family.' ],
            'name'         => [ 'type' => 'string', 'description' => 'At most 255 characters.' ],
            'player_id'    => [ 'type' => 'integer', 'description' => 'The player, for a fixed report about one player.' ],
            'team_id'      => [ 'type' => 'integer', 'description' => 'The team, for a fixed report about one team.' ],
            'cohort'       => [ 'type' => 'object', 'description' => 'A group: team_ids (list), age_group and position (optional). Never player ids.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function patchArgs(): array {
        return [
            'name'       => [ 'type' => 'string', 'description' => 'The report name. Omitted keeps it; empty clears it.' ],
            'definition' => [ 'description' => 'My draft: { blocks: [ { id, key, options } ] }. Omitted keeps it; empty clears it.' ],
            'revision'   => [ 'type' => 'integer', 'description' => 'The draft revision this edit was made on. A stale one is refused with 409.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function draftArgs(): array {
        return [
            'from_version' => [ 'type' => 'integer', 'description' => 'The version number whose definition replaces my draft.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function publishArgs(): array {
        return [
            'note'         => [ 'type' => 'string', 'description' => 'What changed, for the version list. At most 500 characters.' ],
            'persona_keys' => [ 'type' => 'array', 'description' => 'The personas the report is published to. Empty publishes it for me only.' ],
        ];
    }

    public static function list_reports( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $filter  = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        $allowed = [
            'kind'     => ReportStartRules::KINDS,
            'scope'    => ReportContext::SCOPES,
            'owner'    => [ 'me', 'published' ],
            'archived' => [ 'active', 'archived' ],
        ];
        $values = [];
        foreach ( $allowed as $key => $vocabulary ) {
            $value = self::filterValue( $filter, $r, $key );
            if ( $value !== '' && ! in_array( $value, $vocabulary, true ) ) return self::badFilter( $key );
            $values[ $key ] = $value;
        }
        if ( $values['archived'] === '' ) $values['archived'] = 'active';

        $page     = $r->get_param( 'page' ) !== null ? (int) $r->get_param( 'page' ) : 1;
        $per_page = $r->get_param( 'per_page' ) !== null ? (int) $r->get_param( 'per_page' ) : 25;
        $order    = is_string( $r->get_param( 'order' ) ) ? strtolower( (string) $r->get_param( 'order' ) ) : 'desc';
        if ( $page < 1 ) return self::badFilter( 'page' );
        if ( ! in_array( $per_page, self::PER_PAGE, true ) ) return self::badFilter( 'per_page' );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) return self::badFilter( 'order' );

        $user_id = get_current_user_id();
        $result  = ( new ReportsRepository() )->listFor(
            $user_id,
            array_values( PersonaResolver::personasFor( $user_id ) ),
            [ 'kind' => $values['kind'], 'scope' => $values['scope'], 'owner' => $values['owner'], 'archived' => $values['archived'] ],
            $page,
            $per_page,
            $order === 'asc'
        );

        return RestResponse::success( [
            'rows'     => array_map( static fn( array $report ): array => self::payload( $report, $user_id ), $result['rows'] ),
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    public static function create_report( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, self::createArgs() );
        if ( $bad ) return $bad;

        $user_id = get_current_user_id();
        $choices = ReportStartRules::validate( self::body( $r ), $user_id );
        if ( $choices instanceof StudioError ) return self::refuse( $choices );

        $repo = new ReportsRepository();
        $id   = $repo->create( $choices, $user_id );
        $report = $id > 0 ? $repo->find( $id ) : null;
        if ( $report === null ) {
            return RestResponse::error( 'db_error', __( 'The report could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( self::full( $report, $user_id ), 201 );
    }

    public static function get_report( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $report = ( new ReportsRepository() )->find( (int) $r['id'] );
        if ( $report === null ) return RestResponse::notFound();
        $user_id = get_current_user_id();
        if ( ! ReportAccess::canRead( $user_id, $report ) ) return self::notShared();

        return RestResponse::success( self::full( $report, $user_id ) );
    }

    /**
     * Partial: an omitted field keeps its stored value, a field sent empty
     * clears it. `revision`, when sent, must be the draft's current one.
     */
    public static function patch_report( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, self::patchArgs() );
        if ( $bad ) return $bad;

        $loaded = self::editable( (int) $r['id'] );
        if ( $loaded instanceof WP_REST_Response ) return $loaded;
        [ $report, $user_id ] = $loaded;

        $body = self::body( $r );
        if ( ! array_key_exists( 'name', $body ) && ! array_key_exists( 'definition', $body ) ) {
            return RestResponse::error( 'no_writable_field', __( 'Send a name or a definition to change.', 'talenttrack' ), 400, [ 'allowed' => [ 'name', 'definition' ] ] );
        }

        $revision = null;
        if ( array_key_exists( 'revision', $body ) ) {
            if ( ! is_int( $body['revision'] ) && ! ( is_string( $body['revision'] ) && ctype_digit( $body['revision'] ) ) ) {
                return RestResponse::error( 'invalid_field', __( 'The revision is a whole number.', 'talenttrack' ), 400, [ 'field' => 'revision' ] );
            }
            $revision = (int) $body['revision'];
        }

        $repo  = new ReportsRepository();
        $draft = $repo->ensureDraft( $report, $user_id );
        if ( $revision !== null && $revision !== $draft['revision'] ) return self::stale( $draft['revision'] );

        $name = null;
        if ( array_key_exists( 'name', $body ) ) {
            $name = is_string( $body['name'] ) ? trim( $body['name'] ) : ( $body['name'] === null ? '' : null );
            if ( $name === null || mb_strlen( $name ) > ReportStartRules::MAX_NAME ) {
                /* translators: %d: maximum number of characters */
                return RestResponse::error( 'invalid_field', sprintf( __( 'A report name is at most %d characters.', 'talenttrack' ), ReportStartRules::MAX_NAME ), 400, [ 'field' => 'name' ] );
            }
        }

        if ( array_key_exists( 'definition', $body ) ) {
            $definition = ReportDefinition::normalise( $body['definition'] );
            if ( $definition instanceof StudioError ) return self::refuse( $definition );
            $saved = $repo->saveDraft( $report['id'], $user_id, $definition, $revision ?? $draft['revision'] );
            if ( $saved === null ) {
                $current = $repo->draft( $report['id'], $user_id );
                return self::stale( $current !== null ? $current['revision'] : 0 );
            }
        }
        if ( $name !== null ) $repo->rename( $report['id'], $name );

        $fresh = $repo->find( $report['id'] );
        return RestResponse::success( self::full( $fresh ?? $report, $user_id ) );
    }

    public static function archive_report( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $loaded = self::editable( (int) $r['id'] );
        if ( $loaded instanceof WP_REST_Response ) return $loaded;
        [ $report, $user_id ] = $loaded;

        if ( $report['archived_at'] === null ) {
            ( new ArchiveRepository() )->archive( 'report', [ $report['id'] ], $user_id );
        }
        return RestResponse::success( [ 'archived' => true, 'id' => $report['id'] ] );
    }

    public static function restore_report( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, [] );
        if ( $bad ) return $bad;

        $loaded = self::editable( (int) $r['id'] );
        if ( $loaded instanceof WP_REST_Response ) return $loaded;
        [ $report ] = $loaded;

        if ( $report['archived_at'] === null || ( new ArchiveRepository() )->restore( 'report', [ $report['id'] ] ) === 0 ) {
            return RestResponse::error( 'not_found', __( 'This report is not archived.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'restored' => true, 'id' => $report['id'] ] );
    }

    /** Version restore: my draft becomes that version's definition; publishing makes it live. */
    public static function draft_from_version( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, self::draftArgs() );
        if ( $bad ) return $bad;

        $loaded = self::editable( (int) $r['id'] );
        if ( $loaded instanceof WP_REST_Response ) return $loaded;
        [ $report, $user_id ] = $loaded;

        $body = self::body( $r );
        $no   = $body['from_version'] ?? null;
        if ( ! is_int( $no ) && ! ( is_string( $no ) && ctype_digit( $no ) ) ) {
            return RestResponse::error(
                'missing_fields',
                /* translators: %s: comma-separated field names */
                sprintf( __( 'These fields are required: %s.', 'talenttrack' ), 'from_version' ),
                400,
                [ 'fields' => [ 'from_version' ] ]
            );
        }

        $repo    = new ReportsRepository();
        $version = $repo->version( $report['id'], (int) $no );
        if ( $version === null ) return RestResponse::notFound( 'not_found', __( 'There is no such version of this report.', 'talenttrack' ) );

        $repo->ensureDraft( $report, $user_id );
        $repo->saveDraft( $report['id'], $user_id, $version['definition'], null );

        return RestResponse::success( [ 'from_version' => $version['version_no'], 'draft' => $repo->draft( $report['id'], $user_id ) ] );
    }

    public static function list_versions( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $loaded = self::editable( (int) $r['id'] );
        if ( $loaded instanceof WP_REST_Response ) return $loaded;
        [ $report ] = $loaded;

        return RestResponse::success( [ 'versions' => ( new ReportsRepository() )->versions( $report['id'] ) ] );
    }

    /** Publishing is its own action with its own note, never a field on the autosaving draft. */
    public static function publish( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;
        $bad = self::checkBody( $r, self::publishArgs() );
        if ( $bad ) return $bad;

        $loaded = self::editable( (int) $r['id'] );
        if ( $loaded instanceof WP_REST_Response ) return $loaded;
        [ $report, $user_id ] = $loaded;

        $body = self::body( $r );
        $note = is_string( $body['note'] ?? null ) ? sanitize_textarea_field( (string) $body['note'] ) : '';
        if ( mb_strlen( $note ) > 500 ) {
            return RestResponse::error( 'invalid_field', __( 'A version note is at most 500 characters.', 'talenttrack' ), 400, [ 'field' => 'note' ] );
        }

        $raw  = $body['persona_keys'] ?? [];
        $keys = [];
        foreach ( is_array( $raw ) ? $raw : [ $raw ] as $key ) {
            if ( ! is_string( $key ) || ! in_array( $key, ReportAccess::PUBLISHABLE_PERSONAS, true ) ) {
                return RestResponse::error( 'invalid_field', __( 'A report is published to staff roles only.', 'talenttrack' ), 400, [ 'field' => 'persona_keys', 'allowed' => ReportAccess::PUBLISHABLE_PERSONAS ] );
            }
            $keys[ $key ] = $key;
        }
        $keys = array_values( $keys );

        if ( ! ReportAccess::canPublish( $user_id, $report, $keys ) ) {
            return RestResponse::error( 'forbidden_publish', __( 'You may publish this report for yourself, not for others.', 'talenttrack' ), 403 );
        }
        if ( $keys !== [] && $report['sharing'] !== ReportStartRules::SHARING_ROLE ) {
            return RestResponse::error( 'invalid_field', __( 'This report was made to be shared only with you.', 'talenttrack' ), 400, [ 'field' => 'persona_keys' ] );
        }

        $repo    = new ReportsRepository();
        $draft   = $repo->ensureDraft( $report, $user_id );
        $version = $repo->publish( $report['id'], $draft['definition'], $note, $keys, $user_id );
        if ( $version === null ) {
            return RestResponse::error( 'version_conflict', __( 'Someone published this report at the same moment. Try again.', 'talenttrack' ), 409 );
        }

        return RestResponse::success( [ 'version_no' => $version['version_no'], 'persona_keys' => $keys ], 201 );
    }

    public static function get_data( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $report = ( new ReportsRepository() )->find( (int) $r['id'] );
        if ( $report === null ) return RestResponse::notFound();
        $user_id = get_current_user_id();
        if ( ! ReportAccess::canRead( $user_id, $report ) ) return self::notShared();

        $source = is_string( $r->get_param( 'source' ) ) ? (string) $r->get_param( 'source' ) : '';
        if ( ! in_array( $source, [ '', 'draft', 'published' ], true ) ) return self::badFilter( 'source' );

        $id_param = static function ( mixed $value ): ?int {
            return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
        };
        $text = static fn( mixed $value ): string => is_string( $value ) ? trim( $value ) : '';

        $data = ReportData::compose( $report, [
            'source'    => $source,
            'player_id' => $id_param( $r->get_param( 'player_id' ) ),
            'team_id'   => $id_param( $r->get_param( 'team_id' ) ),
            'from'      => $text( $r->get_param( 'from' ) ),
            'to'        => $text( $r->get_param( 'to' ) ),
        ], $user_id );
        if ( $data instanceof StudioError ) return self::refuse( $data );

        return RestResponse::success( $data );
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * The report, when it exists and this user may edit it.
     *
     * @return array{0:Report, 1:int}|WP_REST_Response
     */
    private static function editable( int $id ): array|WP_REST_Response {
        $report = ( new ReportsRepository() )->find( $id );
        if ( $report === null ) return RestResponse::notFound();
        $user_id = get_current_user_id();
        if ( ! ReportAccess::canEdit( $user_id, $report ) ) {
            return ReportAccess::canRead( $user_id, $report )
                ? RestResponse::error( 'forbidden', __( 'You may read this report, not change it.', 'talenttrack' ), 403 )
                : self::notShared();
        }
        return [ $report, $user_id ];
    }

    /**
     * The summary every list row and every single report carries.
     *
     * @param Report $report
     * @return array<string,mixed>
     */
    private static function payload( array $report, int $user_id ): array {
        return [
            'id'                   => $report['id'],
            'uuid'                 => $report['uuid'],
            'name'                 => $report['name'],
            'kind'                 => $report['kind'],
            'scope'                => $report['scope'],
            'binding'              => $report['binding'],
            'audience'             => $report['audience'],
            'period_mode'          => $report['period_mode'],
            'period_value'         => $report['period_value'],
            'layout'               => $report['layout'],
            'sharing'              => $report['sharing'],
            'subject'              => [
                'player_id' => $report['player_id'],
                'team_id'   => $report['team_id'],
                'cohort'    => $report['cohort'],
            ],
            'owner_user_id'        => $report['owner_user_id'],
            'published_version_id' => $report['published_version_id'],
            'created_at'           => $report['created_at'],
            'updated_at'           => $report['updated_at'],
            'archived_at'          => $report['archived_at'],
            'permissions'          => ReportAccess::permissions( $user_id, $report ),
        ];
    }

    /**
     * One report, with the builder's own draft and the personas it is
     * published to when the caller builds it.
     *
     * @param Report $report
     * @return array<string,mixed>
     */
    private static function full( array $report, int $user_id ): array {
        $out = self::payload( $report, $user_id );
        if ( ReportAccess::canEdit( $user_id, $report ) ) {
            $repo                = new ReportsRepository();
            $out['draft']        = $repo->draft( $report['id'], $user_id );
            $out['persona_keys'] = $repo->personaKeys( $report['id'] );
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function body( WP_REST_Request $r ): array {
        $body = $r->get_json_params();
        if ( ! $body ) $body = $r->get_body_params();
        $out = [];
        foreach ( (array) $body as $key => $value ) $out[ (string) $key ] = $value;
        return $out;
    }

    private static function refuse( StudioError $error ): WP_REST_Response {
        return RestResponse::error( $error->code, $error->message, $error->status, $error->details );
    }

    private static function notShared(): WP_REST_Response {
        return RestResponse::error( 'forbidden', __( 'This report is not shared with you.', 'talenttrack' ), 403 );
    }

    private static function stale( int $current ): WP_REST_Response {
        return RestResponse::error(
            'stale_revision',
            __( 'This report was changed elsewhere. Reload it before saving again.', 'talenttrack' ),
            409,
            [ 'field' => 'revision', 'current_revision' => $current ]
        );
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
