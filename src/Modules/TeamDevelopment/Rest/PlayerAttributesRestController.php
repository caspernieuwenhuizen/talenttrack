<?php
namespace TT\Modules\TeamDevelopment\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\TeamDevelopment\Chemistry\ChemistryProfileLoader;
use TT\Modules\TeamDevelopment\Chemistry\PairChemistryEngine;
use TT\Modules\TeamDevelopment\Repositories\ChemistryConfig;
use TT\Modules\TeamDevelopment\Repositories\ChemistryPositionMatrixRepository;
use TT\Modules\TeamDevelopment\Repositories\PlayerAttributesRepository;

/**
 * PlayerAttributesRestController (#1912) — the SaaS contract for the
 * chemistry schema foundation (CLAUDE.md §4), even though the editor UI
 * (Phase 7) ships separately.
 *
 *   GET/PUT /players/{id}/attributes        — a player's attribute set
 *   GET/PUT /chemistry/position-matrix      — the Position Relationship Matrix
 *   GET/PUT /chemistry/config               — the 5 component weights
 *
 * Matrix-gated, no role-string compare: player-attribute reads/writes
 * resolve through canViewPlayer / canEvaluatePlayer (player development
 * data, same as evaluations); the matrix + weights are admin config gated
 * on the `team_chemistry` entity at global scope.
 */
class PlayerAttributesRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<player_id>\d+)/attributes', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_attributes' ],
                'permission_callback' => [ __CLASS__, 'can_view_player' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_attributes' ],
                'permission_callback' => [ __CLASS__, 'can_edit_player' ],
            ],
        ]);

        register_rest_route( self::NS, '/chemistry/position-matrix', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_matrix' ],
                'permission_callback' => [ __CLASS__, 'can_read_config' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_matrix' ],
                'permission_callback' => [ __CLASS__, 'can_change_config' ],
            ],
        ]);

        register_rest_route( self::NS, '/chemistry/config', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_config' ],
                'permission_callback' => [ __CLASS__, 'can_read_config' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_config' ],
                'permission_callback' => [ __CLASS__, 'can_change_config' ],
            ],
        ]);

        // #1017 Phase 3 — the reworked pair engine, exposed for testing /
        // the upcoming surface. Read-only computation.
        register_rest_route( self::NS, '/chemistry/pair/(?P<a>\d+)/(?P<b>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_pair' ],
                'permission_callback' => [ __CLASS__, 'can_view_pair' ],
            ],
        ]);
    }

    // ── permission helpers ──────────────────────────────────────────

    public static function can_view_player( \WP_REST_Request $r ): bool {
        return AuthorizationService::canViewPlayer( get_current_user_id(), absint( $r['player_id'] ) );
    }

    public static function can_view_pair( \WP_REST_Request $r ): bool {
        $uid = get_current_user_id();
        return AuthorizationService::canViewPlayer( $uid, absint( $r['a'] ) )
            && AuthorizationService::canViewPlayer( $uid, absint( $r['b'] ) );
    }

    public static function can_edit_player( \WP_REST_Request $r ): bool {
        return AuthorizationService::canEvaluatePlayer( get_current_user_id(), absint( $r['player_id'] ) );
    }

    public static function can_read_config(): bool {
        return MatrixGate::canAnyScope( get_current_user_id(), 'team_chemistry', 'read' );
    }

    public static function can_change_config(): bool {
        return MatrixGate::can( get_current_user_id(), 'team_chemistry', 'change', 'global' );
    }

    // ── player attributes ───────────────────────────────────────────

    public static function get_attributes( \WP_REST_Request $r ) {
        $player_id = absint( $r['player_id'] );
        $grouped   = ( new PlayerAttributesRepository() )->forPlayer( $player_id );
        return new \WP_REST_Response( [ 'player_id' => $player_id, 'groups' => $grouped ], 200 );
    }

    public static function put_attributes( \WP_REST_Request $r ) {
        $player_id = absint( $r['player_id'] );
        $values    = $r->get_param( 'values' );
        if ( ! is_array( $values ) ) {
            return new \WP_Error( 'tt_bad_payload', __( 'Expected a values map.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $repo  = new PlayerAttributesRepository();
        $saved = 0;
        foreach ( $values as $def_id => $value ) {
            $def_id = absint( $def_id );
            if ( $def_id <= 0 ) continue;
            $v = ( $value === '' || $value === null ) ? null : (int) $value;
            if ( $repo->upsertValue( $player_id, $def_id, $v ) ) {
                $saved++;
            }
        }
        return new \WP_REST_Response( [ 'saved' => $saved ], 200 );
    }

    // ── position matrix ─────────────────────────────────────────────

    public static function get_matrix( \WP_REST_Request $r ) {
        $rows = ( new ChemistryPositionMatrixRepository() )->all();
        $out  = array_map( static function ( $row ) {
            return [
                'position_a' => (string) $row->position_a,
                'position_b' => (string) $row->position_b,
                'weight'     => (float) $row->weight,
            ];
        }, $rows );
        return new \WP_REST_Response( [ 'matrix' => $out ], 200 );
    }

    public static function put_matrix( \WP_REST_Request $r ) {
        $rows = $r->get_param( 'matrix' );
        if ( ! is_array( $rows ) ) {
            return new \WP_Error( 'tt_bad_payload', __( 'Expected a matrix array.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $repo  = new ChemistryPositionMatrixRepository();
        $saved = 0;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $a = sanitize_key( (string) ( $row['position_a'] ?? '' ) );
            $b = sanitize_key( (string) ( $row['position_b'] ?? '' ) );
            $w = (float) ( $row['weight'] ?? 0 );
            if ( $a !== '' && $b !== '' && $repo->upsert( $a, $b, $w ) ) {
                $saved++;
            }
        }
        return new \WP_REST_Response( [ 'saved' => $saved ], 200 );
    }

    // ── component weights ───────────────────────────────────────────

    public static function get_config( \WP_REST_Request $r ) {
        return new \WP_REST_Response( [ 'weights' => ( new ChemistryConfig() )->weights() ], 200 );
    }

    public static function put_config( \WP_REST_Request $r ) {
        $weights = $r->get_param( 'weights' );
        if ( ! is_array( $weights ) ) {
            return new \WP_Error( 'tt_bad_payload', __( 'Expected a weights map.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $saved = ( new ChemistryConfig() )->saveWeights( $weights );
        return new \WP_REST_Response( [ 'weights' => $saved ], 200 );
    }

    // ── pair chemistry (Phase 3) ─────────────────────────────────────

    public static function get_pair( \WP_REST_Request $r ) {
        $a = absint( $r['a'] );
        $b = absint( $r['b'] );
        if ( $a === $b ) {
            return new \WP_Error( 'tt_same_player', __( 'Pick two different players.', 'talenttrack' ), [ 'status' => 400 ] );
        }

        $loader = new ChemistryProfileLoader();
        $loader->load( [ $a, $b ] );
        $result = ( new PairChemistryEngine() )->scorePair(
            $loader->profile( $a ),
            $loader->profile( $b ),
            $loader->pairContext( $a, $b )
        );

        $components = [];
        foreach ( $result->components as $key => $cs ) {
            $components[ $key ] = [
                'value'    => round( $cs->value, 1 ),
                'has_data' => $cs->has_data,
                'reasons'  => $cs->reasons,
            ];
        }

        return new \WP_REST_Response( [
            'player_a_id' => $result->player_a_id,
            'player_b_id' => $result->player_b_id,
            'score'       => $result->score,
            'category'    => $result->category,
            'has_data'    => $result->has_data,
            'components'  => $components,
            'reasons'     => $result->reasons,
        ], 200 );
    }
}
