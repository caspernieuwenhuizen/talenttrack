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
     *   placeholder?:string,
     *   cross_team?:bool,
     *   exclude_team_id?:int,
     *   show_team_filter?:bool,
     *   style?:string
     * } $args
     *
     * `style` selects the rendering mode:
     *   - 'search' (default) — type-to-filter input + result list. The ~6
     *     existing surfaces (comparison view, rate cards, guest attendance,
     *     goal wizard, …) all rely on this; it is unchanged.
     *   - 'dropdown' — a native player `<select>` scoped by the team filter.
     *     When the user manages exactly one team it is pre-selected so the
     *     player list is populated immediately, no typing required (#1731).
     */
    public static function render( array $args = [] ): string {
        $name        = (string) ( $args['name'] ?? 'player_id' );
        $label       = (string) ( $args['label'] ?? __( 'Player', 'talenttrack' ) );
        $required    = ! empty( $args['required'] );
        $placeholder = (string) ( $args['placeholder'] ?? __( 'Type a name to search…', 'talenttrack' ) );
        $selected    = (int) ( $args['selected'] ?? 0 );
        $team_id     = (int) ( $args['team_id'] ?? 0 );
        $cross_team  = ! empty( $args['cross_team'] );
        $exclude     = (int) ( $args['exclude_team_id'] ?? 0 );
        $show_team   = ! empty( $args['show_team_filter'] );
        $is_dropdown = ( (string) ( $args['style'] ?? 'search' ) ) === 'dropdown';

        /** @var array<int, object> $players */
        $players = $args['players'] ?? self::resolvePlayers(
            (int) ( $args['user_id'] ?? get_current_user_id() ),
            (bool) ( $args['is_admin'] ?? false ),
            $team_id,
            $cross_team,
            $exclude
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
        $teams_for_filter = $show_team ? self::teamsForFilter( $players ) : [];

        if ( $is_dropdown ) {
            return self::renderDropdown(
                $instance, $name, $label, $required, $selected,
                $rows, $teams_for_filter, $show_team
            );
        }

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

            <?php if ( $show_team && ! empty( $teams_for_filter ) ) : ?>
                <select class="tt-input tt-psp-team-filter" data-tt-psp-team-filter
                        style="margin-bottom:6px; <?php echo $selected ? 'display:none;' : ''; ?>">
                    <option value="0"><?php esc_html_e( 'All teams', 'talenttrack' ); ?></option>
                    <?php foreach ( $teams_for_filter as $tid => $tname ) : ?>
                        <option value="<?php echo (int) $tid; ?>"><?php echo esc_html( $tname ); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

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
     * Dropdown mode (#1731): a team-scoped native player `<select>` rather
     * than the type-to-search input. When the user manages exactly one team
     * it is pre-selected and the player `<select>` is populated immediately,
     * so a coach evaluating a single squad never has to type.
     *
     * The JSON rows payload is kept so the hydrator can repopulate the
     * player options client-side when the team filter changes.
     *
     * @param array<int, array{id:int, label:string, team_id:int, search:string}> $rows
     * @param array<int, string> $teams_for_filter
     */
    private static function renderDropdown(
        string $instance, string $name, string $label, bool $required, int $selected,
        array $rows, array $teams_for_filter, bool $show_team
    ): string {
        $single_team   = $show_team && count( $teams_for_filter ) === 1;
        $preselect_team = $single_team ? (int) array_key_first( $teams_for_filter ) : 0;

        // When a player is already selected, scope the initial team filter
        // and option list to that player's team so the value round-trips.
        if ( $selected > 0 ) {
            foreach ( $rows as $r ) {
                if ( (int) $r['id'] === $selected ) {
                    $preselect_team = (int) $r['team_id'];
                    break;
                }
            }
        }

        $player_options = $rows;
        if ( $preselect_team > 0 ) {
            $player_options = array_values( array_filter(
                $rows,
                static function ( $r ) use ( $preselect_team ) {
                    return (int) $r['team_id'] === $preselect_team;
                }
            ) );
        }

        ob_start();
        ?>
        <div class="tt-field tt-psp tt-psp-dropdown" data-tt-psp data-instance="<?php echo esc_attr( $instance ); ?>">
            <label class="tt-field-label<?php echo $required ? ' tt-field-required' : ''; ?>" for="<?php echo esc_attr( $instance . '-select' ); ?>">
                <?php echo esc_html( $label ); ?>
            </label>

            <?php if ( $show_team && ! empty( $teams_for_filter ) ) : ?>
                <select class="tt-input tt-psp-team-filter" data-tt-psp-team-filter
                        aria-label="<?php esc_attr_e( 'Filter by team', 'talenttrack' ); ?>">
                    <?php if ( ! $single_team ) : ?>
                        <option value="0"><?php esc_html_e( 'All teams', 'talenttrack' ); ?></option>
                    <?php endif; ?>
                    <?php foreach ( $teams_for_filter as $tid => $tname ) : ?>
                        <option value="<?php echo (int) $tid; ?>" <?php selected( $preselect_team, (int) $tid ); ?>>
                            <?php echo esc_html( $tname ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <select class="tt-input tt-psp-select" id="<?php echo esc_attr( $instance . '-select' ); ?>"
                    name="<?php echo esc_attr( $name ); ?>" data-tt-psp-select
                    <?php echo $required ? 'required' : ''; ?>>
                <option value=""><?php esc_html_e( '— Choose player —', 'talenttrack' ); ?></option>
                <?php foreach ( $player_options as $r ) : ?>
                    <option value="<?php echo (int) $r['id']; ?>" <?php selected( $selected, (int) $r['id'] ); ?>>
                        <?php echo esc_html( $r['label'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <script type="application/json" class="tt-psp-data" data-tt-psp-data>
                <?php echo wp_json_encode( $rows ); ?>
            </script>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build a {team_id: team_name} map from the candidate player rows so
     * the filter dropdown only lists teams that actually have pickable
     * players. Sorted alphabetically.
     *
     * @param object[] $players
     * @return array<int, string>
     */
    private static function teamsForFilter( array $players ): array {
        $out = [];
        foreach ( $players as $pl ) {
            $tid = (int) ( $pl->team_id ?? 0 );
            if ( $tid <= 0 || isset( $out[ $tid ] ) ) continue;
            $t = QueryHelpers::get_team( $tid );
            if ( $t ) $out[ $tid ] = (string) $t->name;
        }
        asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
        return $out;
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
     * accepts an optional team filter, plus a `cross_team` flag that
     * widens scope to every player in the academy (used by the
     * #0026 / #0037 guest-attendance picker so a coach can pick a
     * guest from a team they don't manage).
     *
     * @return array<int, object>
     */
    private static function resolvePlayers( int $user_id, bool $is_admin, int $team_id, bool $cross_team = false, int $exclude_team_id = 0 ): array {
        if ( $team_id > 0 ) {
            return QueryHelpers::get_players( $team_id );
        }
        if ( $is_admin || $cross_team ) {
            $rows = QueryHelpers::get_players();
            if ( $exclude_team_id > 0 ) {
                $rows = array_values( array_filter( $rows, static function ( $pl ) use ( $exclude_team_id ) {
                    return (int) ( $pl->team_id ?? 0 ) !== $exclude_team_id;
                } ) );
            }
            return $rows;
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
