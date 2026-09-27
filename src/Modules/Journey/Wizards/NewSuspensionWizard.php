<?php
namespace TT\Modules\Journey\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Wizards\WizardInterface;

/**
 * NewSuspensionWizard (#4103) — the wizard-first create flow for a
 * suspension (CLAUDE.md §3), at `?tt_view=wizard&tt_wizard=new-suspension`.
 *
 * Steps: player → the suspension (reason, number of matches, start date,
 * note) → confirm, which persists. Entered from a player's profile the
 * first step is skipped: the wizard arrives with `player_id` in state,
 * checked here against the same per-player gate the final step asks.
 *
 * Gated on `tt_manage_suspensions`, which bridges to
 * `player_suspensions:change` at any scope — head and assistant coach on
 * their own team, head of development and academy admin everywhere. The
 * flat form at `?tt_view=suspensions&action=new` is the fallback.
 */
final class NewSuspensionWizard implements WizardInterface {

    public const SLUG = 'new-suspension';

    public function slug(): string { return self::SLUG; }

    public function label(): string { return __( 'Record suspension', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_manage_suspensions'; }

    public function firstStepSlug(): string { return 'player'; }

    /** @return \TT\Shared\Wizards\WizardStepInterface[] */
    public function steps(): array {
        return [
            new SuspensionPlayerStep(),
            new SuspensionDetailsStep(),
            new SuspensionConfirmStep(),
        ];
    }

    /**
     * Seed `player_id` from the entry URL, but only a player this user may
     * record a suspension for — a hand-edited URL does not skip the step
     * for somebody else's squad.
     *
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function initialState( array $get ): array {
        $player_id = isset( $get['player_id'] ) ? absint( $get['player_id'] ) : 0;
        if ( $player_id <= 0 ) return [];
        if ( ! AuthorizationService::canAccessSuspensions( get_current_user_id(), $player_id, 'change' ) ) return [];
        return [ 'player_id' => $player_id ];
    }
}
