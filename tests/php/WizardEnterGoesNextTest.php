<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Shared\Frontend\FrontendWizardView;
use TT\Shared\Wizards\WizardState;

/**
 * #4709 — Enter in a wizard goes to the next step.
 *
 * Implicit submission clicks the form's first submit button. That was
 * Cancel, so Enter in a field or on a radio threw the run away. The form now
 * opens with a visually hidden copy of Next; the visible action bar keeps
 * Cancel first, with `formnovalidate`. The Playwright spec presses the key.
 */
final class WizardEnterGoesNextTest extends WP_UnitTestCase {

    /** The user the wizard renders for. */
    private int $user = 0;

    /** @var array<string, mixed> */
    private array $get_backup = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );
        $this->get_backup = $_GET;
    }

    public function tear_down(): void {
        WizardState::clear( $this->user, 'new-player' );
        WizardState::clear( $this->user, 'new-report' );
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        $_GET = $this->get_backup;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_the_first_submit_in_the_form_is_a_hidden_next(): void {
        $form = $this->wizardForm( 'new-player' );

        $first = $this->submits( $form )[0];
        $this->assertSame( 'tt_wizard_action', $first->getAttribute( 'name' ) );
        $this->assertSame( 'next', $first->getAttribute( 'value' ) );
        $this->assertSame( '-1', $first->getAttribute( 'tabindex' ) );
        $this->assertSame( 'true', $first->getAttribute( 'aria-hidden' ) );
        $this->assertStringContainsString( 'tt-screen-reader-text', $first->getAttribute( 'class' ) );

        $opening = $this->firstElementChild( $form );
        $this->assertNotNull( $opening );
        $this->assertTrue( $first->isSameNode( $opening ), 'the default button opens the form' );
    }

    public function test_the_report_start_wizard_gets_the_same_default(): void {
        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        wp_set_current_user( $hod );
        $this->user = $hod;

        $first = $this->submits( $this->wizardForm( 'new-report' ) )[0];
        $this->assertSame( 'next', $first->getAttribute( 'value' ) );
        $this->assertSame( '-1', $first->getAttribute( 'tabindex' ) );
    }

    public function test_it_sends_what_the_visible_next_sends(): void {
        $form    = $this->wizardForm( 'new-player' );
        $visible = null;
        foreach ( $this->submits( $form ) as $button ) {
            if ( $button->hasAttribute( 'data-tt-wizard-next' ) ) $visible = $button;
        }

        $this->assertNotNull( $visible );
        $this->assertSame( $visible->getAttribute( 'name' ), $this->submits( $form )[0]->getAttribute( 'name' ) );
        $this->assertSame( $visible->getAttribute( 'value' ), $this->submits( $form )[0]->getAttribute( 'value' ) );
    }

    public function test_cancel_stays_first_of_the_visible_buttons_and_skips_validation(): void {
        $form = $this->wizardForm( 'new-player' );

        $bar = null;
        foreach ( $form->getElementsByTagName( 'div' ) as $div ) {
            if ( preg_match( '/\btt-wizard-actions\b/', (string) $div->getAttribute( 'class' ) ) ) { $bar = $div; break; }
        }
        $this->assertNotNull( $bar, 'no action bar' );

        $first_visible = $this->submits( $bar )[0];
        $this->assertSame( 'cancel', $first_visible->getAttribute( 'value' ) );
        $this->assertTrue( $first_visible->hasAttribute( 'formnovalidate' ) );
    }

    // ---- helpers -------------------------------------------------------

    private function wizardForm( string $slug ): \DOMElement {
        $_GET = [ 'tt_view' => 'wizard', 'slug' => $slug ];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        FrontendWizardView::render( $this->user, true );
        $html = (string) ob_get_clean();

        $doc = new \DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
        libxml_clear_errors();

        foreach ( $doc->getElementsByTagName( 'form' ) as $form ) {
            if ( preg_match( '/\btt-wizard-form\b/', (string) $form->getAttribute( 'class' ) ) ) return $form;
        }
        $this->fail( "the {$slug} wizard rendered no form" );
    }

    /** @return list<\DOMElement> */
    private function submits( \DOMElement $root ): array {
        $out = [];
        foreach ( $root->getElementsByTagName( 'button' ) as $button ) {
            if ( strtolower( (string) $button->getAttribute( 'type' ) ) === 'submit' ) $out[] = $button;
        }
        $this->assertNotEmpty( $out, 'no submit buttons' );
        return $out;
    }

    private function firstElementChild( \DOMElement $el ): ?\DOMElement {
        foreach ( $el->childNodes as $child ) {
            if ( $child instanceof \DOMElement ) return $child;
        }
        return null;
    }
}
