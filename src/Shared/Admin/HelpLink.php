<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HelpLink — renders the "? Help on this topic" link for admin pages.
 *
 * v2.22.0. Placed next to an H1 to give admins quick access to the
 * relevant wiki topic without leaving the workflow.
 *
 * Usage:
 *   echo '<h1>' . esc_html__( 'Players', 'talenttrack' ) . ' '
 *      . HelpLink::html( 'teams-players' ) . '</h1>';
 *
 * Or call the render() method which echoes directly.
 */
class HelpLink {

    /**
     * Render the help link inline. Echoes.
     *
     * @param string $topic_slug  Slug of the help topic to link to.
     */
    public static function render( string $topic_slug ): void {
        echo self::html( $topic_slug );
    }

    /**
     * Return the help link as an HTML string (for concatenation
     * inside existing heading echo statements).
     */
    public static function html( string $topic_slug ): string {
        $url = admin_url( 'admin.php?page=tt-docs&topic=' . sanitize_key( $topic_slug ) );
        $label = __( '? Help on this topic', 'talenttrack' );
        // #0063 — open help in a new tab so it doesn't replace the
        // page the user is currently working on. `rel=noopener`
        // closes the standard target=_blank security gap.
        return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="margin-left:12px; font-size:12px; font-weight:normal; color:#2271b1; text-decoration:none;">' . esc_html( $label ) . '</a>';
    }
}
