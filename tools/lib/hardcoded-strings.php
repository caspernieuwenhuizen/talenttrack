<?php
/**
 * Rules for the hard-coded user-facing string gate (#4509).
 *
 * Each rule judges one line, so the gate can run over a PR's added lines
 * only and grandfather everything already on main. Three rules:
 *
 *   js    a letter-bearing literal written to textContent / innerText /
 *         placeholder / title / ariaLabel, or passed to setAttribute() for
 *         aria-label / title / placeholder;
 *   php   echo / print / printf / esc_html() / esc_attr() of a literal with
 *         letters left once markup and placeholders are stripped;
 *   dutch a talenttrack msgid that reads as Dutch (two or more Dutch
 *         function words). Msgids are English; the Dutch goes in the PR's
 *         languages/pending fragment.
 *
 * A trailing `tt-i18n-ok` marker on the line exempts it (data, not copy).
 */

declare( strict_types = 1 );

require_once __DIR__ . '/gettext-calls.php';

const TT_HS_MARKER = 'tt-i18n-ok';

/**
 * Dutch function words that are not also English words ("is" and "met"
 * are left out: "it is" alone would flag half the English catalogue).
 * A msgid scores one point per distinct word from this list, and one more
 * for any word spelled with "ij" or "aa", which Dutch uses constantly and
 * English hardly at all. Two points mark it as Dutch, so a short notice
 * like "Je doel is bijgewerkt" (je + bijgewerkt) is still caught.
 */
const TT_HS_DUTCH_WORDS = [
    'de', 'het', 'een', 'je', 'jouw', 'jij', 'uw', 'van', 'voor', 'niet',
    'zijn', 'wordt', 'worden', 'naar', 'ook', 'geen', 'deze', 'dit', 'dat',
    'aan', 'bij', 'nog', 'maar', 'om', 'te', 'er', 'hoe', 'wat',
    'gaat', 'heeft', 'hebben', 'kan', 'moet', 'mijn', 'ons', 'onze', 'wij',
    'jullie', 'graag', 'alle', 'wel', 'op',
];

/**
 * Whether the text left in a literal is copy a reader would see: at least
 * one letter after markup, entities, printf placeholders and template
 * interpolations are gone.
 */
function tt_hs_has_copy( string $literal ): bool {
    $text = preg_replace( '/\$\{[^}]*\}/', ' ', $literal ) ?? $literal;
    $text = strip_tags( $text );
    $text = preg_replace( '/&[a-z0-9#]+;/i', ' ', $text ) ?? $text;
    $text = preg_replace( '/%(\d+\$)?[-+ 0#]*\d*(\.\d+)?[bcdeEfFgGosuxX]/', ' ', $text ) ?? $text;
    return preg_match( '/\p{L}/u', $text ) === 1;
}

/**
 * The first string literal that starts at $offset in $line, unquoted
 * (quotes and escapes resolved loosely), or null when none starts there.
 */
function tt_hs_literal_at( string $line, int $offset ): ?string {
    if ( ! isset( $line[ $offset ] ) ) return null;
    $quote = $line[ $offset ];
    if ( $quote !== "'" && $quote !== '"' && $quote !== '`' ) return null;

    $out = '';
    $len = strlen( $line );
    for ( $i = $offset + 1; $i < $len; $i++ ) {
        $c = $line[ $i ];
        if ( $c === '\\' && $i + 1 < $len ) {
            $out .= $line[ ++$i ];
            continue;
        }
        if ( $c === $quote ) return $out;
        $out .= $c;
    }
    return $out;
}

/**
 * JS rule violations on one line.
 *
 * @return list<string>
 */
function tt_hs_js_line( string $line ): array {
    if ( strpos( $line, TT_HS_MARKER ) !== false ) return [];

    $found   = [];
    $targets = [];

    if ( preg_match_all( '/\.(textContent|innerText|placeholder|title|ariaLabel)\s*=(?!=)\s*/', $line, $m, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $m[0] as $i => $match ) {
            $targets[] = [ $m[1][ $i ][0], $match[1] + strlen( $match[0] ) ];
        }
    }
    if ( preg_match_all( '/setAttribute\(\s*([\'"])(aria-label|title|placeholder)\1\s*,\s*/', $line, $m, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $m[0] as $i => $match ) {
            $targets[] = [ $m[2][ $i ][0], $match[1] + strlen( $match[0] ) ];
        }
    }

    foreach ( $targets as [ $property, $offset ] ) {
        $literal = tt_hs_literal_at( $line, $offset );
        if ( $literal !== null && tt_hs_has_copy( $literal ) ) {
            $found[] = sprintf( 'hard-coded text written to %s: "%s"', $property, $literal );
        }
    }
    return $found;
}

/**
 * PHP output rule violations on one line.
 *
 * @return list<string>
 */
function tt_hs_php_line( string $line ): array {
    if ( strpos( $line, TT_HS_MARKER ) !== false ) return [];

    $found = [];
    $pattern = '/(?<![\w$>:])(echo|print|printf|esc_html|esc_attr)\b\s*\(?\s*(?=[\'"])/';
    if ( preg_match_all( $pattern, $line, $m, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $m[0] as $i => $match ) {
            $literal = tt_hs_literal_at( $line, $match[1] + strlen( $match[0] ) );
            if ( $literal !== null && tt_hs_has_copy( $literal ) ) {
                $found[] = sprintf( 'untranslated literal passed to %s: "%s"', $m[1][ $i ][0], $literal );
            }
        }
    }
    return $found;
}

/** Whether a msgid reads as Dutch. */
function tt_hs_is_dutch( string $msgid ): bool {
    $text  = preg_replace( '/\{[^}]*\}|%(\d+\$)?[a-z]/i', ' ', $msgid ) ?? $msgid;
    $words = array_unique( preg_split( '/[^\p{L}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] );
    $score = count( array_intersect( $words, TT_HS_DUTCH_WORDS ) );
    foreach ( $words as $word ) {
        if ( strpos( $word, 'ij' ) !== false || strpos( $word, 'aa' ) !== false ) {
            $score++;
            break;
        }
    }
    return $score >= 2;
}

/**
 * Every violation in one file's source, keyed by line number. With
 * $only_lines set, lines outside it are skipped (the PR's added lines).
 *
 * @param array<int, true>|null $only_lines
 * @return array<int, list<string>>
 */
function tt_hs_file_violations( string $relative, string $source, ?array $only_lines = null ): array {
    $is_js  = substr( $relative, -3 ) === '.js';
    $lines  = preg_split( '/\R/', $source ) ?: [];
    $out    = [];

    foreach ( $lines as $index => $line ) {
        $number = $index + 1;
        if ( $only_lines !== null && ! isset( $only_lines[ $number ] ) ) continue;
        $hits = $is_js ? tt_hs_js_line( $line ) : tt_hs_php_line( $line );
        if ( $hits ) $out[ $number ] = $hits;
    }

    if ( ! $is_js ) {
        foreach ( tt_gettext_calls( $source ) as $call ) {
            $number = (int) $call['line'];
            if ( $only_lines !== null && ! isset( $only_lines[ $number ] ) ) continue;
            if ( strpos( $lines[ $number - 1 ] ?? '', TT_HS_MARKER ) !== false ) continue;
            if ( tt_hs_is_dutch( (string) $call['msgid'] ) ) {
                $out[ $number ][] = sprintf(
                    'msgid reads as Dutch: "%s". Msgids are English; put the Dutch in languages/pending/<issue>-<slug>.po',
                    $call['msgid']
                );
            }
        }
        ksort( $out );
    }
    return $out;
}

/** Whether a path is in the gate's scope. */
function tt_hs_in_scope( string $relative ): bool {
    $relative = str_replace( '\\', '/', $relative );
    if ( strpos( $relative, 'src/' ) === 0 && substr( $relative, -4 ) === '.php' ) return true;
    if ( strpos( $relative, 'assets/js/' ) === 0 && substr( $relative, -3 ) === '.js' ) {
        return strpos( $relative, '/vendor/' ) === false && substr( $relative, -7 ) !== '.min.js';
    }
    return false;
}
