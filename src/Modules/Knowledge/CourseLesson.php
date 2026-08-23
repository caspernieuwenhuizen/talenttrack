<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Documentation\DocFrontMatter;
use TT\Modules\Knowledge\Blocks\BlockRegistry;

/**
 * CourseLesson — one lesson file: its front matter and its body.
 *
 *   ---
 *   title: Voetbaltaal
 *   objectives: [Waarom algemene conditietaal niet stuurt, ...]
 *   assignment: true
 *   quiz: true
 *   estimated_minutes: 45
 *   ---
 *
 * `assignment` and `quiz` are declarations, not content. They say what
 * completing this lesson requires, which is what the sequential gate reads
 * (#2645) and what the completion service counts (#2649). The assignment
 * text lives in the body as a `tt-assignment` block; the quiz payload lives
 * beside the lesson in `quizzes/<slug>.json`. Keeping the declaration in
 * front matter means the course structure can be answered without parsing
 * ten lesson bodies — the library index needs exactly that.
 *
 * The body is returned raw. Turning it into HTML is the renderer's job
 * (#2643); a lesson object that rendered itself would make the registry
 * depend on the block layer, and the registry is the thing the CI lint
 * wants to use without booting WordPress.
 */
final class CourseLesson {

    /** Assumed when a lesson does not estimate its own length. */
    private const DEFAULT_MINUTES = 0;

    /** @var string */
    private $slug;

    /** @var array<string, string|list<string>> */
    private $data;

    /** @var string */
    private $body;

    /**
     * @param array<string, string|list<string>> $data
     */
    private function __construct( string $slug, array $data, string $body ) {
        $this->slug = $slug;
        $this->data = $data;
        $this->body = $body;
    }

    /**
     * Build from a lesson path. Null when unreadable or when the file
     * carries no front matter — a lesson without a title has nothing to
     * show in a lesson list, and silently listing "Untitled" is worse
     * than not listing it and letting the lint say why.
     */
    public static function fromFile( string $slug, ?string $path ): ?self {
        if ( $path === null || ! is_readable( $path ) ) {
            return null;
        }

        $source = (string) @file_get_contents( $path );
        if ( $source === '' ) {
            return null;
        }

        $data = DocFrontMatter::parse( $source );
        if ( $data === [] || DocFrontMatter::string( $data, 'title' ) === '' ) {
            return null;
        }

        return new self( $slug, $data, DocFrontMatter::strip( $source ) );
    }

    public function slug(): string {
        return $this->slug;
    }

    public function title(): string {
        return DocFrontMatter::string( $this->data, 'title' );
    }

    /** @return list<string> */
    public function objectives(): array {
        return DocFrontMatter::list( $this->data, 'objectives' );
    }

    /** Whether completing this lesson requires an approved assignment. */
    public function hasAssignment(): bool {
        return self::truthy( DocFrontMatter::string( $this->data, 'assignment' ) );
    }

    /**
     * The `id` on the lesson's `tt-assignment` block, which is the key a
     * submission is stored against (#2648).
     *
     * It lives in the block rather than the front matter because the block
     * is where an author writing the assignment already is, and a second
     * declaration would be a second thing to keep in step. Empty when the
     * lesson has no assignment or the block omits an id; `SubmissionService`
     * falls back to the lesson slug, and `course-lint` is what keeps a
     * declared assignment and its block together in the first place.
     *
     * Not memoised. The one caller is a submit, which happens once per
     * assignment per coach — caching a scan of a body already held in
     * memory would trade a real footprint for an imaginary saving.
     */
    public function assignmentKey(): string {
        if ( ! $this->hasAssignment() ) return '';

        if ( ! preg_match( '/^```[ \t]*(tt-assignment[^\n]*)$/m', $this->body, $match ) ) {
            return '';
        }

        $attrs = BlockRegistry::parseAttributes( $match[1] );
        return trim( $attrs['id'] ?? '' );
    }

    /** Whether completing this lesson requires a passed quiz. */
    public function hasQuiz(): bool {
        return self::truthy( DocFrontMatter::string( $this->data, 'quiz' ) );
    }

    /** Reading time in minutes; zero when the lesson does not say. */
    public function estimatedMinutes(): int {
        $raw = DocFrontMatter::string( $this->data, 'estimated_minutes' );
        return is_numeric( $raw ) ? (int) $raw : self::DEFAULT_MINUTES;
    }

    /** The markdown body, front matter removed. */
    public function body(): string {
        return $this->body;
    }

    /**
     * Lesson metadata without the body — what a lesson list needs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'slug'              => $this->slug,
            'title'             => $this->title(),
            'objectives'        => $this->objectives(),
            'assignment'        => $this->hasAssignment(),
            'quiz'              => $this->hasQuiz(),
            'estimated_minutes' => $this->estimatedMinutes(),
        ];
    }

    /**
     * Front matter has no booleans — every value arrives as a string.
     * Absent reads as false, so a lesson only opts in to a requirement.
     */
    private static function truthy( string $raw ): bool {
        return in_array( strtolower( $raw ), [ 'true', 'yes', '1', 'on' ], true );
    }
}
