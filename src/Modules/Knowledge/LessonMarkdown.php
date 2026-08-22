<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Blocks\BlockRegistry;

/**
 * LessonMarkdown — markdown to classed HTML, with block delegation.
 *
 * Separate from `Documentation\Markdown` rather than an extension of it,
 * for three reasons that together outweigh having two small renderers:
 *
 *   1. That renderer emits wp-admin inline style attributes with hardcoded
 *      greys. Those are grandfathered where they are, but a frontend reader
 *      needs classes reading `tokens.css` — a fixed `#f6f7f7` does not
 *      survive a club colour scheme, let alone a dark surface.
 *   2. It has no table support, and the course corpus is full of tables.
 *   3. It discards the fence info string, which is the entire mechanism
 *      the interactive blocks hang off.
 *
 * Reworking it in place would mean rewriting every emit line during the
 * weeks #2546–#2551 are also editing that module, for the benefit of one
 * consumer. Filed instead as tech debt: when the docs renderer moves to
 * tokens, the two collapse into one.
 *
 * Deliberately tiny, in the same spirit as its neighbour. We control every
 * input file, so this covers what the corpus uses and nothing else. Text
 * is escaped even though the corpus is plugin-shipped, because translated
 * courses are edited by people who are not reviewing PHP.
 */
final class LessonMarkdown {

    /**
     * Render a full lesson body: prose, tables and blocks.
     *
     * @return array{html: string, interactive: bool} `interactive` is true
     *         when at least one rendered block needs the block script.
     */
    public static function render( string $source ): array {
        $source      = str_replace( [ "\r\n", "\r" ], "\n", $source );
        $lines       = explode( "\n", $source );
        $out         = [];
        $interactive = false;

        $count = count( $lines );
        for ( $i = 0; $i < $count; $i++ ) {
            $line = $lines[ $i ];

            // Fenced section. Everything up to the closing fence belongs
            // to it, including blank lines and nested markdown.
            if ( preg_match( '/^```(.*)$/', $line, $match ) ) {
                $info = trim( $match[1] );
                $body = [];

                for ( $i++; $i < $count; $i++ ) {
                    if ( preg_match( '/^```\s*$/', $lines[ $i ] ) ) {
                        break;
                    }
                    $body[] = $lines[ $i ];
                }

                $rendered = self::renderFence( $info, implode( "\n", $body ) );
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

                $out[] = self::renderTable( $table );
                continue;
            }

            // Prose is queued rather than rendered here, so a paragraph
            // split across a table or a block still renders as one.
            $out[] = self::PROSE_MARKER . $line;
        }

        return [
            'html'        => self::assemble( $out ),
            'interactive' => $interactive,
        ];
    }

    /**
     * Render a fragment that is prose only — a callout's body, an
     * assignment's text. Blocks are not nested, so anything fenced inside
     * one renders as a code block.
     */
    public static function renderProse( string $source ): string {
        $source = str_replace( [ "\r\n", "\r" ], "\n", trim( $source ) );

        return self::renderLines( explode( "\n", $source ) );
    }

    /** Marker distinguishing queued prose lines from finished HTML. */
    private const PROSE_MARKER = "\0prose\0";

    /**
     * Run the queued output, rendering consecutive prose lines together so
     * paragraphs and lists survive being interleaved with tables and
     * blocks.
     *
     * @param list<string> $parts
     */
    private static function assemble( array $parts ): string {
        $html  = '';
        $prose = [];

        $flush = static function () use ( &$html, &$prose ): void {
            if ( $prose !== [] ) {
                $html .= self::renderLines( $prose );
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
     * A fenced section: a registered block, or a code sample.
     *
     * An unclaimed info string falls through to a code block rather than
     * failing. A course written against a newer release, opened on an
     * older one, loses one widget and keeps the lesson.
     *
     * @return array{html: string, interactive: bool}
     */
    private static function renderFence( string $info, string $body ): array {
        $name = BlockRegistry::parseName( $info );

        if ( $name !== '' ) {
            $class = BlockRegistry::resolve( $name );
            if ( $class !== null ) {
                return [
                    'html'        => $class::render( BlockRegistry::parseAttributes( $info ), $body ),
                    'interactive' => $class::isInteractive(),
                ];
            }
        }

        $language = $name !== '' ? ' data-language="' . esc_attr( $name ) . '"' : '';

        return [
            'html'        => '<pre class="tt-lesson-code"' . $language . '><code>' . esc_html( $body ) . '</code></pre>',
            'interactive' => false,
        ];
    }

    /**
     * Headings, lists, blockquotes and paragraphs.
     *
     * @param list<string> $lines
     */
    private static function renderLines( array $lines ): string {
        $out   = [];
        $para  = [];
        $item  = [];
        $in_ul = false;
        $in_ol = false;

        $flush_para = static function () use ( &$out, &$para ): void {
            if ( $para !== [] ) {
                $out[] = '<p>' . self::inline( implode( ' ', $para ) ) . '</p>';
                $para  = [];
            }
        };

        // A list item is buffered raw and inlined only when it closes.
        // The corpus wraps at the column, so emphasis routinely spans a
        // line break — inlining each line as it arrives leaves the opening
        // `**` of a wrapped bold run unmatched, and it renders as text.
        $flush_item = static function () use ( &$out, &$item ): void {
            if ( $item !== [] ) {
                $out[] = '<li>' . self::inline( implode( ' ', $item ) ) . '</li>';
                $item  = [];
            }
        };

        $close_lists = static function () use ( &$out, &$in_ul, &$in_ol, $flush_item ): void {
            $flush_item();
            if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
            if ( $in_ol ) { $out[] = '</ol>'; $in_ol = false; }
        };

        foreach ( $lines as $line ) {
            if ( trim( $line ) === '' ) {
                $flush_para();
                $close_lists();
                continue;
            }

            if ( preg_match( '/^(#{1,4})\s+(.+)$/', $line, $m ) ) {
                $flush_para();
                $close_lists();
                $level = strlen( $m[1] );
                $out[] = sprintf(
                    '<h%1$d class="tt-lesson-h%1$d">%2$s</h%1$d>',
                    $level,
                    self::inline( $m[2] )
                );
                continue;
            }

            if ( preg_match( '/^>\s?(.*)$/', $line, $m ) ) {
                $flush_para();
                $close_lists();
                $out[] = '<blockquote class="tt-lesson-quote">' . self::inline( $m[1] ) . '</blockquote>';
                continue;
            }

            if ( preg_match( '/^[-*]\s+(.+)$/', $line, $m ) ) {
                $flush_para();
                $flush_item();
                if ( $in_ol ) { $out[] = '</ol>'; $in_ol = false; }
                if ( ! $in_ul ) { $out[] = '<ul class="tt-lesson-list">'; $in_ul = true; }
                $item[] = $m[1];
                continue;
            }

            if ( preg_match( '/^\d+\.\s+(.+)$/', $line, $m ) ) {
                $flush_para();
                $flush_item();
                if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
                if ( ! $in_ol ) { $out[] = '<ol class="tt-lesson-list tt-lesson-list--ordered">'; $in_ol = true; }
                $item[] = $m[1];
                continue;
            }

            // A continuation line inside a list item: markdown wraps at
            // the column, and the corpus does too.
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
     * Wrapped in a scroll container: a nine-column methods table has to
     * scroll inside its own box on a phone, or the whole page scrolls
     * sideways and every other line becomes unreadable.
     *
     * @param list<string> $rows Header row first, delimiter already dropped.
     */
    private static function renderTable( array $rows ): string {
        $header = self::tableCells( array_shift( $rows ) );

        $head = '';
        foreach ( $header as $cell ) {
            $head .= '<th scope="col">' . self::inline( $cell ) . '</th>';
        }

        $body = '';
        foreach ( $rows as $row ) {
            $cells = self::tableCells( $row );
            $line  = '';

            foreach ( $cells as $index => $cell ) {
                // First column is the row's subject often enough that
                // marking it up as a row header is right more often than
                // it is wrong, and it is what lets a screen reader
                // announce which row a cell belongs to.
                $line .= $index === 0
                    ? '<th scope="row">' . self::inline( $cell ) . '</th>'
                    : '<td>' . self::inline( $cell ) . '</td>';
            }

            $body .= '<tr>' . $line . '</tr>';
        }

        return '<div class="tt-lesson-table-scroll"><table class="tt-lesson-table"><thead><tr>'
            . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    /**
     * Split a table row into cells, dropping the leading and trailing
     * pipes that a well-formed row carries.
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
     * Escapes first, then re-introduces markup, so a stray `<` in the
     * corpus is text and a `**bold**` is not.
     */
    private static function inline( string $text ): string {
        $text = esc_html( $text );

        // Code spans are lifted out before emphasis runs and put back
        // afterwards. Replacing them in place is not enough: `a * b * c`
        // would still be scanned by the italic pattern, and the asterisks
        // inside a code sample are the whole reason it is a code sample.
        $code = [];
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            static function ( array $m ) use ( &$code ): string {
                $token          = "\0code" . count( $code ) . "\0";
                $code[ $token ] = '<code class="tt-lesson-inline-code">' . $m[1] . '</code>';

                return $token;
            },
            $text
        );

        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', (string) $text );
        $text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', (string) $text );

        // Links. The URL is validated rather than trusted: the corpus is
        // reviewed, but a translated corpus is reviewed by a translator.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            static function ( array $m ): string {
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
