<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\RecordActionRow;

/**
 * #4215 — the phone budget on the action row under a record hero.
 *
 * The row scrolled sideways below 768px with its scrollbar hidden, so a
 * coach on a phone saw "Spond-koppeling" cut in half on the team page and
 * half a pencil on the player page, and nothing to say there was more.
 *
 * The rule these tests hold: on a phone at most two actions are in the
 * row and every other one is still reachable, in the menu.
 */
final class RecordActionRowBudgetTest extends WP_UnitTestCase {

    /** @var string|null */
    private $original_ua;

    public function set_up(): void {
        parent::set_up();
        $this->original_ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
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

    /** @return array<int, callable(bool): void> */
    private function actions( int $n ): array {
        $out = [];
        for ( $i = 1; $i <= $n; $i++ ) {
            $out[] = static function ( bool $in_menu ) use ( $i ): void {
                echo '<a class="tt-player-action" href="#">' . ( $in_menu ? 'Menu ' : 'Row ' ) . $i . '</a>';
            };
        }
        return $out;
    }

    /**
     * @param array<int, callable(bool): void> $actions
     * @param array<int, callable(): void>     $menu
     */
    private function html( array $actions, array $menu = [] ): string {
        ob_start();
        RecordActionRow::render( $actions, $menu );
        return (string) ob_get_clean();
    }

    public function test_a_phone_keeps_two_actions_and_folds_the_rest(): void {
        $this->asPhone();

        $html = $this->html( $this->actions( 5 ) );

        $this->assertStringContainsString( 'Row 1', $html );
        $this->assertStringContainsString( 'Row 2', $html );
        $this->assertStringNotContainsString( 'Row 3', $html );
        // Folded, not dropped.
        $this->assertStringContainsString( 'tt-player-action__menu', $html );
        $this->assertStringContainsString( 'Menu 3', $html );
        $this->assertStringContainsString( 'Menu 5', $html );
    }

    public function test_a_desktop_shows_every_action_in_the_row(): void {
        $this->asDesktop();

        $html = $this->html( $this->actions( 5 ) );

        $this->assertStringContainsString( 'Row 5', $html );
        $this->assertStringNotContainsString( 'tt-player-action__menu', $html );
        $this->assertStringNotContainsString( 'tt-player-detail__actions--phone', $html );
    }

    public function test_a_phone_at_budget_gets_no_menu(): void {
        $this->asPhone();

        $html = $this->html( $this->actions( 2 ) );

        $this->assertStringNotContainsString( 'tt-player-action__menu', $html );
    }

    public function test_an_action_that_renders_nothing_takes_no_slot(): void {
        $this->asPhone();

        // The first action is one the reader may not see. It must not
        // cost them one of the two visible slots.
        $actions = $this->actions( 3 );
        array_unshift( $actions, static function ( bool $in_menu ): void {} );

        $html = $this->html( $actions );

        $this->assertStringContainsString( 'Row 1', $html );
        $this->assertStringContainsString( 'Row 2', $html );
        $this->assertStringContainsString( 'Menu 3', $html );
    }

    public function test_menu_items_stay_in_the_menu_on_desktop(): void {
        $this->asDesktop();

        $html = $this->html(
            $this->actions( 1 ),
            [ static function (): void { echo '<button type="button">Archive</button>'; } ]
        );

        $menu = substr( $html, (int) strpos( $html, 'tt-player-action__menu' ) );

        $this->assertStringContainsString( 'Archive', $menu );
        $this->assertStringNotContainsString( 'Row 1', $menu );
    }

    public function test_folded_actions_come_before_the_standing_menu_items(): void {
        $this->asPhone();

        $html = $this->html(
            $this->actions( 3 ),
            [ static function (): void { echo '<button type="button">Archive</button>'; } ]
        );

        // Archive is the destructive one; it stays last.
        $this->assertLessThan( strpos( $html, 'Archive' ), strpos( $html, 'Menu 3' ) );
    }
}
