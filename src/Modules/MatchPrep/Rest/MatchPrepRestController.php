<?php
namespace TT\Modules\MatchPrep\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchPrepRestController — REST surface for the match-prep form.
 *
 * Main endpoint: PUT /match-prep/<activity_id> accepts the full form
 * payload (formation, half_length, goals, lineup, player_goals,
 * availability) in one shot. Idempotent — each sub-set replaces the
 * previous state for the prep.
 *
 * Role endpoints (#965): captain + 5 set-piece taker assignments are
 * managed via their own per-row routes so the view can live-save on
 * pick / clear without rewriting the whole form.
 *
 *   PUT    /match-prep/<prep_id>/role             body: { role_key, player_id }
 *   DELETE /match-prep/<prep_id>/role/<role_key>
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

        // Role assignment (captain + 5 set-piece takers). Keyed off
        // prep_id rather than activity_id because the role row belongs
        // to the match-prep aggregate; the view already knows the
        // prep_id from the initial page load.
        register_rest_route( self::NS, '/match-prep/(?P<prep_id>\d+)/role', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_role' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, '/match-prep/(?P<prep_id>\d+)/role/(?P<role_key>[a-z_]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_role' ],
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
            // #1727 — blank / non-positive falls back to the
            // per-age-category default (global fallback 35) instead of a
            // hardcoded 35.
            $patch['half_length_minutes'] = $hl > 0
                ? min( 120, $hl )
                : ( new \TT\Modules\MatchPrep\Services\MatchLengthResolver() )->halfMinutesForActivity( $activity_id );
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
            $absent_ids = [];
            foreach ( $body['availability'] as $pid => $entry ) {
                if ( ! is_array( $entry ) ) continue;
                $status = sanitize_text_field( (string) ( $entry['status'] ?? 'Present' ) );
                $rows[ (int) $pid ] = [
                    'status' => $status,
                    'reason' => sanitize_text_field( (string) ( $entry['reason'] ?? '' ) ),
                ];
                if ( strcasecmp( $status, 'Present' ) !== 0 ) {
                    $absent_ids[] = (int) $pid;
                }
            }
            $repo->replaceAvailability( $prep_id, $rows );
            // Pull any newly-absent player out of role assignments
            // so the role pane never points at a player marked Absent.
            foreach ( $absent_ids as $pid ) {
                $repo->clearRolesForPlayer( $prep_id, $pid );
            }
        }

        // #1194 — project the match-prep partition onto
        // `tt_attendance.lineup_role` + `position_played` so downstream
        // consumers (post-match evaluation flow, future activity-view
        // surfaces) see the planning state. Match-prep remains
        // canonical; tt_attendance is the projection target. Runs after
        // the lineup + availability writes above so it always reflects
        // the latest state.
        self::projectAttendance( $activity_id, $prep_id, $repo );

        Logger::info( 'match_prep.save', [ 'activity_id' => $activity_id, 'prep_id' => $prep_id ] );

        return RestResponse::success( [ 'prep_id' => $prep_id, 'activity_id' => $activity_id ] );
    }

    /**
     * #1194 — write-through projection from match-prep to tt_attendance.
     *
     *   Half-1 lineup slot → lineup_role = 'start', position_played =
     *     formation-template's slot label.
     *   Present availability not in half-1 lineup → lineup_role =
     *     'bench', position_played = NULL.
     *   Absent / not in availability → lineup_role = NULL, position_played
     *     = NULL.
     *
     * The attendance row gets an UPSERT — if no row exists for
     * (activity_id, player_id) it's seeded with record_type='expected'
     * so the post-save activity edit form can pick it up via the
     * existing pre-seeded roster path (#1297). When the activity is
     * marked completed, the edit form converts to record_type='actual';
     * this projection touches only `lineup_role` + `position_played`
     * and leaves status/notes/record_type unchanged on existing rows.
     */
    private static function projectAttendance( int $activity_id, int $prep_id, MatchPrepRepository $repo ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) return;

        // Formation shape → slot → label map.
        $shape = '';
        $formation_id = (int) ( $prep->formation_template_id ?? 0 );
        if ( $formation_id > 0 ) {
            $shape = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT formation_shape FROM {$p}tt_formation_templates WHERE id = %d LIMIT 1",
                $formation_id
            ) );
        }
        $slot_position = [];
        if ( $shape !== '' ) {
            $layouts = FrontendMatchPrepView::defaultSlotLayouts();
            if ( isset( $layouts[ $shape ] ) ) {
                foreach ( $layouts[ $shape ] as $entry ) {
                    if ( isset( $entry['num'], $entry['label'] ) ) {
                        $slot_position[ (int) $entry['num'] ] = (string) $entry['label'];
                    }
                }
            }
        }

        // Half-1 lineup → slot per player.
        $starting_slot = [];
        foreach ( $repo->listLineup( $prep_id ) as $row ) {
            if ( (int) $row->half !== 1 ) continue;
            $starting_slot[ (int) $row->player_id ] = (int) $row->slot_number;
        }

        // Build the set of players to project — union of availability +
        // lineup (an operator who hasn't filled availability but pinned
        // someone into a slot still gets their projection).
        $availability = [];
        foreach ( $repo->listAvailability( $prep_id ) as $row ) {
            $availability[ (int) $row->player_id ] = (string) $row->status;
        }
        $player_ids = array_keys( $starting_slot + $availability );

        foreach ( $player_ids as $player_id ) {
            $player_id = (int) $player_id;
            if ( $player_id <= 0 ) continue;

            if ( isset( $starting_slot[ $player_id ] ) ) {
                $lineup_role     = 'start';
                $slot            = $starting_slot[ $player_id ];
                $position_played = $slot_position[ $slot ] ?? null;
            } else {
                $present         = strcasecmp( (string) ( $availability[ $player_id ] ?? '' ), 'Present' ) === 0;
                $lineup_role     = $present ? 'bench' : null;
                $position_played = null;
            }

            self::upsertAttendanceLineup( $activity_id, $player_id, $lineup_role, $position_played );
        }
    }

    /**
     * UPSERT the lineup projection onto tt_attendance. Updates an
     * existing (activity_id, player_id, is_guest=0) row if present;
     * otherwise inserts a new row tagged record_type='expected' so the
     * edit form's pre-seeded roster path (#1297) treats it identically
     * to wizard-created expected rows.
     */
    private static function upsertAttendanceLineup( int $activity_id, int $player_id, ?string $lineup_role, ?string $position_played ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_attendance
              WHERE activity_id = %d AND player_id = %d AND is_guest = 0
              LIMIT 1",
            $activity_id, $player_id
        ) );
        if ( $existing_id > 0 ) {
            $wpdb->update(
                "{$p}tt_attendance",
                [
                    'lineup_role'     => $lineup_role,
                    'position_played' => $position_played,
                ],
                [ 'id' => $existing_id ]
            );
            return;
        }

        $wpdb->insert( "{$p}tt_attendance", [
            'club_id'         => CurrentClub::id(),
            'activity_id'     => $activity_id,
            'player_id'       => $player_id,
            'is_guest'        => 0,
            'record_type'     => 'expected',
            'lineup_role'     => $lineup_role,
            'position_played' => $position_played,
        ] );
    }

    /**
     * PUT /match-prep/<prep_id>/role
     *
     * Body: { "role_key": "captain", "player_id": 123 }
     *
     * Upserts the (prep_id, role_key) → player_id assignment. Role
     * keys outside the canonical set are rejected with `bad_role_key`.
     */
    public static function put_role( \WP_REST_Request $r ): \WP_REST_Response {
        $prep_id = absint( $r['prep_id'] );
        if ( $prep_id <= 0 ) {
            return RestResponse::error( 'bad_prep', __( 'Invalid match prep id.', 'talenttrack' ), 400 );
        }

        $body = $r->get_json_params();
        if ( ! is_array( $body ) ) $body = [];

        $role_key  = sanitize_key( (string) ( $body['role_key'] ?? '' ) );
        $player_id = (int) ( $body['player_id'] ?? 0 );

        if ( $role_key === '' || ! in_array( $role_key, MatchPrepRepository::ROLE_KEYS, true ) ) {
            return RestResponse::error( 'bad_role_key', __( 'Unknown role key.', 'talenttrack' ), 400 );
        }
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_player', __( 'A player must be selected.', 'talenttrack' ), 400 );
        }

        $repo = new MatchPrepRepository();
        $ok = $repo->setRole( $prep_id, $role_key, $player_id );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'Could not save the role assignment.', 'talenttrack' ), 500 );
        }

        Logger::info( 'match_prep.role.set', [
            'prep_id'   => $prep_id,
            'role_key'  => $role_key,
            'player_id' => $player_id,
        ] );

        return RestResponse::success( [
            'prep_id'   => $prep_id,
            'role_key'  => $role_key,
            'player_id' => $player_id,
        ] );
    }

    /**
     * DELETE /match-prep/<prep_id>/role/<role_key>
     *
     * Clears the (prep_id, role_key) assignment. Idempotent.
     */
    public static function delete_role( \WP_REST_Request $r ): \WP_REST_Response {
        $prep_id  = absint( $r['prep_id'] );
        $role_key = sanitize_key( (string) $r['role_key'] );

        if ( $prep_id <= 0 ) {
            return RestResponse::error( 'bad_prep', __( 'Invalid match prep id.', 'talenttrack' ), 400 );
        }
        if ( $role_key === '' || ! in_array( $role_key, MatchPrepRepository::ROLE_KEYS, true ) ) {
            return RestResponse::error( 'bad_role_key', __( 'Unknown role key.', 'talenttrack' ), 400 );
        }

        $repo = new MatchPrepRepository();
        $repo->clearRole( $prep_id, $role_key );

        Logger::info( 'match_prep.role.clear', [
            'prep_id'  => $prep_id,
            'role_key' => $role_key,
        ] );

        return RestResponse::success( [ 'prep_id' => $prep_id, 'role_key' => $role_key ] );
    }
}
