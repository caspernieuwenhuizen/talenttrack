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
        // #3152 — `$can_view` answers "may you look at teams". `GET /teams`
        // then narrows per row; `GET /teams/{id}` did not, so the row the
        // list omits was still readable one id at a time. This is the same
        // narrowing, asked about one record.
        $can_view_team = static function ( \WP_REST_Request $r ) use ( $can_view ): bool {
            return $can_view()
                && \TT\Modules\Authorization\AllTeamsScope::canReadTeam(
                    get_current_user_id(),
                    (int) $r['id']
                );
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
                'args'                => self::writeArgs(),
                'permission_callback' => $can_edit,
            ],
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_team' ],
                'permission_callback' => $can_view_team,
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_team' ],
                'args'                => self::updateArgs(),
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
        // #2835 — what share of the minutes the team played each player got,
        // and one player's row out of the same answer. Reads the domain
        // service the Minutes share report composes from, so the rendered
        // page and a non-WordPress front end cannot disagree (CLAUDE.md §4).
        // #3152 — both of these take the team id from the path and return
        // per-player minutes out of it. They were gated on the same club-wide
        // cap `GET /teams/{id}` was, and are the same leak seen from the
        // report side, so they take the same predicate.
        // #3796 (epic #3603) — both have always read `from` / `to`, but
        // neither declared them, so the route index published a pair of
        // endpoints that looked like they took no window at all. A board
        // member reported the rolling year as hardcoded for exactly that
        // reason: the capability was there and undiscoverable.
        $minutes_share_window = [
            'from' => [ 'type' => 'string', 'description' => 'Window start, Y-m-d. Defaults to twelve months before `to`. A value that is not a Y-m-d date is ignored rather than refused.' ],
            'to'   => [ 'type' => 'string', 'description' => 'Window end, Y-m-d. Defaults to today. A value that is not a Y-m-d date is ignored rather than refused.' ],
        ];
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/minutes-share', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_minutes_share' ],
                'permission_callback' => $can_view_team,
                'args'                => $minutes_share_window,
            ],
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/minutes-share/(?P<player_id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_player_minutes_share' ],
                'permission_callback' => $can_view_team,
                'args'                => $minutes_share_window,
            ],
        ] );
        // #3520 (epic #3519) — the team's match output as data: record, form,
        // scorers, assists, appearances. Same predicate as the minutes-share
        // routes above, and for the same reason — it takes a team id out of
        // the path and returns per-player rows from it, so the club-wide cap
        // alone would hand a head coach every squad in the academy (#3152).
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/stats', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_team_stats' ],
                'permission_callback' => $can_view_team,
                'args'                => [
                    'from' => [ 'type' => 'string' ],
                    'to'   => [ 'type' => 'string' ],
                ],
            ],
        ] );
        // #3458 (epic #3457) — the team monthly report as data. Gated in the
        // permission callback itself, on the same `reports` read the sibling
        // team reports use, so a refused caller never reaches the composer and
        // its per-player status calculations.
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/monthly-report', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_monthly_report' ],
                'permission_callback' => static fn( \WP_REST_Request $r ): bool => self::canReadTeamReports( absint( $r['id'] ) ),
                'args'                => [
                    'from'   => [ 'type' => 'string' ],
                    'to'     => [ 'type' => 'string' ],
                    'period' => [ 'type' => 'string' ],
                    'blocks' => [ 'type' => 'string' ],
                ],
            ],
        ] );
        // #1470 — archive lifecycle: restore + gated permanent delete.
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/restore', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'restore_team' ],
                'permission_callback' => $can_edit,
            ],
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_team_permanently' ],
                // #2024 security #6 — re-gate onto tt_manage_recycle_bin: no
                // purge path weaker than the bin's own purge.
                'permission_callback' => function () { return current_user_can( 'tt_manage_recycle_bin' ); },
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
        // #2023 — through filterClause (alias 't') so archived/active views
        // also exclude trashed (recycle-bin) rows.
        $archived = isset( $filter['archived'] ) ? sanitize_key( (string) $filter['archived'] ) : 'active';
        $where[] = \TT\Infrastructure\Archive\ArchiveRepository::filterClause(
            $archived === 'archived' ? 'archived' : 'active',
            't'
        );

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

        // #1315 — head-coach column reads exclusively from the
        // staff-assignment store (`tt_team_people` × `tt_functional_roles`
        // role_key='head_coach'). The legacy `tt_teams.head_coach_id`
        // wp-user pointer was retired in this PR; its LEFT JOINs are gone.
        // Multiple HCs comma-separated. GROUP_CONCAT separators: `||` for
        // names (avoids collision with a comma inside a person's name),
        // `,` for ids.
        $list_sql = "SELECT t.*,
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
                            " . self::playerCountSql( $p ) . " AS player_count
                     FROM {$p}tt_teams t
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

        // #1614 — upcoming-activity counts (next 14 days) per team for
        // the teams-list cards. Computed once per page in the repository
        // (one grouped query for the whole page), keyed by team id and
        // passed into fmtRow so the card fragment carries it.
        $team_ids = array_map( static function ( $r ) { return (int) $r->id; }, $rows );
        $upcoming = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )
            ->upcomingCountsByTeam( $team_ids, 14 );

        $fmt = array_map(
            static function ( $row ) use ( $upcoming ) {
                return self::fmtRow( $row, (int) ( $upcoming[ (int) $row->id ] ?? 0 ) );
            },
            $rows
        );

        return RestResponse::success( [
            'rows'     => $fmt,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    public static function get_team( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $id = absint( $r['id'] );
        // #1315 — single-team query mirrors list_teams: head coaches via
        // staff assignments only; legacy `tt_teams.head_coach_id` retired.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*,
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
                    ) AS hc_person_ids,
                    " . self::playerCountSql( $p ) . " AS player_count
             FROM {$p}tt_teams t
             WHERE t.id = %d AND t.club_id = %d",
            $id, CurrentClub::id()
        ) );
        if ( ! $row ) return RestResponse::error( 'not_found', __( 'Team not found.', 'talenttrack' ), 404 );

        // #3601 — the same counts the list carries. The detail selected no
        // player count and passed no upcoming count, so the one route a
        // client opens a team through showed an empty squad with nothing
        // coming up, card included.
        $upcoming = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )
            ->upcomingCountsByTeam( [ $id ], 14 );
        return RestResponse::success( self::fmtRow( $row, (int) ( $upcoming[ $id ] ?? 0 ) ) );
    }

    /**
     * #3601 — the squad-size subselect, shared by the list and the detail
     * so the two cannot count differently again. Expects the team aliased
     * `t`.
     */
    private static function playerCountSql( string $p ): string {
        return "(SELECT COUNT(*) FROM {$p}tt_players pl WHERE pl.team_id = t.id AND pl.archived_at IS NULL AND pl.club_id = t.club_id)";
    }

    /**
     * #2835 — the squad's share of the minutes the team actually played.
     *
     * Window defaults to the rolling twelve months the minutes reports use;
     * `from` / `to` (Y-m-d) narrow it. Team scope is enforced the same way
     * the rendered report enforces it — a coach asking for a team outside
     * their matrix scope gets a 403, not an empty list, because an empty list
     * would read as "this team played nothing".
     *
     * #3796 — the payload carries `outside_window`: how many played matches
     * the resolved window excluded and when they were. Without it a report
     * aimed at the wrong twelve months is indistinguishable from a team that
     * played nothing, which is the one thing an empty minutes report must
     * not be ambiguous about.
     */
    public static function get_minutes_share( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );

        if ( ! self::canReadTeamReports( $id ) ) {
            return RestResponse::error( 'forbidden', __( 'You do not have access to this team.', 'talenttrack' ), 403 );
        }

        [ $from, $to ] = self::minutesShareWindow( $r );

        $data = ( new \TT\Modules\Analytics\Reports\MinutesShareQuery() )->forTeam( $id, $from, $to );

        return RestResponse::success( array_merge(
            [ 'team_id' => $id, 'from' => $from, 'to' => $to ],
            $data
        ) );
    }

    /**
     * #2835 — one player's row out of the same answer, so a player-facing
     * client does not have to fetch and filter the whole squad.
     */
    public static function get_player_minutes_share( \WP_REST_Request $r ): \WP_REST_Response {
        $id        = absint( $r['id'] );
        $player_id = absint( $r['player_id'] );
        if ( $id <= 0 || $player_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid team or player id.', 'talenttrack' ), 400 );
        }

        if ( ! self::canReadTeamReports( $id ) ) {
            return RestResponse::error( 'forbidden', __( 'You do not have access to this team.', 'talenttrack' ), 403 );
        }

        [ $from, $to ] = self::minutesShareWindow( $r );

        $row = ( new \TT\Modules\Analytics\Reports\MinutesShareQuery() )->forPlayer( $id, $player_id, $from, $to );
        if ( $row === null ) {
            return RestResponse::error(
                'not_in_squad',
                __( 'That player has no recorded minutes for this team in this window.', 'talenttrack' ),
                404
            );
        }

        return RestResponse::success( array_merge(
            [ 'team_id' => $id, 'player_id' => $player_id, 'from' => $from, 'to' => $to ],
            $row
        ) );
    }

    /**
     * #3520 — `GET /teams/{id}/stats`.
     *
     * Composition only: the shape comes straight from the domain query, so a
     * non-WordPress front end and the rendered statistics tab cannot disagree
     * about a team's record (CLAUDE.md §4).
     *
     * `from` / `to` are optional and must both be `Y-m-d` when given. Half a
     * window, or a malformed one, is a 400 — quietly falling back to the
     * season would answer a different question than the caller asked.
     */
    public static function get_team_stats( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );

        $from = trim( (string) ( $r['from'] ?? '' ) );
        $to   = trim( (string) ( $r['to'] ?? '' ) );
        $ymd  = static fn ( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );

        $filters = [];
        if ( $from !== '' || $to !== '' ) {
            if ( ! $ymd( $from ) || ! $ymd( $to ) || $from > $to ) {
                return RestResponse::error(
                    'bad_window',
                    __( 'Give both from and to as YYYY-MM-DD, with from on or before to.', 'talenttrack' ),
                    400
                );
            }
            $filters = [ 'from' => $from, 'to' => $to ];
        }

        return RestResponse::success(
            ( new \TT\Modules\Analytics\Reports\TeamMatchStatsQuery() )->forTeam( $id, $filters )
        );
    }

    /**
     * #3458 — `GET /teams/{id}/monthly-report`.
     *
     * Window: explicit `from` + `to` win; otherwise `period` resolves one
     * (`last_month` by default). `blocks` is a comma-separated list; empty
     * means every block. An unknown block or a malformed window is a 400, not
     * a silently different report.
     */
    public static function get_monthly_report( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );

        $from = (string) ( $r['from'] ?? '' );
        $to   = (string) ( $r['to'] ?? '' );
        if ( $from === '' || $to === '' ) {
            $period = sanitize_key( (string) ( $r['period'] ?? '' ) );
            $window = \TT\Modules\Analytics\Reports\TeamMonthlyReport::periodWindow(
                $period !== '' ? $period : 'last_month',
                gmdate( 'Y-m-d' )
            );
            if ( $window === null ) {
                return RestResponse::error( 'bad_period', __( 'Unknown period.', 'talenttrack' ), 400 );
            }
            $from = $window['from'];
            $to   = $window['to'];
        }

        $raw    = trim( (string) ( $r['blocks'] ?? '' ) );
        $blocks = $raw === '' ? [] : array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn( string $k ): bool => $k !== '' ) );

        // #3515 — per-block options travel as JSON, forgivingly: an option a
        // later version dropped must still return a report, not a 400.
        $options = \TT\Modules\Analytics\Reports\TeamMonthlyReportComposition::normalise( [
            'blocks'  => $blocks,
            'options' => $r['options'] ?? null,
        ] )['options'];

        try {
            $report = ( new \TT\Modules\Analytics\Reports\TeamMonthlyReport() )->forTeam( $id, $from, $to, $blocks, get_current_user_id(), $options );
        } catch ( \InvalidArgumentException $e ) {
            return RestResponse::error( 'bad_request', $e->getMessage(), 400 );
        }

        return RestResponse::success( $report );
    }

    /**
     * #2835 — may this user read a report about this team?
     *
     * The same narrowing the rendered report applies: a global `reports`
     * read (head of development, academy admin, read-only observer) sees any
     * team; everyone else is confined to the teams their matrix grant names.
     * Deliberately the `reports` entity rather than `team` — this is a
     * reporting surface, and the two are not the same right.
     */
    private static function canReadTeamReports( int $team_id ): bool {
        // #3460 — one rule for the REST routes and the monthly report's PDF.
        return \TT\Modules\Analytics\Reports\TeamReportAccess::canRead( get_current_user_id(), $team_id );
    }

    /**
     * `from` / `to` off the request, falling back to the rolling twelve
     * months the minutes reports default to. Anything that is not a Y-m-d
     * date is ignored rather than 400'd — a client sending junk gets the
     * default window, which is the same answer the report gives.
     *
     * @return array{0:string,1:string}
     */
    private static function minutesShareWindow( \WP_REST_Request $r ): array {
        $raw_from = (string) ( $r['from'] ?? '' );
        $raw_to   = (string) ( $r['to'] ?? '' );
        $valid    = static fn ( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );

        $to = $valid( $raw_to ) ? $raw_to : gmdate( 'Y-m-d' );
        if ( $valid( $raw_from ) ) {
            return [ $raw_from, $to ];
        }

        // `strtotime()` returns false on an unparseable string. `$to` is a
        // validated Y-m-d by this point, so it cannot — but the fallback keeps
        // the window honest rather than handing gmdate() a false.
        $ts = strtotime( $to . ' -12 months' );

        return [ gmdate( 'Y-m-d', $ts !== false ? $ts : time() ), $to ];
    }

    /**
     * #3817 (slice 3 of #3603) — the body `POST /teams` and
     * `PUT /teams/{id}` accept.
     *
     * Nothing is declared `required`: core checks required params before the
     * permission callback, so a required field answers an unauthenticated
     * `POST` with a `400` naming the fields instead of the `401` it owes.
     * `create_team()` names `name` itself, behind the capability gate, in
     * the same `missing_fields` envelope. The same reasoning, and the same
     * choice, as `ActivitiesRestController::writeArgs()`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function writeArgs(): array {
        return [
            'name'           => [ 'type' => 'string', 'description' => 'What the team is called. Required on create; on update, sending it blank is refused rather than stored.' ],
            'age_group'      => [ 'type' => 'string', 'description' => 'The age group, e.g. JO17.' ],
            'notes'          => [ 'type' => 'string', 'description' => 'Free text about the team.' ],
            'methodology_id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'Per-team methodology set override. 0 or blank clears it and the team follows the install default.' ],
            'football_form'  => [ 'type' => 'string', 'description' => 'How many a side this team plays. An unknown value clears the override rather than being stored.' ],
        ];
    }

    /**
     * #3817 — `PUT /teams/{id}` on top of `writeArgs()`. `id` comes from the
     * URL; a copy in the body is accepted and ignored.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function updateArgs(): array {
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The team, from the URL. A copy in the body is accepted and ignored.',
        ] ] + self::writeArgs();
    }

    public static function create_team( \WP_REST_Request $r ) {
        // #3817 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::writeArgs() );
        if ( $refused !== null ) return $refused;

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
            // #3817 — name the field. The message said which one in prose
            // and left `details` empty, so a client had to parse English.
            return RestResponse::error(
                'missing_fields',
                __( 'Team name is required.', 'talenttrack' ),
                400,
                [ 'fields' => [ 'name' ] ]
            );
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

        // #3817 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::updateArgs() );
        if ( $refused !== null ) return $refused;

        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );

        // #3817 — absent from the payload means leave it alone (CLAUDE.md
        // §6). This used to write `extract()` whole, which defaults every
        // missing key to empty: a PUT carrying only a name erased the age
        // group and the notes. The edit form posts the record whole so it
        // never showed, but an integration, the planner or a future
        // per-panel save all send a slice, and none of them may clear what
        // they leave out. Every `extract()` key is named after its request
        // param, so keeping the sent keys is the whole rule.
        $data = array_intersect_key( self::extract( $r ), (array) $r->get_params() );

        // A name is what a team is found by, so a *sent* blank is refused
        // rather than stored. An absent one is untouched, not missing.
        if ( array_key_exists( 'name', $data ) && $data['name'] === '' ) {
            return RestResponse::error(
                'missing_fields',
                __( 'Team name is required.', 'talenttrack' ),
                400,
                [ 'fields' => [ 'name' ] ]
            );
        }

        // An empty `$wpdb->update()` is an error rather than a no-op.
        if ( $data === [] ) return RestResponse::success( [ 'id' => $id ] );

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
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );

        // #2411 — the archive now routes through ArchiveRepository rather
        // than stamping the row here, so the team-activities cascade (and
        // its audit trail, which restore reads back) lives in one place.
        // `cascade_activities` is opt-in from the confirm dialog's checkbox.
        $cascade = $r->get_param( 'cascade_activities' );
        $opts    = [ 'cascade_activities' => $cascade === null ? false : (bool) $cascade ];

        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )
                ->archive( 'team', [ $id ], get_current_user_id(), $opts );
        } catch ( \Throwable $e ) {
            Logger::error( 'team.archive.failed', [ 'error' => $e->getMessage(), 'team_id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The team could not be archived.', 'talenttrack' ), 500 );
        }

        if ( $n === 0 ) {
            return RestResponse::error( 'not_archived', __( 'The team could not be archived.', 'talenttrack' ), 404 );
        }

        return RestResponse::success( [
            'archived'           => true,
            'id'                 => $id,
            'cascade_activities' => ! empty( $opts['cascade_activities'] ),
        ] );
    }

    /** #1470 — restore an archived team. */
    public static function restore_team( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );
        $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->restore( 'team', [ $id ] );
        if ( $n === 0 ) return RestResponse::error( 'not_found', __( 'Team not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'restored' => true, 'id' => $id ] );
    }

    /** #1470 — permanently delete a team (irreversible). Gated by tt_edit_settings. */
    public static function delete_team_permanently( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid team id.', 'talenttrack' ), 400 );
        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'team', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) return RestResponse::error( 'not_found', __( 'Team not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function add_player_to_team( \WP_REST_Request $r ) {
        global $wpdb;
        $team_id   = absint( $r['id'] );
        $player_id = absint( $r['player_id'] );
        if ( $team_id <= 0 || $player_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid team or player id.', 'talenttrack' ), 400 );
        }
        // v4.20.40 (#1200) — Audit 2 (#1176) flagged the cross-club
        // reassign class. Pre-fix `team_id` came from the path
        // parameter unchecked. A coach could reassign one of their
        // club's players to a `team_id` belonging to another club —
        // the player's `club_id` stayed put but their `team_id`
        // pointed at a foreign team, breaking every JOIN in
        // dashboards / evaluations / attendance / tournaments.
        // Verify the team exists in the writer's club before the
        // UPDATE; falls through to 404 with a clean error code
        // otherwise.
        if ( QueryHelpers::get_team( $team_id ) === null ) {
            return RestResponse::error( 'team_not_found', __( 'Team not found in your club.', 'talenttrack' ), 404 );
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
        // #1315 — `head_coach_id` retired. Head-coach assignment lives
        // entirely in `tt_team_people` via the Staff section; the legacy
        // wp-user pointer no longer ships in the request shape.
        $data = [
            'name'      => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
            'age_group' => sanitize_text_field( (string) ( $r['age_group'] ?? '' ) ),
            'notes'     => sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) ),
        ];

        // #2320 — per-team methodology set override (epic #2316). Only
        // written when the field is present so callers that don't manage
        // it (e.g. the wizard) leave the column untouched; 0 / blank
        // clears the override (NULL) → the team uses the install default.
        if ( $r->has_param( 'methodology_id' ) ) {
            $mid = absint( $r['methodology_id'] );
            $data['methodology_id'] = $mid > 0 ? $mid : null;
        }

        // #3044 — how many a side this team plays. Only written when the
        // field is present, so a caller that does not manage it (the team
        // wizard, an integration) leaves the column alone; blank clears the
        // override (NULL) and the team follows its age group's default. An
        // unknown value is refused rather than stored, because a team
        // resolving to a form nobody plays would silently empty the
        // blueprint picker.
        if ( $r->has_param( 'football_form' ) ) {
            $form = sanitize_text_field( (string) $r['football_form'] );
            $data['football_form'] = in_array( $form, \TT\Modules\Teams\FootballFormResolver::forms(), true )
                ? $form
                : null;
        }

        return $data;
    }

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    private static function fmtRow( object $t, int $upcoming_count = 0 ): array {
        $name  = (string) $t->name;

        // #1315 — head-coach column derives exclusively from the
        // staff-assignment store (`hc_names` / `hc_person_ids` from
        // the GROUP_CONCAT sub-select). Legacy `tt_teams.head_coach_id`
        // fallback retired.
        $hc_names_raw      = isset( $t->hc_names ) ? (string) $t->hc_names : '';
        $hc_person_ids_raw = isset( $t->hc_person_ids ) ? (string) $t->hc_person_ids : '';
        $hc_names      = $hc_names_raw !== '' ? explode( '||', $hc_names_raw ) : [];
        $hc_person_ids = $hc_person_ids_raw !== '' ? array_map( 'intval', explode( ',', $hc_person_ids_raw ) ) : [];

        $detail_url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', (int) $t->id );
        $name_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
            $name !== '' ? $name : '#' . (int) $t->id,
            $detail_url
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
        }

        $age_group    = (string) ( $t->age_group ?? '' );
        $player_count = isset( $t->player_count ) ? (int) $t->player_count : null;

        // #1614 — pre-built Variant B card fragment for the teams-list
        // card grid. Mirrors the `name_link_html` pattern above: the
        // presentation component renders escaped HTML server-side so the
        // list hydrator can emit it verbatim. The whole card is one <a>
        // → the team detail page (with the tt_back hint already baked
        // into $detail_url).
        $card_html = \TT\Shared\Frontend\Components\TeamCard::html( [
            'id'             => (int) $t->id,
            'name'           => $name,
            'age_group'      => $age_group,
            'coach_name'     => $coach,
            'player_count'   => $player_count ?? 0,
            'upcoming_count' => $upcoming_count,
            'detail_url'     => $detail_url,
        ] );

        return [
            'id'              => (int) $t->id,
            'name'            => $name,
            'name_link_html'  => $name_link_html,
            'age_group'       => $age_group,
            // #3044 — `football_form` is the team's own override (empty when
            // it has none); `football_form_resolved` is what the team
            // actually plays, so a consumer never has to re-derive the
            // age-group fallback.
            'football_form'          => (string) ( $t->football_form ?? '' ),
            'football_form_resolved' => \TT\Modules\Teams\FootballFormResolver::forTeamRow( $t ),
            'coach_name'      => $coach,
            'coach_person_id' => $coach_person_id,
            'coach_link_html' => $coach_link_html,
            'notes'           => (string) ( $t->notes ?? '' ),
            'player_count'    => $player_count,
            // #1614 — next-14-day activity count + pre-rendered card.
            'upcoming_count'  => $upcoming_count,
            'card_html'       => $card_html,
            // v3.110.170 — row-link standard (#758).
            'detail_url'      => $detail_url,
            // #2023 — archived_at + trashed_at via the shared lifecycle helper.
        ] + \TT\Infrastructure\Archive\LifecycleFields::forRow( $t );
    }
}
