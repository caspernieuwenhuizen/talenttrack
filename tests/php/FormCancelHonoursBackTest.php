<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * #2869 — Cancel returns the user to where they came from.
 *
 * CLAUDE.md §6 rule 5 has said so since it was written. Four of the 65
 * `cancel_url` assignments in `src/` honoured it; the other 61 hard-coded a
 * destination. The worst were the attendance and minutes grids: they are
 * opened from an activity header, the link carries `tt_back` so the grid can
 * render its own back-pill, and Cancel threw that same hint away and dropped
 * the coach on the activities list.
 *
 * Resolving it inside the shared helper makes the rule true by construction:
 * a form added tomorrow inherits it without its author knowing the rule
 * exists.
 *
 * The open-redirect case is the one that matters most here. Cancel is a
 * link a user is invited to click, so it must go through
 * `BackLink::resolve()`, which validates, and never through the raw query
 * parameter.
 */
final class FormCancelHonoursBackTest extends WP_UnitTestCase {

    private const DEFAULT_URL = 'https://example.org/dashboard/?tt_view=players';

    public function tear_down(): void {
        unset( $_GET['tt_back'] );
        parent::tear_down();
    }

    private function render( array $extra = [] ): string {
        return FormSaveButton::render( array_merge( [
            'label'      => 'Save',
            'cancel_url' => self::DEFAULT_URL,
        ], $extra ) );
    }

    /** No hint in the URL: the caller's §6 default is used. */
    public function test_without_a_back_hint_the_default_is_used(): void {
        unset( $_GET['tt_back'] );
        $this->assertStringContainsString( 'tt_view=players', $this->render() );
    }

    /** The reported case: a captured origin wins over the hard-coded list. */
    public function test_a_valid_back_hint_wins(): void {
        $origin = home_url( '/dashboard/?tt_view=activities&id=42' );
        $_GET['tt_back'] = rawurlencode( $origin );

        $html = $this->render();

        $this->assertStringContainsString( 'tt_view=activities', $html, 'Cancel returns to the activity' );
        $this->assertStringNotContainsString( 'tt_view=players', $html, 'not to the hard-coded list' );
    }

    /**
     * An off-site target is refused and the default stands. Without this,
     * making Cancel follow `tt_back` would have turned every form in the
     * plugin into an open redirect.
     */
    public function test_an_offsite_back_hint_is_refused(): void {
        $_GET['tt_back'] = rawurlencode( 'https://evil.example.net/phish' );

        $html = $this->render();

        $this->assertStringNotContainsString( 'evil.example.net', $html );
        $this->assertStringContainsString( 'tt_view=players', $html );
    }

    public function test_a_malformed_back_hint_is_refused(): void {
        $_GET['tt_back'] = 'not a url at all';

        $this->assertStringContainsString( 'tt_view=players', $this->render() );
    }

    /** The escape hatch, for a form that must always land in one place. */
    public function test_ignore_back_pins_the_default(): void {
        $_GET['tt_back'] = rawurlencode( home_url( '/dashboard/?tt_view=activities&id=42' ) );

        $html = $this->render( [ 'ignore_back' => true ] );

        $this->assertStringContainsString( 'tt_view=players', $html );
        $this->assertStringNotContainsString( 'tt_view=activities', $html );
    }

    /** A form with no cancel_url still renders Save alone — §6 exemptions. */
    public function test_no_cancel_url_renders_save_only(): void {
        $_GET['tt_back'] = rawurlencode( home_url( '/dashboard/?tt_view=activities&id=42' ) );

        $html = FormSaveButton::render( [ 'label' => 'Save' ] );

        $this->assertStringNotContainsString( 'tt-form-cancel', $html );
    }
}
