<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use WP_REST_Request;
use WP_REST_Response;

/**
 * EvaluationsRestController — /wp-json/talenttrack/v1/evaluations
 *
 * All responses use the standard envelope {success, data, errors}.
 */
class EvaluationsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/evaluations', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_evals' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_eval' ],
                'permission_callback' => self::permCan( 'tt_evaluate_players' ),
            ],
        ]);

        register_rest_route( self::NS, '/evaluations/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_eval' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_eval' ],
                'permission_callback' => self::permCan( 'tt_evaluate_players' ),
            ],
        ]);
    }

    public static function list_evals( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $request['player_id'] !== null && $request['player_id'] !== '' ) {
            $player_id = absint( $request['player_id'] );
            if ( ! $player_id ) {
                return RestResponse::error( 'invalid_player_id', 'player_id must be a positive integer.', 400 );
            }
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}tt_evaluations WHERE player_id=%d ORDER BY eval_date DESC LIMIT 100",
                $player_id
            ));
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$p}tt_evaluations ORDER BY eval_date DESC LIMIT 100"
            );
        }

        $data = array_map( function ( $e ) { return self::fmtSummary( $e ); }, $rows );
        return RestResponse::success( $data );
    }

    public static function get_eval( WP_REST_Request $request ): WP_REST_Response {
        $id = absint( $request['id'] );
        if ( ! $id ) {
            return RestResponse::error( 'invalid_id', 'Evaluation ID must be a positive integer.', 400 );
        }
        $e = QueryHelpers::get_evaluation( $id );
        if ( ! $e ) {
            return RestResponse::error( 'evaluation_not_found', 'Evaluation not found.', 404, [ 'id' => $id ] );
        }
        return RestResponse::success( self::fmtDetail( $e ) );
    }

    public static function create_eval( WP_REST_Request $request ): WP_REST_Response {
        $validation = self::requireFields( $request, [ 'player_id', 'eval_type_id', 'eval_date' ] );
        if ( ! empty( $validation ) ) {
            return RestResponse::errors( $validation, 422 );
        }

        $player_id    = absint( $request['player_id'] );
        $eval_type_id = absint( $request['eval_type_id'] );

        if ( ! $player_id || ! QueryHelpers::get_player( $player_id ) ) {
            return RestResponse::error( 'player_not_found', 'Referenced player does not exist.', 422, [ 'player_id' => $player_id ] );
        }
        if ( ! $eval_type_id || ! QueryHelpers::get_lookup( $eval_type_id ) ) {
            return RestResponse::error( 'eval_type_not_found', 'Referenced evaluation type does not exist.', 422, [ 'eval_type_id' => $eval_type_id ] );
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $header = [
            'player_id'      => $player_id,
            'coach_id'       => get_current_user_id(),
            'eval_type_id'   => $eval_type_id,
            'eval_date'      => sanitize_text_field( (string) ( $request['eval_date'] ?? current_time( 'Y-m-d' ) ) ),
            'notes'          => sanitize_textarea_field( (string) ( $request['notes'] ?? '' ) ),
            'opponent'       => sanitize_text_field( (string) ( $request['opponent'] ?? '' ) ),
            'competition'    => sanitize_text_field( (string) ( $request['competition'] ?? '' ) ),
            'match_result'   => sanitize_text_field( (string) ( $request['match_result'] ?? '' ) ),
            'home_away'      => sanitize_text_field( (string) ( $request['home_away'] ?? '' ) ),
            'minutes_played' => ! empty( $request['minutes_played'] ) ? absint( $request['minutes_played'] ) : null,
        ];

        do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );
        $wpdb->insert( "{$p}tt_evaluations", $header );
        $id = (int) $wpdb->insert_id;

        if ( ! $id ) {
            return RestResponse::error( 'create_failed', 'Could not create evaluation.', 500 );
        }

        // Ratings
        $rating_errors = [];
        $ratings = is_array( $request['ratings'] ?? null ) ? (array) $request['ratings'] : [];
        foreach ( $ratings as $cid => $val ) {
            $cid_int = absint( $cid );
            $val_f   = (float) $val;
            if ( ! $cid_int ) {
                $rating_errors[] = [ 'code' => 'invalid_category_id', 'message' => 'Rating category ID invalid.', 'details' => [ 'category_id' => $cid ] ];
                continue;
            }
            $wpdb->insert( "{$p}tt_eval_ratings", [
                'evaluation_id' => $id,
                'category_id'   => $cid_int,
                'rating'        => $val_f,
            ]);
        }

        if ( ! empty( $rating_errors ) ) {
            // Evaluation was created but some ratings had issues — return 207-style partial: success envelope with details.
            return RestResponse::success( [
                'id'             => $id,
                'warning'        => 'Evaluation created with rating issues.',
                'rating_errors'  => $rating_errors,
            ], 201 );
        }

        $full = QueryHelpers::get_evaluation( $id );
        return RestResponse::success( $full ? self::fmtDetail( $full ) : [ 'id' => $id ], 201 );
    }

    public static function delete_eval( WP_REST_Request $request ): WP_REST_Response {
        $id = absint( $request['id'] );
        if ( ! $id ) {
            return RestResponse::error( 'invalid_id', 'Evaluation ID must be a positive integer.', 400 );
        }
        if ( ! QueryHelpers::get_evaluation( $id ) ) {
            return RestResponse::error( 'evaluation_not_found', 'Evaluation not found.', 404, [ 'id' => $id ] );
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id ] );
        $wpdb->delete( "{$p}tt_evaluations", [ 'id' => $id ] );

        return RestResponse::success( [ 'id' => $id, 'deleted' => true ] );
    }

    /** @return array<string,mixed> */
    private static function fmtSummary( object $e ): array {
        return [
            'id'             => (int) $e->id,
            'player_id'      => (int) $e->player_id,
            'coach_id'       => (int) $e->coach_id,
            'eval_type_id'   => (int) $e->eval_type_id,
            'eval_date'      => (string) $e->eval_date,
            'opponent'       => (string) ( $e->opponent ?? '' ),
            'competition'    => (string) ( $e->competition ?? '' ),
            'match_result'   => (string) ( $e->match_result ?? '' ),
            'home_away'      => (string) ( $e->home_away ?? '' ),
            'minutes_played' => $e->minutes_played !== null ? (int) $e->minutes_played : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function fmtDetail( object $e ): array {
        $out = self::fmtSummary( $e );
        $out['type_name'] = (string) ( $e->type_name ?? '' );
        $out['notes']     = (string) ( $e->notes ?? '' );
        $out['ratings']   = [];
        if ( ! empty( $e->ratings ) && is_array( $e->ratings ) ) {
            foreach ( $e->ratings as $r ) {
                $out['ratings'][] = [
                    'category_id'   => (int) $r->category_id,
                    'category_name' => (string) ( $r->category_name ?? '' ),
                    'rating'        => (float) $r->rating,
                ];
            }
        }
        return $out;
    }
}
