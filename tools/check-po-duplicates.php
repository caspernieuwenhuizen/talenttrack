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
 * ## The three things it looks for (#3205)
 *
 * **1. Duplicate live entries.** The original job, above.
 *
 * **2. Duplicate obsolete entries.** `#~` entries were skipped outright,
 * on the reasoning that gettext treats them as comments. True while they
 * stay commented out — and they do not: `msgmerge` promotes an obsolete
 * entry back to live the moment its string reappears in the `.pot`. Two
 * obsolete copies therefore become two live copies, in a commit nobody
 * wrote by hand, and the first anyone hears of it is a failed compile. The
 * two namespaces are counted separately, because a live entry and an
 * obsolete one sharing a msgid is a normal state and not a fault.
 *
 * **3. Glued entries.** A union merge can append an entry directly onto
 * the tail of the one before it, with no blank line between:
 *
 *     msgstr "…Toegetreden…"
 *     #: src/Shared/Frontend/FrontendRateCardView.php:65   ← no blank line
 *     msgid "You do not have access to rate cards."
 *
 * This is the fingerprint of the damage, and it is worth reporting on its
 * own — before it duplicates anything. Anything that reads the catalogue in
 * blocks sees one entry where there are two, so a re-apply that looks
 * correct silently carries both across; and the glue is what tells an
 * author their file was merged rather than written. `main` has none, so
 * every occurrence is new.
 *
 * `msgid_plural` and `msgstr[n]` legitimately continue their own entry and
 * are not glue. Including them reports every plural in the file.
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

    $source        = (string) file_get_contents( $path );
    $current       = duplicates_in( $source );
    $current_obs   = obsolete_duplicates_in( $source );
    $current_glue  = glued_in( $source );
    $base_dups     = [];
    $base_obs      = [];
    $base_glue     = 0;

    if ( $base_ref !== '' ) {
        $base_source = read_from_git( $base_ref, $relative );
        if ( $base_source === null ) {
            // A missing base is not a failure: a fresh clone, a detached CI
            // checkout, or a brand-new catalogue all land here, and failing
            // the build over it would teach people to ignore this check.
            fwrite( STDOUT, "check-po-duplicates: no {$relative} at {$base_ref}; reporting without a comparison.\n" );
        } else {
            $base_dups = duplicates_in( $base_source );
            $base_obs  = obsolete_duplicates_in( $base_source );
            $base_glue = count( glued_in( $base_source ) );
        }
    }

    $introduced     = introduced_since( $current, $base_dups );
    $introduced_obs = introduced_since( $current_obs, $base_obs );

    // Glue is counted rather than keyed: its line numbers move with every
    // edit, so "the base had three and this branch has four" is the only
    // comparison that means anything. `main` has none, so in practice any
    // count at all is the branch's.
    $introduced_glue = count( $current_glue ) > $base_glue ? $current_glue : [];

    if ( ! $introduced && ! $introduced_obs && ! $introduced_glue ) {
        $clean[] = sprintf( '%s (%d duplicated)', $relative, count( $current ) );
        continue;
    }

    $failed = true;
    fwrite( STDERR, "\n" );

    if ( $introduced ) {
        fwrite( STDERR, sprintf(
            "check-po-duplicates FAILED — %s duplicates %d msgid%s that %s does not.\n\n",
            $relative,
            count( $introduced ),
            count( $introduced ) === 1 ? '' : 's',
            $base_ref !== '' ? $base_ref : 'the base'
        ) );
        report_keys( $introduced );
    }

    if ( $introduced_obs ) {
        fwrite( STDERR, sprintf(
            "check-po-duplicates FAILED — %s duplicates %d OBSOLETE msgid%s that %s does not.\n"
            . "  An obsolete entry is not inert: msgmerge promotes it back to live when the\n"
            . "  string reappears, and two copies come back as two live copies.\n\n",
            $relative,
            count( $introduced_obs ),
            count( $introduced_obs ) === 1 ? '' : 's',
            $base_ref !== '' ? $base_ref : 'the base'
        ) );
        report_keys( $introduced_obs );
    }

    if ( $introduced_glue ) {
        fwrite( STDERR, sprintf(
            "check-po-duplicates FAILED — %s has %d entr%s glued onto the one before it.\n"
            . "  An entry header sitting directly on a msgstr, with no blank line between, is\n"
            . "  the fingerprint of a union merge. Anything reading the catalogue in blocks\n"
            . "  sees one entry where there are two.\n\n",
            $relative,
            count( $introduced_glue ),
            count( $introduced_glue ) === 1 ? 'y' : 'ies'
        ) );
        foreach ( $introduced_glue as $spot ) {
            fwrite( STDERR, sprintf( "  line %d\n    %s\n", $spot['line'], $spot['text'] ) );
        }
        fwrite( STDERR, "\n" );
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
 * Every (msgctxt, msgid) pair that appears more than once in the LIVE
 * namespace, with the line numbers of each occurrence.
 *
 * @return array<string, list<int>> "ctx\x04msgid" => line numbers
 */
function duplicates_in( string $source ): array {
    return repeated( scan( $source )['live'] );
}

/**
 * The same question asked of the obsolete (`#~`) namespace.
 *
 * Counted separately from the live one on purpose: a live entry and an
 * obsolete entry sharing a msgid is the normal state of a catalogue whose
 * string came back, not a fault. Two *obsolete* copies are a fault waiting
 * to happen — see the header, `msgmerge` promotes both.
 *
 * @return array<string, list<int>>
 */
function obsolete_duplicates_in( string $source ): array {
    return repeated( scan( $source )['obsolete'] );
}

/**
 * Entry-start lines sitting directly on the previous entry's `msgstr`.
 *
 * @return list<array{line:int, text:string}>
 */
function glued_in( string $source ): array {
    return scan( $source )['glue'];
}

/**
 * @param array<string, list<int>> $seen
 * @return array<string, list<int>>
 */
function repeated( array $seen ): array {
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

/**
 * One pass over the catalogue, answering all three questions.
 *
 * Quoted fragments are concatenated before the key is built, so the same
 * msgid wrapped by `msgmerge` on one side and carried on a single line by a
 * branch compares equal. That is the half of this a naive key comparison
 * misses; the other half is that entries are closed by the next `msgid`
 * rather than by a blank line, so two entries glued into one block are
 * still two entries here.
 *
 * @return array{
 *     live: array<string, list<int>>,
 *     obsolete: array<string, list<int>>,
 *     glue: list<array{line:int, text:string}>
 * }
 */
function scan( string $source ): array {
    $seen = [ 'live' => [], 'obsolete' => [] ];
    $glue = [];

    $lines   = preg_split( '/\R/', $source ) ?: [];
    $ctx     = '';
    $msgid   = null;
    $line_no = 0;
    $started = 0;
    $space   = 'live';

    $flush = static function () use ( &$seen, &$ctx, &$msgid, &$started, &$space ): void {
        if ( $msgid === null ) return;
        $seen[ $space ][ $ctx . "\x04" . $msgid ][] = $started;
        $msgid = null;
        $ctx   = '';
    };

    // Which multi-line string we are currently accumulating into.
    $accumulating = null; // 'ctx' | 'id' | null

    // True while the previous meaningful line was a msgstr or one of its
    // continuations — the only place glue can appear.
    $after_msgstr = false;

    foreach ( $lines as $raw ) {
        $line_no++;
        $line = trim( $raw );

        // Obsolete lines are the same grammar behind a `#~ ` prefix. Strip
        // it and remember which namespace we are in, rather than treating
        // the whole block as an opaque comment.
        $obsolete = false;
        if ( strncmp( $line, '#~', 2 ) === 0 ) {
            $obsolete = true;
            $line     = ltrim( substr( $line, 2 ) );
        }
        $namespace = $obsolete ? 'obsolete' : 'live';

        if ( $line === '' ) {
            $accumulating = null;
            $after_msgstr = false;
            continue;
        }

        $starts_entry = strncmp( $line, 'msgctxt ', 8 ) === 0
            || strncmp( $line, 'msgid ', 6 ) === 0
            || strncmp( $line, '#:', 2 ) === 0
            || strncmp( $line, '#.', 2 ) === 0
            || strncmp( $line, '#,', 2 ) === 0;

        if ( $starts_entry && $after_msgstr ) {
            $glue[] = [ 'line' => $line_no, 'text' => shorten( trim( $raw ), 70 ) ];
        }

        // A non-obsolete comment that is not an entry header ends the run.
        if ( ! $obsolete && $line[0] === '#' ) {
            $accumulating = null;
            $after_msgstr = false;
            continue;
        }
        if ( $obsolete && $line !== '' && $line[0] === '#' ) {
            $accumulating = null;
            $after_msgstr = false;
            continue;
        }

        if ( strncmp( $line, 'msgctxt ', 8 ) === 0 ) {
            $flush();
            $space        = $namespace;
            $ctx          = unquote( substr( $line, 8 ) );
            $accumulating = 'ctx';
            $after_msgstr = false;
            continue;
        }

        if ( strncmp( $line, 'msgid ', 6 ) === 0 ) {
            // A msgid closes the previous entry — catalogues in the wild are
            // not reliably blank-line separated, and `msgmerge` output is not
            // the only thing that writes this file.
            $flush();
            $space        = $namespace;
            $msgid        = unquote( substr( $line, 6 ) );
            $started      = $line_no;
            $accumulating = 'id';
            $after_msgstr = false;
            continue;
        }

        // `msgid_plural` continues its own entry and `msgstr[n]` is its
        // translation; neither is a new entry, so neither is glue.
        if ( strncmp( $line, 'msgid_plural ', 13 ) === 0 ) {
            $accumulating = null;
            $after_msgstr = false;
            continue;
        }

        if ( strncmp( $line, 'msgstr', 6 ) === 0 ) {
            $accumulating = null;
            $after_msgstr = true;
            continue;
        }

        // A bare "…" line continues whatever came before it.
        if ( $line[0] === '"' ) {
            if ( $accumulating === 'ctx' ) {
                $ctx .= unquote( $line );
            } elseif ( $accumulating === 'id' ) {
                $msgid .= unquote( $line );
            }
            // A continuation of a msgstr keeps the glue window open; one of
            // a msgid does not, because a msgid is not somewhere an entry
            // can be appended onto.
            continue;
        }

        $after_msgstr = false;
    }
    $flush();

    return [
        'live'     => $seen['live'],
        'obsolete' => $seen['obsolete'],
        'glue'     => $glue,
    ];
}

/**
 * Keys duplicated more times here than in the base, or not duplicated
 * there at all.
 *
 * @param array<string, list<int>> $current
 * @param array<string, list<int>> $base
 * @return array<string, list<int>>
 */
function introduced_since( array $current, array $base ): array {
    $out = [];
    foreach ( $current as $key => $lines ) {
        $was = $base[ $key ] ?? [];
        if ( count( $lines ) > max( 2, count( $was ) ) || ! $was ) {
            $out[ $key ] = $lines;
        }
    }

    return $out;
}

/** @param array<string, list<int>> $keys */
function report_keys( array $keys ): void {
    foreach ( $keys as $key => $lines ) {
        [ $ctx, $msgid ] = explode( "\x04", $key, 2 );
        fwrite( STDERR, sprintf(
            "  lines %s%s\n    msgid \"%s\"\n",
            implode( ', ', $lines ),
            $ctx === '' ? '' : "  [msgctxt \"{$ctx}\"]",
            shorten( $msgid )
        ) );
    }
    fwrite( STDERR, "\n" );
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
