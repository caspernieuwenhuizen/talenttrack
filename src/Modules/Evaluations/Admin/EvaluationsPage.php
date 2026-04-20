<?php
namespace TT\Modules\Evaluations\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomFieldsSlot;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Validation\CustomFieldValidator;

/**
 * EvaluationsPage — admin CRUD for evaluations.
 *
 * v2.6.2: fail-loud on insert/update failures. If the database rejects the
 * save (e.g. missing schema column), the user is redirected back with an
 * error notice instead of silently redirecting to the list showing "Saved."
 */
class EvaluationsPage {

    private const TRANSIENT_PREFIX = 'tt_eval_form_state_';

    public static function init(): void {
        add_action( 'admin_post_tt_save_evaluation', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_evaluation', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $action === 'edit' ? $id : 0 ); return; }
        if ( $action === 'view' && $id ) { self::render_view( $id ); return; }
        self::render_list();
    }

    private static function render_list(): void {
        global $wpdb; $p = $wpdb->prefix;
        $evals = $wpdb->get_results(
            "SELECT e.*, lt.name AS type_name, CONCAT(pl.first_name,' ',pl.last_name) AS player_name, u.display_name AS coach_name
             FROM {$p}tt_evaluations e LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id=lt.id AND lt.lookup_type='eval_type'
             LEFT JOIN {$p}tt_players pl ON e.player_id=pl.id LEFT JOIN {$wpdb->users} u ON e.coach_id=u.ID
             ORDER BY e.eval_date DESC LIMIT 50"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Evaluations', 'talenttrack' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th></tr></thead><tbody>
            <?php if ( empty( $evals ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No evaluations.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $evals as $ev ) : ?>
                <tr><td><?php echo esc_html( (string) $ev->eval_date ); ?></td><td><?php echo esc_html( $ev->player_name ?: '—' ); ?></td>
                    <td><?php echo esc_html( $ev->type_name ?: '—' ); ?></td><td><?php echo esc_html( (string) $ev->coach_name ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-evaluations&action=view&id={$ev->id}" ) ); ?>"><?php esc_html_e( 'View', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-evaluations&action=edit&id={$ev->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_evaluation&id={$ev->id}" ), 'tt_del_eval_' . $ev->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( int $eval_id ): void {
        $eval = $eval_id ? QueryHelpers::get_evaluation( $eval_id ) : null;
        $players    = QueryHelpers::get_players();
        $types      = QueryHelpers::get_eval_types();
        $rmin  = (float) QueryHelpers::get_config( 'rating_min',  '1' );
        $rmax  = (float) QueryHelpers::get_config( 'rating_max',  '5' );
        $rstep = (float) QueryHelpers::get_config( 'rating_step', '0.5' );

        // Load the full category tree (mains + their subs) for the ratings UI.
        $cats_repo = new EvalCategoriesRepository();
        $tree      = $cats_repo->getTree( true );

        // Existing ratings, split into main-direct vs subcategory.
        // Keyed by category_id so the form can pre-fill either bucket.
        $direct_ratings = [];   // main_cat_id => float
        $sub_ratings    = [];   // sub_cat_id  => float
        $main_has_subs_rated = []; // main_cat_id => true  (auto-expand mode)
        if ( $eval && ! empty( $eval->ratings ) ) {
            foreach ( $eval->ratings as $r ) {
                $cid    = (int) $r->category_id;
                $rating = (float) $r->rating;
                $is_sub = isset( $r->category_parent_id ) && $r->category_parent_id !== null;
                if ( $is_sub ) {
                    $sub_ratings[ $cid ] = $rating;
                    $main_has_subs_rated[ (int) $r->category_parent_id ] = true;
                } else {
                    $direct_ratings[ $cid ] = $rating;
                }
            }
        }

        $type_meta = [];
        foreach ( $types as $t ) {
            $m = QueryHelpers::lookup_meta( $t );
            $type_meta[ (int) $t->id ] = ! empty( $m['requires_match_details'] ) ? 1 : 0;
        }

        $state = self::popFormState();

        // Pull transient-saved ratings_mode back into POST so the form
        // re-renders in the same mode on a failed save.
        if ( $state && isset( $state['submitted_custom_fields'] ) && is_array( $state['submitted_custom_fields'] ) ) {
            $_POST['custom_fields'] = $state['submitted_custom_fields'];
        }
        ?>
        <div class="wrap">
            <h1><?php echo $eval ? esc_html__( 'Edit Evaluation', 'talenttrack' ) : esc_html__( 'New Evaluation', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'talenttrack' ); ?></a></h1>

            <?php if ( ! empty( $_GET['tt_cf_error'] ) ) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php esc_html_e( 'The evaluation was saved, but one or more custom fields had invalid values and were not updated.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $state && ! empty( $state['db_error'] ) ) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e( 'The database rejected the save. No evaluation was created.', 'talenttrack' ); ?></strong></p>
                    <p style="font-family:monospace;font-size:12px;"><?php echo esc_html( (string) $state['db_error'] ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_evaluation', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_evaluation" />
                <?php if ( $eval ) : ?><input type="hidden" name="id" value="<?php echo (int) $eval->id; ?>" /><?php endif; ?>
                <?php $eid = (int) ( $eval->id ?? 0 ); ?>

                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?> *</th><td><select name="player_id" required>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>" <?php selected( $eval->player_id ?? 0, $pl->id ); ?>><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'player_id' ); ?>
                    <tr><th><?php esc_html_e( 'Type', 'talenttrack' ); ?> *</th><td><select name="eval_type_id" id="tt_eval_type" required>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $types as $t ) : ?><option value="<?php echo (int) $t->id; ?>" data-match="<?php echo (int) $type_meta[ (int) $t->id ]; ?>" <?php selected( $eval->eval_type_id ?? 0, $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'eval_type_id' ); ?>
                    <tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</th><td><input type="date" name="eval_date" value="<?php echo esc_attr( $eval->eval_date ?? current_time( 'Y-m-d' ) ); ?>" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'eval_date' ); ?>
                </table>

                <table class="form-table" id="tt-match-fields" style="display:none;">
                    <tr><th><?php esc_html_e( 'Opponent', 'talenttrack' ); ?></th><td><input type="text" name="opponent" value="<?php echo esc_attr( $eval->opponent ?? '' ); ?>" class="regular-text" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'opponent' ); ?>
                    <tr><th><?php esc_html_e( 'Competition', 'talenttrack' ); ?></th><td><input type="text" name="competition" value="<?php echo esc_attr( $eval->competition ?? '' ); ?>" class="regular-text" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'competition' ); ?>
                    <tr><th><?php esc_html_e( 'Result', 'talenttrack' ); ?></th><td><input type="text" name="match_result" value="<?php echo esc_attr( $eval->match_result ?? '' ); ?>" style="width:80px" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'match_result' ); ?>
                    <tr><th><?php esc_html_e( 'Home/Away', 'talenttrack' ); ?></th><td><select name="home_away"><option value="">—</option><option value="home" <?php selected( $eval->home_away ?? '', 'home' ); ?>><?php esc_html_e( 'Home', 'talenttrack' ); ?></option><option value="away" <?php selected( $eval->home_away ?? '', 'away' ); ?>><?php esc_html_e( 'Away', 'talenttrack' ); ?></option></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'home_away' ); ?>
                    <tr><th><?php esc_html_e( 'Minutes Played', 'talenttrack' ); ?></th><td><input type="number" name="minutes_played" value="<?php echo esc_attr( $eval->minutes_played ?? '' ); ?>" min="0" max="120" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'minutes_played' ); ?>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></h2>
                <?php if ( empty( $tree ) ) : ?>
                    <p style="color:#b32d2e;">
                        <strong><?php esc_html_e( 'No evaluation categories configured.', 'talenttrack' ); ?></strong>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-eval-categories' ) ); ?>"><?php esc_html_e( 'Configure categories', 'talenttrack' ); ?></a>
                    </p>
                <?php else : ?>
                    <p class="description" style="margin-bottom:12px;">
                        <?php esc_html_e( 'For each main category you can either rate it directly OR drill into its subcategories. Pick whichever makes sense — you can mix modes across categories.', 'talenttrack' ); ?>
                    </p>

                    <?php foreach ( $tree as $main ) :
                        $main_id = (int) $main->id;
                        $has_subs = ! empty( $main->children );
                        // Default mode: subcategories iff existing sub ratings present.
                        $mode_default = ( $has_subs && isset( $main_has_subs_rated[ $main_id ] ) ) ? 'subcategories' : 'direct';
                        $direct_val = isset( $direct_ratings[ $main_id ] ) ? (string) $direct_ratings[ $main_id ] : '';
                        ?>
                        <fieldset class="tt-rating-block" data-main-id="<?php echo (int) $main_id; ?>"
                                  data-mode="<?php echo esc_attr( $mode_default ); ?>"
                                  style="border:1px solid #dcdcde; padding:12px 16px; margin-bottom:10px; background:#fff;">
                            <legend style="font-weight:600; padding:0 6px;">
                                <?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $main->label ) ); ?>
                            </legend>

                            <input type="hidden" name="tt_rating_mode[<?php echo $main_id; ?>]"
                                   value="<?php echo esc_attr( $mode_default ); ?>"
                                   class="tt-mode-input" />

                            <!-- Direct mode -->
                            <div class="tt-mode-direct" style="<?php echo $mode_default === 'direct' ? '' : 'display:none;'; ?>">
                                <label>
                                    <input type="number"
                                           name="ratings[<?php echo $main_id; ?>]"
                                           value="<?php echo esc_attr( $direct_val ); ?>"
                                           min="<?php echo esc_attr( (string) $rmin ); ?>"
                                           max="<?php echo esc_attr( (string) $rmax ); ?>"
                                           step="<?php echo esc_attr( (string) $rstep ); ?>"
                                           style="width:80px;" />
                                    <span class="description">
                                        (<?php echo esc_html( (string) $rmin ); ?>–<?php echo esc_html( (string) $rmax ); ?>)
                                    </span>
                                </label>
                                <?php if ( $has_subs ) : ?>
                                    <a href="#" class="tt-toggle-subs" style="margin-left:16px;">
                                        + <?php esc_html_e( 'rate subcategories instead', 'talenttrack' ); ?>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <!-- Subcategories mode -->
                            <?php if ( $has_subs ) : ?>
                                <div class="tt-mode-subs" style="<?php echo $mode_default === 'subcategories' ? '' : 'display:none;'; ?>">
                                    <table style="width:100%; border-collapse:collapse;">
                                        <tbody>
                                        <?php foreach ( $main->children as $sub ) :
                                            $sub_id  = (int) $sub->id;
                                            $sub_val = isset( $sub_ratings[ $sub_id ] ) ? (string) $sub_ratings[ $sub_id ] : '';
                                            ?>
                                            <tr>
                                                <td style="padding:4px 12px 4px 16px; color:#555; width:60%;">
                                                    <span style="color:#bbb;">↳</span>
                                                    <?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $sub->label ) ); ?>
                                                </td>
                                                <td style="padding:4px 0;">
                                                    <input type="number"
                                                           name="ratings[<?php echo $sub_id; ?>]"
                                                           value="<?php echo esc_attr( $sub_val ); ?>"
                                                           min="<?php echo esc_attr( (string) $rmin ); ?>"
                                                           max="<?php echo esc_attr( (string) $rmax ); ?>"
                                                           step="<?php echo esc_attr( (string) $rstep ); ?>"
                                                           class="tt-sub-input"
                                                           style="width:80px;" />
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <!-- v2.12.2: live preview of the computed
                                         main-category average. Updates as the
                                         coach types subcategory scores. Read-
                                         only surface; the actual rollup still
                                         happens on read via effectiveMainRating. -->
                                    <div class="tt-subs-summary" style="margin-top:10px; padding:8px 12px; background:#f0f6fc; border-left:3px solid #2271b1; font-size:13px;">
                                        <strong><?php esc_html_e( 'Main category average (computed):', 'talenttrack' ); ?></strong>
                                        <span class="tt-subs-avg" style="font-weight:600; margin-left:6px;">—</span>
                                        <span class="tt-subs-avg-note" style="color:#666; margin-left:6px; font-weight:400;"></span>
                                    </div>

                                    <p style="margin:8px 0 0;">
                                        <a href="#" class="tt-toggle-direct">
                                            ← <?php esc_html_e( 'rate main category directly instead', 'talenttrack' ); ?>
                                        </a>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </fieldset>
                    <?php endforeach; ?>
                <?php endif; ?>

                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th><td><textarea name="notes" rows="4" class="large-text"><?php echo esc_textarea( $eval->notes ?? '' ); ?></textarea></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_EVALUATION, $eid, 'notes' ); ?>
                    <?php CustomFieldsSlot::renderAppend( CustomFieldsRepository::ENTITY_EVALUATION, $eid ); ?>
                </table>

                <?php submit_button( $eval ? __( 'Update', 'talenttrack' ) : __( 'Save', 'talenttrack' ) ); ?>
            </form>
        </div>

        <script>
        jQuery(function($){
            // Match-fields show/hide based on eval type.
            var m = <?php echo wp_json_encode( $type_meta ); ?>;
            function t(){ var v = $('#tt_eval_type').val(); $('#tt-match-fields').toggle(v && m[v]==1); }
            $('#tt_eval_type').on('change', t); t();

            // Either/or mode switching + live average preview.
            //
            // Clicking "rate subcategories" clears the direct input (per
            // design: discard silently on mode switch). Clicking "rate
            // main directly" clears the subcategory inputs AND resets the
            // preview. While in subcategory mode, the preview updates on
            // every input event — empty inputs are ignored, the average
            // is (sum of entered) / (count of entered), rounded to 1
            // decimal. Preview note shows "(from N subcategory/ies)".
            var noteOneTpl  = <?php echo wp_json_encode( __( '(from %d subcategory)', 'talenttrack' ) ); ?>;
            var noteManyTpl = <?php echo wp_json_encode( __( '(from %d subcategories)', 'talenttrack' ) ); ?>;

            function recomputeAvg($block){
                var $avg  = $block.find('.tt-subs-avg');
                var $note = $block.find('.tt-subs-avg-note');
                if (!$avg.length) return;
                var sum = 0, count = 0;
                $block.find('.tt-sub-input').each(function(){
                    var v = $(this).val();
                    if (v === '' || v === null) return;
                    var n = parseFloat(v);
                    if (!isNaN(n)) { sum += n; count++; }
                });
                if (count === 0) {
                    $avg.text('—');
                    $note.text('');
                } else {
                    var mean = sum / count;
                    // Round to 1 decimal. Strip trailing .0 for cleaner display.
                    var rounded = Math.round(mean * 10) / 10;
                    $avg.text(String(rounded));
                    var tpl = count === 1 ? noteOneTpl : noteManyTpl;
                    $note.text(tpl.replace('%d', count));
                }
            }

            $('.tt-rating-block').each(function(){
                var $block = $(this);
                var $modeInput = $block.find('.tt-mode-input');
                var $direct = $block.find('.tt-mode-direct');
                var $subs   = $block.find('.tt-mode-subs');

                // Live recompute on any sub input change.
                $block.find('.tt-sub-input').on('input change', function(){
                    recomputeAvg($block);
                });
                // Initial paint — show average if pre-filled (editing
                // an evaluation that already has subcategory ratings).
                recomputeAvg($block);

                $block.find('.tt-toggle-subs').on('click', function(e){
                    e.preventDefault();
                    $direct.find('input[type=number]').val('');
                    $direct.hide();
                    $subs.show();
                    $modeInput.val('subcategories');
                    $block.attr('data-mode', 'subcategories');
                    recomputeAvg($block);
                });
                $block.find('.tt-toggle-direct').on('click', function(e){
                    e.preventDefault();
                    $subs.find('input[type=number]').val('');
                    $subs.hide();
                    $direct.show();
                    $modeInput.val('direct');
                    $block.attr('data-mode', 'direct');
                    recomputeAvg($block); // resets to '—'
                });
            });
        });
        </script>
        <?php
    }

    private static function render_view( int $id ): void {
        $eval = QueryHelpers::get_evaluation( $id );
        if ( ! $eval ) { echo '<div class="wrap"><p>' . esc_html__( 'Not found.', 'talenttrack' ) . '</p></div>'; return; }
        $player = QueryHelpers::get_player( (int) $eval->player_id );
        $max    = (float) QueryHelpers::get_config( 'rating_max', '5' );

        // Compute effective rating per main category (direct value OR
        // subcategory rollup). Also collect per-main subcategory ratings
        // for the breakdown display.
        $ratings_repo = new EvalRatingsRepository();
        $effective    = $ratings_repo->effectiveMainRatingsFor( $id );

        $sub_ratings_by_main = []; // main_id => [ [label, value], ... ]
        if ( ! empty( $eval->ratings ) ) {
            foreach ( $eval->ratings as $r ) {
                if ( $r->category_parent_id === null ) continue;
                $pid = (int) $r->category_parent_id;
                $sub_ratings_by_main[ $pid ][] = [
                    'label' => (string) $r->category_name,
                    'value' => (float) $r->rating,
                ];
            }
        }

        // Radar chart uses effective ratings so subcategory-only evals still
        // produce a chart with all four dimensions. Labels go through the
        // translator so seeded category names display in the admin's locale.
        $radar_labels = [];
        $radar_values = [];
        foreach ( $effective as $row ) {
            $radar_labels[] = EvalCategoriesRepository::displayLabel( (string) $row['label'] );
            $radar_values[] = $row['value'] !== null ? $row['value'] : 0;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Evaluation', 'talenttrack' ); ?> — <?php echo esc_html( $player ? QueryHelpers::player_display_name( $player ) : '' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'talenttrack' ); ?></a></h1>
            <div style="display:flex;gap:30px;flex-wrap:wrap;margin-top:20px;">
                <div style="flex:1;min-width:320px;">
                    <table class="form-table">
                        <tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th><td><?php echo esc_html( (string) $eval->eval_date ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th><td><?php echo esc_html( $eval->type_name ?: '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th><td><?php echo nl2br( esc_html( $eval->notes ?: '—' ) ); ?></td></tr>
                    </table>

                    <h3 style="margin-top:24px;"><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></h3>
                    <?php if ( empty( $effective ) ) : ?>
                        <p><em><?php esc_html_e( 'No ratings recorded.', 'talenttrack' ); ?></em></p>
                    <?php else : ?>
                        <table class="widefat striped" style="max-width:500px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Category', 'talenttrack' ); ?></th>
                                    <th style="width:80px; text-align:right;"><?php esc_html_e( 'Rating', 'talenttrack' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $effective as $main_id => $row ) :
                                $val = $row['value'];
                                $source = $row['source'];
                                $subs_here = $sub_ratings_by_main[ $main_id ] ?? [];
                                ?>
                                <tr style="background:#f6f7f7;">
                                    <td><strong><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $row['label'] ) ); ?></strong>
                                        <?php if ( $source === 'computed' ) : ?>
                                            <span style="color:#888; font-weight:400; font-size:12px; margin-left:8px;">
                                                <?php printf(
                                                    /* translators: %d is the number of subcategory ratings the rollup averages. */
                                                    esc_html( _n( '(averaged from %d subcategory)', '(averaged from %d subcategories)', (int) $row['sub_count'], 'talenttrack' ) ),
                                                    (int) $row['sub_count']
                                                ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php echo $val === null
                                            ? '<span style="color:#888;">—</span>'
                                            : esc_html( (string) $val ); ?>
                                    </td>
                                </tr>
                                <?php foreach ( $subs_here as $sub ) : ?>
                                    <tr>
                                        <td style="padding-left:30px; color:#555;">
                                            <span style="color:#bbb;">↳</span>
                                            <?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $sub['label'] ) ); ?>
                                        </td>
                                        <td style="text-align:right; color:#555;">
                                            <?php echo esc_html( (string) $sub['value'] ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php CustomFieldsSlot::renderReadonly( CustomFieldsRepository::ENTITY_EVALUATION, $id ); ?>
                </div>
                <div style="flex:1;min-width:320px;">
                    <h3><?php esc_html_e( 'Radar Chart', 'talenttrack' ); ?></h3>
                    <?php echo ! empty( $radar_labels )
                        ? QueryHelpers::radar_chart_svg( $radar_labels, [ [ 'label' => (string) $eval->eval_date, 'values' => $radar_values ] ], $max )
                        : ''; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function handle_save(): void {
        check_admin_referer( 'tt_save_evaluation', 'tt_nonce' );
        global $wpdb; $p = $wpdb->prefix;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $player_id = isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0;

        // v2.8.0: entity-scoped authorization. Must be allowed to evaluate
        // THIS specific player (not just have the generic capability).
        if ( ! AuthorizationService::canEvaluatePlayer( get_current_user_id(), $player_id ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $header = [
            'player_id'    => $player_id,
            'coach_id'     => get_current_user_id(),
            'eval_type_id' => isset( $_POST['eval_type_id'] ) ? absint( $_POST['eval_type_id'] ) : 0,
            'eval_date'    => isset( $_POST['eval_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['eval_date'] ) ) : '',
            'notes'        => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
            'opponent'     => isset( $_POST['opponent'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['opponent'] ) ) : '',
            'competition'  => isset( $_POST['competition'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['competition'] ) ) : '',
            'match_result' => isset( $_POST['match_result'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['match_result'] ) ) : '',
            'home_away'    => isset( $_POST['home_away'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['home_away'] ) ) : '',
            'minutes_played' => ! empty( $_POST['minutes_played'] ) ? absint( $_POST['minutes_played'] ) : null,
        ];

        if ( $id ) {
            $ok = $wpdb->update( "{$p}tt_evaluations", $header, [ 'id' => $id ] );
        } else {
            do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );
            $ok = $wpdb->insert( "{$p}tt_evaluations", $header );
            if ( $ok !== false ) $id = (int) $wpdb->insert_id;
        }

        if ( $ok === false ) {
            Logger::error( 'admin.evaluation.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'is_update' => (bool) $id ] );
            self::saveFormState( [
                'db_error' => $wpdb->last_error ?: __( 'Unknown database error.', 'talenttrack' ),
                'submitted_custom_fields' => isset( $_POST['custom_fields'] ) && is_array( $_POST['custom_fields'] ) ? wp_unslash( $_POST['custom_fields'] ) : [],
            ] );
            $back = add_query_arg(
                [ 'page' => 'tt-evaluations', 'action' => $id ? 'edit' : 'new', 'id' => $id ],
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $back );
            exit;
        }

        // v2.12.0: hierarchy-aware ratings save.
        //
        // The form POSTs:
        //   - tt_rating_mode[<main_id>]  = 'direct' | 'subcategories'
        //   - ratings[<category_id>]     = number  (any category — main or sub)
        //
        // We wipe previous ratings for this evaluation, then re-insert based
        // on mode per main category:
        //   - mode=direct          → store ratings[main_id] only (ignore sub inputs)
        //   - mode=subcategories   → store ratings[sub_id] for each sub (ignore main)
        //
        // Categories not represented in tt_rating_mode are handled as "direct"
        // for back-compat (e.g. if an admin created a main with no subs, or
        // JS failed to load and the fallback is the direct input).
        $rmin = (float) QueryHelpers::get_config( 'rating_min', '1' );
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '5' );

        $ratings_repo = new EvalRatingsRepository();
        $cats_repo    = new EvalCategoriesRepository();

        $ratings_repo->deleteForEvaluation( $id );

        $mode_map = isset( $_POST['tt_rating_mode'] ) && is_array( $_POST['tt_rating_mode'] )
            ? wp_unslash( $_POST['tt_rating_mode'] )
            : [];
        $raw_ratings = isset( $_POST['ratings'] ) && is_array( $_POST['ratings'] )
            ? wp_unslash( $_POST['ratings'] )
            : [];

        $tree = $cats_repo->getTree( true );
        foreach ( $tree as $main ) {
            $main_id = (int) $main->id;
            $mode    = isset( $mode_map[ $main_id ] ) ? (string) $mode_map[ $main_id ] : 'direct';

            if ( $mode === 'subcategories' && ! empty( $main->children ) ) {
                foreach ( $main->children as $sub ) {
                    $sub_id = (int) $sub->id;
                    if ( ! isset( $raw_ratings[ $sub_id ] ) ) continue;
                    $v = $raw_ratings[ $sub_id ];
                    if ( $v === '' || $v === null ) continue;
                    $clamped = max( $rmin, min( $rmax, floatval( $v ) ) );
                    if ( ! $ratings_repo->upsert( $id, $sub_id, $clamped ) ) {
                        Logger::error( 'admin.evaluation.rating.save.failed', [
                            'db_error' => (string) $wpdb->last_error, 'evaluation_id' => $id, 'category_id' => $sub_id,
                        ] );
                    }
                }
            } else {
                if ( ! isset( $raw_ratings[ $main_id ] ) ) continue;
                $v = $raw_ratings[ $main_id ];
                if ( $v === '' || $v === null ) continue;
                $clamped = max( $rmin, min( $rmax, floatval( $v ) ) );
                if ( ! $ratings_repo->upsert( $id, $main_id, $clamped ) ) {
                    Logger::error( 'admin.evaluation.rating.save.failed', [
                        'db_error' => (string) $wpdb->last_error, 'evaluation_id' => $id, 'category_id' => $main_id,
                    ] );
                }
            }
        }

        // Persist custom field values. Errors don't undo the evaluation save.
        $cf_errors = CustomFieldValidator::persistFromPost( CustomFieldsRepository::ENTITY_EVALUATION, $id, $_POST );

        $redirect_args = [ 'page' => 'tt-evaluations', 'tt_msg' => 'saved' ];
        if ( ! empty( $cf_errors ) ) {
            $redirect_args['tt_cf_error'] = 1;
            $redirect_args['action']      = 'edit';
            $redirect_args['id']          = $id;
        }
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_del_eval_' . $id );

        // v2.8.0: check canEvaluatePlayer against the evaluation's player.
        // Coaches can delete evaluations of players on their teams; admins
        // can delete any.
        global $wpdb; $p = $wpdb->prefix;
        $player_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT player_id FROM {$p}tt_evaluations WHERE id = %d",
            $id
        ) );
        if ( ! AuthorizationService::canEvaluatePlayer( get_current_user_id(), $player_id ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id ] );
        $wpdb->delete( "{$p}tt_evaluations", [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-evaluations&tt_msg=deleted' ) );
        exit;
    }

    private static function saveFormState( array $state ): void {
        set_transient( self::TRANSIENT_PREFIX . get_current_user_id(), $state, 60 );
    }

    private static function popFormState(): ?array {
        $key   = self::TRANSIENT_PREFIX . get_current_user_id();
        $state = get_transient( $key );
        if ( $state === false ) return null;
        delete_transient( $key );
        return is_array( $state ) ? $state : null;
    }
}
