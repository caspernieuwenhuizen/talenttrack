<?php
namespace TT\Modules\Journey\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — who is suspended?
 *
 * Team → player, the cascade the injury and goal wizards use. Only the
 * teams whose suspensions this user may record are offered: a coach sees
 * their own squads, head of development and academy admin every team.
 *
 * Skipped when the wizard was opened from a player's profile.
 */
final class SuspensionPlayerStep implements WizardStepInterface {

    public function slug(): string  { return 'player'; }
    public function label(): string { return __( 'Player', 'talenttrack' ); }

    /** @param array<string, mixed> $state */
    public function notApplicableFor( array $state ): bool {
        return (int) ( $state['player_id'] ?? 0 ) > 0;
    }

    public function render( array $state ): void {
        $user_id = get_current_user_id();
        $current = (int) ( $state['player_id'] ?? 0 );

        wp_enqueue_script(
            'tt-wizard-cascade-picker',
            TT_PLUGIN_URL . 'assets/js/components/wizard-cascade-picker.js',
            [],
            TT_VERSION,
            true
        );

        $teams = self::teamsFor( $user_id );
        if ( $teams === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'There are no teams you can record a suspension for. Ask an administrator to assign you to a team.', 'talenttrack' ) . '</p>';
            echo '<input type="hidden" name="player_id" value="0" required />';
            return;
        }

        $selected_team_id = 0;
        if ( $current > 0 ) {
            $player = (array) ( QueryHelpers::get_player( $current ) ?? [] );
            $selected_team_id = (int) ( $player['team_id'] ?? 0 );
        }

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-suspension-team">' . esc_html__( 'Team', 'talenttrack' ) . '</label>';
        echo '<select id="tt-suspension-team" class="tt-input" data-tt-cascade-filter data-tt-cascade-target="tt-suspension-player">';
        echo '<option value="0">' . esc_html__( '— Pick a team —', 'talenttrack' ) . '</option>';
        foreach ( $teams as $tid => $team_name ) {
            echo '<option value="' . esc_attr( (string) $tid ) . '"' . selected( $selected_team_id, $tid, false ) . '>' . esc_html( $team_name ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-suspension-player">' . esc_html__( 'Which player is suspended?', 'talenttrack' ) . '</label>';
        echo '<select id="tt-suspension-player" class="tt-input" name="player_id" required>';
        echo '<option value="0">' . esc_html__( '— Pick a player —', 'talenttrack' ) . '</option>';
        foreach ( $teams as $tid => $team_name ) {
            $players = QueryHelpers::get_players( $tid );
            if ( $players === [] ) continue;
            echo '<optgroup label="' . esc_attr( $team_name ) . '" data-tt-team-id="' . esc_attr( (string) $tid ) . '">';
            foreach ( $players as $pl ) {
                $pid  = (int) ( ( (array) $pl )['id'] ?? 0 );
                $name = QueryHelpers::player_display_name( $pl );
                echo '<option value="' . esc_attr( (string) $pid ) . '"' . selected( $current, $pid, false ) . '>' . esc_html( $name !== '' ? $name : '#' . $pid ) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $pid = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
        if ( $pid <= 0 ) return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        return [ 'player_id' => $pid ];
    }

    /** Always names the next step, so the framework never submits from here. */
    public function nextStep( array $state ): string { return 'details'; }

    /**
     * Unreachable: only the final step submits.
     *
     * @return array<string,mixed>
     */
    public function submit( array $state ): array { return []; }

    /**
     * The teams whose players this user may record a suspension for.
     *
     * @return array<int, string> team id => name
     */
    public static function teamsFor( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        $global = MatrixGate::can( $user_id, 'player_suspensions', MatrixGate::CHANGE, MatrixGate::SCOPE_GLOBAL );
        $teams  = $global ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        $out = [];
        foreach ( $teams as $team ) {
            $t   = (array) $team;
            $tid = (int) ( $t['id'] ?? 0 );
            if ( $tid <= 0 ) continue;
            if ( ! $global && ! MatrixGate::can( $user_id, 'player_suspensions', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, $tid ) ) continue;
            $out[ $tid ] = (string) ( $t['name'] ?? '' );
        }
        return $out;
    }
}
