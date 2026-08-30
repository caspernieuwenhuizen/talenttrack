<?php
namespace TT\Tests\Php;

use TT\Modules\Alerts\Definitions\MessagingNeverConfiguredAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Comms\Template\TemplateSwitch;
use WP_UnitTestCase;

/**
 * #3139 — the recovery for an academy that skipped the messaging step.
 *
 * The interesting assertions are the two negatives: it must not fire on an
 * install whose template registry has not booted, and it must not reach a
 * verdict about the whole install from a narrowed run.
 */
final class MessagingNeverConfiguredAlertTest extends WP_UnitTestCase {

    private MessagingNeverConfiguredAlert $alert;

    public function set_up(): void {
        parent::set_up();
        $this->alert = new MessagingNeverConfiguredAlert();
    }

    // ── Contract ───────────────────────────────────────────────────────

    public function test_it_is_quiet_and_dismissible(): void {
        $this->assertSame( Severity::INFO, $this->alert->defaultSeverity() );
        $this->assertSame( [ Surface::BADGE ], $this->alert->defaultSurfaces() );
        $this->assertFalse(
            $this->alert->isOperational(),
            'An academy that means to send nothing must be able to mute it (#2632).'
        );
    }

    public function test_it_is_gated_on_the_capability_that_opens_the_settings_screen(): void {
        $this->assertSame( 'tt_edit_feature_toggles', $this->alert->capRequired() );
    }

    /**
     * The decision was explicit: no `config` subject type for one
     * definition. Anything nothing invalidates is sweep-only, and `config`
     * is the name reserved for the day a real one is introduced.
     */
    public function test_it_does_not_introduce_a_config_subject_type(): void {
        $this->assertSame( 'messaging', $this->alert->subjectType() );
        $this->assertNotSame( 'config', $this->alert->subjectType() );
    }

    public function test_it_is_registered_in_the_catalogue(): void {
        $alerts = \TT\Modules\Alerts\AlertsModule::registerCoreAlerts( [] );
        $keys   = array_map( static fn( $a ): string => $a->key(), $alerts );
        $this->assertContains( 'comms.messaging_never_configured', $keys );
    }

    // ── The condition ──────────────────────────────────────────────────

    /**
     * The load-bearing guard. Zero switchable templates means the registry
     * has not booted, not that everything is switched off — and zero of
     * zero being "all off" is how this fires on an install where nothing at
     * all is known yet.
     */
    /**
     * The load-bearing guard, asserted from the source because there is no
     * seam for emptying the template registry from a test.
     *
     * Zero switchable templates means the registry has not booted, not that
     * everything is switched off — and zero of zero counting as "all off"
     * is how this alert would fire on an install where nothing at all is
     * known yet.
     */
    public function test_the_empty_registry_guard_is_present(): void {
        $src = (string) file_get_contents(
            dirname( __DIR__, 2 )
            . '/src/Modules/Alerts/Definitions/MessagingNeverConfiguredAlert.php'
        );
        $this->assertStringContainsString( 'if ( $switchable === [] ) return [];', $src );
    }

    // ── Behaviour ──────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function switchableOrSkip(): array {
        $switchable = array_map( 'strval', array_keys( TemplateSwitch::switchableTemplates() ) );
        if ( $switchable === [] ) {
            $this->markTestSkipped( 'No switchable templates registered in this environment.' );
        }
        return $switchable;
    }

    /**
     * Recipients are resolved from a capability, so an environment where
     * nobody holds it legitimately produces no occurrences. Skipping in
     * that case keeps the test about the condition rather than about the
     * fixture's role setup.
     */
    private function capableRecipientOrSkip(): int {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        if ( ! user_can( $admin, 'tt_edit_feature_toggles' ) ) {
            $this->markTestSkipped( 'No account in this environment holds tt_edit_feature_toggles.' );
        }
        return (int) $admin;
    }

    public function test_it_fires_when_every_switchable_template_is_off(): void {
        $switchable = $this->switchableOrSkip();
        $this->capableRecipientOrSkip();

        TemplateSwitch::setDisabled( $switchable );

        $occurrences = $this->alert->evaluate( new AlertContext() );
        $this->assertNotEmpty( $occurrences );

        foreach ( $occurrences as $occurrence ) {
            $this->assertSame( 'comms.messaging_never_configured', $occurrence->alertKey );
            $this->assertSame( 'messaging', $occurrence->subjectType );
            $this->assertSame( Severity::INFO, $occurrence->severity );
            $this->assertNull( $occurrence->playerId, 'This is about the install, not a player.' );
            $this->assertNotSame( '', $occurrence->title() );
            $this->assertNotSame( '', $occurrence->url() );
        }
    }

    public function test_it_resolves_itself_as_soon_as_one_template_is_switched_on(): void {
        $switchable = $this->switchableOrSkip();
        $this->capableRecipientOrSkip();

        TemplateSwitch::setDisabled( $switchable );
        $this->assertNotEmpty( $this->alert->evaluate( new AlertContext() ) );

        // Switch exactly one back on. The reconcile stamps resolved_at on
        // whatever an evaluation no longer returns, so returning nothing IS
        // the resolution — no explicit clear, no stored flag.
        TemplateSwitch::setDisabled( array_slice( $switchable, 1 ) );
        $this->assertSame(
            [],
            $this->alert->evaluate( new AlertContext() ),
            'One message switched on and the condition is no longer true.'
        );
    }

    /**
     * Sweep-only. A narrowed run must not reach a verdict about the whole
     * install: if it did, the reconcile would resolve an occurrence the run
     * never looked at.
     */
    public function test_a_narrowed_run_produces_nothing(): void {
        $switchable = $this->switchableOrSkip();
        $this->capableRecipientOrSkip();

        TemplateSwitch::setDisabled( $switchable );

        $this->assertSame( [], $this->alert->evaluate( new AlertContext( 0, 'player', [ 1, 2, 3 ] ) ) );
        $this->assertSame( [], $this->alert->evaluate( new AlertContext( 0, 'messaging', [ 1 ] ) ) );
    }
}
