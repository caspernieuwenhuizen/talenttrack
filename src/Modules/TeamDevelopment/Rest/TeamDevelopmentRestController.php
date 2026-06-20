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

        // Sprint 2-5 — chemistry, pairings, team-fit. #1485 — these are
        // the Team-chemistry sub-feature's REST surface. They gate on the
        // feature flag (via can_view_chemistry / can_manage_chemistry) so
        // switching it off takes them dark while the blueprint + formation
        // routes below — which share the same caps — keep serving.
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/chemistry', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_chemistry' ],
                'permission_callback' => [ __CLASS__, 'can_view_chemistry' ],
            ],
        ] );
        // v3.110.174 — chemistry preview for the "Try a lineup" sandbox
        // on the chemistry board. Pure compute, zero DB writes.
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/chemistry/preview', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'preview_chemistry' ],
                'permission_callback' => [ __CLASS__, 'can_view_chemistry' ],
            ],
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/pairings', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_pairings' ],
                'permission_callback' => [ __CLASS__, 'can_view_chemistry' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'add_pairing' ],
                'permission_callback' => [ __CLASS__, 'can_manage_chemistry' ],
            ],
        ] );
        register_rest_route( self::NS, '/pairings/(?P<id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_pairing' ],
                'permission_callback' => [ __CLASS__, 'can_manage_chemistry' ],
            ],
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/team-fit', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_team_fit' ],
                'permission_callback' => [ __CLASS__, 'can_view_chemistry' ],
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
        // v3.110.184 — Save-As. Duplicates the blueprint + every
        // assignment row to a new draft. Body: `{ "name": "..." }`.
        // Returns `{ "id": <new_blueprint_id> }`.
        register_rest_route( self::NS, '/blueprints/(?P<id>\d+)/clone', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'clone_blueprint' ],
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

    /**
     * #1485 — chemistry-board read access: the view cap AND the
     * team_chemistry sub-feature being on. Blueprint / formation routes
     * keep using can_view() so they survive the feature switch.
     */
    public static function can_view_chemistry(): bool {
        if ( ! current_user_can( 'tt_view_team_chemistry' ) ) return false;
        if ( ! class_exists( '\\TT\\Core\\FeatureRegistry' ) ) return true;
        return \TT\Core\FeatureRegistry::isEnabled( 'team_chemistry' );
    }

    /** #1485 — chemistry-board write access, feature-gated. */
    public static function can_manage_chemistry(): bool {
        if ( ! current_user_can( 'tt_manage_team_chemistry' ) ) return false;
        if ( ! class_exists( '\\TT\\Core\\FeatureRegistry' ) ) return true;
        return \TT\Core\FeatureRegistry::isEnabled( 'team_chemistry' );
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

    /**
     * v3.110.174 — chemistry preview for the "Try a lineup" sandbox.
     * Accepts an `overrides` map (slot_label → player_id|null) on top of
     * the existing template + style config and returns the recomputed
     * chemistry payload + link chemistry without writing anything.
     */
    public static function preview_chemistry( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['id'] );
        if ( $team_id <= 0 || ! QueryHelpers::get_team( $team_id ) ) {
            return RestResponse::error( 'bad_team', __( 'Team not found.', 'talenttrack' ), 404 );
        }
        global $wpdb; $p = $wpdb->prefix;

        // Template — defaults to the team's stored pick, then the first
        // seeded template, matching `get_chemistry`'s fallback chain.
        $template_id = absint( $r['template_id'] ?? 0 );
        if ( $template_id <= 0 ) {
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
            return RestResponse::error( 'no_template',
                __( 'No formation template available.', 'talenttrack' ), 500 );
        }

        $style = $wpdb->get_row( $wpdb->prepare(
            "SELECT possession_weight, counter_weight, press_weight FROM {$p}tt_team_playing_styles WHERE team_id = %d",
            $team_id
        ) );
        $poss = isset( $r['possession'] ) ? max( 0, min( 100, absint( $r['possession'] ) ) ) : ( $style ? (int) $style->possession_weight : 33 );
        $cntr = isset( $r['counter'] )    ? max( 0, min( 100, absint( $r['counter'] ) ) )    : ( $style ? (int) $style->counter_weight    : 33 );
        $prss = isset( $r['press'] )      ? max( 0, min( 100, absint( $r['press'] ) ) )      : ( $style ? (int) $style->press_weight      : 34 );

        // Override map — slot_label (string) → player_id (int) | null.
        $overrides_raw = $r['overrides'] ?? [];
        $overrides = [];
        if ( is_array( $overrides_raw ) ) {
            foreach ( $overrides_raw as $slot => $pid ) {
                $slot = sanitize_text_field( (string) $slot );
                if ( $slot === '' ) continue;
                if ( $pid === null || $pid === '' || $pid === 0 || $pid === '0' ) {
                    $overrides[ $slot ] = null;
                } else {
                    $overrides[ $slot ] = absint( $pid );
                }
            }
        }

        $aggregator = new ChemistryAggregator();
        $payload = $aggregator->teamChemistry( $team_id, $template_id, $poss, $cntr, $prss, $overrides );

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
            'overrides'            => $overrides,
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
        // #1268 — same chemistry-recompute guard as set_blueprint_assignment().
        // The GET path is the editor's recovery surface after a save; a
        // chemistry throw here would 500 every subsequent page reload
        // even with the assignment safely written. Catch + log + return
        // the blueprint with `blueprint_chemistry: null`; the editor
        // renders cleanly without the chemistry overlay.
        $primary_lineup      = $repo->loadPrimaryLineup( $id );
        $blueprint_chemistry = null;
        $chemistry_error     = null;
        try {
            $blueprint_chemistry = ( new BlueprintChemistryEngine() )->computeForLineup(
                (int) $bp['team_id'],
                (array) ( $bp['slots'] ?? [] ),
                $primary_lineup
            );
        } catch ( \Throwable $e ) {
            $chemistry_error = $e->getMessage();
            if ( class_exists( '\\TT\\Infrastructure\\Logging\\Logger' ) ) {
                \TT\Infrastructure\Logging\Logger::error( 'blueprint.get.chemistry.failed', [
                    'blueprint_id' => $id,
                    'error'        => $chemistry_error,
                    'trace'        => $e->getTraceAsString(),
                ] );
            }
        }

        // #953 — surface the full ref shape (player / guest / custom) on
        // the GET payload so a future SaaS front end can render every
        // tier cell without a second round-trip. Plain `assignments`
        // stays as the legacy `slot → tier → player_id` map for callers
        // that only need the primary-tier-player lineup (chemistry,
        // share-link view).
        $bp['assignment_refs'] = self::hydrateAssignmentRefs( $repo->loadAssignmentRefs( $id ) );

        return RestResponse::success( [
            'blueprint'           => $bp,
            'blueprint_chemistry' => $blueprint_chemistry,
            'chemistry_error'     => $chemistry_error,
        ] );
    }

    /**
     * #953 — denormalise repository-shaped assignment refs into a
     * display-ready map for the front end. Adds `display_name`,
     * `team_id`, `team_name` to player refs; passes guest / custom
     * through with `display_name` resolved from the on-row fields.
     *
     * @param array<string, array<string, array<string,mixed>>> $refs
     *   `slot_label → tier → ref` from `loadAssignmentRefs()`.
     *
     * @return array<string, array<string, array<string,mixed>>>
     *   Same shape; each ref grows `display_name` (+ `team_id` / `team_name`
     *   on player kinds).
     */
    private static function hydrateAssignmentRefs( array $refs ): array {
        if ( empty( $refs ) ) return $refs;

        // Collect every player_id across all cells so we can do one
        // bulk lookup for name + team rather than N small ones.
        $player_ids = [];
        foreach ( $refs as $tiers ) {
            foreach ( $tiers as $ref ) {
                if ( ( $ref['kind'] ?? '' ) === 'player' && (int) ( $ref['player_id'] ?? 0 ) > 0 ) {
                    $player_ids[ (int) $ref['player_id'] ] = true;
                }
            }
        }

        $player_meta = [];
        if ( ! empty( $player_ids ) ) {
            global $wpdb; $p = $wpdb->prefix;
            $in = implode( ',', array_map( 'intval', array_keys( $player_ids ) ) );
            $rows = $wpdb->get_results(
                "SELECT p.id, p.first_name, p.last_name, p.team_id, t.name AS team_name
                   FROM {$p}tt_players p
                   LEFT JOIN {$p}tt_teams t ON t.id = p.team_id
                  WHERE p.id IN ($in)"
            );
            foreach ( (array) $rows as $row ) {
                $player_meta[ (int) $row->id ] = [
                    'display_name' => trim( (string) $row->first_name . ' ' . (string) $row->last_name ),
                    'team_id'      => $row->team_id !== null ? (int) $row->team_id : null,
                    'team_name'    => $row->team_name !== null ? (string) $row->team_name : null,
                ];
            }
        }

        $out = [];
        foreach ( $refs as $slot_label => $tiers ) {
            foreach ( $tiers as $tier => $ref ) {
                $kind = (string) ( $ref['kind'] ?? '' );
                if ( $kind === 'player' ) {
                    $pid  = (int) ( $ref['player_id'] ?? 0 );
                    $meta = $player_meta[ $pid ] ?? null;
                    $ref['display_name'] = $meta['display_name'] ?? '';
                    $ref['team_id']      = $meta['team_id']      ?? null;
                    $ref['team_name']    = $meta['team_name']    ?? null;
                } elseif ( $kind === 'guest' ) {
                    $ref['display_name'] = (string) ( $ref['name'] ?? '' );
                } elseif ( $kind === 'custom' ) {
                    $ref['display_name'] = (string) ( $ref['label'] ?? '' );
                }
                $out[ (string) $slot_label ][ (string) $tier ] = $ref;
            }
        }
        return $out;
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

    /**
     * v3.110.184 — Save-As. Body: `{ "name": "..." }`. Duplicates
     * the blueprint row + every assignment row to a new draft.
     */
    public static function clone_blueprint( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $repo = new TeamBlueprintsRepository();
        $existing = $repo->find( $id );
        if ( $existing === null ) {
            return RestResponse::error( 'bad_blueprint',
                __( 'Blueprint not found.', 'talenttrack' ), 404 );
        }
        $name = isset( $r['name'] ) ? trim( sanitize_text_field( wp_unslash( (string) $r['name'] ) ) ) : '';
        if ( $name === '' ) {
            return RestResponse::error( 'bad_name',
                __( 'Give the cloned blueprint a name.', 'talenttrack' ), 400 );
        }
        $new_id = $repo->cloneBlueprint( $id, $name, get_current_user_id() );
        if ( $new_id <= 0 ) {
            return RestResponse::error( 'clone_failed',
                __( 'Could not duplicate the blueprint.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $new_id ] );
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
        $slot = trim( (string) ( $r['slot_label'] ?? '' ) );
        $tier = (string) ( $r['tier'] ?? TeamBlueprintsRepository::TIER_PRIMARY );
        if ( $slot === '' ) {
            return RestResponse::error( 'missing_slot',
                __( 'slot_label is required.', 'talenttrack' ), 400 );
        }
        if ( ! in_array( $tier, TeamBlueprintsRepository::TIERS, true ) ) {
            $tier = TeamBlueprintsRepository::TIER_PRIMARY;
        }

        // #953 — accept either the new `ref` object or the legacy flat
        // `player_id` shape. Documented in docs/rest-api.md; the shim
        // stays at the REST boundary so the in-repo callers + storage
        // layer use the new ref shape uniformly.
        //
        // #1054 — coerceAssignmentRef now throws on a malformed player
        // ref (caller meant to set a player but supplied no valid id).
        // Previously the malformed input was silently coerced to null
        // → setAssignment deleted the slot → endpoint returned 200 OK
        // → JS reloaded → slot stayed empty. Now we 400 instead so the
        // client gets a real error.
        try {
            $ref = self::coerceAssignmentRef( $r );
        } catch ( \InvalidArgumentException $e ) {
            return RestResponse::error( 'bad_ref', $e->getMessage(), 400 );
        }
        $saved = $repo->setAssignment( $id, $slot, $ref, $tier );
        if ( ! $saved ) {
            // #1054 — setAssignment now reports DB-layer failures (insert
            // / update / delete returning false). Surface as 500 instead
            // of silently lying to the client. The last_error helps the
            // operator diagnose the underlying SQL issue.
            $hint = $repo->lastError();
            return RestResponse::error(
                'save_failed',
                $hint !== '' ? $hint : __( 'Failed to save the assignment.', 'talenttrack' ),
                500
            );
        }

        // #1268 — chemistry recompute is best-effort. The assignment
        // row is already persisted by setAssignment above; a
        // chemistry failure here must NOT 500 the whole save and
        // lie to the operator that their pick was rejected. Catch
        // any throw, log it with full context, and return the save
        // as 200 with `blueprint_chemistry: null`. The next page
        // reload pulls a fresh chemistry score via the GET path
        // (which is the recovery the JS already performs).
        //
        // Same defence as #1054 / #1137 / #1149 / #1153 — surface
        // downstream failures loudly via the logger so the chemistry
        // root cause becomes diagnosable from `wp-content/debug.log`
        // without the operator describing the steps.
        $blueprint_chemistry = null;
        $chemistry_error = null;
        try {
            $bp     = $repo->find( $id );
            $lineup = $repo->loadPrimaryLineup( $id );
            if ( $bp !== null ) {
                $blueprint_chemistry = ( new BlueprintChemistryEngine() )->computeForLineup(
                    (int) $bp['team_id'],
                    (array) ( $bp['slots'] ?? [] ),
                    $lineup
                );
            }
        } catch ( \Throwable $e ) {
            $chemistry_error = $e->getMessage();
            if ( class_exists( '\\TT\\Infrastructure\\Logging\\Logger' ) ) {
                \TT\Infrastructure\Logging\Logger::error( 'blueprint.assignment.chemistry.failed', [
                    'blueprint_id' => $id,
                    'slot_label'   => $slot,
                    'tier'         => $tier,
                    'error'        => $chemistry_error,
                    'trace'        => $e->getTraceAsString(),
                ] );
            }
        }
        return RestResponse::success( [
            'id'                  => $id,
            'slot_label'          => $slot,
            'tier'                => $tier,
            'ref'                 => $ref,
            'blueprint_chemistry' => $blueprint_chemistry,
            'chemistry_error'     => $chemistry_error,
        ] );
    }

    /**
     * #953 — REST boundary shim. Accepts the new `ref` object payload
     * or the legacy flat `player_id` shape (sunset v5.0.0 per
     * docs/rest-api.md).
     *
     *   { ref: { kind: 'player', player_id: 123 } }
     *   { ref: { kind: 'guest',  name: '…', position: '…' } }
     *   { ref: { kind: 'custom', label: '…' } }
     *   { ref: null }                       — intentional clear
     *   { player_id: 123 }                  — legacy set
     *   { player_id: null }                 — legacy clear
     *
     * #1054 — throws \InvalidArgumentException when the caller meant to
     * set a record (sent `kind`) but supplied no usable identifier. The
     * controller turns that into a 400 instead of silently coercing to
     * a clear (which previously presented as "I picked a player but it
     * vanished").
     *
     * @return array<string,mixed>|null
     */
    private static function coerceAssignmentRef( \WP_REST_Request $r ): ?array {
        $ref = $r['ref'] ?? null;
        if ( is_array( $ref ) && isset( $ref['kind'] ) ) {
            $kind = (string) $ref['kind'];
            if ( $kind === 'player' ) {
                if ( ! isset( $ref['player_id'] ) || $ref['player_id'] === null || ! is_numeric( $ref['player_id'] ) ) {
                    throw new \InvalidArgumentException(
                        __( 'ref.player_id is missing or non-numeric for a player assignment.', 'talenttrack' )
                    );
                }
                $pid = absint( $ref['player_id'] );
                if ( $pid <= 0 ) {
                    throw new \InvalidArgumentException(
                        __( 'ref.player_id must be a positive integer for a player assignment.', 'talenttrack' )
                    );
                }
                return [ 'kind' => 'player', 'player_id' => $pid ];
            }
            if ( $kind === 'guest' ) {
                $name = trim( (string) ( $ref['name'] ?? '' ) );
                if ( $name === '' ) {
                    throw new \InvalidArgumentException(
                        __( 'ref.name is required for a guest assignment.', 'talenttrack' )
                    );
                }
                return [
                    'kind'     => 'guest',
                    'name'     => $name,
                    'position' => isset( $ref['position'] ) ? trim( (string) $ref['position'] ) : null,
                ];
            }
            if ( $kind === 'custom' ) {
                $label = trim( (string) ( $ref['label'] ?? '' ) );
                if ( $label === '' ) {
                    throw new \InvalidArgumentException(
                        __( 'ref.label is required for a custom assignment.', 'talenttrack' )
                    );
                }
                return [ 'kind' => 'custom', 'label' => $label ];
            }
            throw new \InvalidArgumentException(
                sprintf(
                    /* translators: %s = unknown ref kind */
                    __( 'Unknown ref.kind: %s', 'talenttrack' ),
                    $kind
                )
            );
        }
        // Legacy flat shape — `player_id: N` or `player_id: null`.
        if ( ! isset( $r['player_id'] ) ) return null;
        if ( $r['player_id'] === null ) return null;
        $pid = absint( $r['player_id'] );
        return $pid > 0 ? [ 'kind' => 'player', 'player_id' => $pid ] : null;
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
        // #953 — accept three shapes per slot:
        //   1. flat int / null    (legacy `player_id` for primary tier)
        //   2. tier→int map       (legacy multi-tier)
        //   3. tier→ref map       (new — `{ kind: ..., ... }` per tier)
        // The repository's normaliseRef() handles all three uniformly.
        $clean = [];
        foreach ( $assignments as $slot => $value ) {
            $slot_key = (string) $slot;
            if ( $value === null || $value === '' ) {
                $clean[ $slot_key ] = null;
                continue;
            }
            if ( is_array( $value ) && ! isset( $value['kind'] ) ) {
                // Per-tier map; pass through to the repo's tier loop.
                $clean[ $slot_key ] = $value;
                continue;
            }
            $clean[ $slot_key ] = $value;
        }
        // #1328 — bulk-replace now surfaces SQL-layer failures via
        // `lastError()` instead of silently returning 200 OK with no
        // rows persisted. Mirrors the #1066 fix on the singular
        // `set_blueprint_assignment` path.
        $ok = $repo->replaceAssignments( $id, $clean );
        if ( ! $ok ) {
            $hint = $repo->lastError();
            if ( class_exists( '\\TT\\Infrastructure\\Logging\\Logger' ) ) {
                \TT\Infrastructure\Logging\Logger::error( 'blueprint.assignments.replace.failed', [
                    'blueprint_id' => $id,
                    'error'        => $hint,
                ] );
            }
            return RestResponse::error( 'save_failed',
                $hint !== '' ? $hint : __( 'Failed to save the lineup.', 'talenttrack' ), 500 );
        }
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
