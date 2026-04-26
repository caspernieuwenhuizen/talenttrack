<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * GoalSettingForm — three goals + optional notes per goal. The form
 * persists the goals to tt_workflow_tasks.response_json. The follow-up
 * GoalApproval task reads them from there. Eventually (Phase 2) the
 * approved goals get materialised as tt_goals rows; for v1 they live
 * inside the workflow response.
 */
class GoalSettingForm implements FormInterface {

    /** Maximum number of goals one task can capture. Three is the spec default. */
    public const MAX_GOALS = 3;

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $goals = is_array( $existing['goals'] ?? null ) ? $existing['goals'] : [];

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <p style="margin: 0 0 14px; color:#5b6e75;">
                <?php esc_html_e( 'Set up to three goals for the next quarter. Your coach reviews them after you submit.', 'talenttrack' ); ?>
            </p>
            <?php for ( $i = 0; $i < self::MAX_GOALS; $i++ ) :
                $g = $goals[ $i ] ?? [];
                $title = (string) ( $g['title'] ?? '' );
                $why   = (string) ( $g['why'] ?? '' );
                ?>
                <fieldset style="border: 1px solid #e5e7ea; border-radius: 6px; padding: 12px; margin-bottom: 12px;">
                    <legend style="font-weight: 600; padding: 0 6px; color: #1a1d21;">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %d: goal index (1, 2, 3) */
                            __( 'Goal %d', 'talenttrack' ),
                            $i + 1
                        ) );
                        ?>
                    </legend>
                    <p style="margin: 0 0 6px;">
                        <label for="tt-goal-<?php echo esc_attr( (string) $i ); ?>-title" style="font-weight: 600;">
                            <?php esc_html_e( 'Goal', 'talenttrack' ); ?>
                        </label>
                    </p>
                    <p>
                        <input type="text"
                               id="tt-goal-<?php echo esc_attr( (string) $i ); ?>-title"
                               name="goals[<?php echo esc_attr( (string) $i ); ?>][title]"
                               value="<?php echo esc_attr( $title ); ?>"
                               <?php echo self::completedAttr( $task ); ?>
                               style="width: 100%;" />
                    </p>
                    <p style="margin: 8px 0 6px;">
                        <label for="tt-goal-<?php echo esc_attr( (string) $i ); ?>-why" style="font-weight: 600;">
                            <?php esc_html_e( 'Why this matters / how I\'ll work on it', 'talenttrack' ); ?>
                        </label>
                    </p>
                    <p>
                        <textarea id="tt-goal-<?php echo esc_attr( (string) $i ); ?>-why"
                                  name="goals[<?php echo esc_attr( (string) $i ); ?>][why]"
                                  rows="2" style="width: 100%;"
                                  <?php echo self::completedAttr( $task ); ?>><?php
                            echo esc_textarea( $why );
                        ?></textarea>
                    </p>
                </fieldset>
            <?php endfor; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];
        $goals = is_array( $raw['goals'] ?? null ) ? $raw['goals'] : [];
        $any_filled = false;
        foreach ( $goals as $idx => $goal ) {
            if ( ! is_array( $goal ) ) continue;
            $title = trim( (string) ( $goal['title'] ?? '' ) );
            if ( $title !== '' ) $any_filled = true;
        }
        if ( ! $any_filled ) {
            $errors['__form'] = __( 'Set at least one goal before submitting.', 'talenttrack' );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $goals = [];
        if ( is_array( $raw['goals'] ?? null ) ) {
            foreach ( $raw['goals'] as $goal ) {
                if ( ! is_array( $goal ) ) continue;
                $title = trim( (string) ( $goal['title'] ?? '' ) );
                if ( $title === '' ) continue;
                $goals[] = [
                    'title' => sanitize_text_field( $title ),
                    'why'   => sanitize_textarea_field( (string) ( $goal['why'] ?? '' ) ),
                ];
                if ( count( $goals ) >= self::MAX_GOALS ) break;
            }
        }
        return [ 'goals' => $goals ];
    }

    /** @param array<string,mixed> $task */
    private static function decodeResponse( array $task ): array {
        $raw = (string) ( $task['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $task */
    private static function completedAttr( array $task ): string {
        return ( (string) ( $task['status'] ?? '' ) ) === 'completed' ? 'disabled' : '';
    }
}
