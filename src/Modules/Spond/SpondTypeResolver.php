<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondTypeResolver (#0031) — case-insensitive title-keyword classifier.
 *
 * Maps a Spond event summary to one of the seeded `activity_type`
 * lookup names (training / game / tournament / meeting / other),
 * falling back to `training` for ambiguous titles. Built-in keyword
 * lists cover NL/EN/DE/UK; clubs that need a custom rule override via
 * the `tt_spond_classify_event` filter.
 */
final class SpondTypeResolver {

    private const KEYWORDS = [
        'game' => [
            'match', 'wedstrijd', 'kamp', 'spiel', 'partita', 'partido',
            ' vs ', ' vs.', '-vs-', 'thuis', 'uit',
        ],
        'tournament' => [
            'tournament', 'toernooi', 'turnier',
        ],
        'meeting' => [
            'meeting', 'bespreking', 'overleg', 'evaluatie', 'parents',
            'oudergesprek', 'besprechung',
        ],
        'training' => [
            'training', 'trainen', 'practice', 'oefening',
        ],
    ];

    /**
     * Needles that only count as a whole word.
     *
     * `kamp` is Norwegian/Danish for *match* and earns its place — Spond is
     * a Norwegian product — but in Dutch `-kamp` is the ordinary suffix for
     * *camp*, so as a bare substring it turned "Trainingskamp" into a
     * fixture. `uit` ("away") has the same shape inside "vooruit". Both are
     * matched on a word boundary instead; every other needle stays a
     * substring so compounds that really are games ("thuiswedstrijd",
     * "trainingswedstrijd") keep classifying as one.
     */
    private const WHOLE_WORD_NEEDLES = [ 'kamp', 'uit' ];

    public static function classify( string $summary, string $description = '' ): string {
        $haystack = strtolower( $summary . ' ' . $description );

        foreach ( self::KEYWORDS as $type => $needles ) {
            foreach ( $needles as $needle ) {
                if ( self::matches( $haystack, $needle ) ) {
                    return (string) apply_filters( 'tt_spond_classify_event', $type, $summary, $description );
                }
            }
        }

        return (string) apply_filters( 'tt_spond_classify_event', 'training', $summary, $description );
    }

    private static function matches( string $haystack, string $needle ): bool {
        if ( ! in_array( $needle, self::WHOLE_WORD_NEEDLES, true ) ) {
            return strpos( $haystack, $needle ) !== false;
        }

        return preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/u', $haystack ) === 1;
    }
}
