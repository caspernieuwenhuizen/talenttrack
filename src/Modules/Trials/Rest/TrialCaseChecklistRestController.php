<?php
namespace TT\Modules\Trials\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\People\StaffDirectory;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;
use TT\Modules\Trials\Services\TrialCaseChecklistService;

/**
 * The follow-up checklist on an admitted trial case, as an API (#4008).
 *
 *   GET   /trial-cases/{id}/checklist              the items, auto-ticks brought up to date
 *   PATCH /trial-cases/{id}/checklist/{item_key}   tick / untick, name who does it
 *
 * The whole panel reads it: the read gate is the case's own
 * (`TrialCaseAccessPolicy::canOpenCase()`). Changing an item is a trial-case
 * edit, gated like the other edits (`tt_manage_trials`). Every change is
 * audit-logged.
 *
 * Its own controller rather than more routes in `TrialsRestController`, so
 * the checklist can change without touching the file every trial route
 * lives in.
 */
final class TrialCaseChecklistRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/checklist', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_items' ],
                'permission_callback' => [ TrialsRestController::class, 'can_view' ],
            ],
        ] );

        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/checklist/(?P<item_key>[a-z_]+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_item' ],
                'permission_callback' => [ TrialsRestController::class, 'can_manage' ],
                'args'                => self::updateArgs(),
            ],
        ] );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function updateArgs(): array {
        return [
            'done' => [
                'type'        => 'boolean',
                'description' => 'Tick (true) or untick (false) the item.',
            ],
            'assignee_person_id' => [
                'type'        => [ 'integer', 'null' ],
                'description' => 'The staff member who does this item. Find them with GET staff?search=. Null clears it.',
            ],
            'assignee_user_id' => [
                'type'        => [ 'integer', 'null' ],
                'description' => 'The same, as the account id on a row of GET staff?search=. Null clears it.',
            ],
        ];
    }

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $case_id = absint( $r['id'] );
        $refusal = self::caseRefusal( $case_id, false );
        if ( $refusal !== null ) return $refusal;

        return RestResponse::success( [
            'case_id' => $case_id,
            'items'   => ( new TrialCaseChecklistService() )->itemsFor( $case_id ),
        ] );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $refused = BaseController::checkBody( $r, self::updateArgs() );
        if ( $refused !== null ) return $refused;

        $case_id = absint( $r['id'] );
        $key     = sanitize_key( (string) $r['item_key'] );

        $refusal = self::caseRefusal( $case_id, true );
        if ( $refusal !== null ) return $refusal;

        $service = new TrialCaseChecklistService();
        if ( ! $service->hasItem( $case_id, $key ) ) {
            return RestResponse::error(
                'unknown_item',
                __( 'This case has no such follow-up item.', 'talenttrack' ),
                404,
                [ 'allowed' => array_map( 'strval', array_column( $service->itemsFor( $case_id ), 'item_key' ) ) ]
            );
        }

        $body    = self::body( $r );
        $changes = [];
        $actor   = get_current_user_id();

        if ( array_key_exists( 'assignee_person_id', $body ) || array_key_exists( 'assignee_user_id', $body ) ) {
            $assignee = self::resolveAssignee( $body );
            if ( $assignee instanceof \WP_REST_Response ) return $assignee;

            $result = $service->setAssignee( $case_id, $key, $assignee );
            if ( $result === 'not_staff' ) {
                return RestResponse::error( 'not_staff', __( 'That account is not a staff member of this academy.', 'talenttrack' ), 400 );
            }
            $changes['assignee_user_id'] = $assignee;
        }

        if ( array_key_exists( 'done', $body ) ) {
            $done = rest_sanitize_boolean( $body['done'] );
            $service->setDone( $case_id, $key, $done, $actor );
            $changes['done'] = $done;
        }

        if ( $changes === [] ) {
            return RestResponse::error( 'missing_fields', __( 'Send done, an assignee, or both.', 'talenttrack' ), 400 );
        }

        ( new AuditService() )->record( 'trial_case.checklist_updated', 'trial_case', $case_id, [
            'item_key' => $key,
            'changes'  => $changes,
        ] );

        $item = null;
        foreach ( $service->itemsFor( $case_id ) as $row ) {
            if ( $row['item_key'] === $key ) { $item = $row; break; }
        }
        return RestResponse::success( [ 'case_id' => $case_id, 'item' => $item ] );
    }

    /**
     * 404 for a case that is not there, 403 for one the caller may not read
     * or, for a write, may not edit.
     */
    private static function caseRefusal( int $case_id, bool $write ): ?\WP_REST_Response {
        if ( $case_id <= 0 || ! ( new TrialCasesRepository() )->find( $case_id ) ) {
            return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        }
        $uid     = get_current_user_id();
        $allowed = $write
            ? TrialCaseAccessPolicy::canManageCase( $uid, $case_id )
            : TrialCaseAccessPolicy::canOpenCase( $uid, $case_id );
        if ( ! $allowed ) {
            return RestResponse::error( 'forbidden', __( 'No access to this case.', 'talenttrack' ), 403 );
        }
        return null;
    }

    /**
     * The account an assignee field names, null to clear, or the refusal.
     *
     * @param array<array-key,mixed> $body
     * @return int|null|\WP_REST_Response
     */
    private static function resolveAssignee( array $body ) {
        if ( array_key_exists( 'assignee_person_id', $body ) ) {
            if ( $body['assignee_person_id'] === null || $body['assignee_person_id'] === '' ) return null;
            $account = ( new StaffDirectory() )->accountForPerson( absint( $body['assignee_person_id'] ) );
            if ( $account['status'] !== 'ok' ) {
                return RestResponse::error(
                    $account['status'],
                    $account['status'] === 'no_account'
                        ? __( 'That staff member has no login, so they cannot be named on an item.', 'talenttrack' )
                        : __( 'That person is not a staff member of this academy.', 'talenttrack' ),
                    400
                );
            }
            return $account['user_id'];
        }

        if ( $body['assignee_user_id'] === null || $body['assignee_user_id'] === '' ) return null;
        return absint( $body['assignee_user_id'] );
    }

    /** @return array<array-key,mixed> */
    private static function body( \WP_REST_Request $r ): array {
        // Core returns null for a body that is not JSON; the stubs say
        // array, so a falsy test rather than is_array() (see BaseController).
        $body = $r->get_json_params();
        if ( ! $body ) $body = $r->get_body_params();
        return $body;
    }
}
