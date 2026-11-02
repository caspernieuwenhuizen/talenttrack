<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\ReportStudio\Wizard\NewReportWizard;
use TT\Modules\ReportStudio\Wizard\SubjectStep;
use TT\Shared\Frontend\FrontendWizardView;
use TT\Shared\Wizards\WizardInterface;
use TT\Shared\Wizards\WizardRegistry;

/**
 * #4711 — the wizard's "Step X of Y" total only shrinks during a run.
 *
 * The report start wizard counted its subject step as skipped before the
 * binding was chosen, so the total read 5 and grew to 6 once "always the
 * same subject" was picked. A conditional step now counts until its
 * condition is known to be false.
 */
final class WizardStepTotalTest extends WP_UnitTestCase {

    public function test_the_subject_step_counts_until_the_binding_is_known(): void {
        $step = new SubjectStep();

        $this->assertFalse( $step->notApplicableFor( [] ), 'nothing answered yet: counted' );
        $this->assertFalse( $step->notApplicableFor( [ 'scope' => 'player' ] ), 'no binding yet: counted' );
        $this->assertFalse( $step->notApplicableFor( [ 'scope' => 'team', 'binding' => '' ] ), 'no binding yet: counted' );
        $this->assertTrue( $step->notApplicableFor( [ 'scope' => 'player', 'binding' => 'on_open' ] ) );
        $this->assertTrue( $step->notApplicableFor( [ 'scope' => 'team', 'binding' => 'viewer' ] ) );
        $this->assertTrue( $step->notApplicableFor( [ 'scope' => 'me' ] ), 'a known scope without a subject still skips at once' );
        $this->assertTrue( $step->notApplicableFor( [ 'scope' => 'academy' ] ) );
    }

    public function test_new_report_reads_of_6_from_step_1(): void {
        $wizard = new NewReportWizard();

        $this->assertSame( [ 1, 6 ], $this->counter( $wizard, 'what', [] ) );
        $this->assertSame( [ 2, 6 ], $this->counter( $wizard, 'readers', [ 'kind' => 'report', 'scope' => 'player' ] ) );
    }

    public function test_the_total_drops_to_5_only_after_a_non_fixed_binding(): void {
        $wizard = new NewReportWizard();
        $answered = [ 'kind' => 'report', 'scope' => 'player', 'audience' => 'internal' ];

        $this->assertSame( [ 3, 6 ], $this->counter( $wizard, 'subject', $answered + [ 'binding' => 'fixed' ] ) );
        $this->assertSame( [ 3, 5 ], $this->counter( $wizard, 'when', $answered + [ 'binding' => 'on_open' ] ) );
    }

    public function test_the_total_never_grows_through_a_run(): void {
        $wizard = new NewReportWizard();
        $runs = [
            'fixed binding'   => [
                [ 'what', [] ],
                [ 'readers', [ 'kind' => 'report', 'scope' => 'team' ] ],
                [ 'subject', [ 'kind' => 'report', 'scope' => 'team', 'audience' => 'internal', 'binding' => 'fixed' ] ],
                [ 'when', [ 'kind' => 'report', 'scope' => 'team', 'audience' => 'internal', 'binding' => 'fixed', 'team_id' => 1 ] ],
            ],
            'chosen on open'  => [
                [ 'what', [] ],
                [ 'readers', [ 'kind' => 'report', 'scope' => 'player' ] ],
                [ 'when', [ 'kind' => 'report', 'scope' => 'player', 'audience' => 'internal', 'binding' => 'on_open' ] ],
            ],
            'about me'        => [
                [ 'what', [] ],
                [ 'readers', [ 'kind' => 'report', 'scope' => 'me' ] ],
                [ 'when', [ 'kind' => 'report', 'scope' => 'me', 'audience' => 'internal', 'binding' => 'viewer' ] ],
            ],
        ];

        foreach ( $runs as $name => $run ) {
            $previous = PHP_INT_MAX;
            foreach ( $run as [ $slug, $state ] ) {
                [ , $total ] = $this->counter( $wizard, $slug, $state );
                $this->assertLessThanOrEqual( $previous, $total, "{$name}: the total grew at {$slug}" );
                $previous = $total;
            }
        }
    }

    public function test_a_wizard_without_conditional_steps_counts_every_step(): void {
        $checked = 0;
        foreach ( WizardRegistry::all() as $wizard ) {
            $steps = $wizard->steps();
            if ( count( $steps ) <= 1 ) continue;
            foreach ( $steps as $step ) {
                if ( method_exists( $step, 'notApplicableFor' ) ) continue 2;
            }
            $this->assertSame( [ 1, count( $steps ) ], $this->counter( $wizard, $wizard->firstStepSlug(), [] ), $wizard->slug() );
            $checked++;
        }
        $this->assertGreaterThan( 0, $checked, 'no wizard without conditional steps was found to check' );
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
