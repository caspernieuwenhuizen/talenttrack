<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Shared\Wizards\WizardEntryPoint;

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
 *
 * v3.110.108 — two pilot-surfaced bugs on the quick-actions panel:
 *
 *   1. **Wrong cap names.** `tt_create_evaluations` and
 *      `tt_create_goals` do not exist in the cap system — only
 *      `tt_edit_*` caps are granted. `current_user_can()` therefore
 *      returned false for every coach on the New evaluation + New
 *      goal cards, hiding 2 of the 4 quick-action buttons and
 *      leaving the dashboard with a single CTA. Fixed to use the
 *      `tt_edit_*` caps that actually exist.
 *   2. **Flat-form URLs instead of wizard URLs.** "+ New activity"
 *      sent the coach to `?tt_view=activities` (the list view) —
 *      `?action=new` on the flat path renders the form, but only
 *      when the user lands directly there. From the dashboard a
 *      route through `WizardEntryPoint::urlFor()` is required so
 *      the wizards-enabled gate lifts the user into the multi-step
 *      flow. Same pattern `FrontendEvaluationsView::render()` uses
 *      for its page-header CTA.
 */
class ActionCardWidget extends AbstractWidget {

    private const ACTIONS = [
        // 'wizard' key maps to a registered slug in WizardRegistry
        // (see src/Modules/Wizards/*/New*Wizard.php). When set, the
        // CTA routes through WizardEntryPoint::urlFor() with an
        // action=new fallback URL so installs that flip
        // `tt_wizards_enabled` off keep working.
        'new_evaluation' => [ 'label_key' => '+ New evaluation',   'view' => 'evaluations',  'cap' => 'tt_edit_evaluations', 'wizard' => 'new-evaluation' ],
        'new_goal'       => [ 'label_key' => '+ New goal',         'view' => 'goals',        'cap' => 'tt_edit_goals',       'wizard' => 'new-goal' ],
        'new_activity'   => [ 'label_key' => '+ New activity',     'view' => 'activities',   'cap' => 'tt_edit_activities',  'wizard' => 'new-activity' ],
        'new_player'     => [ 'label_key' => '+ Add player',       'view' => 'players',      'cap' => 'tt_edit_players',     'wizard' => 'new-player' ],
        'new_team'       => [ 'label_key' => '+ Add team',         'view' => 'teams',        'cap' => 'tt_edit_teams',       'wizard' => 'new-team' ],
        // scout_report + new_trial keep the flat path — no wizard
        // registered yet.
        'scout_report'   => [ 'label_key' => '+ New scout report', 'view' => 'scout-history','cap' => 'tt_generate_scout_report' ],
        'new_trial'      => [ 'label_key' => '+ New trial',        'view' => 'trials',       'cap' => 'tt_manage_trials' ],
    ];

    public function id(): string { return 'action_card'; }

    public function label(): string { return __( 'Action card', 'talenttrack' ); }

    public function description(): string {
        return __( 'A primary call-to-action tile (e.g. "+ New trial", "+ New evaluation"). The data-source field picks the action; the card renders the action\'s registered label, icon, and gates on its capability — admins without the cap see no card.', 'talenttrack' );
    }

    /** @return list<string> */
    public function intendedPersonas(): array {
        return [ 'head_coach', 'assistant_coach', 'head_of_development', 'scout' ];
    }

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

        // Build the URL — wizard-routed when the action has a `wizard`
        // slug registered, flat-form otherwise.
        $flat_url = add_query_arg(
            [ 'tt_view' => (string) $action['view'], 'action' => 'new' ],
            $ctx->viewUrl( (string) $action['view'] )
        );
        $url = ! empty( $action['wizard'] ) && class_exists( WizardEntryPoint::class )
            ? WizardEntryPoint::urlFor( (string) $action['wizard'], $flat_url )
            : $flat_url;

        $inner = '<a class="tt-pd-action-link" href="' . esc_url( $url ) . '">'
            . '<span class="tt-pd-action-label">' . esc_html( $label ) . '</span>'
            . '</a>';
        return $this->wrap( $slot, $inner );
    }
}
