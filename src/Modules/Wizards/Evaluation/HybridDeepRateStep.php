<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * HybridDeepRateStep (#0072) — player-first deep-rating form. Mirrors
 * the activity flow's deep-rate panel but injects the activity-context
 * fields the activity flow gets for free: date, setting, and a
 * free-text reason / context.
 */
final class HybridDeepRateStep implements WizardStepInterface {

    public function slug(): string  { return 'hybrid-deep-rate'; }
    public function label(): string { return __( 'Rating', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        return ( $state['_path'] ?? '' ) !== 'player-first';
    }

    public function render( array $state ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $cats = $wpdb->get_results(
            "SELECT id, label FROM {$p}tt_eval_categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY display_order, label"
        );
        $max = (int) ( get_option( 'tt_rating_scale_max', 5 ) ?: 5 );

        $settings = $wpdb->get_results( $wpdb->prepare(
            "SELECT name FROM {$p}tt_lookups WHERE lookup_type = 'evaluation_setting' AND club_id = %d ORDER BY sort_order",
            CurrentClub::id()
        ) );
        $setting_names = array_map( static fn( $r ) => (string) $r->name, (array) $settings );
        if ( empty( $setting_names ) ) {
            // Fallback when the lookup table is empty. Wrapping in __() so a
            // Dutch install on a fresh seed sees Dutch in this dropdown.
            $setting_names = [
                __( 'training',    'talenttrack' ),
                __( 'match',       'talenttrack' ),
                __( 'tournament',  'talenttrack' ),
                __( 'observation', 'talenttrack' ),
                __( 'other',       'talenttrack' ),
            ];
        }

        $date_val    = (string) ( $state['eval_date'] ?? gmdate( 'Y-m-d' ) );
        $setting_val = (string) ( $state['eval_setting'] ?? '' );
        $reason_val  = (string) ( $state['eval_reason'] ?? '' );
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( 'Add the activity context this evaluation is anchored to, then rate the categories that matter.', 'talenttrack' ); ?>
        </p>

        <table style="width:100%;max-width:640px;">
            <tbody>
                <tr><th style="text-align:left;font-weight:normal;width:160px;"><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                    <td><input type="date" name="eval_date" value="<?php echo esc_attr( $date_val ); ?>" /></td>
                </tr>
                <tr><th style="text-align:left;font-weight:normal;"><?php esc_html_e( 'Setting', 'talenttrack' ); ?></th>
                    <td>
                        <select name="eval_setting">
                            <option value=""><?php esc_html_e( '— pick a setting —', 'talenttrack' ); ?></option>
                            <?php foreach ( $setting_names as $n ) : ?>
                                <option value="<?php echo esc_attr( $n ); ?>" <?php selected( $setting_val, $n ); ?>><?php echo esc_html( \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'evaluation_setting', (string) $n ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr><th style="text-align:left;font-weight:normal;vertical-align:top;"><?php esc_html_e( 'Context', 'talenttrack' ); ?></th>
                    <td><textarea rows="3" maxlength="500" name="eval_reason" style="width:100%;"><?php echo esc_textarea( $reason_val ); ?></textarea></td>
                </tr>

                <?php foreach ( (array) $cats as $cat ) :
                    $val = (int) ( $state['ratings_self'][ (int) $cat->id ] ?? 0 );
                    ?>
                    <tr>
                        <th style="text-align:left;font-weight:normal;"><?php echo esc_html( \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->label ) ); ?></th>
                        <td>
                            <input type="number" min="0" max="<?php echo (int) $max; ?>" step="1" inputmode="numeric"
                                name="ratings_self[<?php echo (int) $cat->id; ?>]"
                                value="<?php echo $val > 0 ? (int) $val : ''; ?>"
                                style="width:60px;" />
                            <span style="color:var(--tt-muted);font-size:13px;">/ <?php echo (int) $max; ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function validate( array $post, array $state ) {
        $date    = isset( $post['eval_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['eval_date'] ) ) : '';
        $setting = isset( $post['eval_setting'] ) ? sanitize_key( (string) $post['eval_setting'] ) : '';
        $reason  = isset( $post['eval_reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $post['eval_reason'] ) ) : '';

        $ratings_raw = isset( $post['ratings_self'] ) && is_array( $post['ratings_self'] ) ? $post['ratings_self'] : [];
        $clean = [];
        foreach ( $ratings_raw as $cid => $v ) {
            $v = (int) $v;
            if ( $v <= 0 ) continue;
            $clean[ (int) $cid ] = $v;
        }
        return [
            'eval_date'    => $date,
            'eval_setting' => $setting,
            'eval_reason'  => $reason,
            'ratings_self' => $clean,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
