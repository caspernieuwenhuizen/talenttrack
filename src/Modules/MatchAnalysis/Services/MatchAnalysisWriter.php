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

    private MatchAnalysisRepository $repo;

    public function __construct( ?MatchAnalysisRepository $repo = null ) {
        $this->repo = $repo ?? new MatchAnalysisRepository();
    }

    /**
     * Apply any subset of `summary`, `status`, `sections` and `players`.
     * Anything absent is left alone — a client that only knows about
     * sections must not be able to wipe the player items by omission.
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

        if ( isset( $body['sections'] ) && is_array( $body['sections'] ) ) {
            foreach ( $body['sections'] as $key => $section ) {
                if ( ! is_array( $section ) ) continue;
                $this->saveSection(
                    $analysis_id,
                    sanitize_key( (string) $key ),
                    $section['rating'] ?? null,
                    $section['notes'] ?? []
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

        // `notes` is the shape since #3091; `note` is what every client
        // written before it sends, and a single note is just a one-item
        // list. Accepting both keeps the endpoint's promise that a client
        // which knows less cannot destroy what it does not understand.
        $notes = array_key_exists( 'notes', $item ) ? $item['notes'] : ( $item['note'] ?? [] );

        $item_id = $this->repo->savePlayerItem(
            $analysis_id,
            $player_id,
            $marker,
            self::cleanNoteItems( $notes ),
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
