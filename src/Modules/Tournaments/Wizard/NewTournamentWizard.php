<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * #0093 chunk 4 — the new-tournament wizard.
 *
 * Five steps walk the coach from "I have a tournament weekend" to
 * "the planner is open with matches + squad pre-loaded":
 *
 *   1. BasicsStep      — name, anchor team, start_date, end_date.
 *                        Tournament age tier is derived from the
 *                        anchor team's age group (#975 decision).
 *   2. FormationStep   — radio-card grid with hand-drawn dot glyphs
 *                        per the #975 mockup; per-match override
 *                        happens later in the matches step or after.
 *   3. SquadStep       — multi-pick players from the anchor team's
 *                        roster + per-player eligible positions. The
 *                        specific position vocabulary is GK / CB / LB
 *                        / RB / DM / CM / AM / LW / RW / ST (#975
 *                        decision; matches the blueprint editor).
 *   4. MatchesStep     — repeatable card stack with live-updating
 *                        headline, per-card Remove button, and a
 *                        chip editor for substitution windows.
 *   5. ReviewStep      — card-per-step summary with Edit jump links
 *                        + Create. Inserts the tournament + squad +
 *                        match rows in one wpdb session and redirects
 *                        to the planner detail view.
 *
 * Cap gate: `tt_edit_tournaments` — admin-only in v1 by virtue of
 * which roles hold the cap.
 */
final class NewTournamentWizard implements WizardInterface {

    public function slug(): string { return 'new-tournament'; }
    public function label(): string { return __( 'New tournament', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_tournaments'; }
    public function firstStepSlug(): string { return 'basics'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new BasicsStep(),
            new FormationStep(),
            new SquadStep(),
            new MatchesStep(),
            new ReviewStep(),
        ];
    }
}
