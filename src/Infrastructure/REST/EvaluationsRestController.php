<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * EvaluationsRestController — /wp-json/talenttrack/v1/evaluations
 *
 * v2.6.2: insert return values are checked. Failures return HTTP 500 with
 * the DB error message and are logged via Logger.
 */
class EvaluationsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/evaluations', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_evals' ],  'permission_callback' => function () { return is_user_logged_in(); } ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_evaluate_players' ); } ],
        ]);
        register_rest_route( self::NS, '/evaluations/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_eval' ],    'permission_callback' => function () { return is_user_logged_in(); } ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_evaluate_players' ); } ],
        ]);
    }

    public static function list_evals( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        if ( $r['player_id'] ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}tt_evaluations WHERE player_id=%d ORDER BY eval_date DESC LIMIT 100", absint( $r['player_id'] ) ) );
        } else {
            $rows = $wpdb->get_results( "SELECT * FROM {$p}tt_evaluations ORDER BY eval_date DESC LIMIT 100" );
        }
        return RestResponse::success( array_map( function ( $e ) { return (array) $e; }, $rows ) );
    }

    public static function get_eval( \WP_REST_Request $r ) {
        $e = QueryHelpers::get_evaluation( (int) $r['id'] );
        if ( ! $e ) return RestResponse::notFound();
        return RestResponse::success( (array) $e );
    }

    public static function create_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $header = [
            'player_id'    => absint( $r['player_id'] ?? 0 ),
            'coach_id'     => get_current_user_id(),
            'eval_type_id' => absint( $r['eval_type_id'] ?? 0 ),
            'eval_date'    => sanitize_text_field( (string) ( $r['eval_date'] ?? current_time( 'Y-m-d' ) ) ),
            'notes'        => sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) ),
        ];
        do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );

        $ok = $wpdb->insert( "{$p}tt_evaluations", $header );
        if ( $ok === false ) {
            Logger::error( 'rest.evaluation.create.failed', [ 'db_error' => (string) $wpdb->last_error, 'payload' => $header ] );
            return RestResponse::errors( [
                [ 'code' => 'db_error', 'message' => __( 'The evaluation could not be created.', 'talenttrack' ), 'details' => [ 'db_error' => (string) $wpdb->last_error ] ],
            ], 500 );
        }
        $id = (int) $wpdb->insert_id;

        $rating_failures = [];
        foreach ( (array) ( $r['ratings'] ?? [] ) as $cid => $val ) {
            $ok_rating = $wpdb->insert( "{$p}tt_eval_ratings", [
                'evaluation_id' => $id, 'category_id' => absint( $cid ), 'rating' => floatval( $val ),
            ]);
            if ( $ok_rating === false ) {
                $rating_failures[] = [ 'category_id' => absint( $cid ), 'db_error' => (string) $wpdb->last_error ];
            }
        }
        if ( ! empty( $rating_failures ) ) {
            Logger::error( 'rest.evaluation.ratings.failed', [ 'evaluation_id' => $id, 'failures' => $rating_failures ] );
            return RestResponse::errors( [
                [ 'code' => 'partial_save', 'message' => __( 'The evaluation was saved but some ratings failed.', 'talenttrack' ), 'details' => [ 'evaluation_id' => $id, 'failures' => $rating_failures ] ],
            ], 500 );
        }

        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $id = (int) $r['id'];
        $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id ] );
        $ok = $wpdb->delete( "{$p}tt_evaluations", [ 'id' => $id ] );
        if ( $ok === false ) {
            Logger::error( 'rest.evaluation.delete.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::errors( [
                [ 'code' => 'db_error', 'message' => __( 'The evaluation could not be deleted.', 'talenttrack' ) ],
            ], 500 );
        }
        return RestResponse::success( [ 'deleted' => true ] );
    }
}
