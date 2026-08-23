<?php
namespace TT\Modules\Knowledge\Quiz;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * QuizScorer — marks a submission, server-side.
 *
 * Server-side is not a preference. The payload carries the answer key, so
 * a client-side scorer would have to be handed the answers to do its job,
 * and a coach checking devtools would find them.
 *
 * The submission arrives as option **labels**, not indices, because
 * `QuizPayload` shuffles the options per render (see its docblock). The
 * scorer resolves each label back to its stored index and compares
 * against the key.
 *
 * Partial credit is not offered. A multi-part answer is either the answer
 * or it is not: half of an ordering is not half an understanding of the
 * sequence, and awarding it would let a coach pass a check on
 * "the small games are somewhere near the end".
 */
final class QuizScorer {

    /**
     * Mark a submission.
     *
     * `$submitted` maps question id to the reader's answer:
     *   single   — one label
     *   multiple — a list of labels, order irrelevant
     *   order    — a list of labels in the reader's sequence
     *   match    — a list of labels, one per pair, in the pairs' own order
     *
     * A question with no submitted answer is wrong, not skipped: a quiz
     * where leaving a question blank cannot hurt you is one a reader can
     * pass by answering only what they are sure of.
     *
     * @param array<string, mixed> $submitted
     * @return array{
     *   score: int,
     *   max: int,
     *   pass_mark: int,
     *   passed: bool,
     *   questions: list<array{id: string, correct: bool, expected: list<string>, explanation: string}>
     * }
     */
    public static function score( QuizPayload $payload, array $submitted ): array {
        $results = [];
        $score   = 0;

        foreach ( $payload->questions() as $question ) {
            $id      = (string) ( $question['id'] ?? '' );
            $given   = $submitted[ $id ] ?? null;
            $correct = self::isCorrect( $question, $given );

            if ( $correct ) {
                $score++;
            }

            $results[] = [
                'id'          => $id,
                'correct'     => $correct,
                // The expected answer is returned only in the response to a
                // submission — never in the form the reader fills in.
                'expected'    => self::expectedLabels( $question ),
                'explanation' => (string) ( $question['explanation'] ?? '' ),
            ];
        }

        $max       = $payload->count();
        $pass_mark = $payload->passMark();

        return [
            'score'     => $score,
            'max'       => $max,
            'pass_mark' => $pass_mark,
            'passed'    => $score >= $pass_mark,
            'questions' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $question
     * @param mixed                $given
     */
    private static function isCorrect( array $question, $given ): bool {
        $type = (string) ( $question['type'] ?? '' );

        if ( $type === 'single' ) {
            if ( ! is_string( $given ) ) {
                return false;
            }

            return QuizPayload::indexOfOption( $question, $given ) === (int) ( $question['answer'] ?? -1 );
        }

        if ( ! is_array( $given ) ) {
            return false;
        }

        $expected = array_map( 'intval', (array) ( $question['answer'] ?? [] ) );
        $actual   = [];

        foreach ( $given as $label ) {
            if ( ! is_string( $label ) ) {
                return false;
            }
            $index = QuizPayload::indexOfOption( $question, $label );
            if ( $index < 0 ) {
                return false;
            }
            $actual[] = $index;
        }

        if ( $type === 'multiple' ) {
            // Order is not part of the answer; a repeat is, because
            // selecting the same option twice is not a coherent answer.
            if ( count( $actual ) !== count( array_unique( $actual ) ) ) {
                return false;
            }
            sort( $actual );
            sort( $expected );

            return $actual === $expected;
        }

        // order and match are both sequences, compared position by position.
        return $actual === $expected;
    }

    /**
     * The correct answer as labels, for the feedback panel.
     *
     * @param array<string, mixed> $question
     * @return list<string>
     */
    private static function expectedLabels( array $question ): array {
        $options = array_values( array_map( 'strval', (array) ( $question['options'] ?? [] ) ) );
        $answer  = $question['answer'] ?? null;
        $indices = is_array( $answer ) ? array_map( 'intval', $answer ) : [ (int) $answer ];

        $labels = [];
        foreach ( $indices as $index ) {
            if ( isset( $options[ $index ] ) ) {
                $labels[] = $options[ $index ];
            }
        }

        return $labels;
    }
}
