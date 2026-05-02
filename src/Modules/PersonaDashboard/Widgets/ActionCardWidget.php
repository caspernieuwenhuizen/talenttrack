<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * ActionCardWidget — single CTA button.
 *
 * data_source is one of the shipped action ids:
 *   new_evaluation, new_goal, new_activity, new_player, new_team,
 *   scout_report, new_trial.
 *
 * #0074 dropped the decorative `tt-pd-action-icon` yellow circle. The
 * "+" affordance lives inside the translated label string so the cue
 * is preserved without the extra DOM node and without the colour.
 */
class ActionCardWidget extends AbstractWidget {

    private const ACTIONS = [
        'new_evaluation' => [ 'label_key' => '+ New evaluation',   'view' => 'evaluations',  'cap' => 'tt_create_evaluations' ],
        'new_goal'       => [ 'label_key' => '+ New goal',         'view' => 'goals',        'cap' => 'tt_create_goals' ],
        'new_activity'   => [ 'label_key' => '+ New activity',     'view' => 'activities',   'cap' => 'tt_edit_activities' ],
        'new_player'     => [ 'label_key' => '+ Add player',       'view' => 'players',      'cap' => 'tt_edit_players' ],
        'new_team'       => [ 'label_key' => '+ Add team',         'view' => 'teams',        'cap' => 'tt_edit_teams' ],
        'scout_report'   => [ 'label_key' => '+ New scout report', 'view' => 'scout-history','cap' => 'tt_generate_scout_report' ],
        'new_trial'      => [ 'label_key' => '+ New trial',        'view' => 'trials',       'cap' => 'tt_manage_trials' ],
    ];

    public function id(): string { return 'action_card'; }

    public function label(): string { return __( 'Action card', 'talenttrack' ); }

    /** @return array<string,string> */
    public function dataSourceCatalogue(): array {
        $out = [];
        foreach ( self::ACTIONS as $key => $action ) {
            $out[ $key ] = __( (string) $action['label_key'], 'talenttrack' );
        }
        return $out;
    }

    public function defaultSize(): string { return Size::S; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::S, Size::M ]; }

    public function defaultMobilePriority(): int { return 70; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $id = $slot->data_source;
        if ( ! isset( self::ACTIONS[ $id ] ) ) return '';
        $action = self::ACTIONS[ $id ];
        if ( ! empty( $action['cap'] ) && ! current_user_can( (string) $action['cap'] ) ) return '';

        $label = $slot->persona_label !== ''
            ? $slot->persona_label
            : __( $action['label_key'], 'talenttrack' );
        $url = $ctx->viewUrl( (string) $action['view'] );

        $inner = '<a class="tt-pd-action-link" href="' . esc_url( $url ) . '">'
            . '<span class="tt-pd-action-label">' . esc_html( $label ) . '</span>'
            . '</a>';
        return $this->wrap( $slot, $inner );
    }
}
