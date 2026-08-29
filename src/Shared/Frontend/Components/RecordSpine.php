<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\ShellPreference;

/**
 * RecordSpine (#2479) — the pinned identity strip for a record view.
 *
 * A record view opens with a full hero that anchors the screen (CLAUDE.md
 * §1: name and photo anchor any screen where the record is the subject).
 * That hero scrolls away. The spine is the slim strip that survives, so a
 * coach reading down a long team roster or activity page never loses track
 * of which record they are in.
 *
 * Shipped for players first as bespoke markup in #2457; this is that
 * pattern extracted so team, activity and staff detail get it too, and so
 * a fourth surface does not hand-roll a fourth variant.
 *
 * **This component composes; it does not decide** (CLAUDE.md §4). It takes
 * a resolved config array and emits markup. Working out which chips a
 * viewer may see, deriving status, or filtering by permission stays in the
 * calling view and the domain layer. If this class ever needs a repository,
 * the design has gone wrong.
 *
 * Renders only under the `app` shell. Under `classic` it emits nothing, so
 * those views render exactly as before — #2456's rollback contract.
 *
 * ## Tabs
 *
 * Tabs are for surfaces whose sections are genuinely alternative views of
 * one record, and which one qualifies is a per-surface product call rather
 * than something to impose from a shared component. Team detail's sections
 * are individually toggleable per user (`TeamDetailSections::forUser()`);
 * converting them to tabs would quietly override a feature people already
 * rely on. Player detail keeps its own capability-gated strip, which §5c
 * grandfathers.
 *
 * There are two kinds, and a tab entry picks one by which key it carries:
 *
 *   - `url`   — a **navigating** tab. Renders `<a href>`; the destination
 *               is a page load. This is the original behaviour.
 *   - `panel` — an **in-page** tab. Renders `<button role="tab">` bound to
 *               a panel already on the page, switched without a request.
 *
 * A strip is one kind or the other; the first entry carrying `panel`
 * makes the whole strip in-page, and `url` on the remaining entries is
 * then ignored. Mixing is not supported because the two kinds have
 * different keyboard contracts — arrow keys move between in-page tabs and
 * activate them, while a row of links is walked with Tab.
 *
 * **In-page tabs render under `classic` too.** The identity strip stays
 * app-only, per #2456's rollback contract — it is shell chrome. A section
 * switcher is not: it is the only route to half a view's content, and a
 * surface whose sections are unreachable under `classic` is broken rather
 * than degraded. `FrontendTrainingPlansView` shows the cost of the old
 * behaviour — it carries a duplicate Edit / Done header action purely
 * because its navigating tabs vanish under `classic`.
 *
 * `tabs_always` extends that to a **navigating** strip whose tabs are
 * likewise the only route to the view's sections (#2822). It also lets a
 * surface with no record identity — a settings page whose sections are
 * genuinely facets of one subject — use the strip without inventing a name
 * to satisfy the identity guard. The identity strip itself still obeys the
 * app-only rule either way.
 *
 * **Panels belong to the caller.** This component does not create them.
 * The caller renders each panel itself and passes its element id:
 *
 *     RecordSpine::render( [
 *         'name' => $player->name,
 *         'tabs' => [
 *             [ 'label' => 'Squad', 'panel' => 'tt-panel-squad', 'active' => true ],
 *             [ 'label' => 'Pitch', 'panel' => 'tt-panel-pitch' ],
 *         ],
 *     ] );
 *
 *     <div id="tt-panel-squad" role="tabpanel" aria-labelledby="tt-tab-tt-panel-squad">…</div>
 *     <div id="tt-panel-pitch" role="tabpanel" aria-labelledby="tt-tab-tt-panel-pitch" hidden>…</div>
 *
 * Keeping panel ownership with the caller is what stops this component
 * needing to know anything about a record.
 */
final class RecordSpine {

    /**
     * Every key is optional in the type even though `name` is required in
     * practice, because the component's contract is to degrade rather than
     * fatal: a caller that hands it an empty array gets nothing rendered,
     * and that path is tested. Declaring `name` as required would make the
     * guard below unreachable to static analysis while leaving it very much
     * reachable at runtime.
     *
     * @param array{
     *   name?: string,
     *   photo_url?: string,
     *   initials?: string,
     *   status?: string,
     *   meta?: string,
     *   tabs_always?: bool,
     *   tabs?: list<array{label?: string, url?: string, panel?: string, active?: bool}>
     * } $config
     */
    public static function render( array $config ): void {
        $tabs    = is_array( $config['tabs'] ?? null ) ? $config['tabs'] : [];
        $in_page = self::isInPage( $tabs );
        // #2822 — a navigating strip the caller has declared to be the only
        // route to its sections survives `classic` and an absent identity,
        // the same way an in-page strip always has.
        $survives = $in_page || ! empty( $config['tabs_always'] );

        // Under `classic` the identity strip does not render — it is app
        // shell chrome. In-page tabs still do: they are the only route to
        // the panels behind them.
        if ( ! ShellPreference::isApp() ) {
            if ( $survives ) {
                echo '<div class="tt-spine tt-spine--tabs-only">';
                self::renderTabs( $tabs, $in_page );
                echo '</div>';
            }
            return;
        }

        $name = trim( (string) ( $config['name'] ?? '' ) );
        if ( $name === '' ) {
            // Without an identity there is nothing to pin. In-page tabs
            // are still the way into the panels, so they survive an
            // identity-less config the same way they survive `classic`.
            if ( $survives ) {
                echo '<div class="tt-spine tt-spine--tabs-only">';
                self::renderTabs( $tabs, $in_page );
                echo '</div>';
            }
            return;
        }

        $photo    = (string) ( $config['photo_url'] ?? '' );
        $initials = (string) ( $config['initials'] ?? self::initials( $name ) );
        $status   = (string) ( $config['status'] ?? '' );
        $meta     = (string) ( $config['meta'] ?? '' );

        echo '<div class="tt-spine">';

        // aria-hidden: the accessible name for this record is the view's
        // own <h1> in the hero above. Repeating it here would announce the
        // record twice on one page; this copy orients the eye while
        // scrolled, nothing more.
        echo '<div class="tt-spine__id" aria-hidden="true">';
        echo '<span class="tt-spine__avatar"' . ( $status !== '' ? ' data-status="' . esc_attr( $status ) . '"' : '' ) . '>';
        if ( $photo !== '' ) {
            echo '<img class="tt-spine__photo" src="' . esc_url( $photo ) . '" alt="" />';
        } else {
            echo esc_html( $initials );
        }
        echo '</span>';
        echo '<span class="tt-spine__name">' . esc_html( $name ) . '</span>';
        if ( $meta !== '' ) {
            echo '<span class="tt-spine__meta">' . esc_html( $meta ) . '</span>';
        }
        echo '</div>';

        self::renderTabs( $tabs, $in_page );

        echo '</div>';
    }

    /**
     * True when this strip switches panels in place rather than navigating.
     *
     * The first entry carrying a non-empty `panel` decides it for the whole
     * strip — see the class docblock for why the two kinds do not mix.
     *
     * @param list<array<string,mixed>> $tabs
     */
    private static function isInPage( array $tabs ): bool {
        foreach ( $tabs as $tab ) {
            if ( trim( (string) ( $tab['panel'] ?? '' ) ) !== '' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The tab strip, in whichever of the two kinds the config asked for.
     *
     * @param list<array<string,mixed>> $tabs
     */
    private static function renderTabs( array $tabs, bool $in_page ): void {
        if ( $tabs === [] ) {
            return;
        }

        // A tablist is not navigation — it reveals content already on the
        // page — so the in-page kind is a <div role="tablist">, not a <nav>.
        echo $in_page
            ? '<div class="tt-spine__tabs" role="tablist" aria-label="' . esc_attr__( 'Record sections', 'talenttrack' ) . '" data-tt-spine-tabs>'
            : '<nav class="tt-spine__tabs" aria-label="' . esc_attr__( 'Record sections', 'talenttrack' ) . '">';

        // Roving tabindex: exactly one tab is reachable with Tab, and the
        // arrow keys move between them from there. Without a resolved
        // active tab the first one takes the stop, so the strip is never
        // keyboard-unreachable because a caller forgot `active`.
        $active_seen = false;
        foreach ( $tabs as $tab ) {
            if ( ! empty( $tab['active'] ) ) { $active_seen = true; break; }
        }
        $first = true;

        foreach ( $tabs as $tab ) {
            $label = trim( (string) ( $tab['label'] ?? '' ) );
            if ( $label === '' ) continue;

            $active = ! empty( $tab['active'] ) || ( ! $active_seen && $first );

            if ( $in_page ) {
                $panel = trim( (string) ( $tab['panel'] ?? '' ) );
                if ( $panel === '' ) continue;
                echo '<button type="button" class="tt-spine__tab' . ( $active ? ' is-active' : '' ) . '"'
                    . ' role="tab"'
                    . ' id="' . esc_attr( self::tabId( $panel ) ) . '"'
                    . ' aria-controls="' . esc_attr( $panel ) . '"'
                    . ' aria-selected="' . ( $active ? 'true' : 'false' ) . '"'
                    . ' tabindex="' . ( $active ? '0' : '-1' ) . '">'
                    . esc_html( $label )
                    . '</button>';
            } else {
                $url = trim( (string) ( $tab['url'] ?? '' ) );
                if ( $url === '' ) continue;
                echo '<a class="tt-spine__tab' . ( ! empty( $tab['active'] ) ? ' is-active' : '' ) . '" '
                    . 'href="' . esc_url( $url ) . '"'
                    . ( ! empty( $tab['active'] ) ? ' aria-current="page"' : '' ) . '>'
                    . esc_html( $label )
                    . '</a>';
            }

            $first = false;
        }

        echo $in_page ? '</div>' : '</nav>';
    }

    /**
     * The tab button's own id, derived from the panel it controls so the
     * caller only ever has to name one of the pair.
     */
    public static function tabId( string $panel_id ): string {
        return 'tt-tab-' . $panel_id;
    }

    /** Up to two initials, for records with no photo. */
    public static function initials( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) ) ?: [];
        $out   = '';
        foreach ( $parts as $part ) {
            if ( $part === '' ) continue;
            $out .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
            if ( mb_strlen( $out ) >= 2 ) break;
        }
        return $out !== '' ? $out : '?';
    }
}
