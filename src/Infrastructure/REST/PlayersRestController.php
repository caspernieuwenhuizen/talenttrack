<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\PlayerCsvImporter;
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
                // #0052 PR-B — gate on `tt_view_players` instead of bare
                // login. The list query already filters per-row by team
                // scoping; this prevents a logged-in user without any
                // player-view rights from hitting the endpoint at all.
                'permission_callback' => function () { return current_user_can( 'tt_view_players' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_player' ],
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
            ],
        ]);
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
    }

    /** Whitelist of columns the `orderby` query param accepts. */
    private const LIST_ORDERBY_WHITELIST = [
        'last_name'     => 'p.last_name',
        'first_name'    => 'p.first_name',
        'team_name'     => 't.name',
        'jersey_number' => 'p.jersey_number',
        'date_of_birth' => 'p.date_of_birth',
        'date_joined'   => 'p.date_joined',
    ];

    /**
     * GET /players — paginated list with search, filters, sort.
     *
     * #0019 Sprint 3 session 3.1 — replaces the v2.x bare-bones list
     * with the Sprint 2 contract that `FrontendListTable` consumes.
     *
     * Query params:
     *   ?search=<text>             — first/last name LIKE
     *   ?filter[team_id]=<int>
     *   ?filter[position]=<string> — matches anywhere in preferred_positions JSON array
     *   ?filter[preferred_foot]=<string>
     *   ?filter[age_group]=<string> — matches the team's age_group (e.g. "U13")
     *   ?filter[archived]=<active|archived> — default active only
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
        $archived = isset( $filter['archived'] ) ? sanitize_key( (string) $filter['archived'] ) : 'active';
        if ( $archived === 'archived' ) {
            $where[] = 'p.archived_at IS NOT NULL';
        } else {
            $where[] = 'p.archived_at IS NULL';
        }

        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 'p.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }
        if ( ! empty( $filter['preferred_foot'] ) ) {
            $where[]  = 'p.preferred_foot = %s';
            $params[] = sanitize_text_field( (string) $filter['preferred_foot'] );
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

        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = '(p.first_name LIKE %s OR p.last_name LIKE %s)';
            $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        // #0070 — join parent person so the list can render a clickable
        // parent name. Left-joined; null when no parent_person_id is set
        // or the parent record has been removed.
        $list_sql = "SELECT p.*, t.name AS team_name, t.age_group AS team_age_group,
                            par.id AS parent_id,
                            par.first_name AS parent_first_name,
                            par.last_name AS parent_last_name
                     FROM {$p}tt_players p
                     LEFT JOIN {$p}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id
                     LEFT JOIN {$p}tt_people par ON par.id = p.parent_person_id AND par.club_id = p.club_id
                     WHERE {$where_sql}
                     ORDER BY {$orderby} {$order}
                     LIMIT %d OFFSET %d";
        $offset = ( $page - 1 ) * $per_page;
        $list_params = array_merge( $params, [ $per_page, $offset ] );

        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) ) ?: [];

        // Row-level visibility filter — same as v2.x but applied
        // post-fetch. AuthorizationService is per-player; checking it
        // pre-fetch would require either inlining its logic or N+1
        // queries. With the page-level cap (100/page max) the post-fetch
        // filter cost is bounded.
        $user_id = get_current_user_id();
        $rows = array_values( array_filter( $rows, function ( $pl ) use ( $user_id ) {
            return AuthorizationService::canViewPlayer( $user_id, (int) $pl->id );
        } ) );

        // Total count — same WHERE clause, post-AuthZ.
        $count_sql = "SELECT p.id FROM {$p}tt_players p
                      LEFT JOIN {$p}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id
                      WHERE {$where_sql}";
        $all_ids = $params
            ? $wpdb->get_col( $wpdb->prepare( $count_sql, ...$params ) )
            : $wpdb->get_col( $count_sql );
        $total = 0;
        foreach ( (array) $all_ids as $pid ) {
            if ( AuthorizationService::canViewPlayer( $user_id, (int) $pid ) ) $total++;
        }

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'fmtRow' ], $rows ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
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

    /** Compact row format for list responses (no custom fields). */
    private static function fmtRow( object $pl ): array {
        $name = trim( ( (string) $pl->first_name ) . ' ' . ( (string) $pl->last_name ) );

        // #0070 — pre-render link HTML for name / team / parent so the
        // frontend list table can show clickable cells via render: html.
        $name_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
            $name !== '' ? $name : '#' . (int) $pl->id,
            \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'players', (int) $pl->id )
        );

        $team_link_html = '';
        $team_id = (int) ( $pl->team_id ?? 0 );
        $team_name = (string) ( $pl->team_name ?? '' );
        if ( $team_id > 0 && $team_name !== '' ) {
            $team_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
                $team_name,
                \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'teams', $team_id )
            );
        }

        $parent_id   = (int) ( $pl->parent_id ?? 0 );
        $parent_name = trim( ( (string) ( $pl->parent_first_name ?? '' ) ) . ' ' . ( (string) ( $pl->parent_last_name ?? '' ) ) );
        $parent_link_html = '';
        if ( $parent_id > 0 && $parent_name !== '' ) {
            $parent_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
                $parent_name,
                \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'people', $parent_id )
            );
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
            'parent_link_html' => $parent_link_html,
            'jersey_number'    => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
            'preferred_foot'   => (string) ( $pl->preferred_foot ?? '' ),
            'photo_url'        => (string) ( $pl->photo_url ?? '' ),
            'date_of_birth'    => $pl->date_of_birth ?: null,
            'archived_at'      => $pl->archived_at ?? null,
            'status'           => (string) ( $pl->status ?? 'active' ),
        ];
    }

    public static function get_player( \WP_REST_Request $r ) {
        $pl = QueryHelpers::get_player( (int) $r['id'] );
        if ( ! $pl ) return RestResponse::notFound();
        return RestResponse::success( self::fmt( $pl ) );
    }

    public static function create_player( \WP_REST_Request $r ) {
        $validation = self::validateCustomFields( $r );
        if ( ! empty( $validation['errors'] ) ) {
            return RestResponse::errors( $validation['errors'], 422 );
        }

        global $wpdb;
        $data = self::extract( $r );
        $data['club_id'] = CurrentClub::id();
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_players', $data );
        if ( $ok === false ) {
            Logger::error( 'rest.player.create.failed', [ 'db_error' => (string) $wpdb->last_error ] );
            return RestResponse::errors( [
                [ 'code' => 'db_error', 'message' => __( 'The player could not be created.', 'talenttrack' ), 'details' => [ 'db_error' => (string) $wpdb->last_error ] ],
            ], 500 );
        }
        $id = (int) $wpdb->insert_id;

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
        $id = (int) $r['id'];
        $existing = QueryHelpers::get_player( $id );
        if ( ! $existing ) return RestResponse::notFound();
        $previous = (array) $existing;

        $validation = self::validateCustomFields( $r );
        if ( ! empty( $validation['errors'] ) ) {
            return RestResponse::errors( $validation['errors'], 422 );
        }

        global $wpdb;
        $data = self::extract( $r );
        $ok = $wpdb->update( $wpdb->prefix . 'tt_players', $data, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            Logger::error( 'rest.player.update.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::errors( [
                [ 'code' => 'db_error', 'message' => __( 'The player could not be updated.', 'talenttrack' ), 'details' => [ 'db_error' => (string) $wpdb->last_error ] ],
            ], 500 );
        }

        self::upsertCustomValues( $id, $validation['sanitized'] );
        self::maybeLinkParent( $id, $r );
        do_action( 'tt_after_player_save', $id, $data );
        // #0053 — diff hook so the journey subscriber can detect status /
        // team / position transitions.
        do_action( 'tt_player_save_diff', $id, $previous, $data );

        $pl = QueryHelpers::get_player( $id );
        return RestResponse::success( self::fmt( $pl ) );
    }

    /**
     * If the request carries a non-empty `link_parent_user_id`, attach
     * the chosen parent WP user to this player via PlayerParentsRepository.
     * Idempotent — link() handles re-linking gracefully. Validates that
     * the user is a TT person tagged as parent before linking, so the
     * dropdown UX (parents-only) can't be bypassed by a hand-crafted
     * payload.
     */
    private static function maybeLinkParent( int $player_id, \WP_REST_Request $r ): void {
        $parent_user_id = absint( $r['link_parent_user_id'] ?? 0 );
        if ( $player_id <= 0 || $parent_user_id <= 0 ) return;

        global $wpdb; $p = $wpdb->prefix;
        $is_parent = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}tt_people
              WHERE wp_user_id = %d
                AND role_type = 'parent'
                AND archived_at IS NULL
                AND club_id = %d
              LIMIT 1",
            $parent_user_id, CurrentClub::id()
        ) );
        if ( $is_parent !== 1 ) {
            Logger::error( 'rest.player.link_parent.not_parent', [
                'player_id'      => $player_id,
                'parent_user_id' => $parent_user_id,
            ] );
            return;
        }

        ( new \TT\Modules\Invitations\PlayerParentsRepository() )->link( $player_id, $parent_user_id );
    }

    public static function delete_player( \WP_REST_Request $r ) {
        global $wpdb;
        $ok = $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'deleted' ], [ 'id' => (int) $r['id'], 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            Logger::error( 'rest.player.delete.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => (int) $r['id'] ] );
            return RestResponse::errors( [
                [ 'code' => 'db_error', 'message' => __( 'The player could not be deleted.', 'talenttrack' ) ],
            ], 500 );
        }
        return RestResponse::success( [ 'deleted' => true ] );
    }

    private static function validateCustomFields( \WP_REST_Request $r ): array {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) {
            return [ 'errors' => [], 'sanitized' => [] ];
        }
        $submitted = $r->get_param( 'custom_fields' );
        if ( ! is_array( $submitted ) ) {
            $submitted = [];
        }
        return ( new CustomFieldValidator() )->validate( $fields, $submitted );
    }

    private static function upsertCustomValues( int $player_id, array $sanitized ): void {
        $repo = new CustomValuesRepository();
        foreach ( $sanitized as $field_id => $value ) {
            $repo->upsert( CustomFieldsRepository::ENTITY_PLAYER, $player_id, (int) $field_id, $value );
        }
    }

    private static function extract( \WP_REST_Request $r ): array {
        return [
            'first_name'          => sanitize_text_field( (string) ( $r['first_name'] ?? '' ) ),
            'last_name'           => sanitize_text_field( (string) ( $r['last_name'] ?? '' ) ),
            'date_of_birth'       => sanitize_text_field( (string) ( $r['date_of_birth'] ?? '' ) ),
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
            'date_joined'         => sanitize_text_field( (string) ( $r['date_joined'] ?? '' ) ),
            'photo_url'           => esc_url_raw( (string) ( $r['photo_url'] ?? '' ) ),
            'guardian_name'       => sanitize_text_field( (string) ( $r['guardian_name'] ?? '' ) ),
            'guardian_email'      => sanitize_email( (string) ( $r['guardian_email'] ?? '' ) ),
            'guardian_phone'      => sanitize_text_field( (string) ( $r['guardian_phone'] ?? '' ) ),
            'wp_user_id'          => absint( $r['wp_user_id'] ?? 0 ),
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
            'date_of_birth'       => $pl->date_of_birth ?: null,
            'nationality'         => $pl->nationality ?: null,
            'height_cm'           => $pl->height_cm !== null ? (int) $pl->height_cm : null,
            'weight_kg'           => $pl->weight_kg !== null ? (int) $pl->weight_kg : null,
            'preferred_foot'      => $pl->preferred_foot ?: null,
            'preferred_positions' => json_decode( (string) $pl->preferred_positions, true ) ?: [],
            'jersey_number'       => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
            'team_id'             => (int) $pl->team_id,
            'date_joined'         => $pl->date_joined ?: null,
            'photo_url'           => $pl->photo_url ?: null,
            'guardian_name'       => $pl->guardian_name ?: null,
            'guardian_email'      => $pl->guardian_email ?: null,
            'guardian_phone'      => $pl->guardian_phone ?: null,
            'wp_user_id'          => (int) $pl->wp_user_id,
            'status'              => (string) $pl->status,
            'custom_fields'       => (object) $custom,
        ];
    }
}
