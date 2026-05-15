<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Pdp\Calendar\PdpCalendarWriters;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * PdpFilesRestController — /wp-json/talenttrack/v1/pdp-files
 *
 * #0044 Sprint 1. CRUD for PDP files with coach scoping. Creating a
 * file auto-templates the conversation cycle (createCycle) and emits
 * one calendar-link row per conversation via the PdpCalendarWriter
 * (NativeWriter today; Spond writer plugs in via #0031).
 */
class PdpFilesRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/pdp-files', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, '/pdp-files/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_one' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patch' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_pdp' ) || current_user_can( 'tt_edit_pdp' );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_pdp' );
    }

    /**
     * Whitelist for the `orderby` query param. v3.110.110 — added so
     * FrontendListTable's clickable sort headers can request a column.
     */
    private const ORDERBY_WHITELIST = [
        'player_name'  => 'pl.last_name',
        'team_name'    => 't.name',
        'status'       => 'f.status',
        'cycle_size'   => 'f.cycle_size',
        'updated_at'   => 'f.updated_at',
    ];

    /**
     * GET /pdp-files — coach sees own players' files; admin sees all.
     *
     * v3.110.110 — rewritten to the FrontendListTable contract (matches
     * GoalsRestController / EvaluationsRestController). Accepts
     * `filter[team_id]`, `filter[player_id]`, `filter[status]`,
     * `search`, `orderby`, `order`, `page`, `per_page`. Returns the
     * standard `{rows, total, page, per_page}` envelope. Rows are
     * pre-formatted with HTML link cells (player / team click-through)
     * and per-file parent/player ack rollups for the FrontendPdpManageView
     * list table. Coach-scoping preserved via the `listForCoach` /
     * `listForSeason` repository methods replaced by a unified SQL
     * query here (the repo methods returned raw rows; FrontendListTable
     * needs paginated joined output).
     */
    public static function list( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb; $p = $wpdb->prefix;

        $season_id = absint( $r['season_id'] ?? 0 );
        if ( $season_id <= 0 ) {
            $current = ( new SeasonsRepository() )->current();
            $season_id = $current ? (int) $current->id : 0;
        }
        if ( $season_id <= 0 ) {
            return RestResponse::success( [ 'rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 25 ] );
        }

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );
        $offset   = ( $page - 1 ) * $per_page;

        $orderby_key = sanitize_key( (string) ( $r['orderby'] ?? 'updated_at' ) );
        if ( ! isset( self::ORDERBY_WHITELIST[ $orderby_key ] ) ) $orderby_key = 'updated_at';
        $orderby = self::ORDERBY_WHITELIST[ $orderby_key ];
        $order   = strtolower( (string) ( $r['order'] ?? ( $orderby_key === 'updated_at' ? 'desc' : 'asc' ) ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'desc';

        $where  = [ 'f.club_id = %d', 'f.season_id = %d' ];
        $params = [ \TT\Infrastructure\Tenancy\CurrentClub::id(), $season_id ];

        if ( ! self::hasGlobalPdpAccess( 'read' ) ) {
            $where[]  = 'f.owner_coach_id = %d';
            $params[] = get_current_user_id();
        }

        $filter = is_array( $r['filter'] ?? null ) ? (array) $r['filter'] : [];
        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }
        if ( ! empty( $filter['player_id'] ) ) {
            $where[]  = 'f.player_id = %d';
            $params[] = absint( $filter['player_id'] );
        }
        if ( ! empty( $filter['status'] ) ) {
            $where[]  = 'f.status = %s';
            $params[] = sanitize_text_field( (string) $filter['status'] );
        }
        if ( ! empty( $r['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = '(pl.first_name LIKE %s OR pl.last_name LIKE %s)';
            $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // Aggregate parent/player ack across the file's conversations.
        // The list view renders one rollup checkmark per file —
        // "received" = at least one conversation has the ack set.
        // Per-conversation acks remain visible on the file detail page.
        $list_sql = "SELECT f.id, f.player_id, f.season_id, f.owner_coach_id, f.cycle_size, f.status, f.notes, f.updated_at,
                            pl.first_name, pl.last_name, pl.team_id,
                            t.name AS team_name,
                            (SELECT MAX(c.parent_ack_at IS NOT NULL)
                               FROM {$p}tt_pdp_conversations c
                              WHERE c.pdp_file_id = f.id) AS has_parent_ack,
                            (SELECT MAX(c.player_ack_at IS NOT NULL)
                               FROM {$p}tt_pdp_conversations c
                              WHERE c.pdp_file_id = f.id) AS has_player_ack
                       FROM {$p}tt_pdp_files f
                       LEFT JOIN {$p}tt_players pl ON pl.id = f.player_id
                       LEFT JOIN {$p}tt_teams   t  ON t.id  = pl.team_id
                      WHERE {$where_sql}
                      ORDER BY {$orderby} {$order}, f.id DESC
                      LIMIT %d OFFSET %d";

        $list_params = array_merge( $params, [ $per_page, $offset ] );
        $rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) );

        $count_sql = "SELECT COUNT(*)
                        FROM {$p}tt_pdp_files f
                        LEFT JOIN {$p}tt_players pl ON pl.id = f.player_id
                        LEFT JOIN {$p}tt_teams   t  ON t.id  = pl.team_id
                       WHERE {$where_sql}";
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );

        return RestResponse::success( [
            'rows'      => array_map( [ __CLASS__, 'format_list_row' ], $rows ?: [] ),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page,
            'season_id' => $season_id,
        ] );
    }

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    /**
     * v3.110.110 — list-row shape for FrontendListTable. Includes
     * pre-rendered link HTML for player/team cells and grey/green
     * checkmark HTML for the parent/player ack columns (pilot ask:
     * "use a grey checkmark if not received and a green checkmark
     * when received").
     *
     * @param object $row joined query result
     * @return array<string,mixed>
     */
    private static function format_list_row( $row ): array {
        $file_id   = (int) $row->id;
        $player_id = (int) $row->player_id;
        $team_id   = (int) ( $row->team_id ?? 0 );
        $first     = (string) ( $row->first_name ?? '' );
        $last      = (string) ( $row->last_name ?? '' );
        $player_name = trim( $first . ' ' . $last );
        if ( $player_name === '' ) $player_name = '#' . $player_id;
        $team_name = (string) ( $row->team_name ?? '' );

        $detail_url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg(
            [ 'tt_view' => 'pdp', 'id' => $file_id ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        ) );

        $player_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
            $player_name,
            \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'players', $player_id )
        );

        $team_link_html = '—';
        if ( $team_id > 0 && $team_name !== '' ) {
            $team_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
                $team_name,
                \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', $team_id )
            );
        }

        $status        = (string) ( $row->status ?? '' );
        $status_pill_html = \TT\Infrastructure\Query\LookupPill::render( 'pdp_status', $status );

        $parent_ack = ! empty( $row->has_parent_ack );
        $player_ack = ! empty( $row->has_player_ack );
        $parent_ack_html = self::ack_checkmark( $parent_ack, __( 'Parent acknowledgement', 'talenttrack' ) );
        $player_ack_html = self::ack_checkmark( $player_ack, __( 'Player acknowledgement', 'talenttrack' ) );

        return [
            'id'               => $file_id,
            'player_id'        => $player_id,
            'player_name'      => $player_name,
            'player_link_html' => $player_link_html,
            'team_id'          => $team_id,
            'team_name'        => $team_name,
            'team_link_html'   => $team_link_html,
            'status'           => $status,
            'status_pill_html' => $status_pill_html,
            'cycle_size'       => $row->cycle_size !== null ? (int) $row->cycle_size : null,
            'parent_ack'       => $parent_ack,
            'parent_ack_html'  => $parent_ack_html,
            'player_ack'       => $player_ack,
            'player_ack_html'  => $player_ack_html,
            'updated_at'       => (string) ( $row->updated_at ?? '' ),
            'detail_url'       => $detail_url,
        ];
    }

    /**
     * v3.110.110 — checkmark glyph for the ack columns. Grey when not
     * received, green when received. Single inline-SVG so it renders
     * accessibly without a webfont and respects user dark/light themes
     * via `currentColor`.
     */
    private static function ack_checkmark( bool $received, string $aria_label ): string {
        $colour = $received ? '#16a34a' : '#94a3b8'; // green-600 / slate-400
        $title  = $received
            ? sprintf( /* translators: %s = parent / player */ __( '%s received', 'talenttrack' ), $aria_label )
            : sprintf( /* translators: %s = parent / player */ __( '%s not yet received', 'talenttrack' ), $aria_label );
        return '<span title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '" style="display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px;">'
             . '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" style="color:' . esc_attr( $colour ) . ';">'
             . '<path fill="currentColor" d="M6.173 11.207 3.207 8.241l1.06-1.06 1.906 1.905 4.56-4.56 1.06 1.06z"/>'
             . '</svg>'
             . '</span>';
    }

    /**
     * POST /pdp-files — body: { player_id, season_id?, owner_coach_id?,
     * cycle_size?, notes? }. Creates the file and the conversation cycle.
     */
    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['player_id'] ?? 0 );
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'missing_fields',
                __( 'Player is required.', 'talenttrack' ), 400 );
        }

        $seasons = new SeasonsRepository();
        $season_id = absint( $r['season_id'] ?? 0 );
        $season    = $season_id > 0 ? $seasons->find( $season_id ) : $seasons->current();
        if ( ! $season ) {
            return RestResponse::error( 'no_season',
                __( 'No current season is set. Configure a season before creating PDP files.', 'talenttrack' ), 400 );
        }

        // Coach-scope guard: holders without global PDP edit must own
        // the player. Global holders (HoD / academy admin / WP admin)
        // bypass.
        if ( ! self::hasGlobalPdpAccess( 'change' ) ) {
            if ( ! QueryHelpers::coach_owns_player( get_current_user_id(), $player_id ) ) {
                return RestResponse::error( 'forbidden',
                    __( 'You can only create PDP files for players on your own teams.', 'talenttrack' ), 403 );
            }
        }

        $cycle_size = self::resolveCycleSize( $r, $player_id );
        $owner_id   = absint( $r['owner_coach_id'] ?? get_current_user_id() );

        $files = new PdpFilesRepository();
        $file_id = $files->create( [
            'player_id'      => $player_id,
            'season_id'      => (int) $season->id,
            'owner_coach_id' => $owner_id,
            'cycle_size'     => $cycle_size,
            'notes'          => (string) ( $r['notes'] ?? '' ),
        ] );
        if ( $file_id <= 0 ) {
            return RestResponse::error( 'duplicate_or_failed',
                __( 'A PDP file already exists for this player and season, or the database refused the write.', 'talenttrack' ),
                409 );
        }

        $convs    = new PdpConversationsRepository();
        $created  = $convs->createCycle( $file_id, $cycle_size, (string) $season->start_date, (string) $season->end_date );
        if ( $created !== $cycle_size ) {
            Logger::error( 'pdp.cycle.partial', [
                'file_id'   => $file_id,
                'expected'  => $cycle_size,
                'created'   => $created,
            ] );
        }

        // Calendar link per conversation. Sprint 1 native writer no-ops
        // beyond inserting a tt_pdp_calendar_links row; the Spond writer
        // (#0031) replaces the implementation, not the call site.
        $writer = PdpCalendarWriters::default();
        foreach ( $convs->listForFile( $file_id ) as $c ) {
            $writer->onConversationScheduled( (int) $c->id );
        }

        return RestResponse::success( [
            'id'                  => $file_id,
            'cycle_size'          => $cycle_size,
            'conversations_seeded' => $created,
        ] );
    }

    public static function get_one( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid PDP file id.', 'talenttrack' ), 400 );
        }
        $files = new PdpFilesRepository();
        $file  = $files->find( $id );
        if ( ! $file ) {
            return RestResponse::error( 'not_found', __( 'PDP file not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canSeeFile( $file ) ) {
            return RestResponse::error( 'forbidden',
                __( 'You do not have access to this PDP file.', 'talenttrack' ), 403 );
        }

        $conversations = ( new PdpConversationsRepository() )->listForFile( $id );

        return RestResponse::success( [
            'file'          => self::format_file( $file ),
            'conversations' => array_map( [ __CLASS__, 'format_conversation' ], $conversations ),
        ] );
    }

    /** PATCH /pdp-files/{id} — owner_coach_id and/or status. */
    public static function patch( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid PDP file id.', 'talenttrack' ), 400 );
        }
        $files = new PdpFilesRepository();
        $file  = $files->find( $id );
        if ( ! $file ) {
            return RestResponse::error( 'not_found', __( 'PDP file not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canEditFile( $file ) ) {
            return RestResponse::error( 'forbidden',
                __( 'You do not have permission to edit this PDP file.', 'talenttrack' ), 403 );
        }

        $changed = false;
        if ( array_key_exists( 'owner_coach_id', (array) $r->get_params() ) ) {
            $owner = $r['owner_coach_id'];
            $owner_id = ( $owner === null || $owner === '' ) ? null : absint( $owner );
            if ( ! $files->setOwner( $id, $owner_id ) ) {
                return RestResponse::error( 'db_error',
                    __( 'Owner update failed.', 'talenttrack' ), 500 );
            }
            $changed = true;
        }
        if ( isset( $r['status'] ) ) {
            $status = sanitize_text_field( (string) $r['status'] );
            if ( ! $files->setStatus( $id, $status ) ) {
                return RestResponse::error( 'bad_status',
                    __( 'Invalid status. Use open, completed, or archived.', 'talenttrack' ), 400 );
            }
            $changed = true;
        }
        if ( ! $changed ) {
            return RestResponse::success( [ 'id' => $id, 'unchanged' => true ] );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    private static function resolveCycleSize( \WP_REST_Request $r, int $player_id ): int {
        $explicit = absint( $r['cycle_size'] ?? 0 );
        if ( in_array( $explicit, [ 2, 3, 4 ], true ) ) return $explicit;

        // Per-team override → club default → 3.
        global $wpdb; $p = $wpdb->prefix;
        $team_size = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT t.pdp_cycle_size
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id
              WHERE pl.id = %d",
            $player_id
        ) );
        if ( in_array( $team_size, [ 2, 3, 4 ], true ) ) return $team_size;

        $club_default = (int) QueryHelpers::get_config( 'pdp_cycle_default', '3' );
        return in_array( $club_default, [ 2, 3, 4 ], true ) ? $club_default : 3;
    }

    private static function canSeeFile( object $file ): bool {
        if ( self::hasGlobalPdpAccess( 'read' ) ) return true;
        if ( current_user_can( 'tt_edit_pdp' ) ) {
            return QueryHelpers::coach_owns_player( get_current_user_id(), (int) $file->player_id );
        }
        return current_user_can( 'tt_view_pdp' )
            && QueryHelpers::coach_owns_player( get_current_user_id(), (int) $file->player_id );
    }

    private static function canEditFile( object $file ): bool {
        if ( self::hasGlobalPdpAccess( 'change' ) ) return true;
        if ( ! current_user_can( 'tt_edit_pdp' ) ) return false;
        return QueryHelpers::coach_owns_player( get_current_user_id(), (int) $file->player_id );
    }

    /**
     * #0080 Wave C3 — replacement for the legacy "is admin?" proxy
     * (`current_user_can('tt_edit_settings')`) used to bypass the
     * coach-ownership ladder. Three sources, in order:
     *   1. Matrix grant: `pdp_file/<activity>/global` — the precise
     *      semantic ("user has unrestricted PDP access").
     *   2. WordPress site admin (`manage_options`) — portable fallback
     *      for installs whose matrix is dormant or partially seeded.
     *   3. Legacy umbrella `tt_edit_settings` — preserved for
     *      back-compat with v3.0 callers; the CapabilityAliases
     *      roll-up still grants this when the user holds every
     *      settings sub-cap.
     *
     * @param string $activity 'read' | 'change'
     */
    private static function hasGlobalPdpAccess( string $activity ): bool {
        $uid = get_current_user_id();
        if ( $uid > 0 && class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            $matrix_activity = $activity === 'read' ? MatrixGate::READ : MatrixGate::CHANGE;
            if ( MatrixGate::can( $uid, 'pdp_file', $matrix_activity, MatrixGate::SCOPE_GLOBAL ) ) {
                return true;
            }
        }
        if ( current_user_can( 'manage_options' ) ) return true;
        return current_user_can( 'tt_edit_settings' );
    }

    /** @return array<string,mixed> */
    private static function format_file( object $row ): array {
        return [
            'id'             => (int) $row->id,
            'player_id'      => (int) $row->player_id,
            'season_id'      => (int) $row->season_id,
            'owner_coach_id' => $row->owner_coach_id !== null ? (int) $row->owner_coach_id : null,
            'cycle_size'     => $row->cycle_size !== null ? (int) $row->cycle_size : null,
            'status'         => (string) $row->status,
            'notes'          => $row->notes,
            'created_at'     => $row->created_at,
            'updated_at'     => $row->updated_at,
        ];
    }

    /** @return array<string,mixed> */
    private static function format_conversation( object $row ): array {
        return [
            'id'                => (int) $row->id,
            'pdp_file_id'       => (int) $row->pdp_file_id,
            'sequence'          => (int) $row->sequence,
            'template_key'      => (string) $row->template_key,
            'scheduled_at'      => $row->scheduled_at,
            'conducted_at'      => $row->conducted_at,
            'agenda'            => $row->agenda,
            'notes'             => $row->notes,
            'agreed_actions'    => $row->agreed_actions,
            'player_reflection' => $row->player_reflection,
            'coach_signoff_at'  => $row->coach_signoff_at,
            'parent_ack_at'     => $row->parent_ack_at,
            'player_ack_at'     => $row->player_ack_at,
        ];
    }
}
