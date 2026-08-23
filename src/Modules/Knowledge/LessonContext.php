<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LessonContext — which lesson is currently being rendered.
 *
 * A block renderer receives its attributes and its body and nothing else,
 * which is right for the eight blocks that only need those. `tt-quiz` needs
 * to know which lesson it is in, so it can find `quizzes/<lesson>.json`;
 * `tt-assignment` needs that and the reader's enrolment, so it can show
 * what they handed in and what the reviewer said back (#2648).
 *
 * Threading a context object through `BlockRegistry` and every renderer's
 * signature to serve two blocks would be the wrong trade. This mirrors what
 * `Documentation\Markdown` already does with its `$topic_slug` static: the
 * renderer sets it around one render and clears it afterwards, and one
 * render runs at a time.
 *
 * The `try`/`finally` in `LessonRenderer` is what makes that safe — a
 * block that throws must not leave the next lesson rendering under this
 * one's identity, and with an enrolment id in here that now means one
 * coach's submission must not surface on the next reader's page.
 */
final class LessonContext {

    private static string $course = '';

    private static string $lesson = '';

    /**
     * The reading person's enrolment, or 0 when the render has no reader
     * behind it — the REST preview, or a lesson shown to someone whose
     * login is not linked to a staff record.
     */
    private static int $enrolment = 0;

    public static function set( string $course_slug, string $lesson_slug, int $enrolment_id = 0 ): void {
        self::$course    = $course_slug;
        self::$lesson    = $lesson_slug;
        self::$enrolment = max( 0, $enrolment_id );
    }

    public static function clear(): void {
        self::$course    = '';
        self::$lesson    = '';
        self::$enrolment = 0;
    }

    public static function course(): string {
        return self::$course;
    }

    public static function lesson(): string {
        return self::$lesson;
    }

    public static function enrolment(): int {
        return self::$enrolment;
    }

    /** Whether a renderer can resolve lesson-scoped content right now. */
    public static function isSet(): bool {
        return self::$course !== '' && self::$lesson !== '';
    }

    /**
     * Whether a block can additionally resolve *this reader's* state.
     *
     * Separate from `isSet()`, because a lesson renders perfectly well with
     * nobody behind it and a block that needs a reader has to say something
     * else rather than treat a zero enrolment as a real one.
     */
    public static function hasReader(): bool {
        return self::isSet() && self::$enrolment > 0;
    }
}
