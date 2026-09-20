<?php
/**
 * Fold the per-PR translation fragments into the shipped catalogues.
 *
 * ## The problem this closes
 *
 * `languages/talenttrack-nl_NL.po` was the only file parallel branches
 * ever collided on. Not because the work overlapped — it never did — but
 * because every branch that adds a string appends to the same file, and
 * the catalogue regeneration relocates those appends on `main`, so a
 * merge keeps both copies and `msgfmt` then refuses the file.
 *
 * So a PR stops touching the catalogue. It drops one brand-new file:
 *
 *     languages/pending/<issue>-<slug>.po
 *
 * carrying only the entries it introduces, with their translations. A
 * file that exists on exactly one branch cannot conflict. This tool is
 * what turns those fragments back into a catalogue, at release time,
 * once, with nothing else in flight — exactly the way `release.ps1`
 * already consolidates `changelog.d`.
 *
 * ## What it will not do
 *
 * It refuses rather than guesses, because every failure mode here is
 * quiet. It will not write when the result would carry a duplicate
 * `(msgctxt, msgid)` pair, when a fragment's entry collides with an
 * obsolete `#~` entry already in the catalogue (`msgmerge` promotes
 * those back to live, so the duplicate would arrive later in a commit
 * nobody wrote), or when two fragments disagree about the translation
 * of the same string.
 *
 * Entries are keyed the way gettext keys them: on the (msgctxt, msgid)
 * pair, with wrapped literals joined by CONTENT first — see tools/lib/po.php
 * for why that detail is the whole ballgame.
 *
 * ## Usage
 *
 *   php tools/consolidate-translations.php            # fold in + delete
 *   php tools/consolidate-translations.php --check    # validate only
 *   php tools/consolidate-translations.php --dry-run  # report, write nothing
 *   php tools/consolidate-translations.php --keep     # fold in, keep files
 *
 * Exit: 0 clean, 1 a fragment or the result is bad, 2 tooling error.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/lib/po.php';

$options = [
    'root'    => dirname( __DIR__ ),
    'dir'     => '',
    'check'   => false,
    'dry-run' => false,
    'keep'    => false,
];
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( preg_match( '/^--([a-z-]+)=(.*)$/', $arg, $m ) === 1 ) {
        $options[ $m[1] ] = $m[2];
        continue;
    }
    if ( preg_match( '/^--([a-z-]+)$/', $arg, $m ) === 1 ) {
        $options[ $m[1] ] = true;
        continue;
    }
    fwrite( STDERR, "consolidate-translations: unrecognised argument {$arg}\n" );
    exit( 2 );
}

$root    = rtrim( str_replace( '\\', '/', (string) $options['root'] ), '/' );
$pending = (string) $options['dir'] !== ''
    ? rtrim( str_replace( '\\', '/', (string) $options['dir'] ), '/' )
    : $root . '/languages/pending';

$default_locale = 'nl_NL';

if ( ! is_dir( $pending ) ) {
    printf( "consolidate-translations: no %s directory — nothing to consolidate.\n", $pending );
    exit( 0 );
}

$fragments = glob( $pending . '/*.po' ) ?: [];
sort( $fragments );

if ( ! $fragments ) {
    printf( "consolidate-translations: %s is empty — nothing to consolidate.\n", $pending );
    exit( 0 );
}

$errors = [];

/**
 * Read every fragment, validate its shape, and group its entries by the
 * catalogue they belong in.
 *
 * @var array<string, array<string, array{entry:array, file:string}>> $by_locale
 */
$by_locale = [];

foreach ( $fragments as $path ) {
    $name = basename( $path );

    if ( preg_match( '/^(\d+)-[a-z0-9][a-z0-9-]*(?:\.([a-z]{2}_[A-Z]{2}))?\.po$/', $name, $m ) !== 1 ) {
        $errors[] = sprintf(
            "%s: the filename must be <issue>-<slug>.po, or <issue>-<slug>.<locale>.po for a\n"
            . "    catalogue other than %s — e.g. 3863-translation-fragments.po.",
            $name,
            $default_locale
        );
        continue;
    }

    $source = @file_get_contents( $path );
    if ( $source === false ) {
        fwrite( STDERR, "consolidate-translations: cannot read {$path}\n" );
        exit( 2 );
    }

    $parsed = tt_po_parse( $source );
    $locale = $m[2] ?? '';
    if ( $locale === '' && $parsed['header'] !== null ) {
        // A fragment may name its locale in a gettext header instead of in
        // the filename; both spellings are fine, neither is required.
        if ( preg_match( '/Language:\s*([a-z]{2}_[A-Z]{2})/', (string) ( $parsed['header']['msgstr'][0] ?? '' ), $h ) === 1 ) {
            $locale = $h[1];
        }
    }
    if ( $locale === '' ) {
        $locale = $default_locale;
    }

    $catalogue = $root . '/languages/talenttrack-' . $locale . '.po';
    if ( ! is_file( $catalogue ) ) {
        $errors[] = sprintf( '%s: no catalogue at languages/talenttrack-%s.po.', $name, $locale );
        continue;
    }

    $seen = [];
    $kept = 0;
    foreach ( $parsed['entries'] as $entry ) {
        if ( $entry['header'] || ! empty( $entry['orphan'] ) ) {
            continue;
        }
        if ( $entry['obsolete'] ) {
            $errors[] = sprintf(
                '%s: carries an obsolete `#~` entry (%s). A fragment states what this PR adds; '
                . 'obsoleting is the catalogue regeneration\'s job.',
                $name,
                tt_po_label( $entry )
            );
            continue;
        }
        if ( tt_po_is_untranslated( $entry ) ) {
            $errors[] = sprintf(
                '%s: %s has an empty msgstr. A fragment exists to carry the translation; '
                . 'an empty one would be consolidated into the catalogue as untranslated.',
                $name,
                tt_po_label( $entry )
            );
            continue;
        }
        if ( isset( $seen[ $entry['key'] ] ) ) {
            $errors[] = sprintf(
                '%s: %s appears twice in the same fragment. msgfmt refuses a duplicate msgid.',
                $name,
                tt_po_label( $entry )
            );
            continue;
        }
        $seen[ $entry['key'] ] = true;
        $kept++;

        $existing = $by_locale[ $locale ][ $entry['key'] ] ?? null;
        if ( $existing !== null ) {
            if ( tt_po_msgstr_signature( $existing['entry'] ) !== tt_po_msgstr_signature( $entry ) ) {
                $errors[] = sprintf(
                    "%s and %s both translate %s, differently.\n"
                    . '    Agree on one wording and keep it in a single fragment.',
                    $existing['file'],
                    $name,
                    tt_po_label( $entry )
                );
            }
            // Same wording twice is not a problem: two PRs reached for the
            // same string. The first copy is kept.
            continue;
        }

        $by_locale[ $locale ][ $entry['key'] ] = [ 'entry' => $entry, 'file' => $name ];
    }

    if ( $kept === 0 && ! $errors ) {
        printf( "  %s: no entries — nothing to fold in.\n", $name );
    }
}

if ( $errors ) {
    fail( $errors );
}

// --- fold each locale's entries into its catalogue -------------------------

$summary = [];

foreach ( $by_locale as $locale => $entries ) {
    $relative  = 'languages/talenttrack-' . $locale . '.po';
    $catalogue = $root . '/' . $relative;
    $source    = (string) file_get_contents( $catalogue );
    $parsed    = tt_po_parse( $source );
    $live      = tt_po_index( $parsed );
    $obsolete  = tt_po_index( $parsed, true );

    $added    = [];
    $filled   = [];
    $retyped  = [];
    $present  = [];
    $collides = [];

    foreach ( $entries as $key => $carried ) {
        $entry = $carried['entry'];

        if ( isset( $obsolete[ $key ] ) && ! isset( $live[ $key ] ) ) {
            // Not a cosmetic clash. `msgmerge` promotes an obsolete entry
            // back to live the moment its string reappears in the `.pot`,
            // so appending a live copy now means two live copies later, in
            // a commit nobody wrote by hand, breaking the compile for every
            // locale.
            $collides[] = tt_po_label( $entry );
            continue;
        }

        if ( isset( $live[ $key ] ) ) {
            $position = $live[ $key ][0];
            $target   = $parsed['entries'][ $position ];

            if ( tt_po_is_untranslated( $target ) ) {
                $parsed['entries'][ $position ]['lines'] = preg_split(
                    '/\R/',
                    tt_po_with_msgstr_of( $target, $entry )
                ) ?: [];
                $parsed['entries'][ $position ]['msgstr'] = $entry['msgstr'];
                $filled[] = tt_po_label( $entry );
                continue;
            }

            if ( tt_po_msgstr_signature( $target ) === tt_po_msgstr_signature( $entry ) ) {
                $present[] = tt_po_label( $entry );
                continue;
            }

            // The catalogue and the fragment disagree. The fragment is the
            // newer, deliberate statement — a PR author wrote it this
            // release — so it wins, loudly, and the release diff shows it.
            $parsed['entries'][ $position ]['lines'] = preg_split(
                '/\R/',
                tt_po_with_msgstr_of( $target, $entry )
            ) ?: [];
            $parsed['entries'][ $position ]['msgstr'] = $entry['msgstr'];
            $retyped[] = tt_po_label( $entry );
            continue;
        }

        $added[] = $entry;
    }

    if ( $collides ) {
        $errors[] = sprintf(
            "%s: %d fragment entr%s collide%s with an obsolete `#~` entry already in the catalogue.\n"
            . "    An obsolete entry is not inert — msgmerge promotes it back to live when the\n"
            . "    string reappears, so adding a live copy now produces two live copies later.\n"
            . "    Delete the `#~` block for these strings, then run this again:\n      %s",
            $relative,
            count( $collides ),
            count( $collides ) === 1 ? 'y' : 'ies',
            count( $collides ) === 1 ? 's' : '',
            implode( "\n      ", $collides )
        );
        continue;
    }

    $written = tt_consolidated_text( $source, $parsed, $added );

    // Never trust the splice: re-read the result and refuse a catalogue
    // that would not compile. This is the check that would have caught the
    // duplicates the 2026-09-20 repair introduced.
    $verify     = tt_po_parse( $written );
    $duplicates = [];
    foreach ( tt_po_index( $verify ) as $key => $positions ) {
        if ( count( $positions ) > 1 ) {
            [ $ctx, $msgid ] = tt_po_unkey( $key );
            $duplicates[]    = sprintf( '"%s"%s', $msgid, $ctx === '' ? '' : " [msgctxt \"{$ctx}\"]" );
        }
    }
    if ( $duplicates ) {
        $errors[] = sprintf(
            "%s: the consolidated catalogue would carry %d duplicate msgid%s. Nothing was written.\n      %s",
            $relative,
            count( $duplicates ),
            count( $duplicates ) === 1 ? '' : 's',
            implode( "\n      ", $duplicates )
        );
        continue;
    }

    $summary[ $relative ] = [
        'text'    => $written,
        'added'   => count( $added ),
        'filled'  => count( $filled ),
        'retyped' => $retyped,
        'present' => count( $present ),
    ];
}

if ( $errors ) {
    fail( $errors );
}

if ( $options['check'] === true ) {
    printf(
        "consolidate-translations OK — %d fragment(s) in %s validate against the catalogues.\n",
        count( $fragments ),
        str_replace( $root . '/', '', $pending )
    );
    exit( 0 );
}

foreach ( $summary as $relative => $result ) {
    if ( $options['dry-run'] !== true ) {
        file_put_contents( $root . '/' . $relative, $result['text'] );
    }
    printf(
        "  %s: %d added, %d translated in place, %d already present.\n",
        $relative,
        $result['added'],
        $result['filled'],
        $result['present']
    );
    foreach ( $result['retyped'] as $label ) {
        printf( "    retranslated (fragment wins over the catalogue): %s\n", $label );
    }
}

$consumed = 0;
if ( $options['dry-run'] !== true && $options['keep'] !== true ) {
    foreach ( $fragments as $path ) {
        if ( @unlink( $path ) ) {
            $consumed++;
        }
    }
}

printf(
    "consolidate-translations: %d fragment(s) %s.\n",
    count( $fragments ),
    $options['dry-run'] === true
        ? 'validated (dry run — nothing written)'
        : ( $options['keep'] === true ? 'folded in and kept' : sprintf( 'folded in, %d consumed', $consumed ) )
);

exit( 0 );

/**
 * The catalogue text with the entries spliced back in.
 *
 * New entries land at the end of the LIVE section — above the obsolete
 * `#~` block, never below it. An entry written below that block reads as
 * obsolete to every tool in the chain, which is how strings go missing
 * from a gate's count and from the compiled `.mo` at the same time.
 *
 * @param list<array> $added
 */
function tt_consolidated_text( string $source, array $parsed, array $added ): string {
    $blocks = [];
    $tail   = [];

    foreach ( $parsed['entries'] as $entry ) {
        $block = tt_po_block( $entry );
        if ( $entry['obsolete'] ) {
            $tail[] = $block;
        } else {
            $blocks[] = $block;
        }
    }

    foreach ( $added as $entry ) {
        $blocks[] = tt_po_block( $entry );
    }

    // One blank line between every entry, always. Glued entries are the
    // fingerprint of a union merge and the reason block readers lie.
    $text = implode( "\n\n", array_merge( $blocks, $tail ) );

    return rtrim( $text, "\n" ) . "\n";
}

/** @param list<string> $errors */
function fail( array $errors ): void {
    fwrite( STDERR, sprintf(
        "\nconsolidate-translations FAILED — %d problem%s. Nothing was written.\n\n",
        count( $errors ),
        count( $errors ) === 1 ? '' : 's'
    ) );
    foreach ( $errors as $message ) {
        fwrite( STDERR, '  - ' . $message . "\n" );
    }
    fwrite( STDERR, "\nSee languages/pending/README.md.\n\n" );
    exit( 1 );
}
