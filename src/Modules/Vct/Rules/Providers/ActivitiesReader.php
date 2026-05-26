<?php
namespace TT\Modules\Vct\Rules\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ActivitiesReader — VCT-owned interface over the Activities module.
 *
 * The MdContextRule needs to know the headline match anchoring this week's
 * match-day cycle. Rather than reach into ActivitiesRepository internals,
 * VCT declares this narrow interface; the production implementation
 * (NativeActivitiesReader) wraps the Activities repository. Tests inject
 * an in-memory fake that returns canned answers.
 *
 * Architectural rationale: spec § Module isolation via narrow contracts —
 * each cross-module dependency one-way, with this-module-owned interfaces.
 */
interface ActivitiesReader {

    /**
     * Return the date (YYYY-MM-DD) of the team's next match within the
     * given range, or null if no match is scheduled. "Match" = an
     * Activity with activity_type = 'match' (or any subtype the team
     * planner registers as match-flavoured).
     */
    public function nextMatchDate( int $team_id, string $window_start, string $window_end ): ?string;

    /**
     * Return the date of the team's most recent past match within the
     * given range, or null. Used by the MdContext resolver to detect
     * MD+1 / MD+2 (post-match recovery).
     */
    public function previousMatchDate( int $team_id, string $window_start, string $window_end ): ?string;
}
