<?php
namespace TT\Modules\TeamDevelopment\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\TeamDevelopment\BlueprintChemistryEngine;
use TT\Modules\TeamDevelopment\ChemistryAggregator;
use TT\Modules\TeamDevelopment\CompatibilityEngine;
use TT\Modules\TeamDevelopment\Repositories\PairingsRepository;

/**
 * TeamDevelopmentRestController — sprint 1 stubs.
 *
 *   GET /talenttrack/v1/teams/{id}/formation     — current formation assignment
 *   PUT /talenttrack/v1/teams/{id}/formation     — assign a template
 *   GET /talenttrack/v1/teams/{id}/style         — possession/counter/press blend
 *   PUT /talenttrack/v1/teams/{id}/style         — update the blend (must sum to 100)
 *   GET /talenttrack/v1/formation-templates      — list seeded + custom templates
 *
 * Sprint 1 ships the read paths in full + write paths that persist to
 * the new tables; the compatibility engine that consumes the data
 * lands in Sprint 2.
 *
 * Coach-scope guard: only admins / head devs can write team-wide
 * settings; coaches read-only on Sprint 1 (Sprint 4's pairing overrides
 * gate by `tt_manage_team_chemistry`, which coaches don't get).
 */
class TeamDevelopmentRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/formation', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_formation' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_formation' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/style', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_style' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_style' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/formation-templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_templates' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
        ] );

        // Sprint 2-5 — chemistry, pairings, team-fit.
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/chemistry', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_chemistry' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/pairings', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_pairings' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'add_pairing' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/pairings/(?P<id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_pairing' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/team-fit', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_team_fit' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_team_chemistry' );
    }

    public static function can_manage(): bool {
        return current_user_can( 'tt_manage_team_chemistry' );
    }

    public static function get_formation( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT f.*, t.name AS template_name, t.formation_shape, t.slots_json
               FROM {$p}tt_team_formations f
               LEFT JOIN {$p}tt_formation_templates t ON t.id = f.formation_template_id
              WHERE f.team_id = %d",
            $team_id
        ) );
        if ( ! $row ) {
            return RestResponse::success( [ 'team_id' => $team_id, 'formation' => null ] );
        }
        return RestResponse::success( [
            'team_id' => $team_id,
            'formation' => [
                'template_id'     => (int) $row->formation_template_id,
                'template_name'   => \TT\Infrastructure\Query\LabelTranslator::formationName( (string) ( $row->template_name ?? '' ) ),
                'formation_shape' => (string) ( $row->formation_shape ?? '' ),
                'slots'           => self::decodeSlots( (string) ( $row->slots_json ?? '' ) ),
                'assigned_at'     => $row->assigned_at,
                'assigned_by'     => $row->assigned_by !== null ? (int) $row->assigned_by : null,
            ],
        ] );
    }

    public static function put_formation( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        $template_id = absint( $r['formation_template_id'] ?? 0 );
        if ( $template_id <= 0 ) {
            return RestResponse::error( 'missing_fields',
                __( 'formation_template_id is required.', 'talenttrack' ), 400 );
        }
        global $wpdb; $p = $wpdb->prefix;
        $tpl = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}tt_formation_templates WHERE id = %d AND archived_at IS NULL",
            $template_id
        ) );
        if ( ! $tpl ) {
            return RestResponse::error( 'bad_template',
                __( 'Formation template not found.', 'talenttrack' ), 404 );
        }

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_team_formations WHERE team_id = %d", $team_id
        ) );
        $payload = [
            'team_id'               => $team_id,
            'formation_template_id' => $template_id,
            'assigned_by'           => get_current_user_id(),
        ];
        if ( $existing ) {
            $ok = $wpdb->update( "{$p}tt_team_formations", $payload, [ 'id' => (int) $existing ] );
        } else {
            $ok = $wpdb->insert( "{$p}tt_team_formations", $payload );
        }
        if ( $ok === false ) {
            Logger::error( 'team_dev.formation.save.failed', [ 'team_id' => $team_id, 'template_id' => $template_id ] );
            return RestResponse::error( 'db_error',
                __( 'The formation could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'team_id' => $team_id, 'formation_template_id' => $template_id ] );
    }

    public static function get_style( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_team_playing_styles WHERE team_id = %d", $team_id
        ) );
        // Default to even-blend when no row exists yet.
        return RestResponse::success( [
            'team_id'           => $team_id,
            'possession_weight' => $row ? (int) $row->possession_weight : 33,
            'counter_weight'    => $row ? (int) $row->counter_weight    : 33,
            'press_weight'      => $row ? (int) $row->press_weight      : 34,
            'updated_at'        => $row->updated_at ?? null,
            'updated_by'        => $row && $row->updated_by !== null ? (int) $row->updated_by : null,
        ] );
    }

    public static function put_style( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        $poss = max( 0, min( 100, absint( $r['possession_weight'] ?? 0 ) ) );
        $cntr = max( 0, min( 100, absint( $r['counter_weight']    ?? 0 ) ) );
        $prss = max( 0, min( 100, absint( $r['press_weight']      ?? 0 ) ) );
        if ( ( $poss + $cntr + $prss ) !== 100 ) {
            return RestResponse::error( 'bad_blend',
                __( 'Possession + counter + press must sum to 100.', 'talenttrack' ),
                400, [ 'sum' => $poss + $cntr + $prss ] );
        }

        global $wpdb; $p = $wpdb->prefix;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_team_playing_styles WHERE team_id = %d", $team_id
        ) );
        $payload = [
            'team_id'           => $team_id,
            'possession_weight' => $poss,
            'counter_weight'    => $cntr,
            'press_weight'      => $prss,
            'updated_by'        => get_current_user_id(),
        ];
        if ( $existing ) {
            $ok = $wpdb->update( "{$p}tt_team_playing_styles", $payload, [ 'id' => (int) $existing ] );
        } else {
            $ok = $wpdb->insert( "{$p}tt_team_playing_styles", $payload );
        }
        if ( $ok === false ) {
            Logger::error( 'team_dev.style.save.failed', [ 'team_id' => $team_id ] );
            return RestResponse::error( 'db_error',
                __( 'The style blend could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [
            'team_id' => $team_id,
            'possession_weight' => $poss,
            'counter_weight'    => $cntr,
            'press_weight'      => $prss,
        ] );
    }

    public static function list_templates(): \WP_REST_Response {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT id, name, formation_shape, slots_json, is_seeded
               FROM {$p}tt_formation_templates
              WHERE archived_at IS NULL
              ORDER BY is_seeded DESC, name ASC"
        );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = [
                'id'              => (int) $row->id,
                'name'            => \TT\Infrastructure\Query\LabelTranslator::formationName( (string) $row->name ),
                'formation_shape' => (string) $row->formation_shape,
                'is_seeded'       => (int) $row->is_seeded === 1,
                'slots'           => self::decodeSlots( (string) $row->slots_json ),
            ];
        }
        return RestResponse::success( [ 'rows' => $out ] );
    }

    /** @return array<int,mixed> */
    private static function decodeSlots( string $json ): array {
        if ( $json === '' ) return [];
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    // Sprint 2-5 handlers

    public static function get_chemistry( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        global $wpdb; $p = $wpdb->prefix;

        // Resolve formation. If the team hasn't picked one, fall back to
        // the first seeded template so the board still renders.
        $template_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT formation_template_id FROM {$p}tt_team_formations WHERE team_id = %d",
            $team_id
        ) );
        if ( $template_id <= 0 ) {
            $template_id = (int) $wpdb->get_var(
                "SELECT id FROM {$p}tt_formation_templates WHERE is_seeded = 1 AND archived_at IS NULL ORDER BY id ASC LIMIT 1"
            );
        }
        if ( $template_id <= 0 ) {
            return RestResponse::error( 'no_template',
                __( 'No formation template available. Run migrations to seed defaults.', 'talenttrack' ), 500 );
        }

        $style = $wpdb->get_row( $wpdb->prepare(
            "SELECT possession_weight, counter_weight, press_weight FROM {$p}tt_team_playing_styles WHERE team_id = %d",
            $team_id
        ) );
        $poss = $style ? (int) $style->possession_weight : 33;
        $cntr = $style ? (int) $style->counter_weight    : 33;
        $prss = $style ? (int) $style->press_weight      : 34;

        $aggregator = new ChemistryAggregator();
        $payload = $aggregator->teamChemistry( $team_id, $template_id, $poss, $cntr, $prss );

        // Layer in pair-link chemistry over the suggested XI. Consumers
        // that want links + team-score for an arbitrary lineup pass
        // their own lineup via POST when that endpoint lands.
        $template_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT slots_json FROM {$p}tt_formation_templates WHERE id = %d",
            $template_id
        ) );
        $slots = is_array( $decoded = json_decode( (string) ( $template_row->slots_json ?? '[]' ), true ) ) ? $decoded : [];
        $blueprint = ( new BlueprintChemistryEngine() )->computeForSuggested( $team_id, $slots, $payload['suggested_xi'] );

        return RestResponse::success( [
            'team_id'              => $team_id,
            'formation_template_id' => $template_id,
            'style'                => [ 'possession' => $poss, 'counter' => $cntr, 'press' => $prss ],
            'blueprint_chemistry'  => $blueprint,
        ] + $payload );
    }

    public static function list_pairings( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        $rows = ( new PairingsRepository() )->listForTeam( $team_id );
        // Enrich with player names so the UI doesn't need a second round-trip.
        foreach ( $rows as &$row ) {
            $a = QueryHelpers::get_player( (int) $row['player_a_id'] );
            $b = QueryHelpers::get_player( (int) $row['player_b_id'] );
            $row['player_a_name'] = $a ? QueryHelpers::player_display_name( $a ) : '';
            $row['player_b_name'] = $b ? QueryHelpers::player_display_name( $b ) : '';
        }
        unset( $row );
        return RestResponse::success( [ 'rows' => $rows ] );
    }

    public static function add_pairing( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        $a = absint( $r['player_a_id'] ?? 0 );
        $b = absint( $r['player_b_id'] ?? 0 );
        if ( $a <= 0 || $b <= 0 || $a === $b ) {
            return RestResponse::error( 'bad_players',
                __( 'Pick two different players.', 'talenttrack' ), 400 );
        }
        $note = isset( $r['note'] ) ? sanitize_text_field( (string) $r['note'] ) : null;
        $id = ( new PairingsRepository() )->add( $team_id, $a, $b, $note, get_current_user_id() );
        if ( $id <= 0 ) {
            return RestResponse::error( 'db_error',
                __( 'Could not save the pairing.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete_pairing( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid pairing id.', 'talenttrack' ), 400 );
        }
        $ok = ( new PairingsRepository() )->remove( $id );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'Could not delete the pairing.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function get_team_fit( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['id'] );
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            return RestResponse::error( 'bad_player', __( 'Player not found.', 'talenttrack' ), 404 );
        }

        global $wpdb; $p = $wpdb->prefix;
        // Use the team's assigned formation if any, else the first seeded one.
        $template_id = 0;
        $team_id = (int) ( $player->team_id ?? 0 );
        if ( $team_id > 0 ) {
            $template_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT formation_template_id FROM {$p}tt_team_formations WHERE team_id = %d",
                $team_id
            ) );
        }
        if ( $template_id <= 0 ) {
            $template_id = (int) $wpdb->get_var(
                "SELECT id FROM {$p}tt_formation_templates WHERE is_seeded = 1 AND archived_at IS NULL ORDER BY id ASC LIMIT 1"
            );
        }
        if ( $template_id <= 0 ) {
            return RestResponse::success( [ 'player_id' => $player_id, 'rows' => [] ] );
        }

        $engine = new CompatibilityEngine();
        $all = $engine->allSlotsFor( $player_id, $template_id );
        $rows = [];
        foreach ( $all as $label => $result ) {
            $rows[] = [
                'slot'      => $label,
                'score'     => round( $result->score, 2 ),
                'rationale' => $result->rationale,
            ];
        }
        usort( $rows, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
        return RestResponse::success( [
            'player_id'             => $player_id,
            'formation_template_id' => $template_id,
            'rows'                  => $rows,
            'top_3'                 => array_slice( $rows, 0, 3 ),
        ] );
    }
}
