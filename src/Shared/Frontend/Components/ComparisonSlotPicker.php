<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * ComparisonSlotPicker — two-step Team → Player picker for the
 * Player Comparison surfaces (frontend + wp-admin).
 *
 * Operator-asked shape (v3.91.6): each compare slot needs a Team
 * `<select>` first, then a Player `<select>` filtered by team, with a
 * search input filtering player options as you type. Replaces the
 * single-search `PlayerSearchPickerComponent` which mixed all teams'
 * players into one flat list and confused operators on installs with
 * 200+ players across many age groups.
 *
 * Cross-team comparison stays supported: each slot picks independently
 * — slot 1 can be a U10 player, slot 2 a Senior, slot 3 a U13. The
 * server reads the existing `?p1=N&p2=M` URL pattern; the team `<select>`
 * is UI-state-only (`team_N` form field, not consumed by the dispatch).
 *
 * Usage (per slot):
 *
 *   echo ComparisonSlotPicker::render([
 *       'index'              => 1,                // 1..4
 *       'selected_player_id' => $current_pid,
 *       'players'            => $all_players,     // [{id, first_name, last_name, team_id, team_name}]
 *       'teams_by_id'        => $teams_by_id,     // [team_id => team_name]
 *   ]);
 *
 * The component embeds its own self-contained JS via a single
 * data-attribute hook; multiple instances per page are supported.
 */
final class ComparisonSlotPicker {

    /**
     * @param array{
     *   index:int,
     *   selected_player_id?:int,
     *   players:array<int,object>,
     *   teams_by_id:array<int,string>,
     * } $args
     */
    public static function render( array $args ): string {
        $index    = (int) $args['index'];
        $selected = (int) ( $args['selected_player_id'] ?? 0 );
        $players  = $args['players'] ?? [];
        $teams    = $args['teams_by_id'] ?? [];

        // Resolve the selected player's team so we can pre-select the
        // team `<select>` when reloading a comparison URL.
        $selected_team_id = 0;
        if ( $selected > 0 ) {
            foreach ( $players as $pl ) {
                if ( (int) ( $pl->id ?? 0 ) === $selected ) {
                    $selected_team_id = (int) ( $pl->team_id ?? 0 );
                    break;
                }
            }
        }

        // Build a JSON blob the JS hydrator consumes. Keep it small —
        // every slot embeds its own copy, but on a 200-player install
        // that's still ~10 KB per blob, fine for a one-off page load.
        $rows = [];
        foreach ( $players as $pl ) {
            $name = QueryHelpers::player_display_name( $pl );
            $rows[] = [
                'id'      => (int) ( $pl->id ?? 0 ),
                'name'    => $name,
                'team_id' => (int) ( $pl->team_id ?? 0 ),
                'search'  => strtolower( $name ),
            ];
        }

        $instance_id = 'tt-csp-' . $index . '-' . wp_rand( 1000, 9999 );

        /* translators: %d is slot number */
        $slot_label   = sprintf( __( 'Player %d', 'talenttrack' ), $index );
        $team_ph      = __( '— Pick a team —', 'talenttrack' );
        $player_ph    = __( '— Pick a player —', 'talenttrack' );
        $search_ph    = __( 'Search players in this team…', 'talenttrack' );
        $clear_label  = __( 'Clear this slot', 'talenttrack' );

        ob_start();
        ?>
        <div class="tt-compare-slot-picker" data-tt-slot-picker data-instance="<?php echo esc_attr( $instance_id ); ?>" style="display:flex; flex-direction:column; gap:6px;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <strong style="font-size:13px;"><?php echo esc_html( $slot_label ); ?></strong>
                <button type="button" data-tt-slot-clear style="background:transparent; border:0; color:#5b6e75; cursor:pointer; padding:2px 6px; font-size:14px;<?php echo $selected > 0 ? '' : ' visibility:hidden;'; ?>" aria-label="<?php echo esc_attr( $clear_label ); ?>" title="<?php echo esc_attr( $clear_label ); ?>">×</button>
            </div>

            <select class="tt-input" data-tt-slot-team name="team_<?php echo (int) $index; ?>">
                <option value="0"><?php echo esc_html( $team_ph ); ?></option>
                <?php foreach ( $teams as $tid => $tname ) : ?>
                    <option value="<?php echo (int) $tid; ?>" <?php selected( $selected_team_id, (int) $tid ); ?>>
                        <?php echo esc_html( (string) $tname ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                type="text"
                class="tt-input"
                data-tt-slot-search
                placeholder="<?php echo esc_attr( $search_ph ); ?>"
                autocomplete="off"
                <?php echo $selected_team_id > 0 ? '' : 'disabled'; ?>
                style="<?php echo $selected_team_id > 0 ? '' : 'opacity:0.6;'; ?>"
            />

            <select class="tt-input" data-tt-slot-player name="p<?php echo (int) $index; ?>" <?php echo $selected_team_id > 0 ? '' : 'disabled'; ?>>
                <option value="0"><?php echo esc_html( $player_ph ); ?></option>
            </select>

            <script type="application/json" data-tt-slot-data><?php echo wp_json_encode( $rows ); ?></script>
            <script type="application/json" data-tt-slot-selected><?php echo wp_json_encode( [ 'player_id' => $selected, 'team_id' => $selected_team_id ] ); ?></script>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
