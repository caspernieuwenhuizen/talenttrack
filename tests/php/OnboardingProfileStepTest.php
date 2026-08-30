<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\ModuleRegistry;
use TT\Modules\Onboarding\Admin\OnboardingHandlers;
use TT\Modules\Onboarding\OnboardingState;
use TT\Shared\Modules\ProfileRegistry;
use TT\Shared\Modules\ProfileService;

/**
 * #3259 — the install-profile step (#3038) on the frontend.
 *
 * The port's whole discipline is that there is no second implementation:
 * `OnboardingHandlers::applyProfile()` and `::skipProfile()` are the
 * domain, and both surfaces call them. So the properties worth proving
 * are the ones a fork would break — the refusal on a hand-configured
 * install, the payload keys the applied-summary half reads, and the fact
 * that applying does **not** advance the step on its own.
 */
final class OnboardingProfileStepTest extends WP_UnitTestCase {

    private string $slug;

    public function set_up(): void {
        parent::set_up();
        OnboardingState::reset();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $slugs = array_keys( ProfileRegistry::all() );
        $this->slug = (string) ( $slugs[0] ?? '' );
        $this->assertNotSame( '', $this->slug, 'The install ships at least one profile.' );
    }

    public function tear_down(): void {
        OnboardingState::reset();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── apply ──────────────────────────────────────────────────────────

    public function test_applying_a_profile_records_the_counts_the_summary_reads(): void {
        $counts = OnboardingHandlers::applyProfile( $this->slug );

        $this->assertIsArray( $counts );
        $this->assertSame( $this->slug, $counts['profile'] );

        $payload = OnboardingState::payloadFor( 'profile' );
        $this->assertSame( $this->slug, $payload['profile'] ?? null );
        $this->assertArrayHasKey( 'applied', $payload );
        $this->assertArrayHasKey( 'skipped', $payload );
    }

    /**
     * The apply deliberately does not move the flow on. The operator sees
     * what it did and presses Continue, so nothing about the shape of
     * their install happens off-screen.
     */
    public function test_applying_does_not_advance_the_step(): void {
        OnboardingState::setStep( 'profile' );
        OnboardingHandlers::applyProfile( $this->slug );

        $this->assertSame( 'profile', OnboardingState::get()['step'] );
    }

    public function test_an_unknown_profile_is_refused_and_writes_nothing(): void {
        $this->assertNull( OnboardingHandlers::applyProfile( 'no-such-profile' ) );
        $this->assertSame( [], OnboardingState::payloadFor( 'profile' ) );
    }

    /**
     * The refusal that makes the step safe to re-run. An install somebody
     * has already shaped by hand does not get silently reshaped; the
     * preview screen is where that conversation belongs.
     */
    public function test_a_hand_configured_install_is_refused(): void {
        // One operator decision is enough to make the install "shaped".
        ModuleRegistry::setEnabled( 'TT\\Modules\\Vct\\VctModule', false );
        $this->assertTrue(
            ProfileService::hasOperatorChanges(),
            'Precondition: switching a module by hand counts as an operator change.'
        );

        $this->assertNull( OnboardingHandlers::applyProfile( $this->slug ) );
        $this->assertSame( [], OnboardingState::payloadFor( 'profile' ) );

        ModuleRegistry::setEnabled( 'TT\\Modules\\Vct\\VctModule', true );
    }

    // ── skip ───────────────────────────────────────────────────────────

    /**
     * Skipping means full academy — reached by applying nothing at all,
     * so the operator who skips gets exactly what they get today.
     */
    public function test_skipping_writes_no_profile_and_advances(): void {
        OnboardingState::setStep( 'profile' );
        OnboardingHandlers::skipProfile();

        $payload = OnboardingState::payloadFor( 'profile' );
        $this->assertTrue( (bool) ( $payload['step_skipped'] ?? false ) );
        $this->assertArrayNotHasKey( 'profile', $payload );
        $this->assertSame( 'import', OnboardingState::get()['step'] );
    }

    /**
     * `step_skipped`, not `skipped`. The applied-summary payload uses
     * `skipped` for a count of rows the plan would not allow, and one key
     * holding a bool on one path and an int on the other is how a later
     * reader gets it wrong.
     */
    public function test_the_skip_flag_does_not_collide_with_the_skipped_count(): void {
        OnboardingHandlers::skipProfile();
        $this->assertArrayNotHasKey( 'skipped', OnboardingState::payloadFor( 'profile' ) );
    }

    // ── the diff the frontend renders ──────────────────────────────────

    /**
     * The step's one addition over wp-admin: what a choice would change is
     * readable before it is made. Every row must carry what the renderer
     * groups and labels by, or the list silently loses entries.
     */
    public function test_every_diff_row_carries_what_the_frontend_renders(): void {
        foreach ( array_keys( ProfileRegistry::all() ) as $slug ) {
            foreach ( ProfileService::diff( (string) $slug ) as $row ) {
                $this->assertArrayHasKey( 'label', $row );
                $this->assertNotSame( '', trim( (string) $row['label'] ) );
                $this->assertArrayHasKey( 'to', $row );
                $this->assertArrayHasKey( 'skipped_reason', $row );
            }
        }
    }
}
