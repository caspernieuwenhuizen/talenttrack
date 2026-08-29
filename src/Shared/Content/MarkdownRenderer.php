<?php
namespace TT\Shared\Content;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MarkdownRenderer — the plugin's one markdown-to-HTML renderer (#2663).
 *
 * Grown from `Knowledge\LessonMarkdown` and now serving the help topics too,
 * which used to have a renderer of their own. What differs between the two
 * surfaces is a `MarkdownProfile`; what they share is everything else.
 *
 * Deliberately small. We control every input file, so this covers what the
 * corpora use and nothing else — headings, emphasis, code, links, lists,
 * blockquotes, pipe tables, fenced sections. Text is escaped even though the
 * sources are plugin-shipped, because translated content is edited by people
 * who are not reviewing PHP.
 *
 * Output is classed, never inline-styled: a hardcoded grey does not survive a
 * club colour scheme, and the inline-style gate (#1389) scans added lines.
 */
final class MarkdownRenderer {

    /** Marker distinguishing queued prose lines from finished HTML. */
    private const PROSE_MARKER = "\0prose\0";

    private MarkdownProfile $profile;

    public function __construct( MarkdownProfile $profile ) {
        $this->profile = $profile;
    }

    /**
     * Render a full document: prose, tables, and fenced sections.
     *
     * @return array{html: string, interactive: bool} `interactive` is true
     *         when at least one rendered fence needs its surface's script.
     */
    public function render( string $source ): array {
        $source = str_replace( [ "\r\n", "\r" ], "\n", $source );
        if ( $this->profile->preprocess !== null ) {
            $source = (string) ( $this->profile->preprocess )( $source );
        }

        $lines       = explode( "\n", $source );
        $out         = [];
        $interactive = false;

        $count = count( $lines );
        for ( $i = 0; $i < $count; $i++ ) {
            $line = $lines[ $i ];

            // Fenced section. Everything up to the closing fence belongs to
            // it, including blank lines and nested markdown.
            if ( preg_match( '/^```(.*)$/', $line, $match ) ) {
                $info = trim( $match[1] );
                $body = [];

                for ( $i++; $i < $count; $i++ ) {
                    if ( preg_match( '/^```\s*$/', $lines[ $i ] ) ) {
                        break;
                    }
                    $body[] = $lines[ $i ];
                }

                $rendered = $this->renderFence( $info, implode( "\n", $body ) );
                $out[]    = $rendered['html'];

                $interactive = $interactive || $rendered['interactive'];
                continue;
            }

            // Pipe table: a header row followed by a delimiter row.
            if ( self::isTableRow( $line ) && isset( $lines[ $i + 1 ] ) && self::isTableDelimiter( $lines[ $i + 1 ] ) ) {
                $table = [ $line ];
                $i    += 2;

                for ( ; $i < $count && self::isTableRow( $lines[ $i ] ); $i++ ) {
                    $table[] = $lines[ $i ];
                }
                $i--;

                $out[] = $this->renderTable( $table );
                continue;
            }

            // Prose is queued rather than rendered here, so a paragraph split
            // across a table or a block still renders as one.
            $out[] = self::PROSE_MARKER . $line;
        }

        return [
            'html'        => $this->assemble( $out ),
            'interactive' => $interactive,
        ];
    }

    /**
     * Render a fragment that is prose only — a callout's body, an
     * assignment's text. Fences are not nested, so anything fenced inside one
     * renders as a code block.
     */
    public function renderProse( string $source ): string {
        $source = str_replace( [ "\r\n", "\r" ], "\n", trim( $source ) );

        return $this->renderLines( explode( "\n", $source ) );
    }

    /**
     * Run the queued output, rendering consecutive prose lines together so
     * paragraphs and lists survive being interleaved with tables and blocks.
     *
     * @param list<string> $parts
     */
    private function assemble( array $parts ): string {
        $html  = '';
        $prose = [];

        $flush = function () use ( &$html, &$prose ): void {
            if ( $prose !== [] ) {
                $html .= $this->renderLines( $prose );
                $prose = [];
            }
        };

        foreach ( $parts as $part ) {
            if ( strpos( $part, self::PROSE_MARKER ) === 0 ) {
                $prose[] = substr( $part, strlen( self::PROSE_MARKER ) );
                continue;
            }
            $flush();
            $html .= $part;
        }

        $flush();

        return $html;
    }

    /**
     * A fenced section: whatever the profile makes of it, or a code sample.
     *
     * An unclaimed info string falls through to a code block rather than
     * failing. Content written against a newer release, opened on an older
     * one, loses one widget and keeps the page.
     *
     * @return array{html: string, interactive: bool}
     */
    private function renderFence( string $info, string $body ): array {
        if ( $this->profile->fence_renderer !== null ) {
            $rendered = ( $this->profile->fence_renderer )( $info, $body );
            if ( is_array( $rendered ) ) {
                return $rendered;
            }
        }

        $language = $info !== '' ? ' data-language="' . esc_attr( self::fenceLanguage( $info ) ) . '"' : '';

        return [
            'html'        => '<pre class="' . esc_attr( $this->profile->cls( 'code' ) ) . '"' . $language . '><code>'
                             . esc_html( $body ) . '</code></pre>',
            'interactive' => false,
        ];
    }

    /** The bare language token of an info string, ignoring any attributes. */
    private static function fenceLanguage( string $info ): string {
        $first = strtok( $info, " \t" );
        return $first === false ? '' : $first;
    }

    /**
     * Headings, lists, blockquotes and paragraphs.
     *
     * @param list<string> $lines
     */
    private function renderLines( array $lines ): string {
        $out   = [];
        $para  = [];
        $item  = [];
        $in_ul = false;
        $in_ol = false;

        $flush_para = function () use ( &$out, &$para ): void {
            if ( $para !== [] ) {
                $out[] = '<p>' . $this->inline( implode( ' ', $para ) ) . '</p>';
                $para  = [];
            }
        };

        // A list item is buffered raw and inlined only when it closes. Content
        // wraps at the column, so emphasis routinely spans a line break —
        // inlining each line as it arrives leaves the opening `**` of a
        // wrapped bold run unmatched, and it renders as text.
        $flush_item = function () use ( &$out, &$item ): void {
            if ( $item !== [] ) {
                $out[] = '<li>' . $this->inline( implode( ' ', $item ) ) . '</li>';
                $item  = [];
            }
        };

        $close_lists = static function () use ( &$out, &$in_ul, &$in_ol, $flush_item ): void {
            $flush_item();
            if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
            if ( $in_ol ) { $out[] = '</ol>'; $in_ol = false; }
        };

        $heading_pattern = '/^(#{1,' . $this->profile->max_heading_level . '})\s+(.+)$/';

        foreach ( $lines as $line ) {
            if ( trim( $line ) === '' ) {
                $flush_para();
                $close_lists();
                continue;
            }

            if ( preg_match( $heading_pattern, $line, $m ) ) {
                $flush_para();
                $close_lists();
                $level = strlen( $m[1] );
                $out[] = sprintf(
                    '<h%1$d class="%2$s">%3$s</h%1$d>',
                    $level,
                    esc_attr( $this->profile->cls( 'h' . $level ) ),
                    $this->inline( $m[2] )
                );
                continue;
            }

            if ( preg_match( '/^>\s?(.*)$/', $line, $m ) ) {
                $flush_para();
                $close_lists();
                $out[] = '<blockquote class="' . esc_attr( $this->profile->cls( 'quote' ) ) . '">'
                       . $this->inline( $m[1] ) . '</blockquote>';
                continue;
            }

            if ( preg_match( '/^[-*]\s+(.+)$/', $line, $m ) ) {
                $flush_para();
                $flush_item();
                if ( $in_ol ) { $out[] = '</ol>'; $in_ol = false; }
                if ( ! $in_ul ) {
                    $out[] = '<ul class="' . esc_attr( $this->profile->cls( 'list' ) ) . '">';
                    $in_ul = true;
                }
                $item[] = $m[1];
                continue;
            }

            if ( preg_match( '/^\d+\.\s+(.+)$/', $line, $m ) ) {
                $flush_para();
                $flush_item();
                if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
                if ( ! $in_ol ) {
                    $out[] = '<ol class="' . esc_attr( $this->profile->cls( 'list' ) . ' ' . $this->profile->cls( 'list--ordered' ) ) . '">';
                    $in_ol = true;
                }
                $item[] = $m[1];
                continue;
            }

            // A continuation line inside a list item: markdown wraps at the
            // column, and the corpora do too.
            if ( $item !== [] && preg_match( '/^\s+\S/', $line ) ) {
                $item[] = trim( $line );
                continue;
            }

            $para[] = trim( $line );
        }

        $flush_para();
        $flush_item();
        $close_lists();

        return implode( '', $out );
    }

    /** A line that looks like a table row. */
    private static function isTableRow( string $line ): bool {
        $line = trim( $line );

        return $line !== '' && strpos( $line, '|' ) !== false;
    }

    /** The `| --- | --- |` line under a table header. */
    private static function isTableDelimiter( string $line ): bool {
        return (bool) preg_match( '/^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/', $line )
            && strpos( $line, '-' ) !== false
            && strpos( $line, '|' ) !== false;
    }

    /**
     * A pipe table.
     *
     * Wrapped in a scroll container: a nine-column table has to scroll inside
     * its own box on a phone, or the whole page scrolls sideways and every
     * other line becomes unreadable.
     *
     * @param list<string> $rows Header row first, delimiter already dropped.
     */
    private function renderTable( array $rows ): string {
        $header = self::tableCells( (string) array_shift( $rows ) );

        $head = '';
        foreach ( $header as $cell ) {
            $head .= '<th scope="col">' . $this->inline( $cell ) . '</th>';
        }

        $body = '';
        foreach ( $rows as $row ) {
            $cells = self::tableCells( $row );
            $line  = '';

            foreach ( $cells as $index => $cell ) {
                // First column is the row's subject often enough that marking
                // it up as a row header is right more often than it is wrong,
                // and it is what lets a screen reader announce which row a
                // cell belongs to.
                $line .= $index === 0
                    ? '<th scope="row">' . $this->inline( $cell ) . '</th>'
                    : '<td>' . $this->inline( $cell ) . '</td>';
            }

            $body .= '<tr>' . $line . '</tr>';
        }

        return '<div class="' . esc_attr( $this->profile->cls( 'table-scroll' ) ) . '">'
            . '<table class="' . esc_attr( $this->profile->cls( 'table' ) ) . '"><thead><tr>'
            . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    /**
     * Split a table row into cells, dropping the leading and trailing pipes
     * that a well-formed row carries.
     *
     * @return list<string>
     */
    private static function tableCells( string $row ): array {
        $row = trim( $row );
        $row = preg_replace( '/^\||\|$/', '', $row );

        return array_map( 'trim', explode( '|', (string) $row ) );
    }

    /**
     * Inline formatting: bold, italic, code, links.
     *
     * Escapes first, then re-introduces markup, so a stray `<` in the source
     * is text and a `**bold**` is not.
     */
    private function inline( string $text ): string {
        $text = esc_html( $text );

        // Code spans are lifted out before emphasis runs and put back
        // afterwards. Replacing them in place is not enough: `a * b * c` would
        // still be scanned by the italic pattern, and the asterisks inside a
        // code sample are the whole reason it is a code sample.
        $code = [];
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            function ( array $m ) use ( &$code ): string {
                $token          = "\0code" . count( $code ) . "\0";
                $code[ $token ] = '<code class="' . esc_attr( $this->profile->cls( 'inline-code' ) ) . '">' . $m[1] . '</code>';

                return $token;
            },
            $text
        );

        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', (string) $text );
        $text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', (string) $text );

        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            function ( array $m ): string {
                if ( $this->profile->link_renderer !== null ) {
                    $html = ( $this->profile->link_renderer )( $m[1], $m[2] );
                    if ( is_string( $html ) ) {
                        return $html;
                    }
                }

                // The URL is validated rather than trusted: the source is
                // reviewed, but a translated source is reviewed by a
                // translator.
                $url = esc_url( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) );
                if ( $url === '' ) {
                    return $m[1];
                }

                $external = (bool) preg_match( '#^https?://#i', $url )
                    && strpos( $url, (string) home_url() ) !== 0;

                return sprintf(
                    '<a href="%s"%s>%s</a>',
                    $url,
                    $external ? ' target="_blank" rel="noopener noreferrer"' : '',
                    $m[1]
                );
            },
            (string) $text
        );

        return $code === [] ? (string) $text : strtr( (string) $text, $code );
    }
}
