<?php
namespace TT\Modules\Vct\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Modules\Vct\Repositories\VctExercisesRepository;
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
use TT\Modules\Vct\Rules\SessionPlanContext;
use TT\Modules\Vct\Rules\TacticalThemeRule;
use TT\Modules\Vct\Rules\WorkloadCapRule;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;
use TT\Modules\Vct\Repositories\VctPhvFlagsRepository;
use TT\Modules\Vct\Repositories\VctSessionTemplatesRepository;
use TT\Modules\Vct\Repositories\VctTeamSchedulesRepository;
use TT\Modules\Vct\Repositories\VctWorkloadSnapshotsRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Preview. Runs the engine + shows the composed blocks.
 *
 * Calls `RulesEngine::compose()` server-side with a context built from
 * the wizard state so far. Renders each block (sequence, slot, picked
 * exercise + coaching cues, duration, intensity) + any engine warnings.
 *
 * Pure-read step — nothing persists until the Review step submits.
 * The composed shape is stashed in state so the Review step's submit
 * can reuse it without recomposing.
 */
final class PreviewStep implements WizardStepInterface {

    public function slug(): string { return 'preview'; }
    public function label(): string { return __( 'Preview', 'talenttrack' ); }

    public function render( array $state ): void {
        $ctx = $this->buildContext( $state );
        $engine = $this->makeEngine();
        $ctx = $engine->compose( $ctx );

        // Stash for the Review step's submit.
        echo '<input type="hidden" name="_vct_preview_md_context" value="' . esc_attr( $ctx->md_context ) . '">';
        echo '<input type="hidden" name="_vct_preview_age_group" value="' . esc_attr( $ctx->age_group ) . '">';

        $blocking = $this->blockingReasons( $ctx );
        if ( $blocking ) {
            echo '<div class="tt-notice tt-notice--error" role="alert">';
            echo '<p><strong>' . esc_html__( 'Cannot compose this VCT training:', 'talenttrack' ) . '</strong></p><ul>';
            foreach ( $blocking as $r ) {
                echo '<li>' . esc_html( $this->humanReason( $r ) ) . '</li>';
            }
            echo '</ul></div>';
            return;
        }

        // Header chips.
        $md_label = LookupTranslator::byTypeAndName( 'vct_md_context', $ctx->md_context );
        echo '<p>'
            . esc_html( sprintf(
                /* translators: 1: age group, 2: MD context label, 3: total minutes, 4: total load */
                __( '%1$s · %2$s · %3$d min · load %4$d', 'talenttrack' ),
                $ctx->age_group, $md_label, (int) ( $ctx->requested_duration_minutes ?? 0 ), $ctx->total_load
            ) ) . '</p>';

        echo '<table class="tt-table tt-wizard-review-table"><thead><tr>'
            . '<th>#</th>'
            . '<th>' . esc_html__( 'Slot', 'talenttrack' ) . '</th>'
            . '<th>' . esc_html__( 'Exercise', 'talenttrack' ) . '</th>'
            . '<th>' . esc_html__( 'Min', 'talenttrack' ) . '</th>'
            . '<th>' . esc_html__( 'Band', 'talenttrack' ) . '</th>'
            . '</tr></thead><tbody>';

        $repo = new VctExercisesRepository();
        foreach ( $ctx->blocks as $b ) {
            $name = '—';
            if ( ! empty( $b['exercise_id'] ) ) {
                $ex = $repo->find( (int) $b['exercise_id'] );
                if ( $ex !== null ) $name = (string) $ex['name_canonical'];
            } elseif ( ! empty( $b['custom_label'] ) ) {
                $name = (string) $b['custom_label'];
            }
            echo '<tr>'
                . '<td>' . esc_html( (string) $b['sequence'] ) . '</td>'
                . '<td>' . esc_html( LookupTranslator::byTypeAndName( 'vct_exercise_category', (string) $b['slot_category'] ) ) . '</td>'
                . '<td>' . esc_html( $name ) . '</td>'
                . '<td>' . esc_html( (string) $b['duration_minutes'] ) . '</td>'
                . '<td>' . esc_html( (string) $b['intensity_band'] ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';

        if ( $ctx->warnings ) {
            echo '<div class="tt-notice tt-notice--info" style="margin-top:12px;">';
            echo '<p><strong>' . esc_html__( 'Notes from the engine:', 'talenttrack' ) . '</strong></p><ul>';
            foreach ( $ctx->warnings as $w ) {
                $severity = (string) ( $w['severity'] ?? 'info' );
                if ( $severity === 'block' ) continue;
                echo '<li><em>' . esc_html( ucfirst( $severity ) ) . ':</em> ' . esc_html( $this->humanReason( $w ) ) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    public function validate( array $post, array $state ) {
        // No editable fields; just carry preview hints forward.
        return [
            '_vct_preview_md_context' => isset( $post['_vct_preview_md_context'] ) ? (string) $post['_vct_preview_md_context'] : null,
            '_vct_preview_age_group'  => isset( $post['_vct_preview_age_group'] )  ? (string) $post['_vct_preview_age_group']  : null,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }

    private function buildContext( array $state ): SessionPlanContext {
        $ctx = new SessionPlanContext();
        $ctx->team_id        = (int) ( $state['team_id'] ?? 0 );
        $ctx->session_date   = (string) ( $state['session_date'] ?? '' );
        $ctx->age_group      = $this->ageGroupForTeam( $ctx->team_id ) ?? 'U10';
        $ctx->tactical_theme = isset( $state['tactical_theme'] ) ? ( $state['tactical_theme'] ?: null ) : null;
        if ( isset( $state['requested_duration_minutes'] ) ) {
            $ctx->requested_duration_minutes = (int) $state['requested_duration_minutes'];
        }
        $ctx->generated_by = get_current_user_id();
        return $ctx;
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

    private function blockingReasons( SessionPlanContext $ctx ): array {
        $out = [];
        foreach ( $ctx->warnings as $w ) {
            if ( ( $w['severity'] ?? '' ) === 'block' ) $out[] = $w;
        }
        return $out;
    }

    /** Human-readable summary of a structured warning. */
    private function humanReason( array $w ): string {
        $code = (string) ( $w['code'] ?? 'unknown' );
        $details = (array) ( $w['details'] ?? [] );
        return $code . ' — ' . wp_json_encode( $details );
    }

    private function makeEngine(): RulesEngine {
        return new RulesEngine(
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
    }
}
