<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Documentation\DocFrontMatter;

/**
 * CourseManifest — the `course.md` front-matter block, validated.
 *
 * A course is a folder under `courses/` whose `course.md` carries a block
 * this class accepts. Everything the reader, the gate and the statistics
 * need about a course as a whole is declared there:
 *
 *   ---
 *   title: Periodiseren in voetbaltaal
 *   summary: Voetbalconditie in voetbalhandelingen, en hoe je die plant.
 *   source_lang: nl_NL
 *   audience: [coach, head_coach, head_dev]
 *   capability: tt_view_knowledge
 *   feature: knowledge_courses
 *   tier: standard
 *   requires: [some-other-course]
 *   methodology_principles: [conditie-periodisering]
 *   estimated_hours: 36
 *   sequential: true
 *   lessons: [01-voetbaltaal, 02-vier-kenmerken]
 *   ---
 *
 * Two decisions are worth stating because they are not the obvious ones.
 *
 * Lesson order comes from `lessons:`, never from sorting the directory.
 * A numbered filename is a convenience for whoever opens the folder, not
 * a contract; retiring lesson 4 of ten should not mean renaming six files
 * and invalidating every stored `lesson_slug` in the progress table.
 *
 * `source_lang:` names the language the canonical files are written in.
 * The docs corpus is English-first with translations under `docs/<locale>/`;
 * the first course is Dutch-first. Rather than shipping an English shell
 * nobody wrote, a course declares its own source language and the reader
 * falls back to it. A viewer whose locale has no translation gets the
 * course in the language it was written in, and the UI can say so.
 *
 * Parsing rides DocFrontMatter, which is deliberately not a YAML
 * implementation — scalars and inline lists, nothing else. Do not reach
 * for a richer block format here; reach for fewer keys.
 */
final class CourseManifest {

    /** Manifest filename inside a course folder. */
    public const FILENAME = 'course.md';

    /** Assumed when `tier:` is omitted — available on every licence. */
    private const DEFAULT_TIER = 'standard';

    /** @var string */
    private $slug;

    /** @var array<string, string|list<string>> */
    private $data;

    /**
     * @param array<string, string|list<string>> $data Parsed front matter.
     */
    private function __construct( string $slug, array $data ) {
        $this->slug = $slug;
        $this->data = $data;
    }

    /**
     * Build from a `course.md` path.
     *
     * Returns null when the file is missing, carries no front-matter block,
     * or omits a key without which the course cannot be presented at all.
     * A folder that fails here is invisible rather than broken — the same
     * contract `HelpTopics` uses, and what lets a work-in-progress course
     * sit in the tree without reaching a reader. `check-courses.php` is
     * what turns that silence into a CI failure.
     */
    public static function fromFile( string $slug, ?string $path ): ?self {
        if ( $path === null || ! is_readable( $path ) ) {
            return null;
        }

        return self::fromData( $slug, DocFrontMatter::fromFile( $path ) );
    }

    /**
     * Build from an already-parsed block.
     *
     * The registry caches parsed front matter rather than manifest objects
     * — a transient holding serialised instances of this class breaks the
     * moment the class gains a property, and it would break silently, on
     * somebody else's install, after an update. Arrays survive that.
     *
     * @param array<string, string|list<string>> $data
     */
    public static function fromData( string $slug, array $data ): ?self {
        if ( $data === [] || ! self::isValidSlug( $slug ) ) {
            return null;
        }

        $manifest = new self( $slug, $data );
        if ( $manifest->title() === '' || $manifest->lessonSlugs() === [] ) {
            return null;
        }

        return $manifest;
    }

    public function slug(): string {
        return $this->slug;
    }

    public function title(): string {
        return DocFrontMatter::string( $this->data, 'title' );
    }

    public function summary(): string {
        return DocFrontMatter::string( $this->data, 'summary' );
    }

    /**
     * The language the canonical files are written in. Empty when the
     * course does not say, which the reader treats as "no claim made"
     * rather than as English.
     */
    public function sourceLang(): string {
        return DocFrontMatter::string( $this->data, 'source_lang' );
    }

    /** @return list<string> */
    public function audience(): array {
        return DocFrontMatter::list( $this->data, 'audience' );
    }

    /** Capability a viewer needs. Empty means the course is not cap-gated. */
    public function capability(): string {
        return DocFrontMatter::string( $this->data, 'capability' );
    }

    /** Sub-feature key that switches the course off. Empty means always on. */
    public function feature(): string {
        return DocFrontMatter::string( $this->data, 'feature' );
    }

    public function tier(): string {
        return DocFrontMatter::string( $this->data, 'tier', self::DEFAULT_TIER );
    }

    /**
     * Course slugs that must be completed first.
     *
     * @return list<string>
     */
    public function requires(): array {
        return DocFrontMatter::list( $this->data, 'requires' );
    }

    /**
     * Methodology principles this course teaches. The binding that makes a
     * coach-facing library answer a player-facing question: whether the
     * staff around a squad is trained in the method that squad is coached
     * with. Consumed in #2649.
     *
     * @return list<string>
     */
    public function methodologyPrinciples(): array {
        return DocFrontMatter::list( $this->data, 'methodology_principles' );
    }

    /**
     * Study load in hours. Zero when the course does not estimate one, so
     * a caller can distinguish "not stated" from "very short".
     */
    public function estimatedHours(): int {
        $raw = DocFrontMatter::string( $this->data, 'estimated_hours' );
        return is_numeric( $raw ) ? (int) $raw : 0;
    }

    /**
     * Whether lessons unlock in order. Defaults to true: a course is a
     * sequence unless it says otherwise, and the failure mode of guessing
     * wrong in that direction is a reader who has to click in order.
     */
    public function isSequential(): bool {
        $raw = strtolower( DocFrontMatter::string( $this->data, 'sequential', 'true' ) );
        return ! in_array( $raw, [ 'false', 'no', '0', 'off' ], true );
    }

    /**
     * Lesson slugs in the order the course teaches them.
     *
     * @return list<string>
     */
    public function lessonSlugs(): array {
        $slugs = DocFrontMatter::list( $this->data, 'lessons' );
        return array_values( array_filter( $slugs, [ self::class, 'isValidSlug' ] ) );
    }

    /**
     * Everything the REST layer sends for a course, without lesson bodies.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'slug'                   => $this->slug,
            'title'                  => $this->title(),
            'summary'                => $this->summary(),
            'source_lang'            => $this->sourceLang(),
            'audience'               => $this->audience(),
            'capability'             => $this->capability(),
            'feature'                => $this->feature(),
            'tier'                   => $this->tier(),
            'requires'               => $this->requires(),
            'methodology_principles' => $this->methodologyPrinciples(),
            'estimated_hours'        => $this->estimatedHours(),
            'sequential'             => $this->isSequential(),
            'lessons'                => $this->lessonSlugs(),
        ];
    }

    /**
     * Slug shape for both courses and lessons. Constrained deliberately:
     * these become path segments and REST route parameters, and a slug
     * that can hold a dot or a slash is a traversal waiting to happen.
     */
    public static function isValidSlug( string $slug ): bool {
        return (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug );
    }
}
