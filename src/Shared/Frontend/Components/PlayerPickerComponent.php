<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * PlayerPickerComponent — dropdown for picking a player.
 *
 * Respects team-scoping for non-admin coaches: a coach only sees
 * players on the teams they own. Admins see every player. The caller
 * can also pass an explicit `players` array to override scope (e.g.
 * the teammate picker only shows the current player's team).
 *
 * Usage:
 *
 *     PlayerPickerComponent::render([
 *         'name'     => 'player_id',
 *         'label'    => __( 'Player', 'talenttrack' ),
 *         'required' => true,
 *         'user_id'  => get_current_user_id(),
 *         'is_admin' => current_user_can( 'tt_edit_settings' ),
 *     ]);
 */
class PlayerPickerComponent {

    /**
     * @param array{name?:string, label?:string, required?:bool, user_id?:int, is_admin?:bool, players?:array<int,object>, selected?:int, placeholder?:string, cross_team?:bool, exclude_team_id?:int} $args
     */
    public static function render( array $args = [] ): string {
        $name        = (string) ( $args['name'] ?? 'player_id' );
        $label       = (string) ( $args['label'] ?? __( 'Player', 'talenttrack' ) );
        $required    = ! empty( $args['required'] );
        $placeholder = (string) ( $args['placeholder'] ?? __( '— Select —', 'talenttrack' ) );
        $selected    = (int) ( $args['selected'] ?? 0 );
        $cross_team  = ! empty( $args['cross_team'] );
        $exclude     = (int) ( $args['exclude_team_id'] ?? 0 );

        /** @var array<int, object> $players */
        $players = $args['players'] ?? self::resolvePlayers(
            (int) ( $args['user_id'] ?? get_current_user_id() ),
            (bool) ( $args['is_admin'] ?? false ),
            $cross_team,
            $exclude
        );

        $out  = '<div class="tt-field tt-player-picker">';
        $out .= '<label class="tt-field-label' . ( $required ? ' tt-field-required' : '' ) . '" for="' . esc_attr( $name ) . '">';
        $out .= esc_html( $label );
        $out .= '</label>';
        $out .= '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="tt-input"' . ( $required ? ' required' : '' ) . '>';
        $out .= '<option value="">' . esc_html( $placeholder ) . '</option>';
        foreach ( $players as $pl ) {
            $id = (int) $pl->id;
            $out .= '<option value="' . esc_attr( (string) $id ) . '"' . ( $selected === $id ? ' selected' : '' ) . '>';
            $out .= esc_html( QueryHelpers::player_display_name( $pl ) );
            $out .= '</option>';
        }
        $out .= '</select>';
        $out .= '</div>';
        return $out;
    }

    /**
     * Resolve the player list for the dropdown.
     *
     * - `cross_team = true` returns the union of every team's roster the
     *   user has any access to (admin → everyone; coach → all teams,
     *   not just the ones they head-coach). Used by the #0026
     *   linked-guest picker so a coach can pick a player from another
     *   team to attend their session.
     * - `exclude_team_id` drops players already on that team — keeps
     *   the linked-guest list to actual cross-team picks.
     *
     * @return array<int, object>
     */
    private static function resolvePlayers( int $user_id, bool $is_admin, bool $cross_team = false, int $exclude_team_id = 0 ): array {
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
