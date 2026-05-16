<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * PlayerSelfEvaluationForm — three-question reflection form completed
 * by the player (or parent, depending on minors policy): how did the
 * week feel, what went well, what to work on next week.
 *
 * Same shape as PostGameEvaluationForm, intentionally — the visual
 * language is identical so players + coaches recognise the pattern
 * across both tasks.
 */
class PlayerSelfEvaluationForm implements FormInterface {

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $rating_max = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $rating_min = (float) QueryHelpers::get_config( 'rating_min', '5' );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <p style="margin: 0 0 14px; color:#5b6e75;">
                <?php esc_html_e( 'Quick weekly check-in. Be honest — this is for your development.', 'talenttrack' ); ?>
            </p>

            <p style="margin-bottom: 6px;">
                <label for="tt-self-rating" style="font-weight: 600;">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: min rating, 2: max rating */
                        __( 'How was this week? (%1$s–%2$s)', 'talenttrack' ),
                        rtrim( rtrim( number_format( $rating_min, 1 ), '0' ), '.' ),
                        rtrim( rtrim( number_format( $rating_max, 1 ), '0' ), '.' )
                    ) );
                    ?>
                </label>
            </p>
            <p>
                <input type="number" id="tt-self-rating" name="overall_rating"
                       step="0.5"
                       min="<?php echo esc_attr( (string) $rating_min ); ?>"
                       max="<?php echo esc_attr( (string) $rating_max ); ?>"
                       value="<?php echo esc_attr( (string) ( $existing['overall_rating'] ?? '' ) ); ?>"
                       <?php echo self::completedAttr( $task ); ?>
                       style="width: 100px;" />
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-self-well" style="font-weight: 600;"><?php esc_html_e( 'What went well this week', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-self-well" name="went_well" rows="3" style="width: 100%;"
                          <?php echo self::completedAttr( $task ); ?>><?php
                    echo esc_textarea( (string) ( $existing['went_well'] ?? '' ) );
                ?></textarea>
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-self-work" style="font-weight: 600;"><?php esc_html_e( 'What to work on next week', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-self-work" name="to_work_on" rows="3" style="width: 100%;"
                          <?php echo self::completedAttr( $task ); ?>><?php
                    echo esc_textarea( (string) ( $existing['to_work_on'] ?? '' ) );
                ?></textarea>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];
        $rating_min = (float) QueryHelpers::get_config( 'rating_min', '5' );
        $rating_max = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $rating = isset( $raw['overall_rating'] ) ? (float) $raw['overall_rating'] : 0.0;
        if ( $rating < $rating_min || $rating > $rating_max ) {
            $errors['overall_rating'] = __( 'Rating is outside the configured range.', 'talenttrack' );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        return [
            'overall_rating' => isset( $raw['overall_rating'] ) ? (float) $raw['overall_rating'] : null,
            'went_well'      => isset( $raw['went_well'] ) ? sanitize_textarea_field( (string) $raw['went_well'] ) : '',
            'to_work_on'     => isset( $raw['to_work_on'] ) ? sanitize_textarea_field( (string) $raw['to_work_on'] ) : '',
        ];
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
