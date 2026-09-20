<?php
namespace TT\Modules\Trials\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Domain\Vocabularies\Lookups\TrialCaseStatus;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialExtensionsRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Trials\Reminders\TrialReminderScheduler;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;
use TT\Modules\Trials\Services\TrialCaseOpener;

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
 *   GET  /trial-cases/{id}/inputs     staff inputs visible to the caller
 *   POST /trial-cases/{id}/inputs     upsert own input + optional submit
 *   POST /trial-cases/{id}/inputs/release  manager-only release
 *   GET  /trial-cases/{id}/letters    letters generated for the case
 *   POST /trial-cases/{id}/letters    generate one (supersedes the active)
 *   PUT    /trial-cases/{id}/letters/{letter_id}/delivery  record delivery
 *   DELETE /trial-cases/{id}/letters/{letter_id}/delivery  clear it again
 *
 *   GET  /trial-tracks                list non-archived tracks (for pickers)
 *
 *   POST /trial-reminders/run         manual cron trigger (manager-only)
 */
class TrialsRestController {

    private const NS = 'talenttrack/v1';

    /**
     * Shortest motivation the decision route accepts, in characters.
     *
     * Admitting or releasing a child is the hand-off point of a trial and
     * the one entry a family may ask to see a season later, so a one-word
     * "Yes" is refused on purpose.
     */
    private const DECISION_NOTES_MIN = 30;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/trial-cases', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_cases' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args'                => [
                    'player_id'        => [ 'type' => 'integer', 'description' => 'Only this player\'s trial cases.' ],
                    'status'           => [ 'type' => 'string', 'description' => 'open, extended, decided or archived.' ],
                    'track_id'         => [ 'type' => 'integer', 'description' => 'Only cases on this trial track.' ],
                    'decision'         => [ 'type' => 'string', 'description' => 'Only cases with this decision.' ],
                    'include_archived' => [ 'type' => 'boolean', 'description' => 'Include archived cases.' ],
                ],
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
            // #3654 — the motivation is read from `notes`, and a caller who
            // guessed `justification` was told their text was too short.
            'args'                => self::decisionArgs(),
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

        // #3673 — the panel's input was writable over REST and readable
        // only by rendering the Staff inputs tab, so the head of
        // development could record a decision through the API without
        // being able to read what the panel had said about the child. The
        // GET reads through the same repository method the tab does, so
        // the visibility rule is stated once and both surfaces answer it
        // the same way.
        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/inputs', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_inputs' ],
                'permission_callback' => [ __CLASS__, 'can_read_inputs' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'upsert_input' ],
                'permission_callback' => [ __CLASS__, 'can_submit_input' ],
                // #3606 / #3612 — what the route takes, for route discovery.
                'args'                => self::inputArgs(),
            ],
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

        // #3683 — delivery is a human step, and until now nothing wrote it
        // down: a letter read "Active" whether it had gone to the family
        // that afternoon or was still sitting unprinted three weeks later.
        // Same manager gate as the letter itself.
        register_rest_route( self::NS, '/trial-cases/(?P<id>\d+)/letters/(?P<letter_id>\d+)/delivery', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'record_letter_delivery' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
                'args'                => self::deliveryArgs(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'clear_letter_delivery' ],
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

    /**
     * #3566 — these read through `userCanOrMatrix()` rather than bare
     * `current_user_can()`, as `ActivitiesRestController::can_view()`
     * already does.
     *
     * The bare call answers from WP role capabilities alone, so a persona
     * whose access is granted in the matrix and not as a raw role cap is
     * refused before the matrix is ever consulted — which is exactly what
     * happened to the scout. `userCanOrMatrix()` asks both, so the answer
     * no longer depends on whether the `user_has_cap` bridge is active on
     * this install.
     */
    public static function can_view(): bool {
        // v3.85.5 — license gate. Trials is a Pro-tier feature; the
        // capability gate alone wasn't enough since free-tier installs
        // could still hold tt_manage_trials.
        if ( ! self::licenseAllowsTrials() ) return false;
        $uid = get_current_user_id();
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_view_trial_synthesis' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_trials' )
            // A scout holds neither cap, but does hold `trial_cases [r, player]`.
            // The per-case narrowing below decides which cases they reach.
            || MatrixGate::canAnyScope( $uid, 'trial_cases', MatrixGate::READ );
    }

    public static function can_manage(): bool {
        if ( ! self::licenseAllowsTrials() ) return false;
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_manage_trials' );
    }

    /**
     * #3566 — does this caller read trials club-wide, or only for the
     * players they are linked to?
     *
     * True for anyone holding the trials capabilities proper. False for a
     * caller who got through `can_view()` on the scout's player-scoped
     * matrix row alone — they are entitled to *a* case, never *the* list.
     */
    private static function readsAllCases( int $user_id ): bool {
        return AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_trials' )
            || AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_trial_synthesis' );
    }

    /**
     * #3566 — restrict a case query to the caller's linked players when
     * they are not a club-wide reader.
     *
     * Returns the filters to use, or **null** when the caller has no
     * links at all — the caller then returns an empty list rather than
     * running an unfiltered query. Null is deliberately distinct from an
     * empty `player_ids`, which a repository could read as "no filter".
     *
     * @param  array<string,mixed> $filters
     * @return array<string,mixed>|null
     */
    private static function narrowToLinkedPlayers( array $filters ): ?array {
        $uid = get_current_user_id();
        if ( self::readsAllCases( $uid ) ) return $filters;

        $linked = \TT\Infrastructure\Players\ScoutPlayerLinks::playerIds( $uid );
        if ( $linked === [] ) return null;

        // An explicit player_id filter narrows further; it never widens.
        if ( ! empty( $filters['player_ids'] ) && is_array( $filters['player_ids'] ) ) {
            $linked = array_values( array_intersect( $filters['player_ids'], $linked ) );
            if ( $linked === [] ) return null;
        }

        $filters['player_ids'] = $linked;
        return $filters;
    }

    public static function can_submit_input(): bool {
        if ( ! self::licenseAllowsTrials() ) return false;
        $uid = get_current_user_id();
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_submit_trial_input' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_trials' )
            // The scout's seed row is `trial_inputs [c, player]` — `c` is
            // `change` in the seed's letter map, not `create_delete`.
            || MatrixGate::canAnyScope( $uid, 'trial_inputs', MatrixGate::CHANGE );
    }

    /**
     * Who may ask to read a case's staff inputs (#3673).
     *
     * The union of the two reasons to be here: reading the synthesis, and
     * having written an input of your own. It is the coarse gate only —
     * `list_inputs()` still requires assignment for a non-manager, and the
     * repository decides which rows come back.
     */
    public static function can_read_inputs(): bool {
        return self::can_view() || self::can_submit_input();
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
        // #3602 — one player's trials. The repository took a player list all
        // along; the route never passed it, so `player_id` returned everyone.
        $player_id = absint( (int) $r->get_param( 'player_id' ) );
        if ( $player_id > 0 ) $filters['player_ids'] = [ $player_id ];

        // #3566 — narrow BEFORE the query, not after. A caller who reached
        // this route through the scout's player-scoped matrix row sees only
        // the cases they sit on; without this, resolving their scope would
        // hand them every case in the club.
        $narrowed = self::narrowToLinkedPlayers( $filters );
        if ( $narrowed === null ) {
            return RestResponse::success( [ 'cases' => [] ] );
        }
        $filters = $narrowed;

        $rows  = ( new TrialCasesRepository() )->search( $filters );
        $names = self::playerNames( array_values( array_map( static fn( $row ): int => (int) ( ( (array) $row )['player_id'] ?? 0 ), $rows ) ) );
        return RestResponse::success( [
            'cases' => array_map( static fn( $row ): array => self::format( $row, $names ), $rows ),
        ] );
    }

    /**
     * Display names for a set of players, in one club-scoped query.
     *
     * @param list<int> $player_ids
     * @return array<int,string>
     */
    private static function playerNames( array $player_ids ): array {
        $ids = array_values( array_unique( array_filter( $player_ids, static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        $p = $wpdb->prefix;
        // Every id is an int by construction, so the list is safe to inline.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT id, first_name, last_name FROM {$p}tt_players
              WHERE id IN (" . implode( ',', $ids ) . ')
                AND club_id = ' . (int) \TT\Infrastructure\Tenancy\CurrentClub::id(),
            ARRAY_A
        );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) ( $row['id'] ?? 0 ) ] = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
        }
        return $out;
    }

    public static function create_case( \WP_REST_Request $r ): \WP_REST_Response {
        $payload = (array) $r->get_json_params();

        // #3577 — through `TrialCaseOpener`, the path the manage form and the
        // wizard use. This route called the repository directly, so it
        // skipped the cross-club player check and never set the player's
        // status to Trial; it also now meets the one-open-case rule with a
        // 409 naming the case that is open.
        $result = ( new TrialCaseOpener() )->open( [
            'player_id'  => absint( $payload['player_id'] ?? 0 ),
            'track_id'   => absint( $payload['track_id'] ?? 0 ),
            'start_date' => sanitize_text_field( (string) ( $payload['start_date'] ?? gmdate( 'Y-m-d' ) ) ),
            'end_date'   => sanitize_text_field( (string) ( $payload['end_date'] ?? gmdate( 'Y-m-d' ) ) ),
            'notes'      => sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) ),
            'created_by' => get_current_user_id(),
        ] );
        if ( $result instanceof \WP_Error ) {
            $data    = (array) $result->get_error_data();
            $details = isset( $data['existing_case_id'] ) ? [ 'existing_case_id' => (int) $data['existing_case_id'] ] : [];
            return RestResponse::error(
                (string) $result->get_error_code(),
                $result->get_error_message(),
                (int) ( $data['status'] ?? 400 ),
                $details
            );
        }
        $case = ( new TrialCasesRepository() )->find( (int) $result );
        // #3130 — `tt_trial_started` moved into `TrialCasesRepository::create()`.
        // Four callers reached that method and only three fired the hook, so
        // the journey entry depended on which screen opened the trial.
        return RestResponse::success( [ 'case' => self::format( $case, [], true ) ] );
    }

    public static function get_case( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $case = ( new TrialCasesRepository() )->find( $id );
        if ( ! $case ) return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        // #3566 — `canOpenCase()`, not `canViewSynthesis()`. A scout on the
        // panel may read the case they are assessing; the synthesis (other
        // panellists' inputs before release) stays behind its own gate, which
        // is why the motivation below is still asked for separately.
        if ( ! TrialCaseAccessPolicy::canOpenCase( get_current_user_id(), $id ) ) {
            return RestResponse::error( 'forbidden', __( 'No access to this case.', 'talenttrack' ), 403 );
        }
        // The motivation is synthesis-level, so it rides along only for a
        // viewer who passes that narrower gate (#3654).
        $with_motivation = TrialCaseAccessPolicy::canViewSynthesis( get_current_user_id(), $id );
        return RestResponse::success( [ 'case' => self::format( $case, [], $with_motivation ) ] );
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
        $id      = absint( $r['id'] );
        $repo    = new TrialCasesRepository();
        $case    = $repo->find( $id );
        if ( ! $case ) return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );

        $payload = (array) $r->get_json_params();
        $patch   = array_intersect_key( $payload, array_flip( [ 'track_id','start_date','end_date','status','notes' ] ) );

        // #3602 — a status is one of the four, and archiving is the
        // repository's archive(), not a flipped string. Setting `status`
        // alone left `archived_at` null, and every list keys archive state
        // on that column, so an "archived" case stayed among the live ones.
        $archive = false;
        if ( array_key_exists( 'status', $patch ) ) {
            $status = sanitize_key( (string) $patch['status'] );
            if ( ! TrialCaseStatus::isValid( $status ) ) {
                return RestResponse::error( 'bad_status', __( 'Unknown trial case status.', 'talenttrack' ), 400, [
                    'allowed' => TrialCaseStatus::ALL,
                ] );
            }
            if ( $status === TrialCaseStatus::ARCHIVED ) {
                $archive = true;
                unset( $patch['status'] );
            } else {
                $patch['status'] = $status;
                // Moving an archived case back to a live status restores it,
                // or it would be live by status and archived by column.
                if ( ! empty( ( (array) $case )['archived_at'] ) ) {
                    $patch['archived_at'] = null;
                    $patch['archived_by'] = null;
                }
            }
        }

        if ( ! $archive && $patch === [] ) {
            return RestResponse::error( 'bad_request', __( 'No fields to update.', 'talenttrack' ), 400 );
        }

        $ok = true;
        if ( $patch !== [] ) $ok = $repo->update( $id, $patch );
        if ( $archive )      $ok = $repo->archive( $id, get_current_user_id() ) || $ok;

        // Manager-gated, so the same shape `get_case()` answers with (#3654).
        return $ok ? RestResponse::success( [ 'updated' => true, 'case' => self::format( $repo->find( $id ), [], true ) ] )
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

    /**
     * The fields the decision route takes (#3654).
     *
     * The motivation is `notes`. Before this was declared, the route
     * advertised nothing, so a caller sending `justification` — a perfectly
     * reasonable guess — had it silently dropped and was then told the
     * motivation it had written was too short. `checkBody()` now refuses
     * the unknown key by name and lists what the route does accept.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function decisionArgs(): array {
        return [
            'decision' => [
                'type'        => 'string',
                'required'    => true,
                'enum'        => [
                    TrialCaseDecision::ADMIT,
                    TrialCaseDecision::DENY_FINAL,
                    TrialCaseDecision::DENY_ENCOURAGEMENT,
                ],
                'description' => 'The outcome of the trial: admit, deny_final or deny_encouragement.',
            ],
            'notes' => [
                'type'        => 'string',
                'required'    => true,
                'description' => 'Motivation for the decision, at least ' . self::DECISION_NOTES_MIN . ' characters.',
            ],
            'strengths_summary' => [
                'type'        => 'string',
                'description' => 'What the player is good at, for the letter home. Left alone when not sent.',
            ],
            'growth_areas' => [
                'type'        => 'string',
                'description' => 'What the player should work on, for the letter home. Left alone when not sent.',
            ],
        ];
    }

    public static function record_decision( \WP_REST_Request $r ): \WP_REST_Response {
        $id      = absint( $r['id'] );
        $payload = (array) $r->get_json_params();

        // Refuses a key the route does not take, and a declared-required key
        // sent empty. A misnamed motivation is now answered as a misnamed
        // motivation rather than as a short one.
        $refused = \TT\Infrastructure\REST\BaseController::checkBody( $r, self::decisionArgs() );
        if ( $refused !== null ) return $refused;

        $decision = sanitize_key( (string) ( $payload['decision'] ?? '' ) );
        $notes    = sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) );
        // Counted in characters, not bytes: the message promises characters,
        // and a Dutch motivation carrying a few accents used to clear a
        // byte-counted floor several characters early.
        $length   = mb_strlen( $notes );
        if ( $length < self::DECISION_NOTES_MIN ) {
            return RestResponse::error(
                'bad_request',
                sprintf(
                    /* translators: %d: minimum number of characters. */
                    __( 'The motivation in "notes" must be at least %d characters.', 'talenttrack' ),
                    self::DECISION_NOTES_MIN
                ),
                400,
                [ 'field' => 'notes', 'min_length' => self::DECISION_NOTES_MIN, 'length' => $length ]
            );
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
        // #3566 — this had no per-case check at all: `can_view()` admitted
        // the caller and the panel of any case in the club came back. With a
        // scout's player scope now resolving, that would have been every
        // panel. Assignment decides.
        if ( ! TrialCaseAccessPolicy::canOpenCase( get_current_user_id(), $id ) ) {
            return RestResponse::error( 'forbidden', __( 'No access to this case.', 'talenttrack' ), 403 );
        }
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

    /**
     * #3673 — what the panel has said about this trialist.
     *
     * The deciding manager reads every input on the case, submitted or
     * not, plus the two numbers the Staff inputs tab prints: how many of
     * the assigned panel have handed in, and when the inputs were
     * released. An assigned panel member reads their own input, and the
     * others once released — the rule lives in
     * `TrialStaffInputsRepository::listVisibleForUser()`, which the tab
     * calls too, so it is not re-derived here.
     *
     * A draft that is not the reader's own comes back without its
     * content, including for the manager. The row is there so the
     * manager can see who has started; a provisional judgement about a
     * child is its author's until they hand it in.
     */
    public static function list_inputs( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $case = ( new TrialCasesRepository() )->find( $id );
        if ( ! $case ) return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );

        $viewer     = get_current_user_id();
        $is_manager = TrialCaseAccessPolicy::isManager( $viewer );
        $staff      = new TrialCaseStaffRepository();
        if ( ! $is_manager && ! $staff->isAssigned( $id, $viewer ) ) {
            return RestResponse::error( 'forbidden', __( 'Not assigned to this case.', 'talenttrack' ), 403 );
        }

        $inputs = new TrialStaffInputsRepository();
        $rows   = $inputs->listVisibleForUser( $id, $viewer, $is_manager );

        $authors = [];
        foreach ( $rows as $row ) {
            $authors[] = (int) ( ( (array) $row )['user_id'] ?? 0 );
        }
        $names = self::staffNames( $authors );

        $out = [];
        foreach ( $rows as $row ) {
            $author = (int) ( ( (array) $row )['user_id'] ?? 0 );
            $one    = self::formatInput( $row, $names, $author !== $viewer );
            if ( $one !== null ) $out[] = $one;
        }

        $payload = [ 'inputs' => $out ];

        if ( $is_manager ) {
            $released = (string) ( ( (array) $case )['inputs_released_at'] ?? '' );
            $payload['assigned_count']     = count( $staff->listForCase( $id ) );
            $payload['submitted_count']    = count( $inputs->listForCase( $id, true ) );
            $payload['inputs_released_at'] = $released !== '' ? $released : null;
        }

        return RestResponse::success( $payload );
    }

    /**
     * Display names for a set of accounts, in one query (#3673).
     *
     * @param array<int,int> $user_ids
     * @return array<int,string>
     */
    private static function staffNames( array $user_ids ): array {
        $ids = array_values( array_unique( array_filter(
            array_map( 'intval', $user_ids ),
            static fn( int $id ): bool => $id > 0
        ) ) );
        if ( $ids === [] ) return [];

        $out = [];
        foreach ( get_users( [ 'include' => $ids, 'fields' => [ 'ID', 'display_name' ] ] ) as $user ) {
            $u = (array) $user;
            $out[ (int) ( $u['ID'] ?? 0 ) ] = (string) ( $u['display_name'] ?? '' );
        }
        return $out;
    }

    public static function upsert_input( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $payload = $r->get_json_params();
        if ( ! $payload ) $payload = $r->get_body_params();
        if ( ! TrialCaseAccessPolicy::isInputAuthor( get_current_user_id(), $id ) ) {
            return RestResponse::error( 'forbidden', __( 'Not assigned to this case.', 'talenttrack' ), 403 );
        }

        // #3238 — the case has moved past the point where its inputs are
        // still being gathered. Refused rather than silently ignored: an
        // endpoint that returns success and writes nothing is how a coach
        // believes they corrected something they did not.
        //
        // 409 rather than 403, and a distinct message from the one above.
        // The caller is entitled to write on this case; the case's state is
        // what rejects it, and telling them "not assigned" would send them
        // to a manager to fix a permission that is not wrong.
        if ( ! TrialCaseAccessPolicy::caseAcceptsInput( $id ) ) {
            return RestResponse::error(
                'case_closed_to_input',
                __( 'This trial has been decided, so its staff inputs can no longer be changed. They are the record of what the decision was based on.', 'talenttrack' ),
                409
            );
        }
        // #3606 / #3612 — the same strict contract as match prep (#3587). A
        // key the route does not take is refused by name, and a body with
        // none of the ones it does take is refused too: both used to save an
        // empty draft (over the one already there) and answer `saved: true`.
        // The unknown-key half is the shared body contract (#3689); the
        // nothing-to-save half is this route's own rule.
        $refused = \TT\Infrastructure\REST\BaseController::checkBody( $r, self::inputArgs() );
        if ( $refused !== null ) return $refused;
        $accepted = array_keys( self::inputArgs() );
        if ( array_intersect( array_keys( $payload ), $accepted ) === [] ) {
            return RestResponse::error( 'no_input_fields', __( 'Send a rating, notes, or submit.', 'talenttrack' ), 400, [
                'allowed' => $accepted,
            ] );
        }

        // Only what was sent is written, so a rating-only save leaves the
        // notes alone and a bare submit submits what is already there.
        $data = [];
        if ( array_key_exists( 'overall_rating', $payload ) ) {
            $raw = $payload['overall_rating'];
            if ( $raw === null || $raw === '' ) {
                $data['overall_rating'] = null;
            } else {
                $min = (float) QueryHelpers::get_config( 'rating_min', '5' );
                $max = (float) QueryHelpers::get_config( 'rating_max', '10' );
                if ( ! is_numeric( $raw ) || (float) $raw < $min || (float) $raw > $max ) {
                    return RestResponse::error( 'bad_rating', sprintf(
                        /* translators: 1: rating min, 2: rating max */
                        __( 'The overall rating must be a number from %1$s to %2$s.', 'talenttrack' ),
                        (string) $min,
                        (string) $max
                    ), 400 );
                }
                $data['overall_rating'] = (float) $raw;
            }
        }
        if ( array_key_exists( 'free_text_notes', $payload ) ) {
            $data['free_text_notes'] = sanitize_textarea_field( (string) $payload['free_text_notes'] );
        }

        $user_id = get_current_user_id();
        $inputs  = new TrialStaffInputsRepository();
        if ( $data !== [] ) $inputs->upsertDraft( $id, $user_id, $data );

        $submitted = false;
        if ( ! empty( $payload['submit'] ) ) {
            // An empty assessment must never become the record a decision
            // was based on (#3238).
            $row = (array) ( $inputs->findForCaseUser( $id, $user_id ) ?? [] );
            if ( ( $row['overall_rating'] ?? null ) === null && trim( (string) ( $row['free_text_notes'] ?? '' ) ) === '' ) {
                return RestResponse::error( 'empty_input', __( 'Add a rating or notes before submitting.', 'talenttrack' ), 400 );
            }
            $submitted = $inputs->submit( $id, $user_id );
        }

        return RestResponse::success( [
            'saved'     => true,
            'submitted' => $submitted,
            'input'     => self::formatInput( $inputs->findForCaseUser( $id, $user_id ) ),
        ] );
    }

    /**
     * The fields `POST trial-cases/{id}/inputs` takes: the route declares
     * them and the body check compares against them, so the two agree.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function inputArgs(): array {
        return [
            'overall_rating'  => [
                'type'        => [ 'number', 'string', 'null' ],
                'description' => 'Overall rating on the academy\'s rating scale. Null or blank clears it.',
            ],
            'free_text_notes' => [
                'type'        => 'string',
                'description' => 'The assessment in words.',
            ],
            'submit'          => [
                'type'        => 'boolean',
                'description' => 'Submit the input. Refused while it has neither a rating nor notes.',
            ],
        ];
    }

    /**
     * One staff input, as the API states it.
     *
     * @param array<int,string>|null $names   Author display names, resolved
     *                                       by the caller for a whole list.
     *                                       Null resolves this row's own.
     * @param bool                   $withhold True for somebody else's row:
     *                                       an unsubmitted draft then comes
     *                                       back without its content
     *                                       (#3673). A submitted input is
     *                                       returned in full — reaching this
     *                                       method at all means the
     *                                       repository already allowed it.
     * @return array<string,mixed>|null
     */
    private static function formatInput( ?object $row, ?array $names = null, bool $withhold = false ): ?array {
        if ( ! $row ) return null;
        $r         = (array) $row;
        $user_id   = (int) ( $r['user_id'] ?? 0 );
        $submitted = isset( $r['submitted_at'] ) ? (string) $r['submitted_at'] : '';
        $released  = isset( $r['released_at'] ) ? (string) $r['released_at'] : '';
        $hidden    = $withhold && $submitted === '';
        if ( $names === null ) $names = self::staffNames( [ $user_id ] );

        return [
            'id'               => (int) ( $r['id'] ?? 0 ),
            'case_id'          => (int) ( $r['case_id'] ?? 0 ),
            'user_id'          => $user_id,
            'author_name'      => (string) ( $names[ $user_id ] ?? '' ),
            'overall_rating'   => ( $hidden || ! isset( $r['overall_rating'] ) ) ? null : (float) $r['overall_rating'],
            'free_text_notes'  => $hidden ? '' : (string) ( $r['free_text_notes'] ?? '' ),
            'submitted_at'     => $submitted !== '' ? $submitted : null,
            'released_at'      => $released !== '' ? $released : null,
            'updated_at'       => isset( $r['updated_at'] ) ? (string) $r['updated_at'] : null,
            'content_withheld' => $hidden,
        ];
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
            $out[] = self::letterRow( $row );
        }

        return RestResponse::success( [ 'case_id' => $id, 'letters' => $out ] );
    }

    /**
     * The wire shape of one letter, shared by the list and the delivery
     * writes so the two cannot drift.
     *
     * `delivered` is derived rather than stored: a caller asking "does
     * this family have the letter" should not have to know that the
     * answer is "delivered_at is not null".
     *
     * @return array<string,mixed>
     */
    private static function letterRow( object $row ): array {
        $delivered_at = (string) ( $row->delivered_at ?? '' );
        $method       = (string) ( $row->delivery_method ?? '' );
        $delivered_by = (int) ( $row->delivered_by ?? 0 );

        return [
            'id'              => (int) ( $row->id ?? 0 ),
            'audience'        => (string) ( $row->audience ?? '' ),
            'created_at'      => (string) ( $row->created_at ?? '' ),
            'revoked_at'      => (string) ( $row->revoked_at ?? '' ),
            'generated_by'    => (int) ( $row->generated_by ?? 0 ),
            'is_active'       => empty( $row->revoked_at ),
            'delivered'       => $delivered_at !== '',
            'delivered_at'    => $delivered_at !== '' ? $delivered_at : null,
            'delivered_by'    => $delivered_by > 0 ? $delivered_by : null,
            'delivery_method' => $method !== '' ? $method : null,
        ];
    }

    /**
     * What the delivery route takes (#3683).
     *
     * The enum is declared so a caller can read the allowed methods off
     * the route rather than guess at them — #3674's lesson. The handler
     * re-checks it, because a bad method must be answered the same way
     * whichever path reached it.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function deliveryArgs(): array {
        return [
            'method' => [
                'type'        => 'string',
                'required'    => true,
                'enum'        => TrialLetterService::DELIVERY_METHODS,
                'description' => 'How the letter reached the family: printed, emailed or handed_over.',
            ],
        ];
    }

    /**
     * Record that a letter reached the family.
     *
     * TalentTrack sends nothing — this is the club writing down a human
     * step, so that the next person to open the case can tell a family
     * still waiting from one already told.
     */
    public static function record_letter_delivery( \WP_REST_Request $r ): \WP_REST_Response {
        $id        = absint( $r['id'] );
        $letter_id = absint( $r['letter_id'] );

        if ( ! ( new TrialCasesRepository() )->find( $id ) ) {
            return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        }

        $svc = new TrialLetterService();
        if ( ! $svc->findInCase( $letter_id, $id ) ) {
            return RestResponse::error( 'letter_not_found', __( 'That letter is not on this trial case.', 'talenttrack' ), 404 );
        }

        $refused = \TT\Infrastructure\REST\BaseController::checkBody( $r, self::deliveryArgs() );
        if ( $refused !== null ) return $refused;

        $payload = (array) $r->get_json_params();
        $method  = sanitize_key( (string) ( $payload['method'] ?? '' ) );

        if ( ! TrialLetterService::isDeliveryMethod( $method ) ) {
            return RestResponse::error(
                'bad_delivery_method',
                __( 'A valid delivery method is required.', 'talenttrack' ),
                400,
                [ 'field' => 'method', 'allowed' => TrialLetterService::DELIVERY_METHODS ]
            );
        }

        $row = $svc->recordDelivery( $letter_id, $id, $method, get_current_user_id() );
        if ( $row === null ) {
            return RestResponse::error( 'delivery_failed', __( 'The delivery could not be recorded.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [
            'case_id' => $id,
            'letter'  => self::letterRow( $row ),
        ] );
    }

    /** Undo a delivery record — the wrong letter was ticked. */
    public static function clear_letter_delivery( \WP_REST_Request $r ): \WP_REST_Response {
        $id        = absint( $r['id'] );
        $letter_id = absint( $r['letter_id'] );

        if ( ! ( new TrialCasesRepository() )->find( $id ) ) {
            return RestResponse::error( 'not_found', __( 'Trial case not found.', 'talenttrack' ), 404 );
        }

        $svc = new TrialLetterService();
        if ( ! $svc->findInCase( $letter_id, $id ) ) {
            return RestResponse::error( 'letter_not_found', __( 'That letter is not on this trial case.', 'talenttrack' ), 404 );
        }

        $row = $svc->clearDelivery( $letter_id, $id );
        if ( $row === null ) {
            return RestResponse::error( 'delivery_failed', __( 'The delivery record could not be cleared.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [
            'case_id' => $id,
            'letter'  => self::letterRow( $row ),
        ] );
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
     * #3654 — when a decision was recorded and by whom, plus the motivation
     * behind it.
     *
     * `recordDecision()` has always written all three and no read returned
     * any of them, so there was no way to check what had been stored.
     *
     * The split is deliberate. The two stamps ride on every case, because a
     * list that shows `decision` and cannot say when it was taken is half an
     * answer. `decision_notes` does not: it is free text about whether an
     * academy wants a child, and `list_cases` is gated on the capability
     * alone, where `get_case` runs `canViewSynthesis()` per case. Sending
     * the motivation to a coach who is not on the case would widen who reads
     * it without anyone deciding to.
     *
     * @param array<int,string> $names     player id => display name (#3577).
     * @param bool              $with_notes Include `decision_notes`. Only true
     *                                      on a read the caller has per-case
     *                                      synthesis access to.
     * @return array<string,mixed>
     */
    private static function format( ?object $row, array $names = [], bool $with_notes = false ): array {
        if ( ! $row ) return [];
        $r         = (array) $row;
        $player_id = (int) ( $r['player_id'] ?? 0 );
        if ( $names === [] ) $names = self::playerNames( [ $player_id ] );
        $made_at = (string) ( $r['decision_made_at'] ?? '' );
        $made_by = (int) ( $r['decision_made_by'] ?? 0 );
        $out = [
            'id'               => (int) $row->id,
            'player_id'        => (int) $row->player_id,
            // #3577 — who is on trial, without a lookup per row.
            'player_name'      => $names[ $player_id ] ?? '',
            'track_id'         => (int) $row->track_id,
            'start_date'       => (string) $row->start_date,
            'end_date'         => (string) $row->end_date,
            'status'           => (string) $row->status,
            'extension_count'  => (int) $row->extension_count,
            'decision'         => $row->decision ? (string) $row->decision : null,
            'decision_made_at' => $made_at !== '' ? $made_at : null,
            'decision_made_by' => $made_by > 0 ? $made_by : null,
            'created_at'       => (string) $row->created_at,
            // #2023 — archived_at + trashed_at via the shared lifecycle helper.
        ] + \TT\Infrastructure\Archive\LifecycleFields::forRow( $row );
        if ( $with_notes ) {
            $notes = (string) ( $r['decision_notes'] ?? '' );
            $out['decision_notes'] = $notes !== '' ? $notes : null;
        }
        return $out;
    }
}
