<?php
namespace TT\Modules\Journey\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardInterface;

/**
 * NewInjuryWizard (#2609) — the wizard-first create flow for an injury
 * record (CLAUDE.md §3).
 *
 * An injury is one of the transitions §1 names explicitly — trial,
 * signing, promotion, injury, return to play — so it belongs on the
 * player's journey as a dated, queryable record rather than in a note.
 * The data layer has always modelled it; this is the way in.
 *
 * Steps: player → what (body part, type, severity) → when (dates + note),
 * which is the final step and persists. Entered from a player's file the
 * first step is skipped, because the player is already known.
 *
 * Gated on `tt_edit_player_medical`, which bridges to
 * `player_injuries:change` — head coach on their own team, head of
 * development and academy admin globally. An assistant coach holds no
 * `player_injuries` row at all, so the wizard is correctly unreachable
 * for them.
 */
final class NewInjuryWizard implements WizardInterface {

    public function slug(): string { return 'injury'; }

    public function label(): string { return __( 'Record injury', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_edit_player_medical'; }

    public function firstStepSlug(): string { return 'player'; }

    /** @return \TT\Shared\Wizards\WizardStepInterface[] */
    public function steps(): array {
        return [
            new InjuryPlayerStep(),
            new InjuryDetailsStep(),
            new InjuryWhenStep(),
        ];
    }

    /**
     * Seed `player_id` from the entry URL (#4270), but only a player on a
     * team this user may pick from in step 1 — a hand-edited URL does not
     * skip the step for somebody else's squad.
     *
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function initialState( array $get ): array {
        $player_id = isset( $get['player_id'] ) ? absint( $get['player_id'] ) : 0;
        if ( $player_id <= 0 ) return [];

        $player  = (array) ( QueryHelpers::get_player( $player_id ) ?? [] );
        $team_id = (int) ( $player['team_id'] ?? 0 );
        if ( $team_id <= 0 ) return [];

        $teams = QueryHelpers::get_teams_in_scope( get_current_user_id(), current_user_can( 'tt_edit_settings' ) );
        foreach ( $teams as $team ) {
            if ( (int) ( $team->id ?? 0 ) === $team_id ) return [ 'player_id' => $player_id ];
        }
        return [];
    }
}
