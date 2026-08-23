<?php
/**
 * Course-corpus gate (#2642, epic #2641).
 *
 * `CourseRegistry` skips anything it cannot parse, because a half-written
 * course in the tree must not fatal a reader's page. That silence is right
 * at runtime and wrong in CI: it is exactly how the docs corpus drifted
 * until 53 files were unreachable (#2548). This gate is the other half of
 * that contract — what the registry passes over quietly, CI fails loudly.
 *
 * Checks, per course folder under `courses/`:
 *
 *   1. `course.md` parses and carries title, summary and a lesson list.
 *   2. Every slug in `lessons:` has a file, and every lesson parses.
 *   3. Every `requires:` slug names a course that exists.
 *   4. `tier:` is a tier the plugin knows.
 *   5. A lesson declaring `quiz: true` has a quiz JSON, it decodes, and it
 *      has a pass mark and at least one question with the right shape.
 *   6. No lesson file in the folder is missing from `lessons:` — an
 *      orphan is either a forgotten entry or a file someone meant to
 *      delete, and both are worth a human look.
 *   7. Locale twins under `courses/<locale>/` do not introduce a lesson
 *      the canonical course does not have.
 *
 * Runs on plain PHP with no WordPress. It requires the real parsers rather
 * than re-implementing them, so the gate cannot drift from the runtime it
 * is guarding — the one failure mode a hand-rolled lint always develops.
 *
 * Usage: php tools/check-courses.php
 */

$root = dirname( __DIR__ );

// The parsers guard on ABSPATH so they cannot be requested directly over
// HTTP. Neither touches a WordPress function, so defining it is enough to
// load them in a CLI context.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}

require_once $root . '/src/Modules/Documentation/DocFrontMatter.php';
require_once $root . '/src/Modules/Knowledge/CourseManifest.php';
require_once $root . '/src/Modules/Knowledge/CourseLesson.php';
require_once $root . '/src/Modules/Knowledge/Blocks/BlockRenderer.php';
require_once $root . '/src/Modules/Knowledge/Blocks/BlockRegistry.php';
require_once $root . '/src/Modules/Knowledge/Blocks/CheckBlock.php';

use TT\Modules\Documentation\DocFrontMatter;
use TT\Modules\Knowledge\Blocks\BlockRegistry;
use TT\Modules\Knowledge\Blocks\CheckBlock;
use TT\Modules\Knowledge\CourseLesson;
use TT\Modules\Knowledge\CourseManifest;

/**
 * Tiers the plugin recognises, read off the License module's own constants.
 *
 * Scraped rather than hard-coded: a duplicated list here would be right on
 * the day it was written and wrong the first time a tier is added, and it
 * would fail by accepting a tier that does not exist — the quiet direction.
 * FeatureMap cannot simply be required: it calls WordPress functions at
 * class level of use, and this gate runs on bare PHP.
 */
$tier_source = (string) @file_get_contents( $root . '/src/Modules/License/FeatureMap.php' );
preg_match_all( "/const\s+TIER_[A-Z_]+\s*=\s*'([a-z_]+)'/", $tier_source, $tier_matches );
define( 'KNOWN_TIERS', $tier_matches[1] ?: [ 'free', 'standard', 'pro' ] );

/** Question types the quiz renderer implements (#2647). */
const KNOWN_QUESTION_TYPES = [ 'single', 'multiple', 'order', 'match' ];

$courses_dir = $root . '/courses';
if ( ! is_dir( $courses_dir ) ) {
    echo "check-courses: no courses/ directory — nothing to lint.\n";
    exit( 0 );
}

$errors = [];

/** Report a failure against a file. */
$fail = static function ( string $file, string $message ) use ( &$errors ): void {
    $errors[] = [ 'file' => $file, 'message' => $message ];
};

// --- Discover course folders, skipping locale directories. ------------

$all_dirs = glob( $courses_dir . '/*', GLOB_ONLYDIR ) ?: [];
$slugs    = [];
$locales  = [];

foreach ( $all_dirs as $dir ) {
    $name = basename( $dir );
    if ( CourseManifest::isValidSlug( $name ) ) {
        $slugs[] = $name;
    } elseif ( preg_match( '/^[a-z]{2}(_[A-Z]{2})?$/', $name ) ) {
        $locales[] = $name;
    } else {
        $fail( 'courses/' . $name, 'Directory name is neither a valid course slug (lowercase, hyphen-separated) nor a locale.' );
    }
}

sort( $slugs );

if ( $slugs === [] ) {
    echo "check-courses: no course folders found — nothing to lint.\n";
    exit( 0 );
}

// --- Per-course checks. -----------------------------------------------

/** @var array<string, CourseManifest> */
$manifests = [];

foreach ( $slugs as $slug ) {
    $rel      = 'courses/' . $slug;
    $manifest_path = $courses_dir . '/' . $slug . '/' . CourseManifest::FILENAME;

    if ( ! is_file( $manifest_path ) ) {
        $fail( $rel, 'No ' . CourseManifest::FILENAME . ' — a course folder without a manifest is invisible to the registry.' );
        continue;
    }

    $data     = DocFrontMatter::fromFile( $manifest_path );
    $manifest = CourseManifest::fromData( $slug, $data );

    if ( $manifest === null ) {
        $reason = $data === []
            ? 'front matter is missing or the block is never closed'
            : 'title or lessons is missing';
        $fail( $rel . '/' . CourseManifest::FILENAME, 'Manifest does not parse: ' . $reason . '.' );
        continue;
    }

    $manifests[ $slug ] = $manifest;

    if ( $manifest->summary() === '' ) {
        $fail( $rel . '/' . CourseManifest::FILENAME, 'No summary: the library index has nothing to show under the title.' );
    }

    if ( ! in_array( $manifest->tier(), KNOWN_TIERS, true ) ) {
        $fail(
            $rel . '/' . CourseManifest::FILENAME,
            sprintf( 'Unknown tier "%s". Known tiers: %s.', $manifest->tier(), implode( ', ', KNOWN_TIERS ) )
        );
    }

    // #2649 — the certificate this course issues. A blank name would put an
    // unnamed qualification on somebody's staff record, and a non-numeric
    // validity would silently mean "never expires" when the author meant
    // something.
    if ( $manifest->certificationName() === '' ) {
        $fail(
            $rel . '/' . CourseManifest::FILENAME,
            'No certification_name and no title to fall back on — completion would issue an unnamed certificate.'
        );
    }

    // Read from the raw front matter, not through `validForMonths()` — that
    // accessor coerces anything unparseable to 0, which is exactly the
    // silent "never expires" this check exists to catch.
    $raw_valid_for = DocFrontMatter::string( $data, 'valid_for_months' );
    if ( $raw_valid_for !== '' && ! ctype_digit( $raw_valid_for ) ) {
        $fail(
            $rel . '/' . CourseManifest::FILENAME,
            sprintf( 'valid_for_months must be a whole number of months, got "%s".', $raw_valid_for )
        );
    }

    // Declared lessons must exist and parse.
    $declared = $manifest->lessonSlugs();
    foreach ( $declared as $lesson_slug ) {
        $lesson_path = $courses_dir . '/' . $slug . '/' . $lesson_slug . '.md';

        if ( ! is_file( $lesson_path ) ) {
            $fail( $rel . '/' . CourseManifest::FILENAME, sprintf( 'Manifest lists lesson "%s" but %s/%s.md does not exist.', $lesson_slug, $rel, $lesson_slug ) );
            continue;
        }

        $lesson = CourseLesson::fromFile( $lesson_slug, $lesson_path );
        if ( $lesson === null ) {
            $fail( $rel . '/' . $lesson_slug . '.md', 'Lesson does not parse: front matter is missing, unterminated, or has no title.' );
            continue;
        }

        // A lesson that requires a quiz must have one that works, and must
        // actually render it. #2647 found ten lessons declaring
        // `quiz: true` with a valid payload and no `tt-quiz` block — the
        // check existed, was scored, and appeared on no page.
        if ( $lesson->hasQuiz() ) {
            checkQuiz( $courses_dir, $rel, $slug, $lesson_slug, $fail );

            if ( strpos( $lesson->body(), '```tt-quiz' ) === false ) {
                $fail( $rel . '/' . $lesson_slug . '.md', 'Declares quiz: true but the body has no ```tt-quiz block, so the questions never render.' );
            }
        } elseif ( strpos( $lesson->body(), '```tt-quiz' ) !== false ) {
            $fail( $rel . '/' . $lesson_slug . '.md', 'Has a ```tt-quiz block but does not declare quiz: true, so passing it would not count towards completion.' );
        }

        // Same trap in the other direction for assignments.
        if ( $lesson->hasAssignment() && strpos( $lesson->body(), '```tt-assignment' ) === false ) {
            $fail( $rel . '/' . $lesson_slug . '.md', 'Declares assignment: true but the body has no ```tt-assignment block.' );
        }

        checkInlineChecks( $rel, $lesson_slug, $lesson->body(), $fail );
    }

    // Orphan lesson files — on disk but not in the manifest.
    $on_disk = glob( $courses_dir . '/' . $slug . '/*.md' ) ?: [];
    foreach ( $on_disk as $file ) {
        $name = basename( $file, '.md' );
        if ( $name === basename( CourseManifest::FILENAME, '.md' ) ) {
            continue;
        }
        if ( ! in_array( $name, $declared, true ) ) {
            $fail( $rel . '/' . $name . '.md', 'Lesson file is not listed in the manifest\'s lessons: — add it, or delete the file.' );
        }
    }
}

// --- Cross-course checks. ---------------------------------------------

foreach ( $manifests as $slug => $manifest ) {
    foreach ( $manifest->requires() as $prerequisite ) {
        if ( ! isset( $manifests[ $prerequisite ] ) ) {
            $fail(
                'courses/' . $slug . '/' . CourseManifest::FILENAME,
                sprintf( 'requires: names "%s", which is not a course in this corpus.', $prerequisite )
            );
        }
        if ( $prerequisite === $slug ) {
            $fail( 'courses/' . $slug . '/' . CourseManifest::FILENAME, 'requires: lists the course itself.' );
        }
    }
}

// --- Locale twins. ----------------------------------------------------

foreach ( $locales as $locale ) {
    $twin_dirs = glob( $courses_dir . '/' . $locale . '/*', GLOB_ONLYDIR ) ?: [];
    foreach ( $twin_dirs as $twin_dir ) {
        $slug = basename( $twin_dir );
        $rel  = 'courses/' . $locale . '/' . $slug;

        if ( ! isset( $manifests[ $slug ] ) ) {
            $fail( $rel, sprintf( 'Translation of "%s", which is not a course in this corpus.', $slug ) );
            continue;
        }

        $declared = $manifests[ $slug ]->lessonSlugs();
        foreach ( glob( $twin_dir . '/*.md' ) ?: [] as $file ) {
            $name = basename( $file, '.md' );
            if ( $name === basename( CourseManifest::FILENAME, '.md' ) ) {
                continue;
            }
            if ( ! in_array( $name, $declared, true ) ) {
                $fail( $rel . '/' . $name . '.md', 'Translated lesson has no canonical counterpart — the reader can never resolve it.' );
            }
        }
    }
}

// --- Report. ----------------------------------------------------------

if ( $errors !== [] ) {
    fwrite( STDERR, sprintf( "check-courses: %d problem(s).\n\n", count( $errors ) ) );
    foreach ( $errors as $error ) {
        fwrite( STDERR, sprintf( "  %s\n    %s\n", $error['file'], $error['message'] ) );
        // GitHub annotation, so the failure lands on the file in the diff.
        printf( "::error file=%s::%s\n", $error['file'], $error['message'] );
    }
    fwrite( STDERR, "\nSee docs/knowledge-library.md for the corpus contract.\n" );
    exit( 1 );
}

printf( "check-courses: OK — %d course(s), all lessons and quizzes resolve.\n", count( $manifests ) );
exit( 0 );

/**
 * Validate every `tt-check` in a lesson body (#2738).
 *
 * A check is scored in the browser from the `answer` attribute, so a typo
 * there is not a rendering bug — it is a lesson that confidently tells a
 * coach the wrong thing. Nothing at runtime can catch that, which is
 * exactly the sort of thing this gate is for.
 *
 * The body grammar is read through `CheckBlock::inspect()` rather than
 * re-parsed here, so the lint and the renderer cannot disagree about what
 * counts as an option.
 *
 * @param callable(string, string): void $fail
 */
function checkInlineChecks( string $rel, string $lesson_slug, string $body, callable $fail ): void {
    $where = $rel . '/' . $lesson_slug . '.md';
    $lines = preg_split( '/\R/', $body ) ?: [];
    $count = count( $lines );

    $index = 0;
    for ( $i = 0; $i < $count; $i++ ) {
        if ( ! preg_match( '/^```(.*)$/', $lines[ $i ], $match ) ) {
            continue;
        }
        if ( BlockRegistry::parseName( $match[1] ) !== 'tt-check' ) {
            continue;
        }

        $index++;
        $attrs = BlockRegistry::parseAttributes( $match[1] );

        $inner = [];
        for ( $j = $i + 1; $j < $count; $j++ ) {
            if ( preg_match( '/^```\s*$/', $lines[ $j ] ) ) {
                break;
            }
            $inner[] = $lines[ $j ];
        }

        $check = CheckBlock::inspect( $attrs, implode( "\n", $inner ) );
        $label = sprintf( 'tt-check #%d', $index );

        if ( $check['prompt'] === '' ) {
            $fail( $where, $label . ' has no prompt="…" — there is no question to answer.' );
        }

        if ( count( $check['options'] ) < 2 ) {
            $fail( $where, $label . ' has fewer than two options; write them as "- A. text" list items.' );
            continue;
        }

        if ( $check['answer'] === '' ) {
            $fail( $where, $label . ' has no answer="…", so every response scores as wrong.' );
            continue;
        }

        if ( ! in_array( $check['answer'], $check['options'], true ) ) {
            $fail( $where, sprintf(
                '%s declares answer="%s" but its options are %s — the check can never be answered correctly.',
                $label,
                $check['answer'],
                implode( ', ', $check['options'] )
            ) );
        }

        if ( $check['explanation'] === '' ) {
            $fail( $where, $label . ' has no explanation; add it as a "> …" blockquote. A verdict without a reason teaches nothing.' );
        }
    }
}

/**
 * Validate one lesson's quiz payload.
 *
 * The shape checked here is the contract #2647 implements. Validating it
 * now, before anything reads it, means the corpus cannot accumulate
 * quizzes that turn out to be unrenderable when the reader arrives.
 *
 * @param callable(string, string): void $fail
 */
function checkQuiz( string $courses_dir, string $rel, string $slug, string $lesson_slug, callable $fail ): void {
    $quiz_rel  = $rel . '/quizzes/' . $lesson_slug . '.json';
    $quiz_path = $courses_dir . '/' . $slug . '/quizzes/' . $lesson_slug . '.json';

    if ( ! is_file( $quiz_path ) ) {
        $fail( $rel . '/' . $lesson_slug . '.md', 'Declares quiz: true but ' . $quiz_rel . ' does not exist.' );
        return;
    }

    $payload = json_decode( (string) file_get_contents( $quiz_path ), true );
    if ( ! is_array( $payload ) ) {
        $fail( $quiz_rel, 'Not valid JSON: ' . json_last_error_msg() . '.' );
        return;
    }

    $questions = $payload['questions'] ?? null;
    if ( ! is_array( $questions ) || $questions === [] ) {
        $fail( $quiz_rel, 'No questions.' );
        return;
    }

    $pass_mark = $payload['pass_mark'] ?? null;
    if ( ! is_int( $pass_mark ) || $pass_mark < 1 ) {
        $fail( $quiz_rel, 'pass_mark must be a positive integer.' );
    } elseif ( $pass_mark > count( $questions ) ) {
        $fail( $quiz_rel, sprintf( 'pass_mark is %d but there are only %d questions — unpassable.', $pass_mark, count( $questions ) ) );
    }

    $seen_ids = [];
    foreach ( $questions as $index => $question ) {
        $where = sprintf( 'question %d', (int) $index + 1 );

        if ( ! is_array( $question ) ) {
            $fail( $quiz_rel, $where . ' is not an object.' );
            continue;
        }

        $id = $question['id'] ?? '';
        if ( ! is_string( $id ) || $id === '' ) {
            $fail( $quiz_rel, $where . ' has no id.' );
        } elseif ( isset( $seen_ids[ $id ] ) ) {
            $fail( $quiz_rel, sprintf( '%s reuses id "%s" — attempts are stored by id.', $where, $id ) );
        } else {
            $seen_ids[ $id ] = true;
        }

        $type = $question['type'] ?? '';
        if ( ! in_array( $type, KNOWN_QUESTION_TYPES, true ) ) {
            $fail( $quiz_rel, sprintf( '%s has unknown type "%s". Known types: %s.', $where, is_string( $type ) ? $type : gettype( $type ), implode( ', ', KNOWN_QUESTION_TYPES ) ) );
            continue;
        }

        if ( ! isset( $question['prompt'] ) || ! is_string( $question['prompt'] ) || $question['prompt'] === '' ) {
            $fail( $quiz_rel, $where . ' has no prompt.' );
        }

        $options = $question['options'] ?? null;
        if ( ! is_array( $options ) || count( $options ) < 2 ) {
            $fail( $quiz_rel, $where . ' needs at least two options.' );
            continue;
        }

        // A match question pairs a left-hand list against the options. The
        // answer is one option index per pair, in pair order, so the two
        // lists have to be the same length or the grader silently marks a
        // correct pairing wrong.
        if ( $type === 'match' ) {
            $pairs = $question['pairs'] ?? null;
            if ( ! is_array( $pairs ) || count( $pairs ) < 2 ) {
                $fail( $quiz_rel, $where . ': a match question needs a pairs array of at least two items.' );
                continue;
            }
            if ( count( $pairs ) !== count( $options ) ) {
                $fail( $quiz_rel, sprintf( '%s: %d pairs against %d options — every pair needs exactly one option.', $where, count( $pairs ), count( $options ) ) );
                continue;
            }
            $answer = $question['answer'] ?? null;
            if ( is_array( $answer ) && count( $answer ) !== count( $pairs ) ) {
                $fail( $quiz_rel, sprintf( '%s: answer has %d entries for %d pairs.', $where, count( $answer ), count( $pairs ) ) );
                continue;
            }
        }

        checkAnswer( $quiz_rel, $where, (string) $type, $question['answer'] ?? null, count( $options ), $fail );
    }
}

/**
 * Validate a question's answer key against its type and option count.
 *
 * Every type stores its answer as option indices, so an off-by-one in a
 * hand-written quiz is caught here rather than by a coach who is told they
 * got it wrong when they did not.
 *
 * @param callable(string, string): void $fail
 * @param mixed                          $answer
 */
function checkAnswer( string $quiz_rel, string $where, string $type, $answer, int $option_count, callable $fail ): void {
    $in_range = static function ( $value ) use ( $option_count ): bool {
        return is_int( $value ) && $value >= 0 && $value < $option_count;
    };

    if ( $type === 'single' ) {
        if ( ! $in_range( $answer ) ) {
            $fail( $quiz_rel, sprintf( '%s: answer must be an option index between 0 and %d.', $where, $option_count - 1 ) );
        }
        return;
    }

    if ( ! is_array( $answer ) || $answer === [] ) {
        $fail( $quiz_rel, sprintf( '%s: answer must be a non-empty array of option indices.', $where ) );
        return;
    }

    foreach ( $answer as $value ) {
        if ( ! $in_range( $value ) ) {
            $fail( $quiz_rel, sprintf( '%s: answer contains %s, which is not an option index between 0 and %d.', $where, var_export( $value, true ), $option_count - 1 ) );
            return;
        }
    }

    // An ordering question is answered by the full sequence; a partial
    // one would silently mark a correct ordering wrong.
    if ( $type === 'order' && count( $answer ) !== $option_count ) {
        $fail( $quiz_rel, sprintf( '%s: an order question must list all %d options in its answer, got %d.', $where, $option_count, count( $answer ) ) );
    }

    if ( count( $answer ) !== count( array_unique( $answer ) ) ) {
        $fail( $quiz_rel, $where . ': answer repeats an option index.' );
    }
}
