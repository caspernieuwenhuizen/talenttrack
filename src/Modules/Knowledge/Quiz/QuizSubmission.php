<?php
namespace TT\Modules\Knowledge\Quiz;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * QuizSubmission — turn what the form sent into what the scorer wants.
 *
 * The four controls submit four different shapes, because each is the
 * natural HTML for its question type:
 *
 *   single   `q[id]`               one label
 *   multiple `q[id][]`             labels, order irrelevant
 *   match    `q[id][]`             one label per pair, in pair order
 *   order    `q[id][label]=2`      a position per option
 *
 * The scorer takes one shape — a label, or a list of labels in the
 * reader's intended sequence — because a scorer that knew about form
 * controls would be a scorer that changed whenever the form did.
 *
 * The ordering conversion is the only real work: positions are sorted and
 * the labels come back in that sequence. A missing or duplicate position
 * is left as the reader typed it, so the scorer marks it wrong rather than
 * this class guessing what they meant.
 */
final class QuizSubmission {

    /**
     * @param array<string, mixed> $raw The `q` array from the request.
     * @return array<string, string|list<string>>
     */
    public static function normalise( QuizPayload $payload, array $raw ): array {
        $out = [];

        foreach ( $payload->questions() as $question ) {
            $id   = (string) ( $question['id'] ?? '' );
            $type = (string) ( $question['type'] ?? '' );

            if ( ! array_key_exists( $id, $raw ) ) {
                continue;
            }

            $given = $raw[ $id ];

            if ( $type === 'single' ) {
                $out[ $id ] = is_scalar( $given ) ? trim( (string) $given ) : '';
                continue;
            }

            if ( ! is_array( $given ) ) {
                continue;
            }

            if ( $type === 'order' ) {
                $out[ $id ] = self::sequenceFromPositions( $given );
                continue;
            }

            // multiple and match are already lists of labels; drop the
            // empty entries a "Choose…" placeholder leaves behind.
            $labels = [];
            foreach ( $given as $label ) {
                if ( is_scalar( $label ) && trim( (string) $label ) !== '' ) {
                    $labels[] = trim( (string) $label );
                }
            }

            $out[ $id ] = $labels;
        }

        return $out;
    }

    /**
     * `[ label => position ]` to labels in position order.
     *
     * Entries with no position are dropped, which makes a half-filled
     * ordering a short list and therefore wrong — the honest outcome. A
     * duplicate position keeps both entries, so the sequence is the wrong
     * length and also scores wrong; guessing which the reader meant first
     * would be inventing an answer on their behalf.
     *
     * @param array<string, mixed> $positions
     * @return list<string>
     */
    private static function sequenceFromPositions( array $positions ): array {
        $pairs = [];

        foreach ( $positions as $label => $position ) {
            if ( ! is_scalar( $position ) || trim( (string) $position ) === '' ) {
                continue;
            }
            if ( ! is_numeric( $position ) ) {
                continue;
            }

            $pairs[] = [ 'label' => trim( (string) $label ), 'pos' => (float) $position ];
        }

        usort( $pairs, static function ( array $a, array $b ): int {
            return $a['pos'] <=> $b['pos'];
        } );

        return array_map(
            static function ( array $pair ): string {
                return $pair['label'];
            },
            $pairs
        );
    }
}
