<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondTypeResolver (#0031) — case-insensitive keyword classifier.
 *
 * Maps a Spond event to one of the seeded `activity_type` lookup names
 * (training / game / tournament / meeting / other), falling back to
 * `training` for ambiguous events. Built-in keyword lists cover
 * NL/EN/DE/UK; clubs that need a custom rule override via the
 * `tt_spond_classify_event` filter.
 *
 * ## The title decides; the description is a fallback (#3923)
 *
 * Summary and description used to be concatenated into one haystack and
 * searched with equal weight, so a training whose description mentioned
 * the weekend's fixture imported as a fixture. "Training JO14-1" with
 * the note *"laatste training voor de wedstrijd van zaterdag"* landed as
 * a `game`: a match roster was expected, the minutes grid opened against
 * it, and the team record and minutes audit both counted it. The type is
 * written at import, so renaming the activity afterwards does not revise
 * it — and a coach writing a sentence about the weekend is doing nothing
 * unusual.
 *
 * The summary is what the organiser chose to call the event, so it is
 * decided on alone. The description is consulted only when the summary
 * says nothing about the type at all — for an event titled "JO14-1"
 * whose body reads "wedstrijd tegen Ajax", reading the body beats
 * falling through to the default.
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

    /**
     * ## Spond's own match flag comes first (#4164)
     *
     * An event created as a match in Spond carries `matchEvent: true`, and
     * its heading is usually just the two team names — no keyword for the
     * scan below to find, so it fell through to `training`. When Spond says
     * it is a match, it is a `game`, unless the title names a tournament.
     * The keyword scan is only for events Spond does not flag.
     */
    public static function classify( string $summary, string $description = '', bool $spond_match = false ): string {
        if ( $spond_match ) {
            $type = self::firstMatch( $summary ) === 'tournament' ? 'tournament' : 'game';
        } else {
            $type = self::firstMatch( $summary );
            if ( $type === null ) {
                $type = self::firstMatch( $description );
            }
        }

        return (string) apply_filters( 'tt_spond_classify_event', $type ?? 'training', $summary, $description, $spond_match );
    }

    /**
     * The type an already-imported activity should move to on re-sync, or
     * null to leave it alone (#4164).
     *
     * The type is TalentTrack-owned after the first import, with one
     * exception: a row still at `training` whose event Spond flags as a
     * match. That is the classifier's old fallback, not a coach's choice —
     * nobody turns a match into a training on purpose — so it is corrected.
     * Any other stored type, including `other` or `meeting`, stays.
     */
    public static function correctedType( string $stored_type, string $summary, bool $spond_match ): ?string {
        if ( ! $spond_match || $stored_type !== 'training' ) return null;

        return self::classify( $summary, '', true );
    }

    /**
     * The first type whose keyword list this one field answers to, or
     * null when the field says nothing about the type.
     *
     * The list order is load-bearing and must not be rearranged: `game`
     * is scanned before `training` because "Trainingswedstrijd" is Dutch
     * for a friendly, and a friendly is a fixture.
     *
     * The field is padded with a space at each end so the needles that
     * carry their own leading space (` vs `) still fire at the very
     * start or end of it.
     */
    private static function firstMatch( string $field ): ?string {
        if ( trim( $field ) === '' ) return null;

        $haystack = ' ' . strtolower( $field ) . ' ';
        foreach ( self::KEYWORDS as $type => $needles ) {
            foreach ( $needles as $needle ) {
                if ( self::matches( $haystack, $needle ) ) return (string) $type;
            }
        }

        return null;
    }

    private static function matches( string $haystack, string $needle ): bool {
        if ( ! in_array( $needle, self::WHOLE_WORD_NEEDLES, true ) ) {
            return strpos( $haystack, $needle ) !== false;
        }

        return preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/u', $haystack ) === 1;
    }
}
