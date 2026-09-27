<?php
namespace TT\Modules\Journey\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Journey\SuspensionRepository;
use TT\Infrastructure\Journey\SuspensionService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Dates\TTDate;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — read it back, then record it. Final step: persists.
 *
 * The read-back names the matches the suspension will cover, taken from
 * the team's fixture list, so a coach sees "these three" rather than a
 * number and can catch a wrong start date before it reaches the family.
 * Matches not yet on the calendar are simply not listed yet.
 *
 * The write goes through `SuspensionRepository::create()`, which puts
 * `suspension_started` on the journey.
 */
final class SuspensionConfirmStep implements WizardStepInterface {

    public function slug(): string  { return 'confirm'; }
    public function label(): string { return __( 'Confirm', 'talenttrack' ); }

    public function render( array $state ): void {
        $player_id = (int) ( $state['player_id'] ?? 0 );
        $player    = QueryHelpers::get_player( $player_id );
        $name      = $player ? QueryHelpers::player_display_name( $player ) : '';
        $team_id   = (int) ( ( (array) ( $player ?? [] ) )['team_id'] ?? 0 );
        $count     = (int) ( $state['match_count'] ?? 0 );
        $started   = (string) ( $state['started_on'] ?? '' );
        $reason    = SuspensionRepository::reasonLabel( (int) ( $state['reason_lookup_id'] ?? 0 ) );

        echo '<dl class="tt-suspension-review">';
        echo '<dt>' . esc_html__( 'Player', 'talenttrack' ) . '</dt><dd>' . esc_html( $name !== '' ? $name : '—' ) . '</dd>';
        echo '<dt>' . esc_html__( 'Reason', 'talenttrack' ) . '</dt><dd>' . esc_html( $reason !== '' ? $reason : __( 'Not recorded', 'talenttrack' ) ) . '</dd>';
        echo '<dt>' . esc_html__( 'Number of matches', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $count ) . '</dd>';
        echo '<dt>' . esc_html__( 'Counts from', 'talenttrack' ) . '</dt><dd>' . esc_html( $started !== '' ? TTDate::date( $started ) : '—' ) . '</dd>';
        echo '</dl>';

        $fixtures = array_slice( SuspensionService::fixtureSequence( $team_id, $started ), 0, max( 0, $count ) );
        if ( $fixtures === [] ) {
            echo '<p class="tt-field-hint">' . esc_html__( 'No matches are on the calendar from that day yet. The suspension will cover the next ones that are added.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<p class="tt-field-hint">' . esc_html__( 'The player will be unavailable for these matches:', 'talenttrack' ) . '</p>';
        echo '<ol class="tt-suspension-matches-list">';
        foreach ( $fixtures as $fixture ) {
            echo '<li>' . esc_html( self::fixtureLabel( $fixture['id'], $fixture['date'] ) ) . '</li>';
        }
        echo '</ol>';
        if ( count( $fixtures ) < $count ) {
            echo '<p class="tt-field-hint">' . esc_html__( 'The rest will be the next matches added to the calendar.', 'talenttrack' ) . '</p>';
        }
    }

    public function validate( array $post, array $state ) {
        return [];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $player_id = (int) ( $state['player_id'] ?? 0 );
        if ( $player_id <= 0 ) {
            return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        }

        // The wizard's cap answers "may this user record suspensions at
        // all"; this answers "for THIS player" — the same pair the REST
        // route asks, so a hand-built URL cannot outflank the form.
        if ( ! AuthorizationService::canAccessSuspensions( get_current_user_id(), $player_id, 'change' ) ) {
            return new \WP_Error( 'forbidden', __( 'You cannot record a suspension for this player.', 'talenttrack' ) );
        }

        $id = ( new SuspensionRepository() )->create( [
            'player_id'        => $player_id,
            'started_on'       => (string) ( $state['started_on'] ?? '' ),
            'match_count'      => (int) ( $state['match_count'] ?? 0 ),
            'reason_lookup_id' => (int) ( $state['reason_lookup_id'] ?? 0 ),
            'notes'            => (string) ( $state['notes'] ?? '' ),
        ] );
        if ( $id <= 0 ) {
            return new \WP_Error( 'create_failed', __( 'Could not record the suspension.', 'talenttrack' ) );
        }

        ( new AuditService() )->record( 'player.suspension_created', 'player_suspension', $id, [
            'player_id' => $player_id,
            'surface'   => 'wizard',
        ] );

        return [ 'redirect_url' => add_query_arg(
            [ 'tt_view' => 'players', 'id' => $player_id, 'tab' => 'suspensions' ],
            WizardEntryPoint::dashboardBaseUrl()
        ) ];
    }

    /** "Sat 3 Oct · vs Ajax" where the title is known, the date alone otherwise. */
    public static function fixtureLabel( int $activity_id, string $date ): string {
        global $wpdb;
        $p     = $wpdb->prefix;
        $title = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT title FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        $when = $date !== '' ? TTDate::date( $date ) : '';
        return trim( $title ) !== '' ? $when . ' · ' . trim( $title ) : $when;
    }
}
