<?php
namespace TT\Modules\MatchAnalysis\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;

/**
 * MatchAnalysisWriter — the one way an analysis is written.
 *
 * The REST controller, the on-screen form (which posts through REST) and
 * the wizard's final step all land here, so there is exactly one place
 * where a section is validated, a player item is persisted, and the
 * player's timeline entry is kept in step. A second persistence path is
 * how the wizard and the form drift until one of them silently stops
 * emitting journey events.
 *
 * Every method takes already-decoded input and sanitises it itself: the
 * wizard hands over raw `$_POST` fragments and REST hands over decoded
 * JSON, and neither should have to know what the other did first.
 */
final class MatchAnalysisWriter {

    /**
     * The longest a single note may be, in characters (#3853).
     *
     * A note item is a **bullet, not a paragraph** — that is the design of
     * the feature, not an accident of the first schema. The rows exist so
     * valence is countable in SQL for the season trend (#2725), and a unit
     * you can count is a unit somebody ended deliberately. Every input on
     * every surface says so too: 180 characters on a section bullet, 240 on
     * a player note.
     *
     * 255 is the column's width and the boundary the API enforces; the
     * tighter numbers on the surfaces are house style, not the rule. What
     * changed in #3853 is only that going over is said out loud. It used to
     * be `mb_substr( $body, 0, 255 )` in the repository — a 400-character
     * observation answered 200 and came back ending mid-word.
     */
    public const NOTE_MAX = 255;

    private MatchAnalysisRepository $repo;

    public function __construct( ?MatchAnalysisRepository $repo = null ) {
        $this->repo = $repo ?? new MatchAnalysisRepository();
    }

    /**
     * Apply any subset of `summary`, `status`, `sections` and `players`.
     * Anything absent is left alone — a client that only knows about
     * sections must not be able to wipe the player items by omission.
     *
     * `sections` is read through `sectionEntries()`, so both wire shapes
     * land here: the object the form posts and the list an API client
     * writes. An entry this writer cannot place is skipped; the REST
     * controller refuses the request before reaching this point, so
     * nothing arrives here half-written (#3843).
     *
     * @param array<string,mixed> $body
     * @param array<int,?int>     $minutes player id => minutes, for the snapshot
     */
    public function apply( int $analysis_id, array $body, array $minutes = [] ): void {
        if ( $analysis_id <= 0 ) return;

        $patch = [];
        if ( array_key_exists( 'summary', $body ) ) {
            $patch['summary'] = sanitize_textarea_field( (string) $body['summary'] );
        }
        if ( array_key_exists( 'status', $body ) ) {
            $status = sanitize_key( (string) $body['status'] );
            $patch['status'] = $status === MatchAnalysisEnums::STATUS_FINAL
                ? MatchAnalysisEnums::STATUS_FINAL
                : MatchAnalysisEnums::STATUS_DRAFT;
        }
        if ( $patch ) $this->repo->update( $analysis_id, $patch );

        if ( isset( $body['sections'] ) ) {
            foreach ( self::sectionEntries( $body['sections'] ) as $entry ) {
                $this->saveSection(
                    $analysis_id,
                    $entry['key'],
                    $entry['rating'],
                    $entry['notes']
                );
            }
        }

        if ( isset( $body['players'] ) && is_array( $body['players'] ) ) {
            foreach ( $body['players'] as $pid => $item ) {
                if ( ! is_array( $item ) ) continue;
                $player_id = (int) $pid;
                $this->savePlayerItem(
                    $analysis_id,
                    $player_id,
                    $item,
                    $minutes[ $player_id ] ?? null
                );
            }
        }

        // #3007 — every apply moves the parent's clock, whether or not the
        // parent row itself changed. The analysis is four tables and the
        // surface needs one answer to "when did this last change"; without
        // this, a section write left `updated_at` describing the last
        // summary edit, and the concurrency check in the REST controller
        // would wave through a write composed against a stale document.
        $this->repo->touch( $analysis_id );
    }

    /**
     * `sections` in either wire shape, flattened into entries that carry
     * their own key.
     *
     * An object (`{"aanvallen": {…}}`) keys each entry by its property
     * name — what the form, the wizard and the shared surface all post. A
     * JSON list (`[{"key":"aanvallen", …}]`) keys it by the entry's own
     * `key` / `section_key`, which is what an API client reaches for
     * first, and which used to be discarded: the list index was taken for
     * the section key, `saveSection()` refused `"0"`, and `apply()` threw
     * the refusal away, so six written-up sections answered 200 and stored
     * nothing (#3843).
     *
     * A non-array entry, and a list entry that names no section, come back
     * with an empty key. They are not silently dropped here — `problems()`
     * has to be able to name them, and `apply()` skips them the same way
     * `saveSection()` always has.
     *
     * @param mixed $sections
     * @return list<array{key:string, path:string, rating:mixed, has_rating:bool, notes:mixed}>
     */
    public static function sectionEntries( $sections ): array {
        if ( ! is_array( $sections ) ) return [];

        $out = [];
        foreach ( $sections as $index => $section ) {
            $section = is_array( $section ) ? $section : [];

            if ( is_int( $index ) ) {
                $named = $section['key'] ?? $section['section_key'] ?? '';
                $key   = is_scalar( $named ) ? sanitize_key( (string) $named ) : '';
            } else {
                $key = sanitize_key( (string) $index );
            }

            $out[] = [
                'key'        => $key,
                'path'       => sprintf( 'sections[%s]', $key !== '' ? $key : (string) $index ),
                'rating'     => $section['rating'] ?? null,
                'has_rating' => array_key_exists( 'rating', $section ),
                'notes'      => $section['notes'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * What in this body cannot be written, as field paths, so a caller is
     * told rather than answered 200 over an empty document (#3843).
     *
     * Read before anything is stored: a body carrying one unusable section
     * writes none of them, because a coach who has just typed up a match
     * should not have to work out which half of it landed.
     *
     * @param array<string,mixed> $body
     * @return list<string>
     */
    public static function problems( array $body ): array {
        if ( ! array_key_exists( 'sections', $body ) || $body['sections'] === null ) return [];
        if ( ! is_array( $body['sections'] ) ) return [ 'sections' ];

        $problems = [];
        foreach ( self::sectionEntries( $body['sections'] ) as $entry ) {
            if ( ! MatchAnalysisEnums::isSectionKey( $entry['key'] ) ) {
                $problems[] = $entry['path'];
                continue;
            }
            if ( $entry['has_rating'] && ! self::isWritableRating( $entry['rating'] ) ) {
                $problems[] = $entry['path'] . '.rating';
            }
        }

        return $problems;
    }

    /**
     * Notes in this body that are longer than a note may be, as field
     * paths (#3853).
     *
     * Measured on the cleaned items, because those are what would be
     * stored: `cleanNoteItems()` splits a blob on its line breaks, drops
     * the blanks and runs `sanitize_text_field()` first, so a multi-line
     * note is checked one line at a time and only a single line that is
     * genuinely too long is reported. The index is the item's position in
     * that cleaned list — the position it would have been stored at.
     *
     * An over-long line is never split for the coach. Where a bullet ends
     * is their judgement, and guessing a break mid-sentence would invent
     * one they did not make, with a valence nobody assigned.
     *
     * @param array<string,mixed> $body
     * @return list<string>
     */
    public static function overlongNoteFields( array $body ): array {
        $fields = [];

        if ( isset( $body['sections'] ) ) {
            foreach ( self::sectionEntries( $body['sections'] ) as $entry ) {
                foreach ( self::overlongNotes( $entry['notes'] ) as $index ) {
                    $fields[] = sprintf( '%s.notes[%d]', $entry['path'], $index );
                }
            }
        }

        if ( isset( $body['players'] ) && is_array( $body['players'] ) ) {
            foreach ( $body['players'] as $player_id => $item ) {
                if ( ! is_array( $item ) ) continue;
                foreach ( self::overlongNotes( self::notesOf( $item ) ) as $index ) {
                    $fields[] = sprintf( 'players[%s].notes[%d]', (string) $player_id, $index );
                }
            }
        }

        return $fields;
    }

    /**
     * The positions in one note list that are over `NOTE_MAX` (#3853).
     *
     * @param mixed $value
     * @return list<int>
     */
    public static function overlongNotes( $value ): array {
        $over = [];
        foreach ( self::cleanNoteItems( $value ) as $index => $item ) {
            if ( mb_strlen( $item['body'] ) > self::NOTE_MAX ) $over[] = $index;
        }

        return $over;
    }

    /**
     * A player item's notes, in whichever key it carries them.
     *
     * `notes` is the shape since #3091; `note` is what every client written
     * before it sends, and a single note is just a one-item list.
     *
     * @param array<string,mixed> $item
     * @return mixed
     */
    public static function notesOf( array $item ) {
        return array_key_exists( 'notes', $item ) ? $item['notes'] : ( $item['note'] ?? [] );
    }

    /**
     * Whether a rating can be stored as sent.
     *
     * Null and the empty string are the resting state — the surface's
     * fourth radio clears the rating, and clearing is a real answer. A
     * number, or a word that is not one of the three, is not a rating at
     * all: `cleanRating()` turns both into null, which is why a `6.5` used
     * to vanish behind a success (#3843).
     *
     * @param mixed $value
     */
    public static function isWritableRating( $value ): bool {
        if ( $value === null || $value === '' ) return true;

        return is_string( $value ) && MatchAnalysisEnums::isRating( sanitize_key( $value ) );
    }

    /**
     * @param mixed $rating
     * @param mixed $notes
     */
    public function saveSection( int $analysis_id, string $section_key, $rating, $notes ): bool {
        if ( ! MatchAnalysisEnums::isSectionKey( $section_key ) ) return false;

        return $this->repo->saveSection(
            $analysis_id,
            $section_key,
            self::cleanRating( $rating ),
            self::cleanNoteItems( $notes )
        );
    }

    /**
     * Persist one player item and keep its timeline entry in step. The two
     * belong together: a note that reaches the analysis but not the
     * player's file is exactly the silo CLAUDE.md §1 rules out.
     *
     * @param array<string,mixed> $item
     */
    public function savePlayerItem( int $analysis_id, int $player_id, array $item, ?int $minutes ): void {
        if ( $analysis_id <= 0 || $player_id <= 0 ) return;

        $marker = isset( $item['marker'] ) ? sanitize_key( (string) $item['marker'] ) : '';
        $tag    = isset( $item['team_function'] ) ? sanitize_key( (string) $item['team_function'] ) : '';

        // Both note keys are accepted, which keeps the endpoint's promise
        // that a client which knows less cannot destroy what it does not
        // understand. See `notesOf()`.
        $item_id = $this->repo->savePlayerItem(
            $analysis_id,
            $player_id,
            $marker,
            self::cleanNoteItems( self::notesOf( $item ) ),
            $tag !== '' ? $tag : null,
            $minutes
        );

        if ( $item_id > 0 ) {
            MatchAnalysisJourney::record( $analysis_id, $player_id, $item_id );
            return;
        }

        MatchAnalysisJourney::forget( $analysis_id, $player_id );
    }

    public function deletePlayerItem( int $analysis_id, int $player_id ): void {
        if ( $analysis_id <= 0 || $player_id <= 0 ) return;

        $this->repo->deletePlayerItem( $analysis_id, $player_id );
        MatchAnalysisJourney::forget( $analysis_id, $player_id );
    }

    /**
     * @param mixed $value
     */
    public static function cleanRating( $value ): ?string {
        $rating = is_string( $value ) ? sanitize_key( $value ) : '';
        return MatchAnalysisEnums::isRating( $rating ) ? $rating : null;
    }

    /**
     * Notes arrive either as text or as the form's four bullet inputs.
     * Blank inputs are dropped rather than kept as empty lines: a printed
     * sheet would otherwise render the gaps a coach left between points.
     *
     * @param mixed $value
     */
    /**
     * Notes, each with its optional + / − (#3091).
     *
     * Three input shapes are accepted, because three exist in the wild:
     *
     *   - `[ ['body' => '…', 'valence' => 'plus'], … ]` — what the form and
     *     the wizard post now;
     *   - `[ '…', '…' ]` — a flat list of bullets, which is what every
     *     client written before this shipped sends;
     *   - `"a\nb"` — a single text blob, one note per line, which is how
     *     the notes were stored before they had a table.
     *
     * The older two are read as unmarked notes rather than rejected. A
     * client that has not heard of valence should be able to write a note
     * without one, not fail; that is the same courtesy `apply()` extends by
     * leaving absent keys alone.
     *
     * Blank bodies are dropped rather than kept as empty rows: a printed
     * sheet would otherwise render the gaps a coach left between points.
     * An unknown valence string is stored as neutral, never as itself.
     *
     * @param mixed $value
     * @return list<array{valence:string, body:string}>
     */
    public static function cleanNoteItems( $value ): array {
        if ( is_string( $value ) ) {
            $value = preg_split( '/\r\n|\r|\n/', $value ) ?: [];
        }
        if ( ! is_array( $value ) ) return [];

        $out = [];
        foreach ( $value as $entry ) {
            $body    = '';
            $valence = '';

            if ( is_array( $entry ) ) {
                $body    = sanitize_text_field( (string) ( $entry['body'] ?? '' ) );
                $valence = sanitize_key( (string) ( $entry['valence'] ?? '' ) );
            } else {
                $body = sanitize_text_field( (string) $entry );
            }

            $body = trim( $body );
            if ( $body === '' ) continue;

            $out[] = [
                'valence' => MatchAnalysisEnums::isValence( $valence ) ? $valence : '',
                'body'    => $body,
            ];
        }

        return $out;
    }

    /**
     * @deprecated since #3091 — notes are rows now. Kept because the
     *             wizard's draft state and a queued request can still carry
     *             the old joined-text shape through one release.
     *
     * @param mixed $value
     */
    public static function cleanNotes( $value ): string {
        if ( is_array( $value ) ) {
            $lines = array_map(
                static fn( $line ): string => sanitize_text_field( (string) $line ),
                $value
            );
            $lines = array_filter( $lines, static fn( string $line ): bool => trim( $line ) !== '' );
            $value = implode( "\n", $lines );
        }

        return sanitize_textarea_field( (string) $value );
    }
}
