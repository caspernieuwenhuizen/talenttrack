<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RuleMessages — translates the rules engine's structured warning
 * codes into coach-readable sentences.
 *
 * The engine emits machine codes + a details payload (see
 * SessionPlanContext::addWarning). Those codes are stable contract;
 * they must never reach a coach's screen raw. This class is the single
 * place that maps a `{ code, severity, details }` warning to:
 *
 *   - humanMessage()  — a plain-language sentence ("Some exercises
 *                       aren't approved for this age group.").
 *   - resolutionHint() — for blocking warnings, a short "what to do
 *                       next" pointer ("Try a later date or adjust the
 *                       team's age group in VCT settings.").
 *
 * Lives in the rules layer (not the view) so the REST controller and
 * the wizard's Preview step share one vocabulary — a future SaaS front
 * end gets the same sentences (CLAUDE.md §4: business logic out of
 * views). Strings are wrapped in __() so they translate; the details
 * payload is interpolated through sprintf where it adds clarity.
 */
final class RuleMessages {

    /**
     * Readable sentence for a single structured warning.
     *
     * @param array{code?:string,severity?:string,details?:array<string,mixed>} $warning
     */
    public static function humanMessage( array $warning ): string {
        $code    = (string) ( $warning['code'] ?? 'unknown' );
        $details = (array) ( $warning['details'] ?? [] );

        switch ( $code ) {
            case 'missing_age_profile':
                return __( 'No training profile is set up for this age group yet, so the safe-load limits are unknown.', 'talenttrack' );

            case 'unrecognised_age_group_for_selection':
                return __( "This team's age group isn't recognised, so the engine can't pick suitable exercises.", 'talenttrack' );

            case 'missing_session_template':
                return __( 'No session blueprint exists for this age group and match-day context, so there is nothing to build the training from.', 'talenttrack' );

            case 'block_intensity_exceeds_age_ceiling':
                return __( 'One block was more intense than this age group allows, so it was capped to the safe ceiling.', 'talenttrack' );

            case 'no_candidate_for_slot':
                $category = isset( $details['category'] ) ? (string) $details['category'] : '';
                if ( $category !== '' ) {
                    return sprintf(
                        /* translators: %s is the exercise slot category */
                        __( 'No suitable exercise was found for the "%s" slot at this age, theme, and match-day context.', 'talenttrack' ),
                        $category
                    );
                }
                return __( 'No suitable exercise was found for one of the slots at this age, theme, and match-day context.', 'talenttrack' );

            case 'below_recovery_gap':
                $required = isset( $details['required_hours'] ) ? (int) $details['required_hours'] : 0;
                if ( $required > 0 ) {
                    return sprintf(
                        /* translators: %d is the recommended recovery hours */
                        __( 'Some players trained hard less than %d hours ago and may not be fully recovered.', 'talenttrack' ),
                        $required
                    );
                }
                return __( 'Some players trained hard recently and may not be fully recovered.', 'talenttrack' );

            case 'near_weekly_envelope':
                return __( "This training adds a lot of load for the week — keep an eye on the team's total.", 'talenttrack' );

            case 'requested_duration_capped_to_age_max':
                $capped = isset( $details['capped_to'] ) ? (int) $details['capped_to'] : 0;
                if ( $capped > 0 ) {
                    return sprintf(
                        /* translators: %d is the age-safe maximum minutes */
                        __( 'The duration was shortened to %d minutes, the safe maximum for this age group.', 'talenttrack' ),
                        $capped
                    );
                }
                return __( 'The duration was shortened to the safe maximum for this age group.', 'talenttrack' );

            case 'no_macro_block_configured':
                return __( 'No season periodisation block applies here, so a neutral training load was used.', 'talenttrack' );

            case 'phv_load_reduction_applied':
                $pct = isset( $details['reduction_pct'] ) ? (int) $details['reduction_pct'] : 0;
                if ( $pct > 0 ) {
                    return sprintf(
                        /* translators: %d is the percentage the load was reduced by */
                        __( 'The load was eased by %d%% because some players are flagged for a growth spurt (PHV).', 'talenttrack' ),
                        $pct
                    );
                }
                return __( 'The load was eased because some players are flagged for a growth spurt (PHV).', 'talenttrack' );
        }

        // Unknown code — a safe, non-technical fallback.
        return __( 'The engine flagged something about this training. Review the blocks before publishing.', 'talenttrack' );
    }

    /**
     * "What to do next" pointer for a blocking warning. Returns an
     * empty string for codes that don't block (callers only show the
     * hint for `severity = block` warnings).
     *
     * @param array{code?:string,severity?:string,details?:array<string,mixed>} $warning
     */
    public static function resolutionHint( array $warning ): string {
        $code = (string) ( $warning['code'] ?? 'unknown' );

        switch ( $code ) {
            case 'missing_age_profile':
                return __( "Open VCT configuration → Age profiles and set up this age group's limits.", 'talenttrack' );

            case 'unrecognised_age_group_for_selection':
                return __( "Set this team's age group (for example U13) in its team settings, then try again.", 'talenttrack' );

            case 'missing_session_template':
                return __( 'Pick a different date so the match-day context changes, or ask an admin to add a session blueprint for this age group.', 'talenttrack' );

            case 'block_intensity_exceeds_age_ceiling':
                return __( 'No action needed — the block was already capped to the safe ceiling for you.', 'talenttrack' );
        }

        return '';
    }
}
