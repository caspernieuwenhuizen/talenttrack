<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Onboarding\OnboardingState;

/**
 * #2958 — the squad-import step in the onboarding wizard.
 *
 * The step order is the load-bearing part: the stepper derives progress
 * from `OnboardingState::STEPS` by position, so inserting a slug in the
 * wrong place silently mis-numbers every step after it. And `import` has
 * to come before `first_team`, or a club is asked to type its first team
 * by hand before it is offered the chance to upload one.
 */
final class OnboardingImportStepTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        OnboardingState::reset();
    }

    public function tear_down(): void {
        OnboardingState::reset();
        parent::tear_down();
    }

    public function test_import_sits_between_academy_and_first_team(): void {
        $steps = OnboardingState::STEPS;

        $academy    = array_search( 'academy', $steps, true );
        $import     = array_search( 'import', $steps, true );
        $first_team = array_search( 'first_team', $steps, true );

        $this->assertNotFalse( $import, 'the import step is missing from STEPS' );
        $this->assertGreaterThan( $academy, $import );
        $this->assertLessThan( $first_team, $import );
    }

    public function test_the_step_is_a_valid_target(): void {
        // setStep() silently ignores a slug that is not in STEPS, so a
        // typo would leave the wizard stuck on the previous step.
        OnboardingState::setStep( 'import' );

        $this->assertSame( 'import', OnboardingState::get()['step'] );
    }

    public function test_skipping_records_the_skip_and_moves_on(): void {
        OnboardingState::setStep( 'import' );
        OnboardingState::recordPayload( 'import', [ 'skipped' => true ] );
        OnboardingState::setStep( 'first_team' );

        $this->assertTrue( (bool) OnboardingState::payloadFor( 'import' )['skipped'] );
        $this->assertSame( 'first_team', OnboardingState::get()['step'] );
    }

    public function test_a_preview_payload_is_not_a_commit(): void {
        // The renderer distinguishes "here is what the file holds" from
        // "these records now exist" purely on `committed`. If a preview
        // ever recorded committed:true, first_team would tell the admin
        // teams exist that were never written.
        OnboardingState::recordPayload( 'import', [
            'filename'  => 'squad.xlsx',
            'imported'  => [ 'teams' => 3 ],
            'committed' => false,
        ] );

        $payload = OnboardingState::payloadFor( 'import' );
        $this->assertFalse( (bool) $payload['committed'] );
        $this->assertSame( [ 'teams' => 3 ], $payload['imported'] );
    }

    public function test_a_committed_import_records_its_counts(): void {
        OnboardingState::recordPayload( 'import', [
            'filename'  => 'squad.xlsx',
            'imported'  => [ 'teams' => 2, 'players' => 24 ],
            'committed' => true,
        ] );

        $payload = OnboardingState::payloadFor( 'import' );
        $this->assertTrue( (bool) $payload['committed'] );
        $this->assertSame( 2, $payload['imported']['teams'] );
    }

    public function test_the_stepper_still_numbers_every_step( ): void {
        // Every slug the dispatcher renders must be in STEPS, or the
        // header's array_search returns false and the stepper breaks.
        foreach ( [ 'welcome', 'academy', 'import', 'first_team', 'first_admin', 'dashboard', 'done' ] as $slug ) {
            $this->assertContains( $slug, OnboardingState::STEPS, "'$slug' is not a known step" );
        }
    }
}
