<?php
namespace TT\Modules\MatchAnalysis\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Journey\EventEmitter;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;

/**
 * MatchAnalysisJourney (#2707) — keeps a player item and its timeline
 * entry in step.
 *
 * A match analysis that only lived on its own page would be a per-match
 * document, and this plugin does not do per-match documents: the player is
 * the root entity (CLAUDE.md §1). What a coach wrote about a named child on
 * a named Saturday belongs on that child's timeline, next to their
 * evaluations and their PDP, where the question "what has this player
 * shown lately?" is actually asked.
 *
 * Three operations, because an item can be written, rewritten and cleared:
 *
 *   - `record()`  — emit on first write; update the summary on a rewrite,
 *                   because `EventEmitter::emit()` is idempotent by design
 *                   and would otherwise leave the first version standing.
 *   - `forget()`  — delete the entry when the item is cleared. A coach who
 *                   removes a note has decided it should not stand; leaving
 *                   the timeline entry would make the record say something
 *                   the coach withdrew.
 *
 * The direct writes here are scoped to this module's own rows
 * (`source_module = 'MatchAnalysis'`), which is why they are safe: nothing
 * else can be reached through them.
 */
final class MatchAnalysisJourney {

    public const SOURCE_MODULE = 'MatchAnalysis';
    public const ENTITY_TYPE   = 'match_analysis_player';

    /**
     * Emit or refresh the timeline entry for one player item.
     */
    public static function record( int $analysis_id, int $player_id, int $item_id ): void {
        if ( $analysis_id <= 0 || $player_id <= 0 || $item_id <= 0 ) return;

        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.marker, p.team_function, a.activity_id, ac.session_date
               FROM {$wpdb->prefix}tt_match_analysis_players p
          LEFT JOIN {$wpdb->prefix}tt_match_analyses a
                 ON a.id = p.analysis_id AND a.club_id = p.club_id
          LEFT JOIN {$wpdb->prefix}tt_activities ac
                 ON ac.id = a.activity_id AND ac.club_id = a.club_id
              WHERE p.id = %d AND p.club_id = %d",
            $item_id, CurrentClub::id()
        ) );
        if ( ! $row ) return;

        // Date the entry to the match, not to when the coach typed it up.
        // A Saturday game written up on Monday evening is a Saturday event
        // on the child's timeline; anything else makes the sequence of a
        // player's season wrong in the one view built to show it.
        $when = (string) ( $row->session_date ?? '' );
        if ( strlen( $when ) === 10 ) $when .= ' 00:00:00';
        if ( $when === '' ) $when = current_time( 'mysql' );

        // #3091 — a player can hold a plus and a minus in the same match,
        // but this stays ONE timeline entry. Two entries for one game would
        // double-count the player in every count built on the timeline, and
        // read as two separate observations when it was one write-up.
        $notes   = ( new MatchAnalysisRepository() )->playerNotes( $analysis_id )[ $player_id ] ?? [];
        $summary = self::summaryFor( (string) ( $row->marker ?? '' ), $notes );

        $existing = self::existingEventId( $item_id );
        if ( $existing > 0 ) {
            $wpdb->update(
                $wpdb->prefix . 'tt_player_events',
                [
                    'summary'    => mb_substr( $summary, 0, 500 ),
                    'event_date' => $when,
                    'payload'    => (string) wp_json_encode( self::payload( $analysis_id, $item_id, $row ) ),
                ],
                [ 'id' => $existing, 'club_id' => CurrentClub::id() ]
            );
            return;
        }

        EventEmitter::emit(
            $player_id,
            JourneyEventType::MATCH_OBSERVED,
            $when,
            $summary,
            self::payload( $analysis_id, $item_id, $row ),
            self::SOURCE_MODULE,
            self::ENTITY_TYPE,
            $item_id
        );
    }

    /**
     * Remove the timeline entry for a player item that no longer exists.
     */
    public static function forget( int $analysis_id, int $player_id ): void {
        if ( $analysis_id <= 0 || $player_id <= 0 ) return;

        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "DELETE e FROM {$wpdb->prefix}tt_player_events e
              WHERE e.source_module = %s
                AND e.source_entity_type = %s
                AND e.player_id = %d
                AND e.club_id = %d
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}tt_match_analysis_players p
                     WHERE p.id = e.source_entity_id AND p.club_id = e.club_id
                )",
            self::SOURCE_MODULE,
            self::ENTITY_TYPE,
            $player_id,
            CurrentClub::id()
        ) );
    }

    /**
     * The timeline line itself. The marker alone ("Stood out") says nothing
     * a coach can act on six weeks later, so the notes carry the entry and
     * the marker only prefixes it. An item with a marker and no notes falls
     * back to the marker — that is the whole content there is.
     *
     * Since #3091 there can be two notes, each with its own mark. They are
     * joined into one line with their signs in front, because the timeline
     * is a chronological read of a player's season and a single match
     * should occupy a single line in it. The 120-character budget is on the
     * joined text, not per note, for the same reason.
     *
     * @param string|list<array{valence:string, body:string}> $notes a note
     *        list, or the joined text a caller written before #3091 passes
     */
    public static function summaryFor( string $marker, $notes ): string {
        $label = $marker !== '' ? MatchAnalysisEnums::markerLabel( $marker ) : '';
        $note  = is_array( $notes ) ? self::joinNotes( $notes ) : trim( (string) $notes );

        if ( $note === '' ) {
            return $label !== '' ? $label : __( 'Observed in a match', 'talenttrack' );
        }

        $trimmed = mb_strlen( $note ) > 120 ? mb_substr( $note, 0, 119 ) . '…' : $note;

        return $label !== ''
            ? sprintf(
                /* translators: 1: marker label (Stood out / Below par), 2: the coach's note */
                _x( '%1$s — %2$s', 'match observation timeline entry', 'talenttrack' ),
                $label,
                $trimmed
            )
            : $trimmed;
    }

    /**
     * "+ Kept the ball under pressure · − Lost his man twice at corners".
     *
     * The sign goes in front of the sentence it belongs to. Without it a
     * reader six weeks later cannot tell which half of a two-note entry was
     * the good one, which is the whole reason the marks exist.
     *
     * @param list<array{valence:string, body:string}> $notes
     */
    private static function joinNotes( array $notes ): string {
        $parts = [];

        foreach ( $notes as $note ) {
            $body = trim( (string) $note['body'] );
            if ( $body === '' ) continue;

            $glyph   = MatchAnalysisEnums::valenceGlyph( (string) $note['valence'] );
            $parts[] = $glyph !== '' ? $glyph . ' ' . $body : $body;
        }

        return implode( ' · ', $parts );
    }

    private static function existingEventId( int $item_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_player_events
              WHERE source_module = %s AND source_entity_type = %s
                AND source_entity_id = %d AND event_type = %s AND club_id = %d",
            self::SOURCE_MODULE,
            self::ENTITY_TYPE,
            $item_id,
            JourneyEventType::MATCH_OBSERVED,
            CurrentClub::id()
        ) );
    }

    /**
     * @return array<string,mixed>
     */
    private static function payload( int $analysis_id, int $item_id, object $row ): array {
        return [
            'analysis_id'   => $analysis_id,
            'item_id'       => $item_id,
            'activity_id'   => (int) ( $row->activity_id ?? 0 ),
            'marker'        => (string) ( $row->marker ?? '' ),
            'team_function' => $row->team_function !== null ? (string) $row->team_function : null,
        ];
    }
}
