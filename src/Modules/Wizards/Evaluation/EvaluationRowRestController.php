<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * EvaluationRowRestController (#0072 follow-up) — POST one
 * evaluation row at a time so the new-evaluation wizard's Review step
 * can drive a per-player progress bar from JS.
 *
 * Endpoint:
 *   POST /talenttrack/v1/wizards/new-evaluation/insert-row
 *   Body: {
 *     activity_id?: int (omit for player-first ad-hoc),
 *     player_id:   int,
 *     eval_date?:  YYYY-MM-DD (defaults: activity.session_date or today),
 *     ratings:     { cat_id: int },
 *     notes?:      string,
 *   }
 *   Returns: { evaluation_id: int }
 *
 * Cap: tt_edit_evaluations — the same gate `EvaluationsRestController::
 * create_eval` uses. v3.92.4 fix: was `tt_create_evaluations` which is
 * not granted to any TT role (only used by `ActionCardWidget` for tile
 * visibility), so every save through the JS overlay 403'd for non-admin
 * coaches. Single-player save reported the failure loudly because the
 * progress bar can't hide a 100%-fail set; multi-player saves had the
 * same bug but the failure ratio looked like "some rows failed".
 */
final class EvaluationRowRestController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route(
            'talenttrack/v1',
            '/wizards/new-evaluation/insert-row',
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'insert' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && current_user_can( 'tt_edit_evaluations' ),
            ]
        );
    }

    public static function insert( WP_REST_Request $req ): WP_REST_Response {
        $body = $req->get_json_params();
        if ( ! is_array( $body ) ) $body = [];

        $row = [
            'activity_id' => isset( $body['activity_id'] ) ? (int) $body['activity_id'] : 0,
            'player_id'   => isset( $body['player_id'] )   ? (int) $body['player_id']   : 0,
            'eval_date'   => sanitize_text_field( (string) ( $body['eval_date'] ?? '' ) ),
            'notes'       => sanitize_textarea_field( wp_unslash( (string) ( $body['notes'] ?? '' ) ) ),
            'ratings'     => is_array( $body['ratings'] ?? null ) ? array_map( 'intval', (array) $body['ratings'] ) : [],
        ];

        if ( $row['eval_date'] === '' ) {
            $row['eval_date'] = current_time( 'Y-m-d' );
        }

        $result = EvaluationInserter::insert( $row );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ], 400 );
        }
        return new WP_REST_Response( [ 'evaluation_id' => $result ], 201 );
    }
}
