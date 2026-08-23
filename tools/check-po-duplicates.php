<?php
/**
 * Duplicate-msgid gate for the translation catalogues (#2765).
 *
 * ## What goes wrong without this
 *
 * `.gitattributes` gives `languages/*.po` the union merge driver, so parallel
 * branches stop conflicting on the catalogue. The cost is that a union merge
 * takes BOTH sides of every hunk. When `i18n-sync` has relocated and rewrapped
 * a branch's appended entries into their sorted position on `main`, merging
 * `main` back into that branch produces the appended copy *and* the relocated
 * copy — and git reports no conflict, because as far as the driver is
 * concerned nothing disagreed.
 *
 * It happened four times in one day, on four separate branches, and the clean
 * one is the tell: the damage depends on whether `main` has relocated an entry
 * the branch also carries, which is timing, not anything the author did.
 *
 * Duplicates are what `msgfmt` refuses, so one reaching `main` can break the
 * `.mo` compile for every locale. The quieter case is worse: when the two
 * copies disagree — one translated, one emptied by a msgmerge that lost the
 * string — gettext takes the first, and a Dutch string silently reverts to
 * English with no error anywhere.
 *
 * ## What this does about it
 *
 * It does not prevent the merge damage; it makes the damage impossible to
 * merge unnoticed, which is the actual problem. The check fails when a branch
 * duplicates a msgid that the base does not, and it names the strings.
 *
 * A `msgid` shared between a plain entry and a `msgctxt` one is NOT a
 * duplicate — that is what contexts are for, and a checker that ignores
 * msgctxt reports 21 false positives on `main` today. The key is the pair.
 *
 * Pure PHP: no `msgfmt`, no `jq`, neither of which is installed on the
 * maintainer's machine. A gate you cannot reproduce locally is a gate that
 * tells you to go and ask CI.
 *
 * Every catalogue is checked, not only Dutch: they all carry the same merge
 * driver, and a duplicate in `de_DE` breaks that locale's compile exactly as
 * loudly.
 *
 * Usage:
 *   php tools/check-po-duplicates.php                     # vs origin/main
 *   php tools/check-po-duplicates.php --base=main
 *   php tools/check-po-duplicates.php --base=             # no comparison;
 *                                                         # just report
 *   php tools/check-po-duplicates.php --file=languages/talenttrack-nl_NL.po
 *
 * Exit: 0 clean, 1 new duplicates, 2 tooling error.
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

$options = [
    'file' => '',
    'base' => 'origin/main',
];
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( preg_match( '/^--([a-z-]+)=(.*)$/', $arg, $m ) ) {
        $options[ $m[1] ] = $m[2];
    }
}

$files = [];
if ( (string) $options['file'] !== '' ) {
    $files[] = ltrim( str_replace( '\\', '/', (string) $options['file'] ), '/' );
} else {
    foreach ( array_merge(
        glob( $root . '/languages/*.po' ) ?: [],
        glob( $root . '/languages/*.pot' ) ?: []
    ) as $found ) {
        $files[] = 'languages/' . basename( $found );
    }
    sort( $files );
}

if ( ! $files ) {
    fwrite( STDERR, "check-po-duplicates: no catalogues found under languages/\n" );
    exit( 2 );
}

$base_ref = trim( (string) $options['base'] );
$failed   = false;
$clean    = [];

foreach ( $files as $relative ) {
    $path = $root . '/' . $relative;
    if ( ! is_readable( $path ) ) {
        fwrite( STDERR, "check-po-duplicates: cannot read {$relative}\n" );
        exit( 2 );
    }

    $current   = duplicates_in( (string) file_get_contents( $path ) );
    $base_dups = [];

    if ( $base_ref !== '' ) {
        $base_source = read_from_git( $base_ref, $relative );
        if ( $base_source === null ) {
            // A missing base is not a failure: a fresh clone, a detached CI
            // checkout, or a brand-new catalogue all land here, and failing
            // the build over it would teach people to ignore this check.
            fwrite( STDOUT, "check-po-duplicates: no {$relative} at {$base_ref}; reporting without a comparison.\n" );
        } else {
            $base_dups = duplicates_in( $base_source );
        }
    }

    $introduced = [];
    foreach ( $current as $key => $lines ) {
        $was = $base_dups[ $key ] ?? [];
        // More copies than the base had, or a key the base never duplicated.
        if ( count( $lines ) > max( 2, count( $was ) ) || ! $was ) {
            $introduced[ $key ] = $lines;
        }
    }

    if ( ! $introduced ) {
        $clean[] = sprintf( '%s (%d duplicated)', $relative, count( $current ) );
        continue;
    }

    $failed = true;

    fwrite( STDERR, "\n" );
    fwrite( STDERR, sprintf(
        "check-po-duplicates FAILED — %s duplicates %d msgid%s that %s does not.\n\n",
        $relative,
        count( $introduced ),
        count( $introduced ) === 1 ? '' : 's',
        $base_ref !== '' ? $base_ref : 'the base'
    ) );

    foreach ( $introduced as $key => $lines ) {
        [ $ctx, $msgid ] = explode( "\x04", $key, 2 );
        fwrite( STDERR, sprintf(
            "  lines %s%s\n    msgid \"%s\"\n",
            implode( ', ', $lines ),
            $ctx === '' ? '' : "  [msgctxt \"{$ctx}\"]",
            shorten( $msgid )
        ) );
    }
}

if ( ! $failed ) {
    printf( "check-po-duplicates OK — %s.\n", implode( ', ', $clean ) );
    exit( 0 );
}

fwrite( STDERR, <<<'TXT'

This is almost always the union merge driver taking both sides after
i18n-sync relocated your entries on main. Git reported no conflict because
nothing disagreed — both copies are "correct".

To fix, rebuild the catalogue rather than hand-deleting lines:

  git checkout origin/main -- languages/talenttrack-nl_NL.po
  # re-add ONLY the strings this branch introduces, with their Dutch msgstr
  php tools/check-po-duplicates.php

Deleting one of each pair by hand works too, but the copies can disagree —
one translated, one emptied by a msgmerge that lost the string — and
gettext takes the first. Rebuilding removes that coin flip.

See docs/contributing.md § Translation catalogue.

TXT );

exit( 1 );

/**
 * Every (msgctxt, msgid) pair that appears more than once, with the line
 * numbers of each occurrence.
 *
 * Obsolete entries (`#~`) are skipped: gettext treats them as comments, and
 * a live entry sharing a msgid with a commented-out one is not a duplicate.
 *
 * @return array<string, list<int>> "ctx\x04msgid" => line numbers
 */
function duplicates_in( string $source ): array {
    $seen = [];

    $lines   = preg_split( '/\R/', $source ) ?: [];
    $ctx     = '';
    $msgid   = null;
    $line_no = 0;
    $started = 0;

    $flush = static function () use ( &$seen, &$ctx, &$msgid, &$started ): void {
        if ( $msgid === null ) return;
        $seen[ $ctx . "\x04" . $msgid ][] = $started;
        $msgid = null;
        $ctx   = '';
    };

    // Which multi-line string we are currently accumulating into.
    $accumulating = null; // 'ctx' | 'id' | null

    foreach ( $lines as $raw ) {
        $line_no++;
        $line = trim( $raw );

        if ( $line === '' || $line[0] === '#' ) {
            // A blank line or any comment ends an entry's string run. The
            // entry itself is only recorded once its msgid is complete, so
            // trailing msgstr lines fall through here harmlessly.
            $accumulating = null;
            continue;
        }

        if ( strncmp( $line, 'msgctxt ', 8 ) === 0 ) {
            $flush();
            $ctx          = unquote( substr( $line, 8 ) );
            $accumulating = 'ctx';
            continue;
        }

        if ( strncmp( $line, 'msgid ', 6 ) === 0 ) {
            // A msgid closes the previous entry — catalogues in the wild are
            // not reliably blank-line separated, and `msgmerge` output is not
            // the only thing that writes this file.
            $flush();
            $msgid        = unquote( substr( $line, 6 ) );
            $started      = $line_no;
            $accumulating = 'id';
            continue;
        }

        if ( strncmp( $line, 'msgid_plural ', 13 ) === 0
            || strncmp( $line, 'msgstr', 6 ) === 0 ) {
            $accumulating = null;
            continue;
        }

        // A bare "…" line continues whatever came before it.
        if ( $accumulating !== null && $line[0] === '"' ) {
            if ( $accumulating === 'ctx' ) {
                $ctx .= unquote( $line );
            } else {
                $msgid .= unquote( $line );
            }
        }
    }
    $flush();

    $out = [];
    foreach ( $seen as $key => $line_numbers ) {
        // The header entry is `msgid ""`; one of those is expected.
        if ( $key === "\x04" ) continue;
        if ( count( $line_numbers ) > 1 ) {
            $out[ $key ] = $line_numbers;
        }
    }

    return $out;
}

/** Strip the surrounding quotes from a po string literal. */
function unquote( string $literal ): string {
    $literal = trim( $literal );
    if ( strlen( $literal ) >= 2 && $literal[0] === '"' && substr( $literal, -1 ) === '"' ) {
        $literal = substr( $literal, 1, -1 );
    }

    return $literal;
}

function shorten( string $text, int $max = 90 ): string {
    $text = str_replace( [ "\\n", "\n" ], ' ', $text );

    return strlen( $text ) <= $max ? $text : substr( $text, 0, $max - 1 ) . '…';
}

/** File contents at a git ref, or null when it is not there. */
function read_from_git( string $ref, string $relative ): ?string {
    $command = 'git show ' . escapeshellarg( $ref . ':' . $relative ) . ' 2>&1';
    $output  = [];
    $status  = 0;
    exec( $command, $output, $status );

    return $status === 0 ? implode( "\n", $output ) : null;
}
