<?php
/**
 * One-shot tool: generate empty .po skeletons for new locales by
 * stripping all `msgstr` values from `talenttrack-nl_NL.po`.
 *
 * Usage:
 *     php tools/generate-locale-skeletons.php
 *
 * Why nl_NL as the source instead of `talenttrack.pot`? The POT is
 * historically stale (~246 msgids) while `nl_NL.po` carries the
 * full ~1000+ msgid set that has accumulated through every ship
 * since v2.4.0. Using nl_NL as the seed gives FR/DE/ES translators
 * the actual current msgid list to work against. POT regeneration
 * via `wp i18n make-pot` lands as a separate ship-along step (per
 * DEVOPS.md release-checklist update in this same PR).
 *
 * Per WordPress runtime behaviour, an empty `msgstr ""` falls back
 * to the English `msgid` — so these skeleton files render the UI
 * in English for FR/DE/ES users until translators fill them in.
 *
 * #0010 Phase 1 — code-side prep. Translation labor is a calendar-
 * time deliverable that runs against these skeletons.
 */

declare( strict_types = 1 );

$repo_root = dirname( __DIR__ );
$source    = $repo_root . '/languages/talenttrack-nl_NL.po';

if ( ! is_readable( $source ) ) {
    fwrite( STDERR, "ERROR: $source not found\n" );
    exit( 1 );
}

$locales = [
    'fr_FR' => [ 'team' => 'Français', 'plural' => 'nplurals=2; plural=(n > 1);' ],
    'de_DE' => [ 'team' => 'Deutsch',  'plural' => 'nplurals=2; plural=(n != 1);' ],
    'es_ES' => [ 'team' => 'Español',  'plural' => 'nplurals=2; plural=(n != 1);' ],
];

$lines = file( $source, FILE_IGNORE_NEW_LINES );
if ( $lines === false ) {
    fwrite( STDERR, "ERROR: cannot read $source\n" );
    exit( 1 );
}

// Locate the header block — runs from the first `msgid ""` line
// through the first blank line. Everything after is the body.
$header_end = null;
$in_header  = false;
foreach ( $lines as $idx => $line ) {
    if ( ! $in_header && $line === 'msgid ""' ) {
        $in_header = true;
        continue;
    }
    if ( $in_header && $line === '' ) {
        $header_end = $idx;
        break;
    }
}
if ( $header_end === null ) {
    fwrite( STDERR, "ERROR: could not locate header block in source .po\n" );
    exit( 1 );
}

$body_lines = array_slice( $lines, $header_end + 1 );

// Strip all `msgstr "..."` values in the body to empty `msgstr ""`,
// dropping any trailing `"…"` continuation lines that belong to the
// stripped msgstr.
$stripped = [];
$skip_continuation = false;
foreach ( $body_lines as $line ) {
    if ( $skip_continuation ) {
        if ( preg_match( '/^"[^"]*"\s*$/', $line ) ) continue;
        $skip_continuation = false;
    }
    if ( preg_match( '/^msgstr\s+"/', $line ) && ! preg_match( '/^msgstr ""\s*$/', $line ) ) {
        $stripped[]        = 'msgstr ""';
        $skip_continuation = true;
        continue;
    }
    $stripped[] = $line;
}

foreach ( $locales as $locale => $meta ) {
    $header = [
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: TalentTrack\n"',
        '"Report-Msgid-Bugs-To: \n"',
        '"POT-Creation-Date: 2026-05-09 00:00+0000\n"',
        '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\n"',
        '"Last-Translator: \n"',
        sprintf( '"Language-Team: %s\n"', $meta['team'] ),
        sprintf( '"Language: %s\n"', $locale ),
        '"MIME-Version: 1.0\n"',
        '"Content-Type: text/plain; charset=UTF-8\n"',
        '"Content-Transfer-Encoding: 8bit\n"',
        sprintf( '"Plural-Forms: %s\n"', $meta['plural'] ),
    ];
    $combined = array_merge( $header, [ '' ], $stripped );
    $target   = $repo_root . '/languages/talenttrack-' . $locale . '.po';
    if ( file_put_contents( $target, implode( "\n", $combined ) ) === false ) {
        fwrite( STDERR, "ERROR: cannot write $target\n" );
        exit( 1 );
    }
    $count = 0;
    foreach ( $stripped as $l ) if ( strpos( $l, 'msgid "' ) === 0 ) $count++;
    fwrite( STDOUT, sprintf( "Wrote %s (%d msgids, all msgstr empty)\n", $target, $count ) );
}

fwrite( STDOUT, "\nDone. Compile to .mo via msgfmt or wp-cli before shipping.\n" );
