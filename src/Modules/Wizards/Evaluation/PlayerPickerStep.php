<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;
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
        // #2254 — only applies once the PLAYER branch is chosen; unset
        // (still on the mode step) or the activity branch keeps it out of
        // the rail. The mode step sets `_path` before nav advances, so the
        // picker is still reached on the player branch.
        return ( $state['_path'] ?? '' ) !== 'player-first';
    }

    public function render( array $state ): void {
        $current = (int) ( $state['player_id'] ?? 0 );
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( 'Pick the player you\'re evaluating. Use this for ad-hoc observations not anchored to an activity row — a tournament moment, something you noticed in passing.', 'talenttrack' ); ?>
        </p>
        <?php
        // #2567 — ask the question this actually means: does the user hold
        // player-read authority across the whole academy?
        //
        // v3.110.193 (#809, #810) tried to fix "head coaches see every team"
        // by gating on `tt_access_frontend_admin`, reasoning that admin +
        // club_admin + head_dev all hold it. True, but not exclusive: the
        // matrix seeds `frontend_admin [r, global]` to both coach personas
        // too, so the cap is true for every coach and the fix never took
        // effect. See #2569 for the cap itself.
        //
        // `players [read, global]` is seeded to head_of_development and
        // academy_admin; both coach personas hold `players [r, team]`, so
        // they fall through to `get_teams_for_coach()` — which is the
        // cascading team-then-player UX the component already implements.
        $can_cross_team = MatrixGate::can(
            get_current_user_id(),
            'players',
            MatrixGate::READ,
            MatrixGate::SCOPE_GLOBAL
        );
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
