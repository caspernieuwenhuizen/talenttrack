<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EvaluationsRestController — /wp-json/talenttrack/v1/evaluations
 *
 * v2.6.2: insert return values are checked. Failures return HTTP 500 with
 * the DB error message and are logged via Logger.
 *
 * #0019 Sprint 1 session 2: create_eval now carries the full legacy
 * payload (opponent, competition, game_result, home_away, minutes_played)
 * and enforces the coach-owns-player check that FrontendAjax ran. An
 * update endpoint was added so the future edit-evaluation view has an
 * API to hit.
 */
class EvaluationsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        // #0052 PR-B — read endpoints gated on `tt_view_evaluations`
        // (the existing read cap) instead of bare `is_user_logged_in()`.
        // Prevents bare-login users from listing evaluations they don't
        // have visibility on.
        register_rest_route( self::NS, '/evaluations', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_evals' ],  'permission_callback' => function () { return current_user_can( 'tt_view_evaluations' ); } ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_edit_evaluations' ); } ],
        ]);
        register_rest_route( self::NS, '/evaluations/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_eval' ],    'permission_callback' => function () { return current_user_can( 'tt_view_evaluations' ); } ],
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_edit_evaluations' ); } ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_edit_evaluations' ); } ],
        ]);
    }

    /** Whitelist of columns the `orderby` query param accepts (v3.110.106). */
    private const ORDERBY_WHITELIST = [
        'eval_date'   => 'e.eval_date',
        'player_name' => 'pl.last_name',
        'team_name'   => 't.name',
        'coach_name'  => 'u.display_name',
        'avg_rating'  => 'avg_rating',
    ];

    /**
     * GET /evaluations — paginated list with search, filters, sort.
     *
     * v3.110.106 — rewritten to support the FrontendListTable contract
     * used by `FrontendEvaluationsView` (and matching the goals page
     * pattern): pagination, filter[…], search, orderby/order, returns
     * `{rows, total, page, per_page}` shape.
     *
     * Backward-compat: a top-level `?player_id=N` (no `filter[…]`
     * wrapping, the legacy shape) is still honoured by folding it into
     * the filter map. Callers that just want a player's evaluations
     * keep working without code changes.
     *
     * Query params:
     *   ?search=<text>                 — player name / notes LIKE
     *   ?filter[team_id]=<int>         — via player → team join
     *   ?filter[player_id]=<int>
     *   ?filter[eval_type_id]=<int>
     *   ?filter[date_from]=<YYYY-MM-DD>
     *   ?filter[date_to]=<YYYY-MM-DD>
     *   ?orderby=eval_date|player_name|team_name|coach_name|avg_rating
     *   ?order=asc|desc                                  (default desc on eval_date)
     *   ?page=<int>                                      (default 1)
     *   ?per_page=10|25|50|100                           (default 25)
     */
    public static function list_evals( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );
        $offset   = ( $page - 1 ) * $per_page;

        $orderby_key = sanitize_key( (string) ( $r['orderby'] ?? 'eval_date' ) );
        if ( ! isset( self::ORDERBY_WHITELIST[ $orderby_key ] ) ) {
            $orderby_key = 'eval_date';
        }
        $orderby = self::ORDERBY_WHITELIST[ $orderby_key ];
        $order   = strtolower( (string) ( $r['order'] ?? ( $orderby_key === 'eval_date' ? 'desc' : 'asc' ) ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'desc';

        $where  = [ 'e.club_id = %d', 'e.archived_at IS NULL' ];
        $params = [ CurrentClub::id() ];

        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );

        // Coach-scoping: non-admins only see evals for players on teams
        // they head-coach. Matches the v3.110.105 hand-rolled view.
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            $coach_teams = QueryHelpers::get_teams_for_coach( get_current_user_id() );
            if ( ! $coach_teams ) {
                return RestResponse::success( [
                    'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page,
                ] );
            }
            $team_ids     = array_map( static fn( $t ) => (int) $t->id, $coach_teams );
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where[]      = "pl.team_id IN ($placeholders)";
            $params       = array_merge( $params, $team_ids );
        }

        $filter = is_array( $r['filter'] ?? null ) ? (array) $r['filter'] : [];

        // Legacy top-level `?player_id=N` callers (pre-v3.110.106 contract).
        if ( ! empty( $r['player_id'] ) && empty( $filter['player_id'] ) ) {
            $filter['player_id'] = absint( $r['player_id'] );
        }

        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }
        if ( ! empty( $filter['player_id'] ) ) {
            $where[]  = 'e.player_id = %d';
            $params[] = absint( $filter['player_id'] );
        }
        if ( ! empty( $filter['eval_type_id'] ) ) {
            $where[]  = 'e.eval_type_id = %d';
            $params[] = absint( $filter['eval_type_id'] );
        }
        if ( ! empty( $filter['date_from'] ) ) {
            $where[]  = 'e.eval_date >= %s';
            $params[] = sanitize_text_field( (string) $filter['date_from'] );
        }
        if ( ! empty( $filter['date_to'] ) ) {
            $where[]  = 'e.eval_date <= %s';
            $params[] = sanitize_text_field( (string) $filter['date_to'] );
        }

        if ( ! empty( $r['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = "(pl.first_name LIKE %s OR pl.last_name LIKE %s OR e.notes LIKE %s)";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        $list_sql = "SELECT e.id, e.eval_date, e.notes, e.player_id, e.coach_id, e.eval_type_id,
                            pl.first_name, pl.last_name, pl.team_id,
                            t.name AS team_name,
                            u.display_name AS coach_name,
                            coach_p.id AS coach_person_id,
                            et.name  AS eval_type_key,
                            et.label AS eval_type_label,
                            et.meta  AS eval_type_meta,
                            (SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r
                              WHERE r.evaluation_id = e.id AND r.club_id = e.club_id) AS avg_rating
                       FROM {$p}tt_evaluations e
                       LEFT JOIN {$p}tt_players pl     ON pl.id = e.player_id
                       LEFT JOIN {$p}tt_teams   t      ON t.id  = pl.team_id
                       LEFT JOIN {$wpdb->users} u      ON u.ID  = e.coach_id
                       LEFT JOIN {$p}tt_people  coach_p ON coach_p.wp_user_id = e.coach_id AND coach_p.club_id = e.club_id
                       LEFT JOIN {$p}tt_lookups et     ON et.id = e.eval_type_id AND et.lookup_type = 'eval_type'
                      WHERE {$where_sql}
                      ORDER BY {$orderby} {$order}, e.id DESC
                      LIMIT %d OFFSET %d";

        $list_params = array_merge( $params, [ $per_page, $offset ] );
        $rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) );

        $count_sql = "SELECT COUNT(*) FROM {$p}tt_evaluations e
                       LEFT JOIN {$p}tt_players pl ON pl.id = e.player_id
                       LEFT JOIN {$p}tt_teams   t  ON t.id  = pl.team_id
                       LEFT JOIN {$wpdb->users} u  ON u.ID  = e.coach_id
                      WHERE {$where_sql}";
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'format_row' ], $rows ?: [] ),
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
     * @param object $row evaluations row joined with player / team / coach / eval_type
     * @return array<string,mixed>
     */
    private static function format_row( $row ): array {
        $first       = (string) ( $row->first_name ?? '' );
        $last        = (string) ( $row->last_name ?? '' );
        $player_name = trim( $first . ' ' . $last );
        if ( $player_name === '' ) $player_name = '#' . (int) $row->player_id;
        $player_id  = (int) $row->player_id;
        $team_id    = (int) ( $row->team_id ?? 0 );
        $team_name  = (string) ( $row->team_name ?? '' );
        $coach_name = (string) ( $row->coach_name ?? '' );
        $coach_pid  = (int) ( $row->coach_person_id ?? 0 );
        $eval_id    = (int) $row->id;
        $avg        = $row->avg_rating !== null ? round( (float) $row->avg_rating, 1 ) : null;

        $eval_url = \TT\Shared\Frontend\Components\BackLink::appendTo(
            add_query_arg(
                [ 'tt_view' => 'evaluations', 'id' => $eval_id ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            )
        );

        // Pre-rendered link HTML so FrontendListTable can render cells via render: html.
        $date_link_html = '<a class="tt-record-link" href="' . esc_url( $eval_url ) . '">'
                        . esc_html( (string) $row->eval_date ) . '</a>';

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
        } elseif ( $team_name !== '' ) {
            $team_link_html = esc_html( $team_name );
        }

        $coach_link_html = $coach_name !== '' ? esc_html( $coach_name ) : '—';
        if ( $coach_name !== '' && $coach_pid > 0 ) {
            $coach_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
                $coach_name,
                \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'people', $coach_pid )
            );
        }

        $avg_text     = $avg === null ? '—' : number_format_i18n( $avg, 1 );
        $avg_link_html = $avg === null
            ? '<span class="tt-muted">—</span>'
            : '<a class="tt-record-link" href="' . esc_url( $eval_url ) . '"><strong>' . esc_html( $avg_text ) . '</strong></a>';

        $eval_type_label = '';
        if ( ! empty( $row->eval_type_id ) ) {
            $eval_type_label = (string) \TT\Infrastructure\Query\LookupTranslator::name( (object) [
                'name'  => (string) ( $row->eval_type_key ?? '' ),
                'label' => (string) ( $row->eval_type_label ?? '' ),
                'meta'  => (string) ( $row->eval_type_meta ?? '' ),
            ] );
        }

        return [
            'id'              => $eval_id,
            'eval_date'       => (string) $row->eval_date,
            'date_link_html'  => $date_link_html,
            'player_id'       => $player_id,
            'player_name'     => $player_name,
            'player_link_html'=> $player_link_html,
            'team_id'         => $team_id,
            'team_name'       => $team_name,
            'team_link_html'  => $team_link_html,
            'coach_name'      => $coach_name,
            'coach_link_html' => $coach_link_html,
            'eval_type_id'    => (int) ( $row->eval_type_id ?? 0 ),
            'eval_type_label' => $eval_type_label,
            'avg_rating'      => $avg,
            'avg_link_html'   => $avg_link_html,
            'notes_excerpt'   => esc_html( wp_trim_words( (string) ( $row->notes ?? '' ), 14 ) ),
        ];
    }

    public static function get_eval( \WP_REST_Request $r ) {
        $e = QueryHelpers::get_evaluation( (int) $r['id'] );
        if ( ! $e ) return RestResponse::error( 'not_found', __( 'Evaluation not found.', 'talenttrack' ), 404 );
        return RestResponse::success( (array) $e );
    }

    public static function create_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $header = self::extract( $r );
        $header['coach_id'] = get_current_user_id();

        if ( $header['player_id'] <= 0 || $header['eval_date'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Player and date are required.', 'talenttrack' ), 400 );
        }
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            if ( ! QueryHelpers::coach_owns_player( get_current_user_id(), (int) $header['player_id'] ) ) {
                return RestResponse::error( 'forbidden_player', __( 'You can only evaluate players in your team.', 'talenttrack' ), 403 );
            }
        }

        do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );

        $ok = $wpdb->insert( "{$p}tt_evaluations", $header );
        if ( $ok === false ) {
            Logger::error( 'rest.evaluation.create.failed', [ 'db_error' => (string) $wpdb->last_error, 'payload' => $header ] );
            return RestResponse::error(
                'db_error',
                __( 'The evaluation could not be created.', 'talenttrack' ),
                500,
                [ 'db_error' => (string) $wpdb->last_error ]
            );
        }
        $id = (int) $wpdb->insert_id;
        // v3.76.2 — auto-tag demo-on rows.
        \TT\Modules\DemoData\DemoMode::tagIfActive( 'evaluation', $id );

        $rating_failures = self::write_ratings( $id, (array) ( $r['ratings'] ?? [] ) );
        if ( $rating_failures ) {
            Logger::error( 'rest.evaluation.ratings.failed', [ 'evaluation_id' => $id, 'failures' => $rating_failures ] );
            return RestResponse::error(
                'partial_save',
                __( 'The evaluation was saved but some ratings failed.', 'talenttrack' ),
                500,
                [ 'evaluation_id' => $id, 'failures' => $rating_failures ]
            );
        }

        // #0018 — let downstream listeners (e.g. CompatibilityEngine
        // cache invalidation) know the player's evaluation surface
        // changed.
        do_action( 'tt_evaluation_saved', (int) $header['player_id'], $id );

        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function update_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid evaluation id.', 'talenttrack' ), 400 );
        }

        $header = self::extract( $r );
        unset( $header['coach_id'] ); // preserve original coach
        $ok = $wpdb->update( "{$p}tt_evaluations", $header, [ 'id' => $id ] );
        if ( $ok === false ) {
            Logger::error( 'rest.evaluation.update.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error(
                'db_error',
                __( 'The evaluation could not be updated.', 'talenttrack' ),
                500,
                [ 'db_error' => (string) $wpdb->last_error ]
            );
        }

        if ( isset( $r['ratings'] ) ) {
            // v3.110.66 — surgical per-category update. Was a blanket
            // `DELETE FROM tt_eval_ratings WHERE evaluation_id = X`
            // followed by re-insert of submitted rows, which had two
            // problems:
            //   1. Subcategory ratings (which `CoachForms::renderEvalForm`
            //      doesn't render) got wiped on every save, even though
            //      the coach didn't touch them.
            //   2. Combined with the `required` drop on the form, an
            //      unsaved category implicitly meant "set to 0" (because
            //      `(float) ''` is 0, clamped to `rating_min`) — turning
            //      every blank field into a 1-rating.
            // New semantics:
            //   - Submitted category with non-empty value → delete the
            //     existing rating row for that category, then insert
            //     the new value. Net effect: upsert.
            //   - Submitted category with empty value → delete only,
            //     no insert. Net effect: clear the rating.
            //   - Categories NOT in the submission (e.g. subcategory
            //     rows the form doesn't render) → untouched.
            $ratings    = (array) $r['ratings'];
            $club_id    = \TT\Infrastructure\Tenancy\CurrentClub::id();
            foreach ( $ratings as $cid => $val ) {
                $cid_int = absint( $cid );
                if ( $cid_int <= 0 ) continue;
                $wpdb->delete( "{$p}tt_eval_ratings", [
                    'evaluation_id' => $id,
                    'category_id'   => $cid_int,
                    'club_id'       => $club_id,
                ] );
            }
            $rating_failures = self::write_ratings( $id, $ratings );
            if ( $rating_failures ) {
                Logger::error( 'rest.evaluation.ratings.update.failed', [ 'evaluation_id' => $id, 'failures' => $rating_failures ] );
                return RestResponse::error(
                    'partial_save',
                    __( 'The evaluation was updated but some ratings failed.', 'talenttrack' ),
                    500,
                    [ 'evaluation_id' => $id, 'failures' => $rating_failures ]
                );
            }
        }

        // #0018 — same hook as create_eval; let cache invalidators
        // know the player's evaluation surface changed.
        if ( ! empty( $header['player_id'] ) ) {
            do_action( 'tt_evaluation_saved', (int) $header['player_id'], $id );
        }

        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid evaluation id.', 'talenttrack' ), 400 );
        }

        // v3.110.55 — soft-archive via `archived_at` + `archived_by`,
        // mirroring `delete_player` / `delete_team`. Read-side queries
        // already filter `e.archived_at IS NULL`, so the archived row
        // simply disappears from list / detail views without losing the
        // history. Restore is an admin operation.
        $ok = $wpdb->update(
            "{$p}tt_evaluations",
            [ 'archived_at' => current_time( 'mysql' ), 'archived_by' => get_current_user_id() ],
            [ 'id' => $id ]
        );
        if ( $ok === false ) {
            Logger::error( 'rest.evaluation.archive.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error(
                'db_error',
                __( 'The evaluation could not be archived.', 'talenttrack' ),
                500
            );
        }
        return RestResponse::success( [ 'archived' => true, 'id' => $id ] );
    }

    /**
     * Extract the evaluation header columns from a REST request. Matches
     * the legacy FrontendAjax payload so the existing form submits in
     * the same shape.
     *
     * @return array<string, mixed>
     */
    private static function extract( \WP_REST_Request $r ): array {
        return [
            'player_id'      => absint( $r['player_id'] ?? 0 ),
            'coach_id'       => get_current_user_id(),
            'eval_type_id'   => absint( $r['eval_type_id'] ?? 0 ),
            'eval_date'      => sanitize_text_field( (string) ( $r['eval_date'] ?? current_time( 'Y-m-d' ) ) ),
            'notes'          => sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) ),
            'opponent'       => sanitize_text_field( (string) ( $r['opponent'] ?? '' ) ),
            'competition'    => sanitize_text_field( (string) ( $r['competition'] ?? '' ) ),
            'game_result'   => sanitize_text_field( (string) ( $r['game_result'] ?? '' ) ),
            'home_away'      => sanitize_text_field( (string) ( $r['home_away'] ?? '' ) ),
            'minutes_played' => ! empty( $r['minutes_played'] ) ? absint( $r['minutes_played'] ) : null,
        ];
    }

    /**
     * @param array<int|string, mixed> $ratings category_id => rating
     * @return array<int, array{category_id:int, db_error:string}>
     */
    private static function write_ratings( int $evaluation_id, array $ratings ): array {
        global $wpdb; $p = $wpdb->prefix;
        $rmin = (float) QueryHelpers::get_config( 'rating_min', '1' );
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '5' );
        // v3.110.x — every rating row carries `club_id` so the read-side
        // queries (`QueryHelpers::get_evaluation`,
        // `EvalRatingsRepository::overallRatingsForEvaluations`, the
        // FrontendMyEvaluationsView category breakdown, the new
        // FrontendEvaluationsView detail page) can filter by tenant
        // safely. Migration 0038 added the column with `DEFAULT 1`, but
        // strict-mode MySQL installs that didn't pick up the default
        // ended up with rating rows at `club_id = 0` — invisible to
        // every read scoped by `CurrentClub::id()`. Setting it
        // explicitly here closes that hole going forward.
        $club_id = \TT\Infrastructure\Tenancy\CurrentClub::id();
        $failures = [];
        foreach ( $ratings as $cid => $val ) {
            // v3.110.66 — skip empty/null values. The form's main-
            // category inputs no longer carry `required` in edit
            // mode (so the coach can leave a category blank), and
            // an empty number input POSTs as `''`. Without this
            // guard, `(float) ''` would coerce to 0 and clamp up to
            // `rating_min` (1), silently writing a 1-rating into
            // every blank category. The caller (`update_eval`) has
            // already deleted the prior row for these categories,
            // so skipping the insert here is the "clear this
            // rating" path.
            if ( $val === '' || $val === null ) continue;
            if ( ! is_numeric( $val ) ) continue;
            $clamped = max( $rmin, min( $rmax, (float) $val ) );
            $ok = $wpdb->insert( "{$p}tt_eval_ratings", [
                'club_id'       => $club_id,
                'evaluation_id' => $evaluation_id,
                'category_id'   => absint( $cid ),
                'rating'        => $clamped,
            ] );
            if ( $ok === false ) {
                $failures[] = [ 'category_id' => absint( $cid ), 'db_error' => (string) $wpdb->last_error ];
            }
        }
        return $failures;
    }
}
