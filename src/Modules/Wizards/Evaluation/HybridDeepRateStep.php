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

        $date_val    = (string) ( $state['eval_date'] ?? gmdate( 'Y-m-d' ) );
        $type_val    = (int)    ( $state['eval_type_id'] ?? 0 );
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
                <tr><th style="text-align:left;font-weight:normal;"><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                    <td>
                        <select name="eval_type_id">
                            <option value="0"><?php esc_html_e( '— pick a type —', 'talenttrack' ); ?></option>
                            <?php foreach ( $eval_types as $t ) :
                                $tid   = (int) $t->id;
                                $label = (string) \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'eval_type', (string) $t->name );
                                ?>
                                <option value="<?php echo (int) $tid; ?>" <?php selected( $type_val, $tid ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr><th style="text-align:left;font-weight:normal;vertical-align:top;"><?php esc_html_e( 'Context', 'talenttrack' ); ?></th>
                    <td><textarea rows="3" maxlength="500" name="eval_reason" style="width:100%;"><?php echo esc_textarea( $reason_val ); ?></textarea></td>
                </tr>

                <?php
                // v3.110.195 (#811) — Basic / Detailed toggle per main
                // category, mirroring the flat eval form's UX
                // (`CoachForms::renderEvalForm` v3.110.125). Basic
                // hides sub-categories; Detailed reveals them. The
                // wizard previously rendered main-only and had no
                // toggle, so coaches who wanted sub-category granularity
                // had to leave the wizard and use the flat form.
                $cat_repo = class_exists( '\\TT\\Infrastructure\\Evaluations\\EvalCategoriesRepository' )
                    ? new \TT\Infrastructure\Evaluations\EvalCategoriesRepository()
                    : null;
                $toggle_basic_label  = __( 'Basic',    'talenttrack' );
                $toggle_detail_label = __( 'Detailed', 'talenttrack' );
                ?>
                <?php foreach ( (array) $cats as $cat ) :
                    $cid = (int) $cat->id;
                    $val = (int) ( $state['ratings_self'][ $cid ] ?? 0 );
                    $cat_label = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->label, $cid );
                    $sub_cats  = $cat_repo !== null ? $cat_repo->getChildren( $cid ) : [];
                    // Auto-default: Detailed when any sub for this main
                    // already has a stored value (state carries them).
                    $has_sub_values = false;
                    foreach ( (array) $sub_cats as $sub_check ) {
                        $scid_check = (int) $sub_check->id;
                        if ( $scid_check > 0 && ! empty( $state['ratings_self'][ $scid_check ] ) ) {
                            $has_sub_values = true; break;
                        }
                    }
                    $initial_state = $has_sub_values ? 'detailed' : 'basic';
                    ?>
                    <tr>
                        <th style="text-align:left;font-weight:normal;"><?php echo esc_html( $cat_label ); ?></th>
                        <td>
                            <input type="number" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" step="1" inputmode="numeric"
                                name="ratings_self[<?php echo $cid; ?>]"
                                value="<?php echo $val > 0 ? (int) $val : ''; ?>"
                                style="width:60px;" />
                            <span style="color:var(--tt-muted);font-size:13px;">/ <?php echo (int) $max; ?></span>
                            <?php if ( $cat_repo !== null && ! empty( $sub_cats ) ) : ?>
                                <span class="tt-rate-detail-toggle" data-tt-rate-detail-toggle data-state="<?php echo esc_attr( $initial_state ); ?>" role="tablist" style="margin-left:12px;display:inline-flex;gap:2px;border:1px solid var(--tt-line, #d0d4d8);border-radius:4px;overflow:hidden;font-size:11px;">
                                    <button type="button" data-mode="basic"    role="tab" aria-selected="<?php echo $initial_state === 'basic' ? 'true' : 'false'; ?>" style="padding:2px 8px;border:0;background:<?php echo $initial_state === 'basic' ? '#0b3d2e;color:#fff' : '#fff;color:#1a1d21'; ?>;cursor:pointer;"><?php echo esc_html( $toggle_basic_label ); ?></button>
                                    <button type="button" data-mode="detailed" role="tab" aria-selected="<?php echo $initial_state === 'detailed' ? 'true' : 'false'; ?>" style="padding:2px 8px;border:0;background:<?php echo $initial_state === 'detailed' ? '#0b3d2e;color:#fff' : '#fff;color:#1a1d21'; ?>;cursor:pointer;"><?php echo esc_html( $toggle_detail_label ); ?></button>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $cat_repo !== null && ! empty( $sub_cats ) ) : ?>
                        <?php foreach ( (array) $sub_cats as $sub ) :
                            $scid = (int) $sub->id;
                            if ( $scid <= 0 ) continue;
                            $sub_val = (int) ( $state['ratings_self'][ $scid ] ?? 0 );
                            $sub_label = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) ( $sub->label ?? $sub->name ?? '' ), $scid );
                            ?>
                            <tr class="tt-rate-sub-row" data-tt-rate-sub-row data-parent="<?php echo $cid; ?>" <?php echo $initial_state === 'basic' ? 'hidden' : ''; ?>>
                                <th style="text-align:left;font-weight:normal;padding-left:20px;color:var(--tt-muted);">↳ <?php echo esc_html( $sub_label ); ?></th>
                                <td>
                                    <input type="number" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" step="1" inputmode="numeric"
                                        name="ratings_self[<?php echo $scid; ?>]"
                                        value="<?php echo $sub_val > 0 ? (int) $sub_val : ''; ?>"
                                        style="width:60px;" />
                                    <span style="color:var(--tt-muted);font-size:13px;">/ <?php echo (int) $max; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        (function(){
            // v3.110.195 (#811) — Basic/Detailed toggle JS. Click
            // delegated on the document so every toggle row hooks up
            // without per-element wiring. Form values inside the hidden
            // sub rows persist across mode flips.
            document.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('.tt-rate-detail-toggle button') : null;
                if (!btn) return;
                var wrap = btn.closest('.tt-rate-detail-toggle');
                if (!wrap) return;
                var mode = btn.getAttribute('data-mode');
                wrap.setAttribute('data-state', mode);
                var btns = wrap.querySelectorAll('button');
                btns.forEach(function (b) {
                    var selected = (b === btn);
                    b.setAttribute('aria-selected', selected ? 'true' : 'false');
                    b.style.background = selected ? '#0b3d2e' : '#fff';
                    b.style.color      = selected ? '#fff'    : '#1a1d21';
                });
                // Find the parent main category id from the toggle's
                // surrounding row, then show/hide every sub row that
                // points back at it.
                var mainRow = wrap.closest('tr');
                var mainInput = mainRow ? mainRow.querySelector('input[name^="ratings_self["]') : null;
                if (!mainInput) return;
                var nameMatch = mainInput.name.match(/ratings_self\[(\d+)\]/);
                if (!nameMatch) return;
                var parentId = nameMatch[1];
                var subRows = document.querySelectorAll('[data-tt-rate-sub-row][data-parent="' + parentId + '"]');
                subRows.forEach(function (row) {
                    if (mode === 'detailed') row.removeAttribute('hidden');
                    else row.setAttribute('hidden', '');
                });
            });
        }());
        </script>
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
        foreach ( $ratings_raw as $cid => $v ) {
            $v = (int) $v;
            if ( $v <= 0 ) continue;
            $clean[ (int) $cid ] = $v;
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
