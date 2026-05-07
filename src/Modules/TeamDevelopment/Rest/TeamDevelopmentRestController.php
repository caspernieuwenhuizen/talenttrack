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
use TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository;

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

        // Phase 1 of Team Blueprint (#0068 follow-up). REST is the
        // contract for the drag-drop editor; the editor's per-drop
        // PUT lands on /blueprints/{id}/assignment so a 50-row
        // replace doesn't run on every gesture.
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/blueprints', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_blueprints' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_blueprint' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/blueprints/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_blueprint' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_blueprint' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_blueprint' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/blueprints/(?P<id>\d+)/assignment', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'set_blueprint_assignment' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/blueprints/(?P<id>\d+)/assignments', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'replace_blueprint_assignments' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/blueprints/(?P<id>\d+)/status', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'set_blueprint_status' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
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

    // Team Blueprint Phase 1 (#0068 follow-up)

    public static function list_blueprints( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [
            'team_id' => $team_id,
            'rows'    => ( new TeamBlueprintsRepository() )->listForTeam( $team_id ),
        ] );
    }

    public static function create_blueprint( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        $name        = trim( (string) ( $r['name'] ?? '' ) );
        $template_id = absint( $r['formation_template_id'] ?? 0 );
        $flavour     = (string) ( $r['flavour'] ?? TeamBlueprintsRepository::FLAVOUR_MATCH_DAY );
        if ( $name === '' || $template_id <= 0 ) {
            return RestResponse::error( 'missing_fields',
                __( 'Name and formation are required.', 'talenttrack' ), 400 );
        }
        global $wpdb; $p = $wpdb->prefix;
        $tpl = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_formation_templates WHERE id = %d AND archived_at IS NULL",
            $template_id
        ) );
        if ( ! $tpl ) {
            return RestResponse::error( 'bad_template',
                __( 'Formation template not found.', 'talenttrack' ), 404 );
        }
        $id = ( new TeamBlueprintsRepository() )->create(
            $team_id, $name, $template_id, get_current_user_id(), $flavour
        );
        if ( $id <= 0 ) {
            Logger::error( 'team_dev.blueprint.create.failed', [ 'team_id' => $team_id ] );
            return RestResponse::error( 'db_error',
                __( 'The blueprint could not be created.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id, 'team_id' => $team_id, 'flavour' => $flavour ] );
    }

    public static function get_blueprint( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new TeamBlueprintsRepository();
        $bp   = $repo->find( $id );
        if ( $bp === null ) {
            return RestResponse::error( 'bad_blueprint',
                __( 'Blueprint not found.', 'talenttrack' ), 404 );
        }
        $primary_lineup = $repo->loadPrimaryLineup( $id );
        $blueprint_chemistry = ( new BlueprintChemistryEngine() )->computeForLineup(
            (int) $bp['team_id'],
            (array) ( $bp['slots'] ?? [] ),
            $primary_lineup
        );
        return RestResponse::success( [
            'blueprint'           => $bp,
            'blueprint_chemistry' => $blueprint_chemistry,
        ] );
    }

    public static function update_blueprint( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $repo = new TeamBlueprintsRepository();
        $existing = $repo->find( $id );
        if ( $existing === null ) {
            return RestResponse::error( 'bad_blueprint',
                __( 'Blueprint not found.', 'talenttrack' ), 404 );
        }
        if ( $existing['status'] === TeamBlueprintsRepository::STATUS_LOCKED ) {
            return RestResponse::error( 'locked',
                __( 'This blueprint is locked. Reopen it before editing.', 'talenttrack' ), 409 );
        }
        $patch = [];
        if ( isset( $r['name'] ) )                  $patch['name'] = trim( (string) $r['name'] );
        if ( isset( $r['formation_template_id'] ) ) $patch['formation_template_id'] = absint( $r['formation_template_id'] );
        if ( isset( $r['notes'] ) )                 $patch['notes'] = (string) $r['notes'];
        if ( empty( $patch ) ) {
            return RestResponse::error( 'no_changes',
                __( 'Nothing to update.', 'talenttrack' ), 400 );
        }
        $repo->updateMeta( $id, $patch, get_current_user_id() );
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete_blueprint( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $repo = new TeamBlueprintsRepository();
        $existing = $repo->find( $id );
        if ( $existing === null ) {
            return RestResponse::error( 'bad_blueprint',
                __( 'Blueprint not found.', 'talenttrack' ), 404 );
        }
        $repo->delete( $id );
        return RestResponse::success( [ 'id' => $id, 'deleted' => true ] );
    }

    public static function set_blueprint_assignment( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $repo = new TeamBlueprintsRepository();
        $existing = $repo->find( $id );
        if ( $existing === null ) {
            return RestResponse::error( 'bad_blueprint',
                __( 'Blueprint not found.', 'talenttrack' ), 404 );
        }
        if ( $existing['status'] === TeamBlueprintsRepository::STATUS_LOCKED ) {
            return RestResponse::error( 'locked',
                __( 'This blueprint is locked. Reopen it before editing.', 'talenttrack' ), 409 );
        }
        $slot      = trim( (string) ( $r['slot_label'] ?? '' ) );
        $tier      = (string) ( $r['tier'] ?? TeamBlueprintsRepository::TIER_PRIMARY );
        $player_id = isset( $r['player_id'] ) ? absint( $r['player_id'] ) : 0;
        if ( $slot === '' ) {
            return RestResponse::error( 'missing_slot',
                __( 'slot_label is required.', 'talenttrack' ), 400 );
        }
        if ( ! in_array( $tier, TeamBlueprintsRepository::TIERS, true ) ) {
            $tier = TeamBlueprintsRepository::TIER_PRIMARY;
        }
        $repo->setAssignment( $id, $slot, $player_id > 0 ? $player_id : null, $tier );

        // Recompute chemistry on the new primary lineup so the editor
        // can refresh the score + lines without round-tripping the get.
        $bp     = $repo->find( $id );
        $lineup = $repo->loadPrimaryLineup( $id );
        $blueprint_chemistry = ( new BlueprintChemistryEngine() )->computeForLineup(
            (int) $bp['team_id'],
            (array) ( $bp['slots'] ?? [] ),
            $lineup
        );
        return RestResponse::success( [
            'id'                  => $id,
            'slot_label'          => $slot,
            'tier'                => $tier,
            'player_id'           => $player_id > 0 ? $player_id : null,
            'blueprint_chemistry' => $blueprint_chemistry,
        ] );
    }

    public static function replace_blueprint_assignments( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $repo = new TeamBlueprintsRepository();
        $existing = $repo->find( $id );
        if ( $existing === null ) {
            return RestResponse::error( 'bad_blueprint',
                __( 'Blueprint not found.', 'talenttrack' ), 404 );
        }
        if ( $existing['status'] === TeamBlueprintsRepository::STATUS_LOCKED ) {
            return RestResponse::error( 'locked',
                __( 'This blueprint is locked. Reopen it before editing.', 'talenttrack' ), 409 );
        }
        $assignments = $r['assignments'] ?? null;
        if ( ! is_array( $assignments ) ) {
            return RestResponse::error( 'bad_payload',
                __( 'Assignments map is required.', 'talenttrack' ), 400 );
        }
        $clean = [];
        foreach ( $assignments as $slot => $pid ) {
            $clean[ (string) $slot ] = $pid !== null && $pid !== '' ? absint( $pid ) : null;
        }
        $repo->replaceAssignments( $id, $clean );
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function set_blueprint_status( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $repo = new TeamBlueprintsRepository();
        $existing = $repo->find( $id );
        if ( $existing === null ) {
            return RestResponse::error( 'bad_blueprint',
                __( 'Blueprint not found.', 'talenttrack' ), 404 );
        }
        $status = (string) ( $r['status'] ?? '' );
        $valid  = [ TeamBlueprintsRepository::STATUS_DRAFT, TeamBlueprintsRepository::STATUS_SHARED, TeamBlueprintsRepository::STATUS_LOCKED ];
        if ( ! in_array( $status, $valid, true ) ) {
            return RestResponse::error( 'bad_status',
                __( 'Status must be draft, shared, or locked.', 'talenttrack' ), 400 );
        }
        $prior = (string) ( $existing['status'] ?? '' );
        $repo->setStatus( $id, $status, get_current_user_id() );
        if ( $status !== $prior ) {
            // #0068 Phase 3 — emit the status-changed action so the
            // BlueprintSystemMessageSubscriber can post an is_system=1
            // message into the blueprint's discussion thread.
            do_action( 'tt_team_blueprint_status_changed', $id, $status, (int) get_current_user_id() );
        }
        return RestResponse::success( [ 'id' => $id, 'status' => $status ] );
    }
}
