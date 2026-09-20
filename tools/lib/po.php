<?php
/**
 * A gettext catalogue parser, shared by the translation tools.
 *
 * ## Why this exists rather than a regex
 *
 * Every line-based shortcut over a `.po` file fails on the entries that
 * matter most, and it fails silently:
 *
 * - A `msgid` may be written on one line or wrapped across continuation
 *   lines. gettext keys on the CONCATENATED content, so `"ab" "cd"` and
 *   `"abcd"` are the same entry. A tool that keys on the raw literals
 *   sees two, reports a present entry as missing, appends it, and the
 *   catalogue now carries a duplicate `msgid` that `msgfmt` refuses.
 *   That happened during the 2026-09-20 drain: four entries reported
 *   missing from `main` that were already there, three duplicates
 *   created by "fixing" it.
 * - Long strings and every `_n()` plural are written `msgid ""` with the
 *   text on the lines below, so a pattern matching `msgid "..."` on one
 *   line misses exactly the entries that are hardest to get right.
 * - Entries are not reliably blank-line separated. A union merge can glue
 *   an entry's header directly onto the previous entry's `msgstr`, so a
 *   reader that splits on blank lines sees one entry where there are two.
 *
 * So: one state machine, entries closed by the next entry header rather
 * than by whitespace, quoted fragments joined before the key is built.
 *
 * Pure PHP — no `msgfmt`, no `msgmerge`, neither of which is installed on
 * the maintainer's machine. A parser you cannot run locally is a parser
 * that tells you to go and ask CI.
 */

declare( strict_types = 1 );

/**
 * The key gettext itself uses: the (msgctxt, msgid) pair.
 *
 * Two entries sharing a `msgid` but differing in `msgctxt` are distinct
 * and legitimate — that is what contexts are for. Keying on the msgid
 * alone calls ~27 honest pairs in the shipped catalogue a duplicate.
 */
function tt_po_key( string $msgctxt, string $msgid ): string {
    return $msgctxt . "\x04" . $msgid;
}

/** Split a key back into its two halves, for reporting. */
function tt_po_unkey( string $key ): array {
    $parts = explode( "\x04", $key, 2 );

    return [ $parts[0], $parts[1] ?? '' ];
}

/** Strip the surrounding quotes from one po string literal. */
function tt_po_unquote( string $literal ): string {
    $literal = trim( $literal );
    if ( strlen( $literal ) >= 2 && $literal[0] === '"' && substr( $literal, -1 ) === '"' ) {
        return substr( $literal, 1, -1 );
    }

    return $literal;
}

/**
 * Parse a catalogue into entries.
 *
 * Each entry carries both its decoded fields (for comparison) and its raw
 * block (for re-emission), because consolidation moves entries around
 * verbatim: re-rendering them would rewrap comments and produce a release
 * diff nobody can read.
 *
 * @return array{
 *     header: array|null,
 *     entries: list<array{
 *         key:string, msgctxt:string, msgid:string, msgid_plural:?string,
 *         msgstr:array<int,string>, obsolete:bool, header:bool,
 *         lines:list<string>, msgstr_lines:list<string>, line:int
 *     }>
 * }
 */
function tt_po_parse( string $source ): array {
    $lines   = preg_split( '/\R/', $source );
    $lines   = is_array( $lines ) ? $lines : [];
    $entries = [];

    $blank = [
        'msgctxt'      => '',
        'msgid'        => null,
        'msgid_plural' => null,
        'msgstr'       => [],
        'obsolete'     => false,
        'lines'        => [],
        'msgstr_lines' => [],
        'line'         => 0,
        'seen_msgstr'  => false,
    ];
    $current = $blank;

    // Which multi-line literal the next bare `"…"` line continues.
    $into      = null;   // 'msgctxt' | 'msgid' | 'msgid_plural' | int (msgstr index)
    $line_no   = 0;

    $flush = static function () use ( &$entries, &$current, $blank ): void {
        if ( $current['lines'] === [] ) {
            $current = $blank;

            return;
        }
        // A block with no msgid at all — a free-standing comment, or the
        // banner some catalogues open with. It carries no entry, but it is
        // not ours to delete: anything that rewrites the file has to put it
        // back where it was.
        $current['orphan'] = $current['msgid'] === null;
        $msgid             = (string) $current['msgid'];
        $current['msgid']  = $msgid;
        $current['key']    = tt_po_key( (string) $current['msgctxt'], $msgid );
        $current['header'] = ! $current['orphan']
            && $msgid === ''
            && (string) $current['msgctxt'] === '';
        unset( $current['seen_msgstr'] );
        $entries[] = $current;
        $current   = $blank;
    };

    foreach ( $lines as $raw ) {
        $line_no++;
        $line = trim( $raw );

        $obsolete = false;
        if ( strncmp( $line, '#~', 2 ) === 0 ) {
            $obsolete = true;
            $line     = ltrim( substr( $line, 2 ) );
        }

        if ( $line === '' ) {
            // A blank line always closes an entry; it never starts one, and
            // it is not part of any block.
            $flush();
            $into = null;
            continue;
        }

        $starts_entry = strncmp( $line, 'msgctxt ', 8 ) === 0
            || strncmp( $line, 'msgid ', 6 ) === 0
            || ( ! $obsolete && $line[0] === '#' );

        // An entry header arriving after a msgstr opens a new entry even
        // with no blank line between — that is the glued shape a union
        // merge produces, and both halves are real entries.
        if ( $starts_entry && $current['seen_msgstr'] ) {
            $flush();
            $into = null;
        }

        if ( $current['line'] === 0 ) {
            $current['line'] = $line_no;
        }
        $current['lines'][] = $raw;
        if ( $obsolete ) {
            $current['obsolete'] = true;
        }

        if ( ! $obsolete && $line[0] === '#' ) {
            $into = null;
            continue;
        }

        if ( strncmp( $line, 'msgctxt ', 8 ) === 0 ) {
            $current['msgctxt'] = tt_po_unquote( substr( $line, 8 ) );
            $into               = 'msgctxt';
            continue;
        }

        if ( strncmp( $line, 'msgid_plural ', 13 ) === 0 ) {
            $current['msgid_plural'] = tt_po_unquote( substr( $line, 13 ) );
            $into                    = 'msgid_plural';
            continue;
        }

        if ( strncmp( $line, 'msgid ', 6 ) === 0 ) {
            $current['msgid'] = tt_po_unquote( substr( $line, 6 ) );
            $into             = 'msgid';
            continue;
        }

        if ( preg_match( '/^msgstr\[(\d+)\]\s*(.*)$/', $line, $m ) === 1 ) {
            $index                      = (int) $m[1];
            $current['msgstr'][ $index ] = tt_po_unquote( $m[2] );
            $current['msgstr_lines'][]   = $raw;
            $current['seen_msgstr']      = true;
            $into                        = $index;
            continue;
        }

        if ( strncmp( $line, 'msgstr ', 7 ) === 0 || $line === 'msgstr' ) {
            $current['msgstr'][0]      = tt_po_unquote( substr( $line, 6 ) );
            $current['msgstr_lines'][] = $raw;
            $current['seen_msgstr']    = true;
            $into                      = 0;
            continue;
        }

        if ( $line[0] === '"' ) {
            $fragment = tt_po_unquote( $line );
            if ( $into === 'msgctxt' ) {
                $current['msgctxt'] .= $fragment;
            } elseif ( $into === 'msgid' ) {
                $current['msgid'] .= $fragment;
            } elseif ( $into === 'msgid_plural' ) {
                $current['msgid_plural'] .= $fragment;
            } elseif ( is_int( $into ) ) {
                $current['msgstr'][ $into ] .= $fragment;
                $current['msgstr_lines'][]   = $raw;
            }
            continue;
        }

        $into = null;
    }
    $flush();

    $header = null;
    foreach ( $entries as $entry ) {
        if ( $entry['header'] && ! $entry['obsolete'] ) {
            $header = $entry;
            break;
        }
    }

    return [ 'header' => $header, 'entries' => $entries ];
}

/** True when every translation slot of an entry is empty. */
function tt_po_is_untranslated( array $entry ): bool {
    foreach ( $entry['msgstr'] as $value ) {
        if ( trim( (string) $value ) !== '' ) {
            return false;
        }
    }

    return true;
}

/** The translations of an entry, joined, for comparing two copies. */
function tt_po_msgstr_signature( array $entry ): string {
    $msgstr = $entry['msgstr'];
    ksort( $msgstr );

    return implode( "\x1f", array_map( 'strval', $msgstr ) );
}

/**
 * Index the live (non-obsolete) entries by key.
 *
 * @return array<string, list<int>> key => positions in the entry list
 */
function tt_po_index( array $parsed, bool $obsolete = false ): array {
    $index = [];
    foreach ( $parsed['entries'] as $position => $entry ) {
        if ( $entry['obsolete'] !== $obsolete ) {
            continue;
        }
        if ( $entry['header'] || ! empty( $entry['orphan'] ) ) {
            continue;
        }
        $index[ $entry['key'] ][] = $position;
    }

    return $index;
}

/** A one-line, quote-free rendering of a msgid for an error message. */
function tt_po_label( array $entry ): string {
    $text = str_replace( [ '\\n', "\n" ], ' ', $entry['msgid'] );
    if ( strlen( $text ) > 80 ) {
        $text = substr( $text, 0, 79 ) . '…';
    }
    $label = '"' . $text . '"';
    if ( $entry['msgctxt'] !== '' ) {
        $label .= ' [msgctxt "' . $entry['msgctxt'] . '"]';
    }

    return $label;
}

/** The raw text of an entry, with no trailing newline. */
function tt_po_block( array $entry ): string {
    return implode( "\n", $entry['lines'] );
}

/**
 * One entry's block with its `msgstr` replaced by another entry's.
 *
 * Used when the catalogue already carries a msgid with an empty msgstr —
 * the usual case, since the catalogue regeneration adds the msgid and the
 * fragment carries the Dutch. The source comments and references stay put;
 * only the translation lines are swapped, so the release diff reads as
 * "this string got translated".
 */
function tt_po_with_msgstr_of( array $target, array $source ): string {
    $out     = [];
    $swapped = false;
    foreach ( $target['lines'] as $raw ) {
        $line = ltrim( $raw );
        if ( strncmp( $line, '#~', 2 ) === 0 ) {
            $line = ltrim( substr( $line, 2 ) );
        }
        $is_msgstr = strncmp( $line, 'msgstr', 6 ) === 0;
        $is_cont   = $line !== '' && $line[0] === '"';

        if ( $is_msgstr ) {
            if ( ! $swapped ) {
                foreach ( $source['msgstr_lines'] as $replacement ) {
                    $out[] = $replacement;
                }
                $swapped = true;
            }
            continue;
        }
        if ( $swapped && $is_cont ) {
            // A continuation of the msgstr being replaced.
            continue;
        }
        $out[] = $raw;
    }

    return implode( "\n", $out );
}
