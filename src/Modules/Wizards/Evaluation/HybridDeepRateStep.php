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
        // #1067 — chip-grid / slider-row component CSS + JS. Idempotent
        // enqueue. Same handles as RateActorsStep so loading twice is
        // a no-op when both surfaces show in one session.
        wp_enqueue_style(
            'tt-rating-input',
            TT_PLUGIN_URL . 'assets/css/components/rating-input.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-rating-input',
            TT_PLUGIN_URL . 'assets/js/components/rating-input.js',
            [],
            TT_VERSION,
            true
        );

        // #1732 — collapsible per-category accordion. CSS holds the
        // layout (replaces the old inline-styled table); the JS mirrors
        // each category's value into its summary stars + average word and
        // keeps the sub-skill → main average recalc.
        wp_enqueue_style(
            'tt-eval-rate-accordion',
            TT_PLUGIN_URL . 'assets/css/components/eval-rate-accordion.css',
            [ 'tt-rating-input' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-eval-rate-accordion',
            TT_PLUGIN_URL . 'assets/js/components/eval-rate-accordion.js',
            [ 'tt-rating-input' ],
            TT_VERSION,
            true
        );

        global $wpdb;
        $p = $wpdb->prefix;

        $cats = $wpdb->get_results(
            "SELECT id, label FROM {$p}tt_eval_categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY display_order, label"
        );

        // #819 — eval-type → category filter. Empty mapping for the
        // selected type = fall back to "all" (back-compat with pre-#819
        // installs and any type the operator hasn't curated yet).
        $type_for_filter = (int) ( $state['eval_type_id'] ?? 0 );
        if ( $type_for_filter > 0 ) {
            $allowed = ( new \TT\Infrastructure\Evaluations\EvalTypeCategoriesRepository() )
                ->categoryIdsFor( $type_for_filter );
            if ( ! empty( $allowed ) ) {
                $allowed_map = array_flip( array_map( 'intval', $allowed ) );
                $cats = array_values( array_filter( (array) $cats, function ( $c ) use ( $allowed_map ) {
                    return isset( $allowed_map[ (int) $c->id ] );
                } ) );
            }
        }
        // v3.110.116 — was reading the stale `wp_options[tt_rating_scale_max]`
        // key. Reads `tt_config[rating_max]` instead so the input
        // bounds track the active scale.
        $max = (int) round( (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_max', '10' ) );
        $min = (int) round( (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_min', '5' ) );

        // v3.110.67 — unified on the `eval_type` lookup (the same one
        // the flat / edit form uses). Was reading from the parallel
        // `evaluation_setting` lookup, which had different values
        // (training/match/tournament/observation/other) AND was never
        // persisted to the row — picking "observation" in the wizard
        // had zero effect on the saved evaluation, and reopening for
        // edit showed a different list (Training/Match/Friendly) from
        // `eval_type`. Migration 0091 extended `eval_type` with
        // Tournament / Observation / Other so the wizard's value
        // space is preserved.
        $eval_types = \TT\Infrastructure\Query\QueryHelpers::get_eval_types();

        // #1643 — training-eval default. Resolve the training type id +
        // mental category id and hand them to the shared JS enhancement
        // so picking Training reorders + pre-expands the mental category.
        $training_type_id = 0;
        foreach ( $eval_types as $t ) {
            if ( \TT\Infrastructure\Evaluations\TrainingEvalDefaults::isTrainingTypeName( (string) $t->name ) ) {
                $training_type_id = (int) $t->id;
                break;
            }
        }
        $mental_cat = ( new \TT\Infrastructure\Evaluations\EvalCategoriesRepository() )
            ->getByKey( \TT\Infrastructure\Evaluations\TrainingEvalDefaults::PRIORITY_CATEGORY_KEY );
        $mental_cat_id = $mental_cat ? (int) $mental_cat->id : 0;

        wp_enqueue_script(
            'tt-training-eval-defaults',
            TT_PLUGIN_URL . 'assets/js/components/training-eval-defaults.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-training-eval-defaults',
            'TT_TRAINING_EVAL_DEFAULTS',
            [
                'trainingTypeId'   => $training_type_id,
                'mentalCategoryId' => $mental_cat_id,
            ]
        );

        $date_val    = (string) ( $state['eval_date'] ?? gmdate( 'Y-m-d' ) );
        $type_val    = (int)    ( $state['eval_type_id'] ?? 0 );
        $reason_val  = (string) ( $state['eval_reason'] ?? '' );

        // #1732 — the rating value series for the read-only summary stars.
        // Same scale the editable star component uses (whole steps,
        // $min..$max), so the summary column lines up with the body rows.
        $star_series = [];
        for ( $sv = $min; $sv <= $max; $sv++ ) {
            $star_series[] = $sv;
        }

        $cat_repo = class_exists( '\\TT\\Infrastructure\\Evaluations\\EvalCategoriesRepository' )
            ? new \TT\Infrastructure\Evaluations\EvalCategoriesRepository()
            : null;
        ?>
        <p class="tt-rate-intro">
            <?php esc_html_e( 'Add the activity context this evaluation is anchored to, then rate the categories that matter.', 'talenttrack' ); ?>
        </p>

        <div class="tt-rate-context">
            <div class="tt-field tt-rate-context-row">
                <label class="tt-field-label" for="tt_hdr_eval_date"><?php esc_html_e( 'Date', 'talenttrack' ); ?></label>
                <input type="date" id="tt_hdr_eval_date" class="tt-input" name="eval_date" value="<?php echo esc_attr( $date_val ); ?>" />
            </div>
            <div class="tt-field tt-rate-context-row">
                <label class="tt-field-label" for="tt_hdr_eval_type"><?php esc_html_e( 'Type', 'talenttrack' ); ?></label>
                <select name="eval_type_id" id="tt_hdr_eval_type" class="tt-input">
                    <option value="0"><?php esc_html_e( '— pick a type —', 'talenttrack' ); ?></option>
                    <?php foreach ( $eval_types as $t ) :
                        $tid   = (int) $t->id;
                        $label = (string) \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'eval_type', (string) $t->name );
                        ?>
                        <option value="<?php echo (int) $tid; ?>" <?php selected( $type_val, $tid ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tt-field tt-rate-context-row">
                <label class="tt-field-label" for="tt_hdr_eval_reason"><?php esc_html_e( 'Context', 'talenttrack' ); ?></label>
                <textarea id="tt_hdr_eval_reason" class="tt-input" rows="3" maxlength="500" name="eval_reason"><?php echo esc_textarea( $reason_val ); ?></textarea>
            </div>
        </div>

        <div class="tt-rate-cats">
            <?php // Insertion anchor for the #1643 training default (mental-first). ?>
            <span class="tt-rate-cats-anchor" data-tt-eval-cats-anchor hidden></span>
            <?php foreach ( (array) $cats as $cat ) :
                $cid       = (int) $cat->id;
                $val       = (float) ( $state['ratings_self'][ $cid ] ?? 0 );
                $cat_label = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->label, $cid );
                $sub_cats  = $cat_repo !== null ? $cat_repo->getChildren( $cid ) : [];
                ?>
                <details class="tt-rate-cat" data-tt-eval-cat-main data-tt-eval-cat="<?php echo (int) $cid; ?>">
                    <summary class="tt-rate-cat-head">
                        <span class="tt-rate-cat-name"><?php echo esc_html( $cat_label ); ?></span>
                        <span class="tt-rate-cat-summary">
                            <span class="tt-rate-cat-stars" data-tt-rate-cat-stars aria-hidden="true">
                                <?php foreach ( $star_series as $star_val ) : ?>
                                    <span class="tt-rate-cat-star" data-value="<?php echo (int) $star_val; ?>">&#9733;</span>
                                <?php endforeach; ?>
                            </span>
                            <span class="tt-rate-cat-avg tt-rate-cat-avg--unset" data-tt-rate-cat-avg>&mdash;</span>
                        </span>
                    </summary>
                    <div class="tt-rate-cat-body">
                        <div class="tt-rate-cat-row tt-rate-cat-row--main">
                            <span class="tt-rate-cat-rowlabel"><?php echo esc_html( $cat_label ); ?></span>
                            <?php
                            echo \TT\Shared\Frontend\Components\RatingInputComponent::renderListRow( [
                                'name'         => 'ratings_self[' . $cid . ']',
                                'value'        => $val > 0 ? (string) $val : '',
                                'label'        => $cat_label,
                                'label_hidden' => true,
                                'min'          => (float) $min,
                                'max'          => (float) $max,
                                'input_class'  => 'tt-rate-input',
                                'data_attrs'   => [ 'tt-rate-main' => (int) $cid ],
                            ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes
                            ?>
                        </div>
                        <?php if ( $cat_repo !== null && ! empty( $sub_cats ) ) : ?>
                            <?php foreach ( (array) $sub_cats as $sub ) :
                                $scid = (int) $sub->id;
                                if ( $scid <= 0 ) continue;
                                $sub_val   = (float) ( $state['ratings_self'][ $scid ] ?? 0 );
                                $sub_label = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) ( $sub->label ?? $sub->name ?? '' ), $scid );
                                ?>
                                <div class="tt-rate-cat-row tt-rate-cat-row--sub">
                                    <span class="tt-rate-cat-rowlabel tt-rate-cat-rowlabel--sub"><?php echo esc_html( $sub_label ); ?></span>
                                    <?php
                                    echo \TT\Shared\Frontend\Components\RatingInputComponent::renderListRow( [
                                        'name'         => 'ratings_self[' . $scid . ']',
                                        'value'        => $sub_val > 0 ? (string) $sub_val : '',
                                        'label'        => $sub_label,
                                        'label_hidden' => true,
                                        'min'          => (float) $min,
                                        'max'          => (float) $max,
                                        'input_class'  => 'tt-rate-input',
                                        'data_attrs'   => [ 'tt-rate-sub-parent' => (int) $cid ],
                                    ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function validate( array $post, array $state ) {
        $date    = isset( $post['eval_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['eval_date'] ) ) : '';
        // v3.110.67 — `eval_type_id` (FK into tt_lookups, lookup_type='eval_type')
        // replaces the legacy `eval_setting` slug. See render() docblock.
        $type_id = isset( $post['eval_type_id'] ) ? absint( $post['eval_type_id'] ) : 0;
        $reason  = isset( $post['eval_reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $post['eval_reason'] ) ) : '';

        $ratings_raw = isset( $post['ratings_self'] ) && is_array( $post['ratings_self'] ) ? $post['ratings_self'] : [];
        $clean = [];
        // #1067 — slider input is 0.5-step. Schema is DECIMAL(4,1);
        // float-cast + snap-to-0.5 mirrors RateActorsStep::validate.
        foreach ( $ratings_raw as $cid => $v ) {
            $f = (float) $v;
            if ( $f <= 0 ) continue;
            $f = round( $f * 2 ) / 2;
            $clean[ (int) $cid ] = $f;
        }
        return [
            'eval_date'    => $date,
            'eval_type_id' => $type_id,
            'eval_reason'  => $reason,
            'ratings_self' => $clean,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
