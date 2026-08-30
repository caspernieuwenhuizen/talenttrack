<?php
namespace TT\Modules\Wizards\Player;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Review and create.
 *
 * Renders a summary of accumulated state and, on submit, persists:
 *   - roster path: a player, through the canonical create.
 *   - trial path: the same player with status='trial' AND a real
 *     `tt_trial_cases` row pointing at it (when the Trials module is
 *     active). The user lands on the new trial-case detail view.
 *
 * Neither path writes `tt_players` itself — both go through
 * `PlayersRestController::create_player()` and the trial case through
 * `TrialCasesRepository::create()`, so the journey entries, custom-field
 * validation and licence cap are whatever those say they are. See
 * `createPlayer()` for why (#3189).
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $path = (string) ( $state['path'] ?? 'roster' );
        echo '<p>' . esc_html__( 'Check the details below before creating the record.', 'talenttrack' ) . '</p>';
        $rows = [
            __( 'Type', 'talenttrack' )           => $path === 'trial' ? __( 'Trial player', 'talenttrack' ) : __( 'Roster player', 'talenttrack' ),
            __( 'Name', 'talenttrack' )           => trim( ( (string) ( $state['first_name'] ?? '' ) ) . ' ' . ( (string) ( $state['last_name'] ?? '' ) ) ),
            __( 'Date of birth', 'talenttrack' )  => (string) ( $state['date_of_birth'] ?? '' ) ?: '—',
            __( 'Team', 'talenttrack' )           => self::teamLabel( (int) ( $state['team_id'] ?? 0 ) ),
        ];
        if ( $path === 'roster' ) {
            $rows[ __( 'Jersey number', 'talenttrack' ) ]  = $state['jersey_number'] ?? '—';
            $rows[ __( 'Preferred foot', 'talenttrack' ) ] = (string) ( $state['preferred_foot'] ?? '' ) ?: '—';
        } else {
            $rows[ __( 'Trial track', 'talenttrack' ) ]  = self::trackLabel( (int) ( $state['trial_track_id'] ?? 0 ) );
            $rows[ __( 'Trial start', 'talenttrack' ) ]  = (string) ( $state['trial_start_date'] ?? '' );
            $rows[ __( 'Trial end', 'talenttrack' ) ]    = (string) ( $state['trial_end_date'] ?? '' );
        }
        // #1526 — standard two-column review table (matches Activity/Prospect/VCT).
        echo '<div class="tt-table-wrap"><table class="tt-table tt-wizard-review-table"><tbody>';
        foreach ( $rows as $k => $v ) {
            echo '<tr><th scope="row" style="width:35%;">' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function validate( array $post, array $state ) { return []; }
    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $first = (string) ( $state['first_name'] ?? '' );
        $last  = (string) ( $state['last_name']  ?? '' );
        if ( $first === '' || $last === '' ) {
            return new \WP_Error( 'name_required', __( 'First and last name are required.', 'talenttrack' ) );
        }

        $path    = (string) ( $state['path'] ?? 'roster' );
        $created = self::createPlayer( $state, $path );
        if ( $created['id'] <= 0 ) {
            return new \WP_Error( 'player_create_failed', $created['error'] );
        }
        $player_id = $created['id'];

        if ( $path === 'trial' && class_exists( '\\TT\\Modules\\Trials\\Repositories\\TrialCasesRepository' ) ) {
            $track_id = (int) ( $state['trial_track_id'] ?? 0 );
            if ( $track_id > 0 ) {
                $cases = new \TT\Modules\Trials\Repositories\TrialCasesRepository();
                $case_id = $cases->create( [
                    'player_id'  => $player_id,
                    'track_id'   => $track_id,
                    'start_date' => (string) ( $state['trial_start_date'] ?? gmdate( 'Y-m-d' ) ),
                    'end_date'   => (string) ( $state['trial_end_date']   ?? gmdate( 'Y-m-d', time() + 28 * 86400 ) ),
                    'created_by' => get_current_user_id(),
                ] );
                if ( $case_id > 0 ) {
                    return [ 'redirect_url' => add_query_arg( [ 'tt_view' => 'trial-case', 'id' => $case_id ], \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl() ) ];
                }
            }
        }

        return [ 'redirect_url' => add_query_arg( [ 'tt_view' => 'players', 'player_id' => $player_id ], \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl() ) ];
    }

    /**
     * #3189 — create the player through the canonical player create rather
     * than reimplementing it with a raw `$wpdb->insert`.
     *
     * The old inline insert wrote a row and nothing else. No
     * `tt_player_created`, so `JourneyEventSubscriber` never turned the
     * creation into a `joined_academy` entry: a player created here had no
     * arrival on their own timeline, and the journey started at whatever
     * happened to them next. On the trial path that became visible once
     * #3130 landed — the player got a "Trial started" entry and nothing
     * saying they had joined.
     *
     * It skipped more than the hook: custom-field validation and defaults,
     * the consent stamp, the parent link. And it had already grown its own
     * copies of two things the canonical path does — a licence check
     * (v3.85.5) and demo tagging (v3.76.2). A second write path collecting
     * patches one at a time is the shape this fix removes; both copies are
     * gone, because `create_player()` does each of them.
     *
     * Called in-process, not through `rest_do_request()`. The dispatcher
     * would apply the players endpoint's own `permission_callback`, and
     * this wizard is reachable by an operator whose grant is on the wizard
     * surface — routing through REST would lock them out mid-flow. The gate
     * that belongs here already ran before the step was rendered. Same
     * reasoning, and the same choice, as `FrontendTrialsManageView`
     * (#3115).
     *
     * One consequence worth knowing: an install with a *required* player
     * custom field now rejects the wizard create, naming the field, because
     * the canonical path validates those and this form cannot supply them.
     * That is the answer every other player-create path gives; the wizard
     * quietly being the one that skipped validation was the bug.
     *
     * @param array<string,mixed> $state
     * @return array{id: int, error: string} Player id, or 0 with a message.
     */
    private static function createPlayer( array $state, string $path ): array {
        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/players' );
        $request->set_param( 'first_name',    (string) ( $state['first_name'] ?? '' ) );
        $request->set_param( 'last_name',     (string) ( $state['last_name']  ?? '' ) );
        $request->set_param( 'date_of_birth', (string) ( $state['date_of_birth'] ?? '' ) );
        $request->set_param( 'team_id',       (int) ( $state['team_id'] ?? 0 ) );
        $request->set_param( 'status',        $path === 'trial' ? PlayerStatus::TRIAL : PlayerStatus::ACTIVE );
        if ( $path === 'roster' ) {
            $request->set_param( 'jersey_number',  $state['jersey_number'] ?? null );
            $request->set_param( 'preferred_foot', (string) ( $state['preferred_foot'] ?? '' ) );
        }

        // Same msgid the players endpoint uses for this failure, so the two
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

    private static function teamLabel( int $team_id ): string {
        if ( $team_id <= 0 ) return '—';
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d", $team_id, CurrentClub::id() ) );
        return $name ?: '#' . $team_id;
    }

    private static function trackLabel( int $track_id ): string {
        if ( $track_id <= 0 ) return '—';
        if ( ! class_exists( '\\TT\\Modules\\Trials\\Repositories\\TrialTracksRepository' ) ) return '#' . $track_id;
        $row = ( new \TT\Modules\Trials\Repositories\TrialTracksRepository() )->find( $track_id );
        return $row ? (string) $row->name : '#' . $track_id;
    }
}
