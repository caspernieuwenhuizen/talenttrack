<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * EvaluationModeStep (#2246) — the explicit two-way fork that fronts the
 * unified evaluation wizard.
 *
 * The pre-#2246 wizard was activity-first with an implicit player
 * fallback: it landed on ActivityPickerStep and only auto-skipped to
 * PlayerPickerStep when the coach had no rateable activities, with a
 * small "→ Rate a player directly" escape-hatch link. That hid the
 * fork between "rate an activity's players" and "rate one player
 * standalone" inside a smart-default.
 *
 * This step surfaces the fork as two big buttons:
 *
 *   - "Evaluate an activity" → mode=activity → ActivityPickerStep
 *   - "Evaluate 1 player"    → mode=player   → PlayerPickerStep
 *
 * The choice writes `mode = 'activity' | 'player'` into wizard state
 * and mirrors it onto the legacy `_path` key (`activity-first` /
 * `player-first`) so the downstream steps' existing skip logic keys
 * off a single source of truth without a wholesale rewrite.
 *
 * Auto-skipped (`notApplicableFor`) when a caller seeds `mode` up
 * front — the dashboard hero, the activity-completion doors, and the
 * `mark-attendance` alias all deep-link with `mode=activity` +
 * `activity_id`, so they never see this step.
 */
final class EvaluationModeStep implements WizardStepInterface {

    public function slug(): string  { return 'evaluation-mode'; }
    public function label(): string { return __( 'What are you evaluating?', 'talenttrack' ); }

    /**
     * Skip when a caller already seeded the mode (activity-completion
     * door, dashboard hero, mark-attendance alias). The mode is carried
     * either explicitly as `mode` or implicitly via the legacy `_path`
     * seed the alias sets.
     */
    public function notApplicableFor( array $state ): bool {
        $mode = (string) ( $state['mode'] ?? '' );
        if ( $mode === 'activity' || $mode === 'player' ) return true;
        $path = (string) ( $state['_path'] ?? '' );
        return $path === 'activity-first' || $path === 'player-first';
    }

    public function render( array $state ): void {
        if ( defined( 'TT_PLUGIN_URL' ) && defined( 'TT_VERSION' ) ) {
            wp_enqueue_style( 'tt-evaluation-mode', TT_PLUGIN_URL . 'assets/css/evaluation-mode.css', [], TT_VERSION );
        }
        ?>
        <p class="tt-eval-mode-intro">
            <?php esc_html_e( 'Pick how you want to evaluate. You can switch back with Previous.', 'talenttrack' ); ?>
        </p>
        <div class="tt-eval-mode-choices" role="group" aria-label="<?php esc_attr_e( 'Evaluation mode', 'talenttrack' ); ?>">
            <button type="submit" name="mode" value="activity" class="tt-eval-mode-btn">
                <span class="tt-eval-mode-btn__title"><?php esc_html_e( 'Evaluate an activity', 'talenttrack' ); ?></span>
                <span class="tt-eval-mode-btn__desc"><?php esc_html_e( 'Rate the players who took part in a training or match.', 'talenttrack' ); ?></span>
            </button>
            <button type="submit" name="mode" value="player" class="tt-eval-mode-btn">
                <span class="tt-eval-mode-btn__title"><?php esc_html_e( 'Evaluate 1 player', 'talenttrack' ); ?></span>
                <span class="tt-eval-mode-btn__desc"><?php esc_html_e( 'A standalone assessment outside a registered activity.', 'talenttrack' ); ?></span>
            </button>
        </div>
        <?php
    }

    public function validate( array $post, array $state ) {
        $mode = isset( $post['mode'] ) ? sanitize_key( (string) $post['mode'] ) : '';
        if ( $mode !== 'activity' && $mode !== 'player' ) {
            return new \WP_Error( 'no_mode', __( 'Pick whether you are evaluating an activity or a single player.', 'talenttrack' ) );
        }
        // Mirror onto the legacy `_path` key so downstream steps'
        // existing skip logic (keyed on `_path`) keeps working.
        return [
            'mode'  => $mode,
            '_path' => $mode === 'activity' ? 'activity-first' : 'player-first',
        ];
    }

    public function nextStep( array $state ): ?string {
        $mode = (string) ( $state['mode'] ?? '' );
        if ( $mode === 'player' ) return 'player-picker';
        return 'activity-picker';
    }

    public function submit( array $state ) { return null; }
}
