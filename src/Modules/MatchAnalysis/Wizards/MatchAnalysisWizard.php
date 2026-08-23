<?php
namespace TT\Modules\MatchAnalysis\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * MatchAnalysisWizard (#2708) — writing up a match, one question at a time.
 *
 * CLAUDE.md §3 binds here: a match analysis is a new record type, so the
 * creation path ships as a wizard. It is also the right shape for the work
 * — a coach writing up Saturday's game on a phone benefits from being
 * walked through the phases rather than handed a page of empty boxes, and
 * the steps are the order a coach thinks in: what happened, then how we
 * played, then who did what.
 *
 * Entry: `?tt_view=wizard&tt_wizard=match-analysis&activity_id=N`, built
 * through `WizardEntryPoint::urlFor()`.
 *
 * The flat surface (`?tt_view=match-analysis&activity_id=N`) is the
 * power-user path and the re-edit path: a coach returning to last week's
 * analysis to change one line edits it in place rather than walking five
 * steps again. Both write through `MatchAnalysisWriter`, so neither can
 * drift from the other.
 */
final class MatchAnalysisWizard implements WizardInterface {

    public function slug(): string { return 'match-analysis'; }
    public function label(): string { return __( 'Write the match analysis', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_activities'; }
    public function firstStepSlug(): string { return 'overall'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new OverallStep(),
            new TeamFunctionsStep(),
            new SetPiecesStep(),
            new PlayersStep(),
            new ReviewStep(),
        ];
    }

    /**
     * The wizard is always entered from one match, and every step needs to
     * know which. `activity_id` is stashed on first hit because wizard
     * POSTs go through admin-post.php and never see the entry URL's query
     * string again.
     *
     * @param array<string,mixed> $get
     * @return array<string,mixed>
     */
    public function initialState( array $get ): array {
        $activity_id = isset( $get['activity_id'] ) ? (int) $get['activity_id'] : 0;
        return $activity_id > 0 ? [ 'activity_id' => $activity_id ] : [];
    }
}
