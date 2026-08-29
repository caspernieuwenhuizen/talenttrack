<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Repositories\GoalLinksRepository;

/**
 * GoalPrinciplePicker — "what does this goal develop?" on every surface a
 * goal is authored.
 *
 * #2566 — the link between a goal and a methodology principle is the hinge
 * of the whole player → goal → principle → exercise → training chain, and it
 * sat at zero of 109 goals because no authoring surface asked for it in a way
 * a coach would answer. The picker is present and prominent wherever a goal is
 * written, pre-filtered to the club's active methodology, and skippable — a
 * goal without a principle ("be a better team-mate") is still a good goal.
 *
 * Writes `links[principle][]` style data as `principle_ids[]`, which the goals
 * REST controller hands to `GoalLinksRepository::syncPrinciples()`. That is
 * the canonical shape: many principles per goal, alongside the football
 * actions and positions the same table already carries.
 */
final class GoalPrinciplePicker {

    /**
     * @param array<string,mixed> $args
     *        goal_id     — int; read the current selection from the link table.
     *        selected    — list of ints; explicit selection, overrides goal_id.
     *        id          — DOM id prefix, so two pickers on one page stay distinct.
     *        legacy_rows — wrap in `.tt-form-row` instead of `.tt-field` for the
     *                      older coach quick-add forms.
     */
    public static function render( array $args = [] ): string {
        $principles = self::principles();
        if ( ! $principles ) {
            return '';
        }

        // The coach quick-add forms don't load the goals stylesheet, so the
        // component brings its own. Idempotent.
        wp_enqueue_style(
            'tt-goals',
            TT_PLUGIN_URL . 'assets/css/frontend-goals.css',
            [],
            TT_VERSION
        );

        $selected = isset( $args['selected'] ) && is_array( $args['selected'] )
            ? array_map( 'intval', $args['selected'] )
            : ( ( (int) ( $args['goal_id'] ?? 0 ) ) > 0
                ? ( new GoalLinksRepository() )->principleIdsForGoal( (int) $args['goal_id'] )
                : [] );

        $dom_id  = isset( $args['id'] ) ? sanitize_html_class( (string) $args['id'] ) : 'tt-goal-principles';
        $wrapper = ! empty( $args['legacy_rows'] ) ? 'tt-form-row' : 'tt-field';
        $label   = ! empty( $args['legacy_rows'] ) ? '' : ' class="tt-field-label"';

        $out  = '<div class="' . esc_attr( $wrapper ) . '">';
        $out .= '<label' . $label . ' id="' . esc_attr( $dom_id . '-label' ) . '">'
              . esc_html__( 'What does this goal develop?', 'talenttrack' ) . '</label>';
        $out .= '<p class="tt-field-hint">'
              . esc_html__( 'Pick the methodology principles this goal works on. Optional, but tagging it is what lets training plans and reports aim at this goal.', 'talenttrack' )
              . '</p>';
        $out .= '<div class="tt-goal-principle-list" role="group" aria-labelledby="' . esc_attr( $dom_id . '-label' ) . '">';

        foreach ( $principles as $pr ) {
            $pid   = (int) $pr['id'];
            $input = $dom_id . '-' . $pid;
            $out  .= '<label class="tt-goal-principle-opt" for="' . esc_attr( $input ) . '">';
            $out  .= '<input type="checkbox" id="' . esc_attr( $input ) . '" name="principle_ids[]" value="' . esc_attr( (string) $pid ) . '"'
                   . checked( in_array( $pid, $selected, true ), true, false ) . ' />';
            $out  .= '<span>' . esc_html( $pr['label'] ) . '</span>';
            $out  .= '</label>';
        }

        $out .= '</div></div>';
        return $out;
    }

    /**
     * The club's principles, already narrowed to the active methodology by
     * `PrinciplesRepository::listFiltered()`.
     *
     * @return list<array{id:int, label:string}>
     */
    private static function principles(): array {
        if ( ! class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrinciplesRepository' ) ) {
            return [];
        }
        $repo = new \TT\Modules\Methodology\Repositories\PrinciplesRepository();
        $out  = [];
        foreach ( (array) $repo->listFiltered() as $pr ) {
            $title = \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json ?? null );
            $code  = (string) ( $pr->code ?? '' );
            $label = ( $code !== '' && $title !== '' ) ? $code . ' · ' . $title : ( $title !== '' ? $title : $code );
            if ( $label === '' ) continue;
            $out[] = [ 'id' => (int) ( $pr->id ?? 0 ), 'label' => $label ];
        }
        return $out;
    }
}
