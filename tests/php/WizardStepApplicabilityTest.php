<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\ReportStudio\Wizard\NewReportWizard;
use TT\Modules\Wizards\Evaluation\NewEvaluationWizard;
use TT\Modules\Wizards\Player\NewPlayerWizard;
use TT\Shared\Frontend\FrontendWizardView;
use TT\Shared\Wizards\ConditionalStepInterface;
use TT\Shared\Wizards\WizardInterface;
use TT\Shared\Wizards\WizardRegistry;

/**
 * #4744 — conditional steps say yes, no or unknown, and the step counter
 * drops only "no".
 *
 * A step whose condition hangs on an answer not given yet still counts, so
 * the "Step X of Y" total never grows while the user moves forward without
 * changing an answer. Changing an earlier answer may add a step back; that
 * growth is the user's own doing.
 */
final class WizardStepApplicabilityTest extends WP_UnitTestCase {

    public function test_every_step_with_a_condition_declares_the_contract(): void {
        $checked = 0;
        foreach ( WizardRegistry::all() as $wizard ) {
            foreach ( $wizard->steps() as $step ) {
                if ( ! method_exists( $step, 'notApplicableFor' ) ) continue;
                $this->assertInstanceOf(
                    ConditionalStepInterface::class,
                    $step,
                    get_class( $step ) . ' has a condition but does not declare ConditionalStepInterface, so the runner would no longer skip it'
                );
                $checked++;
            }
        }
        $this->assertGreaterThan( 0, $checked );
    }

    public function test_applicability_is_always_one_of_the_three_answers(): void {
        $answers = [ ConditionalStepInterface::APPLIES, ConditionalStepInterface::SKIPPED, ConditionalStepInterface::UNKNOWN ];
        foreach ( WizardRegistry::all() as $wizard ) {
            foreach ( $wizard->steps() as $step ) {
                if ( ! $step instanceof ConditionalStepInterface ) continue;
                $this->assertContains( $step->applicability( [] ), $answers, get_class( $step ) );
            }
        }
    }

    /** @return array<string, array{0: WizardInterface, 1: list<array{0: string, 1: array<string, mixed>}>}> */
    public static function forwardRuns(): array {
        $activity = [ 'mode' => 'activity', '_path' => 'activity-first' ];
        $player   = [ 'mode' => 'player', '_path' => 'player-first' ];
        $report   = [ 'kind' => 'report', 'audience' => 'internal' ];

        return [
            'evaluation, activity branch' => [ new NewEvaluationWizard(), [
                [ 'evaluation-mode', [] ],
                [ 'activity-picker', $activity ],
                [ 'attendance', $activity + [ 'activity_id' => 999999 ] ],
                [ 'rate-confirm', $activity + [ 'activity_id' => 999999 ] ],
                [ 'rate-actors', $activity + [ 'activity_id' => 999999 ] ],
                [ 'review', $activity + [ 'activity_id' => 999999 ] ],
            ] ],
            'evaluation, player branch' => [ new NewEvaluationWizard(), [
                [ 'evaluation-mode', [] ],
                [ 'player-picker', $player ],
                [ 'hybrid-deep-rate', $player + [ 'player_id' => 1 ] ],
                [ 'review', $player + [ 'player_id' => 1 ] ],
            ] ],
            'evaluation, opened from an activity' => [ new NewEvaluationWizard(), [
                [ 'attendance', $activity + [ 'activity_id' => 999999 ] ],
                [ 'rate-confirm', $activity + [ 'activity_id' => 999999 ] ],
                [ 'review', $activity + [ 'activity_id' => 999999 ] ],
            ] ],
            'player, roster' => [ new NewPlayerWizard(), [
                [ 'path', [] ],
                [ 'roster-details', [ 'path' => 'roster' ] ],
                [ 'review', [ 'path' => 'roster', 'first_name' => 'A', 'last_name' => 'B' ] ],
            ] ],
            'player, trial' => [ new NewPlayerWizard(), [
                [ 'path', [] ],
                [ 'trial-details', [ 'path' => 'trial' ] ],
                [ 'review', [ 'path' => 'trial', 'first_name' => 'A', 'last_name' => 'B' ] ],
            ] ],
            'report, fixed binding' => [ new NewReportWizard(), [
                [ 'what', [] ],
                [ 'readers', [ 'scope' => 'team' ] + $report ],
                [ 'subject', [ 'scope' => 'team', 'binding' => 'fixed' ] + $report ],
                [ 'when', [ 'scope' => 'team', 'binding' => 'fixed', 'team_id' => 1 ] + $report ],
            ] ],
            'report, chosen when opened' => [ new NewReportWizard(), [
                [ 'what', [] ],
                [ 'readers', [ 'scope' => 'player' ] + $report ],
                [ 'when', [ 'scope' => 'player', 'binding' => 'on_open' ] + $report ],
            ] ],
            'report, about me' => [ new NewReportWizard(), [
                [ 'what', [] ],
                [ 'readers', [ 'scope' => 'me' ] + $report ],
                [ 'when', [ 'scope' => 'me', 'binding' => 'viewer' ] + $report ],
            ] ],
        ];
    }

    /**
     * @dataProvider forwardRuns
     * @param list<array{0: string, 1: array<string, mixed>}> $run
     */
    public function test_moving_forward_never_raises_the_total( WizardInterface $wizard, array $run ): void {
        $previous = PHP_INT_MAX;
        foreach ( $run as [ $slug, $state ] ) {
            [ $position, $total ] = $this->counter( $wizard, $slug, $state );
            $this->assertLessThanOrEqual( $previous, $total, "the total grew at {$slug}" );
            $this->assertLessThanOrEqual( $total, $position, "{$slug} sits inside the total" );
            $previous = $total;
        }
    }

    public function test_the_evaluation_fork_counts_both_branches_until_one_is_picked(): void {
        $wizard = new NewEvaluationWizard();
        [ , $before ] = $this->counter( $wizard, 'evaluation-mode', [] );
        [ , $activity ] = $this->counter( $wizard, 'activity-picker', [ 'mode' => 'activity', '_path' => 'activity-first' ] );
        [ , $player ] = $this->counter( $wizard, 'player-picker', [ 'mode' => 'player', '_path' => 'player-first' ] );

        $this->assertLessThan( $before, $activity, 'picking the activity branch drops the player branch' );
        $this->assertLessThan( $before, $player, 'picking the player branch drops the activity branch' );
    }

    public function test_changing_an_earlier_answer_may_add_a_step(): void {
        $wizard = new NewReportWizard();
        // Opened from a player's page: the subject is known, so it is skipped.
        [ , $from_page ] = $this->counter( $wizard, 'what', [ 'scope' => 'player', 'player_id' => 5, 'subject_from_page' => 1 ] );
        // The user switches the scope to a team: the subject is open again.
        [ , $changed ] = $this->counter( $wizard, 'readers', [ 'scope' => 'team', 'team_id' => 0, 'player_id' => 0, 'subject_from_page' => 1, 'kind' => 'report' ] );

        $this->assertSame( $from_page + 1, $changed );
    }

    /**
     * The rail's "Step X of Y" for `$slug` with `$state`.
     *
     * @param array<string, mixed> $state
     * @return array{0:int, 1:int}
     */
    private function counter( WizardInterface $wizard, string $slug, array $state ): array {
        $method = new \ReflectionMethod( FrontendWizardView::class, 'renderProgress' );
        $method->setAccessible( true );

        ob_start();
        $method->invoke( null, $wizard, $slug, $state );
        $html = (string) ob_get_clean();

        $this->assertSame( 1, preg_match( '/Step (\d+) of (\d+)/', $html, $m ), 'no step counter rendered' );
        return [ (int) $m[1], (int) $m[2] ];
    }
}
