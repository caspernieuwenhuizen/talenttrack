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
            ' vs ', ' vs.', '-vs-', 'thuis', 'uit ',
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

    public static function classify( string $summary, string $description = '' ): string {
        $haystack = strtolower( $summary . ' ' . $description );

        foreach ( self::KEYWORDS as $type => $needles ) {
            foreach ( $needles as $needle ) {
                if ( strpos( $haystack, $needle ) !== false ) {
                    return (string) apply_filters( 'tt_spond_classify_event', $type, $summary, $description );
                }
            }
        }

        return (string) apply_filters( 'tt_spond_classify_event', 'training', $summary, $description );
    }
}
