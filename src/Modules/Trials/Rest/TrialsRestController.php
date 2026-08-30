<?php
namespace TT\Modules\Trials\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Letters\TrialLetterService;
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
 *   GET  /trial-cases/{id}/letters    letters generated for the case
 *   POST /trial-cases/{id}/letters    generate one (supersedes the active)
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

        // #1784 — referential-integrity permanent delete (staff, inputs and
        // extensions cascade; workflow-task / prospect links cleared).
        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_case_permanently' ],
                // #2024 security #6 — re-gate onto tt_manage_recycle_bin: no
                // purge path weaker than the bin's own purge.
                'permission_callback' => function () {
                    return current_user_can( 'tt_manage_recycle_bin' );
                },
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

        // #3223 — the letters had no REST surface at all. `TrialLetterService`
        // was reachable only from two view files, so §4's smell test failed
        // outright for this half of the module: delete `src/Shared/Frontend/`
        // and the trial letter ceases to exist.
        //
        // Both verbs are manager-gated, matching the Letter tab, which is
        // manager-only in `FrontendTrialCaseView::tabSet()`. A letter to a
        // family about whether the academy wants their child is not something
        // an assigned coach generates.
        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/letters', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_letters' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'generate_letter' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/trial-tracks', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_tracks' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        // #1784 — permanently delete a custom trial track. Blocks (fail-
        // closed) while any trial case still uses it; seeded tracks are
        // never deletable.
        register_rest_route( self::NS, '/trial-tracks/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_track_permanently' ],
                // #2024 security #6 — re-gate onto tt_manage_recycle_bin: no
                // purge path weaker than the bin's own purge.
                'permission_callback' => function () {
                    return current_user_can( 'tt_manage_recycle_bin' );
                },
            ],
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
        // #3130 — `tt_trial_started` moved into `TrialCasesRepository::create()`.
        // Four callers reached that method and only three fired the hook, so
        // the journey entry depended on which screen opened the trial.
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

    /**
     * #1784 — permanently delete a trial case (irreversible). Cascades its
     * staff assignments, staff inputs and extension audit trail; clears any
     * workflow-task / prospect link. Fail-closed via the shared cascade
     * framework; gated by tt_edit_settings.
     */
    public static function delete_case_permanently( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid trial case id.', 'talenttrack' ), 400 );
        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'trial_case', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    /**
     * #1784 — permanently delete a custom trial track. Built-in (seeded)
     * tracks are refused; the delete is fail-closed and blocks while any
     * trial case still references the track. Gated by tt_edit_settings.
     */
    public static function delete_track_permanently( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid trial track id.', 'talenttrack' ), 400 );
        $track = ( new TrialTracksRepository() )->find( $id );
        if ( ! $track ) return RestResponse::error( 'not_found', __( 'Trial track not found.', 'talenttrack' ), 404 );
        if ( (int) ( $track->is_seeded ?? 0 ) === 1 ) {
            return RestResponse::error( 'seeded_track', __( 'Built-in trial tracks cannot be deleted.', 'talenttrack' ), 403 );
        }
        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'trial_track', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) return RestResponse::error( 'not_found', __( 'Trial track not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
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
        // #3138 — `recordDecision()` accepts all six decisions now, because
        // the workflow forms write the other three and had gone around it.
        // This endpoint keeps its own narrower surface: the three classic
        // outcomes are what an API caller decides. The rolling-membership
        // three belong to the workflow chain that spawns the next task, and
        // recording one over HTTP would move the case without moving the
        // chain.
        if ( ! in_array( $decision, [
            TrialCaseDecision::ADMIT,
            TrialCaseDecision::DENY_FINAL,
            TrialCaseDecision::DENY_ENCOURAGEMENT,
        ], true ) ) {
            return RestResponse::error( 'bad_request', __( 'Could not record decision.', 'talenttrack' ), 400 );
        }

        $repo = new TrialCasesRepository();
        // The hook fires from `recordDecision()` now — the journey
        // subscriber and the player-status subscriber both hang off it, and
        // firing it here as well would double every entry.
        $ok = $repo->recordDecision(
            $id, $decision, get_current_user_id(), $notes,
            isset( $payload['strengths_summary'] ) ? sanitize_textarea_field( (string) $payload['strengths_summary'] ) : null,
            isset( $payload['growth_areas'] )      ? sanitize_textarea_field( (string) $payload['growth_areas'] )      : null
        );
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

    /**
     * #3223 — the letters generated for a case, newest first.
     *
     * Body text is deliberately not returned. A letter is rendered HTML
     * about a child, and a list endpoint is for answering "what has been
     * sent" — the document itself is fetched through the reports surface
     * that already owns delivery and revocation.
     */
    public static function list_letters( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( ! ( new TrialCasesRepository() )->find( $id ) ) {
            return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        }

        $out = [];
        foreach ( ( new TrialLetterService() )->listForCase( $id ) as $row ) {
            $out[] = [
                'id'           => (int) ( $row->id ?? 0 ),
                'audience'     => (string) ( $row->audience ?? '' ),
                'created_at'   => (string) ( $row->created_at ?? '' ),
                'revoked_at'   => (string) ( $row->revoked_at ?? '' ),
                'generated_by' => (int) ( $row->generated_by ?? 0 ),
                'is_active'    => empty( $row->revoked_at ),
            ];
        }

        return RestResponse::success( [ 'case_id' => $id, 'letters' => $out ] );
    }

    /**
     * Generate a letter for a case.
     *
     * Generating supersedes: `TrialLetterService::generate()` revokes the
     * prior active letter, so a case has one letter that counts and a
     * history of what it replaced. That is deliberate — two live letters
     * saying different things to the same family is the failure mode.
     */
    public static function generate_letter( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $case = ( new TrialCasesRepository() )->find( $id );
        if ( ! $case ) {
            return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        }

        $payload  = (array) $r->get_json_params();
        $audience = isset( $payload['audience'] ) ? sanitize_key( (string) $payload['audience'] ) : '';

        if ( ! AudienceType::isTrialLetter( $audience ) ) {
            return RestResponse::error(
                'bad_audience',
                __( 'A valid trial-letter audience is required.', 'talenttrack' ),
                400,
                [ 'allowed' => AudienceType::trialLetters() ]
            );
        }

        $strengths = isset( $payload['strengths_summary'] )
            ? sanitize_textarea_field( (string) $payload['strengths_summary'] )
            : null;
        $growth = isset( $payload['growth_areas'] )
            ? sanitize_textarea_field( (string) $payload['growth_areas'] )
            : null;

        $letter_id = ( new TrialLetterService() )->generate(
            $case,
            $audience,
            get_current_user_id(),
            $strengths,
            $growth
        );

        if ( $letter_id <= 0 ) {
            return RestResponse::error( 'generate_failed', __( 'The letter could not be generated.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [
            'id'       => $letter_id,
            'case_id'  => $id,
            'audience' => $audience,
        ] );
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

    /**
     * "Send reminders now". A user is waiting on this, so it reports per
     * recipient rather than returning a bare count (#2602 / #2604) — a
     * reminder held for quiet hours or refused by an opt-out is not a
     * failure, but it is not a send either, and the caller has to be able
     * to tell the operator which.
     */
    public static function run_reminders(): \WP_REST_Response {
        $results = TrialReminderScheduler::run();

        // No results means no case was due a reminder — a different thing
        // from a send that reached nobody, which is what the shared
        // summariser says for an empty list.
        $outcome = $results === []
            ? [ __( 'No reminders were due.', 'talenttrack' ) ]
            : \TT\Modules\Comms\Domain\CommsOutcomeSummary::lines( $results );

        return RestResponse::success( [
            'sent'    => \TT\Modules\Comms\Domain\CommsOutcomeSummary::sentCount( $results ),
            'outcome' => $outcome,
        ] );
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
            // #2023 — archived_at + trashed_at via the shared lifecycle helper.
        ] + \TT\Infrastructure\Archive\LifecycleFields::forRow( $row );
    }
}
