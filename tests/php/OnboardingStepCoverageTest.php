<?php
namespace TT\Tests\Php;

use TT\Modules\Onboarding\OnboardingState;
use WP_UnitTestCase;

/**
 * #3140 — the guard the issue asks for.
 *
 * Four steps were added to the wp-admin wizard without a frontend arm, and
 * nothing failed when they were: `profile` (#3038), `import` (#2958),
 * `staff` (#2965) and `messaging` (#3113) all fell through to
 * *"Unknown step."*. A separate drift put five titles in the frontend
 * stepper against a ten-step state machine, and #3025 had already fixed
 * the same class of bug in the wp-admin map.
 *
 * So: every entry in `STEPS` must have a title in the one shared registry,
 * and every entry must be handled by both surfaces — either with a render
 * arm or, on the frontend, by the deliberate not-yet-ported state.
 */
final class OnboardingStepCoverageTest extends WP_UnitTestCase {

    private function source( string $relative ): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
    }

    public function test_every_step_has_a_title_in_the_shared_registry(): void {
        $titles = OnboardingState::stepTitles();

        foreach ( OnboardingState::STEPS as $step ) {
            $this->assertArrayHasKey(
                $step,
                $titles,
                "Step '{$step}' has no title, so the stepper would render a gap on both surfaces."
            );
            $this->assertNotSame( '', trim( $titles[ $step ] ) );
        }
    }

    public function test_the_registry_carries_no_title_for_a_step_that_does_not_exist(): void {
        foreach ( array_keys( OnboardingState::stepTitles() ) as $key ) {
            $this->assertContains(
                $key,
                OnboardingState::STEPS,
                "'{$key}' has a title but is not a step — a rename left the old key behind."
            );
        }
    }

    /**
     * The wp-admin wizard carries a render arm for every step. This is the
     * assertion #3025 would have wanted.
     */
    public function test_the_wp_admin_wizard_handles_every_step(): void {
        $src = $this->source( 'src/Modules/Onboarding/Admin/OnboardingPage.php' );

        foreach ( OnboardingState::STEPS as $step ) {
            $this->assertStringContainsString(
                "case '{$step}':",
                $src,
                "The wp-admin wizard has no arm for '{$step}'."
            );
        }
    }

    /**
     * The frontend view handles every step too — but "handled" here means
     * either a render arm or the not-yet-ported state, because three steps
     * are real ports filed separately. What it must never do again is fall
     * through to a dead end whose only exit is Start over.
     */
    public function test_the_frontend_view_handles_every_step(): void {
        $src     = $this->source( 'src/Shared/Frontend/FrontendSetupView.php' );
        $ported  = [];
        $pending = [];

        foreach ( OnboardingState::STEPS as $step ) {
            if ( strpos( $src, "case '{$step}':" ) !== false ) {
                $ported[] = $step;
            } else {
                $pending[] = $step;
            }
        }

        $this->assertContains( 'messaging', $ported, '#3140 ports the messaging step.' );
        $this->assertContains( 'profile', $ported, '#3259 ports the install-profile step.' );
        $this->assertContains( 'import', $ported, '#3260 ports the squad-import step.' );
        $this->assertSame(
            [ 'staff' ],
            $pending,
            'Only the staff step, filed as #3261, may still be unported. '
            . 'A second means a step was added without a frontend arm again.'
        );

        $this->assertStringContainsString(
            'renderNotYetPorted',
            $src,
            'The unported steps need the honest not-yet-available state.'
        );
        $this->assertStringNotContainsString(
            'Unknown step.',
            $src,
            'The dead end is what this issue removes.'
        );
    }

    /**
     * The messaging write is not forked. #3113's guarantee — the handler
     * inverts against the **registered** switchable set, never the POST,
     * so a template cannot be enabled by omission — has to be inherited,
     * and it is inherited by calling the same method.
     */
    public function test_the_frontend_messaging_write_goes_through_the_shared_handler(): void {
        $rest = $this->source( 'src/Infrastructure/REST/OnboardingRestController.php' );

        $this->assertStringContainsString( 'OnboardingHandlers::applyMessaging', $rest );
        $this->assertStringContainsString( 'OnboardingHandlers::skipMessaging', $rest );
        $this->assertStringNotContainsString(
            'TemplateSwitch',
            $rest,
            'The REST layer must not write the switch itself — that is the fork the issue forbids.'
        );

        $view = $this->source( 'src/Shared/Frontend/FrontendSetupView.php' );
        $this->assertStringNotContainsString(
            'TemplateSwitch::setDisabled',
            $view,
            'The view renders the choice; the handler makes it.'
        );
    }

    /**
     * #3259 — same rule, the profile step.
     *
     * The refusal on an already-configured install, the payload keys and
     * the completion action all live in `OnboardingHandlers::applyProfile()`.
     * A REST layer calling `ProfileService::apply()` directly would work
     * on the day it was written and drift the first time one of those
     * three changed — which is exactly how the two surfaces would stop
     * being resumable from each other.
     */
    public function test_the_frontend_profile_write_goes_through_the_shared_handler(): void {
        $rest = $this->source( 'src/Infrastructure/REST/OnboardingRestController.php' );

        $this->assertStringContainsString( 'OnboardingHandlers::applyProfile', $rest );
        $this->assertStringContainsString( 'OnboardingHandlers::skipProfile', $rest );
        $this->assertStringNotContainsString(
            'ProfileService::apply',
            $rest,
            'The REST layer must not apply the profile itself — that is the fork the issue forbids.'
        );

        $view = $this->source( 'src/Shared/Frontend/FrontendSetupView.php' );
        $this->assertStringNotContainsString(
            'ProfileService::apply',
            $view,
            'The view reads the diff and renders the choice; the handler makes it.'
        );
        $this->assertStringContainsString(
            'ProfileService::diff',
            $view,
            'What the choice will change has to be visible before it is applied.'
        );
    }

    /**
     * #3260 — same rule again, and the one where it matters most.
     *
     * A second importer would have to keep up with the mapping rules, the
     * workbook validation and the error reporting, and it would not. So
     * neither the REST layer nor the view may touch `ImportService`: both
     * go through `OnboardingHandlers::applyImport()`, which is also what
     * the wp-admin form posts to.
     */
    public function test_the_frontend_import_write_goes_through_the_shared_handler(): void {
        $rest = $this->source( 'src/Infrastructure/REST/OnboardingRestController.php' );

        $this->assertStringContainsString( 'OnboardingHandlers::applyImport', $rest );
        $this->assertStringContainsString( 'OnboardingHandlers::skipImport', $rest );

        // Constructing it, not naming it. Both files' docblocks explain
        // *why* there is exactly one importer, and a bare substring match
        // reads that explanation as the violation it warns against — which
        // is how this assertion failed on the commit that introduced it.
        foreach ( [
            'src/Infrastructure/REST/OnboardingRestController.php' => 'The REST layer must not import the workbook itself — that is the second importer the issue forbids.',
            'src/Shared/Frontend/FrontendSetupView.php'            => 'The view renders an upload and a report; the handler does the import.',
        ] as $file => $why ) {
            $this->assertDoesNotMatchRegularExpression(
                '/new\s+(\\\\?TT\\\\Modules\\\\Import\\\\)?ImportService\s*\(/',
                $this->source( $file ),
                $why
            );
        }
    }

    /**
     * The two-pass contract, which is what makes "nothing is saved until
     * you confirm" true rather than a claim in the copy.
     */
    public function test_the_import_handler_only_advances_on_a_commit(): void {
        $src = $this->source( 'src/Modules/Onboarding/Admin/OnboardingHandlers.php' );

        $this->assertStringContainsString(
            'public static function applyImport(',
            $src,
            'The domain half has to exist for both surfaces to share it.'
        );
        $this->assertMatchesRegularExpression(
            '/if \( \$commit \) \{\s*\n\s*OnboardingState::setStep\( \'first_team\' \);/',
            $src,
            'A preview must not move the flow on — the report is what the operator sees next.'
        );
    }
}
