<?php
namespace TT\Modules\Journey\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — who got injured?
 *
 * Two stacked dropdowns (team → player), the same cascade the goal
 * wizard uses: a coach knows the team, and tapping beats typing on a
 * phone at the side of a pitch. Scoping comes from
 * `get_teams_for_coach()`, so a head coach only ever sees their own
 * squads.
 *
 * Entered from a player's file the wizard arrives with `player_id`
 * already in state and the step is skipped by the wizard runner.
 */
final class InjuryPlayerStep implements WizardStepInterface {

    public function slug(): string  { return 'player'; }
    public function label(): string { return __( 'Player', 'talenttrack' ); }

    public function render( array $state ): void {
        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'tt_edit_settings' );
        $current  = (int) ( $state['player_id'] ?? 0 );

        wp_enqueue_script(
            'tt-wizard-cascade-picker',
            TT_PLUGIN_URL . 'assets/js/components/wizard-cascade-picker.js',
            [],
            TT_VERSION,
            true
        );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        if ( ! $teams ) {
            echo '<p class="tt-notice">' . esc_html__( "You don't coach any teams yet, so there's no roster to pick from. Ask an administrator to assign you to a team.", 'talenttrack' ) . '</p>';
            echo '<input type="hidden" name="player_id" value="0" required />';
            return;
        }

        $selected_team_id = 0;
        if ( $current > 0 ) {
            global $wpdb;
            $selected_team_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d",
                $current
            ) );
        }

        $players_by_team = [];
        foreach ( $teams as $t ) {
            $players_by_team[ (int) $t->id ] = QueryHelpers::get_players( (int) $t->id );
        }

        ?>
        <div class="tt-field">
            <label class="tt-field-label" for="tt-injury-team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
            <select id="tt-injury-team" class="tt-input"
                    data-tt-cascade-filter
                    data-tt-cascade-target="tt-injury-player">
                <option value="0"><?php esc_html_e( '— Pick a team —', 'talenttrack' ); ?></option>
                <?php foreach ( $teams as $t ) :
                    $tid = (int) $t->id; ?>
                    <option value="<?php echo esc_attr( (string) $tid ); ?>"<?php selected( $selected_team_id, $tid ); ?>>
                        <?php echo esc_html( (string) $t->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tt-field">
            <label class="tt-field-label" for="tt-injury-player"><?php esc_html_e( 'Which player got injured?', 'talenttrack' ); ?></label>
            <select id="tt-injury-player" class="tt-input" name="player_id" required>
                <option value="0"><?php esc_html_e( '— Pick a player —', 'talenttrack' ); ?></option>
                <?php foreach ( $teams as $t ) :
                    $tid     = (int) $t->id;
                    $players = $players_by_team[ $tid ] ?? [];
                    if ( ! $players ) continue; ?>
                    <optgroup label="<?php echo esc_attr( (string) $t->name ); ?>" data-tt-team-id="<?php echo esc_attr( (string) $tid ); ?>">
                        <?php foreach ( $players as $pl ) :
                            $pid  = (int) $pl->id;
                            $name = trim( ( (string) ( $pl->first_name ?? '' ) ) . ' ' . ( (string) ( $pl->last_name ?? '' ) ) );
                            if ( $name === '' ) $name = '#' . $pid; ?>
                            <option value="<?php echo esc_attr( (string) $pid ); ?>"<?php selected( $current, $pid ); ?>>
                                <?php echo esc_html( $name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    public function validate( array $post, array $state ) {
        $pid = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
        if ( $pid <= 0 ) return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        return [ 'player_id' => $pid ];
    }

    public function nextStep( array $state ): ?string { return 'details'; }

    public function submit( array $state ) { return null; }
}
