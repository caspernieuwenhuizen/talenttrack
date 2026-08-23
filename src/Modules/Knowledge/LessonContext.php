<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LessonContext — which lesson is currently being rendered.
 *
 * A block renderer receives its attributes and its body and nothing else,
 * which is right for the nine blocks that only need those. `tt-quiz` needs
 * to know which lesson it is in, so it can find `quizzes/<lesson>.json`.
 *
 * Threading a context object through `BlockRegistry` and every renderer's
 * signature to serve one block would be the wrong trade. This mirrors what
 * `Documentation\Markdown` already does with its `$topic_slug` static: the
 * renderer sets it around one render and clears it afterwards, and one
 * render runs at a time.
 *
 * The `try`/`finally` in `LessonRenderer` is what makes that safe — a
 * block that throws must not leave the next lesson rendering under this
 * one's identity.
 */
final class LessonContext {

    private static string $course = '';

    private static string $lesson = '';

    public static function set( string $course_slug, string $lesson_slug ): void {
        self::$course = $course_slug;
        self::$lesson = $lesson_slug;
    }

    public static function clear(): void {
        self::$course = '';
        self::$lesson = '';
    }

    public static function course(): string {
        return self::$course;
    }

    public static function lesson(): string {
        return self::$lesson;
    }

    /** Whether a renderer can resolve lesson-scoped content right now. */
    public static function isSet(): bool {
        return self::$course !== '' && self::$lesson !== '';
    }
}
