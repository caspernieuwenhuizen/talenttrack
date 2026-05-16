<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TournamentsRestController — /wp-json/talenttrack/v1/tournaments
 *
 * #0093 chunk 2. Tournament + match + squad CRUD. Lifecycle endpoints
 * (kickoff / complete) and the planner-grid + auto-balance endpoints
 * land in later chunks.
 *
 * v1 admin-only: every permission_callback gates on
 * `tt_view_tournaments` / `tt_edit_tournaments`. Per-entity checks
 * via AuthorizationService::canViewTournament / canEditTournament
 * are wired in but currently just defer to the cap check (see those
 * methods). The plumbing is in place so the persona-expansion
 * follow-up can swap the implementation without changing call sites.
 *
 * Tenant-scoped: every query filters on `club_id = CurrentClub::id()`.
 * Writes set `club_id` from the same source.
 */
class TournamentsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $can_view = static function (): bool {
            return current_user_can( 'tt_view_tournaments' );
        };
        $can_edit = static function (): bool {
            return current_user_can( 'tt_edit_tournaments' );
        };

        // Tournament collection.
        register_rest_route( self::NS, '/tournaments', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_tournaments' ],
                'permission_callback' => $can_view,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_tournament' ],
                'permission_callback' => $can_edit,
            ],
        ] );

        // Tournament detail.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_tournament' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canViewTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_tournament' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_tournament' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Per-player rollup totals (consumed by the minutes ticker).
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/totals', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_totals' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canViewTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Matches collection (nested).
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/matches', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_match' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Match detail.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_match' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_match' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Squad bulk replace.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/squad', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'replace_squad' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Squad per-player.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/squad/(?P<player_id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_squad_member' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'remove_squad_member' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );
    }

    /**
     * GET /tournaments — paginated list. List response envelope:
     * `{ rows, total, page, per_page }`.
     */
    public static function list_tournaments( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clampPerPage( $r['per_page'] ?? 25 );

        $orderby = sanitize_key( (string) ( $r['orderby'] ?? 'start_date' ) );
        $orderby = in_array( $orderby, [ 'name', 'start_date', 'end_date', 'created_at' ], true ) ? $orderby : 'start_date';
        $order   = strtolower( (string) ( $r['order'] ?? 'desc' ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'desc';

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        $where  = [ 't.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        $status = isset( $filter['status'] ) ? sanitize_key( (string) $filter['status'] ) : 'active';
        if ( $status === 'archived' ) {
            $where[] = 't.archived_at IS NOT NULL';
        } else {
            $where[] = 't.archived_at IS NULL';
        }

        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 't.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }

        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = 't.name LIKE %s';
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = ( $page - 1 ) * $per_page;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, tm.name AS team_name
               FROM {$p}tt_tournaments t
          LEFT JOIN {$p}tt_teams tm ON tm.id = t.team_id AND tm.club_id = t.club_id
              WHERE {$where_sql}
           ORDER BY t.{$orderby} {$order}
              LIMIT %d OFFSET %d",
            ...array_merge( $params, [ $per_page, $offset ] )
        ) ) ?: [];

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_tournaments t WHERE {$where_sql}",
            ...$params
        ) );

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'fmtTournamentRow' ], $rows ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    /**
     * POST /tournaments — create. Accepts the full payload from the
     * wizard's final step (basics + formation + squad + matches) so a
     * single request hydrates the new tournament end-to-end.
     */
    public static function create_tournament( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $payload = self::extractTournament( $r );
        if ( $payload['name'] === '' ) {
            return RestResponse::error( 'name_required', __( 'Tournament name is required.', 'talenttrack' ), 422 );
        }
        if ( $payload['team_id'] <= 0 ) {
            return RestResponse::error( 'team_required', __( 'An anchor team is required.', 'talenttrack' ), 422 );
        }
        if ( $payload['start_date'] === '' ) {
            return RestResponse::error( 'start_date_required', __( 'Start date is required.', 'talenttrack' ), 422 );
        }

        $payload['club_id']    = CurrentClub::id();
        $payload['uuid']       = wp_generate_uuid4();
        $payload['created_by'] = get_current_user_id();

        $ok = $wpdb->insert( "{$p}tt_tournaments", $payload );
        if ( $ok === false ) {
            Logger::error( 'rest.tournament.create.failed', [ 'db_error' => (string) $wpdb->last_error ] );
            return RestResponse::error( 'db_error', __( 'The tournament could not be created.', 'talenttrack' ), 500, [ 'db_error' => (string) $wpdb->last_error ] );
        }
        $id = (int) $wpdb->insert_id;

        // Optional nested squad payload (wizard sends this on final step).
        $squad = is_array( $r['squad'] ?? null ) ? $r['squad'] : [];
        foreach ( $squad as $sq ) {
            self::upsertSquadRow( $id, (array) $sq );
        }

        // Optional nested matches payload.
        $matches = is_array( $r['matches'] ?? null ) ? $r['matches'] : [];
        $seq = 0;
        foreach ( $matches as $m ) {
            $seq++;
            self::insertMatch( $id, (array) $m, $seq );
        }

        do_action( 'tt_tournament_created', $id, $payload );

        $row = self::fetchTournamentRow( $id );
        return RestResponse::success( self::fmtTournamentDetail( $row ) );
    }

    /**
     * GET /tournaments/{id} — detail. Composes the tournament + its
     * matches + squad + per-player totals so a single fetch hydrates
     * the planner. Assignments are NOT included here (the planner
     * grid fetches them per-match on expand to keep the payload small).
     */
    public static function get_tournament( \WP_REST_Request $r ) {
        $row = self::fetchTournamentRow( (int) $r['id'] );
        if ( ! $row ) return RestResponse::notFound( 'tournament_not_found' );
        return RestResponse::success( self::fmtTournamentDetail( $row ) );
    }

    public static function update_tournament( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $id = (int) $r['id'];
        $existing = self::fetchTournamentRow( $id );
        if ( ! $existing ) return RestResponse::notFound( 'tournament_not_found' );

        $payload = self::extractTournament( $r );
        // Don't allow club_id / uuid / created_by mutation through the update path.
        unset( $payload['club_id'], $payload['uuid'], $payload['created_by'] );

        if ( $payload['name'] === '' ) {
            return RestResponse::error( 'name_required', __( 'Tournament name is required.', 'talenttrack' ), 422 );
        }

        $ok = $wpdb->update(
            "{$p}tt_tournaments",
            $payload,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) {
            Logger::error( 'rest.tournament.update.failed', [ 'id' => $id, 'db_error' => (string) $wpdb->last_error ] );
            return RestResponse::error( 'db_error', __( 'The tournament could not be updated.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_tournament_updated', $id, $payload );

        $row = self::fetchTournamentRow( $id );
        return RestResponse::success( self::fmtTournamentDetail( $row ) );
    }

    public static function delete_tournament( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $id = (int) $r['id'];

        $existing = self::fetchTournamentRow( $id );
        if ( ! $existing ) return RestResponse::notFound( 'tournament_not_found' );

        $ok = $wpdb->update(
            "{$p}tt_tournaments",
            [
                'archived_at' => current_time( 'mysql' ),
                'archived_by' => get_current_user_id(),
            ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) {
            Logger::error( 'rest.tournament.delete.failed', [ 'id' => $id, 'db_error' => (string) $wpdb->last_error ] );
            return RestResponse::error( 'db_error', __( 'The tournament could not be archived.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_tournament_archived', $id );
        return RestResponse::success( [ 'archived' => true, 'id' => $id ] );
    }

    /**
     * GET /tournaments/{id}/totals — per-player rollup for the minutes
     * ticker. Returns target, expected (sum of scheduled minutes
     * across un-completed matches), played (sum across completed
     * matches), starts, full_matches.
     */
    public static function get_totals( \WP_REST_Request $r ) {
        $id  = (int) $r['id'];
        $row = self::fetchTournamentRow( $id );
        if ( ! $row ) return RestResponse::notFound( 'tournament_not_found' );

        $totals = self::computeTotals( $id );
        return RestResponse::success( [
            'tournament_id' => $id,
            'players'       => $totals,
        ] );
    }

    public static function create_match( \WP_REST_Request $r ) {
        $tournament_id = (int) $r['id'];
        $existing = self::fetchTournamentRow( $tournament_id );
        if ( ! $existing ) return RestResponse::notFound( 'tournament_not_found' );

        global $wpdb; $p = $wpdb->prefix;
        $next_seq = 1 + (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sequence), 0) FROM {$p}tt_tournament_matches WHERE tournament_id = %d AND club_id = %d",
            $tournament_id, CurrentClub::id()
        ) );

        $match_id = self::insertMatch( $tournament_id, (array) $r->get_params(), $next_seq );
        if ( $match_id === 0 ) {
            return RestResponse::error( 'db_error', __( 'The match could not be created.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_tournament_match_created', $tournament_id, $match_id );
        return RestResponse::success( self::fetchMatch( $match_id ) );
    }

    public static function update_match( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $tournament_id = (int) $r['id'];
        $match_id      = (int) $r['match_id'];

        $existing = self::fetchMatch( $match_id );
        if ( ! $existing || (int) $existing['tournament_id'] !== $tournament_id ) {
            return RestResponse::notFound( 'match_not_found' );
        }

        $payload = self::extractMatch( (array) $r->get_params() );
        // Cannot mutate the tournament_id / activity_id through this path.
        unset( $payload['tournament_id'], $payload['club_id'], $payload['activity_id'] );

        $ok = $wpdb->update(
            "{$p}tt_tournament_matches",
            $payload,
            [ 'id' => $match_id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) {
            Logger::error( 'rest.tournament_match.update.failed', [ 'id' => $match_id, 'db_error' => (string) $wpdb->last_error ] );
            return RestResponse::error( 'db_error', __( 'The match could not be updated.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_tournament_match_updated', $tournament_id, $match_id );
        return RestResponse::success( self::fetchMatch( $match_id ) );
    }

    public static function delete_match( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $tournament_id = (int) $r['id'];
        $match_id      = (int) $r['match_id'];

        $existing = self::fetchMatch( $match_id );
        if ( ! $existing || (int) $existing['tournament_id'] !== $tournament_id ) {
            return RestResponse::notFound( 'match_not_found' );
        }

        // Hard-delete a match wipes its assignments. Bench rows + slot
        // rows go together; FK enforcement is application-level here.
        $wpdb->delete( "{$p}tt_tournament_assignments", [ 'match_id' => $match_id, 'club_id' => CurrentClub::id() ] );
        $ok = $wpdb->delete( "{$p}tt_tournament_matches", [ 'id' => $match_id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            return RestResponse::error( 'db_error', __( 'The match could not be deleted.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_tournament_match_deleted', $tournament_id, $match_id );
        return RestResponse::success( [ 'deleted' => true, 'id' => $match_id ] );
    }

    /**
     * PATCH /tournaments/{id}/squad — bulk replace the squad. Payload
     * shape: `{ squad: [ { player_id, eligible_positions, target_minutes }, ... ] }`.
     * Wipes existing squad rows (and dependent assignments) then re-inserts.
     */
    public static function replace_squad( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $tournament_id = (int) $r['id'];

        $existing = self::fetchTournamentRow( $tournament_id );
        if ( ! $existing ) return RestResponse::notFound( 'tournament_not_found' );

        $squad = is_array( $r['squad'] ?? null ) ? $r['squad'] : [];
        if ( ! is_array( $squad ) ) {
            return RestResponse::error( 'invalid_payload', __( 'Squad payload must be an array.', 'talenttrack' ), 422 );
        }

        // Wipe assignments for every match in the tournament — squad
        // changes invalidate the existing plan.
        $wpdb->query( $wpdb->prepare(
            "DELETE a FROM {$p}tt_tournament_assignments a
              INNER JOIN {$p}tt_tournament_matches m ON m.id = a.match_id
              WHERE m.tournament_id = %d AND a.club_id = %d",
            $tournament_id, CurrentClub::id()
        ) );
        $wpdb->delete( "{$p}tt_tournament_squad", [ 'tournament_id' => $tournament_id, 'club_id' => CurrentClub::id() ] );

        foreach ( $squad as $sq ) {
            self::upsertSquadRow( $tournament_id, (array) $sq );
        }
        do_action( 'tt_tournament_squad_replaced', $tournament_id );
        return RestResponse::success( self::fmtTournamentDetail( self::fetchTournamentRow( $tournament_id ) ) );
    }

    public static function update_squad_member( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $tournament_id = (int) $r['id'];
        $player_id     = (int) $r['player_id'];

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_tournament_squad WHERE tournament_id = %d AND player_id = %d AND club_id = %d",
            $tournament_id, $player_id, CurrentClub::id()
        ) );

        $payload = [
            'eligible_positions' => self::normalisePositionsJson( $r['eligible_positions'] ?? null ),
            'target_minutes'     => isset( $r['target_minutes'] ) && $r['target_minutes'] !== '' ? absint( $r['target_minutes'] ) : null,
            'notes'              => isset( $r['notes'] ) ? sanitize_text_field( (string) $r['notes'] ) : null,
        ];

        if ( $existing ) {
            $wpdb->update(
                "{$p}tt_tournament_squad",
                $payload,
                [ 'tournament_id' => $tournament_id, 'player_id' => $player_id, 'club_id' => CurrentClub::id() ]
            );
        } else {
            self::upsertSquadRow( $tournament_id, array_merge( [ 'player_id' => $player_id ], $payload ) );
        }
        do_action( 'tt_tournament_squad_member_updated', $tournament_id, $player_id );
        return RestResponse::success( self::fmtTournamentDetail( self::fetchTournamentRow( $tournament_id ) ) );
    }

    public static function remove_squad_member( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $tournament_id = (int) $r['id'];
        $player_id     = (int) $r['player_id'];

        // Wipe the player's assignments across every match in this tournament.
        $wpdb->query( $wpdb->prepare(
            "DELETE a FROM {$p}tt_tournament_assignments a
              INNER JOIN {$p}tt_tournament_matches m ON m.id = a.match_id
              WHERE m.tournament_id = %d AND a.player_id = %d AND a.club_id = %d",
            $tournament_id, $player_id, CurrentClub::id()
        ) );
        $ok = $wpdb->delete(
            "{$p}tt_tournament_squad",
            [ 'tournament_id' => $tournament_id, 'player_id' => $player_id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) {
            return RestResponse::error( 'db_error', __( 'The squad member could not be removed.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_tournament_squad_member_removed', $tournament_id, $player_id );
        return RestResponse::success( [ 'removed' => true, 'player_id' => $player_id ] );
    }

    // ---- helpers ------------------------------------------------------------

    private static function clampPerPage( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    /**
     * Fetch a tournament row + its team name. Returns null when the
     * tournament doesn't exist on the current club.
     *
     * @return object|null
     */
    private static function fetchTournamentRow( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*, tm.name AS team_name
               FROM {$p}tt_tournaments t
          LEFT JOIN {$p}tt_teams tm ON tm.id = t.team_id AND tm.club_id = t.club_id
              WHERE t.id = %d AND t.club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Sanitise + cast the inbound tournament payload. Subset used by
     * both create (full payload + uuid/created_by stamps) and update
     * (subset of mutable fields).
     */
    private static function extractTournament( \WP_REST_Request $r ): array {
        $start = sanitize_text_field( (string) ( $r['start_date'] ?? '' ) );
        $end   = sanitize_text_field( (string) ( $r['end_date'] ?? '' ) );
        return [
            'name'              => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
            'start_date'        => $start !== '' ? $start : null,
            'end_date'          => $end !== '' ? $end : null,
            'default_formation' => isset( $r['default_formation'] ) ? sanitize_text_field( (string) $r['default_formation'] ) : null,
            'team_id'           => absint( $r['team_id'] ?? 0 ),
            'notes'             => isset( $r['notes'] ) ? sanitize_textarea_field( (string) $r['notes'] ) : null,
        ];
    }

    /**
     * Sanitise + cast the inbound match payload.
     */
    private static function extractMatch( array $r ): array {
        $duration = isset( $r['duration_min'] ) ? max( 1, absint( $r['duration_min'] ) ) : 20;
        $windows  = self::normaliseWindowsJson( $r['substitution_windows'] ?? null, $duration );
        $scheduled = sanitize_text_field( (string) ( $r['scheduled_at'] ?? '' ) );
        return [
            'label'                => isset( $r['label'] ) ? sanitize_text_field( (string) $r['label'] ) : null,
            'opponent_name'        => isset( $r['opponent_name'] ) ? sanitize_text_field( (string) $r['opponent_name'] ) : null,
            'opponent_level'       => isset( $r['opponent_level'] ) ? sanitize_text_field( (string) $r['opponent_level'] ) : null,
            'formation'            => isset( $r['formation'] ) ? sanitize_text_field( (string) $r['formation'] ) : null,
            'duration_min'         => $duration,
            'substitution_windows' => $windows,
            'scheduled_at'         => $scheduled !== '' ? $scheduled : null,
            'notes'                => isset( $r['notes'] ) ? sanitize_textarea_field( (string) $r['notes'] ) : null,
        ];
    }

    /**
     * Normalise the substitution_windows payload to a sorted integer
     * JSON array. Drops windows that don't fall inside (0, duration_min).
     * Empty array is valid (= no substitutions, one period of duration_min).
     */
    private static function normaliseWindowsJson( $raw, int $duration_min ): string {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) $raw = $decoded;
        }
        if ( ! is_array( $raw ) ) return wp_json_encode( [] );
        $windows = array_values( array_unique( array_filter(
            array_map( 'absint', $raw ),
            function ( $w ) use ( $duration_min ) {
                return $w > 0 && $w < $duration_min;
            }
        ) ) );
        sort( $windows );
        return wp_json_encode( $windows );
    }

    /**
     * Normalise the eligible_positions payload to a JSON array of
     * position-type strings (GK/DEF/MID/FWD). Unknown tokens drop.
     */
    private static function normalisePositionsJson( $raw ): string {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) $raw = $decoded;
        }
        if ( ! is_array( $raw ) ) return wp_json_encode( [] );
        $allowed = [ 'GK', 'DEF', 'MID', 'FWD' ];
        $positions = array_values( array_intersect(
            $allowed,
            array_map( static function ( $v ) { return strtoupper( sanitize_key( (string) $v ) ); }, $raw )
        ) );
        return wp_json_encode( $positions );
    }

    private static function insertMatch( int $tournament_id, array $payload, int $sequence ): int {
        global $wpdb; $p = $wpdb->prefix;
        $data = self::extractMatch( $payload );
        $data['tournament_id'] = $tournament_id;
        $data['club_id']       = CurrentClub::id();
        $data['sequence']      = $sequence;
        $ok = $wpdb->insert( "{$p}tt_tournament_matches", $data );
        if ( $ok === false ) {
            Logger::error( 'rest.tournament_match.create.failed', [ 'db_error' => (string) $wpdb->last_error, 'tournament_id' => $tournament_id ] );
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    private static function upsertSquadRow( int $tournament_id, array $sq ): void {
        global $wpdb; $p = $wpdb->prefix;
        $player_id = absint( $sq['player_id'] ?? 0 );
        if ( $player_id <= 0 ) return;
        $data = [
            'tournament_id'      => $tournament_id,
            'player_id'          => $player_id,
            'club_id'            => CurrentClub::id(),
            'eligible_positions' => self::normalisePositionsJson( $sq['eligible_positions'] ?? null ),
            'target_minutes'     => isset( $sq['target_minutes'] ) && $sq['target_minutes'] !== '' && $sq['target_minutes'] !== null ? absint( $sq['target_minutes'] ) : null,
            'notes'              => isset( $sq['notes'] ) ? sanitize_text_field( (string) $sq['notes'] ) : null,
        ];
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}tt_tournament_squad WHERE tournament_id = %d AND player_id = %d AND club_id = %d",
            $tournament_id, $player_id, CurrentClub::id()
        ) );
        if ( $existing ) {
            unset( $data['tournament_id'], $data['player_id'] );
            $wpdb->update( "{$p}tt_tournament_squad", $data, [
                'tournament_id' => $tournament_id,
                'player_id'     => $player_id,
                'club_id'       => CurrentClub::id(),
            ] );
        } else {
            $wpdb->insert( "{$p}tt_tournament_squad", $data );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function fetchMatch( int $match_id ): ?array {
        if ( $match_id <= 0 ) return null;
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_tournament_matches WHERE id = %d AND club_id = %d",
            $match_id, CurrentClub::id()
        ), ARRAY_A );
        if ( ! $row ) return null;
        return self::fmtMatchRow( $row );
    }

    /**
     * Fetch all match rows for a tournament, in sequence order.
     * @return array<int, array<string,mixed>>
     */
    private static function fetchMatches( int $tournament_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_tournament_matches WHERE tournament_id = %d AND club_id = %d ORDER BY sequence ASC",
            $tournament_id, CurrentClub::id()
        ), ARRAY_A ) ?: [];
        return array_map( [ __CLASS__, 'fmtMatchRow' ], $rows );
    }

    /**
     * Fetch the full squad with each player's name pre-joined, so the
     * planner can render the ticker without a second roundtrip.
     * @return array<int, array<string,mixed>>
     */
    private static function fetchSquad( int $tournament_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, pl.first_name, pl.last_name, pl.photo_url
               FROM {$p}tt_tournament_squad s
               JOIN {$p}tt_players pl ON pl.id = s.player_id AND pl.club_id = s.club_id
              WHERE s.tournament_id = %d AND s.club_id = %d
           ORDER BY pl.last_name ASC, pl.first_name ASC",
            $tournament_id, CurrentClub::id()
        ), ARRAY_A ) ?: [];
        return array_map( static function ( $row ) {
            return [
                'player_id'          => (int) $row['player_id'],
                'first_name'         => (string) $row['first_name'],
                'last_name'          => (string) $row['last_name'],
                'full_name'          => trim( ( (string) $row['first_name'] ) . ' ' . ( (string) $row['last_name'] ) ),
                'photo_url'          => (string) ( $row['photo_url'] ?? '' ),
                'eligible_positions' => json_decode( (string) $row['eligible_positions'], true ) ?: [],
                'target_minutes'     => $row['target_minutes'] !== null ? (int) $row['target_minutes'] : null,
                'notes'              => (string) ( $row['notes'] ?? '' ),
            ];
        }, $rows );
    }

    /**
     * Compute per-player rollup totals used by the minutes ticker.
     * Played = minutes in completed matches. Expected = minutes in
     * un-completed matches. Target = equal-share default OR the per-
     * player target_minutes override on the squad row.
     *
     * Period minute math derives from the match's substitution_windows
     * array on the same even-split assumption the planner uses:
     * `minutes_per_period = duration_min / (windows + 1)`.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function computeTotals( int $tournament_id ): array {
        global $wpdb; $p = $wpdb->prefix;

        $squad = self::fetchSquad( $tournament_id );
        if ( ! $squad ) return [];

        $matches = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, duration_min, substitution_windows, completed_at
               FROM {$p}tt_tournament_matches
              WHERE tournament_id = %d AND club_id = %d",
            $tournament_id, CurrentClub::id()
        ), ARRAY_A ) ?: [];

        // Pre-compute period count + minutes-per-period per match.
        $match_meta = [];
        $total_field_minutes = 0;        // = sum of duration_min × players_on_pitch_per_period × period_count? — see below
        $total_match_minutes = 0;        // sum of duration_min — used for the equal-share target
        foreach ( $matches as $m ) {
            $duration = (int) $m['duration_min'];
            $windows  = json_decode( (string) $m['substitution_windows'], true ) ?: [];
            $periods  = count( $windows ) + 1;
            $per_period = $periods > 0 ? (int) round( $duration / $periods ) : $duration;
            $match_meta[ (int) $m['id'] ] = [
                'duration'   => $duration,
                'periods'    => $periods,
                'per_period' => $per_period,
                'completed'  => ! empty( $m['completed_at'] ),
            ];
            $total_match_minutes += $duration;
        }

        // Per-player aggregates from tt_tournament_assignments.
        $assignment_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.player_id, a.match_id, a.period_index, a.position_code
               FROM {$p}tt_tournament_assignments a
               JOIN {$p}tt_tournament_matches m ON m.id = a.match_id
              WHERE m.tournament_id = %d AND a.club_id = %d",
            $tournament_id, CurrentClub::id()
        ), ARRAY_A ) ?: [];

        $per_player = [];
        // Initialise from squad.
        $squad_size = max( 1, count( $squad ) );
        // Equal-share target: total match minutes × on-pitch slots / squad size.
        // For v1 we use a simpler proxy — total match minutes — as the
        // ceiling. Coaches read this as "if you played every minute of
        // every match, you'd hit this." Under-served if expected/played
        // is much less than this. The planner will refine when slot
        // counts per match are available (chunk 5).
        $default_target = (int) round( $total_match_minutes );
        foreach ( $squad as $sq ) {
            $pid = (int) $sq['player_id'];
            $per_player[ $pid ] = [
                'player_id'         => $pid,
                'first_name'        => $sq['first_name'],
                'last_name'         => $sq['last_name'],
                'full_name'         => $sq['full_name'],
                'photo_url'         => $sq['photo_url'],
                'eligible_positions'=> $sq['eligible_positions'],
                'target_minutes'    => $sq['target_minutes'] ?? $default_target,
                'played_minutes'    => 0,
                'expected_minutes'  => 0,
                'starts'            => 0,
                'full_matches'      => 0,
                // Internal accumulator for full_matches derivation.
                '_periods_played'   => [],
            ];
        }

        foreach ( $assignment_rows as $a ) {
            $pid = (int) $a['player_id'];
            if ( ! isset( $per_player[ $pid ] ) ) continue;
            $match_id = (int) $a['match_id'];
            $meta     = $match_meta[ $match_id ] ?? null;
            if ( ! $meta ) continue;
            if ( (string) $a['position_code'] === 'BENCH' ) continue;
            $minutes = $meta['per_period'];
            if ( $meta['completed'] ) {
                $per_player[ $pid ]['played_minutes'] += $minutes;
            } else {
                $per_player[ $pid ]['expected_minutes'] += $minutes;
            }
            if ( (int) $a['period_index'] === 0 ) {
                $per_player[ $pid ]['starts']++;
            }
            $per_player[ $pid ]['_periods_played'][ $match_id ][] = (int) $a['period_index'];
        }

        // Derive full_matches: player has a non-bench assignment in
        // every period of a match.
        foreach ( $per_player as $pid => &$stats ) {
            $full = 0;
            foreach ( $stats['_periods_played'] as $match_id => $periods ) {
                $meta = $match_meta[ $match_id ] ?? null;
                if ( ! $meta ) continue;
                if ( count( array_unique( $periods ) ) === $meta['periods'] ) {
                    $full++;
                }
            }
            $stats['full_matches'] = $full;
            unset( $stats['_periods_played'] );
        }
        unset( $stats );

        return array_values( $per_player );
    }

    /**
     * Compact row format for the list response.
     */
    private static function fmtTournamentRow( object $row ): array {
        $detail_url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'tournaments', (int) $row->id );
        return [
            'id'                => (int) $row->id,
            'uuid'              => (string) $row->uuid,
            'name'              => (string) $row->name,
            'start_date'        => $row->start_date,
            'end_date'          => $row->end_date,
            'default_formation' => (string) ( $row->default_formation ?? '' ),
            'team_id'           => (int) $row->team_id,
            'team_name'         => (string) ( $row->team_name ?? '' ),
            'created_at'        => $row->created_at,
            'archived_at'       => $row->archived_at,
            'detail_url'        => $detail_url,
        ];
    }

    /**
     * Detail-format with matches + squad + totals composed in.
     */
    private static function fmtTournamentDetail( ?object $row ): array {
        if ( ! $row ) return [];
        $base = self::fmtTournamentRow( $row );
        $tid  = (int) $row->id;
        return array_merge( $base, [
            'notes'   => (string) ( $row->notes ?? '' ),
            'matches' => self::fetchMatches( $tid ),
            'squad'   => self::fetchSquad( $tid ),
            'totals'  => self::computeTotals( $tid ),
        ] );
    }

    /**
     * Compact row format for a match.
     */
    private static function fmtMatchRow( array $row ): array {
        return [
            'id'                   => (int) $row['id'],
            'tournament_id'        => (int) $row['tournament_id'],
            'sequence'             => (int) $row['sequence'],
            'label'                => (string) ( $row['label'] ?? '' ),
            'opponent_name'        => (string) ( $row['opponent_name'] ?? '' ),
            'opponent_level'       => (string) ( $row['opponent_level'] ?? '' ),
            'formation'            => (string) ( $row['formation'] ?? '' ),
            'duration_min'         => (int) $row['duration_min'],
            'substitution_windows' => json_decode( (string) $row['substitution_windows'], true ) ?: [],
            'scheduled_at'         => $row['scheduled_at'],
            'kicked_off_at'        => $row['kicked_off_at'],
            'completed_at'         => $row['completed_at'],
            'activity_id'          => $row['activity_id'] !== null ? (int) $row['activity_id'] : null,
            'notes'                => (string) ( $row['notes'] ?? '' ),
        ];
    }
}
