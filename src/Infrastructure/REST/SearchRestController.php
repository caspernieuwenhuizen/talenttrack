<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\RecentItems;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchRegistry;
use TT\Infrastructure\Search\SearchService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * SearchRestController (#2458, #4671) — cross-entity search, and the recent
 * items beside it.
 *
 *   GET  /search      without `page`: the header box's `{ results }`, as it
 *                     always was. With `page`: `{ items, counts, understood,
 *                     total }` over every kind.
 *   GET  /me/recent   the records the caller opened last, re-checked.
 *   POST /me/recent   note that the caller opened one.
 *
 * ## Privacy is the whole design
 *
 * These are minors (CLAUDE.md §1). A search box is the easiest place in a
 * product to leak a record someone may not see. Each kind's provider
 * (`Infrastructure\Search\Providers`) narrows in SQL by the record's own
 * access rule before the `LIMIT`, then confirms every hit with the check the
 * record's screens ask; counts are taken over the same scope. A match the
 * caller may not open and no match at all give the same answer. This
 * controller only reads the request and composes the response
 * (`SearchService`); no rule lives here.
 *
 * Any logged-in user may search; what they get back is scoped per kind.
 */
final class SearchRestController extends BaseController {

    /** The largest page. */
    public const MAX_PER_PAGE = 50;

    /** The deepest page: search is for finding, not for paging the academy. */
    public const MAX_PAGE = 20;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( self::NS, '/search', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'search' ],
            'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            'args'                => [
                'q'        => [ 'type' => 'string', 'required' => false, 'description' => 'What was typed. Records need 2 characters, sections and settings 1. Recognised patterns: #<n> (shirt number), O<nn>/MO<nn> (age group), dd-mm or a day and month name (a date this season), yyyy-mm-dd or dd-mm-yyyy, a weekday.' ],
                'types'    => [ 'type' => 'string', 'required' => false, 'description' => 'Comma-separated kinds: view, player, team, person, activity, evaluation, goal, message, player_note, setting, help. All when omitted (without page: view, player, team, activity). An unknown kind is refused with 400 bad_filter when page is set.' ],
                'team_id'  => [ 'type' => 'string', 'required' => false, 'description' => 'Only records of this team. With page only; anything but a positive id is refused with 400 bad_filter.' ],
                'from'     => [ 'type' => 'string', 'required' => false, 'description' => 'Activities on or after this day (Y-m-d). With page only; 400 bad_date otherwise.' ],
                'to'       => [ 'type' => 'string', 'required' => false, 'description' => 'Activities on or before this day (Y-m-d). With page only; 400 bad_date otherwise.' ],
                'page'     => [ 'type' => 'string', 'required' => false, 'description' => 'Selects the paged response. 1 to ' . self::MAX_PAGE . '.' ],
                'per_page' => [ 'type' => 'string', 'required' => false, 'description' => 'Items per page with page, 1 to ' . self::MAX_PER_PAGE . '; 20 when omitted.' ],
            ],
        ] );

        register_rest_route( self::NS, '/me/recent', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'recent' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'note_recent' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
                'args'                => self::recentArgs(),
            ],
        ] );
    }

    /** @return array<string, array<string, mixed>> */
    private static function recentArgs(): array {
        return [
            'type' => [ 'type' => 'string', 'required' => true, 'description' => 'A record kind: player, team, person or activity.' ],
            'id'   => [ 'type' => 'integer', 'required' => true, 'description' => 'The record id.' ],
        ];
    }

    public static function search( WP_REST_Request $request ): WP_REST_Response {
        $user_id = get_current_user_id();
        $raw     = trim( (string) $request->get_param( 'q' ) );
        $types   = self::typesOf( (string) $request->get_param( 'types' ) );

        if ( $request->get_param( 'page' ) === null ) {
            $legacy = array_values( array_intersect( $types, SearchService::LEGACY_KINDS ) );
            if ( $types !== [] && $legacy === [] ) return new WP_REST_Response( [ 'results' => [] ], 200 );
            return new WP_REST_Response( [ 'results' => ( new SearchService() )->headerResults( $raw, $legacy, $user_id ) ], 200 );
        }

        $unknown = array_values( array_diff( $types, SearchRegistry::kinds() ) );
        if ( $unknown !== [] ) {
            return RestResponse::error( 'bad_filter', __( 'That filter value cannot be read.', 'talenttrack' ), 400, [ 'parameter' => 'types', 'allowed' => SearchRegistry::kinds() ] );
        }

        $page = self::positiveInt( $request->get_param( 'page' ) );
        if ( $page === null || $page > self::MAX_PAGE ) {
            return RestResponse::error( 'bad_filter', __( 'That filter value cannot be read.', 'talenttrack' ), 400, [ 'parameter' => 'page' ] );
        }
        $per_page = $request->get_param( 'per_page' ) === null ? 20 : self::positiveInt( $request->get_param( 'per_page' ) );
        if ( $per_page === null || $per_page > self::MAX_PER_PAGE ) {
            return RestResponse::error( 'bad_filter', __( 'That filter value cannot be read.', 'talenttrack' ), 400, [ 'parameter' => 'per_page' ] );
        }

        $query = new SearchQuery( $raw );
        if ( $request->get_param( 'team_id' ) !== null ) {
            $team_id = self::positiveInt( $request->get_param( 'team_id' ) );
            if ( $team_id === null ) {
                return RestResponse::error( 'bad_filter', __( 'That filter value cannot be read.', 'talenttrack' ), 400, [ 'parameter' => 'team_id' ] );
            }
            $query->team_id = $team_id;
        }
        foreach ( [ 'from', 'to' ] as $key ) {
            $value = $request->get_param( $key );
            if ( $value === null ) continue;
            $value = trim( (string) $value );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || ! checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ) ) {
                return RestResponse::error( 'bad_date', __( 'Dates must be written as YYYY-MM-DD.', 'talenttrack' ), 400, [ 'param' => $key ] );
            }
            $query->{$key} = $value;
        }

        return RestResponse::success( ( new SearchService() )->page( $query, $types, $page, $per_page, $user_id ) );
    }

    public static function recent( WP_REST_Request $request ): WP_REST_Response {
        return RestResponse::success( [ 'items' => ( new RecentItems() )->forUser( get_current_user_id() ) ] );
    }

    public static function note_recent( WP_REST_Request $request ): WP_REST_Response {
        $refused = self::checkBody( $request, self::recentArgs() );
        if ( $refused !== null ) return $refused;

        $type = sanitize_key( (string) $request->get_param( 'type' ) );
        $provider = SearchRegistry::get( $type );
        if ( $provider === null || ! $provider->isRecord() ) {
            return RestResponse::error( 'invalid_field', __( 'That kind of record cannot be kept as recent.', 'talenttrack' ), 400, [ 'field' => 'type' ] );
        }
        $id = self::positiveInt( $request->get_param( 'id' ) );
        if ( $id === null ) {
            return RestResponse::error( 'invalid_field', __( 'That record id cannot be read.', 'talenttrack' ), 400, [ 'field' => 'id' ] );
        }
        // A record the caller may not open answers exactly as a missing one.
        if ( ! ( new RecentItems() )->record( get_current_user_id(), $type, $id ) ) {
            return RestResponse::error( 'not_found', __( 'Not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'type' => $type, 'id' => $id ], 201 );
    }

    /** @return list<string> */
    private static function typesOf( string $raw ): array {
        if ( trim( $raw ) === '' ) return [];
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) ) ) );
    }

    /** @param mixed $value */
    private static function positiveInt( $value ): ?int {
        if ( is_int( $value ) ) return $value > 0 ? $value : null;
        if ( ! is_string( $value ) || ! preg_match( '/^\d+$/', trim( $value ) ) ) return null;
        $n = (int) trim( $value );
        return $n > 0 ? $n : null;
    }
}
