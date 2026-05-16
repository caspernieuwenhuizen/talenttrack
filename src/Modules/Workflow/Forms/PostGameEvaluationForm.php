<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * PostGameEvaluationForm — three-question reflection form: overall
 * rating, what went well, what to work on. Lightweight on purpose —
 * coaches can still go to the full evaluation flow for the per-category
 * rating set; this is the deadline-bound nudge to capture the gist.
 *
 * The response is stored in tt_workflow_tasks.response_json; turning
 * it into a tt_evaluations row is a Phase 2 polish (the post-task
 * action would create an evaluation with an `eval_type=match` tag,
 * letting the eval show up in the player's history).
 */
class PostGameEvaluationForm implements FormInterface {

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $rating_max = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $rating_min = (float) QueryHelpers::get_config( 'rating_min', '5' );

        $player_label = self::playerLabel( (int) ( $task['player_id'] ?? 0 ) );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <?php if ( $player_label !== '' ) : ?>
                <p style="margin: 0 0 14px; font-weight: 600;">
                    <?php echo esc_html( sprintf( __( 'Player: %s', 'talenttrack' ), $player_label ) ); ?>
                </p>
            <?php endif; ?>

            <p style="margin-bottom: 6px;">
                <label for="tt-pm-rating" style="font-weight: 600;">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: min rating, 2: max rating */
                        __( 'Overall rating (%1$s–%2$s)', 'talenttrack' ),
                        rtrim( rtrim( number_format( $rating_min, 1 ), '0' ), '.' ),
                        rtrim( rtrim( number_format( $rating_max, 1 ), '0' ), '.' )
                    ) );
                    ?>
                </label>
            </p>
            <p>
                <input type="number" id="tt-pm-rating" name="overall_rating"
                       step="0.5"
                       min="<?php echo esc_attr( (string) $rating_min ); ?>"
                       max="<?php echo esc_attr( (string) $rating_max ); ?>"
                       value="<?php echo esc_attr( (string) ( $existing['overall_rating'] ?? '' ) ); ?>"
                       <?php echo self::completedAttr( $task ); ?>
                       style="width: 100px;" />
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-pm-well" style="font-weight: 600;"><?php esc_html_e( 'What went well', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-pm-well" name="went_well" rows="3" style="width: 100%;"
                          <?php echo self::completedAttr( $task ); ?>><?php
                    echo esc_textarea( (string) ( $existing['went_well'] ?? '' ) );
                ?></textarea>
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-pm-work" style="font-weight: 600;"><?php esc_html_e( 'What to work on', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-pm-work" name="to_work_on" rows="3" style="width: 100%;"
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

    private static function playerLabel( int $player_id ): string {
        if ( $player_id <= 0 ) return '';
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
            $player_id, CurrentClub::id()
        ) );
        if ( ! $row ) return '';
        return trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) );
    }
}
