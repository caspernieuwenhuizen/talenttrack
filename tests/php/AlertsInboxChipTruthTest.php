<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Frontend\FrontendAlertsInboxView;

/**
 * #3320 — the alerts inbox is the one surface that lets FilterBar derive its
 * own chips, and everything it said about its filters was wrong.
 *
 * Set an Area and a Severity, leave State on its default: the badge read 2,
 * a single chip read "State: Open", and Open is the default — so the one
 * chip named the one thing that was NOT filtered, its ✕ pointed at the
 * option it was already on, and the two filters that were set showed
 * nothing.
 *
 * Two faults live here (the third, selects never chipping at all, is
 * #3318 in the component). The state group declared no `default_value`, so
 * the bar had no way to know Open means "unfiltered"; and the view passed a
 * hand-rolled `active_count` alongside derived chips, which makes the badge
 * and the chips different sources that can disagree by construction.
 *
 * These assertions are about what the bar SAYS, not what the list returns —
 * the filtering itself was never broken, only the readback.
 */
final class AlertsInboxChipTruthTest extends WP_UnitTestCase {

    /** @var int */
    private $user;

    public function set_up(): void {
        parent::set_up();
        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );
        $_GET = [];
    }

    public function tear_down(): void {
        $_GET = [];
        parent::tear_down();
    }

    private function renderBar(): string {
        ob_start();
        FrontendAlertsInboxView::render( $this->user );
        return (string) ob_get_clean();
    }

    /** The chip labels the bar rendered, in order. */
    private function chips( string $html ): array {
        preg_match_all( '/<span class="tt-chip__label">([^<]+)</', $html, $m );
        return array_map( 'html_entity_decode', $m[1] );
    }

    private function badge( string $html ): ?string {
        return preg_match( '/tt-filterbtn__badge">(\d+)</', $html, $m ) ? $m[1] : null;
    }

    /**
     * The heart of it: an unfiltered list says it is unfiltered. The default
     * state is not a filter and must not chip.
     */
    public function test_the_default_state_does_not_chip(): void {
        $html = $this->renderBar();
        if ( strpos( $html, 'tt-filterbar' ) === false ) {
            $this->markTestSkipped( 'Alerts module or table not available on this install.' );
        }

        $this->assertSame( [], $this->chips( $html ), 'An unfiltered inbox should show no chips.' );
        $this->assertNull( $this->badge( $html ), 'An unfiltered inbox should show no badge.' );
        $this->assertStringNotContainsString( 'State: Open', $html );
    }

    /** A state the user actually chose IS a filter, and does chip. */
    public function test_a_non_default_state_chips(): void {
        $_GET['state'] = 'resolved';

        $html = $this->renderBar();
        if ( strpos( $html, 'tt-filterbar' ) === false ) {
            $this->markTestSkipped( 'Alerts module or table not available on this install.' );
        }

        $this->assertNotEmpty(
            preg_grep( '/^State: /', $this->chips( $html ) ),
            'A state off its default should chip.'
        );
        $this->assertSame( '1', $this->badge( $html ) );
    }

    /**
     * The badge and the chips are one number. The view no longer passes an
     * `active_count`, so the bar counts what it rendered — which is the only
     * way the two cannot drift.
     */
    public function test_the_badge_equals_the_chip_count(): void {
        $severities = Severity::all();
        $_GET['severity'] = (string) reset( $severities );
        $_GET['state']    = 'resolved';

        $html = $this->renderBar();
        if ( strpos( $html, 'tt-filterbar' ) === false ) {
            $this->markTestSkipped( 'Alerts module or table not available on this install.' );
        }

        $chips = $this->chips( $html );
        $this->assertNotEmpty( $chips );
        $this->assertSame(
            (string) count( $chips ),
            $this->badge( $html ),
            'The badge must be the number of chips, not a separately counted total.'
        );
    }

    /**
     * A subject-scoped deep link narrows the whole list, but those params are
     * routing state carried in the bar's hidden fields — not filter groups.
     * They must not chip, or every alert opened from a record would look
     * like it had a filter the user could take off.
     */
    public function test_a_subject_deep_link_does_not_chip(): void {
        $_GET['subject_type'] = 'player';
        $_GET['subject_id']   = '7';

        $html = $this->renderBar();
        if ( strpos( $html, 'tt-filterbar' ) === false ) {
            $this->markTestSkipped( 'Alerts module or table not available on this install.' );
        }

        $this->assertSame( [], $this->chips( $html ) );
    }
}
