<?php
namespace TT\Modules\Trials\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;

/**
 * TrialCaseOpener (#3221) — the one way a trial case gets opened.
 *
 * Opening a case is four writes that have to happen together: the case
 * row, the player's status flipping to trial, the initial staff
 * assignments, and — through the repository — the `tt_trial_started`
 * event that puts the trial on the player's timeline.
 *
 * ## Why this is a service and not a method on a view
 *
 * It used to live in `FrontendTrialsManageView::handlePost()`, which was
 * fine while that form was the only way in. The wizard makes it the
 * second, and this module's own history says what happens next: #3115
 * found the flat form creating players with a raw `$wpdb->insert` that
 * skipped `tt_player_created`, and #3130 found `tt_trial_started` fired
 * by three of its four callers. Both were second-write-path bugs, and
 * both took a while to notice because nothing errored — the data was
 * simply less complete depending on which screen you used.
 *
 * A third write path was not worth the risk, so both surfaces call this
 * (CLAUDE.md §4 — the logic is not in the view).
 */
class TrialCaseOpener {

    /**
     * Create a trial-status player through the canonical player create.
     *
     * Lifted verbatim from `FrontendTrialsManageView` (#3115), reasoning
     * included, because the reasoning is the point:
     *
     * The old inline insert wrote a row and nothing else — no
     * `tt_player_created`, so the player's own arrival was missing from
     * the timeline for exactly the players whose journey begins with a
     * trial, and no custom-field defaults, consent stamp, parent link or
     * licence check either.
     *
     * It calls `PlayersRestController::create_player()` in-process rather
     * than through `rest_do_request()`, which would apply the players
     * endpoint's own `permission_callback` — a trials manager who can open
     * a case would lose the ability to create the player the case is
     * about. The gate that belongs here runs in the caller.
     *
     * One consequence worth knowing: an install with a *required* player
     * custom field rejects this, because the canonical path validates
     * those and a three-field form cannot supply them. That is the same
     * answer every other player-create path gives, and the reason to route
     * through one place rather than have the trials surfaces quietly be
     * the ones that skip validation.
     *
     * @return array{id: int, error: string} Player id, or 0 with a message.
     */
    public function createTrialPlayer( string $first_name, string $last_name, string $date_of_birth ): array {
        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/players' );
        $request->set_param( 'first_name',    $first_name );
        $request->set_param( 'last_name',     $last_name );
        $request->set_param( 'date_of_birth', $date_of_birth );
        $request->set_param( 'status',        PlayerStatus::TRIAL );

        // Same msgid the players endpoint uses for this failure, so the
        // surfaces say the same sentence.
        $generic  = __( 'The player could not be created.', 'talenttrack' );
        $response = \TT\Infrastructure\REST\PlayersRestController::create_player( $request );
        if ( ! $response instanceof \WP_REST_Response ) {
            return [ 'id' => 0, 'error' => $generic ];
        }

        $body = (array) $response->get_data();

        if ( $response->get_status() >= 300 ) {
            $first = is_array( $body['errors'] ?? null ) ? reset( $body['errors'] ) : null;
            $msg   = is_array( $first ) ? (string) ( $first['message'] ?? '' ) : '';
            return [ 'id' => 0, 'error' => $msg !== '' ? $msg : $generic ];
        }

        // RestResponse::success() nests the payload under `data`.
        $player = is_array( $body['data'] ?? null ) ? $body['data'] : $body;
        $id     = isset( $player['id'] ) ? (int) $player['id'] : 0;

        return [ 'id' => $id, 'error' => $id > 0 ? '' : $generic ];
    }

    /**
     * Open a trial case.
     *
     * Caller supplies an already-authorised user: this checks that the
     * data is coherent and that the player is in the caller's club, not
     * that the caller is allowed to be here. Both surfaces gate before
     * they get this far.
     *
     * Keys: `player_id`, `track_id`, `start_date`, `end_date`, and
     * optionally `notes`, `created_by` and `staff` (a list of
     * `[ 'user_id' => int, 'role_label' => string ]`).
     *
     * Typed loosely on purpose. Both callers build this from request data,
     * so every key is genuinely absent-able and the defaulting below is
     * the validation — a precise array shape would tell PHPStan the keys
     * always exist and turn the guards into dead code.
     *
     * @param array<string,mixed> $data
     * @return int|\WP_Error New case id.
     */
    public function open( array $data ) {
        $player_id = (int) ( $data['player_id'] ?? 0 );
        $track_id  = (int) ( $data['track_id'] ?? 0 );
        $start     = (string) ( $data['start_date'] ?? '' );
        $end       = (string) ( $data['end_date'] ?? '' );

        if ( $player_id <= 0 || $track_id <= 0 || $start === '' || $end === '' ) {
            return new \WP_Error(
                'incomplete',
                __( 'Please pick a player (or fill in first name, last name and date of birth to create one), a track, and start/end dates.', 'talenttrack' )
            );
        }

        // v4.20.41 (#1201) — the cross-club pointing class. A submitted
        // `player_id` used to be accepted as-is, stamping the writer's
        // `club_id` on the case row while pointing it at any player in the
        // database. The trial cascade then silently no-ops downstream,
        // because every one of those updates is club-scoped.
        $player_row = QueryHelpers::get_player( $player_id );
        if ( ! $player_row || (int) ( $player_row->club_id ?? 0 ) !== (int) CurrentClub::id() ) {
            return new \WP_Error( 'foreign_player', __( 'Player not found in your club.', 'talenttrack' ) );
        }

        $case_id = ( new TrialCasesRepository() )->create( [
            'player_id'  => $player_id,
            'track_id'   => $track_id,
            'start_date' => $start,
            'end_date'   => $end,
            'notes'      => (string) ( $data['notes'] ?? '' ),
            'created_by' => (int) ( $data['created_by'] ?? get_current_user_id() ),
        ] );

        if ( $case_id <= 0 ) {
            return new \WP_Error( 'create_failed', __( 'Could not create the case. Please try again.', 'talenttrack' ) );
        }

        // The trial is now the player's state, not just a row about them.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_players',
            [ 'status' => PlayerStatus::TRIAL ],
            [ 'id' => $player_id, 'club_id' => CurrentClub::id() ]
        );

        $staff_repo = new TrialCaseStaffRepository();
        foreach ( (array) ( $data['staff'] ?? [] ) as $entry ) {
            $uid = (int) ( $entry['user_id'] ?? 0 );
            if ( $uid <= 0 ) continue;
            $label = trim( (string) ( $entry['role_label'] ?? '' ) );
            $staff_repo->assign( $case_id, $uid, $label !== '' ? $label : null );
        }

        // `tt_trial_started` is announced by the repository (#3130), so
        // every caller — this one included — gets the journey event
        // without having to remember it.

        return $case_id;
    }
}
