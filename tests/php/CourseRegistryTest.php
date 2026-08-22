<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Knowledge\CourseLesson;
use TT\Modules\Knowledge\CourseManifest;
use TT\Modules\Knowledge\CourseRegistry;

/**
 * #2642 — the course corpus is a projection of `courses/`.
 *
 * Two halves, for the same reason DocFrontMatterTest has two. The parsers
 * are exercised against strings and temporary files, including the shapes
 * that must NOT register. The registry is exercised against the shipped
 * corpus, because the failure this issue exists to prevent is corpus drift
 * — a scan that silently drops half a course passes every synthetic test.
 */
final class CourseRegistryTest extends WP_UnitTestCase {

    /** The course that ships with this issue. */
    private const SHIPPED = 'voetbalperiodisering';

    /** @var list<string> paths to clean up after a test */
    private $temp_paths = [];

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
    }

    public function tear_down(): void {
        foreach ( array_reverse( $this->temp_paths ) as $path ) {
            if ( is_file( $path ) ) {
                @unlink( $path );
            } elseif ( is_dir( $path ) ) {
                @rmdir( $path );
            }
        }
        $this->temp_paths = [];
        CourseRegistry::flushCache();
        parent::tear_down();
    }

    // ── manifest parsing ───────────────────────────────────────────────

    public function test_manifest_reads_every_declared_key(): void {
        $manifest = CourseManifest::fromData( 'demo', [
            'title'                  => 'Demo course',
            'summary'                => 'A summary.',
            'source_lang'            => 'nl_NL',
            'audience'               => [ 'coach', 'head_dev' ],
            'capability'             => 'tt_view_knowledge',
            'feature'                => 'knowledge_courses',
            'tier'                   => 'pro',
            'requires'               => [ 'other-course' ],
            'methodology_principles' => [ 'conditie-periodisering' ],
            'estimated_hours'        => '12',
            'sequential'             => 'false',
            'lessons'                => [ '01-one', '02-two' ],
        ] );

        $this->assertNotNull( $manifest );
        $this->assertSame( 'Demo course', $manifest->title() );
        $this->assertSame( 'nl_NL', $manifest->sourceLang() );
        $this->assertSame( [ 'coach', 'head_dev' ], $manifest->audience() );
        $this->assertSame( 'pro', $manifest->tier() );
        $this->assertSame( [ 'other-course' ], $manifest->requires() );
        $this->assertSame( [ 'conditie-periodisering' ], $manifest->methodologyPrinciples() );
        $this->assertSame( 12, $manifest->estimatedHours() );
        $this->assertFalse( $manifest->isSequential() );
        $this->assertSame( [ '01-one', '02-two' ], $manifest->lessonSlugs() );
    }

    public function test_manifest_without_a_title_does_not_register(): void {
        $this->assertNull( CourseManifest::fromData( 'demo', [ 'lessons' => [ '01-one' ] ] ) );
    }

    public function test_manifest_without_lessons_does_not_register(): void {
        $this->assertNull( CourseManifest::fromData( 'demo', [ 'title' => 'Demo' ] ) );
    }

    /**
     * A course is a sequence unless it opts out. Guessing wrong in this
     * direction costs a reader some clicks; guessing wrong the other way
     * unlocks a lesson whose prerequisite was never done.
     */
    public function test_sequential_defaults_to_true(): void {
        $manifest = CourseManifest::fromData( 'demo', [
            'title'   => 'Demo',
            'lessons' => [ '01-one' ],
        ] );

        $this->assertTrue( $manifest->isSequential() );
    }

    public function test_tier_defaults_to_standard(): void {
        $manifest = CourseManifest::fromData( 'demo', [
            'title'   => 'Demo',
            'lessons' => [ '01-one' ],
        ] );

        $this->assertSame( 'standard', $manifest->tier() );
    }

    /**
     * Slugs become path segments and REST route parameters. Anything that
     * can hold a dot or a slash is a traversal waiting to happen.
     */
    public function test_slug_validation_rejects_traversal_shapes(): void {
        $this->assertTrue( CourseManifest::isValidSlug( '01-voetbaltaal' ) );
        $this->assertTrue( CourseManifest::isValidSlug( 'course' ) );

        $this->assertFalse( CourseManifest::isValidSlug( '../etc' ) );
        $this->assertFalse( CourseManifest::isValidSlug( 'a/b' ) );
        $this->assertFalse( CourseManifest::isValidSlug( 'a.b' ) );
        $this->assertFalse( CourseManifest::isValidSlug( 'Uppercase' ) );
        $this->assertFalse( CourseManifest::isValidSlug( '' ) );
        $this->assertFalse( CourseManifest::isValidSlug( 'trailing-' ) );
    }

    public function test_lesson_slugs_drop_entries_that_are_not_valid_slugs(): void {
        $manifest = CourseManifest::fromData( 'demo', [
            'title'   => 'Demo',
            'lessons' => [ '01-one', '../escape', '02-two' ],
        ] );

        $this->assertSame( [ '01-one', '02-two' ], $manifest->lessonSlugs() );
    }

    // ── lesson parsing ─────────────────────────────────────────────────

    public function test_lesson_reads_front_matter_and_strips_it_from_the_body(): void {
        $path = $this->writeTempLesson(
            "---\ntitle: Voetbaltaal\nobjectives: [Een, Twee]\nassignment: true\nquiz: true\nestimated_minutes: 45\n---\n\n# Body\n\nProse.\n"
        );

        $lesson = CourseLesson::fromFile( '01-voetbaltaal', $path );

        $this->assertNotNull( $lesson );
        $this->assertSame( 'Voetbaltaal', $lesson->title() );
        $this->assertSame( [ 'Een', 'Twee' ], $lesson->objectives() );
        $this->assertTrue( $lesson->hasAssignment() );
        $this->assertTrue( $lesson->hasQuiz() );
        $this->assertSame( 45, $lesson->estimatedMinutes() );
        $this->assertStringStartsWith( '# Body', $lesson->body() );
        $this->assertStringNotContainsString( 'title: Voetbaltaal', $lesson->body() );
    }

    /**
     * Absent reads as false, so a lesson only ever opts in to a
     * completion requirement. The opposite default would make every
     * lesson written before #2647 suddenly require a quiz that has no
     * payload.
     */
    public function test_assignment_and_quiz_default_to_false(): void {
        $path   = $this->writeTempLesson( "---\ntitle: Plain\n---\n\nProse.\n" );
        $lesson = CourseLesson::fromFile( '01-plain', $path );

        $this->assertNotNull( $lesson );
        $this->assertFalse( $lesson->hasAssignment() );
        $this->assertFalse( $lesson->hasQuiz() );
        $this->assertSame( 0, $lesson->estimatedMinutes() );
    }

    public function test_lesson_without_front_matter_does_not_parse(): void {
        $path = $this->writeTempLesson( "# Just a heading\n\nProse.\n" );

        $this->assertNull( CourseLesson::fromFile( '01-bare', $path ) );
    }

    public function test_lesson_with_an_unterminated_block_does_not_parse(): void {
        $path = $this->writeTempLesson( "---\ntitle: Never closed\n\n# Body\n" );

        $this->assertNull( CourseLesson::fromFile( '01-unterminated', $path ) );
    }

    public function test_missing_lesson_file_is_null_not_an_error(): void {
        $this->assertNull( CourseLesson::fromFile( '01-gone', CourseRegistry::root() . 'nope/01-gone.md' ) );
    }

    // ── the shipped corpus ─────────────────────────────────────────────

    public function test_the_shipped_course_registers(): void {
        $manifest = CourseRegistry::get( self::SHIPPED );

        $this->assertNotNull( $manifest, 'The periodisation course should be registered.' );
        $this->assertNotSame( '', $manifest->title() );
        $this->assertNotSame( '', $manifest->summary() );
        $this->assertSame( 'nl_NL', $manifest->sourceLang() );
        $this->assertTrue( $manifest->isSequential() );
    }

    /**
     * The drift guard. `check-courses.php` enforces this in CI; asserting
     * it here as well means a corpus edit that breaks the registry fails
     * the test suite too, rather than only the lint.
     */
    public function test_every_declared_lesson_in_the_shipped_course_resolves(): void {
        $manifest = CourseRegistry::get( self::SHIPPED );
        $this->assertNotNull( $manifest );

        $declared = $manifest->lessonSlugs();
        $resolved = array_keys( CourseRegistry::lessons( self::SHIPPED ) );

        $this->assertSame(
            $declared,
            $resolved,
            'Every lesson the manifest declares must resolve, in the declared order.'
        );
    }

    /**
     * Order is the manifest's to decide. The shipped course happens to be
     * numbered in reading order, so a directory sort would pass by luck —
     * assert against the manifest rather than against a sorted list, so
     * this keeps holding when a course retires a lesson and the numbers
     * stop being contiguous.
     */
    public function test_lessons_come_back_in_manifest_order(): void {
        $this->assertSame(
            CourseRegistry::get( self::SHIPPED )->lessonSlugs(),
            array_keys( CourseRegistry::lessons( self::SHIPPED ) )
        );
    }

    public function test_every_lesson_declaring_a_quiz_has_a_payload(): void {
        foreach ( CourseRegistry::lessons( self::SHIPPED ) as $slug => $lesson ) {
            if ( ! $lesson->hasQuiz() ) {
                continue;
            }

            $path = CourseRegistry::quizPath( self::SHIPPED, $slug );
            $this->assertNotNull( $path, "Lesson {$slug} declares quiz: true but has no payload." );

            $payload = json_decode( (string) file_get_contents( $path ), true );
            $this->assertIsArray( $payload, "Quiz payload for {$slug} is not valid JSON." );
            $this->assertArrayHasKey( 'questions', $payload );
            $this->assertNotEmpty( $payload['questions'] );
        }
    }

    public function test_unknown_course_and_lesson_resolve_to_null(): void {
        $this->assertNull( CourseRegistry::get( 'no-such-course' ) );
        $this->assertSame( [], CourseRegistry::lessons( 'no-such-course' ) );
        $this->assertNull( CourseRegistry::lesson( self::SHIPPED, 'no-such-lesson' ) );
    }

    /**
     * A lesson slug that the manifest does not declare must not resolve
     * even when a file of that name exists — otherwise the sequential gate
     * in #2645 can be walked around by guessing a URL.
     */
    public function test_a_file_not_declared_by_the_manifest_is_unreachable(): void {
        $this->assertNull( CourseRegistry::lesson( self::SHIPPED, 'course' ) );
    }

    public function test_locale_directories_are_not_mistaken_for_courses(): void {
        foreach ( array_keys( CourseRegistry::all() ) as $slug ) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[a-z]{2}(_[A-Z]{2})?$/',
                $slug,
                "Locale directory {$slug} registered as a course."
            );
        }
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Write a lesson fixture to the system temp directory.
     *
     * Deliberately not inside `courses/`: a fixture left behind by a
     * crashed test would then be a folder the corpus lint fails on, and
     * the next contributor would be debugging a CI failure that has
     * nothing to do with their change.
     */
    private function writeTempLesson( string $contents ): string {
        $dir = rtrim( get_temp_dir(), '/\\' ) . '/tt-course-fixture';
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0777, true );
            $this->temp_paths[] = $dir;
        }

        $path = $dir . '/' . uniqid( 'lesson-', false ) . '.md';
        file_put_contents( $path, $contents );
        $this->temp_paths[] = $path;

        return $path;
    }
}
