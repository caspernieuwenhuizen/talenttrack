<?php
namespace TT\Modules\Wizards\Goal;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — pick the player this goal is for.
 *
 * v3.110.101 — was a club-wide `<select>` of all players ordered by
 * last name, which ignored team scoping. A head-coach assigned only
 * to O13 saw O14 players in the dropdown. Replaced with
 * `PlayerSearchPickerComponent`, an autocomplete that delegated to
 * the same `resolvePlayers( $user_id, $is_admin )` helper used
 * elsewhere — head-coach scope automatic.
 *
 * v4.20.6 (#1156) — autocomplete replaced with two stacked dropdowns
 * (team → player) per pilot UX feedback. The coach knows which team
 * the goal is for; scanning + tapping is faster than typing. The
 * player dropdown groups its options by `<optgroup label="…">` per
 * team; `wizard-cascade-picker.js` (enqueued in render()) hides
 * off-team optgroups when the team filter changes. No REST
 * round-trip; no autocomplete library; properly enqueued JS per
 * CLAUDE.md (no inline scripts).
 *
 * Scoping: head-coach / assistant-coach see only their assigned
 * teams (`get_teams_for_coach`); admin / HoD see the full club
 * (`get_teams`). Empty assignment renders a friendly notice instead
 * of an empty form.
 */
final class PlayerStep implements WizardStepInterface {

    public function slug(): string { return 'player'; }
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
            // Required hidden input so the wizard's required-field
            // check still recognises the missing player_id.
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
            <label class="tt-field-label" for="tt-goal-team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
            <select id="tt-goal-team" class="tt-input"
                    data-tt-cascade-filter
                    data-tt-cascade-target="tt-goal-player">
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
            <label class="tt-field-label" for="tt-goal-player"><?php esc_html_e( 'Which player is this goal for?', 'talenttrack' ); ?></label>
            <select id="tt-goal-player" class="tt-input" name="player_id" required>
                <option value="0"><?php esc_html_e( '— Pick a player —', 'talenttrack' ); ?></option>
                <?php foreach ( $teams as $t ) :
                    $tid = (int) $t->id;
                    $players = $players_by_team[ $tid ] ?? [];
                    if ( ! $players ) continue; ?>
                    <optgroup label="<?php echo esc_attr( (string) $t->name ); ?>" data-tt-team-id="<?php echo esc_attr( (string) $tid ); ?>">
                        <?php foreach ( $players as $pl ) :
                            $pid = (int) $pl->id;
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

    public function nextStep( array $state ): ?string { return 'link'; }
    public function submit( array $state ) { return null; }
}
