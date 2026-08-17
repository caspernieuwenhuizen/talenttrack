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
 * The `tabs` key is supported and unused by the initial adopters, on
 * purpose. Team detail's sections are individually toggleable per user
 * (`TeamDetailSections::forUser()`); converting them to tabs would quietly
 * override a feature people already rely on. Tabs are for surfaces whose
 * sections are genuinely alternative views of one record, and that is a
 * per-surface product call rather than something to impose from a shared
 * component. Player detail keeps its own capability-gated strip, which §5c
 * grandfathers.
 */
final class RecordSpine {

    /**
     * @param array{
     *   name: string,
     *   photo_url?: string,
     *   initials?: string,
     *   status?: string,
     *   meta?: string,
     *   tabs?: list<array{label: string, url: string, active?: bool}>
     * } $config
     */
    public static function render( array $config ): void {
        if ( ! ShellPreference::isApp() ) {
            return;
        }

        $name = trim( (string) ( $config['name'] ?? '' ) );
        if ( $name === '' ) {
            // Without an identity there is nothing to pin, and an empty
            // strip would just steal vertical room.
            return;
        }

        $photo    = (string) ( $config['photo_url'] ?? '' );
        $initials = (string) ( $config['initials'] ?? self::initials( $name ) );
        $status   = (string) ( $config['status'] ?? '' );
        $meta     = (string) ( $config['meta'] ?? '' );
        $tabs     = is_array( $config['tabs'] ?? null ) ? $config['tabs'] : [];

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

        if ( $tabs !== [] ) {
            echo '<nav class="tt-spine__tabs" aria-label="' . esc_attr__( 'Record sections', 'talenttrack' ) . '">';
            foreach ( $tabs as $tab ) {
                $label = (string) ( $tab['label'] ?? '' );
                $url   = (string) ( $tab['url'] ?? '' );
                if ( $label === '' || $url === '' ) continue;
                $active = ! empty( $tab['active'] );
                echo '<a class="tt-spine__tab' . ( $active ? ' is-active' : '' ) . '" '
                    . 'href="' . esc_url( $url ) . '"'
                    . ( $active ? ' aria-current="page"' : '' ) . '>'
                    . esc_html( $label )
                    . '</a>';
            }
            echo '</nav>';
        }

        echo '</div>';
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
