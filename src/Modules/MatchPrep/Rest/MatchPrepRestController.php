<?php
namespace TT\Modules\MatchPrep\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchPrepRestController (#838) — REST surface for the match-prep form.
 *
 * One endpoint, PUT /match-prep/<activity_id>, takes the entire form
 * payload (formation, half_length, goals, lineup, player_goals) in one
 * shot. Idempotent — each sub-set replaces the previous state for the
 * prep. Availability is owned by the wizard step's submit; this
 * endpoint touches everything else.
 *
 * Cap = tt_edit_activities (existing).
 */
class MatchPrepRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/match-prep/(?P<activity_id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_activities' );
    }

    public static function put( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        if ( $activity_id <= 0 ) {
            return RestResponse::error( 'bad_activity', __( 'Invalid activity id.', 'talenttrack' ), 400 );
        }

        $repo = new MatchPrepRepository();
        $prep_id = $repo->ensureForActivity( $activity_id );
        if ( $prep_id <= 0 ) {
            return RestResponse::error( 'db_error', __( 'Match prep could not be created.', 'talenttrack' ), 500 );
        }

        $body = $r->get_json_params();
        if ( ! is_array( $body ) ) $body = [];

        // Header fields (formation, half length, goals).
        $patch = [];
        if ( array_key_exists( 'formation_template_id', $body ) ) {
            $patch['formation_template_id'] = (int) $body['formation_template_id'] ?: null;
        }
        if ( array_key_exists( 'half_length_minutes', $body ) ) {
            $hl = (int) $body['half_length_minutes'];
            $patch['half_length_minutes'] = $hl > 0 ? min( 120, $hl ) : 35;
        }
        foreach ( [ 'goals_general', 'goals_attack', 'goals_defend', 'goals_attack_setpiece', 'goals_defend_setpiece' ] as $col ) {
            if ( array_key_exists( $col, $body ) ) {
                $patch[ $col ] = sanitize_textarea_field( (string) $body[ $col ] );
            }
        }
        if ( $patch ) $repo->updatePrep( $prep_id, $patch );

        // Lineup — accept per-half map { 1: { slot: player_id, ... }, 2: { ... } }.
        if ( isset( $body['lineup'] ) && is_array( $body['lineup'] ) ) {
            foreach ( [ 1, 2 ] as $half ) {
                $half_map = $body['lineup'][ $half ] ?? $body['lineup'][ (string) $half ] ?? null;
                if ( is_array( $half_map ) ) {
                    $slots = [];
                    foreach ( $half_map as $slot => $pid ) {
                        $slots[ (int) $slot ] = (int) $pid;
                    }
                    // Validate full XI when caller asks for it via a
                    // distinct flag; the partial save (in-progress edit)
                    // is allowed so the operator doesn't lose work.
                    $repo->replaceLineupForHalf( $prep_id, $half, $slots );
                }
            }
        }

        // Player goals (attention text + flags). Keyed by player_id.
        if ( isset( $body['player_goals'] ) && is_array( $body['player_goals'] ) ) {
            $rows = [];
            foreach ( $body['player_goals'] as $pid => $entry ) {
                if ( ! is_array( $entry ) ) continue;
                $rows[ (int) $pid ] = [
                    'attention_text'    => sanitize_textarea_field( (string) ( $entry['attention_text'] ?? '' ) ),
                    'is_specific_goal'  => ! empty( $entry['is_specific_goal'] ),
                    'analyst_appointed' => ! empty( $entry['analyst_appointed'] ),
                ];
            }
            $repo->replacePlayerGoals( $prep_id, $rows );
        }

        // Availability (re-edit from the form's [Manage availability] modal).
        if ( isset( $body['availability'] ) && is_array( $body['availability'] ) ) {
            $rows = [];
            foreach ( $body['availability'] as $pid => $entry ) {
                if ( ! is_array( $entry ) ) continue;
                $rows[ (int) $pid ] = [
                    'status' => sanitize_text_field( (string) ( $entry['status'] ?? 'Present' ) ),
                    'reason' => sanitize_text_field( (string) ( $entry['reason'] ?? '' ) ),
                ];
            }
            $repo->replaceAvailability( $prep_id, $rows );
        }

        Logger::info( 'match_prep.save', [ 'activity_id' => $activity_id, 'prep_id' => $prep_id ] );

        return RestResponse::success( [ 'prep_id' => $prep_id, 'activity_id' => $activity_id ] );
    }
}
