<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;

/**
 * AgeAdmissibilityRule — Pass 1.
 *
 * Reads the per-club age profile for the requested age_group and
 * stamps the constraint fields onto the context: intensity ceiling,
 * session max minutes, MD-logic flag, recovery gap, PHV reduction
 * percentage, match-load multiplier, weekly envelope.
 *
 * If no profile exists for the age (operator deleted it, or the age
 * isn't seeded), the pipeline emits a `block`-severity warning so
 * the caller surfaces a 400 instead of silently composing with
 * default ceilings.
 */
class AgeAdmissibilityRule implements RulePass {

    private VctAgeProfilesRepository $age_profiles;

    public function __construct( VctAgeProfilesRepository $age_profiles ) {
        $this->age_profiles = $age_profiles;
    }

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        $profile = $this->age_profiles->findByAgeGroup( $ctx->age_group );

        if ( $profile === null ) {
            $ctx->addWarning( 'missing_age_profile', 'block', [
                'age_group' => $ctx->age_group,
            ] );
            return $ctx;
        }

        $ctx->intensity_band_max                = (int)   $profile['intensity_band_max'];
        $ctx->session_minutes_max               = (int)   $profile['session_minutes_max'];
        $ctx->md_logic_enabled                  = (bool)  $profile['md_logic_enabled'];
        $ctx->min_recovery_hours_between_high   = (int)   $profile['min_recovery_hours_between_high'];
        $ctx->growth_spurt_load_reduction_pct   = (int)   $profile['growth_spurt_load_reduction_pct'];
        $ctx->match_load_multiplier_per_minute  = (float) $profile['match_load_multiplier_per_minute'];
        $ctx->weekly_load_envelope              = (int)   $profile['weekly_load_envelope'];

        // Clamp any client-supplied requested duration to the age
        // ceiling. Doing this here means downstream passes (composition,
        // selection) operate on a duration that's already safe; the
        // template still drives the slot proportions.
        if ( $ctx->requested_duration_minutes !== null
            && $ctx->requested_duration_minutes > $ctx->session_minutes_max ) {
            $ctx->addWarning( 'requested_duration_capped_to_age_max', 'info', [
                'requested' => $ctx->requested_duration_minutes,
                'capped_to' => $ctx->session_minutes_max,
            ] );
            $ctx->requested_duration_minutes = $ctx->session_minutes_max;
        }

        return $ctx;
    }
}
