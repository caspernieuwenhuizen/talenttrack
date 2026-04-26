<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\FormInterface;
use TT\Modules\Workflow\Repositories\TasksRepository;

/**
 * GoalApprovalForm — coach reviews the goals their player submitted in
 * the parent goal-setting task. For each goal, the coach sets a
 * decision (approve / amend / reject) and an optional note.
 *
 * Reads the parent task's response_json via parent_task_id; that's the
 * source of truth for the goals being reviewed.
 */
class GoalApprovalForm implements FormInterface {

    public const DECISION_APPROVE = 'approve';
    public const DECISION_AMEND   = 'amend';
    public const DECISION_REJECT  = 'reject';

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $existing_decisions = is_array( $existing['decisions'] ?? null ) ? $existing['decisions'] : [];
        $goals = self::loadParentGoals( $task );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <?php if ( empty( $goals ) ) : ?>
                <p><em><?php esc_html_e( 'No goals were submitted in the parent task.', 'talenttrack' ); ?></em></p>
            <?php else : foreach ( $goals as $idx => $goal ) :
                $decision = (string) ( $existing_decisions[ $idx ]['decision'] ?? '' );
                $note     = (string) ( $existing_decisions[ $idx ]['note'] ?? '' );
                ?>
                <fieldset style="border: 1px solid #e5e7ea; border-radius: 6px; padding: 12px; margin-bottom: 12px;">
                    <legend style="font-weight: 600; padding: 0 6px; color: #1a1d21;">
                        <?php echo esc_html( sprintf( __( 'Goal %d', 'talenttrack' ), $idx + 1 ) ); ?>
                    </legend>
                    <p style="margin: 0 0 6px; font-weight: 600;">
                        <?php echo esc_html( (string) ( $goal['title'] ?? '' ) ); ?>
                    </p>
                    <?php if ( ! empty( $goal['why'] ) ) : ?>
                        <p style="margin: 0 0 12px; color:#5b6e75; font-size: 13px;">
                            <?php echo esc_html( (string) $goal['why'] ); ?>
                        </p>
                    <?php endif; ?>

                    <p style="margin: 0 0 4px; font-weight: 600;"><?php esc_html_e( 'Decision', 'talenttrack' ); ?></p>
                    <p>
                        <?php foreach ( [
                            self::DECISION_APPROVE => __( 'Approve', 'talenttrack' ),
                            self::DECISION_AMEND   => __( 'Approve with amendment', 'talenttrack' ),
                            self::DECISION_REJECT  => __( 'Reject', 'talenttrack' ),
                        ] as $value => $label ) : ?>
                            <label style="margin-right: 14px;">
                                <input type="radio"
                                       name="decisions[<?php echo esc_attr( (string) $idx ); ?>][decision]"
                                       value="<?php echo esc_attr( $value ); ?>"
                                       <?php checked( $decision, $value ); ?>
                                       <?php echo self::completedAttr( $task ); ?> />
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </p>

                    <p style="margin: 8px 0 4px;">
                        <label style="font-weight: 600;">
                            <?php esc_html_e( 'Note (optional)', 'talenttrack' ); ?>
                        </label>
                    </p>
                    <p>
                        <textarea name="decisions[<?php echo esc_attr( (string) $idx ); ?>][note]"
                                  rows="2" style="width: 100%;"
                                  <?php echo self::completedAttr( $task ); ?>><?php
                            echo esc_textarea( $note );
                        ?></textarea>
                    </p>
                </fieldset>
            <?php endforeach; endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];
        $decisions = is_array( $raw['decisions'] ?? null ) ? $raw['decisions'] : [];
        $goals = self::loadParentGoals( $task );

        foreach ( $goals as $idx => $_ ) {
            $decision = (string) ( $decisions[ $idx ]['decision'] ?? '' );
            if ( $decision === '' ) {
                $errors['__form'] = __( 'Pick a decision for every goal.', 'talenttrack' );
                break;
            }
            if ( ! in_array( $decision, [ self::DECISION_APPROVE, self::DECISION_AMEND, self::DECISION_REJECT ], true ) ) {
                $errors['__form'] = __( 'Invalid decision value.', 'talenttrack' );
                break;
            }
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $out = [];
        $decisions = is_array( $raw['decisions'] ?? null ) ? $raw['decisions'] : [];
        foreach ( $decisions as $idx => $row ) {
            if ( ! is_array( $row ) ) continue;
            $out[ (int) $idx ] = [
                'decision' => sanitize_key( (string) ( $row['decision'] ?? '' ) ),
                'note'     => sanitize_textarea_field( (string) ( $row['note'] ?? '' ) ),
            ];
        }
        ksort( $out );
        return [ 'decisions' => array_values( $out ) ];
    }

    /**
     * Load the goals from the parent task's response_json. Returns an
     * empty array when there's no parent or the parent has no goals
     * (defensive — the chain should always populate this, but a manual
     * dispatch could in principle skip it).
     *
     * @param array<string,mixed> $task
     * @return array<int, array<string,string>>
     */
    private static function loadParentGoals( array $task ): array {
        $parent_id = (int) ( $task['parent_task_id'] ?? 0 );
        if ( $parent_id <= 0 ) return [];
        $parent = ( new TasksRepository() )->find( $parent_id );
        if ( $parent === null ) return [];
        $raw = (string) ( $parent['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $goals = $decoded['goals'] ?? [];
        return is_array( $goals ) ? array_values( $goals ) : [];
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
