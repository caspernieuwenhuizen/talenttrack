<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * PlayerSearchPickerComponent — autocomplete-driven player picker.
 *
 * Replacement for the long select-of-all-players that the existing
 * PlayerPickerComponent renders. When a club has 200+ players the
 * select dropdown becomes unusable. This component renders:
 *
 *   - A search input (type-to-filter)
 *   - A scrollable result list rendered below the input
 *   - A hidden field that holds the selected player_id
 *   - An optional team filter applied as a server-side pre-scope
 *
 * The JS hydrator lives at assets/js/components/player-search-picker.js
 * and is enqueued by DashboardShortcode. Multiple instances per page
 * are supported — each instance is a `.tt-psp` element with its own
 * embedded `<script type="application/json" class="tt-psp-data">`
 * payload of player rows.
 *
 * Usage:
 *
 *   echo PlayerSearchPickerComponent::render([
 *       'name'      => 'player_id',
 *       'label'     => __( 'Player', 'talenttrack' ),
 *       'required'  => true,
 *       'user_id'   => get_current_user_id(),
 *       'is_admin'  => current_user_can( 'tt_edit_settings' ),
 *       'team_id'   => 0,        // 0 = no team filter; otherwise restrict
 *       'selected'  => 0,
 *   ]);
 *
 * Reused by:
 *   - FrontendComparisonView slot pickers (F2 application)
 *   - PlayerRateCardsPage / Frontend equivalent (F3 application)
 *   - Any future surface that needs single-player selection
 */
class PlayerSearchPickerComponent {

    /**
     * @param array{
     *   name?:string,
     *   label?:string,
     *   required?:bool,
     *   user_id?:int,
     *   is_admin?:bool,
     *   players?:array<int,object>,
     *   team_id?:int,
     *   selected?:int,
     *   placeholder?:string
     * } $args
     */
    public static function render( array $args = [] ): string {
        $name        = (string) ( $args['name'] ?? 'player_id' );
        $label       = (string) ( $args['label'] ?? __( 'Player', 'talenttrack' ) );
        $required    = ! empty( $args['required'] );
        $placeholder = (string) ( $args['placeholder'] ?? __( 'Type a name to search…', 'talenttrack' ) );
        $selected    = (int) ( $args['selected'] ?? 0 );
        $team_id     = (int) ( $args['team_id'] ?? 0 );

        /** @var array<int, object> $players */
        $players = $args['players'] ?? self::resolvePlayers(
            (int) ( $args['user_id'] ?? get_current_user_id() ),
            (bool) ( $args['is_admin'] ?? false ),
            $team_id
        );

        $rows = self::buildRows( $players );
        $selected_label = '';
        foreach ( $rows as $r ) {
            if ( (int) $r['id'] === $selected ) {
                $selected_label = (string) $r['label'];
                break;
            }
        }

        $instance = 'tt-psp-' . wp_generate_uuid4();
        ob_start();
        ?>
        <div class="tt-field tt-psp" data-tt-psp data-instance="<?php echo esc_attr( $instance ); ?>">
            <label class="tt-field-label<?php echo $required ? ' tt-field-required' : ''; ?>" for="<?php echo esc_attr( $instance . '-search' ); ?>">
                <?php echo esc_html( $label ); ?>
            </label>

            <input
                type="hidden"
                name="<?php echo esc_attr( $name ); ?>"
                value="<?php echo esc_attr( (string) $selected ); ?>"
                <?php echo $required ? 'required' : ''; ?>
                data-tt-psp-value
            />

            <div class="tt-psp-selected" style="<?php echo $selected ? '' : 'display:none;'; ?>" data-tt-psp-selected>
                <span class="tt-psp-selected-label" data-tt-psp-selected-label>
                    <?php echo esc_html( $selected_label ); ?>
                </span>
                <button type="button" class="tt-psp-clear" data-tt-psp-clear aria-label="<?php esc_attr_e( 'Clear selection', 'talenttrack' ); ?>">×</button>
            </div>

            <input
                type="text"
                id="<?php echo esc_attr( $instance . '-search' ); ?>"
                class="tt-input tt-psp-search"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                autocomplete="off"
                data-tt-psp-search
                style="<?php echo $selected ? 'display:none;' : ''; ?>"
            />

            <ul class="tt-psp-results" data-tt-psp-results role="listbox" hidden></ul>

            <script type="application/json" class="tt-psp-data" data-tt-psp-data>
                <?php echo wp_json_encode( $rows ); ?>
            </script>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build a flat array of {id, label, team_id, search} rows for the
     * client-side list. `search` is a lower-cased searchable string
     * concatenating name + team for prefix/contains matching.
     *
     * @param object[] $players
     * @return array<int, array{id:int, label:string, team_id:int, search:string}>
     */
    private static function buildRows( array $players ): array {
        $out = [];
        foreach ( $players as $pl ) {
            $name = QueryHelpers::player_display_name( $pl );
            $team = '';
            if ( ! empty( $pl->team_id ) ) {
                $t = QueryHelpers::get_team( (int) $pl->team_id );
                $team = $t ? (string) $t->name : '';
            }
            $label  = $team !== '' ? sprintf( '%s — %s', $name, $team ) : $name;
            $search = strtolower( $name . ' ' . $team );
            $out[] = [
                'id'      => (int) $pl->id,
                'label'   => $label,
                'team_id' => (int) ( $pl->team_id ?? 0 ),
                'search'  => $search,
            ];
        }
        return $out;
    }

    /**
     * Resolve the player list for the current user. Mirrors the
     * resolution logic in the existing PlayerPickerComponent but
     * accepts an optional team filter.
     *
     * @return array<int, object>
     */
    private static function resolvePlayers( int $user_id, bool $is_admin, int $team_id ): array {
        if ( $team_id > 0 ) {
            return QueryHelpers::get_players( $team_id );
        }
        if ( $is_admin ) {
            return QueryHelpers::get_players();
        }
        $teams = QueryHelpers::get_teams_for_coach( $user_id );
        $out = [];
        foreach ( $teams as $t ) {
            foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) {
                $out[ (int) $pl->id ] = $pl;
            }
        }
        return array_values( $out );
    }
}
