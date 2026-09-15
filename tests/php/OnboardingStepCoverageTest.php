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
        $this->assertContains( 'staff', $ported, '#3261 ports the staff step — the last of them.' );
        $this->assertSame(
            [],
            $pending,
            'Every step in OnboardingState::STEPS has a frontend arm as of #3261. '
            . 'A step here means one was added without a renderer again.'
        );

        // Kept, with nothing currently reaching it. The list of steps and
        // the switch are two places, so the next step added to STEPS lands
        // on this default — which is how the four ports before #3261 were
        // found. Deleting it because the list is empty today is how the
        // dead end comes back.
        $this->assertStringContainsString(
            'renderNotYetPorted',
            $src,
            'A step added to STEPS without a renderer still needs the honest state.'
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
     * #3261 — same rule, the staff step, and the stakes are highest here.
     *
     * #2964/#2965 decided an invitation is **created and held**, not sent.
     * That guarantee is one argument — `defer_send` — inside
     * `OnboardingHandlers::addStaff()`. A REST layer that created the
     * person and the invitation itself would work on the day it was
     * written and, the first time somebody read the screen rather than
     * that line, mail a club's coaches the moment their names were typed.
     */
    public function test_the_frontend_staff_write_goes_through_the_shared_handler(): void {
        $rest = $this->source( 'src/Infrastructure/REST/OnboardingRestController.php' );

        $this->assertStringContainsString( 'OnboardingHandlers::addStaff', $rest );
        $this->assertStringContainsString( 'OnboardingHandlers::skipStaff', $rest );
        $this->assertStringContainsString( 'OnboardingHandlers::sendInvites', $rest );

        foreach ( [ 'InvitationService', 'PeopleRepository' ] as $forbidden ) {
            $this->assertStringNotContainsString(
                $forbidden,
                $rest,
                'The REST layer must not create people or invitations itself — that is the fork that would un-hold the invitation.'
            );
        }

        $view = $this->source( 'src/Shared/Frontend/FrontendSetupView.php' );
        $this->assertStringNotContainsString(
            'InvitationService',
            $view,
            'The view renders the list; the handler creates the invitation.'
        );
    }

    /**
     * #3261 — the held invitation is never rendered as a credential.
     *
     * An invitation token is a bearer credential: whoever reads it can
     * claim that staff seat, over a database of minors. The frontend runs
     * on phones in clubhouses and laptops on touchlines, so a token on
     * screen is one a bystander can photograph. The decision is that it is
     * only ever mailed, and this is the assertion that keeps it.
     */
    public function test_the_staff_step_renders_no_credential(): void {
        $view = $this->source( 'src/Shared/Frontend/FrontendSetupView.php' );

        foreach ( [ 'accept_token', 'invitation_token', 'acceptUrl', 'accept_url' ] as $leak ) {
            $this->assertStringNotContainsString(
                $leak,
                $view,
                'The setup view must not render anything that can be used to accept an invitation.'
            );
        }

        $rest = $this->source( 'src/Infrastructure/REST/OnboardingRestController.php' );
        foreach ( [ 'accept_token', 'invitation_token', 'accept_url' ] as $leak ) {
            $this->assertStringNotContainsString(
                $leak,
                $rest,
                'Nothing that could be used to sign in leaves the server on the onboarding routes.'
            );
        }
    }

    /**
     * #3261 — leaving the step mid-flow loses nothing.
     *
     * `skipStaff()` writes only the step. Held invitations stay under
     * Configuration → Invitations, and an academy wondering why nobody got
     * an email is the failure this is guarding against.
     */
    public function test_skipping_the_staff_step_keeps_the_held_invitations(): void {
        $handlers = $this->source( 'src/Modules/Onboarding/Admin/OnboardingHandlers.php' );

        $this->assertMatchesRegularExpression(
            '/function skipStaff\(\): void \{\s*OnboardingState::setStep\( \'messaging\' \);\s*\}/',
            $handlers,
            'skipStaff() does nothing but advance the step — no discard, no send.'
        );

        $view = $this->source( 'src/Shared/Frontend/FrontendSetupView.php' );
        $this->assertStringContainsString(
            'the invitations stay ready and waiting',
            $view,
            'The screen says so before the operator leaves, which is the point.'
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
