<?php
namespace TT\Modules\Documentation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Markdown — minimal markdown-to-HTML renderer for help topics.
 *
 * Intentionally tiny — we control the topic file contents so we
 * don't need a full CommonMark implementation. Covers what the
 * wiki topics actually use:
 *
 *   # H1, ## H2, ### H3
 *   **bold**, *italic*, `inline code`
 *   - bullet lists
 *   1. numbered lists
 *   [link text](url)
 *   paragraphs separated by blank lines
 *   > blockquote
 *   ```\ncode block\n```
 *
 * Output is trusted since input is plugin-shipped; still escapes
 * HTML special characters inside code blocks and untrusted text
 * to prevent accidental HTML injection through translated strings.
 */
class Markdown {

    /**
     * Slug of the topic being rendered, for the `tt_back` hint on links
     * that leave for the app. Static because the class is all-static and
     * one render runs at a time; `render()` owns setting and clearing it.
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
            return self::renderBody( $source );
        } finally {
            self::$topic_slug = '';
        }
    }

    private static function renderBody( string $source ): string {
        $source = str_replace( [ "\r\n", "\r" ], "\n", $source );
        // Metadata never reaches the renderer: the front-matter block would
        // come out as a horizontal rule followed by visible `key: value`
        // lines, and the legacy audience comment would be escaped into
        // literal text by inline().
        $source = DocFrontMatter::strip( $source );
        $source = preg_replace( '/^\s*<!--\s*audience:.*?-->\s*$/mi', '', (string) $source );
        $lines = explode( "\n", (string) $source );

        $out = [];
        $in_ul = false;
        $in_ol = false;
        $in_code = false;
        $in_para = false;
        $para = [];

        $flush_para = function () use ( &$out, &$para, &$in_para ) {
            if ( $in_para && ! empty( $para ) ) {
                $out[] = '<p>' . self::inline( implode( ' ', $para ) ) . '</p>';
            }
            $para = [];
            $in_para = false;
        };

        $close_lists = function () use ( &$out, &$in_ul, &$in_ol ) {
            if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
            if ( $in_ol ) { $out[] = '</ol>'; $in_ol = false; }
        };

        foreach ( $lines as $line ) {
            // Fenced code block
            if ( preg_match( '/^```/', $line ) ) {
                $flush_para();
                $close_lists();
                if ( $in_code ) {
                    $out[] = '</code></pre>';
                    $in_code = false;
                } else {
                    $out[] = '<pre style="background:#f6f7f7; padding:10px 14px; border-left:3px solid #dcdcde; overflow-x:auto;"><code>';
                    $in_code = true;
                }
                continue;
            }
            if ( $in_code ) {
                $out[] = esc_html( $line );
                continue;
            }

            // Blank line
            if ( trim( $line ) === '' ) {
                $flush_para();
                $close_lists();
                continue;
            }

            // Headings
            if ( preg_match( '/^(#{1,3})\s+(.+)$/', $line, $m ) ) {
                $flush_para();
                $close_lists();
                $level = strlen( $m[1] );
                $text = self::inline( $m[2] );
                $out[] = '<h' . $level . ' style="margin:18px 0 8px; color:#1a1d21;">' . $text . '</h' . $level . '>';
                continue;
            }

            // Blockquote
            if ( preg_match( '/^>\s*(.*)$/', $line, $m ) ) {
                $flush_para();
                $close_lists();
                $out[] = '<blockquote style="margin:10px 0; padding:8px 14px; border-left:3px solid #2271b1; background:#f6fafe; color:#555;">' . self::inline( $m[1] ) . '</blockquote>';
                continue;
            }

            // Unordered list
            if ( preg_match( '/^[\-\*]\s+(.+)$/', $line, $m ) ) {
                $flush_para();
                if ( $in_ol ) { $out[] = '</ol>'; $in_ol = false; }
                if ( ! $in_ul ) { $out[] = '<ul style="margin:8px 0 8px 24px;">'; $in_ul = true; }
                $out[] = '<li style="margin-bottom:4px;">' . self::inline( $m[1] ) . '</li>';
                continue;
            }

            // Ordered list
            if ( preg_match( '/^\d+\.\s+(.+)$/', $line, $m ) ) {
                $flush_para();
                if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
                if ( ! $in_ol ) { $out[] = '<ol style="margin:8px 0 8px 24px;">'; $in_ol = true; }
                $out[] = '<li style="margin-bottom:4px;">' . self::inline( $m[1] ) . '</li>';
                continue;
            }

            // Paragraph (accumulate)
            $close_lists();
            $in_para = true;
            $para[] = trim( $line );
        }

        $flush_para();
        $close_lists();
        if ( $in_code ) $out[] = '</code></pre>';

        return implode( "\n", $out );
    }

    /**
     * Inline transformations: bold, italic, inline code, links.
     * Applied AFTER HTML escaping of the input.
     */
    private static function inline( string $text ): string {
        $text = esc_html( $text );

        // Inline code `foo`
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            function ( $m ) {
                return '<code style="background:#f0f0f1; padding:1px 5px; border-radius:3px; font-size:0.92em;">' . $m[1] . '</code>';
            },
            $text
        );

        // Bold **foo**
        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

        // Italic *foo* (after bold so ** doesn't match)
        $text = preg_replace( '/(?<!\*)\*(?!\*)([^*]+)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text );

        // Links [text](url). Five shapes, in order of specificity; the
        // fall-through renders the label as plain text, which is also
        // what an unreachable destination gets — a link that lands on
        // "permission denied" is worse than no link.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            static function ( $m ) {
                $label = $m[1];
                $url   = $m[2];

                // 1. Off-site. Nothing to resolve.
                if ( preg_match( '#^https?://#', $url ) ) {
                    return self::anchor( $url, $label );
                }

                // 2. Cross-reference to another topic: <slug>.md, or
                //    <locale>/<slug>.md. Stays inside whichever docs
                //    viewer the reader is already in, and resolves as a
                //    real file when the doc is read on GitHub.
                //
                //    The anchor's `#` is escaped because it is also the
                //    pattern delimiter — unescaped it truncated the
                //    expression, and preg_match then failed on every link
                //    shape this branch was tried against, not just
                //    anchored ones.
                if ( preg_match( '#^(?:[a-z]{2}_[A-Z]{2}/)?([a-z0-9][a-z0-9\-]*)\.md(?:\#.*)?$#', $url, $sm ) ) {
                    return self::anchor( DocLinkResolver::topic( $sm[1] ), $label );
                }

                // 3. Into the application. Carries a back hint to this
                //    topic, and renders as an action chip rather than a
                //    run-of-text link.
                if ( strpos( $url, '?tt_view=' ) === 0 ) {
                    $href = DocLinkResolver::frontend( $url, self::$topic_slug );
                    return $href === null ? $label : self::anchor( $href, $label, 'tt-doc-action' );
                }

                // 4. Into wp-admin. Admin-only, and always marked as
                //    leaving TalentTrack.
                if ( preg_match( '#^(\?page=|admin\.php|/wp-admin)#', $url ) ) {
                    $href = DocLinkResolver::admin( $url );
                    return $href === null ? $label : self::externalAnchor( $href, $label );
                }

                return $label;
            },
            $text
        );

        return $text;
    }

    /**
     * The label is already escaped by the time a link callback sees it —
     * inline() escapes the whole string before any substitution.
     */
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
