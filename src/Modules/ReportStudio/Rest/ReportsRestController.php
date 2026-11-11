<?php
namespace TT\Modules\ReportStudio\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Authorization\PersonaLabels;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Document\StudioDocument;
use TT\Modules\ReportStudio\Document\StudioGrid;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportGrid;
use TT\Modules\ReportStudio\Domain\ReportSnapshots;
use TT\Modules\ReportStudio\Domain\ReportNaming;
use TT\Modules\ReportStudio\Domain\ReportReach;
use TT\Modules\ReportStudio\Domain\ReportSubjectOptions;
use TT\Modules\ReportStudio\Domain\ReportViewerSubjects;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportCreation;
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
 *   GET    /reports/{id}/document      the same, with the document's HTML (#5021)
 *   GET    /reports/{id}/reach         how many people publishing to personas reaches
 *   GET    /reports/subject-options    the teams and players I build reports about
 *   GET    /reports/{id}/subjects      the subjects I may switch a report I read to
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

    /**
     * The read routes' gate: `tt_view_reports` with the feature on, and
     * `report_studio` read at some scope (#4695). The observer holds the
     * first club-wide and never the second, so the Studio stays closed to
     * that seat; a builder holds both, and a team manager reads at team
     * scope.
     */
    public static function permReadStudio(): callable {
        $view = self::permCanFeature( 'tt_view_reports', 'report_studio' );
        return static function () use ( $view ): bool {
            return $view() && ReportReader::for( get_current_user_id() )->readsStudio();
        };
    }

    public static function register(): void {
        $view  = self::permReadStudio();
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
        register_rest_route( self::NS, '/reports/start-options', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'start_options' ],
                'permission_callback' => $build,
                'args'                => [
                    'kind'        => [ 'type' => 'string', 'description' => 'report or dashboard.' ],
                    'scope'       => [ 'type' => 'string', 'description' => 'me (default), player, team, group or academy.' ],
                    'audience'    => [ 'type' => 'string', 'description' => 'internal, scout or family.' ],
                    'binding'     => [ 'type' => 'string', 'description' => 'fixed, on_open or viewer.' ],
                    'period_mode' => [ 'type' => 'string', 'description' => 'on_open, rolling or fixed.' ],
                    'layout'      => [ 'type' => 'string', 'description' => 'rows or grid.' ],
                    'sharing'     => [ 'type' => 'string', 'description' => 'me, role, schedule or family.' ],
                ],
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
                'args'                => self::dataArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/document', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_document' ],
                'permission_callback' => $view,
                'args'                => self::dataArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/subjects', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_subjects' ],
                'permission_callback' => $view,
            ],
        ] );
        register_rest_route( self::NS, '/reports/(?P<id>\d+)/reach', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_reach' ],
                'permission_callback' => $build,
                'args'                => [
                    'persona_keys' => [ 'description' => 'The personas the report would be published to.' ],
                ],
            ],
        ] );
        register_rest_route( self::NS, '/reports/subject-options', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'subject_options' ],
                'permission_callback' => $build,
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

    /**
     * The read arguments `/data` and `/document` share.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function dataArgs(): array {
        return [
            'source'    => [ 'type' => 'string', 'description' => 'draft (a builder\'s own, the default for builders) or published (the default for everyone else).' ],
            'player_id' => [ 'type' => 'integer', 'description' => 'The player, for a report about a player chosen when opened. A recipient cannot change a fixed subject.' ],
            'team_id'   => [ 'type' => 'integer', 'description' => 'The team, for a report about a team chosen when opened, or one of the viewer\'s teams.' ],
            'from'      => [ 'type' => 'string', 'description' => 'Y-m-d. Only for a period chosen when opened, or by a builder.' ],
            'to'        => [ 'type' => 'string', 'description' => 'Y-m-d. As from.' ],
            'audience'  => [ 'type' => 'string', 'description' => 'internal, scout or family: a builder previews the report as that audience gets it. A recipient reads the report\'s own audience.' ],
            'period'    => [ 'type' => 'string', 'description' => 'last_week, last_month, this_month or this_season, computed today, instead of from and to. Same rule as from and to.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function patchArgs(): array {
        return [
            'name'       => [ 'type' => 'string', 'description' => 'The report name. Omitted keeps it; empty clears it.' ],
            'definition' => [ 'description' => 'My draft: { blocks: [ { id, key, options, width, grid } ] }. grid is { x, y, w, h } on 12 columns, for a report laid out as a grid only. Omitted keeps it; empty clears it.' ],
            'revision'   => [ 'type' => 'integer', 'description' => 'The draft revision this edit was made on. A stale one is refused with 409.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function draftArgs(): array {
        return [
            'from_version' => [ 'type' => 'integer', 'description' => 'The version number whose definition replaces my draft.' ],
            'revision'     => [ 'type' => 'integer', 'description' => 'The draft revision this was asked on. A stale one is refused with 409, as on PATCH.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function publishArgs(): array {
        return [
            'note'         => [ 'type' => 'string', 'description' => 'What changed, for the version list. Required; at most 500 characters.' ],
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

        // A report published to me is listed only while I can read what it
        // is about; the query applies that, so it pages and counts exactly.
        $reader   = ReportReader::for( get_current_user_id() );
        $repo     = new ReportsRepository();
        $subjects = ReportAccess::listedSubjects( $reader, $repo->publishedPlayerSubjects( $reader->userId, $reader->personas() ) );
        $list     = $repo->listFor(
            $reader->userId,
            $reader->personas(),
            [ 'kind' => $values['kind'], 'scope' => $values['scope'], 'owner' => $values['owner'], 'archived' => $values['archived'] ],
            $subjects,
            $order === 'asc',
            $page,
            $per_page
        );

        // The page's own player subjects in one read, for its permissions.
        $reader->primePlayers( array_values( array_filter( array_map( static fn( array $row ): int => (int) $row['player_id'], $list['rows'] ) ) ) );

        return RestResponse::success( [
            'rows'     => array_map( static fn( array $report ): array => self::payload( $report, $reader ), $list['rows'] ),
            'total'    => $list['total'],
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
        $id      = ReportCreation::create( self::body( $r ), $user_id );
        if ( $id instanceof StudioError ) return self::refuse( $id );

        $report = ( new ReportsRepository() )->find( $id );
        if ( $report === null ) {
            return RestResponse::error( 'db_error', __( 'The report could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( self::full( $report, ReportReader::for( $user_id ) ), 201 );
    }

    /**
     * Every starting choice's options, each `ok` or switched off with the
     * reason, for the choices sent so far. A choice an earlier one rules out
     * is reported under `moved`. Reads only.
     */
    public static function start_options( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $in = [];
        foreach ( ReportStartRules::FIELDS as $field ) {
            $value = $r->get_param( $field );
            if ( $value === null || $value === '' ) continue;
            if ( ! is_string( $value ) || ! in_array( $value, ReportStartRules::values( $field ), true ) ) return self::badFilter( $field );
            $in[ $field ] = $value;
        }
        if ( ! isset( $in['scope'] ) ) $in['scope'] = ReportContext::SCOPE_ME;

        $answer = ReportStartRules::options( $in, get_current_user_id() );
        if ( $answer instanceof StudioError ) return self::refuse( $answer );
        return RestResponse::success( $answer );
    }

    public static function get_report( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $report = ( new ReportsRepository() )->find( (int) $r['id'] );
        if ( $report === null ) return RestResponse::notFound();
        $reader = ReportReader::for( get_current_user_id() );
        if ( ! ReportAccess::canRead( $reader, $report ) ) return self::notShared();

        return RestResponse::success( self::full( $report, $reader ) );
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
        [ $report, $reader ] = $loaded;
        $user_id = $reader->userId;

        $body = self::body( $r );
        if ( ! array_key_exists( 'name', $body ) && ! array_key_exists( 'definition', $body ) ) {
            return RestResponse::error( 'no_writable_field', __( 'Send a name or a definition to change.', 'talenttrack' ), 400, [ 'allowed' => [ 'name', 'definition' ] ] );
        }

        // #4571 — `revision` may also travel in the query string, so an
        // autosaving client's body holds only what the builder edited.
        $query = $r->get_query_params();
        if ( ! array_key_exists( 'revision', $body ) && array_key_exists( 'revision', $query ) ) {
            $body['revision'] = $query['revision'];
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

        $definition = null;
        if ( array_key_exists( 'definition', $body ) ) {
            $definition = ReportDefinition::normalise( $body['definition'], $report['layout'], $report['scope'], $report['audience'], $report['kind'] );
            if ( $definition instanceof StudioError ) return self::refuse( $definition );
        }

        // #4737 — the name is part of the draft, like the definition, and
        // reaches readers when the draft is published. A report never
        // published has no readers, so its own name follows the draft.
        $saved = $repo->saveDraft( $report['id'], $user_id, $definition, $revision ?? $draft['revision'], $name );
        if ( $saved === null ) {
            $current = $repo->draft( $report['id'], $user_id );
            return self::stale( $current !== null ? $current['revision'] : 0 );
        }
        if ( $name !== null && $report['published_version_id'] === null ) $repo->rename( $report['id'], $name );

        $fresh = $repo->find( $report['id'] );
        return RestResponse::success( self::full( $fresh ?? $report, $reader ) );
    }

    public static function archive_report( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $loaded = self::editable( (int) $r['id'] );
        if ( $loaded instanceof WP_REST_Response ) return $loaded;
        [ $report, $reader ] = $loaded;
        $user_id = $reader->userId;

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
        [ $report, $reader ] = $loaded;
        $user_id = $reader->userId;

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

        $revision = null;
        if ( array_key_exists( 'revision', $body ) ) {
            if ( ! is_int( $body['revision'] ) && ! ( is_string( $body['revision'] ) && ctype_digit( $body['revision'] ) ) ) {
                return RestResponse::error( 'invalid_field', __( 'The revision is a whole number.', 'talenttrack' ), 400, [ 'field' => 'revision' ] );
            }
            $revision = (int) $body['revision'];
        }

        $draft = $repo->ensureDraft( $report, $user_id );
        if ( $revision !== null && $revision !== $draft['revision'] ) return self::stale( $draft['revision'] );
        if ( $repo->saveDraft( $report['id'], $user_id, $version['definition'], $revision, $version['name'] ) === null ) {
            $current = $repo->draft( $report['id'], $user_id );
            return self::stale( $current !== null ? $current['revision'] : 0 );
        }

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
        [ $report, $reader ] = $loaded;
        $user_id = $reader->userId;

        $body = self::body( $r );
        $note = is_string( $body['note'] ?? null ) ? trim( sanitize_textarea_field( (string) $body['note'] ) ) : '';
        if ( $note === '' ) {
            return RestResponse::error(
                'missing_fields',
                __( 'Say what changed in this version.', 'talenttrack' ),
                400,
                [ 'field' => 'note', 'fields' => [ 'note' ] ]
            );
        }
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

        if ( ! ReportAccess::canPublish( $reader, $report, $keys ) ) {
            return RestResponse::error( 'forbidden_publish', __( 'You may publish this report for yourself, not for others.', 'talenttrack' ), 403 );
        }
        if ( $keys !== [] && $report['sharing'] !== ReportStartRules::SHARING_ROLE ) {
            return RestResponse::error( 'invalid_field', __( 'This report was made to be shared only with you.', 'talenttrack' ), 400, [ 'field' => 'persona_keys' ] );
        }

        $repo    = new ReportsRepository();
        $draft   = $repo->ensureDraft( $report, $user_id );
        try {
            $version = $repo->publish( $report['id'], $draft['definition'], $note, $keys, $user_id, $draft['name'] );
        } catch ( \RuntimeException $e ) {
            return RestResponse::error( 'db_error', __( 'The report could not be saved.', 'talenttrack' ), 500 );
        }
        if ( $version === null ) {
            return RestResponse::error( 'version_conflict', __( 'Someone published this report at the same moment. Try again.', 'talenttrack' ), 409 );
        }

        return RestResponse::success( [ 'version_no' => $version['version_no'], 'persona_keys' => $keys ], 201 );
    }

    public static function get_data( WP_REST_Request $r ): WP_REST_Response {
        $read = self::read( $r );
        if ( $read instanceof WP_REST_Response ) return $read;

        return RestResponse::success( $read[0] );
    }

    /**
     * #5021 — the report as one document: `GET /reports/{id}/data` read the
     * same way, with the HTML the reader's view, the canvas's A4 view and the
     * PDF all show. The data route stays the contract for any other front
     * end; this is what the plugin's own pages draw.
     */
    public static function get_document( WP_REST_Request $r ): WP_REST_Response {
        $read = self::read( $r );
        if ( $read instanceof WP_REST_Response ) return $read;
        [ $data, $reader, $report ] = $read;

        // #5021 — whether this reader may freeze it for this subject, so a
        // page offers Take snapshot only where it would succeed.
        $context = [];
        foreach ( is_array( $data['context'] ?? null ) ? $data['context'] : [] as $k => $v ) $context[ (string) $k ] = $v;
        $context['can_snapshot'] = ReportSnapshots::canTake( $report, $context, $reader->userId );

        // #4962 — a grid is laid out for a screen, never as pages.
        $grid = ! ReportGrid::printable( $report['layout'] );
        return RestResponse::success( [
            'report_id'  => $data['report_id'],
            'source'     => $data['source'],
            'version_no' => $data['version_no'],
            'layout'     => $report['layout'],
            'context'    => $context,
            'page_fit'   => $grid ? null : $data['page_fit'],
            'html'       => $grid ? StudioGrid::body( $data, $reader->userId ) : StudioDocument::body( $data, $reader->userId ),
        ] );
    }

    /**
     * Every block of a report read for the caller, by the data route's rules.
     *
     * @return array{0:array<string,mixed>, 1:ReportReader, 2:Report}|WP_REST_Response
     */
    private static function read( WP_REST_Request $r ): array|WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $report = ( new ReportsRepository() )->find( (int) $r['id'] );
        if ( $report === null ) return RestResponse::notFound();
        $reader = ReportReader::for( get_current_user_id() );
        if ( ! ReportAccess::canRead( $reader, $report ) ) return self::notShared();

        $source = is_string( $r->get_param( 'source' ) ) ? (string) $r->get_param( 'source' ) : '';
        if ( ! in_array( $source, [ '', 'draft', 'published' ], true ) ) return self::badFilter( 'source' );

        $id_param = static function ( mixed $value ): ?int {
            return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
        };
        $text = static fn( mixed $value ): string => is_string( $value ) ? trim( $value ) : '';

        $audience = $text( $r->get_param( 'audience' ) );
        if ( $audience !== '' && ! PlayerReportAudience::isValid( $audience ) ) return self::badFilter( 'audience' );

        // #4591 — a period pill, computed today; it then counts as asked
        // from / to, so a recipient may send it only when the report leaves
        // the period open.
        $from   = $text( $r->get_param( 'from' ) );
        $to     = $text( $r->get_param( 'to' ) );
        $period = $text( $r->get_param( 'period' ) );
        if ( $period !== '' ) {
            if ( ReportFilters::sanitizePeriod( $period ) === '' ) return self::badFilter( 'period' );
            $window = ReportFilters::periodWindow( $period, (string) current_time( 'Y-m-d' ) );
            if ( $window === null || ( $period === 'this_season' && ReportFilters::seasonDefaultWindow() === null ) ) {
                // A known pill with no window, or this season before it has
                // started, answers as an omitted period does (#4637).
                return RestResponse::error( 'no_period', ReportFilters::noPeriodMessage(), 422, [ 'fields' => [ 'from', 'to' ] ] );
            }
            $from = $window['from'];
            $to   = $window['to'];
        }

        $data = ReportData::compose( $report, [
            'source'    => $source,
            'player_id' => $id_param( $r->get_param( 'player_id' ) ),
            'team_id'   => $id_param( $r->get_param( 'team_id' ) ),
            'from'      => $from,
            'to'        => $to,
            'audience'  => $audience,
        ], $reader );
        if ( $data instanceof StudioError ) return self::refuse( $data );

        return [ $data, $reader, $report ];
    }

    /**
     * #4591 — the subjects this reader may switch the report to, by its
     * binding: none for a fixed subject, the teams or players they read for
     * one chosen when opened, their own teams for one that follows them.
     */
    public static function get_subjects( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $report = ( new ReportsRepository() )->find( (int) $r['id'] );
        if ( $report === null ) return RestResponse::notFound();
        $reader = ReportReader::for( get_current_user_id() );
        if ( ! ReportAccess::canRead( $reader, $report ) ) return self::notShared();

        return RestResponse::success( ReportViewerSubjects::for( $reader, $report ) );
    }

    /**
     * #4571 — how many people publishing to these personas reaches, for the
     * publish confirm: the people who hold one and can read the subject.
     */
    public static function get_reach( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $loaded = self::editable( (int) $r['id'] );
        if ( $loaded instanceof WP_REST_Response ) return $loaded;
        [ $report ] = $loaded;

        $raw  = $r->get_param( 'persona_keys' );
        $raw  = is_string( $raw ) ? explode( ',', $raw ) : ( is_array( $raw ) ? $raw : [] );
        $keys = [];
        foreach ( $raw as $key ) {
            $key = is_string( $key ) ? trim( $key ) : '';
            if ( $key === '' ) continue;
            if ( ! in_array( $key, ReportAccess::PUBLISHABLE_PERSONAS, true ) ) return self::badFilter( 'persona_keys' );
            $keys[ $key ] = $key;
        }
        $keys = array_values( $keys );

        $personas = [];
        foreach ( $keys as $key ) $personas[] = [ 'key' => $key, 'label' => PersonaLabels::label( $key ) ];

        return RestResponse::success( [ 'count' => ReportReach::count( $report, $keys ), 'personas' => $personas ] );
    }

    /**
     * #4571 — the teams and players this builder may build a report about,
     * for the Studio's subject switch. A coach gets their own squads.
     */
    public static function subject_options( WP_REST_Request $r ): WP_REST_Response {
        $blocked = LicenseGate::enforceFeatureRest( 'report_studio' );
        if ( $blocked ) return $blocked;

        $reader  = ReportReader::for( get_current_user_id() );
        $offered = ReportSubjectOptions::teams( $reader );
        $teams   = [];
        foreach ( $offered as $id => $team ) {
            $teams[] = [ 'id' => $id, 'name' => $team['name'], 'age_group' => $team['age_group'] ];
        }
        $players = [];
        foreach ( ReportSubjectOptions::players( $reader, $offered ) as $team_id => $list ) {
            foreach ( $list as $pid => $name ) $players[] = [ 'id' => $pid, 'name' => $name, 'team_id' => $team_id ];
        }
        return RestResponse::success( [ 'teams' => $teams, 'players' => $players ] );
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * The report, when it exists and this user may edit it.
     *
     * @return array{0:Report, 1:ReportReader}|WP_REST_Response
     */
    private static function editable( int $id ): array|WP_REST_Response {
        $report = ( new ReportsRepository() )->find( $id );
        if ( $report === null ) return RestResponse::notFound();
        $reader = ReportReader::for( get_current_user_id() );
        if ( ! ReportAccess::canEdit( $reader, $report ) ) {
            return ReportAccess::canRead( $reader, $report )
                ? RestResponse::error( 'forbidden', __( 'You may read this report, not change it.', 'talenttrack' ), 403 )
                : self::notShared();
        }
        return [ $report, $reader ];
    }

    /**
     * The summary every list row and every single report carries. A
     * recipient does not get the group's cohort rule or the owner's account:
     * they read the report, not how it was built or by whom.
     *
     * @param Report $report
     * @return array<string,mixed>
     */
    private static function payload( array $report, ReportReader $reader ): array {
        $permissions = ReportAccess::permissions( $reader, $report );
        $out = [
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
            'permissions'          => $permissions,
        ];
        if ( ! $permissions['can_edit'] ) {
            unset( $out['subject']['cohort'], $out['owner_user_id'] );
        }
        return $out;
    }

    /**
     * One report, with the builder's own draft and the personas it is
     * published to when the caller builds it.
     *
     * @param Report $report
     * @return array<string,mixed>
     */
    private static function full( array $report, ReportReader $reader ): array {
        $out = self::payload( $report, $reader );
        if ( ReportAccess::canEdit( $reader, $report ) ) {
            $repo  = new ReportsRepository();
            $draft = $repo->draft( $report['id'], $reader->userId );
            // #4737 — `name` is what readers see; the builder's draft keeps
            // its own, and says when it differs from the published one.
            if ( $draft !== null ) {
                $draft['name_changed'] = ReportNaming::changed( $report, $draft['name'] );
            }
            $out['draft']        = $draft;
            // #4903 — only keys that may be published, so the editor never
            // sends back one a stored row still carries before 0318 runs.
            $out['persona_keys'] = array_values( array_intersect( $repo->personaKeys( $report['id'] ), ReportAccess::PUBLISHABLE_PERSONAS ) );
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
