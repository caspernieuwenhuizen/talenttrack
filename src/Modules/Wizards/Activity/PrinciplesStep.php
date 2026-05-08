<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Connected principles. Optional multiselect.
 *
 * Closes the parity gap operator flagged: the activity edit form
 * (FrontendActivitiesManageView) has had this multiselect since
 * v3.79.0 (#0077 M2), but the create wizard never picked it up.
 * Operators couldn't tag a brand-new activity to a methodology
 * principle without first saving and then editing.
 *
 * State key: `activity_principle_ids` — list<int> of principle row
 * ids. ReviewStep::submit reads this and writes through
 * PrincipleLinksRepository::setActivityPrinciples after the
 * tt_activities insert. Step is skippable — operators that don't
 * want a link click Next without selecting any.
 */
final class PrinciplesStep implements WizardStepInterface {

    public function slug(): string { return 'principles'; }
    public function label(): string { return __( 'Connected principles', 'talenttrack' ); }

    public function render( array $state ): void {
        if ( ! class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrinciplesRepository' ) ) {
            echo '<p><em>' . esc_html__( 'Methodology module not available — skip this step.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $principles = ( new \TT\Modules\Methodology\Repositories\PrinciplesRepository() )->listFiltered();
        $selected   = (array) ( $state['activity_principle_ids'] ?? [] );
        $selected   = array_map( 'intval', $selected );

        echo '<p>' . esc_html__( 'Optionally connect this activity to one or more methodology principles. Hold Ctrl/Cmd to select multiple. Leave blank to skip.', 'talenttrack' ) . '</p>';

        if ( empty( $principles ) ) {
            echo '<p><em>' . esc_html__( 'No principles configured yet. Skip this step or configure principles under Methodology first.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<label><span>' . esc_html__( 'Principles', 'talenttrack' ) . '</span>';
        echo '<select name="activity_principle_ids[]" multiple size="6" style="min-width:320px;">';
        foreach ( $principles as $pr ) {
            $title = '';
            if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                $title = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
            }
            $label = trim( (string) $pr->code . ( $title !== '' ? ' · ' . $title : '' ) );
            $is_sel = in_array( (int) $pr->id, $selected, true );
            echo '<option value="' . (int) $pr->id . '"' . ( $is_sel ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $raw = $post['activity_principle_ids'] ?? [];
        if ( ! is_array( $raw ) ) $raw = [];
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $raw ) ) ) );
        return [ 'activity_principle_ids' => $ids ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
