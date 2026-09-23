<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Domain\Vocabularies\Lookups\TournamentOpponentLevel;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\AttendanceWriter;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Tournaments\Services\TournamentMinutesCalculator;
use TT\Modules\Tournaments\TournamentAccess;

/**
 * TournamentsRestController — /wp-json/talenttrack/v1/tournaments
 *
 * #0093 chunk 2. Tournament + match + squad CRUD. Lifecycle endpoints
 * (kickoff / complete) and the planner-grid + auto-balance endpoints
 * land in later chunks.
 *
 * #3703 — team-scoped, not admin-only. Every `{id}`-bearing route asks
 * `AuthorizationService::canViewTournament` / `canEditTournament` /
 * `canDeleteTournament`, which resolve the tournament's participating
 * teams against the caller's `tournaments` matrix grants. The collection
 * routes have no id to resolve, so they ask the same entity for "do you
 * hold this anywhere" and the list narrows its own rows below.
 *
 * Tenant-scoped: every query filters on `club_id = CurrentClub::id()`.
 * Writes set `club_id` from the same source.
 */
class TournamentsRestController {

    const NS = 'talenttrack/v1';

    /**
     * #3105 — `tournaments` is a Pro feature. Every route is wrapped;
     * `enforceWriteRest()` decides from the verb, so the lists, the detail
     * reads, the totals and the planner read pass through and the writes
     * answer 402. A club that drops off Pro keeps every tournament it ran
     * and cannot start another (#3017's third decision).
     *
     * The feature key is a literal, not a constant, so
     * `FeatureMapGateCoverageTest` can find it.
     */
    private static function gate( callable $callback ): \Closure {
        return static function ( \WP_REST_Request $r ) use ( $callback ) {
            $blocked = \TT\Modules\License\LicenseGate::enforceWriteRest( 'tournaments', $r );
            return $blocked ?? $callback( $r );
        };
    }

    /**
     * Auto-balance is sold separately from tournaments — the finer-grained
     * case #3105 calls out. A Standard club runs its tournament and plans
     * the grid by hand; the button that fills it for them is the Pro half.
     * So this gate is `enforceFeatureRest()`, not the write-verb helper:
     * there is no auto-balance record to keep readable, only an action.
     */
    private static function gateAutoBalance( callable $callback ): \Closure {
        return static function ( \WP_REST_Request $r ) use ( $callback ) {
            $blocked = \TT\Modules\License\LicenseGate::enforceFeatureRest( 'tournaments_auto_balance' );
            return $blocked ?? $callback( $r );
        };
    }

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    /**
     * #3703 — the delete gate, which has two different refusals to give.
     *
     * "You cannot delete tournaments" and "you can, but not this one,
     * because it belongs to squads that are not yours" are the same 403
     * to a client and entirely different facts to a coach. The second
     * gets its own message, because a coach who holds delete on their own
     * team and is refused without explanation has no way to tell the
     * difference from a bug.
     *
     * @return true|\WP_Error
     */
    public static function deleteGate( int $tournament_id ) {
        $user_id = get_current_user_id();
        if ( AuthorizationService::canDeleteTournament( $user_id, $tournament_id ) ) {
            return true;
        }

        if ( TournamentAccess::spansTeamsOutsideScope( $user_id, $tournament_id ) ) {
            return new \WP_Error(
                'tournament_spans_other_teams',
                __( 'This tournament includes teams you don’t manage, so it can’t be deleted from here.', 'talenttrack' ),
                [ 'status' => 403 ]
            );
        }

        return new \WP_Error(
            'rest_forbidden',
            __( 'You do not have permission to delete this tournament.', 'talenttrack' ),
            [ 'status' => 403 ]
        );
    }

    public static function register(): void {
        // #3703 — the collection routes carry no tournament id, so they
        // ask the entity rather than a record: may you read tournaments
        // at all, may you create one on the team you are posting. The
        // list narrows its own rows in `list_tournaments()`.
        $can_view = static function (): bool {
            return TournamentAccess::canAnywhere( get_current_user_id(), MatrixGate::READ );
        };
        $can_create = static function ( \WP_REST_Request $r ): bool {
            return TournamentAccess::canCreateForTeam(
                get_current_user_id(),
                absint( $r['team_id'] ?? 0 )
            );
        };

        // Tournament collection.
        register_rest_route( self::NS, '/tournaments', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'list_tournaments' ] ),
                'permission_callback' => $can_view,
            ],
            [
                'methods'             => 'POST',
                'args'                => self::createArgs(),
                'callback'            => self::gate( [ __CLASS__, 'create_tournament' ] ),
                'permission_callback' => $can_create,
            ],
        ] );

        // Tournament detail.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'get_tournament' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canViewTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'PUT',
                'args'                => self::updateArgs(),
                'callback'            => self::gate( [ __CLASS__, 'update_tournament' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'delete_tournament' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return self::deleteGate( (int) $r['id'] );
                },
            ],
        ] );

        // #1784 — restore an archived tournament + referential-integrity
        // permanent delete (the DELETE above only archives).
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/restore', [
            [
                'methods'             => 'POST',
                'args'                => self::tournamentIdArgs(),
                'callback'            => self::gate( [ __CLASS__, 'restore_tournament' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament( get_current_user_id(), (int) $r['id'] );
                },
            ],
        ] );
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'delete_tournament_permanently' ] ),
                // #2024 security #6 — re-gate onto tt_manage_recycle_bin: no
                // purge path weaker than the bin's own purge.
                'permission_callback' => function () {
                    return current_user_can( 'tt_manage_recycle_bin' );
                },
            ],
        ] );
        // #2023 — reversible "Move to recycle bin" (archived → trashed).
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/trash', [
            [
                'methods'             => 'POST',
                'args'                => self::tournamentIdArgs(),
                'callback'            => self::gate( [ __CLASS__, 'trash_tournament' ] ),
                'permission_callback' => function () {
                    return current_user_can( 'tt_edit_settings' );
                },
            ],
        ] );

        // Per-player rollup totals (consumed by the minutes ticker).
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/totals', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'get_totals' ] ),
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
                'args'                => self::matchArgs(),
                'callback'            => self::gate( [ __CLASS__, 'create_match' ] ),
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
                'args'                => self::matchUpdateArgs(),
                'callback'            => self::gate( [ __CLASS__, 'update_match' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'delete_match' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Planner hydrate — single fetch returning everything the
        // grid needs (formation slot_labels + squad + current
        // assignments).
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/planner', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'get_planner' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canViewTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Kick off — creates the linked tt_activities row of type
        // 'match', sets kicked_off_at on the tournament match.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/kickoff', [
            [
                'methods'             => 'POST',
                'args'                => self::matchIdArgs(),
                'callback'            => self::gate( [ __CLASS__, 'kickoff_match' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Complete — sets completed_at, locks lineup (override via
        // PATCH assignments?force=1), syncs starts to tt_attendance.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/complete', [
            [
                'methods'             => 'POST',
                'args'                => self::matchIdArgs(),
                'callback'            => self::gate( [ __CLASS__, 'complete_match' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
        ] );

        // Greedy auto-balance — wipes the match's assignments and
        // fills the grid based on eligibility + equal-share + starts
        // distribution + no-back-to-back-bench heuristic.
        //
        // #1979 — gated by the `tournaments_auto_balance` feature flag
        // AND the edit-tournament capability. When the feature is off the
        // route returns 403; manual click-to-swap planning (the
        // assignments PATCH below) is untouched.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/auto-plan', [
            [
                'methods'             => 'POST',
                'args'                => self::matchIdArgs(),
                'callback'            => self::gateAutoBalance( [ __CLASS__, 'auto_plan' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return \TT\Core\FeatureRegistry::isEnabled( 'tournaments_auto_balance' )
                        && AuthorizationService::canEditTournament(
                            get_current_user_id(),
                            (int) $r['id']
                        );
                },
            ],
        ] );

        // Planner write — bulk-replace assignments for one match.
        // Click-to-swap on the grid PATCHes the full new state here
        // each interaction. Idempotent.
        register_rest_route( self::NS, '/tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/assignments', [
            [
                'methods'             => 'PATCH',
                'args'                => self::assignmentsArgs(),
                'callback'            => self::gate( [ __CLASS__, 'update_assignments' ] ),
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
                'args'                => self::squadArgs(),
                'callback'            => self::gate( [ __CLASS__, 'replace_squad' ] ),
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
                'args'                => self::squadMemberArgs(),
                'callback'            => self::gate( [ __CLASS__, 'update_squad_member' ] ),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditTournament(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'remove_squad_member' ] ),
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

        // #2023 — through filterClause (alias 't') so archived/active views
        // also exclude trashed (recycle-bin) rows.
        // #2625 — `filter[archived]` is canonical; `filter[status]` is a
        // deprecated alias kept for one release. Inline by design: `status`
        // means domain status on other resources.
        $raw     = $filter['archived'] ?? ( $filter['status'] ?? null );
        $status  = $raw !== null ? sanitize_key( (string) $raw ) : 'active';
        $where[] = \TT\Infrastructure\Archive\ArchiveRepository::filterClause(
            $status === 'archived' ? 'archived' : 'active',
            't'
        );

        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 't.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }

        // #3703 — narrow to the caller's own teams unless they hold the
        // club-wide read. In SQL, not after the fact: a team-scoped coach
        // paging through the list must never be handed another age group's
        // squad and then have it hidden. A tournament counts as theirs
        // when they hold its anchor team OR any team its squad is drawn
        // from, the same set `TournamentAccess` decides a single record on.
        if ( ! TournamentAccess::hasGlobal( get_current_user_id(), MatrixGate::READ ) ) {
            $scope_ids = TournamentAccess::readableTeamIds( get_current_user_id() );
            if ( $scope_ids === [] ) {
                $where[] = '1 = 0';
            } else {
                $placeholders = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
                $where[] = "( t.team_id IN ({$placeholders})"
                    . " OR EXISTS ( SELECT 1 FROM {$p}tt_tournament_squad s"
                    . " INNER JOIN {$p}tt_players pl ON pl.id = s.player_id"
                    . " WHERE s.tournament_id = t.id AND pl.team_id IN ({$placeholders}) ) )";
                $params = array_merge( $params, $scope_ids, $scope_ids );
            }
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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::createArgs() );
        if ( $refused !== null ) return $refused;

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

        // #4020 — the formation and the squad's position codes, checked
        // before the row is written for the same reason the levels are.
        $bad_formation = self::rejectUnknownFormation( (array) $r->get_params(), 'default_formation' );
        if ( $bad_formation !== null ) return $bad_formation;

        $bad_positions = self::rejectUnknownPositionsInSquad( $r['squad'] ?? null );
        if ( $bad_positions !== null ) return $bad_positions;

        // #3559 — the nested fixtures are checked before the tournament
        // row is written, so a bad level refuses the request rather than
        // leaving a tournament behind with some of its matches missing.
        foreach ( ( is_array( $r['matches'] ?? null ) ? $r['matches'] : [] ) as $nested ) {
            $bad_level = self::rejectUnknownOpponentLevel( (array) $nested );
            if ( $bad_level !== null ) return $bad_level;
            $bad_formation = self::rejectUnknownFormation( (array) $nested, 'formation' );
            if ( $bad_formation !== null ) return $bad_formation;
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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::updateArgs() );
        if ( $refused !== null ) return $refused;

        global $wpdb; $p = $wpdb->prefix;
        $id = (int) $r['id'];
        $existing = self::fetchTournamentRow( $id );
        if ( ! $existing ) return RestResponse::notFound( 'tournament_not_found' );

        // #4020 — a formation the planner cannot use is refused here too, or
        // the edit form becomes the back door round the create check.
        $bad_formation = self::rejectUnknownFormation( (array) $r->get_params(), 'default_formation' );
        if ( $bad_formation !== null ) return $bad_formation;

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

    /** #1784 — restore an archived tournament. */
    public static function restore_tournament( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::tournamentIdArgs() );
        if ( $refused !== null ) return $refused;

        $id = (int) $r['id'];
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid tournament id.', 'talenttrack' ), 400 );
        $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->restore( 'tournament', [ $id ] );
        if ( $n === 0 ) return RestResponse::notFound( 'tournament_not_found' );
        return RestResponse::success( [ 'restored' => true, 'id' => $id ] );
    }

    /**
     * #1784 — permanently delete a tournament (irreversible). Routes
     * through the referential-integrity cascade (matches, squad, per-match
     * assignments removed; a linked activity's tournament link cleared);
     * fail-closed if anything undeclared still references it. Gated by
     * tt_edit_settings.
     */
    public static function delete_tournament_permanently( \WP_REST_Request $r ) {
        $id = (int) $r['id'];
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid tournament id.', 'talenttrack' ), 400 );
        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'tournament', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) return RestResponse::notFound( 'tournament_not_found' );
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    /** #2023 — move an archived tournament into the recycle bin (reversible). */
    public static function trash_tournament( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::tournamentIdArgs() );
        if ( $refused !== null ) return $refused;

        return \TT\Infrastructure\Archive\RecycleBinRestActions::trash(
            'tournament', (int) $r['id'], __( 'Tournament not found.', 'talenttrack' )
        );
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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::matchArgs() );
        if ( $refused !== null ) return $refused;

        $tournament_id = (int) $r['id'];
        $existing = self::fetchTournamentRow( $tournament_id );
        if ( ! $existing ) return RestResponse::notFound( 'tournament_not_found' );

        global $wpdb; $p = $wpdb->prefix;
        $next_seq = 1 + (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sequence), 0) FROM {$p}tt_tournament_matches WHERE tournament_id = %d AND club_id = %d",
            $tournament_id, CurrentClub::id()
        ) );

        $bad_level = self::rejectUnknownOpponentLevel( (array) $r->get_params() );
        if ( $bad_level !== null ) return $bad_level;

        // #4020 — a fixture formation the planner cannot resolve.
        $bad_formation = self::rejectUnknownFormation( (array) $r->get_params(), 'formation' );
        if ( $bad_formation !== null ) return $bad_formation;

        $match_id = self::insertMatch( $tournament_id, (array) $r->get_params(), $next_seq );
        if ( $match_id === 0 ) {
            return RestResponse::error( 'db_error', __( 'The match could not be created.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_tournament_match_created', $tournament_id, $match_id );
        return RestResponse::success( self::fetchMatch( $match_id ) );
    }

    /**
     * GET /tournaments/{id}/matches/{m_id}/planner — single fetch
     * bundle for the planner grid. Returns the formation's slot
     * labels (so the JS doesn't need to round-trip the lookup table),
     * the current squad, and the existing assignments for this match.
     */
    public static function get_planner( \WP_REST_Request $r ) {
        $tournament_id = (int) $r['id'];
        $match_id      = (int) $r['match_id'];

        $tournament = self::fetchTournamentRow( $tournament_id );
        if ( ! $tournament ) return RestResponse::notFound( 'tournament_not_found' );

        $match = self::fetchMatch( $match_id );
        if ( ! $match || (int) $match['tournament_id'] !== $tournament_id ) {
            return RestResponse::notFound( 'match_not_found' );
        }

        $effective_formation = $match['formation'] !== ''
            ? $match['formation']
            : (string) ( $tournament->default_formation ?? '' );

        $slot_labels = self::lookupSlotLabels( $effective_formation );

        global $wpdb; $p = $wpdb->prefix;
        $assignments = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, period_index, player_id, position_code
               FROM {$p}tt_tournament_assignments
              WHERE match_id = %d AND club_id = %d",
            $match_id, CurrentClub::id()
        ), ARRAY_A ) ?: [];

        return RestResponse::success( [
            'match'        => $match,
            'formation'    => [
                'name'        => $effective_formation,
                'slot_labels' => $slot_labels,
            ],
            'squad'        => self::fetchSquad( $tournament_id ),
            'periods'      => count( $match['substitution_windows'] ) + 1,
            'minutes_per_period' => self::minutesPerPeriod( (int) $match['duration_min'], count( $match['substitution_windows'] ) ),
            'assignments'  => array_map( static function ( $a ) {
                return [
                    'period_index'  => (int) $a['period_index'],
                    'player_id'     => (int) $a['player_id'],
                    'position_code' => (string) $a['position_code'],
                ];
            }, $assignments ),
        ] );
    }

    /**
     * PATCH /tournaments/{id}/matches/{m_id}/assignments — wipe and
     * replace the assignments for one match. Payload shape:
     *
     *   { assignments: [
     *       { period_index, player_id, position_code },
     *       ...
     *     ] }
     *
     * Idempotent. Click-to-swap on the grid sends the full new state
     * every interaction (small payloads: a 7v7 match × 2 periods is
     * 14 rows, ~600 bytes JSON).
     */
    public static function update_assignments( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::assignmentsArgs() );
        if ( $refused !== null ) return $refused;

        $tournament_id = (int) $r['id'];
        $match_id      = (int) $r['match_id'];

        $match = self::fetchMatch( $match_id );
        if ( ! $match || (int) $match['tournament_id'] !== $tournament_id ) {
            return RestResponse::notFound( 'match_not_found' );
        }
        if ( ! empty( $match['completed_at'] ) && ! $r->get_param( 'force' ) ) {
            return RestResponse::error( 'match_completed', __( 'Match already completed. Pass force=1 to edit a locked lineup.', 'talenttrack' ), 409 );
        }

        $payload = $r->get_param( 'assignments' );
        if ( ! is_array( $payload ) ) {
            return RestResponse::error( 'invalid_payload', __( 'Assignments payload must be an array.', 'talenttrack' ), 422 );
        }

        global $wpdb; $p = $wpdb->prefix;

        // v4.20.39 (#1199) — Audit 2 (#1176) flagged: the parent match
        // is scope-checked via `fetchMatch` (club_id) above, but each
        // row's `player_id` was trusted. Result: tournament minutes
        // could be assigned to any player_id within the club —
        // including players not on the tournament's squad, not on the
        // tournament's team, even archived players. Concrete #1148
        // shape reproduction.
        //
        // Resolve the tournament's allowed squad once, then drop any
        // submitted row whose `player_id` is not in it. Same
        // diagnostic shape as v4.20.5 attendance — silently filter
        // with a single warning log capturing the dropped ids.
        $allowed_player_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT player_id FROM {$p}tt_tournament_squad
              WHERE tournament_id = %d AND club_id = %d",
            $tournament_id, CurrentClub::id()
        ) );
        $allowed_player_ids = array_map( 'intval', is_array( $allowed_player_ids ) ? $allowed_player_ids : [] );
        $allowed_set        = array_flip( $allowed_player_ids );

        $wpdb->delete( "{$p}tt_tournament_assignments", [ 'match_id' => $match_id, 'club_id' => CurrentClub::id() ] );

        $period_count = count( $match['substitution_windows'] ) + 1;
        $seen    = []; // dedup (period, player) within the payload
        $dropped = [];
        foreach ( $payload as $row ) {
            $period   = isset( $row['period_index'] ) ? (int) $row['period_index'] : -1;
            $player   = isset( $row['player_id'] ) ? (int) $row['player_id'] : 0;
            $position = isset( $row['position_code'] ) ? strtoupper( sanitize_key( (string) $row['position_code'] ) ) : '';
            if ( $period < 0 || $period >= $period_count ) continue;
            if ( $player <= 0 ) continue;
            if ( ! isset( $allowed_set[ $player ] ) ) {
                $dropped[] = $player;
                continue;
            }
            $key = $period . '|' . $player;
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $wpdb->insert( "{$p}tt_tournament_assignments", [
                'match_id'      => $match_id,
                'club_id'       => CurrentClub::id(),
                'period_index'  => $period,
                'player_id'     => $player,
                'position_code' => $position !== '' ? $position : 'BENCH',
            ] );
        }
        if ( $dropped ) {
            Logger::warning( 'tournament.assignments.dropped_off_squad', [
                'tournament_id' => $tournament_id,
                'match_id'      => $match_id,
                'dropped'       => array_values( array_unique( $dropped ) ),
            ] );
        }

        do_action( 'tt_tournament_assignments_updated', $tournament_id, $match_id );

        return RestResponse::success( [
            'match_id' => $match_id,
            'totals'   => self::computeTotals( $tournament_id ),
        ] );
    }

    /**
     * POST /tournaments/{id}/matches/{m_id}/kickoff — promotes the
     * tournament match to a "real" event by creating a tt_activities
     * row of type 'match' and linking it via activity_id. The
     * activity carries opponent + formation + kickoff_time so the
     * existing match-day team sheet + player-journey rollups light
     * up automatically.
     *
     * Idempotent: a match already linked to an activity returns the
     * existing activity_id without creating a duplicate.
     */
    public static function kickoff_match( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values. `complete_match()`
        // calls this one with the same request, and the two declare the
        // same fields, so the check answers the same either way.
        $refused = BaseController::checkBody( $r, self::matchIdArgs() );
        if ( $refused !== null ) return $refused;

        $tournament_id = (int) $r['id'];
        $match_id      = (int) $r['match_id'];

        $tournament = self::fetchTournamentRow( $tournament_id );
        if ( ! $tournament ) return RestResponse::notFound( 'tournament_not_found' );

        $match = self::fetchMatch( $match_id );
        if ( ! $match || (int) $match['tournament_id'] !== $tournament_id ) {
            return RestResponse::notFound( 'match_not_found' );
        }

        global $wpdb; $p = $wpdb->prefix;

        // Idempotent: if already linked, just return.
        if ( ! empty( $match['activity_id'] ) ) {
            return RestResponse::success( [
                'match_id'    => $match_id,
                'activity_id' => (int) $match['activity_id'],
                'already_kicked_off' => true,
            ] );
        }

        // Compose the activity row. session_date pulls from the
        // tournament's scheduled_at OR start_date. Opponent + formation
        // come straight from the tournament match row.
        $session_date = $match['scheduled_at']
            ? substr( (string) $match['scheduled_at'], 0, 10 )
            : (string) $tournament->start_date;
        $effective_formation = $match['formation'] !== ''
            ? $match['formation']
            : (string) ( $tournament->default_formation ?? '' );

        $title = $match['label'] !== ''
            ? (string) $match['label']
            : ( $match['opponent_name'] !== '' ? 'vs ' . $match['opponent_name'] : 'Match' );

        $activity_data = [
            'club_id'             => CurrentClub::id(),
            'team_id'             => (int) $tournament->team_id,
            'session_date'        => $session_date,
            'title'               => $title,
            'notes'               => sprintf(
                /* translators: %s is the tournament name */
                __( 'Tournament match — %s', 'talenttrack' ),
                (string) $tournament->name
            ),
            // Note: 'match' is a legacy synonym for ActivityTypeKey::GAME;
            // tournament-created activities preserve the original label
            // until a coordinated rename — see #988 follow-up.
            'activity_type_key'   => 'match',
            'activity_status_key' => ActivityStatusKey::PLANNED,
            'activity_source_key' => 'tournament',
            'coach_id'            => get_current_user_id(),
            'opponent'            => (string) ( $match['opponent_name'] ?? '' ),
            'formation'           => $effective_formation,
            'kickoff_time'        => $match['scheduled_at']
                ? substr( (string) $match['scheduled_at'], 11, 5 )
                : null,
        ];

        $ok = $wpdb->insert( "{$p}tt_activities", $activity_data );
        if ( $ok === false ) {
            Logger::error( 'rest.tournament.kickoff.failed', [ 'match_id' => $match_id, 'db_error' => (string) $wpdb->last_error ] );
            return RestResponse::error( 'db_error', __( 'Could not create the match activity.', 'talenttrack' ), 500 );
        }
        $activity_id = (int) $wpdb->insert_id;

        // Tag demo-on rows so they remain visible to demo-scoped queries.
        if ( class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
            \TT\Modules\DemoData\DemoMode::tagIfActive( 'activity', $activity_id );
        }

        $wpdb->update(
            "{$p}tt_tournament_matches",
            [
                'activity_id'   => $activity_id,
                'kicked_off_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $match_id, 'club_id' => CurrentClub::id() ]
        );

        do_action( 'tt_tournament_match_kicked_off', $tournament_id, $match_id, $activity_id );

        return RestResponse::success( [
            'match_id'    => $match_id,
            'activity_id' => $activity_id,
        ] );
    }

    /**
     * POST /tournaments/{id}/matches/{m_id}/complete — sets
     * completed_at on the tournament match and syncs the period-0
     * starting lineup to tt_attendance rows on the linked activity:
     *
     *   - Players in period 0 with position_code != 'BENCH' →
     *     lineup_role='start', position_played=position_code.
     *   - Other squad members → lineup_role='bench', position_played
     *     pulled from their first non-bench assignment (if any) or
     *     left NULL.
     *
     * Idempotent: re-running re-syncs attendance.
     */
    public static function complete_match( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::matchIdArgs() );
        if ( $refused !== null ) return $refused;

        $tournament_id = (int) $r['id'];
        $match_id      = (int) $r['match_id'];

        $tournament = self::fetchTournamentRow( $tournament_id );
        if ( ! $tournament ) return RestResponse::notFound( 'tournament_not_found' );

        $match = self::fetchMatch( $match_id );
        if ( ! $match || (int) $match['tournament_id'] !== $tournament_id ) {
            return RestResponse::notFound( 'match_not_found' );
        }

        global $wpdb; $p = $wpdb->prefix;

        // Auto-kickoff if not already linked, so the player journey
        // surfaces the match without the coach having to tap kickoff
        // separately.
        if ( empty( $match['activity_id'] ) ) {
            $kickoff = self::kickoff_match( $r );
            // Refresh match data.
            $match = self::fetchMatch( $match_id );
            if ( ! $match || empty( $match['activity_id'] ) ) {
                return RestResponse::error( 'kickoff_failed', __( 'Could not promote match to activity.', 'talenttrack' ), 500 );
            }
        }
        $activity_id = (int) $match['activity_id'];

        // Fetch assignments.
        $assignments = $wpdb->get_results( $wpdb->prepare(
            "SELECT period_index, player_id, position_code
               FROM {$p}tt_tournament_assignments
              WHERE match_id = %d AND club_id = %d",
            $match_id, CurrentClub::id()
        ), ARRAY_A ) ?: [];

        // Per-player aggregates: did they ever start (period 0
        // non-bench)? what's their position_played (first non-bench
        // slot in any period)?
        $per_player = [];
        foreach ( $assignments as $a ) {
            $pid = (int) $a['player_id'];
            if ( ! isset( $per_player[ $pid ] ) ) {
                $per_player[ $pid ] = [ 'started' => false, 'position_played' => null ];
            }
            if ( (int) $a['period_index'] === 0 && (string) $a['position_code'] !== 'BENCH' ) {
                $per_player[ $pid ]['started'] = true;
            }
            if ( $per_player[ $pid ]['position_played'] === null && (string) $a['position_code'] !== 'BENCH' ) {
                $per_player[ $pid ]['position_played'] = (string) $a['position_code'];
            }
        }

        // Sync to tt_attendance. Wipe the existing REGISTER for this
        // activity first — idempotent re-sync.
        //
        // #3451 — it used to wipe every row for the activity regardless of
        // `record_type` and re-insert without naming one, so the rows landed
        // on the column's `actual` default and a squad somebody had planned
        // for the tournament fixture was destroyed on the way. Guests stay
        // in scope of the wipe here (unlike the activity form's, #0026):
        // this path rebuilds the whole register for the fixture from the
        // tournament squad, and a guest row it did not write is a row it
        // would otherwise orphan.
        $writer = new AttendanceWriter();
        $writer->clearActual( $activity_id, true );

        // Pull the full squad so benched-with-no-assignment players
        // also get attendance rows.
        $squad = self::fetchSquad( $tournament_id );
        foreach ( $squad as $sq ) {
            $pid = (int) $sq['player_id'];
            $row = $per_player[ $pid ] ?? [ 'started' => false, 'position_played' => null ];
            $writer->recordActual( [
                'club_id'         => CurrentClub::id(),
                'activity_id'     => $activity_id,
                'player_id'       => $pid,
                'status'          => AttendanceStatus::PRESENT,
                'lineup_role'     => $row['started'] ? 'start' : 'bench',
                'position_played' => $row['position_played'],
            ] );
        }

        $wpdb->update(
            "{$p}tt_tournament_matches",
            [ 'completed_at' => current_time( 'mysql' ) ],
            [ 'id' => $match_id, 'club_id' => CurrentClub::id() ]
        );

        // Mark the activity completed so the existing list-view query
        // picks it up.
        $wpdb->update(
            "{$p}tt_activities",
            [ 'activity_status_key' => 'completed' ],
            [ 'id' => $activity_id, 'club_id' => CurrentClub::id() ]
        );

        do_action( 'tt_tournament_match_completed', $tournament_id, $match_id, $activity_id );

        return RestResponse::success( [
            'match_id'    => $match_id,
            'activity_id' => $activity_id,
            'attendance_rows' => count( $squad ),
            'totals'      => self::computeTotals( $tournament_id ),
        ] );
    }

    /**
     * POST /tournaments/{id}/matches/{m_id}/auto-plan — greedy
     * assignment. Wipes existing assignments for the match and fills
     * the grid per the spec's algorithm:
     *
     *   For each period p:
     *     For each formation slot s:
     *       candidates = squad where:
     *         position_type(s) IN player.eligible_positions
     *         AND player NOT already assigned in this period
     *       rank by:
     *         1. (target - expected_minutes) DESC   -- under-served first
     *         2. (if p == 0) starts ASC             -- fewer starts first for openers
     *         3. (player not benched in p-1)         -- avoid back-to-back bench
     *       assign top candidate to slot
     *     remaining squad members → BENCH for period p
     *
     * No backtracking. Coach manually fixes corner cases via the
     * planner grid.
     */
    public static function auto_plan( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::matchIdArgs() );
        if ( $refused !== null ) return $refused;

        $tournament_id = (int) $r['id'];
        $match_id      = (int) $r['match_id'];

        $tournament = self::fetchTournamentRow( $tournament_id );
        if ( ! $tournament ) return RestResponse::notFound( 'tournament_not_found' );

        $match = self::fetchMatch( $match_id );
        if ( ! $match || (int) $match['tournament_id'] !== $tournament_id ) {
            return RestResponse::notFound( 'match_not_found' );
        }
        if ( ! empty( $match['completed_at'] ) ) {
            return RestResponse::error( 'match_completed', __( 'Cannot auto-plan a completed match.', 'talenttrack' ), 409 );
        }

        $effective_formation = $match['formation'] !== ''
            ? $match['formation']
            : (string) ( $tournament->default_formation ?? '' );
        $slot_labels = self::lookupSlotLabels( $effective_formation );
        if ( ! $slot_labels ) {
            return RestResponse::error( 'no_formation', __( 'Match has no formation. Set a formation on the match or tournament before auto-planning.', 'talenttrack' ), 422 );
        }

        $squad = self::fetchSquad( $tournament_id );
        if ( ! $squad ) {
            return RestResponse::error( 'empty_squad', __( 'Add players to the squad before auto-planning.', 'talenttrack' ), 422 );
        }

        $period_count = count( $match['substitution_windows'] ) + 1;

        // Pre-compute starts + expected-so-far across the tournament
        // (excluding this match — we're about to overwrite it).
        $rollup = self::rollupForOtherMatches( $tournament_id, $match_id );

        $target_default = self::computeDefaultTarget( $tournament_id );

        // Working state: per-player accumulator that mutates as we
        // assign slots in this match.
        $state = [];
        foreach ( $squad as $sq ) {
            $pid = (int) $sq['player_id'];
            $state[ $pid ] = [
                'eligible_positions' => is_array( $sq['eligible_positions'] ?? null ) ? $sq['eligible_positions'] : [],
                'target'             => $sq['target_minutes'] ?? $target_default,
                'starts'             => $rollup[ $pid ]['starts']   ?? 0,
                'expected'           => $rollup[ $pid ]['expected'] ?? 0,
                'last_benched'       => false, // updated as we walk periods
            ];
        }

        $minutes_per_period = self::minutesPerPeriod( (int) $match['duration_min'], count( $match['substitution_windows'] ) );

        // Flatten the formation into (line_index, slot_code) tuples
        // so we know which position TYPE bucket each slot belongs to.
        $line_count = count( $slot_labels );
        $flat_slots = [];
        foreach ( $slot_labels as $line_idx => $line ) {
            $position_type = self::positionTypeForLine( $line_idx, $line_count );
            foreach ( $line as $code ) {
                $flat_slots[] = [ 'code' => $code, 'type' => $position_type ];
            }
        }

        $assignments = [];
        for ( $p = 0; $p < $period_count; $p++ ) {
            $assigned_this_period = [];
            // Track this period's bench candidates as we go — used for
            // the next period's last_benched flag computation.
            foreach ( $flat_slots as $slot ) {
                $candidates = [];
                foreach ( $state as $pid => $s ) {
                    if ( in_array( $pid, $assigned_this_period, true ) ) continue;
                    // v4.8.0 (#975) — eligible_positions are specific
                    // codes (GK/CB/LB/RB/DM/CM/AM/LW/RW/ST). The slot
                    // type bucket (GK/DEF/MID/FWD) is matched against
                    // the player's set via `positionTypeBucket()`. GK
                    // stays strict — the player must list 'GK'.
                    if ( $slot['type'] === 'GK' && ! in_array( 'GK', $s['eligible_positions'], true ) ) continue;
                    if ( $slot['type'] !== 'GK' && ! self::playerCoversBucket( $s['eligible_positions'], $slot['type'] ) ) continue;
                    $candidates[] = $pid;
                }
                if ( ! $candidates ) {
                    // No eligible player — leave the slot empty. The grid
                    // will render this with a "+" empty chip.
                    continue;
                }
                // Rank.
                usort( $candidates, function ( $a, $b ) use ( $state, $p ) {
                    $sa = $state[ $a ]; $sb = $state[ $b ];
                    $gapA = (int) $sa['target'] - (int) $sa['expected'];
                    $gapB = (int) $sb['target'] - (int) $sb['expected'];
                    if ( $gapA !== $gapB ) return $gapB <=> $gapA; // under-served first

                    if ( $p === 0 ) {
                        if ( $sa['starts'] !== $sb['starts'] ) {
                            return $sa['starts'] <=> $sb['starts']; // fewer starts first
                        }
                    }
                    // Avoid back-to-back bench: penalise a player who was
                    // benched in p-1 by ranking them BEHIND a player who
                    // wasn't.
                    $aPen = $sa['last_benched'] ? 1 : 0;
                    $bPen = $sb['last_benched'] ? 1 : 0;
                    if ( $aPen !== $bPen ) return $aPen <=> $bPen;
                    return 0;
                } );
                $winner = $candidates[0];
                $assignments[] = [
                    'period_index'  => $p,
                    'player_id'     => $winner,
                    'position_code' => $slot['code'],
                ];
                $assigned_this_period[] = $winner;
                $state[ $winner ]['expected']     += $minutes_per_period;
                $state[ $winner ]['last_benched']  = false;
                if ( $p === 0 ) $state[ $winner ]['starts']++;
            }
            // Mark un-assigned squad members as benched this period.
            foreach ( $state as $pid => $s ) {
                if ( in_array( $pid, $assigned_this_period, true ) ) continue;
                $assignments[] = [
                    'period_index'  => $p,
                    'player_id'     => $pid,
                    'position_code' => 'BENCH',
                ];
                $state[ $pid ]['last_benched'] = true;
            }
        }

        // Commit.
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_tournament_assignments", [ 'match_id' => $match_id, 'club_id' => CurrentClub::id() ] );
        foreach ( $assignments as $a ) {
            $wpdb->insert( "{$p}tt_tournament_assignments", [
                'match_id'      => $match_id,
                'club_id'       => CurrentClub::id(),
                'period_index'  => (int) $a['period_index'],
                'player_id'     => (int) $a['player_id'],
                'position_code' => (string) $a['position_code'],
            ] );
        }

        do_action( 'tt_tournament_auto_planned', $tournament_id, $match_id );

        return RestResponse::success( [
            'match_id'    => $match_id,
            'assignments' => $assignments,
            'totals'      => self::computeTotals( $tournament_id ),
        ] );
    }

    /**
     * Map a formation-line index to a position TYPE bucket. Used by
     * the auto-planner to know which eligible-position type matches
     * each slot.
     *
     * Convention for v1 seeded formations:
     *   Line 0           → GK
     *   Last line        → FWD
     *   Line 1           → DEF
     *   Intermediate     → MID
     *
     * Operator-added custom formations follow the same convention as
     * long as line 0 is the goalkeeper and the last line is the
     * forwards — which is the standard football notation.
     */
    private static function positionTypeForLine( int $line_idx, int $line_count ): string {
        if ( $line_idx === 0 ) return 'GK';
        if ( $line_idx === $line_count - 1 ) return 'FWD';
        if ( $line_idx === 1 ) return 'DEF';
        return 'MID';
    }

    /**
     * Map the v4.8.0 specific position codes onto the four formation-
     * line buckets used by the auto-planner.
     *
     *   GK   → GK
     *   CB / LB / RB                     → DEF
     *   DM / CM / AM                     → MID
     *   LW / RW / ST                     → FWD
     *
     * Legacy bucket tokens (GK/DEF/MID/FWD) saved before #975 also
     * satisfy the matching check verbatim.
     */
    private static function playerCoversBucket( array $eligible_positions, string $bucket ): bool {
        $map = [
            'GK' => 'GK',
            'CB' => 'DEF', 'LB' => 'DEF', 'RB' => 'DEF', 'DEF' => 'DEF',
            'DM' => 'MID', 'CM' => 'MID', 'AM' => 'MID', 'MID' => 'MID',
            'LW' => 'FWD', 'RW' => 'FWD', 'ST' => 'FWD', 'FWD' => 'FWD',
        ];
        foreach ( $eligible_positions as $code ) {
            $code = strtoupper( (string) $code );
            if ( isset( $map[ $code ] ) && $map[ $code ] === $bucket ) return true;
        }
        return false;
    }

    /**
     * Aggregate played-minutes + expected-minutes + starts per player
     * across every match in a tournament EXCEPT the one being
     * auto-planned. The auto-planner uses this as the baseline so
     * under-served players bubble up first.
     *
     * @return array<int, array{starts:int, expected:int}>
     */
    private static function rollupForOtherMatches( int $tournament_id, int $exclude_match_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.player_id, a.match_id, a.period_index, a.position_code,
                    m.duration_min, m.substitution_windows
               FROM {$p}tt_tournament_assignments a
               JOIN {$p}tt_tournament_matches m ON m.id = a.match_id
              WHERE m.tournament_id = %d
                AND a.match_id <> %d
                AND a.club_id = %d",
            $tournament_id, $exclude_match_id, CurrentClub::id()
        ), ARRAY_A ) ?: [];

        $out = [];
        foreach ( $rows as $row ) {
            $pid = (int) $row['player_id'];
            if ( ! isset( $out[ $pid ] ) ) {
                $out[ $pid ] = [ 'starts' => 0, 'expected' => 0 ];
            }
            $windows = json_decode( (string) $row['substitution_windows'], true ) ?: [];
            $per = self::minutesPerPeriod( (int) $row['duration_min'], count( $windows ) );
            if ( (string) $row['position_code'] !== 'BENCH' ) {
                $out[ $pid ]['expected'] += $per;
            }
            if ( (int) $row['period_index'] === 0 && (string) $row['position_code'] !== 'BENCH' ) {
                $out[ $pid ]['starts']++;
            }
        }
        return $out;
    }

    /**
     * Compute the default equal-share target for a tournament — sum
     * of every match's duration_min, used when a squad row has no
     * per-player target_minutes override.
     */
    private static function computeDefaultTarget( int $tournament_id ): int {
        global $wpdb; $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(duration_min), 0)
               FROM {$p}tt_tournament_matches
              WHERE tournament_id = %d AND club_id = %d",
            $tournament_id, CurrentClub::id()
        ) );
    }

    /**
     * PATCH /tournaments/{id}/matches/{match_id} — a true partial update.
     *
     * #3557 — this used to rebuild the row from `extractMatch()`, which
     * defaults every column the request does not mention. The score boxes
     * (#3532) PATCH one field on blur, so typing "3" into a scoreline wiped
     * the fixture's opponent, level, kickoff time and substitution windows,
     * and reset its duration to 20. CLAUDE.md § 6: the endpoint has to accept
     * partial updates before a per-field caller points at it. Only the keys
     * the request actually carries are written; anything absent is left as it
     * is on the row.
     */
    public static function update_match( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::matchUpdateArgs() );
        if ( $refused !== null ) return $refused;

        global $wpdb; $p = $wpdb->prefix;
        $tournament_id = (int) $r['id'];
        $match_id      = (int) $r['match_id'];

        $existing = self::fetchMatch( $match_id );
        if ( ! $existing || (int) $existing['tournament_id'] !== $tournament_id ) {
            return RestResponse::notFound( 'match_not_found' );
        }

        $bad_level = self::rejectUnknownOpponentLevel( (array) $r->get_params() );
        if ( $bad_level !== null ) return $bad_level;

        // #4020 — PATCHing a fixture to a formation the lookup does not carry
        // used to answer 200 and then 422 at auto-plan time.
        $bad_formation = self::rejectUnknownFormation( (array) $r->get_params(), 'formation' );
        if ( $bad_formation !== null ) return $bad_formation;

        // The extractor whitelists mutable columns, so tournament_id,
        // club_id, activity_id and sequence cannot be reached from here.
        $payload = self::extractMatchPartial( (array) $r->get_params(), $existing );

        if ( $payload === [] ) {
            // Nothing recognised in the request. Returning the row untouched
            // beats an UPDATE with no columns, which wpdb reports as a
            // failure and the caller would read as a save error.
            return RestResponse::success( $existing );
        }

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
        // #3819 — the body's shape before its values, and before the
        // assignments are cleared: a body this route cannot read must not
        // wipe a tournament's plans on its way to being refused.
        $refused = BaseController::checkBody( $r, self::squadArgs() );
        if ( $refused !== null ) return $refused;

        global $wpdb; $p = $wpdb->prefix;
        $tournament_id = (int) $r['id'];

        $existing = self::fetchTournamentRow( $tournament_id );
        if ( ! $existing ) return RestResponse::notFound( 'tournament_not_found' );

        $squad = is_array( $r['squad'] ?? null ) ? $r['squad'] : [];
        if ( ! is_array( $squad ) ) {
            return RestResponse::error( 'invalid_payload', __( 'Squad payload must be an array.', 'talenttrack' ), 422 );
        }

        // #4020 — before the wipe below: a payload this route cannot store
        // must not take the tournament's plans with it on the way out.
        $bad_positions = self::rejectUnknownPositionsInSquad( $squad );
        if ( $bad_positions !== null ) return $bad_positions;

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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::squadMemberArgs() );
        if ( $refused !== null ) return $refused;

        global $wpdb; $p = $wpdb->prefix;
        $tournament_id = (int) $r['id'];
        $player_id     = (int) $r['player_id'];

        // #4020 — a code the planner has no slot for is refused, not dropped.
        $bad_positions = self::rejectUnknownPositions(
            [ 'player_id' => $player_id ] + (array) $r->get_params()
        );
        if ( $bad_positions !== null ) return $bad_positions;

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

    /**
     * Fetch a formation's `slot_labels` from the tt_lookups row.
     * Returns a list-of-lists shape: each outer entry is a formation
     * line (GK / DEF / MID / FWD), each inner entry is the slot
     * codes for that line (e.g. ["RB","CB","LB"]).
     *
     * Returns an empty array when the formation isn't seeded or
     * when meta.slot_labels is missing.
     *
     * @return array<int, array<int, string>>
     */
    private static function lookupSlotLabels( string $formation_name ): array {
        if ( $formation_name === '' ) return [];
        global $wpdb; $p = $wpdb->prefix;
        $meta_raw = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT meta FROM {$p}tt_lookups WHERE lookup_type = %s AND name = %s LIMIT 1",
            'tournament_formation',
            $formation_name
        ) );
        if ( $meta_raw === '' ) return [];
        $meta = json_decode( $meta_raw, true );
        if ( ! is_array( $meta ) ) return [];
        $labels = $meta['slot_labels'] ?? [];
        if ( ! is_array( $labels ) ) return [];
        return $labels;
    }

    /**
     * Minutes per period assuming even splits. `N windows → N+1 periods`.
     */
    private static function minutesPerPeriod( int $duration_min, int $windows_count ): int {
        $periods = max( 1, $windows_count + 1 );
        return (int) round( $duration_min / $periods );
    }

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
    // Body contracts (#3819) -------------------------------------------

    /*
     * Nothing on this surface is declared `required`. Core checks required
     * params in `has_valid_params()`, which runs before the permission
     * callback, so a required field would answer a caller with no claim on
     * the tournament with a 400 naming the fields rather than the 403
     * `canEditTournament()` owes them. Each handler names what it needs.
     *
     * `club_id`, `uuid`, `created_by`, `sequence` and `activity_id` are
     * absent from every declaration here. The first three are stamped from
     * the request context; the last two are the route's own bookkeeping,
     * and the update extractor already refuses to write them.
     */

    /**
     * `POST /tournaments`, including the wizard's nested squad and
     * fixtures on its final step.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function createArgs(): array {
        return self::tournamentFieldArgs() + [
            'squad'   => [ 'type' => 'array', 'description' => 'The players taking part, each as a squad entry. Optional; the wizard sends it with the tournament.' ],
            'matches' => [ 'type' => 'array', 'description' => 'The fixtures, in order. Checked before the tournament row is written, so a bad one refuses the request rather than leaving a tournament with some of its fixtures missing.' ],
        ];
    }

    /**
     * `PUT /tournaments/{id}`. The whole row is rebuilt from these fields,
     * so a key left out is cleared rather than kept — which is why the
     * edit form posts all of them.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function updateArgs(): array {
        return self::tournamentIdArgs() + self::tournamentFieldArgs();
    }

    /** @return array<string, array<string, mixed>> */
    private static function tournamentFieldArgs(): array {
        return [
            'name'              => [ 'type' => 'string', 'description' => 'What the tournament is called.' ],
            'team_id'           => [ 'type' => [ 'integer', 'string' ], 'description' => 'The team playing it.' ],
            'start_date'        => [ 'type' => 'string', 'description' => 'The first day, as YYYY-MM-DD.' ],
            'end_date'          => [ 'type' => 'string', 'description' => 'The last day, as YYYY-MM-DD. Blank for a one-day tournament.' ],
            'default_formation' => [ 'type' => 'string', 'description' => 'The formation a fixture uses when it names none of its own.' ],
            'notes'             => [ 'type' => 'string', 'description' => 'Anything the staff need to know.' ],
        ];
    }

    /**
     * `POST /tournaments/{id}/matches` — one fixture.
     *
     * No `enum` on `opponent_level`: `rejectUnknownOpponentLevel()`
     * answers with the levels this academy has configured, which a list
     * hard-coded here could not.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function matchArgs(): array {
        return self::tournamentIdArgs() + [
            'label'                => [ 'type' => 'string', 'description' => 'What to call the fixture.' ],
            'opponent_name'        => [ 'type' => 'string', 'description' => 'Who it is against.' ],
            'opponent_level'       => [ 'type' => 'string', 'description' => 'How strong they are, from the configured levels.' ],
            'formation'            => [ 'type' => 'string', 'description' => 'The formation for this fixture. Blank falls back to the tournament\'s.' ],
            'duration_min'         => [ 'type' => [ 'integer', 'string' ], 'description' => 'How long the fixture lasts, in minutes. Defaults to 20.' ],
            'substitution_windows' => [ 'type' => 'array', 'description' => 'The minutes the planner may change the lineup at. One more period than windows.' ],
            'scheduled_at'         => [ 'type' => 'string', 'description' => 'When it kicks off.' ],
            'our_score'            => [ 'type' => [ 'integer', 'string', 'null' ], 'description' => 'Goals for. Null or blank means no result recorded, which is not the same as nil.' ],
            'their_score'          => [ 'type' => [ 'integer', 'string', 'null' ], 'description' => 'Goals against. Null or blank means no result recorded.' ],
            'notes'                => [ 'type' => 'string', 'description' => 'Anything worth remembering about the fixture.' ],
        ];
    }

    /**
     * `PATCH /tournaments/{id}/matches/{match_id}`. Every field is
     * optional and an omitted one is left alone (CLAUDE.md §6) — the
     * extractor writes only what the request names.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function matchUpdateArgs(): array {
        return self::matchIdArgs() + self::matchArgs();
    }

    /**
     * `PATCH /tournaments/{id}/matches/{match_id}/assignments` — the
     * lineup, period by period.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function assignmentsArgs(): array {
        return self::matchIdArgs() + [
            'assignments' => [ 'type' => 'array', 'description' => 'Who plays where, per period. A row naming a player who is not in this tournament\'s squad is dropped.' ],
            'force'       => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Edit the lineup of a fixture that is already completed.' ],
        ];
    }

    /**
     * `PATCH /tournaments/{id}/squad` — the whole squad at once. Changing
     * it clears every fixture's lineup, because a plan built around a
     * squad no longer holds once the squad moves.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function squadArgs(): array {
        return self::tournamentIdArgs() + [
            'squad' => [ 'type' => 'array', 'description' => 'The players taking part, each as {player_id, eligible_positions, target_minutes, notes}. Replaces the squad.' ],
        ];
    }

    /**
     * `PATCH /tournaments/{id}/squad/{player_id}` — one player's entry.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function squadMemberArgs(): array {
        return self::tournamentIdArgs() + [
            'player_id'          => [ 'type' => [ 'integer', 'string' ], 'description' => 'The player, from the URL. A copy in the body is accepted and ignored.' ],
            // No `type`: a field that lists `array` goes through
            // `rest_sanitize_array()`, which splits a plain string on
            // whitespace and commas. `normalisePositionsJson()` decides
            // what a position list is.
            'eligible_positions' => [ 'description' => 'Where this player can be used.' ],
            'target_minutes'     => [ 'type' => [ 'integer', 'string', 'null' ], 'description' => 'How many minutes they should get across the tournament. Blank falls back to the shared target.' ],
            'notes'              => [ 'type' => 'string', 'description' => 'Anything about their availability.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function tournamentIdArgs(): array {
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The tournament, from the URL. A copy in the body is accepted and ignored.',
        ] ];
    }

    /**
     * The routes that act on one fixture: kickoff, complete and auto-plan
     * take nothing but these two, which are both URL segments.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function matchIdArgs(): array {
        return self::tournamentIdArgs() + [ 'match_id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The fixture, from the URL. A copy in the body is accepted and ignored.',
        ] ];
    }

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
     * Sanitise + cast a **complete** inbound match payload, applying the
     * creation defaults for anything absent. Used on the create paths
     * (`insertMatch`), where there is no row yet and a missing key genuinely
     * means "no value". The update path wants `extractMatchPartial()`
     * instead — see #3557.
     */
    /**
     * #3559 — the opponent level a match may carry.
     *
     * The operator-editable `tournament_opponent_level` vocabulary is the
     * authority; the typed constants are the floor for an install whose
     * rows were deleted, so an emptied vocabulary refuses everything
     * rather than accepting anything.
     *
     * @return list<string>
     */
    private static function allowedOpponentLevels(): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( 'tournament_opponent_level' ) as $row ) {
            $name = (string) ( $row->name ?? '' );
            if ( $name !== '' && ! in_array( $name, $out, true ) ) $out[] = $name;
        }
        return $out ?: TournamentOpponentLevel::ALL;
    }

    /**
     * #3559 — refuse a level the vocabulary does not carry.
     *
     * The column is a plain `VARCHAR(64)` and every write path sanitised
     * the string without ever checking it, so `opponent_level: "banana"`
     * stored and then rendered as itself on the planner. An empty value
     * stays allowed: it clears the column, which is "not recorded".
     *
     * @param array<string,mixed> $params
     * @return \WP_REST_Response|null the 400, or null when there is
     *   nothing to object to
     */
    private static function rejectUnknownOpponentLevel( array $params ): ?\WP_REST_Response {
        if ( ! array_key_exists( 'opponent_level', $params ) ) return null;

        $value = sanitize_text_field( (string) ( $params['opponent_level'] ?? '' ) );
        if ( $value === '' ) return null;

        $allowed = self::allowedOpponentLevels();
        if ( in_array( $value, $allowed, true ) ) return null;

        return RestResponse::error(
            'opponent_level_invalid',
            sprintf(
                /* translators: 1: the rejected value, 2: comma-separated list of allowed values. */
                __( '"%1$s" is not an opponent level. Allowed values: %2$s.', 'talenttrack' ),
                $value,
                implode( ', ', $allowed )
            ),
            400,
            [ 'allowed' => $allowed ]
        );
    }

    private static function extractMatch( array $r ): array {
        $duration = isset( $r['duration_min'] ) ? max( 1, absint( $r['duration_min'] ) ) : 20;
        $windows  = self::normaliseWindowsJson( $r['substitution_windows'] ?? null, $duration );
        $scheduled = sanitize_text_field( (string) ( $r['scheduled_at'] ?? '' ) );

        // #3532 — the fixture's result. Merged in only when the request
        // mentions it. An explicit null or '' clears the column, because a
        // coach deleting the digits is saying "no result recorded", not "0-0".
        $scores = [];
        foreach ( [ 'our_score', 'their_score' ] as $col ) {
            if ( ! array_key_exists( $col, $r ) ) continue;
            $scores[ $col ] = self::sanitiseScore( $r[ $col ] );
        }

        return $scores + [
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
     * Sanitise + cast only the match columns the request actually mentions.
     *
     * #3557 — the difference from `extractMatch()` is what an absent key
     * means. On create it means "no value, use the default"; on update it
     * means "the caller is not talking about this column", and the row keeps
     * what it has. An explicit `null` still clears a column, so a coach can
     * empty the opponent field.
     *
     * `$existing` is a formatted row from `fetchMatch()` and is only read for
     * the duration / windows pair, which cannot be decided in isolation: the
     * windows are minute marks inside the duration, so normalising one
     * against a stale copy of the other would silently drop them.
     *
     * @param array<string,mixed> $r
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private static function extractMatchPartial( array $r, array $existing ): array {
        $out = [];

        foreach ( [ 'our_score', 'their_score' ] as $col ) {
            if ( ! array_key_exists( $col, $r ) ) continue;
            $out[ $col ] = self::sanitiseScore( $r[ $col ] );
        }

        foreach ( [ 'label', 'opponent_name', 'opponent_level', 'formation' ] as $col ) {
            if ( ! array_key_exists( $col, $r ) ) continue;
            $value = sanitize_text_field( (string) ( $r[ $col ] ?? '' ) );
            $out[ $col ] = $value !== '' ? $value : null;
        }

        if ( array_key_exists( 'notes', $r ) ) {
            $notes = sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) );
            $out['notes'] = $notes !== '' ? $notes : null;
        }

        if ( array_key_exists( 'scheduled_at', $r ) ) {
            $scheduled = sanitize_text_field( (string) ( $r['scheduled_at'] ?? '' ) );
            $out['scheduled_at'] = $scheduled !== '' ? $scheduled : null;
        }

        $has_duration = array_key_exists( 'duration_min', $r );
        $has_windows  = array_key_exists( 'substitution_windows', $r );
        if ( $has_duration || $has_windows ) {
            $duration = $has_duration
                ? max( 1, absint( $r['duration_min'] ) )
                : max( 1, (int) ( $existing['duration_min'] ?? 20 ) );
            $windows_raw = $has_windows
                ? $r['substitution_windows']
                : ( $existing['substitution_windows'] ?? [] );

            $out['duration_min']         = $duration;
            $out['substitution_windows'] = self::normaliseWindowsJson( $windows_raw, $duration );
        }

        return $out;
    }

    /**
     * A fixture score: 0–99, or null for "no result recorded". Shared by the
     * create and update extractors so the two cannot drift.
     *
     * @param mixed $raw
     */
    private static function sanitiseScore( $raw ): ?int {
        if ( $raw === null || $raw === '' ) return null;
        return min( 99, absint( $raw ) );
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
     * The position codes a squad entry may carry — the ten specific codes
     * the blueprint editor uses, since v4.8.0 (#975).
     *
     * @var list<string>
     */
    private const POSITION_CODES = [ 'GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST' ];

    /**
     * Legacy GK/DEF/MID/FWD payloads from v4.7.x and earlier coerce to a
     * representative specific code so existing `tt_tournament_squad` rows and
     * in-flight wizard state survive the bump.
     *
     * @var array<string, string>
     */
    private const POSITION_COERCE = [ 'DEF' => 'CB', 'MID' => 'CM', 'FWD' => 'ST' ];

    /**
     * Split an `eligible_positions` payload into what this squad row can hold
     * and what it cannot.
     *
     * #4020 — the kept half used to be the whole answer: an unknown token was
     * dropped in silence, so a U7 squad sent as `["GK","DF","MF"]` stored
     * `["GK"]` and the auto-planner benched thirteen children with nobody
     * told anything. The rejected half is what lets the write routes refuse.
     *
     * @param mixed $raw
     * @return array{kept: list<string>, rejected: list<string>}
     */
    private static function classifyPositions( $raw ): array {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) $raw = $decoded;
        }
        if ( ! is_array( $raw ) ) return [ 'kept' => [], 'rejected' => [] ];

        $kept     = [];
        $rejected = [];
        foreach ( $raw as $v ) {
            $raw_code = trim( (string) $v );
            if ( $raw_code === '' ) continue;
            $code = strtoupper( sanitize_key( $raw_code ) );
            if ( isset( self::POSITION_COERCE[ $code ] ) ) $code = self::POSITION_COERCE[ $code ];
            if ( in_array( $code, self::POSITION_CODES, true ) ) {
                if ( ! in_array( $code, $kept, true ) ) $kept[] = $code;
                continue;
            }
            if ( ! in_array( $raw_code, $rejected, true ) ) $rejected[] = $raw_code;
        }
        return [ 'kept' => $kept, 'rejected' => $rejected ];
    }

    /**
     * Normalise the eligible_positions payload to a JSON array of
     * position-code strings, for a payload already known to be clean.
     */
    private static function normalisePositionsJson( $raw ): string {
        return (string) wp_json_encode( self::classifyPositions( $raw )['kept'] );
    }

    /**
     * #4020 — refuse a squad entry carrying a position code this plugin has
     * no slot for, naming both the rejected codes and the accepted set.
     *
     * @param array<string,mixed> $entry one squad entry
     * @return \WP_REST_Response|null the 400, or null when there is nothing
     *   to object to
     */
    private static function rejectUnknownPositions( array $entry ): ?\WP_REST_Response {
        if ( ! array_key_exists( 'eligible_positions', $entry ) ) return null;

        $split = self::classifyPositions( $entry['eligible_positions'] );
        if ( $split['rejected'] === [] ) return null;

        $player_id = absint( $entry['player_id'] ?? 0 );
        return RestResponse::error(
            'invalid_positions',
            sprintf(
                /* translators: 1: comma-separated rejected position codes, 2: the player's id, 3: comma-separated accepted codes. */
                __( '"%1$s" is not a position code TalentTrack can plan with (player %2$d). Accepted codes: %3$s.', 'talenttrack' ),
                implode( ', ', $split['rejected'] ),
                $player_id,
                implode( ', ', self::POSITION_CODES )
            ),
            400,
            [
                'player_id' => $player_id,
                'rejected'  => $split['rejected'],
                'allowed'   => self::POSITION_CODES,
            ]
        );
    }

    /**
     * #4020 — every squad entry in one payload, checked before anything is
     * written. A squad is saved as a set, so one bad entry refuses the
     * request rather than leaving half a squad behind.
     *
     * @param mixed $squad
     */
    private static function rejectUnknownPositionsInSquad( $squad ): ?\WP_REST_Response {
        if ( ! is_array( $squad ) ) return null;
        foreach ( $squad as $entry ) {
            $refused = self::rejectUnknownPositions( (array) $entry );
            if ( $refused !== null ) return $refused;
        }
        return null;
    }

    /**
     * The formation names a tournament or fixture may carry.
     *
     * Read with the same query `lookupSlotLabels()` uses, so "accepted on the
     * way in" and "the planner can use it" are the same set by construction.
     * The typed constants are the floor for an install whose lookup rows were
     * deleted: an emptied vocabulary refuses everything rather than accepting
     * anything.
     *
     * @return list<string>
     */
    private static function knownFormationNames(): array {
        global $wpdb; $p = $wpdb->prefix;
        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT name FROM {$p}tt_lookups WHERE lookup_type = %s ORDER BY sort_order ASC, name ASC",
            'tournament_formation'
        ) );
        $out = [];
        foreach ( is_array( $names ) ? $names : [] as $name ) {
            $name = (string) $name;
            if ( $name !== '' && ! in_array( $name, $out, true ) ) $out[] = $name;
        }
        return $out ?: \TT\Domain\Vocabularies\Lookups\TournamentFormation::ALL;
    }

    /**
     * #4020 — refuse a formation the vocabulary does not carry.
     *
     * The column is a plain string and every write path sanitised it without
     * ever checking it, so `default_formation: "1-2-2-1"` saved with a 200 and
     * then answered `422 no_formation` at auto-plan time — the one place that
     * reads the lookup. Blank stays allowed: it means "fall back".
     *
     * @param array<string,mixed> $params
     * @param string $field `default_formation` on a tournament, `formation` on
     *   a fixture.
     */
    private static function rejectUnknownFormation( array $params, string $field ): ?\WP_REST_Response {
        if ( ! array_key_exists( $field, $params ) ) return null;

        $value = sanitize_text_field( (string) ( $params[ $field ] ?? '' ) );
        if ( $value === '' ) return null;

        $allowed = self::knownFormationNames();
        if ( in_array( $value, $allowed, true ) ) return null;

        return RestResponse::error(
            'unknown_formation',
            sprintf(
                /* translators: 1: the rejected formation, 2: comma-separated list of known formations. */
                __( '"%1$s" is not a formation this academy has. Known formations: %2$s.', 'talenttrack' ),
                $value,
                implode( ', ', $allowed )
            ),
            400,
            [ 'allowed' => $allowed ]
        );
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
            "SELECT s.*, pl.first_name, pl.last_name, pl.photo_url, pl.photo_media_id
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
                // #3399 — gated URL. `$row` is an array here, so cast to the
                // object shape the accessor reads.
                'photo_url'          => \TT\Modules\Players\Services\PlayerPhoto::url( (object) $row ),
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
        //
        // #3561 — the division lives in `TournamentMinutesCalculator` now,
        // so the player-file history and this ticker cannot drift on the
        // equal-length-periods assumption. This method keeps its response
        // shape exactly; `TournamentMinutesParityTest` pins the two.
        $match_meta = [];
        $total_match_minutes = 0;        // sum of duration_min — used for the equal-share target
        foreach ( $matches as $m ) {
            $shape = TournamentMinutesCalculator::fixtureShape(
                (int) $m['duration_min'],
                (string) $m['substitution_windows']
            );
            $match_meta[ (int) $m['id'] ] = $shape + [ 'completed' => ! empty( $m['completed_at'] ) ];
            $total_match_minutes += $shape['duration'];
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
            ];
        }

        // #3561 — grouped by (player, fixture) so the calculator answers
        // one fixture at a time, the way the player file asks it. The
        // assignments table is unique on (match_id, period_index,
        // player_id), so grouping loses nothing.
        $by_player_match = [];
        foreach ( $assignment_rows as $a ) {
            $pid = (int) $a['player_id'];
            if ( ! isset( $per_player[ $pid ] ) ) continue;
            $match_id = (int) $a['match_id'];
            if ( ! isset( $match_meta[ $match_id ] ) ) continue;
            $by_player_match[ $pid ][ $match_id ][] = [
                'period_index'  => (int) $a['period_index'],
                'position_code' => (string) $a['position_code'],
            ];
        }

        foreach ( $by_player_match as $pid => $fixtures ) {
            foreach ( $fixtures as $match_id => $assignments ) {
                $meta = $match_meta[ $match_id ];
                $out  = TournamentMinutesCalculator::forPlayer( $meta, $assignments );

                if ( $meta['completed'] ) {
                    $per_player[ $pid ]['played_minutes'] += $out['minutes'];
                } else {
                    $per_player[ $pid ]['expected_minutes'] += $out['minutes'];
                }
                if ( $out['started'] ) $per_player[ $pid ]['starts']++;
                if ( $out['full'] )    $per_player[ $pid ]['full_matches']++;
            }
        }

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
            'detail_url'        => $detail_url,
            // #2023 — archived_at + trashed_at via the shared lifecycle helper.
        ] + \TT\Infrastructure\Archive\LifecycleFields::forRow( $row );
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
            // #3532 — null, not 0, where no result was recorded. A consumer
            // has to be able to tell "0-0" from "we never typed it in", which
            // is the difference between a goalless draw and an unplayed or
            // unrecorded fixture.
            'our_score'            => isset( $row['our_score'] ) ? (int) $row['our_score'] : null,
            'their_score'          => isset( $row['their_score'] ) ? (int) $row['their_score'] : null,
        ];
    }
}
