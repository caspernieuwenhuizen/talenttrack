<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamsRestController — /wp-json/talenttrack/v1/teams
 *
 * #0019 Sprint 3 session 3.2. Built from scratch — no v2.x equivalent.
 * Mirrors the Sprint 2 contract used by `FrontendListTable`.
 *
 * Routes:
 *   GET    /teams                          — paginated list (search/filter/sort/paginate envelope)
 *   POST   /teams                          — create
 *   GET    /teams/{id}                     — single
 *   PUT    /teams/{id}                     — update
 *   DELETE /teams/{id}                     — soft-archive
 *   POST   /teams/{id}/players/{player_id} — add player to team's roster
 *   DELETE /teams/{id}/players/{player_id} — remove player from team's roster
 *
 * Roster management is a sub-resource (Q3 in the Sprint 3 plan):
 * separate add/remove endpoints rather than embedding the roster in
 * the team payload. Cleaner for the autocomplete-add UI on the team
 * edit form, doesn't require sending the full team payload on every
 * roster change.
 *
 * Roster removal sets `tt_players.team_id = 0` rather than deleting
 * the player row — same model the existing wp-admin roster surface
 * uses.
 */
class TeamsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        // #0077 M5 — every gate now consults AuthorizationService::userCanOrMatrix
        // so users granted the cap via a matrix scope-row pass too. Same
        // pattern as ActivitiesRestController::can_edit and TileRegistry.
        $can_view = static function (): bool {
            $uid = get_current_user_id();
            return AuthorizationService::userCanOrMatrix( $uid, 'tt_view_teams' )
                || AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_teams' );
        };
        $can_edit = static function (): bool {
            return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_edit_teams' );
        };

        register_rest_route( self::NS, '/teams', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_teams' ],
                'permission_callback' => $can_view,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_team' ],
                'permission_callback' => $can_edit,
            ],
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_team' ],
                'permission_callback' => $can_view,
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_team' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canManageTeam( get_current_user_id(), (int) $r['id'] );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_team' ],
                'permission_callback' => $can_edit,
            ],
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/players/(?P<player_id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'add_player_to_team' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canManageTeam( get_current_user_id(), (int) $r['id'] );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'remove_player_from_team' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canManageTeam( get_current_user_id(), (int) $r['id'] );
                },
            ],
        ] );
    }

    /** Whitelist of columns the `orderby` query param accepts. */
    private const LIST_ORDERBY_WHITELIST = [
        'name'         => 't.name',
        'age_group'    => 't.age_group',
        'player_count' => 'player_count',
    ];

    public static function list_teams( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );

        $orderby_key = sanitize_key( (string) ( $r['orderby'] ?? 'name' ) );
        if ( ! isset( self::LIST_ORDERBY_WHITELIST[ $orderby_key ] ) ) {
            return RestResponse::error(
                'bad_orderby',
                __( 'Unknown orderby column.', 'talenttrack' ),
                400,
                [ 'allowed' => array_keys( self::LIST_ORDERBY_WHITELIST ) ]
            );
        }
        $orderby = self::LIST_ORDERBY_WHITELIST[ $orderby_key ];
        $order   = strtolower( (string) ( $r['order'] ?? 'asc' ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'asc';

        $where  = [ '1=1', 't.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        $scope = QueryHelpers::apply_demo_scope( 't', 'team' );

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        $archived = isset( $filter['archived'] ) ? sanitize_key( (string) $filter['archived'] ) : 'active';
        if ( $archived === 'archived' ) {
            $where[] = 't.archived_at IS NOT NULL';
        } else {
            $where[] = 't.archived_at IS NULL';
        }

        if ( ! empty( $filter['age_group'] ) ) {
            $where[]  = 't.age_group = %s';
            $params[] = sanitize_text_field( (string) $filter['age_group'] );
        }

        // v3.91.2 — coach-scoping is bypassed for personas with a
        // matrix `team:r[global]` grant (scout, head_of_development,
        // academy_admin, anyone the operator gives global read on the
        // matrix admin page). Coaches with team-scope grants still hit
        // the coach-scope filter as before.
        if ( ! QueryHelpers::user_has_global_entity_read( get_current_user_id(), 'team' ) ) {
            $coach_teams = QueryHelpers::get_teams_for_coach( get_current_user_id() );
            if ( ! $coach_teams ) {
                return RestResponse::success( [
                    'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page,
                ] );
            }
            $team_ids = array_map( static function ( $t ) { return (int) $t->id; }, $coach_teams );
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where[] = "t.id IN ($placeholders)";
            $params  = array_merge( $params, $team_ids );
        }

        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = '(t.name LIKE %s OR t.age_group LIKE %s)';
            $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        // v3.87.1 — head-coach column now reads from the canonical
        // staff-assignment store (`tt_team_people` × `tt_functional_roles`
        // role_key='head_coach') instead of the legacy `tt_teams.head_coach_id`
        // wp-user pointer. Multiple HCs comma-separated. Fallback to the
        // legacy field is retained for teams that haven't been migrated to
        // staff assignments. A pilot install reported the column was empty
        // because they assigned head coaches via Functional Roles only.
        // GROUP_CONCAT separators: `||` for names (avoids collision with
        // a comma inside a person's name), `,` for ids.
        $list_sql = "SELECT t.*,
                            u.display_name AS legacy_coach_name,
                            coach_p.id AS legacy_coach_person_id,
                            (SELECT GROUP_CONCAT(CONCAT(p.first_name, ' ', p.last_name) ORDER BY p.last_name SEPARATOR '||')
                             FROM {$p}tt_team_people tp
                             JOIN {$p}tt_people p ON p.id = tp.person_id AND p.club_id = tp.club_id
                             JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id AND fr.club_id = tp.club_id
                             WHERE tp.team_id = t.id
                               AND tp.club_id = t.club_id
                               AND fr.role_key = 'head_coach'
                               AND p.archived_at IS NULL
                               AND ( tp.end_date IS NULL OR tp.end_date >= CURDATE() )
                            ) AS hc_names,
                            (SELECT GROUP_CONCAT(p.id ORDER BY p.last_name SEPARATOR ',')
                             FROM {$p}tt_team_people tp
                             JOIN {$p}tt_people p ON p.id = tp.person_id AND p.club_id = tp.club_id
                             JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id AND fr.club_id = tp.club_id
                             WHERE tp.team_id = t.id
                               AND tp.club_id = t.club_id
                               AND fr.role_key = 'head_coach'
                               AND p.archived_at IS NULL
                               AND ( tp.end_date IS NULL OR tp.end_date >= CURDATE() )
                            ) AS hc_person_ids,
                            (SELECT COUNT(*) FROM {$p}tt_players pl WHERE pl.team_id = t.id AND pl.archived_at IS NULL AND pl.club_id = t.club_id) AS player_count
                     FROM {$p}tt_teams t
                     LEFT JOIN {$wpdb->users} u ON u.ID = t.head_coach_id
                     LEFT JOIN {$p}tt_people coach_p ON coach_p.wp_user_id = t.head_coach_id AND coach_p.club_id = t.club_id
                     WHERE {$where_sql}
                     ORDER BY {$orderby} {$order}
                     LIMIT %d OFFSET %d";
        $offset = ( $page - 1 ) * $per_page;
        $list_params = array_merge( $params, [ $per_page, $offset ] );

        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) ) ?: [];

        $count_sql = "SELECT COUNT(*) FROM {$p}tt_teams t WHERE {$where_sql}";
        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
            : (int) $wpdb->get_var( $count_sql );

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'fmtRow' ], $rows ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    public static function get_team( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $id = absint( $r['id'] );
        // v3.87.1 — single-team query mirrors list_teams: head coaches via
        // staff assignments first, legacy `tt_teams.head_coach_id` as fallback.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*,
                    u.display_name AS legacy_coach_name,
                    coach_p.id AS legacy_coach_person_id,
                    (SELECT GROUP_CONCAT(CONCAT(p.first_name, ' ', p.last_name) ORDER BY p.last_name SEPARATOR '||')
                     FROM {$p}tt_team_people tp
                     JOIN {$p}tt_people p ON p.id = tp.person_id
                     JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id
                     WHERE tp.team_id = t.id
                       AND fr.role_key = 'head_coach'
                       AND p.archived_at IS NULL
                       AND p.club_id = t.club_id
                       AND ( tp.end_date IS NULL OR tp.end_date >= CURDATE() )
                    ) AS hc_names,
                    (SELECT GROUP_CONCAT(p.id ORDER BY p.last_name SEPARATOR ',')
                     FROM {$p}tt_team_people tp
                     JOIN {$p}tt_people p ON p.id = tp.person_id
                     JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id
                     WHERE tp.team_id = t.id
                       AND fr.role_key = 'head_coach'
                       AND p.archived_at IS NULL
                       AND p.club_id = t.club_id
                       AND ( tp.end_date IS NULL OR tp.end_date >= CURDATE() )
                    ) AS hc_person_ids
             FROM {$p}tt_teams t
             LEFT JOIN {$wpdb->users} u ON u.ID = t.head_coach_id
             LEFT JOIN {$p}tt_people coach_p ON coach_p.wp_user_id = t.head_coach_id AND coach_p.club_id = t.club_id
             WHERE t.id = %d AND t.club_id = %d",
            $id, CurrentClub::id()
        ) );
        if ( ! $row ) return RestResponse::error( 'not_found', __( 'Team not found.', 'talenttrack' ), 404 );
        return RestResponse::success( self::fmtRow( $row ) );
    }

    public static function create_team( \WP_REST_Request $r ) {
        // v3.85.5 — REST cap enforcement, mirrors PlayersRestController.
        // wp-admin TeamsPage already enforced; frontend REST path was
        // bypassing the free-tier 1-team cap.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' ) ) {
            $blocked = \TT\Modules\License\LicenseGate::enforceCapRest( 'teams' );
            if ( $blocked ) return $blocked;
        }

        global $wpdb;
        $data = self::extract( $r );
        if ( $data['name'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Team name is required.', 'talenttrack' ), 400 );
        }
        $data['club_id'] = CurrentClub::id();
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_teams', $data );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'team.create.failed', [ 'db_error' => $err, 'payload' => $data ] );
            return RestResponse::error( 'db_error', __( 'The team could not be created.', 'talenttrack' ), 500, [ 'db_error' => $err ] );
        }
        $team_id = (int) $wpdb->insert_id;
        // v3.76.2 — auto-tag demo-on rows.
        \TT\Modules\DemoData\DemoMode::tagIfActive( 'team', $team_id );
        return RestResponse::success( [ 'id' => $team_id ] );
    }

    public static function update_team( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );
        $data = self::extract( $r );
        if ( $data['name'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Team name is required.', 'talenttrack' ), 400 );
        }
        $ok = $wpdb->update( $wpdb->prefix . 'tt_teams', $data, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'team.update.failed', [ 'db_error' => $err, 'team_id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The team could not be updated.', 'talenttrack' ), 500, [ 'db_error' => $err ] );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    /**
     * DELETE /teams/{id} — soft-archive. Sets archived_at; the row
     * stays in the DB so foreign references in evaluations / sessions
     * / staff assignments don't dangle.
     */
    public static function delete_team( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );
        $ok = $wpdb->update(
            $wpdb->prefix . 'tt_teams',
            [ 'archived_at' => current_time( 'mysql' ), 'archived_by' => get_current_user_id() ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'team.archive.failed', [ 'db_error' => $err, 'team_id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The team could not be archived.', 'talenttrack' ), 500, [ 'db_error' => $err ] );
        }
        return RestResponse::success( [ 'archived' => true, 'id' => $id ] );
    }

    public static function add_player_to_team( \WP_REST_Request $r ) {
        global $wpdb;
        $team_id   = absint( $r['id'] );
        $player_id = absint( $r['player_id'] );
        if ( $team_id <= 0 || $player_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid team or player id.', 'talenttrack' ), 400 );
        }
        $ok = $wpdb->update( $wpdb->prefix . 'tt_players', [ 'team_id' => $team_id ], [ 'id' => $player_id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'team.roster.add.failed', [ 'db_error' => $err, 'team_id' => $team_id, 'player_id' => $player_id ] );
            return RestResponse::error( 'db_error', __( 'The player could not be added to the team.', 'talenttrack' ), 500, [ 'db_error' => $err ] );
        }
        return RestResponse::success( [ 'team_id' => $team_id, 'player_id' => $player_id ] );
    }

    public static function remove_player_from_team( \WP_REST_Request $r ) {
        global $wpdb;
        $team_id   = absint( $r['id'] );
        $player_id = absint( $r['player_id'] );
        if ( $team_id <= 0 || $player_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid team or player id.', 'talenttrack' ), 400 );
        }
        // Only clear if the player is actually on that team (no-op otherwise).
        $ok = $wpdb->update( $wpdb->prefix . 'tt_players', [ 'team_id' => 0 ], [ 'id' => $player_id, 'team_id' => $team_id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'team.roster.remove.failed', [ 'db_error' => $err, 'team_id' => $team_id, 'player_id' => $player_id ] );
            return RestResponse::error( 'db_error', __( 'The player could not be removed from the team.', 'talenttrack' ), 500, [ 'db_error' => $err ] );
        }
        return RestResponse::success( [ 'team_id' => $team_id, 'player_id' => $player_id ] );
    }

    /** @return array<string, mixed> */
    private static function extract( \WP_REST_Request $r ): array {
        return [
            'name'          => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
            'age_group'     => sanitize_text_field( (string) ( $r['age_group'] ?? '' ) ),
            'head_coach_id' => absint( $r['head_coach_id'] ?? 0 ),
            'notes'         => sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) ),
        ];
    }

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    private static function fmtRow( object $t ): array {
        $name  = (string) $t->name;

        // v3.87.1 — head-coach column derives from the staff-assignment
        // store (`hc_names` / `hc_person_ids` from the GROUP_CONCAT
        // sub-select in `list_teams`). Comma-separated link list when
        // multiple HCs are assigned. Falls back to the legacy
        // `tt_teams.head_coach_id` wp-user pointer for teams that haven't
        // adopted the staff-assignment workflow.
        $hc_names_raw      = isset( $t->hc_names ) ? (string) $t->hc_names : '';
        $hc_person_ids_raw = isset( $t->hc_person_ids ) ? (string) $t->hc_person_ids : '';
        $hc_names      = $hc_names_raw !== '' ? explode( '||', $hc_names_raw ) : [];
        $hc_person_ids = $hc_person_ids_raw !== '' ? array_map( 'intval', explode( ',', $hc_person_ids_raw ) ) : [];

        $name_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
            $name !== '' ? $name : '#' . (int) $t->id,
            \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', (int) $t->id )
        );

        $coach_link_html = '';
        $coach           = '';
        $coach_person_id = 0;
        if ( $hc_names ) {
            $links = [];
            foreach ( $hc_names as $i => $hc_name ) {
                $hc_pid = (int) ( $hc_person_ids[ $i ] ?? 0 );
                if ( $hc_pid > 0 ) {
                    $links[] = \TT\Shared\Frontend\Components\RecordLink::inline(
                        (string) $hc_name,
                        \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'people', $hc_pid )
                    );
                } else {
                    $links[] = esc_html( (string) $hc_name );
                }
            }
            $coach_link_html = implode( ', ', $links );
            $coach           = implode( ', ', $hc_names );
            $coach_person_id = (int) ( $hc_person_ids[0] ?? 0 );
        } else {
            // Fallback to legacy pointer.
            $legacy_name = (string) ( $t->legacy_coach_name ?? '' );
            $legacy_pid  = (int) ( $t->legacy_coach_person_id ?? 0 );
            if ( $legacy_name !== '' && $legacy_pid > 0 ) {
                $coach_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
                    $legacy_name,
                    \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'people', $legacy_pid )
                );
            } elseif ( $legacy_name !== '' ) {
                $coach_link_html = esc_html( $legacy_name );
            }
            $coach           = $legacy_name;
            $coach_person_id = $legacy_pid;
        }

        return [
            'id'              => (int) $t->id,
            'name'            => $name,
            'name_link_html'  => $name_link_html,
            'age_group'       => (string) ( $t->age_group ?? '' ),
            'head_coach_id'   => (int) ( $t->head_coach_id ?? 0 ),
            'coach_name'      => $coach,
            'coach_person_id' => $coach_person_id,
            'coach_link_html' => $coach_link_html,
            'notes'           => (string) ( $t->notes ?? '' ),
            'player_count'    => isset( $t->player_count ) ? (int) $t->player_count : null,
            'archived_at'     => $t->archived_at ?? null,
        ];
    }
}
