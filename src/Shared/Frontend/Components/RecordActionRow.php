<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The action row under a record hero (player profile, team page).
 *
 * The row used to be a horizontally scrolling strip below 768px with its
 * scrollbar hidden, so on a phone the actions past the second or third
 * were cut off at the right edge with nothing to say they were there, and
 * the `⋯` menu — an absolutely positioned panel inside that scroll
 * container — was clipped by it and could not be reached at all.
 *
 * This applies the page-actions phone budget to the row: on a phone at
 * most `FrontendViewBase::PHONE_ACTION_BUDGET` actions stay visible and
 * the rest move into the `⋯` menu. Desktop renders every action inline,
 * as before.
 *
 * Each action is a callable that echoes its own control and takes one
 * argument, `$in_menu`. It is called with `false` for the row and with
 * `true` when the action has to be rendered as a menu item instead — an
 * icon-only control needs its label back there, and a primary button
 * must not carry its filled variant into a menu. An action that echoes
 * nothing (a capability or gate said no) is not counted, so an action the
 * reader cannot see never takes one of the two slots.
 *
 * Declared order is the prominence order: put the primary action first.
 */
final class RecordActionRow {

    /**
     * @param array<int, callable(bool): void> $actions Row actions, most prominent first.
     * @param array<int, callable(): void>     $menu    Items that live in the `⋯` menu on every viewport.
     */
    public static function render( array $actions, array $menu = [] ): void {
        $is_phone = class_exists( \TT\Shared\MobileDetector::class ) && \TT\Shared\MobileDetector::isPhone();
        $budget   = \TT\Shared\Frontend\FrontendViewBase::PHONE_ACTION_BUDGET;

        $row_html  = '';
        $menu_html = '';
        $shown     = 0;

        foreach ( $actions as $action ) {
            $html = self::capture( $action, false );
            if ( $html === '' ) continue;

            if ( $is_phone && $shown >= $budget ) {
                $menu_html .= self::capture( $action, true );
                continue;
            }

            $row_html .= $html;
            $shown++;
        }

        foreach ( $menu as $item ) {
            ob_start();
            $item();
            $menu_html .= trim( (string) ob_get_clean() );
        }

        $class = 'tt-player-detail__actions';
        if ( $is_phone ) $class .= ' tt-player-detail__actions--phone';

        // Rendered even when empty so the surface keeps its visual rhythm:
        // the paper under the hero continues into the action band.
        echo '<div class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Actions', 'talenttrack' ) . '">';
        echo $row_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each action escapes its own output.
        if ( $menu_html !== '' ) {
            ?>
            <div class="tt-player-action tt-player-action--more"
                 role="button"
                 tabindex="0"
                 aria-haspopup="true"
                 aria-expanded="false"
                 aria-label="<?php esc_attr_e( 'More actions', 'talenttrack' ); ?>"
                 onclick="this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');}">
                ⋯
                <div class="tt-player-action__menu" role="menu">
                    <?php echo $menu_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item escapes its own output. ?>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    /**
     * @param callable(bool): void $action
     */
    private static function capture( callable $action, bool $in_menu ): string {
        ob_start();
        $action( $in_menu );
        return trim( (string) ob_get_clean() );
    }
}
