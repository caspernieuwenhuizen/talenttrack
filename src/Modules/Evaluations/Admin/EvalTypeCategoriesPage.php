<?php
namespace TT\Modules\Evaluations\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Evaluations\EvalTypeCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * EvalTypeCategoriesPage (#819) — admin matrix mapping each
 * `eval_type` lookup row to the categories that surface when that
 * type is selected in the evaluation form.
 *
 * Empty row (no checkboxes ticked) = "all active categories" fallback,
 * back-compat with the pre-#819 behaviour.
 *
 * Mirrors the CategoryWeightsPage shape: one matrix table, one save
 * per row (per eval_type).
 */
class EvalTypeCategoriesPage {

    private const CAP = 'tt_view_settings';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'registerMenu' ] );
        add_action( 'admin_post_tt_save_eval_type_categories', [ __CLASS__, 'handleSave' ] );
    }

    public static function registerMenu(): void {
        add_submenu_page(
            'talenttrack',
            __( 'Eval Type Categories', 'talenttrack' ),
            __( 'Eval Type Categories', 'talenttrack' ),
            self::CAP,
            'tt-eval-type-categories',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $types_lookup = QueryHelpers::get_eval_types();
        $cats_repo    = new EvalCategoriesRepository();
        $mains        = $cats_repo->getMainCategories( true );

        $repo = new EvalTypeCategoriesRepository();
        $all_mappings = $repo->allMappings();

        $saved = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Eval Type Categories', 'talenttrack' ); ?></h1>
            <p class="description" style="max-width: 700px;">
                <?php esc_html_e( 'Per evaluatietype: vink de categorieën die in het rating-formulier moeten verschijnen. Niets aanvinken voor een type = alle actieve categorieën tonen (terugval-gedrag, gelijk aan vóór #819).', 'talenttrack' ); ?>
            </p>

            <?php if ( $saved > 0 ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Mapping opgeslagen.', 'talenttrack' ); ?></p></div>
            <?php endif; ?>

            <?php foreach ( (array) $types_lookup as $type ) :
                $tid    = (int) $type->id;
                $tlabel = (string) $type->label;
                $current = $all_mappings[ $tid ] ?? [];
                $current_map = array_flip( array_map( 'intval', $current ) );
                ?>
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 14px; margin: 16px 0;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'tt_save_eval_type_categories_' . $tid, '_tt_etc_nonce' ); ?>
                        <input type="hidden" name="action" value="tt_save_eval_type_categories">
                        <input type="hidden" name="eval_type_id" value="<?php echo (int) $tid; ?>">

                        <h2 style="margin: 0 0 8px; font-size: 16px;"><?php echo esc_html( $tlabel ); ?>
                            <?php if ( empty( $current ) ) : ?>
                                <span style="font-size: 12px; color: #5b6e75; font-weight: 400; margin-left: 8px;">— alle categorieën (terugval)</span>
                            <?php else : ?>
                                <span style="font-size: 12px; color: #1d7874; font-weight: 600; margin-left: 8px;"><?php echo (int) count( $current ); ?> aangevinkt</span>
                            <?php endif; ?>
                        </h2>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 6px 16px; margin: 8px 0 12px;">
                            <?php foreach ( $mains as $cat ) :
                                $cid     = (int) $cat->id;
                                $clabel  = EvalCategoriesRepository::displayLabel( (string) $cat->name );
                                $checked = isset( $current_map[ $cid ] ) ? 'checked' : '';
                                ?>
                                <label style="display: flex; gap: 6px; align-items: center;">
                                    <input type="checkbox" name="categories[]" value="<?php echo (int) $cid; ?>" <?php echo $checked; ?>>
                                    <?php echo esc_html( $clabel ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Opslaan', 'talenttrack' ); ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $tid = isset( $_POST['eval_type_id'] ) ? absint( $_POST['eval_type_id'] ) : 0;
        if ( $tid <= 0 ) wp_die( 'bad request' );
        if ( ! isset( $_POST['_tt_etc_nonce'] ) || ! wp_verify_nonce( (string) $_POST['_tt_etc_nonce'], 'tt_save_eval_type_categories_' . $tid ) ) {
            wp_die( 'bad nonce' );
        }
        $cids = isset( $_POST['categories'] ) ? array_map( 'absint', (array) $_POST['categories'] ) : [];
        ( new EvalTypeCategoriesRepository() )->setCategoriesFor( $tid, $cids );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'tt-eval-type-categories', 'saved' => 1 ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
