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
 *
 * #0019 Sprint 1 session 2: create_eval now carries the full legacy
 * payload (opponent, competition, game_result, home_away, minutes_played)
 * and enforces the coach-owns-player check that FrontendAjax ran. An
 * update endpoint was added so the future edit-evaluation view has an
 * API to hit.
 */
class EvaluationsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/evaluations', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_evals' ],  'permission_callback' => function () { return is_user_logged_in(); } ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_edit_evaluations' ); } ],
        ]);
        register_rest_route( self::NS, '/evaluations/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_eval' ],    'permission_callback' => function () { return is_user_logged_in(); } ],
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_edit_evaluations' ); } ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_edit_evaluations' ); } ],
        ]);
    }

    public static function list_evals( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );
        if ( $r['player_id'] ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.* FROM {$p}tt_evaluations e WHERE e.player_id=%d {$scope} ORDER BY e.eval_date DESC LIMIT 100", absint( $r['player_id'] ) ) );
        } else {
            $rows = $wpdb->get_results( "SELECT e.* FROM {$p}tt_evaluations e WHERE 1=1 {$scope} ORDER BY e.eval_date DESC LIMIT 100" );
        }
        return RestResponse::success( array_map( function ( $e ) { return (array) $e; }, $rows ) );
    }

    public static function get_eval( \WP_REST_Request $r ) {
        $e = QueryHelpers::get_evaluation( (int) $r['id'] );
        if ( ! $e ) return RestResponse::error( 'not_found', __( 'Evaluation not found.', 'talenttrack' ), 404 );
        return RestResponse::success( (array) $e );
    }

    public static function create_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $header = self::extract( $r );
        $header['coach_id'] = get_current_user_id();

        if ( $header['player_id'] <= 0 || $header['eval_date'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Player and date are required.', 'talenttrack' ), 400 );
        }
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            if ( ! QueryHelpers::coach_owns_player( get_current_user_id(), (int) $header['player_id'] ) ) {
                return RestResponse::error( 'forbidden_player', __( 'You can only evaluate players in your team.', 'talenttrack' ), 403 );
            }
        }

        do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );

        $ok = $wpdb->insert( "{$p}tt_evaluations", $header );
        if ( $ok === false ) {
            Logger::error( 'rest.evaluation.create.failed', [ 'db_error' => (string) $wpdb->last_error, 'payload' => $header ] );
            return RestResponse::error(
                'db_error',
                __( 'The evaluation could not be created.', 'talenttrack' ),
                500,
                [ 'db_error' => (string) $wpdb->last_error ]
            );
        }
        $id = (int) $wpdb->insert_id;

        $rating_failures = self::write_ratings( $id, (array) ( $r['ratings'] ?? [] ) );
        if ( $rating_failures ) {
            Logger::error( 'rest.evaluation.ratings.failed', [ 'evaluation_id' => $id, 'failures' => $rating_failures ] );
            return RestResponse::error(
                'partial_save',
                __( 'The evaluation was saved but some ratings failed.', 'talenttrack' ),
                500,
                [ 'evaluation_id' => $id, 'failures' => $rating_failures ]
            );
        }

        // #0018 — let downstream listeners (e.g. CompatibilityEngine
        // cache invalidation) know the player's evaluation surface
        // changed.
        do_action( 'tt_evaluation_saved', (int) $header['player_id'], $id );

        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function update_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid evaluation id.', 'talenttrack' ), 400 );
        }

        $header = self::extract( $r );
        unset( $header['coach_id'] ); // preserve original coach
        $ok = $wpdb->update( "{$p}tt_evaluations", $header, [ 'id' => $id ] );
        if ( $ok === false ) {
            Logger::error( 'rest.evaluation.update.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error(
                'db_error',
                __( 'The evaluation could not be updated.', 'talenttrack' ),
                500,
                [ 'db_error' => (string) $wpdb->last_error ]
            );
        }

        if ( isset( $r['ratings'] ) ) {
            $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id ] );
            $rating_failures = self::write_ratings( $id, (array) $r['ratings'] );
            if ( $rating_failures ) {
                Logger::error( 'rest.evaluation.ratings.update.failed', [ 'evaluation_id' => $id, 'failures' => $rating_failures ] );
                return RestResponse::error(
                    'partial_save',
                    __( 'The evaluation was updated but some ratings failed.', 'talenttrack' ),
                    500,
                    [ 'evaluation_id' => $id, 'failures' => $rating_failures ]
                );
            }
        }

        // #0018 — same hook as create_eval; let cache invalidators
        // know the player's evaluation surface changed.
        if ( ! empty( $header['player_id'] ) ) {
            do_action( 'tt_evaluation_saved', (int) $header['player_id'], $id );
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
            return RestResponse::error(
                'db_error',
                __( 'The evaluation could not be deleted.', 'talenttrack' ),
                500
            );
        }
        return RestResponse::success( [ 'deleted' => true ] );
    }

    /**
     * Extract the evaluation header columns from a REST request. Matches
     * the legacy FrontendAjax payload so the existing form submits in
     * the same shape.
     *
     * @return array<string, mixed>
     */
    private static function extract( \WP_REST_Request $r ): array {
        return [
            'player_id'      => absint( $r['player_id'] ?? 0 ),
            'coach_id'       => get_current_user_id(),
            'eval_type_id'   => absint( $r['eval_type_id'] ?? 0 ),
            'eval_date'      => sanitize_text_field( (string) ( $r['eval_date'] ?? current_time( 'Y-m-d' ) ) ),
            'notes'          => sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) ),
            'opponent'       => sanitize_text_field( (string) ( $r['opponent'] ?? '' ) ),
            'competition'    => sanitize_text_field( (string) ( $r['competition'] ?? '' ) ),
            'game_result'   => sanitize_text_field( (string) ( $r['game_result'] ?? '' ) ),
            'home_away'      => sanitize_text_field( (string) ( $r['home_away'] ?? '' ) ),
            'minutes_played' => ! empty( $r['minutes_played'] ) ? absint( $r['minutes_played'] ) : null,
        ];
    }

    /**
     * @param array<int|string, mixed> $ratings category_id => rating
     * @return array<int, array{category_id:int, db_error:string}>
     */
    private static function write_ratings( int $evaluation_id, array $ratings ): array {
        global $wpdb; $p = $wpdb->prefix;
        $rmin = (float) QueryHelpers::get_config( 'rating_min', '1' );
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $failures = [];
        foreach ( $ratings as $cid => $val ) {
            $clamped = max( $rmin, min( $rmax, (float) $val ) );
            $ok = $wpdb->insert( "{$p}tt_eval_ratings", [
                'evaluation_id' => $evaluation_id,
                'category_id'   => absint( $cid ),
                'rating'        => $clamped,
            ] );
            if ( $ok === false ) {
                $failures[] = [ 'category_id' => absint( $cid ), 'db_error' => (string) $wpdb->last_error ];
            }
        }
        return $failures;
    }
}
