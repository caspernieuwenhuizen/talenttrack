<?php
namespace TT\Modules\MatchAnalysis\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisShareLink;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;

/**
 * MatchAnalysisRestController (#2705) — the REST surface for a match's
 * analysis.
 *
 * Resource-oriented under the activity that owns it, because the analysis
 * has no identity apart from its match:
 *
 *   GET    /activities/<id>/analysis
 *   PUT    /activities/<id>/analysis                     (whole document)
 *   PUT    /activities/<id>/analysis/sections/<key>
 *   PUT    /activities/<id>/analysis/players/<player_id>
 *   DELETE /activities/<id>/analysis/players/<player_id>
 *   POST   /activities/<id>/analysis/share/rotate
 *
 * The whole-document PUT is what the on-screen form submits (one save, one
 * request); the granular routes exist so a non-WordPress front end editing
 * one section does not have to send the whole document back. Both go
 * through `MatchAnalysisWriter`, so they cannot drift.
 *
 * Cap: `tt_edit_activities` to write, `tt_view_activities` to read — the
 * same permissions match prep and match execution use. No new capability:
 * an academy that lets someone plan and run a match lets them write it up.
 */
class MatchAnalysisRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/sections/(?P<section_key>[a-z_]+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_section' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/players/(?P<player_id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_player' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_player' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/share', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_share' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/share/rotate', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'rotate_share' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_activities' );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_activities' );
    }

    // -----------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------

    public static function get( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        if ( $payload === null ) {
            return self::not_a_match();
        }

        return RestResponse::success( self::shape( $payload ) );
    }

    // -----------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------

    public static function put( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );

        $composer = new MatchAnalysisComposer();
        $payload  = $composer->forActivity( $activity_id, true );
        if ( $payload === null ) {
            return self::not_a_match();
        }

        $analysis_id = (int) $payload['analysis_id'];
        if ( $analysis_id <= 0 ) {
            return RestResponse::error( 'db_error', __( 'The analysis could not be created.', 'talenttrack' ), 500 );
        }

        ( new MatchAnalysisWriter() )->apply(
            $analysis_id,
            self::body( $r ),
            self::minutesByPlayer( $payload )
        );

        $fresh = $composer->forActivity( $activity_id, false );

        return RestResponse::success( $fresh !== null ? self::shape( $fresh ) : null );
    }

    public static function put_section( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        $section_key = sanitize_key( (string) $r['section_key'] );

        if ( ! MatchAnalysisEnums::isSectionKey( $section_key ) ) {
            return RestResponse::error(
                'unknown_section',
                __( 'That is not a section of a match analysis.', 'talenttrack' ),
                400
            );
        }

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, true );
        if ( $payload === null ) return self::not_a_match();

        $body = self::body( $r );

        ( new MatchAnalysisWriter() )->saveSection(
            (int) $payload['analysis_id'],
            $section_key,
            $body['rating'] ?? null,
            $body['notes'] ?? ''
        );

        return RestResponse::success( [ 'section_key' => $section_key ] );
    }

    public static function put_player( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        $player_id   = absint( $r['player_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, true );
        if ( $payload === null ) return self::not_a_match();

        $minutes = self::minutesByPlayer( $payload );

        ( new MatchAnalysisWriter() )->savePlayerItem(
            (int) $payload['analysis_id'],
            $player_id,
            self::body( $r ),
            $minutes[ $player_id ] ?? null
        );

        return RestResponse::success( [ 'player_id' => $player_id ] );
    }

    public static function delete_player( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        $player_id   = absint( $r['player_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        if ( $payload === null ) return self::not_a_match();

        if ( (int) $payload['analysis_id'] > 0 ) {
            ( new MatchAnalysisWriter() )->deletePlayerItem( (int) $payload['analysis_id'], $player_id );
        }

        return RestResponse::success( [ 'player_id' => $player_id ] );
    }

    /**
     * Mint the share link. Separate from rendering the surface on purpose
     * (#2749): opening an analysis used to write a seed as a side effect,
     * so every analysis anyone merely looked at ended up with a live,
     * working URL nobody had asked for. Sharing is a decision.
     *
     * Idempotent — calling it twice returns the same link rather than
     * quietly invalidating the one already handed out. Replacing a link is
     * what `share/rotate` is for, and it says so in the UI.
     */
    public static function create_share( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, true );
        if ( $payload === null ) return self::not_a_match();

        $analysis_id = (int) $payload['analysis_id'];

        return RestResponse::success( [
            'share_url' => MatchAnalysisShareLink::urlFor( $analysis_id ),
        ] );
    }

    public static function rotate_share( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, true );
        if ( $payload === null ) return self::not_a_match();

        $analysis_id = (int) $payload['analysis_id'];
        ( new MatchAnalysisRepository() )->rotateShareTokenSeed( $analysis_id );

        return RestResponse::success( [
            'share_url' => MatchAnalysisShareLink::urlFor( $analysis_id ),
        ] );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The decoded request body. Falls back to form-encoded params so a
     * plain HTML form POST reaches the same route as a JSON client.
     *
     * @return array<string,mixed>
     */
    private static function body( \WP_REST_Request $r ): array {
        $body = $r->get_json_params();
        if ( is_array( $body ) ) return $body;

        return (array) $r->get_body_params();
    }

    /**
     * player id => minutes, as the composer resolved them. Snapshotted onto
     * the item so a printed or shared analysis still says how long a player
     * was on the pitch after the attendance row has moved on.
     *
     * @param array<string,mixed> $payload
     * @return array<int,?int>
     */
    private static function minutesByPlayer( array $payload ): array {
        $out = [];
        foreach ( (array) $payload['players'] as $row ) {
            $out[ (int) $row['player_id'] ] = $row['minutes'] !== null ? (int) $row['minutes'] : null;
        }
        return $out;
    }

    /**
     * The wire shape. Deliberately not the raw rows: sections carry their
     * resolved label and players are a flat list, so a consumer does not
     * have to know the methodology taxonomy to render the document.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function shape( array $payload ): array {
        $activity = $payload['activity'];

        return [
            'activity_id' => (int) $activity->id,
            'analysis_id' => (int) $payload['analysis_id'],
            'exists'      => (int) $payload['analysis_id'] > 0,
            'status'      => (string) $payload['status'],
            'summary'     => (string) $payload['summary'],
            'match'       => [
                'title'     => (string) ( $activity->title ?? '' ),
                'date'      => (string) ( $activity->session_date ?? '' ),
                'opponent'  => (string) ( $activity->opponent ?? '' ),
                'home_away' => (string) ( $activity->home_away ?? '' ),
                'team_id'   => (int) ( $activity->team_id ?? 0 ),
            ],
            'result'      => $payload['result'],
            'sections'    => array_values( $payload['sections'] ),
            'players'     => $payload['players'],
            'sources'     => [
                'match_prep'      => (bool) $payload['has_prep'],
                'match_execution' => (bool) $payload['has_exec'],
            ],
        ];
    }

    private static function not_a_match(): \WP_REST_Response {
        return RestResponse::error(
            'not_a_match',
            __( 'A match analysis can only be written for a match activity.', 'talenttrack' ),
            400
        );
    }
}
