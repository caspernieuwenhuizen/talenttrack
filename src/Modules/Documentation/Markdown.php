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

    public static function render( string $source ): string {
        $source = str_replace( [ "\r\n", "\r" ], "\n", $source );
        // #0048 — strip audience metadata HTML comments before render.
        // The line-based renderer would otherwise pass them to inline()
        // where esc_html turns them into visible literal text.
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

        // Links [text](url) — URL must be a relative admin URL, http(s),
        // or a `<slug>.md` cross-reference to another doc topic. The
        // .md branch (#0069) rewrites `[X](other.md)` to the in-product
        // docs URL `?tt_view=docs&topic=other` so internal cross-
        // references stay inside the docs viewer instead of silently
        // rendering as plain text (the previous behaviour).
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ( $m ) {
                $url = $m[2];
                if ( preg_match( '#^(https?://|admin\.php|/wp-admin)#', $url ) ) {
                    return '<a href="' . esc_url( $url ) . '" style="color:#2271b1;">' . $m[1] . '</a>';
                }
                // Cross-reference to another doc: <slug>.md or <locale>/<slug>.md
                if ( preg_match( '#^(?:[a-z]{2}_[A-Z]{2}/)?([a-z0-9][a-z0-9\-]*)\.md(?:#.*)?$#', $url, $sm ) ) {
                    $slug = $sm[1];
                    // Stay inside the in-product docs viewer. The frontend
                    // and wp-admin viewers both honour `?tt_view=docs&topic=`;
                    // only difference is the surrounding shell.
                    if ( is_admin() ) {
                        $href = admin_url( 'admin.php?page=tt-docs&topic=' . rawurlencode( $slug ) );
                    } else {
                        $href = add_query_arg( [ 'tt_view' => 'docs', 'topic' => $slug ], home_url( '/' ) );
                    }
                    return '<a href="' . esc_url( $href ) . '" style="color:#2271b1;">' . $m[1] . '</a>';
                }
                // Treat as relative admin link
                if ( preg_match( '/^\?page=/', $url ) ) {
                    return '<a href="' . esc_url( admin_url( 'admin.php' . $url ) ) . '" style="color:#2271b1;">' . $m[1] . '</a>';
                }
                return $m[1];
            },
            $text
        );

        return $text;
    }
}
