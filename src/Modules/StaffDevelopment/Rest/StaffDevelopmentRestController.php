<?php
namespace TT\Modules\StaffDevelopment\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\StaffDevelopment\Repositories\StaffCertificationsRepository;
use TT\Modules\StaffDevelopment\Repositories\StaffEvaluationsRepository;
use TT\Modules\StaffDevelopment\Repositories\StaffGoalsRepository;
use TT\Modules\StaffDevelopment\Repositories\StaffMentorshipsRepository;
use TT\Modules\StaffDevelopment\Repositories\StaffPdpRepository;
use WP_REST_Request;

/**
 * REST surface for #0039 — staff development.
 *
 * Resource-oriented routes under `talenttrack/v1`:
 *
 *   GET    /staff/{person_id}/goals
 *   POST   /staff/{person_id}/goals
 *   PUT    /staff-goals/{id}
 *   DELETE /staff-goals/{id}
 *
 *   GET    /staff/{person_id}/evaluations
 *   POST   /staff/{person_id}/evaluations
 *   PUT    /staff-evaluations/{id}
 *   DELETE /staff-evaluations/{id}
 *
 *   GET    /staff/{person_id}/certifications
 *   POST   /staff/{person_id}/certifications
 *   PUT    /staff-certifications/{id}
 *   DELETE /staff-certifications/{id}
 *
 *   GET    /staff/{person_id}/pdp
 *   PUT    /staff/{person_id}/pdp                    upsert by (person_id, season_id)
 *
 *   GET    /staff/expiring-certifications            window query, manager-only
 *
 *   GET    /staff/{person_id}/mentorships
 *   POST   /staff/{person_id}/mentorships
 *   DELETE /staff-mentorships/{id}
 *
 * Bundled in one controller for v1 (mirrors `TrialsRestController`'s
 * approach). Per-resource controllers can be split out in v2 if any
 * grows beyond 100 LOC of route logic.
 */
final class StaffDevelopmentRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        // Goals
        register_rest_route( self::NS, '/staff/(?P<person_id>\d+)/goals', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_goals' ],   'permission_callback' => [ __CLASS__, 'can_view' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_goal' ], 'permission_callback' => [ __CLASS__, 'can_manage_target' ], 'args' => self::goalArgs() ],
        ] );
        register_rest_route( self::NS, '/staff-goals/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_goal' ], 'permission_callback' => [ __CLASS__, 'can_manage' ], 'args' => self::goalUpdateArgs() ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_goal' ], 'permission_callback' => [ __CLASS__, 'can_manage' ] ],
        ] );

        // Evaluations
        register_rest_route( self::NS, '/staff/(?P<person_id>\d+)/evaluations', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_evaluations' ],   'permission_callback' => [ __CLASS__, 'can_view' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_evaluation' ], 'permission_callback' => [ __CLASS__, 'can_evaluate_target' ], 'args' => self::evaluationArgs() ],
        ] );
        register_rest_route( self::NS, '/staff-evaluations/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_evaluation' ], 'permission_callback' => [ __CLASS__, 'can_manage' ], 'args' => self::evaluationUpdateArgs() ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_evaluation' ], 'permission_callback' => [ __CLASS__, 'can_manage' ] ],
        ] );

        // Certifications
        register_rest_route( self::NS, '/staff/(?P<person_id>\d+)/certifications', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_certifications' ],   'permission_callback' => [ __CLASS__, 'can_view' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_certification' ], 'permission_callback' => [ __CLASS__, 'can_manage_target' ], 'args' => self::certificationArgs() ],
        ] );
        register_rest_route( self::NS, '/staff-certifications/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_certification' ], 'permission_callback' => [ __CLASS__, 'can_manage' ], 'args' => self::certificationUpdateArgs() ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_certification' ], 'permission_callback' => [ __CLASS__, 'can_manage' ] ],
        ] );

        // PDP
        register_rest_route( self::NS, '/staff/(?P<person_id>\d+)/pdp', [
            [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_pdp' ], 'permission_callback' => [ __CLASS__, 'can_view' ] ],
            [ 'methods' => 'PUT', 'callback' => [ __CLASS__, 'upsert_pdp' ], 'permission_callback' => [ __CLASS__, 'can_manage_target' ], 'args' => self::pdpArgs() ],
        ] );

        // Expiring certs (HoD overview + workflow)
        register_rest_route( self::NS, '/staff/expiring-certifications', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_expiring' ],
            'permission_callback' => static function () { return current_user_can( 'tt_view_staff_certifications_expiry' ); },
        ] );

        // Mentorships
        register_rest_route( self::NS, '/staff/(?P<person_id>\d+)/mentorships', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_mentorships' ],  'permission_callback' => [ __CLASS__, 'can_view' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_mentorship' ], 'permission_callback' => [ __CLASS__, 'can_manage' ], 'args' => self::mentorshipArgs() ],
        ] );
        register_rest_route( self::NS, '/staff-mentorships/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'delete_mentorship' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );
    }

    /* ===== body contracts (#3819) ===== */

    /*
     * Nothing on this surface is declared `required`. Core checks required
     * params in `has_valid_params()`, which runs before the permission
     * callback, so a required field would answer a caller with no staff
     * grant with a 400 naming the fields rather than the 403 it is owed.
     * The repositories refuse an incomplete write themselves.
     *
     * `archived_at` and `archived_by` are absent on purpose, although the
     * repositories' update allowlists still accept them. They are
     * lifecycle columns the DELETE route owns; a PUT that set them would
     * archive a record around the route built to do it, and nothing sends
     * them today.
     *
     * The `person_id` in the path is what the permission callback reads to
     * decide whether the caller may write for that person, so a body copy
     * is accepted and ignored rather than trusted.
     */

    /**
     * The body a staff goal takes on create.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function goalArgs(): array {
        return [
            'person_id'           => [ 'type' => [ 'integer', 'string' ], 'description' => 'The staff member, from the URL. A copy in the body is accepted and ignored.' ],
            'season_id'           => [ 'type' => [ 'integer', 'string' ], 'description' => 'The season the goal belongs to.' ],
            'title'               => [ 'type' => 'string', 'description' => 'What the goal is.' ],
            'description'         => [ 'type' => 'string', 'description' => 'What reaching it looks like.' ],
            'priority'            => [ 'type' => 'string', 'description' => 'How urgent the goal is.' ],
            'due_date'            => [ 'type' => 'string', 'description' => 'Target date as YYYY-MM-DD. Blank means no deadline.' ],
            'cert_type_lookup_id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'The certificate this goal works towards, when it is a qualification.' ],
        ];
    }

    /**
     * `PUT /staff-goals/{id}`: the create fields plus where the goal
     * stands. Every field is optional and an omitted one is left alone
     * (CLAUDE.md §6) — the repository writes only the keys it is given.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function goalUpdateArgs(): array {
        $args = self::goalArgs();
        unset( $args['person_id'] );
        return [
            'id'     => [ 'type' => [ 'integer', 'string' ], 'description' => 'The goal, from the URL. A copy in the body is accepted and ignored.' ],
            'status' => [ 'type' => 'string', 'description' => 'Where the goal stands.' ],
        ] + $args;
    }

    /**
     * The body a staff evaluation takes on create.
     *
     * The reviewer is never read from the body: it is whoever is calling,
     * so an evaluation cannot be attributed to somebody who did not write
     * it.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function evaluationArgs(): array {
        return [
            'person_id'   => [ 'type' => [ 'integer', 'string' ], 'description' => 'The staff member, from the URL. A copy in the body is accepted and ignored.' ],
            'review_kind' => [ 'type' => 'string', 'description' => 'Which way the review runs, e.g. top_down.' ],
            'season_id'   => [ 'type' => [ 'integer', 'string' ], 'description' => 'The season the review belongs to.' ],
            'eval_date'   => [ 'type' => 'string', 'description' => 'When the review took place, as YYYY-MM-DD.' ],
            'notes'       => [ 'type' => 'string', 'description' => 'The write-up.' ],
            'ratings'     => [ 'type' => [ 'array', 'object' ], 'description' => 'The scores per competency. Create only.' ],
        ];
    }

    /**
     * `PUT /staff-evaluations/{id}`. `ratings` is absent: the update path
     * writes the evaluation row only, so a ratings block in the body would
     * be accepted and quietly dropped.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function evaluationUpdateArgs(): array {
        $args = self::evaluationArgs();
        unset( $args['person_id'], $args['ratings'] );
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The evaluation, from the URL. A copy in the body is accepted and ignored.',
        ] ] + $args;
    }

    /**
     * The body a staff certification takes.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function certificationArgs(): array {
        return [
            'person_id'           => [ 'type' => [ 'integer', 'string' ], 'description' => 'The staff member, from the URL. A copy in the body is accepted and ignored.' ],
            'cert_type_lookup_id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'Which certificate this is.' ],
            'issuer'              => [ 'type' => 'string', 'description' => 'Who issued it.' ],
            'issued_on'           => [ 'type' => 'string', 'description' => 'When it was issued, as YYYY-MM-DD.' ],
            'expires_on'          => [ 'type' => 'string', 'description' => 'When it expires, as YYYY-MM-DD. Blank means it does not.' ],
            'document_url'        => [ 'type' => 'string', 'description' => 'A link to the certificate itself.' ],
        ];
    }

    /**
     * `PUT /staff-certifications/{id}`. Every field is optional and an
     * omitted one is left alone (CLAUDE.md §6).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function certificationUpdateArgs(): array {
        $args = self::certificationArgs();
        unset( $args['person_id'] );
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The certification, from the URL. A copy in the body is accepted and ignored.',
        ] ] + $args;
    }

    /**
     * The body `PUT /staff/{person_id}/pdp` takes. The reviewer and the
     * review time are stamped from the request context.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function pdpArgs(): array {
        return [
            'person_id'            => [ 'type' => [ 'integer', 'string' ], 'description' => 'The staff member, from the URL. A copy in the body is accepted and ignored.' ],
            'season_id'            => [ 'type' => [ 'integer', 'string' ], 'description' => 'The season the plan covers.' ],
            'strengths'            => [ 'type' => 'string', 'description' => 'What this person is good at.' ],
            'development_areas'    => [ 'type' => 'string', 'description' => 'What they are working on.' ],
            'actions_next_quarter' => [ 'type' => 'string', 'description' => 'What happens next.' ],
            'narrative'            => [ 'type' => 'string', 'description' => 'The plan in prose.' ],
        ];
    }

    /**
     * The body `POST /staff/{person_id}/mentorships` takes.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function mentorshipArgs(): array {
        return [
            'person_id'        => [ 'type' => [ 'integer', 'string' ], 'description' => 'The mentor, from the URL. A copy in the body is accepted and ignored.' ],
            'mentee_person_id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'Who is being mentored.' ],
            'started_on'       => [ 'type' => 'string', 'description' => 'When the mentorship began, as YYYY-MM-DD.' ],
        ];
    }

    /* ===== permission gates ===== */

    public static function can_view(): bool {
        return current_user_can( 'tt_view_staff_development' );
    }

    public static function can_manage(): bool {
        return current_user_can( 'tt_manage_staff_development' );
    }

    /**
     * Manage-or-self gate: a staff member is always allowed to write
     * to their own goals / certs / PDP. Managers can write to anyone.
     */
    public static function can_manage_target( WP_REST_Request $r ): bool {
        if ( current_user_can( 'tt_manage_staff_development' ) ) return true;
        return self::isSelfTarget( (int) $r['person_id'] );
    }

    /**
     * Evaluation gate: managers can submit any kind; a staff member can
     * only submit a `self` evaluation against themselves.
     */
    public static function can_evaluate_target( WP_REST_Request $r ): bool {
        if ( current_user_can( 'tt_manage_staff_development' ) ) return true;
        if ( ! self::isSelfTarget( (int) $r['person_id'] ) ) return false;
        $kind = (string) $r->get_param( 'review_kind' );
        return $kind === '' || $kind === StaffEvaluationsRepository::KIND_SELF;
    }

    private static function isSelfTarget( int $person_id ): bool {
        if ( $person_id <= 0 ) return false;
        global $wpdb;
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_people WHERE id = %d", $person_id
        ) );
        return $owner > 0 && $owner === get_current_user_id();
    }

    /* ===== Goals ===== */

    public static function list_goals( WP_REST_Request $r ) {
        $repo = new StaffGoalsRepository();
        return RestResponse::success( $repo->listForPerson( (int) $r['person_id'] ) );
    }

    public static function create_goal( WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::goalArgs() );
        if ( $refused !== null ) return $refused;

        $repo = new StaffGoalsRepository();
        $id = $repo->create( [
            'person_id'           => (int) $r['person_id'],
            'season_id'           => $r->get_param( 'season_id' ),
            'title'               => (string) $r->get_param( 'title' ),
            'description'         => (string) $r->get_param( 'description' ),
            'priority'            => (string) $r->get_param( 'priority' ),
            'due_date'            => $r->get_param( 'due_date' ) ?: null,
            'cert_type_lookup_id' => $r->get_param( 'cert_type_lookup_id' ),
        ] );
        return $id > 0 ? RestResponse::success( [ 'id' => $id ], 201 ) : RestResponse::error( 'create_failed', __( 'Could not create the goal.', 'talenttrack' ), 500 );
    }

    public static function update_goal( WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::goalUpdateArgs() );
        if ( $refused !== null ) return $refused;

        $repo = new StaffGoalsRepository();
        $ok = $repo->update( (int) $r['id'], $r->get_params() );
        return $ok ? RestResponse::success( [ 'updated' => true ] ) : RestResponse::error( 'update_failed', __( 'Update failed.', 'talenttrack' ), 400 );
    }

    public static function delete_goal( WP_REST_Request $r ) {
        $repo = new StaffGoalsRepository();
        return RestResponse::success( [ 'archived' => $repo->archive( (int) $r['id'] ) ] );
    }

    /* ===== Evaluations ===== */

    public static function list_evaluations( WP_REST_Request $r ) {
        $repo = new StaffEvaluationsRepository();
        $rows = $repo->listForPerson( (int) $r['person_id'] );
        return RestResponse::success( $rows );
    }

    public static function create_evaluation( WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::evaluationArgs() );
        if ( $refused !== null ) return $refused;

        $repo = new StaffEvaluationsRepository();
        $ratings = (array) ( $r->get_param( 'ratings' ) ?? [] );
        $id = $repo->create( [
            'person_id'        => (int) $r['person_id'],
            'reviewer_user_id' => get_current_user_id(),
            'review_kind'      => (string) $r->get_param( 'review_kind' ),
            'season_id'        => $r->get_param( 'season_id' ),
            'eval_date'        => (string) $r->get_param( 'eval_date' ),
            'notes'            => (string) $r->get_param( 'notes' ),
        ], $ratings );
        return $id > 0 ? RestResponse::success( [ 'id' => $id ], 201 ) : RestResponse::error( 'create_failed', __( 'Could not record the evaluation.', 'talenttrack' ), 500 );
    }

    public static function update_evaluation( WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::evaluationUpdateArgs() );
        if ( $refused !== null ) return $refused;

        $repo = new StaffEvaluationsRepository();
        return RestResponse::success( [ 'updated' => $repo->update( (int) $r['id'], $r->get_params() ) ] );
    }

    public static function delete_evaluation( WP_REST_Request $r ) {
        $repo = new StaffEvaluationsRepository();
        return RestResponse::success( [ 'archived' => $repo->archive( (int) $r['id'], get_current_user_id() ) ] );
    }

    /* ===== Certifications ===== */

    public static function list_certifications( WP_REST_Request $r ) {
        $repo = new StaffCertificationsRepository();
        return RestResponse::success( $repo->listForPerson( (int) $r['person_id'] ) );
    }

    public static function create_certification( WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::certificationArgs() );
        if ( $refused !== null ) return $refused;

        $repo = new StaffCertificationsRepository();
        $id = $repo->create( [
            'person_id'           => (int) $r['person_id'],
            'cert_type_lookup_id' => (int) $r->get_param( 'cert_type_lookup_id' ),
            'issuer'              => (string) $r->get_param( 'issuer' ),
            'issued_on'           => (string) $r->get_param( 'issued_on' ),
            'expires_on'          => $r->get_param( 'expires_on' ) ?: null,
            'document_url'        => (string) $r->get_param( 'document_url' ),
        ] );
        return $id > 0 ? RestResponse::success( [ 'id' => $id ], 201 ) : RestResponse::error( 'create_failed', __( 'Could not record the certification.', 'talenttrack' ), 500 );
    }

    public static function update_certification( WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::certificationUpdateArgs() );
        if ( $refused !== null ) return $refused;

        $repo = new StaffCertificationsRepository();
        return RestResponse::success( [ 'updated' => $repo->update( (int) $r['id'], $r->get_params() ) ] );
    }

    public static function delete_certification( WP_REST_Request $r ) {
        $repo = new StaffCertificationsRepository();
        return RestResponse::success( [ 'archived' => $repo->archive( (int) $r['id'] ) ] );
    }

    /* ===== PDP ===== */

    public static function get_pdp( WP_REST_Request $r ) {
        $repo    = new StaffPdpRepository();
        $season  = $r->get_param( 'season_id' );
        $row     = $repo->findForPersonSeason( (int) $r['person_id'], $season !== null ? (int) $season : null );
        return RestResponse::success( $row ?: null );
    }

    public static function upsert_pdp( WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::pdpArgs() );
        if ( $refused !== null ) return $refused;

        $repo = new StaffPdpRepository();
        $id   = $repo->upsert(
            (int) $r['person_id'],
            $r->get_param( 'season_id' ) !== null ? (int) $r->get_param( 'season_id' ) : null,
            $r->get_params(),
            get_current_user_id()
        );
        return $id > 0 ? RestResponse::success( [ 'id' => $id ] ) : RestResponse::error( 'upsert_failed', __( 'Could not save the PDP.', 'talenttrack' ), 500 );
    }

    /* ===== Expiring certs ===== */

    public static function list_expiring( WP_REST_Request $r ) {
        $repo   = new StaffCertificationsRepository();
        $window = max( 0, min( 365, (int) ( $r->get_param( 'within_days' ) ?? 90 ) ) );
        return RestResponse::success( $repo->listExpiringWithin( $window ) );
    }

    /* ===== Mentorships ===== */

    public static function list_mentorships( WP_REST_Request $r ) {
        $repo = new StaffMentorshipsRepository();
        return RestResponse::success( [
            'as_mentor' => $repo->listForMentor( (int) $r['person_id'] ),
            'as_mentee' => $repo->listForMentee( (int) $r['person_id'] ),
        ] );
    }

    public static function create_mentorship( WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::mentorshipArgs() );
        if ( $refused !== null ) return $refused;

        $repo  = new StaffMentorshipsRepository();
        $mentee = (int) $r->get_param( 'mentee_person_id' );
        $id     = $repo->create( (int) $r['person_id'], $mentee, $r->get_param( 'started_on' ) );
        return $id > 0 ? RestResponse::success( [ 'id' => $id ], 201 ) : RestResponse::error( 'create_failed', __( 'Could not create the mentorship.', 'talenttrack' ), 400 );
    }

    public static function delete_mentorship( WP_REST_Request $r ) {
        $repo = new StaffMentorshipsRepository();
        return RestResponse::success( [ 'deleted' => $repo->delete( (int) $r['id'] ) ] );
    }
}
