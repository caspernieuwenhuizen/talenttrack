<?php
namespace TT\Modules\Vct\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Duration. Bounded by the age profile's session_minutes_max.
 *
 * Looks up the age group from the team_id picked in Step 1 (via
 * tt_teams.age_group), then reads the per-club age profile to find
 * the ceiling. Defaults to the ceiling; coach can pick lower.
 */
final class DurationStep implements WizardStepInterface {

    public function slug(): string { return 'duration'; }
    public function label(): string { return __( 'Duration', 'talenttrack' ); }

    public function render( array $state ): void {
        $age_group = $this->ageGroupForTeam( (int) ( $state['team_id'] ?? 0 ) );
        $profile   = $age_group !== null
            ? ( new VctAgeProfilesRepository() )->findByAgeGroup( $age_group )
            : null;

        $max     = $profile !== null ? (int) $profile['session_minutes_max'] : 90;
        $current = isset( $state['requested_duration_minutes'] ) ? (int) $state['requested_duration_minutes'] : $max;
        if ( $current < 20 ) $current = $max;
        if ( $current > $max ) $current = $max;

        // #1518 — when the team has no age group, the cap silently fell
        // back to a default. Make that visible so the coach knows the
        // ceiling isn't tuned to their team yet.
        if ( $age_group === null ) {
            echo '<div class="tt-notice tt-notice--caution" role="status" style="padding:10px 14px;margin:0 0 12px;background:#fff8e1;border-left:4px solid #dba617;border-radius:6px;font-size:14px;">'
                . esc_html( sprintf(
                    /* translators: %d is the default minutes ceiling */
                    __( 'This team has no age group set, so we\'re using a default cap of %d minutes. Set the team\'s age group in its team settings for an age-tuned limit.', 'talenttrack' ),
                    $max
                ) )
                . '</div>';
        }

        echo '<p>' . esc_html(
            $age_group !== null
                ? sprintf(
                    /* translators: 1: age group, 2: minutes ceiling */
                    __( 'How long should this VCT training be? Age %1$s caps at %2$d minutes.', 'talenttrack' ),
                    $age_group, $max
                )
                : __( 'How long should this VCT training be? Pick a value within the age profile ceiling.', 'talenttrack' )
        ) . '</p>';

        echo '<label><span>' . esc_html__( 'Duration (minutes)', 'talenttrack' ) . '</span>'
            . '<input type="number" inputmode="numeric" name="requested_duration_minutes" min="20" max="' . esc_attr( (string) $max ) . '" step="5" value="' . esc_attr( (string) $current ) . '" required></label>';
    }

    public function validate( array $post, array $state ) {
        $req = isset( $post['requested_duration_minutes'] ) ? (int) $post['requested_duration_minutes'] : 0;
        if ( $req < 20 ) return new \WP_Error( 'bad_duration', __( 'Duration must be at least 20 minutes.', 'talenttrack' ) );

        $age_group = $this->ageGroupForTeam( (int) ( $state['team_id'] ?? 0 ) );
        $profile   = $age_group !== null
            ? ( new VctAgeProfilesRepository() )->findByAgeGroup( $age_group )
            : null;
        $max = $profile !== null ? (int) $profile['session_minutes_max'] : 120;
        if ( $req > $max ) {
            return new \WP_Error(
                'over_age_max',
                sprintf(
                    /* translators: 1: requested minutes, 2: age max */
                    __( 'Requested duration %1$d exceeds the age profile ceiling of %2$d minutes.', 'talenttrack' ),
                    $req, $max
                )
            );
        }
        return [ 'requested_duration_minutes' => $req ];
    }

    public function nextStep( array $state ): ?string { return 'preview'; }
    public function submit( array $state ) { return null; }

    private function ageGroupForTeam( int $team_id ): ?string {
        if ( $team_id <= 0 ) return null;
        global $wpdb;
        $tag = $wpdb->get_var( $wpdb->prepare(
            "SELECT age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d LIMIT 1",
            $team_id
        ) );
        return $tag !== null && $tag !== '' ? (string) $tag : null;
    }
}
