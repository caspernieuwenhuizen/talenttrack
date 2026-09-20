<?php
/**
 * The i18n PR gate: every string this PR adds has a translation.
 *
 * ## The question it answers
 *
 * Does every new translatable string in this PR's diff have an entry, with
 * a non-empty `msgstr`, in the PR's translation fragment?
 *
 * That is a different question from the one this gate used to ask. It
 * regenerated the `.pot` on the merge base and on the head, `msgmerge`d
 * each into a copy of the catalogue, counted untranslated entries on both
 * sides, and failed when the number grew. Accurate, and hard to act on: it
 * reported
 *
 *     Empty-msgstr delta: 3 (baseline=9, head=12)
 *     New untranslated msgids: 0
 *
 * — two true statements whose combination takes real effort to read as
 * "this branch added three strings and translated none of them". It also
 * needed `wp-cli`, `gettext`, a second worktree and two full catalogue
 * merges to produce a number.
 *
 * This asks the direct question, names the strings, and runs in a second
 * with nothing installed but PHP — so the same command that decides the PR
 * can be run before pushing it.
 *
 * ## How "new" is decided
 *
 * A string counts as new when the gettext call that carries it sits on a
 * line this PR **added**, per `git diff --unified=0` against the merge
 * base. Not "every string in a file the PR touched" — reindenting a view
 * would then fail on strings somebody else wrote — and not a whole-file
 * comparison, which misses a string moved between files.
 *
 * A new string is satisfied by a non-empty `msgstr` in any of:
 *
 *   - the PR's fragments under `languages/pending/` (the route);
 *   - the shipped catalogue on this branch (a string that already ships,
 *     moved or re-wrapped by this PR);
 *   - the shipped catalogue at the merge base (same, for a call the PR
 *     re-indented into its added lines).
 *
 * A string the base catalogue already carries UNTRANSLATED is reported as
 * a notice and does not fail: that drift predates this PR, and failing on
 * it teaches people to reach for the override label, which is how a gate
 * stops meaning anything.
 *
 * Only calls whose text domain is literally `talenttrack` are considered.
 * A call with a non-literal msgid or domain cannot be checked against a
 * catalogue at all, so it is skipped rather than guessed at.
 *
 * Usage:
 *   php tools/check-i18n-fragment.php                 # vs origin/main
 *   php tools/check-i18n-fragment.php --base=main
 *   php tools/check-i18n-fragment.php --format=github # + workflow annotations
 *
 * Exit: 0 clean, 1 untranslated strings introduced, 2 tooling error.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/lib/po.php';
require_once __DIR__ . '/lib/gettext-calls.php';

$options = [
    'base'    => 'origin/main',
    'root'    => dirname( __DIR__ ),
    'locale'  => 'nl_NL',
    'format'  => 'plain',
];
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( preg_match( '/^--([a-z-]+)=(.*)$/', $arg, $m ) === 1 ) {
        $options[ $m[1] ] = $m[2];
        continue;
    }
    fwrite( STDERR, "check-i18n-fragment: unrecognised argument {$arg}\n" );
    exit( 2 );
}

$root      = rtrim( str_replace( '\\', '/', (string) $options['root'] ), '/' );
$base      = trim( (string) $options['base'] );
$locale    = (string) $options['locale'];
$annotate  = $options['format'] === 'github';
$catalogue = 'languages/talenttrack-' . $locale . '.po';

if ( $base === '' ) {
    fwrite( STDERR, "check-i18n-fragment: --base is required; there is no diff without one.\n" );
    exit( 2 );
}

$merge_base = tt_git( $root, [ 'merge-base', 'HEAD', $base ] );
if ( $merge_base === null ) {
    fwrite( STDERR, "check-i18n-fragment: cannot find a merge base with {$base}.\n" );
    exit( 2 );
}
$merge_base = trim( $merge_base );

$changed = tt_git( $root, [ 'diff', '--name-only', '--diff-filter=AM', $merge_base . '...HEAD', '--', 'src' ] );
if ( $changed === null ) {
    fwrite( STDERR, "check-i18n-fragment: cannot diff against {$merge_base}.\n" );
    exit( 2 );
}

$files = [];
foreach ( preg_split( '/\R/', $changed ) ?: [] as $line ) {
    $line = trim( $line );
    if ( $line !== '' && substr( $line, -4 ) === '.php' ) {
        $files[] = $line;
    }
}

if ( ! $files ) {
    echo "check-i18n-fragment OK — this PR changes no PHP under src/.\n";
    exit( 0 );
}

// --- what this PR added ----------------------------------------------------

$introduced = [];   // key => [ 'msgctxt' => …, 'msgid' => …, 'where' => list<string> ]

foreach ( $files as $relative ) {
    $path = $root . '/' . $relative;
    if ( ! is_readable( $path ) ) {
        continue;
    }

    $added = tt_added_lines( $root, $merge_base, $relative );
    if ( ! $added ) {
        continue;
    }

    foreach ( tt_gettext_calls( (string) file_get_contents( $path ) ) as $call ) {
        if ( ! isset( $added[ $call['line'] ] ) ) {
            continue;
        }
        $key = tt_po_key( $call['msgctxt'], $call['msgid'] );
        if ( ! isset( $introduced[ $key ] ) ) {
            $introduced[ $key ] = [
                'msgctxt' => $call['msgctxt'],
                'msgid'   => $call['msgid'],
                'where'   => [],
            ];
        }
        $introduced[ $key ]['where'][] = $relative . ':' . $call['line'];
    }
}

if ( ! $introduced ) {
    echo "check-i18n-fragment OK — this PR adds no new translatable strings.\n";
    exit( 0 );
}

// --- what is translated, and where -----------------------------------------

$translated = [];   // key => source description
$known      = [];   // key => true (present in the base catalogue, any state)

$base_source = tt_git( $root, [ 'show', $merge_base . ':' . $catalogue ] );
if ( $base_source !== null ) {
    foreach ( tt_po_parse( $base_source )['entries'] as $entry ) {
        if ( $entry['obsolete'] || $entry['header'] || ! empty( $entry['orphan'] ) ) {
            continue;
        }
        $known[ $entry['key'] ] = true;
        if ( ! tt_po_is_untranslated( $entry ) ) {
            $translated[ $entry['key'] ] = 'the shipped catalogue';
        }
    }
}

if ( is_readable( $root . '/' . $catalogue ) ) {
    foreach ( tt_po_parse( (string) file_get_contents( $root . '/' . $catalogue ) )['entries'] as $entry ) {
        if ( $entry['obsolete'] || $entry['header'] || ! empty( $entry['orphan'] ) ) {
            continue;
        }
        if ( ! tt_po_is_untranslated( $entry ) ) {
            $translated[ $entry['key'] ] = 'the shipped catalogue';
        }
    }
}

$fragments = glob( $root . '/languages/pending/*.po' ) ?: [];
sort( $fragments );
foreach ( $fragments as $path ) {
    foreach ( tt_po_parse( (string) file_get_contents( $path ) )['entries'] as $entry ) {
        if ( $entry['obsolete'] || $entry['header'] || ! empty( $entry['orphan'] ) ) {
            continue;
        }
        if ( ! tt_po_is_untranslated( $entry ) ) {
            $translated[ $entry['key'] ] = 'languages/pending/' . basename( $path );
        }
    }
}

// --- the verdict -----------------------------------------------------------

$missing  = [];
$inherited = [];
$covered  = 0;

foreach ( $introduced as $key => $string ) {
    if ( isset( $translated[ $key ] ) ) {
        $covered++;
        continue;
    }
    if ( isset( $known[ $key ] ) ) {
        // Untranslated on `main` before this PR touched it.
        $inherited[ $key ] = $string;
        continue;
    }
    $missing[ $key ] = $string;
}

foreach ( $inherited as $string ) {
    printf(
        "  note: %s is untranslated on %s already — not this PR's drift.\n",
        tt_quote( $string ),
        $base
    );
}

if ( ! $missing ) {
    printf(
        "check-i18n-fragment OK — %d string%s on this PR's added lines: %d translated, %d carried over untranslated from %s%s.\n",
        count( $introduced ),
        count( $introduced ) === 1 ? '' : 's',
        $covered,
        count( $inherited ),
        $base,
        $fragments ? sprintf( ' (%d fragment(s) read)', count( $fragments ) ) : ''
    );
    exit( 0 );
}

fwrite( STDERR, sprintf(
    "\ncheck-i18n-fragment FAILED — %d string%s added by this PR ha%s no Dutch translation.\n\n",
    count( $missing ),
    count( $missing ) === 1 ? '' : 's',
    count( $missing ) === 1 ? 's' : 've'
) );

foreach ( $missing as $string ) {
    fwrite( STDERR, sprintf(
        "  %s\n    %s\n",
        tt_quote( $string ),
        implode( "\n    ", array_slice( $string['where'], 0, 4 ) )
    ) );
    if ( $annotate ) {
        [ $file, $line ] = array_pad( explode( ':', $string['where'][0] ), 2, '1' );
        printf(
            "::error file=%s,line=%s::Untranslated string %s — add it to languages/pending/<issue>-<slug>.po\n",
            $file,
            $line,
            tt_quote( $string )
        );
    }
}

$suggested = tt_suggested_fragment( $root, $fragments );

fwrite( STDERR, sprintf( <<<'TXT'

Add each one to this PR's translation fragment, with its Dutch msgstr:

  %s

    #: %s
    msgid "%s"
    msgstr "…"

A PR does not edit languages/talenttrack-nl_NL.po — see
languages/pending/README.md. Run this check before pushing:

  php tools/check-i18n-fragment.php

If the Dutch genuinely cannot land in this PR, label it
`i18n-drift-acceptable`; the weekly drift report still records the strings.


TXT, $suggested, reset( $missing )['where'][0], tt_escaped_msgid( reset( $missing ) ) ) );

exit( 1 );

// ---------------------------------------------------------------------------

/** Run git in the repo and return stdout, or null on a non-zero exit. */
function tt_git( string $root, array $args ): ?string {
    $command = 'git -C ' . escapeshellarg( $root );
    foreach ( $args as $arg ) {
        $command .= ' ' . escapeshellarg( (string) $arg );
    }

    $output = [];
    $status = 0;
    exec( $command, $output, $status );

    return $status === 0 ? implode( "\n", $output ) : null;
}

/**
 * The line numbers this PR added to one file, as a lookup.
 *
 * `--unified=0` so a hunk header names exactly the added lines and nothing
 * around them: context lines are somebody else's code, and a gate that
 * blames you for them is a gate people route around.
 *
 * @return array<int, true>
 */
function tt_added_lines( string $root, string $merge_base, string $relative ): array {
    $diff = tt_git( $root, [ 'diff', '--unified=0', $merge_base . '...HEAD', '--', $relative ] );
    if ( $diff === null ) {
        return [];
    }

    $added = [];
    foreach ( preg_split( '/\R/', $diff ) ?: [] as $line ) {
        if ( preg_match( '/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m ) !== 1 ) {
            continue;
        }
        $start = (int) $m[1];
        $count = isset( $m[2] ) ? (int) $m[2] : 1;
        for ( $i = 0; $i < $count; $i++ ) {
            $added[ $start + $i ] = true;
        }
    }

    return $added;
}


/** A one-line rendering of an introduced string, for a message. */
function tt_quote( array $string ): string {
    $text = str_replace( '\\n', ' ', $string['msgid'] );
    if ( strlen( $text ) > 80 ) {
        $text = substr( $text, 0, 79 ) . '…';
    }
    $out = '"' . $text . '"';
    if ( $string['msgctxt'] !== '' ) {
        $out .= ' [msgctxt "' . $string['msgctxt'] . '"]';
    }

    return $out;
}

function tt_escaped_msgid( array $string ): string {
    return $string['msgid'];
}

/**
 * The fragment this PR should be writing to: the one it already has, or a
 * name built from the branch when there is none.
 *
 * @param list<string> $fragments
 */
function tt_suggested_fragment( string $root, array $fragments ): string {
    if ( $fragments ) {
        return 'languages/pending/' . basename( $fragments[0] );
    }

    $branch = tt_git( $root, [ 'rev-parse', '--abbrev-ref', 'HEAD' ] );
    $branch = $branch === null ? '' : trim( $branch );
    if ( preg_match( '#(\d+)-([a-z0-9-]+)#', $branch, $m ) === 1 ) {
        return 'languages/pending/' . $m[1] . '-' . $m[2] . '.po';
    }

    return 'languages/pending/<issue>-<slug>.po';
}
