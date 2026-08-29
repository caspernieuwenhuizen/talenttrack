<?php
namespace TT\Modules\Documentation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Content\MarkdownProfile;
use TT\Shared\Content\MarkdownRenderer;

/**
 * Markdown — the help topics' view of the shared markdown renderer.
 *
 * #2663 folded the parsing into `Shared\Content\MarkdownRenderer`, which the
 * course reader already used. What is left here is the docs profile:
 *
 *   - the `tt-doc-*` class prefix, replacing the inline wp-admin greys this
 *     class used to emit. Topics render on two surfaces — the wp-admin Help
 *     & Docs page and the frontend help reader — and a hardcoded `#f6f7f7`
 *     never survived the second one's club colour scheme.
 *   - front-matter stripping, plus the legacy `<!-- audience: -->` comment.
 *     Without it every topic renders its metadata as visible `key: value`
 *     lines.
 *   - the five link shapes a topic can carry, including the `tt_back` hint on
 *     links that leave the docs for the app so a reader can get back to what
 *     they were reading.
 *
 * Topics gain pipe tables and fence info strings from the shared renderer.
 */
class Markdown {

    /**
     * Slug of the topic being rendered, for the `tt_back` hint on links that
     * leave for the app. Static because the class is all-static and one
     * render runs at a time; `render()` owns setting and clearing it.
     */
    private static $topic_slug = '';

    /**
     * @param string $topic_slug Topic being rendered. Links into the app
     *                           carry a back hint to it, so the reader can
     *                           return to what they were reading.
     */
    public static function render( string $source, string $topic_slug = '' ): string {
        self::$topic_slug = $topic_slug;
        try {
            return self::renderer()->render( $source )['html'];
        } finally {
            self::$topic_slug = '';
        }
    }

    private static function renderer(): MarkdownRenderer {
        return new MarkdownRenderer( new MarkdownProfile(
            'tt-doc',
            3,
            null,
            static function ( string $source ): string {
                // Metadata never reaches the parser: the front-matter block
                // would come out as a horizontal rule followed by visible
                // `key: value` lines, and the legacy audience comment would be
                // escaped into literal text.
                $source = DocFrontMatter::strip( $source );

                return (string) preg_replace( '/^\s*<!--\s*audience:.*?-->\s*$/mi', '', (string) $source );
            },
            [ __CLASS__, 'renderLink' ]
        ) );
    }

    /**
     * The five link shapes a topic can carry, in order of specificity. The
     * fall-through renders the label as plain text, which is also what an
     * unreachable destination gets — a link that lands on "permission denied"
     * is worse than no link.
     *
     * The label arrives already escaped: the renderer escapes the whole line
     * before substituting.
     */
    public static function renderLink( string $label, string $url ): string {
        // 1. Off-site. Nothing to resolve.
        if ( preg_match( '#^https?://#', $url ) ) {
            return self::anchor( $url, $label );
        }

        // 2. Cross-reference to another topic: <slug>.md, or
        //    <locale>/<slug>.md. Stays inside whichever docs viewer the
        //    reader is already in, and resolves as a real file when the doc
        //    is read on GitHub.
        //
        //    The anchor's `#` is escaped because it is also the pattern
        //    delimiter — unescaped it truncated the expression, and
        //    preg_match then failed on every link shape this branch was tried
        //    against, not just anchored ones.
        if ( preg_match( '#^(?:[a-z]{2}_[A-Z]{2}/)?([a-z0-9][a-z0-9\-]*)\.md(?:\#.*)?$#', $url, $sm ) ) {
            return self::anchor( DocLinkResolver::topic( $sm[1] ), $label );
        }

        // 3. Into the application. Carries a back hint to this topic, and
        //    renders as an action chip rather than a run-of-text link.
        if ( strpos( $url, '?tt_view=' ) === 0 ) {
            $href = DocLinkResolver::frontend( $url, self::$topic_slug );
            return $href === null ? $label : self::anchor( $href, $label, 'tt-doc-action' );
        }

        // 4. Into wp-admin. Admin-only, and always marked as leaving
        //    TalentTrack.
        if ( preg_match( '#^(\?page=|admin\.php|/wp-admin)#', $url ) ) {
            $href = DocLinkResolver::admin( $url );
            return $href === null ? $label : self::externalAnchor( $href, $label );
        }

        return $label;
    }

    private static function anchor( string $href, string $label, string $class = 'tt-doc-link' ): string {
        return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $href ) . '">' . $label . '</a>';
    }

    /**
     * A destination outside TalentTrack. Marked visually and named in the
     * accessible label, so the context switch is never a surprise.
     */
    private static function externalAnchor( string $href, string $label ): string {
        $aria = sprintf(
            /* translators: %s: link text */
            __( '%s (opens the WordPress admin)', 'talenttrack' ),
            wp_strip_all_tags( html_entity_decode( $label, ENT_QUOTES ) )
        );
        return '<a class="tt-doc-link tt-doc-extlink" href="' . esc_url( $href ) . '"'
            . ' aria-label="' . esc_attr( $aria ) . '">'
            . $label
            . '<span class="tt-doc-extlink__mark" aria-hidden="true">&#8599;</span>'
            . '</a>';
    }
}
