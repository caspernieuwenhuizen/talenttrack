<?php
namespace TT\Modules\Activities\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OpponentFromTitle (#3860) — read the opponent, and where possible
 * home/away, out of a match activity's title.
 *
 * `tt_activities.opponent` was written by nothing until #3530, and the
 * Spond importer still writes only the title, so every fixture created
 * before that date — and every imported one since — has an empty column
 * while the opponent the coach recognises sits in the title. Eight
 * surfaces read the column and degrade to a placeholder: the monthly
 * report prints "Unknown opponent" per fixture, the live scoreboard shows
 * `OPP`, the minutes grid `OPP.`.
 *
 * ## It guesses, and says how sure it is
 *
 * Titles are free-form. Spond summaries and hand-typed titles mix formats
 * inside one team, so there is no separator rule to lean on. This parser
 * therefore answers one of three ways:
 *
 *   - `null` — the title carries no signal at all. That is a first-class
 *     answer, not a failure: writing a guess into a column eight surfaces
 *     print is worse than leaving it empty, and the caller has to be able
 *     to act on "no idea" as cheaply as on a confident read.
 *   - a result with `confidence: 'high'` — the title names two sides and
 *     one of them is this team, so the other side is the opponent and its
 *     position gives home/away.
 *   - a result with `confidence: 'low'` — there is a signal (a separator,
 *     a "vs"/"tegen" keyword) but nothing identifies which side we are.
 *     The opponent is the caller's to confirm, and `home_away` is left
 *     empty rather than guessed.
 *
 * Nothing here writes. {@see \TT\Modules\Spond\SpondSync} uses it on
 * insert, and the backfill screen proposes its answers for confirmation
 * before a single row is touched.
 *
 * @phpstan-type Derived array{opponent:string, home_away:string, confidence:string}
 */
final class OpponentFromTitle {

    /** Sides in "A - B", "A – B", "A vs B", "A tegen B". */
    private const SEPARATORS = [ ' - ', ' – ', ' — ', ' vs. ', ' vs ', ' v ', ' tegen ' ];

    /** "vs Ajax", "tegen Ajax", "uit tegen Ajax", "wedstrijd tegen Ajax". */
    private const LEAD_KEYWORDS = [ 'tegen', 'vs.', 'vs', 'v', 'against' ];

    /** Words that mark the fixture as away / home when they stand alone. */
    private const AWAY_WORDS = [ 'uit', 'away', 'u' ];
    private const HOME_WORDS = [ 'thuis', 'home', 'h' ];

    /**
     * Noise that is about the fixture, not about who it is against: it is
     * stripped before anything else is read.
     */
    private const NOISE = [
        'wedstrijd', 'match', 'game', 'competitie', 'beker', 'cup', 'league',
        'oefenwedstrijd', 'friendly', 'vriendschappelijk', 'toernooi', 'tournament',
    ];

    /**
     * Derive the opponent from a title, or null when the title says
     * nothing about one.
     *
     * `$team_name` is what turns a guess into a read: when one side of the
     * title is this team, the other side is the opponent and the order
     * tells us home from away. Callers that know the team should always
     * pass it.
     *
     * @return Derived|null
     */
    public static function parse( string $title, string $team_name = '' ): ?array {
        $title = trim( preg_replace( '/\s+/u', ' ', $title ) ?? '' );
        if ( $title === '' ) return null;

        $home_away = '';
        $title     = self::stripBracketedVenue( $title, $home_away );

        $sides = self::split( $title );
        if ( $sides !== null ) {
            return self::fromSides( $sides[0], $sides[1], $team_name, $home_away );
        }

        $lead = self::fromLeadKeyword( $title );
        if ( $lead !== null ) {
            [ $opponent, $keyword_home_away ] = $lead;
            $opponent = self::clean( $opponent );
            if ( $opponent === '' ) return null;
            return [
                'opponent'   => $opponent,
                'home_away'  => $home_away !== '' ? $home_away : $keyword_home_away,
                // One named side and a keyword saying it is the other team:
                // as good as the two-sided read, without the cross-check.
                'confidence' => 'low',
            ];
        }

        // A bare title — "Zaterdag 14:00", "JO12-1", "Training" — says
        // nothing about an opponent. Answering "no idea" is the point.
        return null;
    }

    /**
     * A trailing or leading "(uit)" / "(thuis)" / "[away]" is about venue,
     * not about the opponent: it is removed and reported through
     * `$home_away`.
     */
    private static function stripBracketedVenue( string $title, string &$home_away ): string {
        if ( ! preg_match( '/[\(\[]\s*([^\)\]]{1,12})\s*[\)\]]/u', $title, $m ) ) return $title;

        $word = self::fold( $m[1] );
        if ( in_array( $word, self::AWAY_WORDS, true ) ) {
            $home_away = 'away';
        } elseif ( in_array( $word, self::HOME_WORDS, true ) ) {
            $home_away = 'home';
        } else {
            return $title; // a bracket about something else — leave it alone.
        }
        return trim( str_replace( $m[0], ' ', $title ) );
    }

    /**
     * Split a two-sided title on the first separator that actually has
     * text either side of it.
     *
     * @return array{0:string,1:string}|null
     */
    private static function split( string $title ): ?array {
        $padded = ' ' . $title . ' ';
        foreach ( self::SEPARATORS as $separator ) {
            $at = stripos( $padded, $separator );
            if ( $at === false ) continue;
            $left  = trim( substr( $padded, 0, $at ) );
            $right = trim( substr( $padded, $at + strlen( $separator ) ) );
            if ( $left === '' || $right === '' ) continue;
            return [ $left, $right ];
        }
        return null;
    }

    /**
     * Two named sides. When one of them is this team the other is the
     * opponent and the order gives home/away — that is the only read here
     * worth calling confident.
     *
     * @return Derived|null
     */
    private static function fromSides( string $left, string $right, string $team_name, string $home_away ): ?array {
        // "uit tegen DVVC" splits on " tegen " into a venue word and a
        // club. The venue word is not a side — it is the answer to the
        // home/away half, and dropping it would lose the one thing the
        // title was explicit about.
        $venue = self::venueWord( $left );
        if ( $venue !== '' ) {
            $opponent = self::clean( $right );
            if ( $opponent === '' ) return null;
            return [
                'opponent'   => $opponent,
                'home_away'  => $home_away !== '' ? $home_away : $venue,
                'confidence' => 'low',
            ];
        }

        $ours_left  = $team_name !== '' && self::sameTeam( $left, $team_name );
        $ours_right = $team_name !== '' && self::sameTeam( $right, $team_name );

        if ( $ours_left !== $ours_right ) {
            $opponent = self::clean( $ours_left ? $right : $left );
            if ( $opponent === '' ) return null;
            return [
                'opponent'   => $opponent,
                // The home side is named first, the convention every league
                // fixture list uses. An explicit "(uit)" in the title still
                // wins — somebody wrote it on purpose.
                'home_away'  => $home_away !== '' ? $home_away : ( $ours_left ? 'home' : 'away' ),
                'confidence' => 'high',
            ];
        }

        // Neither side is recognisably us (or both are). There is a signal,
        // so this is worth proposing — but which side is the opponent is a
        // guess, and home/away would be a guess on top of a guess.
        $opponent = self::clean( $right );
        if ( $opponent === '' ) $opponent = self::clean( $left );
        if ( $opponent === '' ) return null;

        return [ 'opponent' => $opponent, 'home_away' => $home_away, 'confidence' => 'low' ];
    }

    /**
     * "tegen Ajax", "uit tegen Ajax", "vs Ajax JO12-1" — one named side,
     * introduced by a keyword.
     *
     * @return array{0:string,1:string}|null opponent + home/away
     */
    private static function fromLeadKeyword( string $title ): ?array {
        $words = preg_split( '/\s+/u', $title ) ?: [];
        foreach ( $words as $i => $word ) {
            if ( ! in_array( self::fold( $word ), self::LEAD_KEYWORDS, true ) ) continue;
            $rest = trim( implode( ' ', array_slice( $words, $i + 1 ) ) );
            if ( $rest === '' ) continue;

            // A venue word anywhere before the keyword ("uit tegen Ajax").
            $home_away = '';
            foreach ( array_slice( $words, 0, $i ) as $before ) {
                $folded = self::fold( $before );
                if ( in_array( $folded, self::AWAY_WORDS, true ) ) $home_away = 'away';
                if ( in_array( $folded, self::HOME_WORDS, true ) ) $home_away = 'home';
            }
            return [ $rest, $home_away ];
        }
        return null;
    }

    /**
     * Is this side of the title the team the fixture belongs to? Compared
     * on folded text, so "Hedel JO12-1", "hedel jo12-1" and "Hedel  JO12-1"
     * are one team, and a side that merely contains the team name
     * ("Hedel JO12-1 selectie") still matches.
     */
    private static function sameTeam( string $side, string $team_name ): bool {
        $side = self::fold( $side );
        $team = self::fold( $team_name );
        if ( $side === '' || $team === '' ) return false;
        if ( $side === $team ) return true;
        return strpos( $side, $team ) !== false || strpos( $team, $side ) !== false;
    }

    /** Drop the fixture-describing noise and tidy the leftovers. */
    private static function clean( string $side ): string {
        $words = preg_split( '/\s+/u', trim( $side ) ) ?: [];
        $kept  = [];
        foreach ( $words as $word ) {
            $folded = self::fold( $word );
            if ( $folded === '' ) continue;
            if ( in_array( $folded, self::NOISE, true ) ) continue;
            if ( in_array( $folded, self::AWAY_WORDS, true ) || in_array( $folded, self::HOME_WORDS, true ) ) continue;
            // A bare time or date fragment is never an opponent.
            if ( preg_match( '/^\d{1,2}[:.]\d{2}$/', $word ) ) continue;
            if ( preg_match( '/^\d{1,4}([-\/]\d{1,4}){1,2}$/', $word ) ) continue;
            $kept[] = $word;
        }
        $out = trim( implode( ' ', $kept ), " \t\n\r\0\x0B-–—:,;" );
        // A leftover that is only punctuation or a lone digit is not a name.
        if ( $out === '' || preg_match( '/^[\W\d_]+$/u', $out ) ) return '';
        return $out;
    }

    /**
     * `home` / `away` when the text is nothing but a venue word, '' when
     * it is anything else — a club called "Uitgeest" is not a venue.
     */
    private static function venueWord( string $text ): string {
        $folded = self::fold( $text );
        if ( in_array( $folded, self::AWAY_WORDS, true ) ) return 'away';
        if ( in_array( $folded, self::HOME_WORDS, true ) ) return 'home';
        return '';
    }

    /** Lower-cased, punctuation-light comparison text. */
    private static function fold( string $value ): string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^\p{L}\p{N}\s-]+/u', '', $value ) ?? $value;
        return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
    }
}
