<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Template\TemplateCatalog;
use TT\Modules\Comms\Template\TemplateGuide;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Onboarding\Admin\OnboardingHandlers;
use TT\Modules\Onboarding\OnboardingState;

/**
 * #3113 (epic #3049) — the setup-wizard step that turns messaging on.
 *
 * #3111 makes a new academy send nothing. That is only defensible while
 * this step exists, so the properties worth proving are the ones that
 * make skipping honest and choosing exhaustive:
 *
 *   - a completed step enables exactly what was ticked, and nothing else;
 *   - a skipped step writes nothing at all, leaving the seeded silence;
 *   - a template that never appeared in the POST stays off, rather than
 *     being switched on by omission.
 */
final class OnboardingMessagingStepTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        OnboardingState::reset();

        TemplateRegistry::clear();
        foreach ( TemplateCatalog::shipped() as $template ) {
            TemplateRegistry::register( $template );
        }

        // The state a fresh install is actually in when the step renders.
        TemplateSwitch::seedFreshInstallDefault();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $_POST = [];
    }

    public function tear_down(): void {
        $_POST = [];
        TemplateRegistry::clear();
        OnboardingState::reset();
        parent::tear_down();
    }

    /**
     * The handlers redirect and exit, so the test drives the same logic
     * the handler does without the wp_safe_redirect. Keeping this in one
     * place makes the divergence obvious if the handler grows a branch.
     *
     * @param string[] $ticked
     */
    private function submit( array $ticked ): void {
        $switchable = array_keys( TemplateSwitch::switchableTemplates() );
        $enabled    = array_values( array_intersect( $switchable, $ticked ) );
        TemplateSwitch::setDisabled( array_values( array_diff( $switchable, $enabled ) ) );
    }

    // -- the step's position -----------------------------------------------

    public function test_messaging_sits_between_staff_and_the_dashboard_page(): void {
        $steps = OnboardingState::STEPS;
        $staff = array_search( 'staff', $steps, true );
        $msg   = array_search( 'messaging', $steps, true );
        $dash  = array_search( 'dashboard', $steps, true );

        $this->assertIsInt( $msg, 'The wizard has no messaging step.' );
        $this->assertGreaterThan( $staff, $msg );
        $this->assertLessThan( $dash, $msg );
    }

    public function test_the_handler_is_registered(): void {
        OnboardingHandlers::init();

        $this->assertNotFalse( has_action( 'admin_post_tt_onboarding_messaging' ) );
        $this->assertNotFalse( has_action( 'admin_post_tt_onboarding_skip_messaging' ) );
    }

    // -- what the step writes ----------------------------------------------

    public function test_nothing_is_on_before_the_step_runs(): void {
        foreach ( array_keys( TemplateSwitch::switchableTemplates() ) as $key ) {
            $this->assertFalse( TemplateSwitch::isEnabled( (string) $key ) );
        }
    }

    public function test_completing_the_step_enables_exactly_what_was_ticked(): void {
        $this->submit( [ 'training_cancelled', 'safeguarding_broadcast' ] );

        $this->assertTrue( TemplateSwitch::isEnabled( 'training_cancelled' ) );
        $this->assertTrue( TemplateSwitch::isEnabled( 'safeguarding_broadcast' ) );
        $this->assertFalse( TemplateSwitch::isEnabled( 'goal_nudge' ) );
        $this->assertFalse( TemplateSwitch::isEnabled( 'mass_announcement' ) );
    }

    /**
     * The submitted list is intersected with the registered switchable
     * set rather than trusted. A template the browser never sent — a
     * family nobody scrolled to, a checkbox dropped in transit — must
     * stay off, because switching a message on by omission is the one
     * failure mode this whole epic exists to prevent.
     */
    public function test_a_template_absent_from_the_post_stays_off(): void {
        $this->submit( [ 'training_cancelled' ] );

        foreach ( array_keys( TemplateSwitch::switchableTemplates() ) as $key ) {
            if ( $key === 'training_cancelled' ) continue;
            $this->assertFalse(
                TemplateSwitch::isEnabled( (string) $key ),
                sprintf( '"%s" was switched on without being chosen.', $key ) );
        }
    }

    public function test_an_unknown_key_in_the_post_switches_nothing_on(): void {
        $this->submit( [ 'training_cancelled', 'not_a_template' ] );

        $this->assertNotContains( 'not_a_template', TemplateSwitch::disabledKeys() );
        $this->assertTrue( TemplateSwitch::isEnabled( 'training_cancelled' ) );
    }

    /**
     * Account mail is outside the switch (#3110), so staff invited on the
     * previous step get their invitations whatever is chosen here — and
     * the step must not be able to break that.
     */
    public function test_the_step_cannot_switch_off_account_mail(): void {
        $this->submit( [] );

        $this->assertTrue( TemplateSwitch::isEnabled( 'invitation_email' ) );
        $this->assertNotContains( 'invitation_email', TemplateSwitch::disabledKeys() );
    }

    // -- skipping ----------------------------------------------------------

    /**
     * Skipping leaves the seeded silence in place and writes nothing.
     * Re-asserting "everything off" would be a second writer, and would
     * quietly undo anything switched on since the seed.
     */
    public function test_skipping_leaves_an_operators_earlier_choice_alone(): void {
        TemplateSwitch::setDisabled(
            array_values( array_diff( array_keys( TemplateSwitch::switchableTemplates() ), [ 'training_cancelled' ] ) )
        );
        $before = QueryHelpers::get_config( TemplateSwitch::CONFIG_KEY, '' );

        OnboardingState::recordPayload( 'messaging', [ 'skipped' => true ] );

        $this->assertSame( $before, QueryHelpers::get_config( TemplateSwitch::CONFIG_KEY, '' ) );
        $this->assertTrue( TemplateSwitch::isEnabled( 'training_cancelled' ) );
    }

    // -- the copy ----------------------------------------------------------

    /**
     * The step reuses the settings screen's copy rather than carrying its
     * own — #3112's spec: one set of words, two surfaces. If a family
     * lost its recommendation marking, the step would silently stop
     * steering an operator towards the messages they almost certainly
     * want.
     */
    public function test_one_family_is_marked_recommended(): void {
        $recommended = array_filter(
            TemplateGuide::families(),
            static fn ( array $f ): bool => ! empty( $f['recommended'] )
        );

        $this->assertCount( 1, $recommended );
        $this->assertArrayHasKey( TemplateGuide::FAMILY_URGENT, $recommended );
    }

    public function test_every_message_the_step_lists_has_copy(): void {
        foreach ( array_keys( TemplateSwitch::switchableTemplates() ) as $key ) {
            $this->assertNotNull(
                TemplateGuide::forKey( (string) $key ),
                sprintf( 'The wizard would list "%s" with no explanation.', $key )
            );
        }
    }
}
