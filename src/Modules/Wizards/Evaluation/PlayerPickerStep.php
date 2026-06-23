<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * PlayerPickerStep (#0072) — entry to the player-first ad-hoc path.
 * Replaces today's `PlayerStep`. Reuses the existing
 * `PlayerSearchPickerComponent` so clubs with 200+ players have search;
 * smaller clubs get the dropdown fallback the component already
 * handles.
 */
final class PlayerPickerStep implements WizardStepInterface {

    public function slug(): string  { return 'player-picker'; }
    public function label(): string { return __( 'Player', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        return ( $state['_path'] ?? '' ) === 'activity-first';
    }

    public function render( array $state ): void {
        $current = (int) ( $state['player_id'] ?? 0 );
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( 'Pick the player you\'re evaluating. Use this for ad-hoc observations not anchored to an activity row — a tournament moment, something you noticed in passing.', 'talenttrack' ); ?>
        </p>
        <?php
        // v3.110.193 (#809, #810) — was passing `cross_team => true`
        // and a narrow `is_admin` (only `tt_edit_settings`). Result:
        // head coaches saw every team in the picker, not just the ones
        // they coach. The component already has the cascading
        // team-then-player UX; `cross_team => true` was overriding it
        // by forcing all teams' players into the candidate set. Drop
        // `cross_team` and treat `tt_access_frontend_admin` as the
        // "is admin / HoD" gate (admin + tt_club_admin + tt_head_dev
        // all hold it). Result: head coaches see only their assigned
        // teams via `get_teams_for_coach()`; admin / HoD keep full
        // visibility.
        $can_cross_team = current_user_can( 'tt_edit_settings' )
            || current_user_can( 'tt_access_frontend_admin' );
        echo PlayerSearchPickerComponent::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'name'             => 'player_id',
            'label'            => __( 'Which player?', 'talenttrack' ),
            'required'         => true,
            'user_id'          => get_current_user_id(),
            'is_admin'         => $can_cross_team,
            'selected'         => $current,
            'show_team_filter' => true,
            // #1731 — team-scoped player dropdown (pre-selected when the
            // coach manages a single team) instead of type-to-search, so
            // the player list is visible without typing.
            'style'            => 'dropdown',
        ] );
    }

    public function validate( array $post, array $state ) {
        $pid = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
        if ( $pid <= 0 ) return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        return [ 'player_id' => $pid, '_path' => 'player-first' ];
    }

    public function nextStep( array $state ): ?string { return 'hybrid-deep-rate'; }
    public function submit( array $state ) { return null; }
}
