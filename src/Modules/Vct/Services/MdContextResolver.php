<?php
namespace TT\Modules\Vct\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Rules\Providers\ActivitiesReader;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Repositories\VctTeamSchedulesRepository;

/**
 * MdContextResolver — standalone helper for surfaces that need the
 * MD context BEFORE the full pipeline runs (e.g. the wizard's "When"
 * step previewing "this is MD-3" inline as the coach picks a date).
 *
 * Mirrors the logic baked into MdContextRule but can be called
 * outside the engine. Always returns `NONE` for U10/U11 regardless
 * of the Activities state (the `md_logic_enabled = 0` guard at the
 * age-profile level).
 */
class MdContextResolver {

    private ActivitiesReader $activities;
    private VctAgeProfilesRepository $age_profiles;
    private VctTeamSchedulesRepository $team_schedules;

    public function __construct(
        ActivitiesReader $activities,
        VctAgeProfilesRepository $age_profiles,
        VctTeamSchedulesRepository $team_schedules
    ) {
        $this->activities     = $activities;
        $this->age_profiles   = $age_profiles;
        $this->team_schedules = $team_schedules;
    }

    public function resolve( int $team_id, string $age_group, string $session_date ): string {
        $profile = $this->age_profiles->findByAgeGroup( $age_group );
        if ( $profile === null || ! (bool) $profile['md_logic_enabled'] ) {
            return 'NONE';
        }

        $session_ts = strtotime( $session_date );
        if ( $session_ts === false ) return 'NONE';

        $forward_end  = gmdate( 'Y-m-d', $session_ts + 14 * 86400 );
        $backward_end = gmdate( 'Y-m-d', $session_ts -  7 * 86400 );

        $next = $this->activities->nextMatchDate( $team_id, $session_date, $forward_end );
        $prev = $this->activities->previousMatchDate( $team_id, $backward_end, $session_date );

        $forward_days  = $next !== null ? $this->daysBetween( $session_date, $next ) : null;
        $backward_days = $prev !== null ? $this->daysBetween( $prev, $session_date ) : null;

        if ( $forward_days === 0 ) return 'MD';

        if ( $backward_days !== null && $backward_days <= 2 ) {
            return $backward_days === 1 ? 'MD+1' : 'MD+2';
        }
        if ( $forward_days !== null && $forward_days >= 1 && $forward_days <= 4 ) {
            return 'MD-' . $forward_days;
        }
        return 'NONE';
    }

    private function daysBetween( string $earlier, string $later ): ?int {
        $a = strtotime( $earlier );
        $b = strtotime( $later );
        if ( $a === false || $b === false ) return null;
        return (int) floor( ( $b - $a ) / 86400 );
    }
}
