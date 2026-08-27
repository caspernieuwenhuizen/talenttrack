<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\Templates\LogProspectTemplate;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\WorkflowModule;

/**
 * REST surface for the #0081 prospects entity (child 2 — chain entry
 * point only).
 *
 * Routes:
 *
 *   POST  /talenttrack/v1/prospects/log     dispatch the LogProspect
 *                                           chain for the current user.
 *   GET   /talenttrack/v1/prospects         paginated list.
 *   GET   /talenttrack/v1/prospects/{id}    one prospect.
 *   PATCH /talenttrack/v1/prospects/{id}    correct the parent contact
 *                                           block and the consent state.
 *
 * Subsequent stages (parent confirmation, test-training outcome
 * recording, trial-group review) are handled entirely by `TaskEngine`
 * chain spawning — no bespoke orchestration. PR 2b adds a public
 * (no-login) signed-token endpoint for the parent-confirmation stage.
 *
 * The chain entry point exists as a REST route — and not just an
 * inline form button — so the future `OnboardingPipelineWidget`
 * (child 3) and any external integration (PR 2b's public endpoint
 * being the first such integration) consume the same code path.
 */
class ProspectsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/prospects/log', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'log_prospect' ],
            'permission_callback' => [ self::class, 'can_log' ],
        ] );
        // v3.110.99 — list endpoint backing FrontendListTable on the new
        // ?tt_view=prospects-overview page.
        register_rest_route( self::NS, '/prospects', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'list_prospects' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
        // #2838 — a prospect could be created and never corrected. The
        // repository's update() already whitelisted these fields; nothing
        // had ever called it.
        register_rest_route( self::NS, '/prospects/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_prospect' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ self::class, 'update_prospect' ],
                'permission_callback' => [ self::class, 'can_log' ],
            ],
        ] );
    }

    public static function can_log(): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_prospects' );
    }

    public static function can_view(): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_view_prospects' );
    }

    /**
     * GET /prospects — paginated list for FrontendListTable.
     *
     * Filter params (`?filter[…]=`): status, discovered_by_user_id,
     * include_archived. Search: `?search=`. Sort: `?orderby=&order=`.
     * Pagination: `?page=&per_page=` (per_page clamped to 10/25/50/100,
     * default 25).
     *
     * Scout role scoping: scouts see only their own prospects. The
     * scope is enforced server-side regardless of any operator-supplied
     * discovered_by_user_id filter — the filter narrows further but
     * can't widen.
     */
    public static function list_prospects( \WP_REST_Request $r ) {
        $uid = get_current_user_id();
        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];

        $search_args = [
            'orderby' => sanitize_key( (string) ( $r['orderby'] ?? 'discovered_at' ) ),
            'order'   => strtolower( (string) ( $r['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc',
            'limit'   => $per_page,
            'offset'  => ( $page - 1 ) * $per_page,
        ];

        if ( ! empty( $filter['status'] ) ) {
            $search_args['status'] = sanitize_key( (string) $filter['status'] );
        }
        $include_archived = ! empty( $filter['include_archived'] );
        if ( $include_archived ) {
            $search_args['include_archived'] = true;
        }
        if ( ! empty( $filter['discovered_by_user_id'] ) ) {
            $search_args['discovered_by_user_id'] = (int) $filter['discovered_by_user_id'];
        }
        if ( ! empty( $r['search'] ) ) {
            $search_args['name_like'] = sanitize_text_field( (string) $r['search'] );
        }

        // v3.110.154 — scout-scope clamp removed. Policy changed:
        // scouts now read all prospects (matrix entity now `global`
        // per #0081 follow-up — two scouts on the same pool need
        // collaboration). Personal-funnel views (MyRecentProspectsSource,
        // MyProspects* KPIs, AddProspectHeroWidget) still scope to
        // `discovered_by_user_id = $user_id` at the query level and
        // aren't affected. An explicit
        // `?filter[discovered_by_user_id]=N` from the client (e.g. a
        // "show only my prospects" toggle on the overview page) still
        // works because the list deliberately leaves the filter
        // untouched.

        $repo  = new ProspectsRepository();
        $rows  = $repo->search( $search_args );
        $count_args = $search_args;
        unset( $count_args['limit'], $count_args['offset'], $count_args['orderby'], $count_args['order'] );
        $total = $repo->count( $count_args );

        $base = home_url( '/' );
        $formatted = array_map( static function ( $row ) use ( $base ): array {
            $first = (string) ( $row->first_name ?? '' );
            $last  = (string) ( $row->last_name  ?? '' );
            $dob   = (string) ( $row->date_of_birth ?? '' );
            $year  = '';
            if ( $dob !== '' ) {
                $ts = strtotime( $dob );
                if ( $ts !== false ) {
                    $y = (int) date( 'Y', $ts );
                    if ( $y >= 1900 && $y <= (int) date( 'Y' ) ) $year = (string) $y;
                }
            }
            $disc_by = (int) ( $row->discovered_by_user_id ?? 0 );
            $disc_by_name = '';
            if ( $disc_by > 0 ) {
                $u = get_userdata( $disc_by );
                if ( $u ) $disc_by_name = (string) $u->display_name;
            }
            $status = 'active';
            if ( ! empty( $row->archived_at ) ) {
                $status = 'archived';
            } elseif ( ! empty( $row->promoted_to_trial_case_id ) ) {
                $status = 'trial';
            } elseif ( ! empty( $row->promoted_to_player_id ) ) {
                $status = 'joined';
            }
            $status_label = self::statusLabelFor( $status );
            return [
                'id'              => (int) $row->id,
                'first_name'      => $first,
                'last_name'       => $last,
                'birth_year'      => $year,
                'current_club'    => (string) ( $row->current_club ?? '' ),
                'discovered_at'   => (string) ( $row->discovered_at ?? '' ),
                'discovered_by'   => $disc_by_name,
                'status'          => $status,
                'status_label'    => $status_label,
            ];
        }, $rows );

        return RestResponse::success( [
            'rows'     => $formatted,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    private static function statusLabelFor( string $status ): string {
        switch ( $status ) {
            case 'active':   return __( 'Active',   'talenttrack' );
            case 'trial':    return __( 'In trial', 'talenttrack' );
            case 'joined':   return __( 'Joined',   'talenttrack' );
            case 'archived': return __( 'Archived', 'talenttrack' );
        }
        return $status;
    }

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    /**
     * GET /prospects/{id} — one prospect, club-scoped by the repository.
     */
    public static function get_prospect( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = (int) $r['id'];
        $row = $id > 0 ? ( new ProspectsRepository() )->find( $id ) : null;
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Prospect not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'prospect' => $row ] );
    }

    /**
     * PATCH /prospects/{id} — correct the parent contact block and the
     * consent state.
     *
     * Scope is deliberately narrow (#2838): the four fields a scout needs
     * to fix after the fact, not a general-purpose record editor. A
     * mistyped email and a consent that arrived a day late by text are the
     * two everyday cases, and the second is the one that matters — a
     * consent flag that cannot be corrected asserts a state about a minor
     * that may no longer be true.
     *
     * `array_key_exists` rather than `isset` throughout, so an explicit
     * null clears a field instead of being read as "not supplied". That is
     * what makes withdrawing consent expressible at all.
     */
    public static function update_prospect( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid prospect id.', 'talenttrack' ), 400 );
        }

        $repo = new ProspectsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Prospect not found.', 'talenttrack' ), 404 );
        }

        $params = $r->get_params();
        $patch  = [];

        if ( array_key_exists( 'parent_name', $params ) ) {
            $v = trim( sanitize_text_field( (string) $r['parent_name'] ) );
            $patch['parent_name'] = $v !== '' ? $v : null;
        }
        if ( array_key_exists( 'parent_email', $params ) ) {
            $raw = trim( (string) $r['parent_email'] );
            if ( $raw === '' ) {
                $patch['parent_email'] = null;
            } else {
                $email = sanitize_email( $raw );
                if ( ! is_email( $email ) ) {
                    return RestResponse::error(
                        'invalid_email',
                        __( 'That does not look like an email address.', 'talenttrack' ),
                        400
                    );
                }
                $patch['parent_email'] = $email;
            }
        }
        if ( array_key_exists( 'parent_phone', $params ) ) {
            $v = trim( sanitize_text_field( (string) $r['parent_phone'] ) );
            $patch['parent_phone'] = $v !== '' ? $v : null;
        }
        if ( array_key_exists( 'consent_given_at', $params ) ) {
            $raw = trim( (string) $r['consent_given_at'] );
            if ( $raw === '' ) {
                $patch['consent_given_at'] = null;
            } elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
                $patch['consent_given_at'] = $raw . ' 00:00:00';
            } else {
                // Reject rather than store null: silently dropping a
                // consent date the operator typed is the failure mode this
                // whole endpoint exists to end.
                return RestResponse::error(
                    'invalid_consent_date',
                    __( 'Consent date must be YYYY-MM-DD.', 'talenttrack' ),
                    400
                );
            }
        }

        if ( ! $patch ) {
            return RestResponse::success( [ 'id' => $id, 'changed' => false ] );
        }

        $ok = $repo->update( $id, $patch );
        if ( $ok ) {
            self::audit_changes( $id, $row, $patch );
        }
        return RestResponse::success( [ 'id' => $id, 'changed' => $ok ] );
    }

    /**
     * Two distinct audit actions, because they answer different questions.
     * A contact correction is housekeeping; a consent change is the record
     * of whether a family agreed to their child being tracked, and a
     * consent state that can be edited without a trail is no better than
     * one that cannot be edited at all.
     *
     * @param array<string,mixed> $patch
     */
    private static function audit_changes( int $id, object $before, array $patch ): void {
        $audit = new AuditService();

        if ( array_key_exists( 'consent_given_at', $patch ) ) {
            $audit->record( 'prospect.consent_changed', 'prospect', $id, [
                'from' => $before->consent_given_at ?? null,
                'to'   => $patch['consent_given_at'],
            ] );
        }

        $contact = array_diff_key( $patch, [ 'consent_given_at' => true ] );
        if ( $contact ) {
            $audit->record( 'prospect.updated', 'prospect', $id, [
                'fields' => array_keys( $contact ),
            ] );
        }
    }

    /**
     * Start the chain. Dispatches a `LogProspectTemplate` task assigned
     * to the calling user; the task's form (`LogProspectForm`) writes
     * the actual `tt_prospects` row on submit. This is intentionally
     * thin — the surface lives at the form, not the REST endpoint.
     *
     * The endpoint exists so the "+ New prospect" button on the future
     * pipeline widget (and the future Onboarding Pipeline standalone
     * view) has a stable, capability-gated entry into the chain.
     *
     * Response payload echoes the new task ID + the canonical task-
     * detail URL so the caller can redirect the scout straight into
     * the form. No prospect row exists yet at this point — the row is
     * only created when the form is submitted.
     */
    public static function log_prospect( \WP_REST_Request $r ) {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) {
            return RestResponse::error( 'not_logged_in', __( 'You must be logged in to log a prospect.', 'talenttrack' ), 401 );
        }

        $context = new TaskContext(
            null, null, null, null, null, null, null, null,
            [ 'initiated_by' => $uid ]
        );
        $task_ids = WorkflowModule::engine()->dispatch( LogProspectTemplate::KEY, $context );

        if ( empty( $task_ids ) ) {
            return RestResponse::error(
                'dispatch_failed',
                __( 'Could not start the prospect-logging chain. Check the workflow templates are enabled.', 'talenttrack' ),
                500
            );
        }

        $task_id = (int) $task_ids[0];
        return RestResponse::success( [
            'task_id'      => $task_id,
            'redirect_url' => add_query_arg(
                [
                    'tt_view'  => 'my-tasks',
                    'task_id'  => $task_id,
                ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            ),
        ], 201 );
    }
}
