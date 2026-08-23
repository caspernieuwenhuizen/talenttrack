<?php
namespace TT\Modules\Knowledge\Quiz;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseRegistry;

/**
 * QuizPayload — one lesson's questions, loaded from `quizzes/<lesson>.json`.
 *
 * The file carries the answer key. This class is the only thing that reads
 * it, and it exposes two deliberately different views:
 *
 *   - `questions()` — everything, including answers and explanations.
 *     Server-side only, for the scorer.
 *   - `forDisplay()` — prompts and options, with the answer key and the
 *     explanations removed, and the options shuffled.
 *
 * The shuffle is not decoration. Every `order` and `match` answer in the
 * shipped corpus is the identity permutation — the stored order *is* the
 * correct sequence — so rendering options as stored would hand the reader
 * the answer to every sequencing question in the course.
 *
 * Because the shuffle happens per render, the browser cannot submit
 * indices: it would be describing positions the server has forgotten.
 * It submits **option labels** instead, which the reader can already see,
 * and the server maps them back. No index and no answer key ever crosses
 * the wire, and there is no per-render state to keep.
 */
final class QuizPayload {

    /** Question types the renderer and scorer implement. */
    public const TYPES = [ 'single', 'multiple', 'order', 'match' ];

    private string $lesson_slug;

    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    private function __construct( string $lesson_slug, array $data ) {
        $this->lesson_slug = $lesson_slug;
        $this->data        = $data;
    }

    /**
     * Load a lesson's quiz, or null when it has none or the file is
     * unusable. `check-courses.php` fails a PR for an unusable payload, so
     * at runtime null means the lesson genuinely declares no quiz.
     */
    public static function forLesson( string $course_slug, string $lesson_slug ): ?self {
        $path = CourseRegistry::quizPath( $course_slug, $lesson_slug );
        if ( $path === null ) {
            return null;
        }

        $decoded = json_decode( (string) @file_get_contents( $path ), true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['questions'] ) || ! is_array( $decoded['questions'] ) ) {
            return null;
        }

        return new self( $lesson_slug, $decoded );
    }

    /** How many correct answers the reader needs. */
    public function passMark(): int {
        $mark = (int) ( $this->data['pass_mark'] ?? 0 );

        return max( 1, min( $mark, $this->count() ) );
    }

    public function count(): int {
        return count( $this->questions() );
    }

    /**
     * Every question, answer key included. Never send this to a browser.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(): array {
        $out = [];

        foreach ( $this->data['questions'] as $question ) {
            if ( ! is_array( $question ) ) {
                continue;
            }
            if ( ! in_array( $question['type'] ?? '', self::TYPES, true ) ) {
                continue;
            }
            $out[] = $question;
        }

        return $out;
    }

    /** One question by id, or null. @return array<string, mixed>|null */
    public function question( string $id ): ?array {
        foreach ( $this->questions() as $question ) {
            if ( (string) ( $question['id'] ?? '' ) === $id ) {
                return $question;
            }
        }

        return null;
    }

    /**
     * The renderable form of every question: prompts, pairs and options,
     * with options shuffled and the answer key and explanation stripped.
     *
     * @return list<array{id: string, type: string, prompt: string, pairs: list<string>, options: list<string>}>
     */
    public function forDisplay(): array {
        $out = [];

        foreach ( $this->questions() as $question ) {
            $options = array_values( array_map( 'strval', (array) ( $question['options'] ?? [] ) ) );

            // `single` and `multiple` are shuffled too. Their stored order
            // is not the answer, but a corpus author who habitually writes
            // the right answer first would leak a pattern across a course,
            // and shuffling costs nothing.
            shuffle( $options );

            $out[] = [
                'id'      => (string) ( $question['id'] ?? '' ),
                'type'    => (string) $question['type'],
                'prompt'  => (string) ( $question['prompt'] ?? '' ),
                'pairs'   => array_values( array_map( 'strval', (array) ( $question['pairs'] ?? [] ) ) ),
                'options' => $options,
            ];
        }

        return $out;
    }

    public function lessonSlug(): string {
        return $this->lesson_slug;
    }

    /**
     * Map an option label back to its stored index.
     *
     * Comparison is on the trimmed string. The corpus lint guarantees no
     * two options within a question share a label, so this is unambiguous;
     * an unknown label returns -1 and scores as wrong rather than throwing,
     * because a stale form is a user problem, not a server error.
     *
     * @param array<string, mixed> $question
     */
    public static function indexOfOption( array $question, string $label ): int {
        $needle = trim( $label );

        foreach ( (array) ( $question['options'] ?? [] ) as $index => $option ) {
            if ( trim( (string) $option ) === $needle ) {
                return (int) $index;
            }
        }

        return -1;
    }
}
