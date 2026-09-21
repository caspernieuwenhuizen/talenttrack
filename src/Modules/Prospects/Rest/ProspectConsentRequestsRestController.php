<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;

/**
 * ProspectConsentRequestsRestController (#3812) — the dated log of asking
 * a child's own club to pass a consent request on to the family.
 *
 * Routes:
 *
 *   GET   /prospects/{id}/consent-requests            the trail, newest first
 *   POST  /prospects/{id}/consent-requests            record a request
 *   PATCH /prospects/{id}/consent-requests/{entry}    record what came back
 *
 * Every write route declares its args and refuses a body it has not
 * understood (#3868's lesson: a route that answers `200 changed:false` for
 * an unknown field tells a scout their consent request was saved when it
 * was dropped).
 *
 * The log holds **no family-identifying field**, and neither do these
 * routes: `asked_of` is the club or coordinator, and nothing here takes a
 * parent's name, email or phone. Those live on `tt_prospects`, behind the
 * consent rule that has always guarded them.
 */
class ProspectConsentRequestsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/prospects/(?P<id>\d+)/consent-requests', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_requests' ],
                'permission_callback' => [ self::class, 'can_view' ],
                'args'                => [],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'create_request' ],
                'permission_callback' => [ self::class, 'can_edit' ],
                'args'                => self::createArgs(),
            ],
        ] );

        register_rest_route( self::NS, '/prospects/(?P<id>\d+)/consent-requests/(?P<entry_id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [ self::class, 'update_request' ],
            'permission_callback' => [ self::class, 'can_edit' ],
            'args'                => self::updateArgs(),
        ] );
    }

    /** @return array<string,array<string,mixed>> */
    private static function createArgs(): array {
        return [
            'asked_at' => [
                'type'        => 'string',
                'required'    => true,
                'description' => 'The date the academy asked, as YYYY-MM-DD.',
            ],
            'asked_of' => [
                'type'        => 'string',
                'required'    => true,
                'description' => 'The club or coordinator that was asked. Never the family — this field records the route, not the people.',
            ],
            'outcome' => [
                'type'        => 'string',
                'enum'        => ConsentOutcome::all(),
                'description' => 'What came back. Defaults to "awaiting", which is what holds the retention clock.',
            ],
            'notes' => [
                'type'        => 'string',
                'description' => 'Free text about the request. Not a place for family contact details.',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function updateArgs(): array {
        return [
            'outcome' => [
                'type'        => 'string',
                'required'    => true,
                'enum'        => ConsentOutcome::all(),
                'description' => 'What came back.',
            ],
            'notes' => [
                'type'        => 'string',
                'description' => 'Free text about the request.',
            ],
        ];
    }

    public static function can_view(): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_view_prospects' );
    }

    public static function can_edit(): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_prospects' );
    }

    /** GET /prospects/{id}/consent-requests */
    public static function list_requests( \WP_REST_Request $r ): \WP_REST_Response {
        $prospect_id = (int) $r['id'];
        if ( ( new ProspectsRepository() )->find( $prospect_id ) === null ) {
            return RestResponse::notFound( 'prospect_not_found', __( 'Prospect not found.', 'talenttrack' ) );
        }
        if ( ! ProspectConsentRequestsRepository::tableExists() ) {
            return RestResponse::success( [ 'prospect_id' => $prospect_id, 'requests' => [] ] );
        }

        $rows = ( new ProspectConsentRequestsRepository() )->forProspect( $prospect_id );
        $out  = [];
        foreach ( $rows as $row ) {
            $out[] = self::serialize( $row );
        }
        return RestResponse::success( [ 'prospect_id' => $prospect_id, 'requests' => $out ] );
    }

    /** POST /prospects/{id}/consent-requests */
    public static function create_request( \WP_REST_Request $r ): \WP_REST_Response {
        $bad = BaseController::checkBody( $r, self::createArgs() );
        if ( $bad ) return $bad;

        $prospect_id = (int) $r['id'];
        if ( ( new ProspectsRepository() )->find( $prospect_id ) === null ) {
            return RestResponse::notFound( 'prospect_not_found', __( 'Prospect not found.', 'talenttrack' ) );
        }

        $asked_at = trim( (string) $r['asked_at'] );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $asked_at ) ) {
            return RestResponse::error(
                'invalid_asked_at',
                __( 'The date asked must be YYYY-MM-DD.', 'talenttrack' ),
                400
            );
        }

        $asked_of = trim( sanitize_text_field( (string) $r['asked_of'] ) );
        if ( $asked_of === '' ) {
            return RestResponse::error(
                'missing_asked_of',
                __( 'Name the club or coordinator that was asked.', 'talenttrack' ),
                400
            );
        }

        $outcome = (string) ( $r['outcome'] ?? ConsentOutcome::AWAITING );
        $notes   = sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) );

        $id = ( new ProspectConsentRequestsRepository() )
            ->create( $prospect_id, $asked_at, $asked_of, $outcome, $notes );
        if ( $id <= 0 ) {
            return RestResponse::error(
                'consent_request_failed',
                __( 'Could not record the consent request.', 'talenttrack' ),
                500
            );
        }

        ( new AuditService() )->record( 'prospect.consent_requested', 'prospect', $prospect_id, [
            'entry_id' => $id,
            'asked_at' => $asked_at,
            'outcome'  => $outcome,
        ] );

        $row = ( new ProspectConsentRequestsRepository() )->findById( $id );
        return RestResponse::success( $row !== null ? self::serialize( $row ) : [ 'id' => $id ], 201 );
    }

    /** PATCH /prospects/{id}/consent-requests/{entry_id} */
    public static function update_request( \WP_REST_Request $r ): \WP_REST_Response {
        $bad = BaseController::checkBody( $r, self::updateArgs() );
        if ( $bad ) return $bad;

        $prospect_id = (int) $r['id'];
        $entry_id    = (int) $r['entry_id'];
        $repo        = new ProspectConsentRequestsRepository();

        $existing = $repo->findById( $entry_id );
        if ( $existing === null || (int) ( $existing['prospect_id'] ?? 0 ) !== $prospect_id ) {
            return RestResponse::notFound( 'consent_request_not_found', __( 'Consent request not found.', 'talenttrack' ) );
        }

        $outcome = (string) $r['outcome'];
        if ( ! ConsentOutcome::isValid( $outcome ) ) {
            return RestResponse::error(
                'invalid_outcome',
                __( 'Pick one of the listed outcomes.', 'talenttrack' ),
                400
            );
        }

        $notes = $r->has_param( 'notes' ) ? sanitize_textarea_field( (string) $r['notes'] ) : null;
        $ok    = $repo->setOutcome( $entry_id, $outcome, $notes );
        if ( ! $ok ) {
            return RestResponse::error(
                'consent_request_update_failed',
                __( 'Could not update the consent request.', 'talenttrack' ),
                500
            );
        }

        ( new AuditService() )->record( 'prospect.consent_outcome_recorded', 'prospect', $prospect_id, [
            'entry_id' => $entry_id,
            'from'     => (string) ( $existing['outcome'] ?? '' ),
            'to'       => $outcome,
        ] );

        $row = $repo->findById( $entry_id );
        return RestResponse::success( $row !== null ? self::serialize( $row ) : [ 'id' => $entry_id ] );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function serialize( array $row ): array {
        $outcome = (string) ( $row['outcome'] ?? '' );
        return [
            'id'            => (int) ( $row['id'] ?? 0 ),
            'uuid'          => (string) ( $row['uuid'] ?? '' ),
            'prospect_id'   => (int) ( $row['prospect_id'] ?? 0 ),
            'asked_at'      => (string) ( $row['asked_at'] ?? '' ),
            'asked_of'      => (string) ( $row['asked_of'] ?? '' ),
            'outcome'       => $outcome,
            'outcome_label' => ConsentOutcome::label( $outcome ),
            'notes'         => (string) ( $row['notes'] ?? '' ),
            'created_by'    => (int) ( $row['created_by'] ?? 0 ),
            'created_at'    => (string) ( $row['created_at'] ?? '' ),
        ];
    }
}
