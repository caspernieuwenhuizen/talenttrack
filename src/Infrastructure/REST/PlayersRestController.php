<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerSex;
use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Players\PlayerDates;
use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Query\LookupPill;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\PlayerCsvImporter;
use TT\Modules\Players\Services\ScoutPlayerCard;
use TT\Shared\Validation\CustomFieldValidator;

/**
 * PlayersRestController — /wp-json/talenttrack/v1/players
 *
 * v2.8.0: permission_callback now uses AuthorizationService instead of raw
 * current_user_can(). Individual routes are entity-scoped where appropriate
 * (GET /players/{id} checks canViewPlayer for that specific player, etc.).
 * The generic list GET remains gated on "logged in" because the
 * results are filtered per-user further down the stack.
 */
class PlayersRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_players' ],
                'args'                => self::listArgs(),
                // #0052 PR-B — gate on `tt_view_players` instead of bare
                // login. The list query already filters per-row by team
                // scoping; this prevents a logged-in user without any
                // player-view rights from hitting the endpoint at all.
                'permission_callback' => function () { return current_user_can( 'tt_view_players' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_player' ],
                'args'                => self::writeArgs(),
                'permission_callback' => function () {
                    // Creating a new player is reserved for users with the
                    // manage_players capability. AuthorizationService has no
                    // per-entity check for creation (no target entity yet).
                    return current_user_can( 'tt_edit_players' );
                },
            ],
        ]);
        register_rest_route( self::NS, '/players/import', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'import_players' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_players' ); },
                'args'                => self::importArgs(),
            ],
        ]);
        // #3807 — the thin scout card. Its own route on purpose, NOT a
        // widening of `players/{id}`: that one returns the full record,
        // guardian contact and every custom field a club has defined
        // included, with no per-field filter. The field list lives in
        // `ScoutPlayerCard` and is the point of it.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/scout-card', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_scout_card' ],
                'permission_callback' => static function ( \WP_REST_Request $r ): bool {
                    return ScoutPlayerCard::canRead( get_current_user_id(), (int) $r['id'] );
                },
            ],
        ] );
        // #3872 (epic #3871) — the player report as data. The same composer
        // the online view and the PDF read, so an API consumer gets the
        // report a coach prints. Refusal does not say whether the player
        // exists: an out-of-scope id and a missing one both answer 403.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/report', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_player_report' ],
                'permission_callback' => static function ( \WP_REST_Request $r ): bool {
                    return \TT\Modules\Analytics\Reports\PlayerReportAccess::canRead( get_current_user_id(), (int) $r['id'] );
                },
                'args'                => [
                    'period' => [ 'type' => 'string', 'description' => 'last_week, last_month, this_month or this_season. Omit for the season so far.' ],
                    'from'   => [ 'type' => 'string', 'description' => 'Y-m-d. With `to`, overrides `period`.' ],
                    'to'     => [ 'type' => 'string', 'description' => 'Y-m-d. With `from`, overrides `period`.' ],
                    'blocks' => [ 'type' => 'string', 'description' => 'Comma-separated block keys. Omit for the conversation set; an unknown key is refused.' ],
                ],
            ],
        ] );
        // #3890 — frozen player reports. Every route answers to the snapshot's
        // own player: an unknown uuid and one the caller may not read are the
        // same 403, so a uuid cannot be probed for existence.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/report-snapshots', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_report_snapshots' ],
                'permission_callback' => static function ( \WP_REST_Request $r ): bool {
                    return \TT\Modules\Analytics\Reports\PlayerReportAccess::canRead( get_current_user_id(), (int) $r['id'] );
                },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_report_snapshot' ],
                'permission_callback' => static function ( \WP_REST_Request $r ): bool {
                    return \TT\Modules\Analytics\Reports\PlayerReportAccess::canRead( get_current_user_id(), (int) $r['id'] );
                },
                'args'                => [
                    'period' => [ 'type' => 'string', 'description' => 'last_week, last_month, this_month or this_season. Omit for the season so far.' ],
                    'from'   => [ 'type' => 'string', 'description' => 'Y-m-d. With `to`, overrides `period`.' ],
                    'to'     => [ 'type' => 'string', 'description' => 'Y-m-d. With `from`, overrides `period`.' ],
                    'layout' => [ 'type' => 'string', 'description' => 'A (one-pager) or B (two-page pack), for the snapshot\'s PDF.' ],
                    'blocks' => [ 'type' => 'string', 'description' => 'Comma-separated block keys. Omit for the conversation set.' ],
                    'title'  => [ 'type' => 'string', 'description' => 'Omit for the player\'s name and today\'s date.' ],
                ],
            ],
        ] );
        register_rest_route( self::NS, '/player-report-snapshots/(?P<uuid>[0-9a-f-]{36})', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_report_snapshot' ],
                'permission_callback' => static function ( \WP_REST_Request $r ): bool {
                    return \TT\Modules\Analytics\Reports\PlayerReportSnapshots::read( (string) $r['uuid'], get_current_user_id() ) !== null;
                },
            ],
        ] );
        register_rest_route( self::NS, '/player-report-snapshots/(?P<uuid>[0-9a-f-]{36})/notes/(?P<section>[a-z_]+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_report_snapshot_note' ],
                'permission_callback' => static function ( \WP_REST_Request $r ): bool {
                    return \TT\Modules\Analytics\Reports\PlayerReportSnapshots::read( (string) $r['uuid'], get_current_user_id() ) !== null;
                },
                'args'                => [
                    'body' => [ 'type' => 'string', 'description' => 'The note. Empty removes it.' ],
                ],
            ],
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_player' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canViewPlayer(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_player' ],
                'args'                => self::updateArgs(),
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    return AuthorizationService::canEditPlayer(
                        get_current_user_id(),
                        (int) $r['id']
                    );
                },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_player' ],
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    // Delete is strictly a manage_players capability since
                    // it's destructive. Team-scoped editors (coaches) should
                    // not be able to delete players they merely coach.
                    return current_user_can( 'tt_edit_players' );
                },
            ],
        ]);
        // #1470 — archive lifecycle: restore + gated permanent delete.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/restore', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'restore_player' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_players' ); },
                'args'                => self::lifecycleArgs(),
            ],
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_player_permanently' ],
                // #2024 security #6 — re-gate onto tt_manage_recycle_bin: no
                // purge path weaker than the bin's own purge.
                'permission_callback' => function () { return current_user_can( 'tt_manage_recycle_bin' ); },
            ],
        ] );
        // #2023 — reversible "Move to recycle bin" (archived → trashed).
        // Replaces the archived-tier permanent delete in the list UI; the
        // real purge stays bin-only (#2024). Cap matches the destructive-op
        // gate; ownership re-checked in the handler.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/trash', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'trash_player' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_settings' ); },
                'args'                => self::lifecycleArgs(),
            ],
        ] );
    }

    /**
     * #3819 — the body `POST /players/import` takes alongside the uploaded
     * file, which arrives as multipart rather than in the body.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function importArgs(): array {
        return [
            'dry_run'       => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Preview the import without writing. Anything but "0" previews, so a commit has to say so.' ],
            'dupe_strategy' => [ 'type' => 'string', 'description' => 'What to do with a row that matches an existing player: skip, update or create. An unknown value skips.' ],
        ];
    }

    /**
     * #3819 — restore and trash act on the player in the URL and take no
     * body of their own.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function lifecycleArgs(): array {
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The player, from the URL. A copy in the body is accepted and ignored.',
        ] ];
    }

    /** #2023 — move an archived player into the recycle bin (reversible). */
    public static function trash_player( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::lifecycleArgs() );
        if ( $refused !== null ) return $refused;

        return \TT\Infrastructure\Archive\RecycleBinRestActions::trash(
            'player', absint( $r['id'] ), __( 'Player not found.', 'talenttrack' )
        );
    }

    /** #1470 — restore an archived player. */
    public static function restore_player( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::lifecycleArgs() );
        if ( $refused !== null ) return $refused;

        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid player id.', 'talenttrack' ), 400 );
        }
        $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->restore( 'player', [ $id ] );
        if ( $n === 0 ) {
            return RestResponse::error( 'not_found', __( 'Player not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'restored' => true, 'id' => $id ] );
    }

    /** #1470 — permanently delete a player + cascade (irreversible). Gated by tt_edit_settings. */
    public static function delete_player_permanently( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid player id.', 'talenttrack' ), 400 );
        }
        $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'player', [ $id ] );
        if ( $n === 0 ) {
            return RestResponse::error( 'not_found', __( 'Player not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    /** Whitelist of columns the `orderby` query param accepts. */
    private const LIST_ORDERBY_WHITELIST = [
        'last_name'     => 'p.last_name',
        'first_name'    => 'p.first_name',
        'team_name'     => 't.name',
        'jersey_number' => 'p.jersey_number',
        'date_of_birth' => 'p.date_of_birth',
        'date_joined'   => 'p.date_joined',
        // #3804 — sorts the unconsented to the top, which is the order
        // somebody working through the gap wants to read the list in.
        'media_consent' => 'p.media_consent',
    ];

    /**
     * #3856 — the list filters, each readable under its plain name as well
     * as nested under `filter[...]`. The nested spelling wins when both are
     * sent, the precedence #3584, #3607, #3668, #3765 and #3790 settled on.
     *
     * @var array<string,string>
     */
    private const LIST_FILTERS = [
        'team_id'        => 'Only players on this team.',
        'position'       => 'Only players with this preferred position.',
        'preferred_foot' => 'left, right or both.',
        'age_group'      => "Only players whose team sits in this age group, e.g. U13.",
        'archived'       => 'active (default) or archived.',
        'status'         => 'active, trial, released, inactive — matches the player status.',
        'assignment'     => 'unassigned — active players who sit without a team.',
        'media_consent'  => 'no_consent, recorded, or media_no_consent (pictures on file, no consent).',
    ];

    /**
     * #3856 — the list's parameters, so route discovery publishes them and
     * a caller can see that both spellings are read.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function listArgs(): array {
        $args = [];
        foreach ( self::LIST_FILTERS as $key => $description ) {
            $args[ $key ] = [
                'type'        => $key === 'team_id' ? [ 'integer', 'string' ] : 'string',
                'description' => $description,
            ];
        }
        return $args + [
            'filter'   => [ 'type' => 'object', 'description' => 'The same filters, nested: filter[team_id]=…' ],
            'search'   => [ 'type' => 'string', 'description' => 'Matches the first or last name.' ],
            'orderby'  => [ 'type' => 'string', 'description' => 'Column to sort on.' ],
            'order'    => [ 'type' => 'string', 'description' => 'asc or desc.' ],
            'page'     => [ 'type' => [ 'integer', 'string' ], 'description' => 'Page number, from 1.' ],
            'per_page' => [ 'type' => [ 'integer', 'string' ], 'description' => 'Rows per page.' ],
        ];
    }

    /**
     * #3856 — fold the plain filter names into the nested array the query
     * below reads, and refuse a value nobody can act on.
     *
     * `?team_id=73` used to be neither applied nor refused: WP REST drops a
     * query parameter no route declared, without a word, so an
     * administrator asking for one squad got every player they may read
     * back — 64 children across four age groups, each row carrying a real
     * team name that made the answer look deliberate. Dropping the filter
     * is the widening; refusing an unusable one is the fix.
     *
     * @param array<mixed> $filter the nested `filter[...]` array as sent
     * @return array{filter:array<mixed>, error:\WP_REST_Response|null}
     */
    private static function resolveListFilters( \WP_REST_Request $r, array $filter ): array {
        foreach ( array_keys( self::LIST_FILTERS ) as $key ) {
            if ( array_key_exists( $key, $filter ) ) {
                if ( ! is_scalar( $filter[ $key ] ) ) {
                    return [ 'filter' => [], 'error' => self::badFilter( 'filter[' . $key . ']' ) ];
                }
                if ( (string) $filter[ $key ] !== '' ) continue;
                unset( $filter[ $key ] );
            }
            $plain = $r->get_param( $key );
            if ( $plain === null ) continue;
            if ( ! is_scalar( $plain ) ) {
                return [ 'filter' => [], 'error' => self::badFilter( $key ) ];
            }
            if ( (string) $plain === '' ) continue;
            $filter[ $key ] = $plain;
        }

        // The squad filter is the one that carries an id, so it is the one
        // that can be present and still unreadable. An id that resolves to
        // nothing is refused rather than ignored — see above.
        if ( isset( $filter['team_id'] ) && absint( $filter['team_id'] ) <= 0 ) {
            $name = array_key_exists( 'team_id', is_array( $r->get_param( 'filter' ) ) ? $r->get_param( 'filter' ) : [] )
                ? 'filter[team_id]'
                : 'team_id';
            return [ 'filter' => [], 'error' => self::badFilter( $name ) ];
        }

        return [ 'filter' => $filter, 'error' => null ];
    }

    /** #3856 — the one refusal a filter nobody can resolve earns, naming the spelling at fault. */
    private static function badFilter( string $parameter ): \WP_REST_Response {
        return RestResponse::error(
            'bad_filter',
            __( 'That filter value cannot be read.', 'talenttrack' ),
            400,
            [ 'parameter' => $parameter ]
        );
    }

    /**
     * GET /players — paginated list with search, filters, sort.
     *
     * #0019 Sprint 3 session 3.1 — replaces the v2.x bare-bones list
     * with the Sprint 2 contract that `FrontendListTable` consumes.
     *
     * Every filter below is read from `filter[<name>]` first and the plain
     * `<name>` second (#3856); the nested spelling wins when both are sent.
     *
     * Query params:
     *   ?search=<text>             — first/last name LIKE
     *   ?team_id=<int> | ?filter[team_id]=<int>
     *   ?position=<string>         — matches anywhere in preferred_positions JSON array
     *   ?preferred_foot=<string>
     *   ?age_group=<string>        — matches the team's age_group (e.g. "U13")
     *   ?archived=<active|archived> — default active only
     *   ?status=<active|trial|released|inactive|…> — optional, matches `tt_players.status`
     *   ?assignment=unassigned     — active players without a team
     *   ?media_consent=<no_consent|recorded|media_no_consent>
     *   ?orderby=last_name|first_name|team_name|jersey_number|date_of_birth|date_joined
     *   ?order=asc|desc                                   (default: asc on last_name, desc otherwise)
     *   ?page=<int>                                       (default 1)
     *   ?per_page=10|25|50|100                            (default 25)
     *
     * Authorization: row-level visibility filter via AuthorizationService.
     */
    public static function list_players( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );

        $orderby_key = sanitize_key( (string) ( $r['orderby'] ?? 'last_name' ) );
        if ( ! isset( self::LIST_ORDERBY_WHITELIST[ $orderby_key ] ) ) {
            return RestResponse::error(
                'bad_orderby',
                __( 'Unknown orderby column.', 'talenttrack' ),
                400,
                [ 'allowed' => array_keys( self::LIST_ORDERBY_WHITELIST ) ]
            );
        }
        $orderby = self::LIST_ORDERBY_WHITELIST[ $orderby_key ];
        $order   = strtolower( (string) ( $r['order'] ?? ( $orderby_key === 'last_name' ? 'asc' : 'desc' ) ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'asc';

        $where  = [ '1=1', 'p.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        $scope = QueryHelpers::apply_demo_scope( 'p', 'player' );

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        $read   = self::resolveListFilters( $r, $filter );
        if ( $read['error'] !== null ) {
            return $read['error'];
        }
        $filter = $read['filter'];
        // #2023 — go through filterClause so the archived/active views also
        // exclude trashed (recycle-bin) rows. A trashed minor's row must
        // never surface in an ordinary list — only in the bin view.
        $archived = isset( $filter['archived'] ) ? sanitize_key( (string) $filter['archived'] ) : 'active';
        $where[] = \TT\Infrastructure\Archive\ArchiveRepository::filterClause(
            $archived === 'archived' ? 'archived' : 'active',
            'p'
        );

        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 'p.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }
        // #0093 — "Unassigned" surface: active players sitting without a
        // team. Reached two ways today — direct via the Status filter on
        // the list, and indirect via the "Assign to team" CTA on the
        // player file. The bucket exists because the trial → team-offer
        // flow flips the player to `status='active'` without
        // simultaneously setting `team_id`, so admitted players land in
        // limbo until a coach picks them up.
        if ( ! empty( $filter['assignment'] ) && (string) $filter['assignment'] === 'unassigned' ) {
            $where[] = '(p.team_id IS NULL OR p.team_id = 0)';
        }
        if ( ! empty( $filter['preferred_foot'] ) ) {
            $where[]  = 'p.preferred_foot = %s';
            $params[] = sanitize_text_field( (string) $filter['preferred_foot'] );
        }
        // v4.20.23 (#1209) — `filter[status]` matches against
        // `tt_players.status` (active/trial/released/inactive/…). Used by
        // `ActivePlayersTotal::linkUrl()` so the KPI's "active players"
        // count matches the destination list 1:1 — pre-fix the KPI
        // filtered `p.status = 'active'` while the list did not, so a
        // trial / released player with `archived_at IS NULL` showed in
        // the list but not the count.
        if ( ! empty( $filter['status'] ) ) {
            $where[]  = 'p.status = %s';
            $params[] = sanitize_text_field( (string) $filter['status'] );
        }
        if ( ! empty( $filter['position'] ) ) {
            // preferred_positions is a JSON array — match the value as a
            // standalone token in the JSON string. Good enough for the
            // typical 4-6 element arrays this column carries.
            $where[]  = 'p.preferred_positions LIKE %s';
            $params[] = '%"' . $wpdb->esc_like( (string) $filter['position'] ) . '"%';
        }
        if ( ! empty( $filter['age_group'] ) ) {
            $where[]  = 't.age_group = %s';
            $params[] = sanitize_text_field( (string) $filter['age_group'] );
        }
        // #3804 — the administrator's actual question, in one call.
        //
        // `no_consent` is the plain flag. `media_no_consent` is the one this
        // issue exists for: children who HAVE pictures on file and no
        // consent recorded. Reading down a list of every unconsented child
        // does not answer it — most of them have no photos, and the ones
        // that matter are lost among them.
        $consent_filter = sanitize_key( (string) ( $filter['media_consent'] ?? '' ) );
        if ( $consent_filter === 'no_consent' ) {
            $where[] = "( p.media_consent IS NULL OR p.media_consent = 0 )";
        } elseif ( $consent_filter === 'recorded' ) {
            $where[] = 'p.media_consent = 1';
        } elseif ( $consent_filter === 'media_no_consent' ) {
            $where[] = "( p.media_consent IS NULL OR p.media_consent = 0 )
                        AND EXISTS (
                            SELECT 1 FROM {$p}tt_media_links ml
                              JOIN {$p}tt_media m ON m.id = ml.media_id
                             WHERE ml.entity_type = 'player'
                               AND ml.entity_id = p.id
                               AND ml.club_id = p.club_id
                               AND m.archived_at IS NULL
                        )";
        }

        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = '(p.first_name LIKE %s OR p.last_name LIKE %s)';
            $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        // #2331 — authorize BEFORE paginating. canViewPlayer is the sole
        // coach/parent visibility gate (there is no team scope in the SQL
        // WHERE), so applying it AFTER LIMIT/OFFSET under-fills the page and
        // strands authorized players sorted past the first SQL page — while
        // `total`, computed over the whole set, disagrees with the rendered
        // rows. Instead: fetch the full ordered id set, authorize it once
        // (single source of truth), then paginate the authorized ids and
        // hydrate full rows for just that page.
        $user_id = get_current_user_id();
        $offset  = ( $page - 1 ) * $per_page;

        // Ordered ids over the same WHERE + joins the ORDER BY may reference.
        $id_sql = "SELECT p.id FROM {$p}tt_players p
                   LEFT JOIN {$p}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id
                   WHERE {$where_sql}
                   ORDER BY {$orderby} {$order}";
        $all_ids = $params
            ? $wpdb->get_col( $wpdb->prepare( $id_sql, ...$params ) )
            : $wpdb->get_col( $id_sql );

        // #1359 — canViewPlayer resolves from AuthorizationService's
        // per-request caches after the first call (role scopes + team links
        // load once), so this is O(ids) array work, not N+1 queries.
        // Replicating the matrix + parent-link + team-scope logic in SQL
        // would fork the authorization rules into a second implementation.
        $auth_ids = array_values( array_filter(
            array_map( 'intval', (array) $all_ids ),
            static function ( $pid ) use ( $user_id ) {
                return AuthorizationService::canViewPlayer( $user_id, $pid );
            }
        ) );
        $total = count( $auth_ids );

        // #3807 — say "forbidden" out loud. The door and the rows used to
        // disagree about which gate matters: this route admits anyone
        // holding `tt_view_players`, then filters every row away with
        // `canViewPlayer` and answers 200 with `{"rows":[],"total":0}`.
        // A scout lost a week believing the screen was broken, and
        // reported a bug instead of asking for access.
        //
        // The distinction is the caller's entitlement, NOT this query's
        // result. Refusing whenever the filter's own rows all fail the
        // check would tell a coach searching "Jansen" that a Jansen exists
        // on somebody else's team — a 403 would be the disclosure the
        // filtering exists to prevent. So a caller entitled to nobody is
        // refused; a caller entitled to somebody gets an honest empty page
        // when their filter matched nothing they may see.
        if ( $auth_ids === [] && ! PlayerVisibility::entitledToAny( $user_id ) ) {
            return RestResponse::error(
                'no_players_visible',
                PlayerVisibility::refusalMessage(),
                403
            );
        }

        // Page the authorized ids, then hydrate full rows for just this page.
        // #3572 — the parent comes from `tt_player_parents`, the one parent
        // model every access check already reads: the primary link (then
        // the oldest) and a count of all links. It used to come from
        // `parent_person_id`, which only the retired wp-admin picker wrote,
        // so a parent linked the supported way read as "no parent" here.
        // SQL `IN` does not preserve order, so reorder in PHP to match the
        // authorized page.
        $page_ids = array_slice( $auth_ids, $offset, $per_page );
        $rows = [];
        if ( $page_ids ) {
            $users    = $wpdb->users;
            $in       = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
            $rows_sql = "SELECT p.*, t.name AS team_name, t.age_group AS team_age_group,
                                pu.ID AS parent_id,
                                pu.display_name AS parent_display_name,
                                ( SELECT COUNT(*) FROM {$p}tt_player_parents ppc
                                   WHERE ppc.player_id = p.id AND ppc.club_id = p.club_id ) AS parent_count,
                                -- #3804 — how many media items hang off this
                                -- player. Counts ATTACHMENTS (tt_media_links),
                                -- which is the right unit: one squad photo
                                -- linked to eleven children is one item each,
                                -- not one item. Archived items are excluded,
                                -- so a cleared-out player reads as clear.
                                ( SELECT COUNT(*)
                                    FROM {$p}tt_media_links ml
                                    JOIN {$p}tt_media m ON m.id = ml.media_id
                                   WHERE ml.entity_type = 'player'
                                     AND ml.entity_id = p.id
                                     AND ml.club_id = p.club_id
                                     AND m.archived_at IS NULL ) AS media_count
                         FROM {$p}tt_players p
                         LEFT JOIN {$p}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id
                         LEFT JOIN {$users} pu ON pu.ID = (
                             SELECT pp.parent_user_id FROM {$p}tt_player_parents pp
                              WHERE pp.player_id = p.id AND pp.club_id = p.club_id
                              ORDER BY pp.is_primary DESC, pp.created_at ASC, pp.parent_user_id ASC
                              LIMIT 1
                         )
                         WHERE p.id IN ($in)";
            $fetched = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$page_ids ) ) ?: [];
            $by_id   = [];
            foreach ( $fetched as $row ) {
                $by_id[ (int) $row->id ] = $row;
            }
            foreach ( $page_ids as $pid ) {
                if ( isset( $by_id[ $pid ] ) ) {
                    $rows[] = $by_id[ $pid ];
                }
            }
        }

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'fmtRow' ], $rows ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    /**
     * #3807 — `GET /players/{id}/scout-card`.
     *
     * Composition only: the field list and the entitlement both live in
     * `ScoutPlayerCard`, so the rendered card and a non-WordPress front
     * end cannot disagree about what a scout may read about a child
     * (CLAUDE.md §4).
     */
    public static function get_scout_card( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['id'] );
        $card      = ScoutPlayerCard::forPlayer( $player_id, get_current_user_id() );
        if ( $card === null ) {
            return RestResponse::error( 'not_found', __( 'Player not found.', 'talenttrack' ), 404 );
        }

        return RestResponse::success( [ 'card' => $card ] );
    }

    /**
     * #3872 — `GET /players/{id}/report`. Defaults to the season so far and
     * the conversation set; refuses an unknown block key or period rather
     * than quietly returning a different report than was asked for.
     */
    public static function get_player_report( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['id'] );

        $blocks  = \TT\Modules\Analytics\Reports\PlayerReportComposition::rawBlocks( $r['blocks'] ?? '' );
        $unknown = \TT\Modules\Analytics\Reports\PlayerReportBlock::unknown( $blocks );
        if ( $unknown !== [] ) {
            return RestResponse::error(
                'unknown_blocks',
                sprintf(
                    /* translators: %s = comma-separated list of block keys */
                    __( 'Unknown report sections: %s', 'talenttrack' ),
                    implode( ', ', $unknown )
                ),
                400,
                [ 'blocks' => $unknown ]
            );
        }

        $period = sanitize_key( (string) ( $r['period'] ?? '' ) );
        if ( $period !== '' && ! in_array( $period, \TT\Modules\Analytics\Reports\ReportFilters::PERIODS, true ) ) {
            return RestResponse::error( 'bad_period', __( 'Unknown period.', 'talenttrack' ), 400, [ 'period' => $period ] );
        }

        $from = (string) ( $r['from'] ?? '' );
        $to   = (string) ( $r['to'] ?? '' );
        if ( ( $from !== '' || $to !== '' )
            && ! ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && $from <= $to )
        ) {
            return RestResponse::error( 'bad_window', __( 'from and to must both be dates (YYYY-MM-DD), with from on or before to.', 'talenttrack' ), 400 );
        }

        $composition = \TT\Modules\Analytics\Reports\PlayerReportComposition::normalise( [
            'player_id' => $player_id,
            'period'    => $period,
            'from'      => $r['from'] ?? '',
            'to'        => $r['to'] ?? '',
            'blocks'    => $blocks,
        ] );
        $window = \TT\Modules\Analytics\Reports\PlayerReportComposition::window( $composition, gmdate( 'Y-m-d' ) );

        try {
            $report = ( new \TT\Modules\Analytics\Reports\PlayerReport() )->forPlayer(
                $player_id, $window['from'], $window['to'], $blocks, get_current_user_id()
            );
        } catch ( \InvalidArgumentException $e ) {
            return RestResponse::error( 'bad_request', $e->getMessage(), 400 );
        }
        if ( $report === null ) {
            return RestResponse::error( 'not_found', __( 'Player not found.', 'talenttrack' ), 404 );
        }

        return RestResponse::success( $report + [ 'period' => $window['period'] ] );
    }

    /** #3890 — `GET /players/{id}/report-snapshots`, most recent first, without payloads. */
    public static function list_report_snapshots( \WP_REST_Request $r ): \WP_REST_Response {
        $rows = ( new \TT\Modules\Analytics\Reports\PlayerReportSnapshotRepository() )->listForPlayer( absint( $r['id'] ) );
        $out  = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'uuid'        => (string) $row->uuid,
                'title'       => (string) $row->title,
                'period_from' => (string) $row->period_from,
                'period_to'   => (string) $row->period_to,
                'created_by'  => (int) $row->created_by,
                'created_at'  => (string) $row->created_at,
            ];
        }
        return RestResponse::success( [ 'snapshots' => $out ] );
    }

    /** #3890 — `POST /players/{id}/report-snapshots`: freeze the report as the caller sees it now. */
    public static function create_report_snapshot( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['id'] );
        $uuid      = \TT\Modules\Analytics\Reports\PlayerReportSnapshots::take(
            $player_id,
            [
                'period' => (string) ( $r['period'] ?? '' ),
                'from'   => (string) ( $r['from'] ?? '' ),
                'to'     => (string) ( $r['to'] ?? '' ),
                'layout' => (string) ( $r['layout'] ?? '' ),
                'blocks' => (string) ( $r['blocks'] ?? '' ),
            ],
            get_current_user_id(),
            sanitize_text_field( (string) ( $r['title'] ?? '' ) )
        );
        if ( $uuid === '' ) {
            return RestResponse::error( 'snapshot_failed', __( 'The snapshot could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( \TT\Modules\Analytics\Reports\PlayerReportSnapshots::read( $uuid, get_current_user_id() ), 201 );
    }

    /** #3890 — `GET /player-report-snapshots/{uuid}`. */
    public static function get_report_snapshot( \WP_REST_Request $r ): \WP_REST_Response {
        return RestResponse::success( \TT\Modules\Analytics\Reports\PlayerReportSnapshots::read( (string) $r['uuid'], get_current_user_id() ) );
    }

    /** #3890 — `PUT /player-report-snapshots/{uuid}/notes/{section}`. */
    public static function put_report_snapshot_note( \WP_REST_Request $r ): \WP_REST_Response {
        $section = sanitize_key( (string) $r['section'] );
        if ( ! \TT\Modules\Analytics\Reports\PlayerReportBlock::isValid( $section ) ) {
            return RestResponse::error( 'unknown_section', __( 'Unknown report section.', 'talenttrack' ), 400, [ 'section' => $section ] );
        }
        $ok = \TT\Modules\Analytics\Reports\PlayerReportSnapshots::note(
            (string) $r['uuid'],
            $section,
            sanitize_textarea_field( (string) ( $r['body'] ?? '' ) ),
            get_current_user_id()
        );
        if ( ! $ok ) {
            return RestResponse::error( 'note_failed', __( 'The note could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( \TT\Modules\Analytics\Reports\PlayerReportSnapshots::read( (string) $r['uuid'], get_current_user_id() ) );
    }

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    /**
     * POST /players/import — multipart upload of a CSV.
     *
     * Single endpoint for both preview (dry_run=1, default) and commit
     * (dry_run=0). The client re-uploads the file on commit; cheap for
     * typical CSVs (≤1MB, few hundred rows) and keeps the endpoint
     * stateless. See `PlayerCsvImporter` for the parsing/validation/
     * dupe logic.
     *
     * Request fields:
     *   file            — CSV upload (multipart/form-data)
     *   dry_run         — '1' for preview, '0' for commit (default '1')
     *   dupe_strategy   — 'skip' | 'update' | 'create' (default 'skip')
     *
     * Preview response: { header_warnings, total, preview: [...] }
     * Commit response: { created, updated, skipped, errored, error_rows, error_csv }
     */
    public static function import_players( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values. The CSV itself
        // arrives as a multipart file, not as a body key, so only the two
        // switches are checked here.
        $refused = BaseController::checkBody( $r, self::importArgs() );
        if ( $refused !== null ) return $refused;

        $files = $r->get_file_params();
        if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
            return RestResponse::error( 'no_file', __( 'No CSV file uploaded.', 'talenttrack' ), 400 );
        }
        $upload = $files['file'];
        if ( ( $upload['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
            return RestResponse::error( 'upload_error', __( 'The upload failed.', 'talenttrack' ), 400 );
        }
        if ( ( $upload['size'] ?? 0 ) > 5 * 1024 * 1024 ) {
            return RestResponse::error( 'file_too_large', __( 'CSV files larger than 5MB are not accepted.', 'talenttrack' ), 400 );
        }
        if ( ! is_uploaded_file( $upload['tmp_name'] ) ) {
            return RestResponse::error( 'upload_invalid', __( 'Uploaded file could not be read.', 'talenttrack' ), 400 );
        }
        $ext = strtolower( pathinfo( (string) $upload['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'csv' ) {
            return RestResponse::error( 'bad_extension', __( 'Only .csv files are accepted.', 'talenttrack' ), 400 );
        }

        $dry_run       = ( $r['dry_run'] ?? '1' ) !== '0';
        $dupe_strategy = sanitize_key( (string) ( $r['dupe_strategy'] ?? PlayerCsvImporter::DUPE_SKIP ) );
        if ( ! in_array( $dupe_strategy, [ PlayerCsvImporter::DUPE_SKIP, PlayerCsvImporter::DUPE_UPDATE, PlayerCsvImporter::DUPE_CREATE ], true ) ) {
            $dupe_strategy = PlayerCsvImporter::DUPE_SKIP;
        }

        $tmp_path = (string) $upload['tmp_name'];

        if ( $dry_run ) {
            $preview = PlayerCsvImporter::preview( $tmp_path );
            return RestResponse::success( $preview );
        }

        $summary = PlayerCsvImporter::commit( $tmp_path, $dupe_strategy );
        // Attach a CSV string of error rows the client can offer for re-download.
        $summary['error_csv'] = PlayerCsvImporter::errorRowsToCsv( $summary['error_rows'] );

        Logger::info( 'csv.player.import.completed', [
            'created'  => $summary['created'],
            'updated'  => $summary['updated'],
            'skipped'  => $summary['skipped'],
            'errored'  => $summary['errored'],
            'strategy' => $dupe_strategy,
        ] );

        return RestResponse::success( $summary );
    }

    /**
     * #3804 — the media-consent cell for the players list.
     *
     * Three states, because two would hide the one that matters. A child
     * with no consent and no pictures is an administrative gap; a child
     * with no consent and pictures on file is the thing somebody has to
     * act on this week, and it says how many.
     *
     * Nothing here hides an image. The cell reports; the media surfaces
     * still show every picture they showed before.
     */
    private static function mediaConsentPill( bool $consented, int $media_count ): string {
        if ( $consented ) {
            return '<span class="tt-consent-pill tt-consent-pill--ok">'
                . esc_html__( 'Consent on file', 'talenttrack' ) . '</span>';
        }
        if ( $media_count > 0 ) {
            return '<span class="tt-consent-pill tt-consent-pill--gap">'
                . esc_html(
                    sprintf(
                        /* translators: %d: number of photos and clips held for this player. */
                        _n( 'No consent, %d media item', 'No consent, %d media items', $media_count, 'talenttrack' ),
                        $media_count
                    )
                )
                . '</span>';
        }
        return '<span class="tt-consent-pill tt-consent-pill--none">'
            . esc_html__( 'No consent', 'talenttrack' ) . '</span>';
    }

    /** Compact row format for list responses (no custom fields). */
    private static function fmtRow( object $pl ): array {
        $name = trim( ( (string) $pl->first_name ) . ' ' . ( (string) $pl->last_name ) );

        // #0070 — pre-render link HTML for name / team / parent so the
        // frontend list table can show clickable cells via render: html.
        $detail_url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'players', (int) $pl->id );
        $name_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
            $name !== '' ? $name : '#' . (int) $pl->id,
            $detail_url
        );

        $team_link_html = '';
        $team_id = (int) ( $pl->team_id ?? 0 );
        $team_name = (string) ( $pl->team_name ?? '' );
        if ( $team_id > 0 && $team_name !== '' ) {
            $team_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
                $team_name,
                \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', $team_id )
            );
        }

        // #3572 — primary parent plus a count ("Anna de Vries +1"). The
        // name links to the Parent accounts view for whoever may open it,
        // and is plain text for everyone else.
        $parent_id    = (int) ( $pl->parent_id ?? 0 );
        $parent_name  = $parent_id > 0 ? trim( (string) ( $pl->parent_display_name ?? '' ) ) : '';
        $parent_count = $parent_id > 0 ? max( 1, (int) ( $pl->parent_count ?? 1 ) ) : 0;
        $parent_link_html = '';
        if ( $parent_name !== '' ) {
            $label = $parent_count > 1 ? $parent_name . ' +' . ( $parent_count - 1 ) : $parent_name;
            $parent_link_html = AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_manage_parent_accounts' )
                ? \TT\Shared\Frontend\Components\RecordLink::inline(
                    $label,
                    add_query_arg( [ 'tt_view' => 'parent-accounts' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() )
                )
                : esc_html( $label );
        }

        return [
            'id'               => (int) $pl->id,
            'first_name'       => (string) $pl->first_name,
            'last_name'        => (string) $pl->last_name,
            'name'             => $name,
            'name_link_html'   => $name_link_html,
            'team_id'          => $team_id,
            'team_name'        => $team_name,
            'team_link_html'   => $team_link_html,
            'team_age_group'   => (string) ( $pl->team_age_group ?? '' ),
            'parent_id'        => $parent_id,
            'parent_name'      => $parent_name,
            'parent_count'     => $parent_count,
            'parent_link_html' => $parent_link_html,
            'jersey_number'    => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
            'preferred_foot'   => (string) ( $pl->preferred_foot ?? '' ),
            // The list table renders this column via the lookup-pill
            // path so the value shows as the localised label ("Rechts"
            // on a Dutch install) instead of the raw stored key.
            'preferred_foot_pill_html' => $pl->preferred_foot
                ? LookupPill::render( 'foot_option', (string) $pl->preferred_foot )
                : '',
            // #3399 — the gated delivery URL, not the raw column. A REST
            // consumer gets a link that still needs the caller's session,
            // which is the whole point of moving photos off uploads/.
            'photo_url'        => \TT\Modules\Players\Services\PlayerPhoto::url( $pl ),
            'date_of_birth'    => PlayerDates::forOutput( $pl->date_of_birth ?? null ),
            'sex'              => (string) ( $pl->sex ?? '' ),
            'status'           => (string) ( $pl->status ?? 'active' ),
            // #3804 — consent on the list row, so "which children may we
            // show?" stops being sixteen calls to players/{id}. The row
            // hydration already selects `p.*`, so these columns were being
            // fetched and thrown away.
            'media_consent'    => ! empty( $pl->media_consent ),
            'media_consent_at' => ! empty( $pl->media_consent_at ) ? (string) $pl->media_consent_at : null,
            'media_count'      => isset( $pl->media_count ) ? (int) $pl->media_count : null,
            'media_consent_pill_html' => self::mediaConsentPill(
                ! empty( $pl->media_consent ),
                isset( $pl->media_count ) ? (int) $pl->media_count : 0
            ),
            // v3.110.170 — row-link standard (#758).
            'detail_url'       => $detail_url,
            // #2023 — archived_at + trashed_at via the shared lifecycle helper.
        ] + \TT\Infrastructure\Archive\LifecycleFields::forRow( $pl );
    }

    public static function get_player( \WP_REST_Request $r ) {
        $pl = QueryHelpers::get_player( (int) $r['id'] );
        if ( ! $pl ) return RestResponse::notFound();
        return RestResponse::success( self::fmt( $pl ) );
    }

    /**
     * #3817 (slice 3 of #3603) — the body `POST /players` and
     * `PUT /players/{id}` accept.
     *
     * Every key here is a key `extract()` reads, plus the two that are
     * written outside the row: `custom_fields` (validated and upserted into
     * `tt_custom_values`) and `link_parent_user_id` (the parent-account
     * link). A key outside this list is `400 unknown_field` rather than a
     * 200 over a field nothing stored — which is how a misspelled
     * `guardian_mail` used to look exactly like a save.
     *
     * Every field is optional on update: an omitted one keeps its stored
     * value (#3569, CLAUDE.md §6). **Nothing is declared `required` on
     * either verb**, for the reason `ActivitiesRestController::writeArgs()`
     * sets out at length: core checks required params before the permission
     * callback, so a required field answers an unauthenticated `POST` with a
     * `400` naming the fields instead of the `401` it owes. `create_player()`
     * names the two fields it needs itself, behind the capability gate, in
     * the same `missing_fields` envelope.
     *
     * The declared `type` is published schema, not enforcement: WordPress
     * only runs a `validate_callback` a route sets explicitly, and
     * `register_rest_route()` does not default one (#3929).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function writeArgs(): array {
        return [
            'first_name'          => [ 'type' => 'string', 'description' => 'The player\'s first name.' ],
            'last_name'           => [ 'type' => 'string', 'description' => 'The player\'s last name.' ],
            'date_of_birth'       => [ 'type' => 'string', 'description' => 'Date of birth as YYYY-MM-DD. Blank means not recorded; anything else that is not a date is refused with bad_date.' ],
            'sex'                 => [ 'type' => 'string', 'description' => 'Used only to pick the right growth reference. An unrecognised value degrades to blank rather than failing the save.' ],
            'nationality'         => [ 'type' => 'string', 'description' => 'Nationality, free text.' ],
            'height_cm'           => [ 'type' => [ 'integer', 'string' ], 'description' => 'Height in centimetres. Empty clears it.' ],
            'weight_kg'           => [ 'type' => [ 'integer', 'string' ], 'description' => 'Weight in kilograms. Empty clears it.' ],
            'preferred_foot'      => [ 'type' => 'string', 'description' => 'A key from the foot_option lookup.' ],
            'preferred_positions' => [ 'type' => [ 'array', 'string' ], 'description' => 'Position keys. An empty value clears the list.' ],
            'jersey_number'       => [ 'type' => [ 'integer', 'string' ], 'description' => 'Shirt number. Empty clears it.' ],
            'team_id'             => [ 'type' => [ 'integer', 'string' ], 'description' => 'The squad the player is on. 0 takes them off a team (#2866).' ],
            'date_joined'         => [ 'type' => 'string', 'description' => 'The date they joined the academy, as YYYY-MM-DD.' ],
            'photo_url'           => [ 'type' => 'string', 'description' => 'URL of the player photo.' ],
            'media_consent'       => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Whether the family agreed to the club using pictures of this player. A record, never a gate (#2744). Sending it stamps who recorded it and when; 0 withdraws it.' ],
            'guardian_name'       => [ 'type' => 'string', 'description' => 'Parent or guardian name.' ],
            'guardian_email'      => [ 'type' => 'string', 'description' => 'Parent or guardian e-mail address.' ],
            'guardian_phone'      => [ 'type' => 'string', 'description' => 'Parent or guardian phone number.' ],
            'wp_user_id'          => [ 'type' => [ 'integer', 'string' ], 'description' => 'The player\'s own account, where they have one. 0 means no account.' ],
            'status'              => [ 'type' => 'string', 'description' => 'Player status key. Defaults to active on create.' ],
            'custom_fields'       => [ 'type' => 'object', 'description' => 'Field key to value, for the club\'s own player fields. A required one absent from a create is refused by name.' ],
            'link_parent_user_id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'A WordPress user to link as this player\'s parent. Idempotent; 0 or absent links nobody.' ],
        ];
    }

    /**
     * #3817 — `PUT /players/{id}` on top of `writeArgs()`.
     *
     * `id` is the URL segment, declared so a client that echoes the
     * record's own id back in the body is not refused for it. The URL
     * copy is what the handler reads either way.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function updateArgs(): array {
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The player, from the URL. A copy in the body is accepted and ignored.',
        ] ] + self::writeArgs();
    }

    public static function create_player( \WP_REST_Request $r ) {
        // #3817 — the body's shape before its values, so a key this route
        // does not take is a refusal rather than a 200 over a field that
        // was never read.
        $refused = BaseController::checkBody( $r, self::writeArgs() );
        if ( $refused !== null ) return $refused;

        // v3.85.5 — REST cap enforcement. wp-admin PlayersPage already
        // gated; the REST endpoint (used by the frontend manage view +
        // the new-player wizard's REST submit path) silently bypassed
        // the free-tier 25-player cap. Now mirrors the admin gate.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' ) ) {
            $blocked = \TT\Modules\License\LicenseGate::enforceCapRest( 'players' );
            if ( $blocked ) return $blocked;
        }

        // #3217 — create mode: a required custom field absent from the
        // payload is missing, not skipped. This is the gate the inline
        // trial-player create and the new-player wizard both reach, since
        // #3115 and #3189 routed both through this endpoint.
        $validation = self::validateCustomFields( $r, CustomFieldValidator::MODE_CREATE );
        if ( ! empty( $validation['errors'] ) ) {
            return RestResponse::errors( $validation['errors'], 422 );
        }

        global $wpdb;
        $data = self::extract( $r );
        // #3817 — a player with no name is not a record anybody can find
        // again. The form has marked both fields required since it shipped;
        // the endpoint accepted a nameless row and answered 200. Named here
        // rather than declared `required` in `args`, so an unauthenticated
        // POST still gets the 401 it is owed (see `writeArgs()`).
        $missing = array_values( array_filter( [
            $data['first_name'] === '' ? 'first_name' : null,
            $data['last_name']  === '' ? 'last_name'  : null,
        ] ) );
        if ( $missing !== [] ) {
            return RestResponse::error(
                'missing_fields',
                __( 'A first name and a last name are required.', 'talenttrack' ),
                400,
                [ 'fields' => $missing ]
            );
        }
        $bad_date = self::dateRefusal( $data );
        if ( $bad_date !== null ) return $bad_date;
        $data = self::stampConsent( $data, null );
        $data['club_id'] = CurrentClub::id();
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_players', $data );
        if ( $ok === false ) {
            Logger::error( 'rest.player.create.failed', [ 'db_error' => (string) $wpdb->last_error ] );
            return RestResponse::errors( [
                [ 'code' => 'db_error', 'message' => __( 'The player could not be created.', 'talenttrack' ), 'details' => [ 'db_error' => (string) $wpdb->last_error ] ],
            ], 500 );
        }
        $id = (int) $wpdb->insert_id;
        // v3.76.2 — auto-tag demo-on rows so operator-created records
        // remain visible to demo-scoped queries.
        \TT\Modules\DemoData\DemoMode::tagIfActive( 'player', $id );

        self::upsertCustomValues( $id, $validation['sanitized'] );
        self::maybeLinkParent( $id, $r );
        do_action( 'tt_after_player_save', $id, $data );
        // #0053 — separate hook so journey subscribers can react to
        // creation specifically (not every save).
        do_action( 'tt_player_created', $id, $data );

        $pl = QueryHelpers::get_player( $id );
        return RestResponse::success( self::fmt( $pl ) );
    }

    public static function update_player( \WP_REST_Request $r ) {
        // #3817 — refuse a key this route does not take before anything is
        // written. Nothing here is required: the update is a patch, and an
        // omitted field keeps its stored value (#3569).
        $refused = BaseController::checkBody( $r, self::updateArgs() );
        if ( $refused !== null ) return $refused;

        $id = (int) $r['id'];
        $existing = QueryHelpers::get_player( $id );
        if ( ! $existing ) return RestResponse::notFound();
        $previous = (array) $existing;

        $validation = self::validateCustomFields( $r );
        if ( ! empty( $validation['errors'] ) ) {
            return RestResponse::errors( $validation['errors'], 422 );
        }

        global $wpdb;

        // #3569 — absent from the payload means leave it alone. `extract()`
        // defaults every missing key to empty, which is right on create and
        // destructive here: a PUT carrying only a guardian name used to
        // blank the name, date of birth, positions and account link. Every
        // `extract()` key is named after its request param, so keeping the
        // sent keys is the whole rule. A key that *is* sent is honoured
        // whatever its value — an explicit empty is how a field is cleared
        // (and `team_id: 0` how a player comes off a team, #2866).
        $data = array_intersect_key( self::extract( $r ), $r->get_params() );
        $bad_date = self::dateRefusal( $data );
        if ( $bad_date !== null ) return $bad_date;
        if ( array_key_exists( 'media_consent', $data ) ) {
            $data = self::stampConsent( $data, $existing );
        }

        if ( $data !== [] ) {
            $ok = $wpdb->update( $wpdb->prefix . 'tt_players', $data, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
            if ( $ok === false ) {
                Logger::error( 'rest.player.update.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
                return RestResponse::errors( [
                    [ 'code' => 'db_error', 'message' => __( 'The player could not be updated.', 'talenttrack' ), 'details' => [ 'db_error' => (string) $wpdb->last_error ] ],
                ], 500 );
            }
        }

        self::upsertCustomValues( $id, $validation['sanitized'] );
        self::maybeLinkParent( $id, $r );
        do_action( 'tt_after_player_save', $id, $data );
        // #0053 — diff hook so the journey subscriber can detect status /
        // team / position transitions. It gets the whole row after the
        // save: a field this request did not send is unchanged, not blank,
        // and must not read as a transition to nothing.
        do_action( 'tt_player_save_diff', $id, $previous, array_merge( $previous, $data ) );

        $pl = QueryHelpers::get_player( $id );
        return RestResponse::success( self::fmt( $pl ) );
    }

    /**
     * If the request carries a non-empty `link_parent_user_id`, attach the
     * chosen parent WP user to this player.
     *
     * #3572 — through `ParentAccountService::linkToPlayer()`, the one link
     * rule. This used to run its own check that REQUIRED a `tt_people` row
     * of type `parent`, while the Parent accounts path REFUSED any account
     * with a people row, so the two write paths into the same pivot
     * accepted opposite sets of accounts. Idempotent: an existing link is
     * a no-op.
     */
    private static function maybeLinkParent( int $player_id, \WP_REST_Request $r ): void {
        $parent_user_id = absint( $r['link_parent_user_id'] ?? 0 );
        if ( $player_id <= 0 || $parent_user_id <= 0 ) return;

        $result = ( new \TT\Infrastructure\Players\ParentAccountService() )->linkToPlayer( $player_id, $parent_user_id );
        if ( empty( $result['ok'] ) ) {
            Logger::error( 'rest.player.link_parent.refused', [
                'player_id'      => $player_id,
                'parent_user_id' => $parent_user_id,
                'code'           => $result['code'],
            ] );
        }
    }

    public static function delete_player( \WP_REST_Request $r ) {
        global $wpdb;
        $id = (int) $r['id'];
        // v3.89.2 — soft-archive via `archived_at` + `archived_by`, not via
        // `status='deleted'`. The list query (line 154) filters on
        // `p.archived_at IS NULL`, so writing to `status` left the row in
        // the active list — operator clicked Delete and saw "nothing
        // happened". Mirrors `delete_team`'s archive shape (TeamsRestController).
        $ok = $wpdb->update(
            $wpdb->prefix . 'tt_players',
            [ 'archived_at' => current_time( 'mysql' ), 'archived_by' => get_current_user_id() ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) {
            Logger::error( 'rest.player.delete.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::errors( [
                [ 'code' => 'db_error', 'message' => __( 'The player could not be deleted.', 'talenttrack' ) ],
            ], 500 );
        }
        // #0085 — cascade soft-archive of player notes. Notes are
        // retained for compliance (hard-delete happens via the future
        // GDPR erasure pipeline) but hidden from default queries. The
        // existing thread_messages query layer filters on `deleted_at IS NULL`
        // for non-author non-admin viewers; setting deleted_at here
        // matches the existing soft-delete shape.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_thread_messages
                SET deleted_at = %s,
                    deleted_by = %d
              WHERE thread_type = 'player' AND thread_id = %d AND deleted_at IS NULL",
            current_time( 'mysql' ),
            get_current_user_id(),
            $id
        ) );
        return RestResponse::success( [ 'archived' => true, 'id' => $id ] );
    }

    /**
     * #3217 — the mode matters. A create must validate every required
     * custom field whether or not the payload mentions it; an update only
     * validates what it was given, so a partial `PUT` cannot be made to
     * fail by a field it never intended to touch.
     */
    private static function validateCustomFields( \WP_REST_Request $r, string $mode = CustomFieldValidator::MODE_UPDATE ): array {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) {
            return [ 'errors' => [], 'sanitized' => [] ];
        }
        $submitted = $r->get_param( 'custom_fields' );
        if ( ! is_array( $submitted ) ) {
            $submitted = [];
        }
        return ( new CustomFieldValidator() )->validate( $fields, $submitted, [], $mode );
    }

    private static function upsertCustomValues( int $player_id, array $sanitized ): void {
        $repo = new CustomValuesRepository();
        foreach ( $sanitized as $field_id => $value ) {
            $repo->upsert( CustomFieldsRepository::ENTITY_PLAYER, $player_id, (int) $field_id, $value );
        }
    }

    /**
     * Stamp who recorded media consent and when (#2744).
     *
     * The date and the recorder are the whole point of the record: a bare
     * boolean is an assertion, a boolean with provenance is evidence, and
     * evidence is what a consent record exists to be.
     *
     * Only a *change* re-stamps. Re-saving an unrelated field on a player
     * whose consent was recorded last season must not silently move the
     * date to today and put the current user's name against a decision
     * they did not take.
     *
     * @param array<string, mixed> $data
     * @param object|null          $existing null on create.
     * @return array<string, mixed>
     */
    private static function stampConsent( array $data, ?object $existing ): array {
        $now  = (int) ( $data['media_consent'] ?? 0 );
        $was  = $existing ? (int) ( $existing->media_consent ?? 0 ) : 0;

        if ( $now === $was && $existing ) return $data;

        if ( $now === 1 ) {
            $data['media_consent_at'] = current_time( 'mysql' );
            $data['media_consent_by'] = get_current_user_id();
        } else {
            // Withdrawn, or never given. The provenance of a consent that
            // no longer stands would only be misleading.
            $data['media_consent_at'] = null;
            $data['media_consent_by'] = null;
        }

        return $data;
    }

    /**
     * #3590 — a date that is sent must be a real `Y-m-d` date. Blank is
     * accepted and means "not recorded"; anything else that does not parse
     * is refused rather than stored as the zero date.
     *
     * @param array<string,mixed> $data
     */
    private static function dateRefusal( array $data ): ?\WP_REST_Response {
        foreach ( [ 'date_of_birth', 'date_joined' ] as $field ) {
            if ( ! array_key_exists( $field, $data ) ) continue;
            $value = $data[ $field ];
            if ( PlayerDates::isValid( is_string( $value ) ? $value : null ) ) continue;
            return RestResponse::errors( [ [
                'code'    => 'bad_date',
                'message' => __( 'Dates must be a real date in the form YYYY-MM-DD.', 'talenttrack' ),
                'details' => [ 'field' => $field ],
            ] ], 400 );
        }
        return null;
    }

    private static function extract( \WP_REST_Request $r ): array {
        return [
            'first_name'          => sanitize_text_field( (string) ( $r['first_name'] ?? '' ) ),
            'last_name'           => sanitize_text_field( (string) ( $r['last_name'] ?? '' ) ),
            // #3590 — blank is NULL, not the zero date an empty string
            // becomes in a DATE column.
            'date_of_birth'       => PlayerDates::fromInput( $r['date_of_birth'] ?? null ),
            // #2894 — sanitize() maps anything unrecognised to blank rather
            // than rejecting the request. An unknown value on a minor's
            // record should degrade to "not recorded", not fail a save that
            // was otherwise fine.
            'sex'                 => PlayerSex::sanitize( $r['sex'] ?? '' ),
            'nationality'         => sanitize_text_field( (string) ( $r['nationality'] ?? '' ) ),
            'height_cm'           => ! empty( $r['height_cm'] ) ? absint( $r['height_cm'] ) : null,
            'weight_kg'           => ! empty( $r['weight_kg'] ) ? absint( $r['weight_kg'] ) : null,
            'preferred_foot'      => sanitize_text_field( (string) ( $r['preferred_foot'] ?? '' ) ),
            'preferred_positions' => wp_json_encode(
                is_array( $r['preferred_positions'] ?? null )
                    ? array_map( 'sanitize_text_field', (array) $r['preferred_positions'] )
                    : []
            ),
            'jersey_number'       => ! empty( $r['jersey_number'] ) ? absint( $r['jersey_number'] ) : null,
            'team_id'             => absint( $r['team_id'] ?? 0 ),
            'date_joined'         => PlayerDates::fromInput( $r['date_joined'] ?? null ),
            'photo_url'           => esc_url_raw( (string) ( $r['photo_url'] ?? '' ) ),
            // #2744 — a record of what the family agreed to, not a gate.
            // On create an absent key means "no", which is the honest
            // reading for a consent record. On update an absent key leaves
            // it alone (#3569); the form sends a hidden 0 to withdraw.
            'media_consent'       => ! empty( $r['media_consent'] ) ? 1 : 0,
            'guardian_name'       => sanitize_text_field( (string) ( $r['guardian_name'] ?? '' ) ),
            'guardian_email'      => sanitize_email( (string) ( $r['guardian_email'] ?? '' ) ),
            'guardian_phone'      => sanitize_text_field( (string) ( $r['guardian_phone'] ?? '' ) ),
            // #1772 — store NULL (not 0) for "no account" so the
            // UNIQUE (club_id, wp_user_id) index only constrains real
            // accounts; multiple unlinked players stay valid.
            'wp_user_id'          => absint( $r['wp_user_id'] ?? 0 ) > 0 ? absint( $r['wp_user_id'] ?? 0 ) : null,
            'status'              => sanitize_text_field( (string) ( $r['status'] ?? 'active' ) ),
        ];
    }

    private static function fmt( ?object $pl ): array {
        if ( ! $pl ) return [];
        $custom = ( new CustomValuesRepository() )->getByEntityKeyed(
            CustomFieldsRepository::ENTITY_PLAYER,
            (int) $pl->id
        );
        return [
            'id'                  => (int) $pl->id,
            'first_name'          => (string) $pl->first_name,
            'last_name'           => (string) $pl->last_name,
            'date_of_birth'       => PlayerDates::forOutput( $pl->date_of_birth ),
            'sex'                 => (string) ( $pl->sex ?? '' ),
            'nationality'         => $pl->nationality ?: null,
            'height_cm'           => $pl->height_cm !== null ? (int) $pl->height_cm : null,
            'weight_kg'           => $pl->weight_kg !== null ? (int) $pl->weight_kg : null,
            'preferred_foot'      => $pl->preferred_foot ?: null,
            // #2744 — under the existing player permissions, like every
            // other field here. Safeguarding-adjacent, so it follows the
            // same rules as the fields beside it rather than gaining a
            // capability of its own.
            'media_consent'       => ! empty( $pl->media_consent ),
            'media_consent_at'    => $pl->media_consent_at ?: null,
            'media_consent_by'    => $pl->media_consent_by !== null ? (int) $pl->media_consent_by : null,
            'preferred_positions' => json_decode( (string) $pl->preferred_positions, true ) ?: [],
            'jersey_number'       => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
            'team_id'             => (int) $pl->team_id,
            'date_joined'         => PlayerDates::forOutput( $pl->date_joined ),
            'photo_url'           => \TT\Modules\Players\Services\PlayerPhoto::url( $pl ) ?: null,
            'guardian_name'       => $pl->guardian_name ?: null,
            'guardian_email'      => $pl->guardian_email ?: null,
            'guardian_phone'      => $pl->guardian_phone ?: null,
            // #3590 — NULL is "no account" since #1772; 0 read as an id.
            'wp_user_id'          => ( (int) $pl->wp_user_id ) ?: null,
            'status'              => (string) $pl->status,
            'custom_fields'       => (object) $custom,
        ];
    }
}
