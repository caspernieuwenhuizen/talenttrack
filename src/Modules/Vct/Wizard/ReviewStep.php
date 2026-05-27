<?php
namespace TT\Modules\Vct\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctSessionBlocksRepository;
use TT\Modules\Vct\Repositories\VctSessionsRepository;
use TT\Modules\Vct\Rules\AgeAdmissibilityRule;
use TT\Modules\Vct\Rules\ExerciseSelectionPass;
use TT\Modules\Vct\Rules\FinalizationPass;
use TT\Modules\Vct\Rules\MdContextRule;
use TT\Modules\Vct\Rules\ProgressionRule;
use TT\Modules\Vct\Rules\Providers\NativeActivitiesReader;
use TT\Modules\Vct\Rules\Providers\NativeRecentPicksProvider;
use TT\Modules\Vct\Rules\RecoveryRule;
use TT\Modules\Vct\Rules\RulesEngine;
use TT\Modules\Vct\Rules\SessionCompositionRule;
use TT\Modules\Vct\Rules\TacticalThemeRule;
use TT\Modules\Vct\Rules\WorkloadCapRule;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Repositories\VctExercisesRepository;
use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;
use TT\Modules\Vct\Repositories\VctPhvFlagsRepository;
use TT\Modules\Vct\Repositories\VctSessionTemplatesRepository;
use TT\Modules\Vct\Repositories\VctTeamSchedulesRepository;
use TT\Modules\Vct\Repositories\VctWorkloadSnapshotsRepository;
use TT\Modules\Vct\Services\VctTrainingComposer;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 5 — Review + save.
 *
 * Recomposes via VctTrainingComposer (the same path POST
 * /vct/sessions/generate uses) and persists the draft VCT-training
 * row + its blocks. Redirects to ?tt_view=vct-session&id=N (which
 * VCT-10 renders).
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $md = (string) ( $state['_vct_preview_md_context'] ?? 'NONE' );
        $age = (string) ( $state['_vct_preview_age_group'] ?? 'U10' );
        $theme = (string) ( $state['tactical_theme'] ?? '' );

        echo '<p>' . esc_html__( 'Save this VCT training as a draft. You can publish it from the detail page.', 'talenttrack' ) . '</p>';
        echo '<table class="tt-table tt-wizard-review-table"><tbody>';
        echo '<tr><th>' . esc_html__( 'Team',     'talenttrack' ) . '</th><td>' . esc_html( $this->teamName( (int) ( $state['team_id'] ?? 0 ) ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Date',     'talenttrack' ) . '</th><td>' . esc_html( (string) ( $state['session_date'] ?? '' ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Age',      'talenttrack' ) . '</th><td>' . esc_html( $age ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'MD',       'talenttrack' ) . '</th><td>' . esc_html( $md ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Theme',    'talenttrack' ) . '</th><td>' . esc_html( $theme !== '' ? $theme : __( '— none —', 'talenttrack' ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Duration', 'talenttrack' ) . '</th><td>' . esc_html( (string) ( $state['requested_duration_minutes'] ?? '' ) ) . ' ' . esc_html__( 'min', 'talenttrack' ) . '</td></tr>';
        echo '</tbody></table>';
    }

    public function validate( array $post, array $state ) { return []; }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $payload = [
            'team_id'                    => (int)    ( $state['team_id']      ?? 0 ),
            'session_date'               => (string) ( $state['session_date'] ?? '' ),
            'age_group'                  => (string) ( $state['_vct_preview_age_group'] ?? ( $this->ageGroupForTeam( (int) ( $state['team_id'] ?? 0 ) ) ?? 'U10' ) ),
            'tactical_theme'             => isset( $state['tactical_theme'] ) ? ( $state['tactical_theme'] ?: null ) : null,
            'requested_duration_minutes' => isset( $state['requested_duration_minutes'] ) ? (int) $state['requested_duration_minutes'] : null,
            'roster_player_ids'          => [],
            'generated_by'               => get_current_user_id(),
        ];

        $engine = new RulesEngine(
            new AgeAdmissibilityRule( new VctAgeProfilesRepository() ),
            new MdContextRule( new NativeActivitiesReader(), new VctTeamSchedulesRepository() ),
            new SessionCompositionRule( new VctSessionTemplatesRepository() ),
            new TacticalThemeRule(),
            new ProgressionRule( new VctMacroBlocksRepository() ),
            new ExerciseSelectionPass( new VctExercisesRepository(), new NativeRecentPicksProvider() ),
            new WorkloadCapRule( new VctPhvFlagsRepository() ),
            new RecoveryRule( new VctWorkloadSnapshotsRepository() ),
            new FinalizationPass()
        );
        $composer = new VctTrainingComposer(
            $engine,
            new VctSessionsRepository(),
            new VctSessionBlocksRepository()
        );

        $result = $composer->generate( $payload );
        if ( $result === null ) {
            return new \WP_Error(
                'vct_validation',
                __( 'The VCT training could not be composed. Pick another date or check the team configuration.', 'talenttrack' )
            );
        }

        $new_id = (int) ( $result['session']['id'] ?? 0 );
        if ( $new_id <= 0 ) {
            return new \WP_Error( 'db_error', __( 'The VCT training was composed but not persisted.', 'talenttrack' ) );
        }

        return [
            'redirect_url' => add_query_arg(
                [ 'tt_view' => 'vct-session', 'id' => $new_id ],
                \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl()
            ),
        ];
    }

    private function teamName( int $team_id ): string {
        if ( $team_id <= 0 ) return '—';
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT name, age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d LIMIT 1",
            $team_id
        ) );
        if ( ! $row ) return '—';
        $name = (string) ( $row->name ?? '' );
        $age  = (string) ( $row->age_group ?? '' );
        return $age !== '' ? $name . ' (' . $age . ')' : $name;
    }

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
