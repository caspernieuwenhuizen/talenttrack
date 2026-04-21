<?php
namespace TT\Modules\Evaluations\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\CategoryWeightsRepository;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * CategoryWeightsPage — manages weighted overall rating config.
 *
 * Sprint v2.13.0. TalentTrack → Category Weights. One section per active
 * age group, each containing four weight inputs (one per main category)
 * that must sum to 100% on save.
 *
 * Age groups without a configured weight set use equal-weights fallback
 * at compute time — the admin sees "Not configured (equal fallback in
 * use)" as status and can configure on demand. Nothing breaks if weights
 * are never configured.
 *
 * Routes under admin.php?page=tt-category-weights:
 *   - default          → render all age groups with current weights
 * Handlers:
 *   - tt_save_category_weights   → persist one age group's weights
 *   - tt_reset_category_weights  → reset one age group to equal (deletes rows)
 */
class CategoryWeightsPage {

    private const CAP = 'tt_manage_settings';

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $cats_repo    = new EvalCategoriesRepository();
        $weights_repo = new CategoryWeightsRepository();

        $mains     = $cats_repo->getMainCategories( true );
        $age_groups = QueryHelpers::get_lookups( 'age_group' );

        $all_weights = $weights_repo->getForAgeGroups( array_map( fn( $ag ) => (int) $ag->id, $age_groups ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Category Weights', 'talenttrack' ); ?> <?php \TT\Shared\Admin\HelpLink::render( 'eval-categories-weights' ); ?></h1>

            <?php self::renderMessages(); ?>

            <p class="description" style="max-width:700px;">
                <?php esc_html_e( 'Configure how much each main category contributes to the overall rating per age group. Weights are percentages — the four values must sum to 100. Age groups without a configured weight set use equal weights (25% / 25% / 25% / 25% for four mains).', 'talenttrack' ); ?>
            </p>

            <?php if ( empty( $mains ) ) : ?>
                <p><em><?php esc_html_e( 'No active main categories found.', 'talenttrack' ); ?></em></p>
                <?php return; ?>
            <?php endif; ?>

            <?php if ( empty( $age_groups ) ) : ?>
                <p><em><?php esc_html_e( 'No age groups configured. Add some under Configuration → Age Groups first.', 'talenttrack' ); ?></em></p>
                <?php return; ?>
            <?php endif; ?>

            <?php foreach ( $age_groups as $ag ) :
                $ag_id    = (int) $ag->id;
                $weights  = $all_weights[ $ag_id ] ?? [];
                $has_cfg  = ! empty( $weights );
                $eq       = CategoryWeightsRepository::equalWeightsForMains(
                    array_map( fn( $m ) => (int) $m->id, $mains )
                );
                ?>
                <div class="tt-weight-section" style="background:#fff; border:1px solid #dcdcde; padding:16px 20px; margin:16px 0; max-width:700px;">
                    <h2 style="margin:0 0 6px; display:flex; align-items:center; gap:12px;">
                        <?php echo esc_html( (string) $ag->name ); ?>
                        <?php if ( $has_cfg ) : ?>
                            <span style="font-size:12px; font-weight:400; color:#00a32a;">● <?php esc_html_e( 'Configured', 'talenttrack' ); ?></span>
                        <?php else : ?>
                            <span style="font-size:12px; font-weight:400; color:#888;">● <?php esc_html_e( 'Equal fallback in use', 'talenttrack' ); ?></span>
                        <?php endif; ?>
                    </h2>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-weight-form">
                        <?php wp_nonce_field( 'tt_save_category_weights_' . $ag_id, 'tt_nonce' ); ?>
                        <input type="hidden" name="action" value="tt_save_category_weights" />
                        <input type="hidden" name="age_group_id" value="<?php echo $ag_id; ?>" />

                        <table class="form-table" style="margin:0;">
                            <tbody>
                            <?php foreach ( $mains as $main ) :
                                $main_id = (int) $main->id;
                                $val = $weights[ $main_id ] ?? $eq[ $main_id ] ?? 25;
                                ?>
                                <tr>
                                    <th style="width:220px; padding-left:0;">
                                        <label for="tt_w_<?php echo $ag_id; ?>_<?php echo $main_id; ?>">
                                            <?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $main->label ) ); ?>
                                        </label>
                                    </th>
                                    <td>
                                        <input type="number"
                                               id="tt_w_<?php echo $ag_id; ?>_<?php echo $main_id; ?>"
                                               name="weights[<?php echo $main_id; ?>]"
                                               value="<?php echo (int) $val; ?>"
                                               min="0" max="100" step="1"
                                               class="small-text tt-weight-input"
                                               style="width:80px;" />
                                        <span style="color:#666; margin-left:4px;">%</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <th style="padding-left:0;">
                                    <strong><?php esc_html_e( 'Total:', 'talenttrack' ); ?></strong>
                                </th>
                                <td>
                                    <span class="tt-weight-total" style="font-weight:600;">—</span>
                                    <span class="tt-weight-total-hint" style="margin-left:8px;"></span>
                                </td>
                            </tr>
                            </tbody>
                        </table>

                        <p style="margin-top:8px;">
                            <?php submit_button( __( 'Save weights', 'talenttrack' ), 'primary', 'submit', false ); ?>
                            <?php if ( $has_cfg ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin-post.php?action=tt_reset_category_weights&age_group_id=' . $ag_id ),
                                    'tt_reset_category_weights_' . $ag_id
                                ) ); ?>" style="margin-left:12px; color:#b32d2e;"
                                   onclick="return confirm('<?php echo esc_js( __( 'Reset to equal weights?', 'talenttrack' ) ); ?>')">
                                    <?php esc_html_e( 'Reset to equal', 'talenttrack' ); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
        jQuery(function($){
            var okTxt    = <?php echo wp_json_encode( __( 'Sum is 100 — ready to save', 'talenttrack' ) ); ?>;
            var errTpl   = <?php echo wp_json_encode( __( 'Must equal 100 (current: %d)', 'talenttrack' ) ); ?>;

            $('.tt-weight-form').each(function(){
                var $form    = $(this);
                var $inputs  = $form.find('.tt-weight-input');
                var $total   = $form.find('.tt-weight-total');
                var $hint    = $form.find('.tt-weight-total-hint');
                var $submit  = $form.find('input[type=submit]');

                function recompute(){
                    var sum = 0;
                    $inputs.each(function(){
                        var v = parseInt($(this).val(), 10);
                        if (!isNaN(v)) sum += v;
                    });
                    $total.text(sum + '%');
                    if (sum === 100) {
                        $total.css('color', '#00a32a');
                        $hint.css('color', '#00a32a').text(okTxt);
                        $submit.prop('disabled', false);
                    } else {
                        $total.css('color', '#b32d2e');
                        $hint.css('color', '#b32d2e').text(errTpl.replace('%d', sum));
                        $submit.prop('disabled', true);
                    }
                }

                $inputs.on('input change', recompute);
                recompute();
            });
        });
        </script>
        <?php
    }

    /* ═══════════════ Handlers ═══════════════ */

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $age_group_id = isset( $_POST['age_group_id'] ) ? absint( $_POST['age_group_id'] ) : 0;
        check_admin_referer( 'tt_save_category_weights_' . $age_group_id, 'tt_nonce' );

        if ( $age_group_id <= 0 ) {
            self::redirectWithError( 'missing_age_group' );
        }

        $raw = isset( $_POST['weights'] ) && is_array( $_POST['weights'] ) ? wp_unslash( $_POST['weights'] ) : [];
        $weights = [];
        foreach ( $raw as $main_id => $w ) {
            $main_id = (int) $main_id;
            $w_int   = (int) $w;
            if ( $main_id > 0 ) {
                $weights[ $main_id ] = max( 0, min( 100, $w_int ) );
            }
        }

        // Hard validation — must sum to exactly 100.
        $sum_or_null = CategoryWeightsRepository::validateSumsTo100( $weights );
        if ( $sum_or_null !== null ) {
            self::redirectWithError( 'sum_not_100', $sum_or_null );
        }

        $repo = new CategoryWeightsRepository();
        $ok = $repo->saveForAgeGroup( $age_group_id, $weights );
        if ( ! $ok ) {
            self::redirectWithError( 'save_failed' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-category-weights&tt_msg=saved' ) );
        exit;
    }

    public static function handleReset(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $age_group_id = isset( $_GET['age_group_id'] ) ? absint( $_GET['age_group_id'] ) : 0;
        check_admin_referer( 'tt_reset_category_weights_' . $age_group_id );

        if ( $age_group_id > 0 ) {
            $repo = new CategoryWeightsRepository();
            $repo->deleteForAgeGroup( $age_group_id );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-category-weights&tt_msg=reset' ) );
        exit;
    }

    /* ═══════════════ Helpers ═══════════════ */

    private static function redirectWithError( string $code, ?int $detail = null ): void {
        $args = [ 'page' => 'tt-category-weights', 'tt_error' => $code ];
        if ( $detail !== null ) $args['tt_detail'] = $detail;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function renderMessages(): void {
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) ) : '';
        if ( $msg === 'saved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Weights saved.', 'talenttrack' ) . '</p></div>';
        } elseif ( $msg === 'reset' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Weights reset to equal.', 'talenttrack' ) . '</p></div>';
        }

        $err = isset( $_GET['tt_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_error'] ) ) : '';
        if ( $err !== '' ) {
            if ( $err === 'sum_not_100' ) {
                $detail = isset( $_GET['tt_detail'] ) ? (int) $_GET['tt_detail'] : 0;
                echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(
                    esc_html__( 'Weights must sum to 100%%. Current total: %d%%.', 'talenttrack' ),
                    $detail
                ) . '</p></div>';
            } else {
                $map = [
                    'missing_age_group' => __( 'Missing age group.', 'talenttrack' ),
                    'save_failed'       => __( 'The database rejected the save. Try again.', 'talenttrack' ),
                ];
                $text = $map[ $err ] ?? __( 'Something went wrong.', 'talenttrack' );
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
            }
        }
    }
}
