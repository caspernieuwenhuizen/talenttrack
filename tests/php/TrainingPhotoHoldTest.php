<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Training\Frontend\FrontendTrainingPhotoView;

/**
 * #2735 — a photograph held on a coach's phone until there is signal.
 *
 * The holding itself lives in the browser, so what can be tested here is
 * the contract PHP owns: the retention window, and the strings the store
 * renders on its own. Both matter more than their size suggests — the
 * window is a DPIA commitment rather than a tuning knob, and a missing
 * string is a badge that silently says nothing about a photograph the
 * coach believes is safe.
 */
final class TrainingPhotoHoldTest extends WP_UnitTestCase {

    /** The inline config `tt-photo-hold.js` reads, decoded. */
    private function config(): array {
        FrontendTrainingPhotoView::enqueuePhotoHold();

        // `before` data is a LIST of inline chunks, not a string — WordPress
        // lets several callers stack scripts ahead of one handle, and this
        // helper enqueues once per test, so by the third call there are
        // three. Read them one at a time: joining them first and matching
        // across the join spans two objects and decodes to nothing.
        $chunks = wp_scripts()->get_data( 'tt-photo-hold', 'before' );
        $chunks = is_array( $chunks ) ? $chunks : [ $chunks ];

        foreach ( $chunks as $chunk ) {
            if ( ! is_string( $chunk ) ) continue;
            if ( ! preg_match( '/TT_PHOTO_HOLD\s*=\s*(\{.*\})\s*;/s', $chunk, $m ) ) continue;

            $decoded = json_decode( $m[1], true );
            if ( is_array( $decoded ) ) return $decoded;
        }

        $this->fail( 'the hold script was enqueued without a config in the shape the script reads' );
    }

    /**
     * Seven days, decided 2026-08-23. The number is a commitment recorded
     * in the DPIA, not a constant somebody may retune because a coach
     * asked for longer — so the document is asserted alongside the code.
     * They drifting apart is the failure worth catching: the code would
     * still work and the operator's signed record would be false.
     */
    public function test_the_hold_window_is_seven_days_and_the_dpia_says_so(): void {
        $this->assertSame( 7, FrontendTrainingPhotoView::HOLD_DAYS );

        $dpia = (string) file_get_contents( TT_PLUGIN_DIR . 'docs/photo-capture-dpia.md' );
        $this->assertStringContainsString(
            '**7 days**',
            $dpia,
            'the retention window is no longer stated in the DPIA'
        );
    }

    /** The window reaches the store, or the sweep falls back to its own guess. */
    public function test_the_window_reaches_the_script(): void {
        $config = $this->config();

        $this->assertSame( FrontendTrainingPhotoView::HOLD_DAYS, $config['holdDays'] ?? null );
    }

    /**
     * Every `i18n.` key `tt-photo-hold.js` reads must be sent. The store
     * paints the pending badge on pages that never load the capture
     * script — the plans list is the whole point of it — so it cannot
     * borrow that script's strings.
     */
    public function test_every_string_the_hold_script_reads_is_sent(): void {
        $config = $this->config();
        $sent   = array_keys( (array) ( $config['i18n'] ?? [] ) );

        $js = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/tt-photo-hold.js' );
        preg_match_all( '/i18n\.([a-zA-Z0-9_]+)/', $js, $matches );
        $read = array_values( array_unique( $matches[1] ) );

        $this->assertNotSame( [], $read, 'precondition: the script reads at least one string' );
        $this->assertSame( [], array_values( array_diff( $read, $sent ) ), 'read by the script, never sent' );

        foreach ( (array) ( $config['i18n'] ?? [] ) as $key => $value ) {
            $this->assertNotSame( '', trim( (string) $value ), "the '{$key}' string is empty" );
        }
    }

    /**
     * A count of one and a count of many are separate strings. Dutch
     * inflects the verb as well as the noun, so a single `%d photo(s)`
     * msgid cannot be translated correctly whatever the translator does.
     */
    public function test_the_pending_badge_has_both_plural_forms(): void {
        $i18n = (array) ( $this->config()['i18n'] ?? [] );

        $this->assertArrayHasKey( 'pendingOne', $i18n );
        $this->assertArrayHasKey( 'pendingMany', $i18n );
        $this->assertStringNotContainsString( '%d', $i18n['pendingOne'], 'the singular form should not carry a count' );
        $this->assertStringContainsString( '%d', $i18n['pendingMany'] );
    }

    /**
     * The capture script must not hold its own copy of the store. Two
     * IndexedDB databases with the same purpose is how a photograph ends
     * up swept from one and resurrected from the other.
     */
    public function test_the_capture_script_uses_the_shared_store(): void {
        $capture = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/frontend-training-photo.js' );

        $this->assertStringContainsString( 'window.TT.photoHold', $capture );
        $this->assertStringNotContainsString( 'indexedDB', $capture, 'the capture script opened a database of its own' );
    }
}
