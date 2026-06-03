<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\MethodologyEnums;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Connected principles. Optional two-level picker (#1122).
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
 *
 * #1122 — pilot 2026-06-03 asked for a two-level picker (bucket
 * first, then principles within). The hold-Ctrl multiselect over 18
 * principles was unusable on touch. Now: principles are grouped by
 * `team_function_key` × `team_task_key` (the same vocabulary the
 * methodology browser groups by); each bucket renders as a section
 * with one checkbox per principle.
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
        $selected   = array_map( 'intval', (array) ( $state['activity_principle_ids'] ?? [] ) );

        echo '<p>' . esc_html__( 'Tag this activity with one or more methodology principles. Group by team function + team task; tick whichever apply. Skip the step to save without links.', 'talenttrack' ) . '</p>';

        if ( empty( $principles ) ) {
            echo '<p><em>' . esc_html__( 'No principles configured yet. Skip this step or configure principles under Methodology first.', 'talenttrack' ) . '</em></p>';
            return;
        }

        // Group by (team_function_key, team_task_key).
        $function_labels = MethodologyEnums::teamFunctions();
        $task_labels     = MethodologyEnums::teamTasks();
        $grouped = [];
        $unbucketed = [];
        foreach ( $principles as $pr ) {
            $fk = (string) ( $pr->team_function_key ?? '' );
            $tk = (string) ( $pr->team_task_key     ?? '' );
            if ( $fk === '' && $tk === '' ) {
                $unbucketed[] = $pr;
                continue;
            }
            $grouped[ $fk ][ $tk ][] = $pr;
        }

        echo '<div class="tt-act-principle-picker" style="display:flex; flex-direction:column; gap:14px;">';
        foreach ( $function_labels as $fk => $fn_label ) {
            if ( empty( $grouped[ $fk ] ) ) continue;
            echo '<details open style="border:1px solid #d6dadd; border-radius:8px; padding:10px 12px; background:#fff;">';
            echo '<summary style="cursor:pointer; font-weight:700; font-size:14px; color:#1a1d21;">'
                . esc_html( $fn_label )
                . '</summary>';
            // Tasks within this function. Preserve task label order
            // from teamTasks(); fall back to alphabetical key for tasks
            // not in the enum.
            $task_order = array_keys( $task_labels );
            uksort( $grouped[ $fk ], static function ( $a, $b ) use ( $task_order ): int {
                $ia = array_search( $a, $task_order, true );
                $ib = array_search( $b, $task_order, true );
                if ( $ia === false ) $ia = PHP_INT_MAX;
                if ( $ib === false ) $ib = PHP_INT_MAX;
                return $ia <=> $ib;
            } );
            foreach ( $grouped[ $fk ] as $tk => $principle_rows ) {
                $task_label = $task_labels[ $tk ] ?? ucfirst( str_replace( '_', ' ', (string) $tk ) );
                echo '<div style="margin:10px 0 4px;">';
                if ( (string) $tk !== '' ) {
                    echo '<div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#5b6e75; margin-bottom:6px;">'
                        . esc_html( (string) $task_label )
                        . '</div>';
                }
                self::renderCheckboxList( $principle_rows, $selected );
                echo '</div>';
            }
            echo '</details>';
        }
        if ( $unbucketed !== [] ) {
            echo '<details style="border:1px solid #d6dadd; border-radius:8px; padding:10px 12px; background:#fff;">';
            echo '<summary style="cursor:pointer; font-weight:700; font-size:14px; color:#5b6e75;">'
                . esc_html__( 'Other principles', 'talenttrack' )
                . '</summary>';
            echo '<div style="margin:10px 0 4px;">';
            self::renderCheckboxList( $unbucketed, $selected );
            echo '</div></details>';
        }
        echo '</div>';
    }

    /**
     * @param object[] $principles
     * @param int[]    $selected
     */
    private static function renderCheckboxList( array $principles, array $selected ): void {
        echo '<ul style="list-style:none; margin:0; padding:0; display:grid; grid-template-columns:1fr; gap:6px;">';
        foreach ( $principles as $pr ) {
            $title = '';
            if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                $title = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
            }
            $code   = (string) ( $pr->code ?? '' );
            $label  = trim( $code . ( $title !== '' ? ' · ' . $title : '' ) );
            $pid    = (int) $pr->id;
            $is_sel = in_array( $pid, $selected, true );
            echo '<li>';
            echo '<label style="display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:6px; background:' . ( $is_sel ? '#eef4fb' : '#f9fafb' ) . '; cursor:pointer; min-height:44px;">';
            echo '<input type="checkbox" name="activity_principle_ids[]" value="' . $pid . '"' . ( $is_sel ? ' checked' : '' ) . ' style="width:20px; height:20px;">';
            echo '<span style="flex:1; font-size:14px; color:#1a1d21;">' . esc_html( $label ) . '</span>';
            echo '</label>';
            echo '</li>';
        }
        echo '</ul>';
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
