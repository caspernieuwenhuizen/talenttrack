<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Repositories\MeasurementSessionsRepository;
use TT\Modules\Measurements\Services\MeasurementResultsBrowse;
use TT\Modules\Measurements\Services\PlayerMeasurementProfile;

/**
 * MeasurementsRestController — /wp-json/talenttrack/v1/measurements (#1856).
 *
 * The SaaS contract for the Measurements module (CLAUDE.md §4): every
 * surface the frontend renders is reachable here, matrix-gated, with the
 * business logic in repositories/services rather than the controller.
 *
 * Permission model (no role-string compare):
 *   - reading a player's measurements  → AuthorizationService::canViewPlayer
 *     (self / parent-child / team / global, matrix-resolved).
 *   - recording / editing a result     → canEvaluatePlayer (team-scoped create).
 *   - the test catalogue (definitions)  → MatrixGate on measurement_definitions.
 *   - sessions                          → MatrixGate on measurement_sessions.
 */
class MeasurementsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        // A player's measurement profile (categories → tests → latest + flag + trend).
        register_rest_route( self::NS, '/players/(?P<player_id>\d+)/measurements', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_player_measurements' ],
                'permission_callback' => [ __CLASS__, 'can_view_player_from_route' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'record_result' ],
                'permission_callback' => [ __CLASS__, 'can_edit_player_from_route' ],
            ],
        ]);

        // One test's trend series for a player.
        register_rest_route( self::NS, '/players/(?P<player_id>\d+)/measurements/(?P<definition_id>\d+)/series', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_series' ],
                'permission_callback' => [ __CLASS__, 'can_view_player_from_route' ],
            ],
        ]);

        // A single result — edit / soft-archive.
        register_rest_route( self::NS, '/measurements/results/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_result' ],
                'permission_callback' => [ __CLASS__, 'can_edit_result' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive_result' ],
                'permission_callback' => [ __CLASS__, 'can_edit_result' ],
            ],
        ]);

        // The test catalogue.
        register_rest_route( self::NS, '/measurements/definitions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_definitions' ],
                'permission_callback' => function () {
                    return MatrixGate::canAnyScope( get_current_user_id(), 'measurements', 'read' )
                        || MatrixGate::canAnyScope( get_current_user_id(), 'measurement_definitions', 'read' );
                },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_definition' ],
                'permission_callback' => function () {
                    return MatrixGate::can( get_current_user_id(), 'measurement_definitions', 'create_delete', 'global' );
                },
            ],
        ]);
        register_rest_route( self::NS, '/measurements/definitions/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_definition' ],
                'permission_callback' => function () {
                    return MatrixGate::can( get_current_user_id(), 'measurement_definitions', 'change', 'global' );
                },
            ],
        ]);

        // Testing sessions per team.
        register_rest_route( self::NS, '/teams/(?P<team_id>\d+)/measurement-sessions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_team_sessions' ],
                'permission_callback' => [ __CLASS__, 'can_read_team_sessions' ],
            ],
        ]);
        // #1882 — due/overdue coverage for a team (insights).
        register_rest_route( self::NS, '/teams/(?P<team_id>\d+)/measurement-coverage', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_team_coverage' ],
                'permission_callback' => [ __CLASS__, 'can_read_team_sessions' ],
            ],
        ]);
        register_rest_route( self::NS, '/measurements/sessions', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_session' ],
                'permission_callback' => [ __CLASS__, 'can_create_session' ],
            ],
        ]);

        // #2145 — the Test results browse rows (latest per player for one test,
        // with colour / flag / trend). The SaaS contract behind the
        // FrontendTestResultsView; both call MeasurementResultsBrowse so a
        // non-WordPress front end gets identical answers (CLAUDE.md §4).
        register_rest_route( self::NS, '/measurement-results', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'browse_results' ],
                'permission_callback' => [ __CLASS__, 'can_browse_results' ],
            ],
        ]);
    }

    public static function can_browse_results(): bool {
        return MatrixGate::canAnyScope( get_current_user_id(), 'measurements', 'read' );
    }

    // ── permission helpers ──────────────────────────────────────────

    public static function can_view_player_from_route( \WP_REST_Request $r ): bool {
        $uid = get_current_user_id();
        $pid = absint( $r['player_id'] );
        // #1867 — a parent only reads a section the child hasn't hidden.
        return AuthorizationService::canViewPlayer( $uid, $pid )
            && AuthorizationService::parentCanViewSection( $uid, $pid, 'measurements' );
    }

    public static function can_edit_player_from_route( \WP_REST_Request $r ): bool {
        return AuthorizationService::canEvaluatePlayer( get_current_user_id(), absint( $r['player_id'] ) );
    }

    public static function can_edit_result( \WP_REST_Request $r ): bool {
        $result = ( new MeasurementResultsRepository() )->find( absint( $r['id'] ) );
        if ( ! $result ) return false;
        return AuthorizationService::canEvaluatePlayer( get_current_user_id(), (int) $result->player_id );
    }

    public static function can_read_team_sessions( \WP_REST_Request $r ): bool {
        $uid     = get_current_user_id();
        $team_id = absint( $r['team_id'] );
        return MatrixGate::can( $uid, 'measurement_sessions', 'read', 'global' )
            || MatrixGate::can( $uid, 'measurement_sessions', 'read', 'team', $team_id );
    }

    public static function can_create_session( \WP_REST_Request $r ): bool {
        $uid     = get_current_user_id();
        $team_id = absint( $r['team_id'] ?? 0 );
        return MatrixGate::can( $uid, 'measurement_sessions', 'create_delete', 'global' )
            || ( $team_id > 0 && MatrixGate::can( $uid, 'measurement_sessions', 'create_delete', 'team', $team_id ) );
    }

    // ── read ────────────────────────────────────────────────────────

    public static function get_player_measurements( \WP_REST_Request $r ) {
        $player_id = absint( $r['player_id'] );
        $profile   = ( new PlayerMeasurementProfile() )->forPlayer( $player_id );
        return new \WP_REST_Response( [ 'player_id' => $player_id, 'categories' => $profile ], 200 );
    }

    /** #1882 — per-team due/overdue coverage across scheduled tests. */
    public static function get_team_coverage( \WP_REST_Request $r ) {
        $team_id  = absint( $r['team_id'] );
        $coverage = ( new \TT\Modules\Measurements\Services\MeasurementCoverageService() )->forTeam( $team_id );
        return new \WP_REST_Response( array_merge( [ 'team_id' => $team_id ], $coverage ), 200 );
    }

    public static function get_series( \WP_REST_Request $r ) {
        $player_id     = absint( $r['player_id'] );
        $definition_id = absint( $r['definition_id'] );
        $rows = ( new MeasurementResultsRepository() )->listSeriesForPlayer( $player_id, $definition_id );
        $series = array_map( static function ( $row ) {
            return [
                'id'    => (int) $row->id,
                'date'  => (string) $row->recorded_date,
                'value' => $row->value_numeric !== null ? (float) $row->value_numeric : null,
                'text'  => $row->value_text !== null ? (string) $row->value_text : null,
            ];
        }, $rows );
        return new \WP_REST_Response( [ 'definition_id' => $definition_id, 'series' => $series ], 200 );
    }

    public static function list_definitions( \WP_REST_Request $r ) {
        $defs = ( new MeasurementDefinitionsRepository() )->listActive();
        $out  = array_map( static function ( $d ) {
            return [
                'id'         => (int) $d->id,
                'name'       => (string) $d->name,
                'category'   => (string) ( $d->category_label ?: $d->category_name ?: '' ),
                'value_type' => (string) $d->value_type,
                'unit'       => (string) ( $d->unit ?? '' ),
                'frequency'  => (string) $d->frequency,
                'direction'  => (string) $d->direction,
            ];
        }, $defs );
        return new \WP_REST_Response( [ 'definitions' => $out ], 200 );
    }

    /**
     * #2145 — browse rows for one test: each player's latest in-window value
     * with colour / flag / trend. `definition_id` is required; `team_id`,
     * `age_group`, `from`, `to` narrow the cohort.
     */
    public static function browse_results( \WP_REST_Request $r ) {
        $definition_id = absint( $r['definition_id'] ?? 0 );
        if ( $definition_id <= 0 ) {
            return new \WP_Error( 'tt_missing_definition', __( 'A test must be chosen.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $filters = [
            'team_id'   => absint( $r['team_id'] ?? 0 ),
            'age_group' => sanitize_text_field( (string) ( $r['age_group'] ?? '' ) ),
            'date_from' => self::safe_date( (string) ( $r['from'] ?? '' ) ),
            'date_to'   => self::safe_date( (string) ( $r['to'] ?? '' ) ),
        ];
        $rows = ( new MeasurementResultsBrowse() )->rows( $definition_id, $filters );
        return new \WP_REST_Response( [ 'definition_id' => $definition_id, 'rows' => $rows ], 200 );
    }

    /** Accept only a YYYY-MM-DD date; anything else collapses to ''. */
    private static function safe_date( string $value ): string {
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
    }

    public static function list_team_sessions( \WP_REST_Request $r ) {
        $team_id  = absint( $r['team_id'] );
        $sessions = ( new MeasurementSessionsRepository() )->listForTeam( $team_id );
        $out = array_map( static function ( $s ) {
            return [
                'id'              => (int) $s->id,
                'definition_id'   => (int) $s->definition_id,
                'definition_name' => (string) ( $s->definition_name ?? '' ),
                'planned_date'    => (string) $s->planned_date,
                'status'          => (string) $s->status,
            ];
        }, $sessions );
        return new \WP_REST_Response( [ 'team_id' => $team_id, 'sessions' => $out ], 200 );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function record_result( \WP_REST_Request $r ) {
        $player_id     = absint( $r['player_id'] );
        $definition_id = absint( $r['definition_id'] ?? 0 );
        if ( $definition_id <= 0 ) {
            return new \WP_Error( 'tt_missing_definition', __( 'A test must be chosen.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $id = ( new MeasurementResultsRepository() )->create( [
            'player_id'              => $player_id,
            'definition_id'          => $definition_id,
            'measurement_session_id' => absint( $r['measurement_session_id'] ?? 0 ),
            'recorded_date'          => sanitize_text_field( (string) ( $r['recorded_date'] ?? '' ) ),
            'value_numeric'          => $r['value_numeric'] ?? '',
            'value_text'             => sanitize_text_field( (string) ( $r['value_text'] ?? '' ) ),
        ] );
        if ( $id <= 0 ) {
            Logger::error( 'measurement result insert failed', [ 'player_id' => $player_id ] );
            return new \WP_Error( 'tt_insert_failed', __( 'Could not save the measurement.', 'talenttrack' ), [ 'status' => 500 ] );
        }
        return new \WP_REST_Response( [ 'id' => $id ], 201 );
    }

    public static function update_result( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        $ok = ( new MeasurementResultsRepository() )->update( $id, [
            'recorded_date' => sanitize_text_field( (string) ( $r['recorded_date'] ?? '' ) ),
            'value_numeric' => $r['value_numeric'] ?? '',
            'value_text'    => sanitize_text_field( (string) ( $r['value_text'] ?? '' ) ),
        ] );
        return new \WP_REST_Response( [ 'updated' => $ok ], $ok ? 200 : 400 );
    }

    public static function archive_result( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        $ok = ( new MeasurementResultsRepository() )->archive( $id, get_current_user_id() );
        return new \WP_REST_Response( [ 'archived' => $ok ], $ok ? 200 : 400 );
    }

    public static function create_definition( \WP_REST_Request $r ) {
        $name = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
        if ( $name === '' ) {
            return new \WP_Error( 'tt_missing_name', __( 'A test needs a name.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $id = ( new MeasurementDefinitionsRepository() )->create( [
            'category_id' => absint( $r['category_id'] ?? 0 ),
            'name'        => $name,
            'value_type'  => sanitize_text_field( (string) ( $r['value_type'] ?? 'numeric' ) ),
            'unit'        => sanitize_text_field( (string) ( $r['unit'] ?? '' ) ),
            'frequency'   => sanitize_text_field( (string) ( $r['frequency'] ?? 'adhoc' ) ),
            'direction'   => sanitize_text_field( (string) ( $r['direction'] ?? 'higher' ) ),
        ] );
        if ( $id <= 0 ) {
            return new \WP_Error( 'tt_insert_failed', __( 'Could not save the test.', 'talenttrack' ), [ 'status' => 500 ] );
        }
        return new \WP_REST_Response( [ 'id' => $id ], 201 );
    }

    public static function update_definition( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        $data = [];
        foreach ( [ 'category_id', 'name', 'value_type', 'unit', 'frequency', 'direction', 'is_active' ] as $k ) {
            if ( $r->has_param( $k ) ) {
                $data[ $k ] = is_string( $r[ $k ] ) ? sanitize_text_field( (string) $r[ $k ] ) : $r[ $k ];
            }
        }
        $ok = ( new MeasurementDefinitionsRepository() )->update( $id, $data );
        return new \WP_REST_Response( [ 'updated' => $ok ], $ok ? 200 : 400 );
    }

    public static function create_session( \WP_REST_Request $r ) {
        $id = ( new MeasurementSessionsRepository() )->create( [
            'definition_id' => absint( $r['definition_id'] ?? 0 ),
            'team_id'       => absint( $r['team_id'] ?? 0 ),
            'planned_date'  => sanitize_text_field( (string) ( $r['planned_date'] ?? '' ) ),
            'status'        => sanitize_text_field( (string) ( $r['status'] ?? 'planned' ) ),
            'notes'         => sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) ),
        ] );
        if ( $id <= 0 ) {
            return new \WP_Error( 'tt_insert_failed', __( 'Could not schedule the test.', 'talenttrack' ), [ 'status' => 500 ] );
        }
        return new \WP_REST_Response( [ 'id' => $id ], 201 );
    }
}
