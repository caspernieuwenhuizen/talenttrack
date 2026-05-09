<?php
/**
 * apply-translations.php — apply an inline EN→target-locale dictionary
 * to a `.po` file, filling in `msgstr` values for matching msgids.
 *
 * Usage:
 *     php tools/apply-translations.php <locale>
 *
 * Where <locale> is one of `fr_FR`, `de_DE`, `es_ES`. The tool loads
 * `tools/translations-<locale>.php` (which returns an associative
 * `[ msgid => msgstr ]` array), opens `languages/talenttrack-<locale>.po`,
 * and replaces empty msgstrs whose preceding msgid is a key in the
 * dictionary. msgstrs that already have a value are left untouched
 * — operator edits via the Translations admin won't be overwritten.
 *
 * Idempotent: re-running with the same dictionary against an already-
 * filled `.po` is a no-op for those entries.
 *
 * Strategy per #0010: this tool is the "machine translation as first
 * draft" stage. The translation table embedded in
 * `tools/translations-<locale>.php` is hand-curated by an LLM
 * (Claude) for the ~150 highest-frequency UI labels per language.
 * Native-speaker review remains a calendar-time follow-up that
 * extends the dictionary in subsequent PRs.
 */

declare( strict_types = 1 );

if ( $argc < 2 ) {
    fwrite( STDERR, "Usage: php tools/apply-translations.php <locale>\n" );
    fwrite( STDERR, "  <locale>: fr_FR | de_DE | es_ES\n" );
    exit( 1 );
}
$locale = (string) $argv[1];
if ( ! in_array( $locale, [ 'fr_FR', 'de_DE', 'es_ES' ], true ) ) {
    fwrite( STDERR, "Unsupported locale: $locale\n" );
    exit( 1 );
}

$repo = dirname( __DIR__ );
$dict = $repo . '/tools/translations-' . $locale . '.php';
$po   = $repo . '/languages/talenttrack-' . $locale . '.po';

if ( ! is_readable( $dict ) ) {
    fwrite( STDERR, "Dictionary file not found: $dict\n" );
    exit( 1 );
}
if ( ! is_readable( $po ) ) {
    fwrite( STDERR, "Target .po not found: $po\n" );
    exit( 1 );
}

$translations = require $dict;
if ( ! is_array( $translations ) ) {
    fwrite( STDERR, "Dictionary must return an associative array.\n" );
    exit( 1 );
}

$lines = file( $po, FILE_IGNORE_NEW_LINES );
if ( $lines === false ) {
    fwrite( STDERR, "Cannot read $po\n" );
    exit( 1 );
}

$out          = [];
$pending_msgid = null;
$applied       = 0;
$skipped_full  = 0;
$skipped_miss  = 0;
foreach ( $lines as $idx => $line ) {
    if ( preg_match( '/^msgid "(.*)"$/', $line, $m ) ) {
        $pending_msgid = stripcslashes( $m[1] );
        $out[]         = $line;
        continue;
    }
    if ( $pending_msgid !== null && preg_match( '/^msgstr "(.*)"$/', $line, $m ) ) {
        $existing = stripcslashes( $m[1] );
        if ( $existing !== '' ) {
            // Already translated — preserve.
            $out[] = $line;
            $skipped_full++;
            $pending_msgid = null;
            continue;
        }
        if ( isset( $translations[ $pending_msgid ] ) ) {
            $tx       = (string) $translations[ $pending_msgid ];
            // Escape backslashes + double-quotes for .po format.
            $escaped  = addcslashes( $tx, "\\\"" );
            $out[]    = 'msgstr "' . $escaped . '"';
            $applied++;
        } else {
            // No dictionary entry; leave empty (English fallback).
            $out[] = $line;
            $skipped_miss++;
        }
        $pending_msgid = null;
        continue;
    }
    $out[] = $line;
}

$result = implode( "\n", $out );
if ( substr( $lines[ count( $lines ) - 1 ] ?? '', -1 ) !== "\n" ) {
    // file() strips trailing newline; restore.
    $result .= "\n";
}
if ( file_put_contents( $po, $result ) === false ) {
    fwrite( STDERR, "Cannot write $po\n" );
    exit( 1 );
}

printf(
    "[%s] applied %d translations, skipped %d (already filled), %d (no dictionary entry)\n",
    $locale,
    $applied,
    $skipped_full,
    $skipped_miss
);
