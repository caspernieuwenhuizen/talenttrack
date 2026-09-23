<?php
namespace TT\Modules\Trials\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Repositories\TrialCasesRepository;

/**
 * TrialDecisionService (#4042) — record a trial decision and produce the
 * letter that goes with it.
 *
 * Deciding a trial used to mean two different things depending on the door
 * the decision came in by. The case screen recorded the decision and then
 * generated the letter for the matching audience; `POST
 * trial-cases/{id}/decision` recorded the decision and stopped. Same domain
 * action, two outcomes — and the rule saying which of the three letter
 * variants a decision warrants lived in a render class, where no API caller
 * and no test could reach it.
 *
 * Both paths call `record()` now, so they leave the case in the same state
 * (CLAUDE.md §4). Generating stays an explicit step, deliberately: the
 * screen's button reads "Record decision and generate letter", and nothing
 * here happens off a hook, so a decision written by the trial-group
 * workflow does not silently post a letter to a family.
 *
 * Generating is not sending. `TrialLetterService` writes the letter and
 * supersedes any earlier live one; getting it to the family stays a human
 * step the club records separately.
 */
final class TrialDecisionService {

    /**
     * Which letter a decision warrants, or null when it warrants none.
     *
     * The three Decision-tab outcomes each have their own letter. The
     * three the trial-group workflow writes do not: `offered_team_position`
     * and `continue_in_trial_group` do not end the trial at all, and
     * `declined_offered_position` is the family's answer rather than the
     * club's. Returning null for those is what keeps a case that was
     * offered a place from producing a final-denial letter — a letter
     * telling the family the opposite of what they were told.
     */
    public static function audienceFor( string $decision ): ?string {
        switch ( $decision ) {
            case TrialCaseDecision::ADMIT:              return AudienceType::TRIAL_ADMITTANCE;
            case TrialCaseDecision::DENY_FINAL:         return AudienceType::TRIAL_DENIAL_FINAL;
            case TrialCaseDecision::DENY_ENCOURAGEMENT: return AudienceType::TRIAL_DENIAL_ENCOURAGE;
        }
        return null;
    }

    /**
     * Record the decision, then generate its letter.
     *
     * The motivation is validated by the caller — `TrialDecisionMotivation`
     * is what both surfaces use, and it answers with a message they render
     * differently. What is shared is everything after it.
     *
     * @return array{recorded:bool, letter_id:int} `letter_id` is 0 when the
     *         decision was not recorded, warrants no letter, or the letter
     *         could not be written.
     */
    public function record(
        int $case_id,
        string $decision,
        int $actor_id,
        string $notes,
        ?string $strengths = null,
        ?string $growth = null
    ): array {
        $cases = new TrialCasesRepository();
        if ( ! $cases->recordDecision( $case_id, $decision, $actor_id, $notes, $strengths, $growth ) ) {
            return [ 'recorded' => false, 'letter_id' => 0 ];
        }

        return [
            'recorded'  => true,
            'letter_id' => $this->generateFor( $case_id, $decision, $actor_id, $strengths, $growth ),
        ];
    }

    /**
     * Generate the letter a recorded decision warrants.
     *
     * Read back from the stored case rather than from the request: the
     * letter is rendered from the row, and the row is what the family's
     * copy has to agree with.
     *
     * @return int The letter id, or 0 when none was written.
     */
    public function generateFor(
        int $case_id,
        string $decision,
        int $actor_id,
        ?string $strengths = null,
        ?string $growth = null
    ): int {
        $audience = self::audienceFor( $decision );
        if ( $audience === null ) return 0;

        $case = ( new TrialCasesRepository() )->find( $case_id );
        if ( ! $case ) return 0;

        // #3223 — `generate()` supersedes the prior live letter itself, so
        // there is no revoke to follow this call with.
        return ( new TrialLetterService() )->generate( $case, $audience, $actor_id, $strengths, $growth );
    }
}
