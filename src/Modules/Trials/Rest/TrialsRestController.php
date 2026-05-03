<?php
namespace TT\Modules\Trials\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialExtensionsRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Trials\Reminders\TrialReminderScheduler;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;

/**
 * REST surface for #0017 — trial cases.
 *
 * Resource-oriented routes:
 *
 *   GET  /trial-cases                 list, filterable
 *   POST /trial-cases                 create case
 *   GET  /trial-cases/{id}            single case
 *   PUT  /trial-cases/{id}            patch (track / dates / status)
 *   POST /trial-cases/{id}/extend     log extension + bump end_date
 *   POST /trial-cases/{id}/decision   record decision + status transition
 *   GET  /trial-cases/{id}/staff      list assigned staff
 *   POST /trial-cases/{id}/staff      assign staff
 *   POST /trial-cases/{id}/inputs     upsert own input + optional submit
 *   POST /trial-cases/{id}/inputs/release  manager-only release
 *
 *   GET  /trial-tracks                list non-archived tracks (for pickers)
 *
 *   POST /trial-reminders/run         manual cron trigger (manager-only)
 */
class TrialsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/trial-cases', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_cases' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_case' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_case' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_case' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/extend', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'extend_case' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/decision', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'record_decision' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/staff', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_staff' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'assign_staff' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/inputs', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'upsert_input' ],
            'permission_callback' => [ __CLASS__, 'can_submit_input' ],
        ] );

        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/inputs/release', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'release_inputs' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/trial-tracks', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_tracks' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( self::NS, '/trial-reminders/run', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'run_reminders' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );
    }

    public static function can_view(): bool {
        // v3.85.5 — license gate. Trials is a Pro-tier feature; the
        // capability gate alone wasn't enough since free-tier installs
        // could still hold tt_manage_trials.
        if ( ! self::licenseAllowsTrials() ) return false;
        return current_user_can( 'tt_view_trial_synthesis' ) || current_user_can( 'tt_manage_trials' );
    }

    public static function can_manage(): bool {
        if ( ! self::licenseAllowsTrials() ) return false;
        return current_user_can( 'tt_manage_trials' );
    }

    public static function can_submit_input(): bool {
        if ( ! self::licenseAllowsTrials() ) return false;
        return current_user_can( 'tt_submit_trial_input' ) || current_user_can( 'tt_manage_trials' );
    }

    private static function licenseAllowsTrials(): bool {
        if ( ! class_exists( '\\TT\\Modules\\License\\LicenseGate' ) ) return true;
        return \TT\Modules\License\LicenseGate::allows( 'trial_module' );
    }

    public static function list_cases( \WP_REST_Request $r ): \WP_REST_Response {
        $filters = [
            'status'   => sanitize_key( (string) $r->get_param( 'status' ) ),
            'track_id' => absint( (int) $r->get_param( 'track_id' ) ),
            'decision' => sanitize_key( (string) $r->get_param( 'decision' ) ),
            'include_archived' => (bool) $r->get_param( 'include_archived' ),
        ];
        $rows = ( new TrialCasesRepository() )->search( $filters );
        return RestResponse::success( [ 'cases' => array_map( [ __CLASS__, 'format' ], $rows ) ] );
    }

    public static function create_case( \WP_REST_Request $r ): \WP_REST_Response {
        $payload = (array) $r->get_json_params();
        $repo = new TrialCasesRepository();
        $id = $repo->create( [
            'player_id'  => absint( $payload['player_id'] ?? 0 ),
            'track_id'   => absint( $payload['track_id'] ?? 0 ),
            'start_date' => sanitize_text_field( (string) ( $payload['start_date'] ?? gmdate( 'Y-m-d' ) ) ),
            'end_date'   => sanitize_text_field( (string) ( $payload['end_date'] ?? gmdate( 'Y-m-d' ) ) ),
            'notes'      => sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) ),
            'created_by' => get_current_user_id(),
        ] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_request', __( 'Could not create trial case.', 'talenttrack' ), 400 );
        }
        $case = $repo->find( $id );
        // #0053 — journey subscriber emits trial_started against this hook.
        do_action( 'tt_trial_started', $id, (int) ( $case->player_id ?? 0 ) );
        return RestResponse::success( [ 'case' => self::format( $case ) ] );
    }

    public static function get_case( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $case = ( new TrialCasesRepository() )->find( $id );
        if ( ! $case ) return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        if ( ! TrialCaseAccessPolicy::canViewSynthesis( get_current_user_id(), $id ) ) {
            return RestResponse::error( 'forbidden', __( 'No access to this case.', 'talenttrack' ), 403 );
        }
        return RestResponse::success( [ 'case' => self::format( $case ) ] );
    }

    public static function update_case( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $payload = (array) $r->get_json_params();
        $patch = array_intersect_key( $payload, array_flip( [ 'track_id','start_date','end_date','status','notes' ] ) );
        $ok = ( new TrialCasesRepository() )->update( $id, $patch );
        return $ok ? RestResponse::success( [ 'updated' => true ] )
                   : RestResponse::error( 'bad_request', __( 'No fields to update.', 'talenttrack' ), 400 );
    }

    public static function extend_case( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $payload = (array) $r->get_json_params();
        $new_end = sanitize_text_field( (string) ( $payload['new_end_date'] ?? '' ) );
        $just    = sanitize_textarea_field( (string) ( $payload['justification'] ?? '' ) );
        if ( $new_end === '' || trim( $just ) === '' ) {
            return RestResponse::error( 'bad_request', __( 'New end date and justification are required.', 'talenttrack' ), 400 );
        }
        $repo = new TrialCasesRepository();
        $case = $repo->find( $id );
        if ( ! $case ) return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        if ( $new_end <= $case->end_date ) {
            return RestResponse::error( 'bad_request', __( 'New end date must be after the current end date.', 'talenttrack' ), 400 );
        }
        ( new TrialExtensionsRepository() )->record( $id, (string) $case->end_date, $new_end, $just, get_current_user_id() );
        $repo->update( $id, [
            'end_date'        => $new_end,
            'extension_count' => (int) $case->extension_count + 1,
            'status'          => TrialCasesRepository::STATUS_EXTENDED,
        ] );
        return RestResponse::success( [ 'extended' => true ] );
    }

    public static function record_decision( \WP_REST_Request $r ): \WP_REST_Response {
        $id      = absint( $r['id'] );
        $payload = (array) $r->get_json_params();
        $decision = sanitize_key( (string) ( $payload['decision'] ?? '' ) );
        $notes    = sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) );
        if ( strlen( $notes ) < 30 ) {
            return RestResponse::error( 'bad_request', __( 'Justification must be at least 30 characters.', 'talenttrack' ), 400 );
        }
        $repo = new TrialCasesRepository();
        $ok = $repo->recordDecision(
            $id, $decision, get_current_user_id(), $notes,
            isset( $payload['strengths_summary'] ) ? sanitize_textarea_field( (string) $payload['strengths_summary'] ) : null,
            isset( $payload['growth_areas'] )      ? sanitize_textarea_field( (string) $payload['growth_areas'] )      : null
        );
        if ( $ok ) {
            $case = $repo->find( $id );
            // #0053 — journey subscriber emits trial_ended (+ signed/released
            // depending on decision) against this hook.
            do_action(
                'tt_trial_decision_recorded',
                $id,
                (int) ( $case->player_id ?? 0 ),
                $decision,
                (string) ( $case->decision_made_at ?? '' )
            );
        }
        return $ok ? RestResponse::success( [ 'recorded' => true ] )
                   : RestResponse::error( 'bad_request', __( 'Could not record decision.', 'talenttrack' ), 400 );
    }

    public static function list_staff( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $rows = ( new TrialCaseStaffRepository() )->listForCase( $id );
        return RestResponse::success( [ 'staff' => $rows ] );
    }

    public static function assign_staff( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $payload = (array) $r->get_json_params();
        $u = absint( $payload['user_id'] ?? 0 );
        if ( $u <= 0 ) return RestResponse::error( 'bad_request', __( 'Invalid user id.', 'talenttrack' ), 400 );
        $label = isset( $payload['role_label'] ) ? sanitize_text_field( (string) $payload['role_label'] ) : null;
        ( new TrialCaseStaffRepository() )->assign( $id, $u, $label ?: null );
        return RestResponse::success( [ 'assigned' => true ] );
    }

    public static function upsert_input( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $payload = (array) $r->get_json_params();
        if ( ! TrialCaseAccessPolicy::canSubmitInput( get_current_user_id(), $id ) ) {
            return RestResponse::error( 'forbidden', __( 'Not assigned to this case.', 'talenttrack' ), 403 );
        }
        $inputs = new TrialStaffInputsRepository();
        $inputs->upsertDraft( $id, get_current_user_id(), [
            'overall_rating'  => isset( $payload['overall_rating'] ) ? (float) $payload['overall_rating'] : null,
            'free_text_notes' => sanitize_textarea_field( (string) ( $payload['free_text_notes'] ?? '' ) ),
        ] );
        if ( ! empty( $payload['submit'] ) ) {
            $inputs->submit( $id, get_current_user_id() );
        }
        return RestResponse::success( [ 'saved' => true ] );
    }

    public static function release_inputs( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        ( new TrialStaffInputsRepository() )->release( $id, get_current_user_id() );
        ( new TrialCasesRepository() )->releaseInputs( $id, get_current_user_id() );
        return RestResponse::success( [ 'released' => true ] );
    }

    public static function list_tracks(): \WP_REST_Response {
        $tracks = ( new TrialTracksRepository() )->listAll( false );
        return RestResponse::success( [ 'tracks' => $tracks ] );
    }

    public static function run_reminders(): \WP_REST_Response {
        $sent = TrialReminderScheduler::dispatch();
        return RestResponse::success( [ 'sent' => $sent ] );
    }

    /**
     * @return array<string,mixed>
     */
    private static function format( ?object $row ): array {
        if ( ! $row ) return [];
        return [
            'id'              => (int) $row->id,
            'player_id'       => (int) $row->player_id,
            'track_id'        => (int) $row->track_id,
            'start_date'      => (string) $row->start_date,
            'end_date'        => (string) $row->end_date,
            'status'          => (string) $row->status,
            'extension_count' => (int) $row->extension_count,
            'decision'        => $row->decision ? (string) $row->decision : null,
            'created_at'      => (string) $row->created_at,
            'archived_at'     => $row->archived_at ? (string) $row->archived_at : null,
        ];
    }
}
