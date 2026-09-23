<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Domain\ProposeTestTrainingService;
use TT\Modules\Prospects\ProspectScope;
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
 *                                           block, the consent state and
 *                                           the scouting notes.
 *   POST  /talenttrack/v1/prospects/{id}/test-training-proposal
 *                                           put the prospect forward for a
 *                                           test training.
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
            // #3818 — the route takes no body at all: it starts the chain
            // for the calling user and the form that follows collects
            // everything. An empty declaration is what says so, and what
            // makes a body arriving here a refusal rather than a shrug.
            'args'                => [],
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
                'args'                => self::updateArgs(),
            ],
        ] );
        // #3710 — put a prospect forward for a test training. The invite
        // task is the only thing that links a prospect to one, and until
        // this route it could only be spawned by the pipeline chain, so a
        // prospect whose chain never ran was stuck in the first column.
        register_rest_route( self::NS, '/prospects/(?P<id>\d+)/test-training-proposal', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'propose_test_training' ],
            'permission_callback' => [ self::class, 'can_log' ],
            'args'                => [],
        ] );
    }

    /**
     * The fields `PATCH /prospects/{id}` accepts (#3868). Anything else in
     * the body is refused rather than dropped behind a 200 — the route
     * used to answer `changed: false` for a body it had not understood, so
     * a scout who had recorded a consent request was told it was saved.
     *
     * Every field takes `null` as well as its type: an explicit null (or
     * an empty string) clears the value, which is what makes withdrawing
     * consent expressible at all.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function updateArgs(): array {
        return [
            'parent_name' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Name of the parent or guardian. Empty clears it.',
            ],
            'parent_email' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Email of the parent or guardian. Empty clears it.',
            ],
            'parent_phone' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Phone number of the parent or guardian. Empty clears it.',
            ],
            'consent_given_at' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Date the family gave consent, YYYY-MM-DD. Empty withdraws it.',
            ],
            'scouting_visit_id' => [
                'type'        => [ 'integer', 'null' ],
                'description' => 'The scouting visit the prospect was found at. Null or 0 unlinks.',
            ],
            'scouting_notes' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'What the scout saw, and what happened since. Empty clears it.',
            ],
        ];
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
        //
        // #3160 — what that comment did not cover is the **head coach**,
        // whose `prospects` grant is team-scoped and who was reading the
        // whole club's funnel here. The scope is resolved server-side and
        // merged as an extra AND, so a client filter can narrow further and
        // never widen past it.
        $search_args['scope_sql'] = ProspectScope::sqlClause( $uid, '' );

        $repo  = new ProspectsRepository();
        $rows  = $repo->search( $search_args );
        $count_args = $search_args;
        unset( $count_args['limit'], $count_args['offset'], $count_args['orderby'], $count_args['order'] );
        $total = $repo->count( $count_args );

        // #4017 — how long each prospect has been waiting on a consent
        // answer, for the whole page in one query. A scout could not see a
        // request ageing anywhere, and the only code reading an ageing
        // `awaiting` row uses it to hold the retention clock — so an
        // unchased request ended in a silent purge.
        $waiting = ( new \TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository() )
            ->waitingDaysFor( array_map(
                static fn( $row ): int => (int) ( $row->id ?? 0 ),
                is_array( $rows ) ? $rows : []
            ) );

        $base = home_url( '/' );
        $formatted = array_map( static function ( $row ) use ( $base, $waiting ): array {
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
            $waiting_days = $waiting[ (int) $row->id ] ?? null;
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
                // #4017 — null when nothing is waiting. "Not waiting" and
                // "asked today" are different answers, and a 0 would read
                // as the second.
                'consent_waiting_days'  => $waiting_days,
                'consent_waiting_label' => $waiting_days === null
                    ? ''
                    : sprintf(
                        /* translators: %d: number of days a consent request has been waiting for an answer */
                        _n( '%d day', '%d days', $waiting_days, 'talenttrack' ),
                        $waiting_days
                    ),
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
        // #3160 — the list narrows; the detail must narrow to the same set,
        // or the row the list omits stays readable one id at a time. 404
        // rather than 403 to match the "not found" the caller would get for
        // any id they have no business knowing exists.
        if ( ! self::visibleTo( $id, get_current_user_id() ) ) {
            return RestResponse::error( 'not_found', __( 'Prospect not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'prospect' => $row ] );
    }

    /**
     * #3160 — is this prospect inside the caller's visibility scope?
     *
     * Re-runs the list's own narrowing over the single id rather than
     * reimplementing it, so the two can never disagree about who is
     * visible.
     */
    private static function visibleTo( int $prospect_id, int $user_id ): bool {
        // #3711 — moved into `ProspectScope` so the visit-observation link
        // asks the same question through the same code.
        return ProspectScope::canSee( $user_id, $prospect_id );
    }

    /**
     * PATCH /prospects/{id} — correct the parent contact block and the
     * consent state, (#3600) the scouting visit the prospect was found at,
     * and (#3844) the scouting notes.
     *
     * Scope is deliberately narrow (#2838): the fields a scout needs to fix
     * after the fact, not a general-purpose record editor. A mistyped email
     * and a consent that arrived a day late by text are the two everyday
     * cases, and the second is the one that matters — a consent flag that
     * cannot be corrected asserts a state about a minor that may no longer
     * be true. The scouting notes joined them because the trail of what was
     * seen and what the family answered lives there, and it could only ever
     * be written once, at creation.
     *
     * Narrow, but no longer silent (#3868): a key outside that set is
     * `400 unknown_field`, the way the sibling scouting-visit routes
     * already answer. The route used to build its patch from the keys it
     * knew and return `changed: false` for everything else, so a caller
     * could not tell a discarded write from a no-op.
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
        // The write narrows to the same set the read does (#3160): a scout
        // could otherwise edit the contact and consent of another scout's
        // prospect they cannot even open.
        if ( ! $row || ! self::visibleTo( $id, get_current_user_id() ) ) {
            return RestResponse::error( 'not_found', __( 'Prospect not found.', 'talenttrack' ), 404 );
        }

        // Checked after the scope test so an id the caller may not see
        // answers 404 whatever the body says, and before any field is read
        // so a body mixing a known and an unknown key is refused whole.
        $bad = BaseController::checkBody( $r, self::updateArgs() );
        if ( $bad ) return $bad;

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

        // #3844 — the note is a running trail, not a one-off: when the club
        // asked the family, what the youth coordinator answered, what the
        // scout saw the second time. The repository allowed it all along;
        // only the route never mapped it.
        if ( array_key_exists( 'scouting_notes', $params ) ) {
            $v = trim( sanitize_textarea_field( (string) $r['scouting_notes'] ) );
            $patch['scouting_notes'] = $v !== '' ? $v : null;
        }

        // #3600 — the visit the prospect was found at. Null or 0 unlinks; a
        // visit that is not this club's, or is archived, is refused rather
        // than stored as a dangling id.
        if ( array_key_exists( 'scouting_visit_id', $params ) ) {
            $visit_id = (int) $r['scouting_visit_id'];
            if ( $visit_id <= 0 ) {
                $patch['scouting_visit_id'] = null;
            } elseif ( ( new \TT\Modules\Prospects\Repositories\ScoutingVisitsRepository() )->findLinkable( $visit_id ) === null ) {
                return RestResponse::error(
                    'invalid_visit',
                    __( 'That scouting visit does not exist.', 'talenttrack' ),
                    400
                );
            } else {
                $patch['scouting_visit_id'] = $visit_id;
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
     * POST /prospects/{id}/test-training-proposal — put this prospect
     * forward for a test training (#3710).
     *
     * Takes no body: the prospect is the URL segment and the proposal has
     * nothing else to say. The decision — may this caller propose, and is
     * there already an invite in flight — belongs to
     * `ProposeTestTrainingService`, which the pipeline panel calls too, so
     * the board and the API cannot answer differently.
     *
     * `created` says whether this call made the task or found one already
     * open, so a caller can tell a first proposal from a repeat without
     * the route having to refuse the repeat.
     */
    public static function propose_test_training( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        $uid = get_current_user_id();

        $repo = new ProspectsRepository();
        if ( $repo->find( $id ) === null || ! self::visibleTo( $id, $uid ) ) {
            return RestResponse::error( 'not_found', __( 'Prospect not found.', 'talenttrack' ), 404 );
        }

        $existing = ProposeTestTrainingService::openInviteTaskId( $id );
        $result   = ProposeTestTrainingService::propose( $uid, $id );
        if ( is_wp_error( $result ) ) {
            return RestResponse::error(
                (string) $result->get_error_code(),
                (string) $result->get_error_message(),
                400
            );
        }

        return RestResponse::success( [
            'prospect_id' => $id,
            'task_id'     => $result,
            'created'     => $existing === 0,
        ] );
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

        // #3818 — a caller that sends the prospect's details here is told
        // so. Nothing in this body was ever read: the row is written by the
        // form the task opens, so a POST carrying a name used to start a
        // chain and drop the name.
        $bad = BaseController::checkBody( $r, [] );
        if ( $bad ) return $bad;

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
