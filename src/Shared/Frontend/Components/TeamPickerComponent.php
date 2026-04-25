<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * TeamPickerComponent — dropdown for picking a team.
 *
 * Mirrors `PlayerPickerComponent` but for teams. Kept as its own
 * class (Sprint 2 plan Q3) because team scoping rules differ from
 * player scoping: admin sees all teams; a non-admin coach sees only
 * teams they head-coach. HoD scoping (age-group teams) is a future
 * sprint concern; for now the non-admin path mirrors the existing
 * frontend.
 *
 * Usage:
 *
 *   TeamPickerComponent::render([
 *     'name'     => 'team_id',
 *     'label'    => __( 'Team', 'talenttrack' ),
 *     'required' => true,
 *     'user_id'  => get_current_user_id(),
 *     'is_admin' => current_user_can( 'tt_edit_settings' ),
 *     'selected' => $session ? (int) $session->team_id : 0,
 *   ]);
 */
class TeamPickerComponent {

    /**
     * @param array{name?:string, label?:string, required?:bool, user_id?:int, is_admin?:bool, teams?:array<int,object>, selected?:int, placeholder?:string} $args
     */
    public static function render( array $args = [] ): string {
        $name        = (string) ( $args['name'] ?? 'team_id' );
        $label       = (string) ( $args['label'] ?? __( 'Team', 'talenttrack' ) );
        $required    = ! empty( $args['required'] );
        $placeholder = (string) ( $args['placeholder'] ?? __( '— Select —', 'talenttrack' ) );
        $selected    = (int) ( $args['selected'] ?? 0 );

        /** @var array<int, object> $teams */
        $teams = $args['teams'] ?? self::resolveTeams(
            (int) ( $args['user_id'] ?? get_current_user_id() ),
            (bool) ( $args['is_admin'] ?? false )
        );

        $out  = '<div class="tt-field">';
        $out .= '<label class="tt-field-label' . ( $required ? ' tt-field-required' : '' ) . '" for="' . esc_attr( $name ) . '">';
        $out .= esc_html( $label );
        $out .= '</label>';
        $out .= '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="tt-input"' . ( $required ? ' required' : '' ) . '>';
        $out .= '<option value="">' . esc_html( $placeholder ) . '</option>';
        foreach ( $teams as $t ) {
            $id = (int) $t->id;
            $out .= '<option value="' . esc_attr( (string) $id ) . '"' . ( $selected === $id ? ' selected' : '' ) . '>';
            $out .= esc_html( (string) $t->name );
            $out .= '</option>';
        }
        $out .= '</select>';
        $out .= '</div>';
        return $out;
    }

    /**
     * @return array<int, object>
     */
    public static function resolveTeams( int $user_id, bool $is_admin ): array {
        return $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
    }

    /**
     * Convenience for use as a `FrontendListTable` filter:
     *
     *   'team_id' => [
     *     'type'    => 'select',
     *     'label'   => __( 'Team', 'talenttrack' ),
     *     'options' => TeamPickerComponent::filterOptions( $user_id, $is_admin ),
     *   ]
     *
     * @return array<int, string>
     */
    public static function filterOptions( int $user_id, bool $is_admin ): array {
        $out = [];
        foreach ( self::resolveTeams( $user_id, $is_admin ) as $t ) {
            $out[ (int) $t->id ] = (string) $t->name;
        }
        return $out;
    }
}
