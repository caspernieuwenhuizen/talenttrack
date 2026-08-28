<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * #2809 — the phone page-action budget.
 *
 * #2789 stopped the activity-detail header running 1096px off-screen, but
 * it did so by letting the actions stack, which on that page is nine
 * full-width buttons before any content. #2830 then added an overflow menu
 * — but entirely opt-in, so a view that never marks an action `overflow`
 * still renders all of them.
 *
 * The rule these tests hold: **on a phone, at most two actions are visible
 * regardless of what the call site declared.** The issue is explicit that
 * this must not depend on every caller remembering to pass a flag, so the
 * interesting cases are the ones where the caller passed nothing at all.
 */
final class PageActionBudgetTest extends WP_UnitTestCase {

    /** @var string|null */
    private $original_ua;

    public function set_up(): void {
        parent::set_up();
        $this->original_ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        if ( $this->original_ua === null ) {
            unset( $_SERVER['HTTP_USER_AGENT'] );
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $this->original_ua;
        }
        unset( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] );
        parent::tear_down();
    }

    private function asPhone(): void {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';
    }

    private function asDesktop(): void {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120 Safari/537.36';
    }

    /** @return array<int,array<string,mixed>> */
    private function actions( int $n ): array {
        $out = [];
        for ( $i = 1; $i <= $n; $i++ ) {
            $out[] = [ 'label' => "Action {$i}", 'href' => "https://example.test/{$i}" ];
        }
        return $out;
    }

    private function countButtons( string $html ): int {
        return substr_count( $html, 'tt-btn' ) - substr_count( $html, 'tt-btn-cta' );
    }

    // ── the budget ─────────────────────────────────────────────────────

    public function test_a_phone_shows_two_actions_and_folds_the_rest(): void {
        $this->asPhone();

        // Nine actions, none flagged — the activity-detail case exactly.
        $html = FrontendViewBase::pageActionsHtml( $this->actions( 9 ) );

        $this->assertStringContainsString( 'Action 1', $html );
        $this->assertStringContainsString( 'Action 2', $html );
        $this->assertStringContainsString( 'data-tt-actions-more', $html );
        // Folded, not dropped: everything is still reachable.
        $this->assertStringContainsString( 'Action 9', $html );
    }

    public function test_a_desktop_still_shows_every_action_inline(): void {
        $this->asDesktop();

        $html = FrontendViewBase::pageActionsHtml( $this->actions( 9 ) );

        $this->assertStringContainsString( 'Action 9', $html );
        $this->assertStringNotContainsString( 'data-tt-actions-more', $html );
    }

    public function test_a_phone_with_two_actions_gets_no_menu(): void {
        $this->asPhone();

        // At budget, not over it — a menu holding nothing is chrome.
        $html = FrontendViewBase::pageActionsHtml( $this->actions( 2 ) );

        $this->assertStringNotContainsString( 'data-tt-actions-more', $html );
    }

    // ── which two survive ──────────────────────────────────────────────

    public function test_a_primary_action_keeps_its_slot(): void {
        $this->asPhone();

        $actions   = $this->actions( 6 );
        $actions[] = [ 'label' => 'Publish', 'href' => 'https://example.test/p', 'primary' => true ];

        $html = FrontendViewBase::pageActionsHtml( $actions );

        // Declared last, but primary — so it survives, and the second slot
        // goes to the first declared action.
        $before_menu = substr( $html, 0, (int) strpos( $html, 'data-tt-actions-more' ) );

        $this->assertStringContainsString( 'Publish', $before_menu );
        $this->assertStringContainsString( 'Action 1', $before_menu );
        $this->assertStringNotContainsString( 'Action 3', $before_menu );
    }

    // ── the interaction with #2830's explicit flag ─────────────────────

    public function test_an_explicit_overflow_action_still_folds_on_desktop(): void {
        $this->asDesktop();

        $actions   = $this->actions( 2 );
        $actions[] = [ 'label' => 'Archive', 'href' => 'https://example.test/a', 'overflow' => true ];

        $html = FrontendViewBase::pageActionsHtml( $actions );

        // The author's intent outranks the viewport: `overflow` means
        // "secondary even on a desktop".
        $this->assertStringContainsString( 'data-tt-actions-more', $html );
    }

    // ── capability filtering happens before the count ──────────────────

    public function test_actions_the_reader_cannot_see_do_not_consume_the_budget(): void {
        $this->asPhone();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $actions = [
            [ 'label' => 'Visible one', 'href' => 'https://example.test/1' ],
            [ 'label' => 'Visible two', 'href' => 'https://example.test/2' ],
            [ 'label' => 'Gated three', 'href' => 'https://example.test/3', 'cap' => 'manage_options' ],
            [ 'label' => 'Gated four',  'href' => 'https://example.test/4', 'cap' => 'manage_options' ],
        ];

        $html = FrontendViewBase::pageActionsHtml( $actions );

        // Two visible actions and two the reader cannot have: that is at
        // budget, not over it. Counting before filtering would hand them a
        // menu containing nothing.
        $this->assertStringNotContainsString( 'data-tt-actions-more', $html );
        $this->assertStringNotContainsString( 'Gated three', $html );
        $this->assertStringContainsString( 'Visible two', $html );
    }

    public function test_an_empty_action_list_renders_nothing(): void {
        $this->asPhone();

        $this->assertSame( '', FrontendViewBase::pageActionsHtml( [] ) );
    }
}
