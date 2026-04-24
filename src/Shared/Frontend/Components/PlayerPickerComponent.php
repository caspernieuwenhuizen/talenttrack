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
     * @param array{name?:string, label?:string, required?:bool, user_id?:int, is_admin?:bool, players?:array<int,object>, selected?:int, placeholder?:string} $args
     */
    public static function render( array $args = [] ): string {
        $name        = (string) ( $args['name'] ?? 'player_id' );
        $label       = (string) ( $args['label'] ?? __( 'Player', 'talenttrack' ) );
        $required    = ! empty( $args['required'] );
        $placeholder = (string) ( $args['placeholder'] ?? __( '— Select —', 'talenttrack' ) );
        $selected    = (int) ( $args['selected'] ?? 0 );

        /** @var array<int, object> $players */
        $players = $args['players'] ?? self::resolvePlayers( (int) ( $args['user_id'] ?? get_current_user_id() ), (bool) ( $args['is_admin'] ?? false ) );

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
     * @return array<int, object>
     */
    private static function resolvePlayers( int $user_id, bool $is_admin ): array {
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
