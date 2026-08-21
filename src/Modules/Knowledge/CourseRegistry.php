<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Documentation\DocFrontMatter;

/**
 * CourseRegistry — the catalogue of courses, projected from `courses/`.
 *
 * A projection, never a list beside the folder. `HelpTopics` learned this
 * the expensive way: a hand-maintained PHP literal next to `docs/` drifted
 * until 53 documented features had no reachable page. Dropping a folder
 * with a valid `course.md` in registers a course; deleting the folder
 * unregisters it. There is no second place to edit and therefore no second
 * place to forget.
 *
 * Locale resolution mirrors the docs corpus and then adds one step. A file
 * is looked for under `courses/<locale>/<course>/` first and under
 * `courses/<course>/` second. Where the docs fallback lands on English by
 * construction, a course's canonical files may be written in any language
 * and say which in `source_lang:` — so the fallback lands on the language
 * the course was written in, and the reader can tell the viewer that
 * rather than pretending the translation exists.
 *
 * Caching matches HelpTopics: an in-process memo for the request, and a
 * version-keyed transient so a plugin update invalidates without anyone
 * remembering to flush.
 */
final class CourseRegistry {

    /** Cache lifetime. Version-keyed as well, so an update busts it. */
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** Corpus root, relative to the plugin directory. */
    private const ROOT = 'courses';

    /** @var array<string, CourseManifest>|null in-process memo */
    private static $memo = null;

    /**
     * Rebuild the manifest objects from cached front-matter arrays.
     *
     * @param array<string, array<string, string|list<string>>> $raw
     * @return array<string, CourseManifest>
     */
    private static function hydrate( array $raw ): array {
        $courses = [];
        foreach ( $raw as $slug => $data ) {
            $manifest = CourseManifest::fromData( (string) $slug, is_array( $data ) ? $data : [] );
            if ( $manifest !== null ) {
                $courses[ (string) $slug ] = $manifest;
            }
        }

        return $courses;
    }

    /**
     * Every registered course, keyed by slug, ordered by title.
     *
     * Ordering is alphabetical on purpose. A curated `order:` key would be
     * a third thing to keep in step for a catalogue that will hold a
     * handful of entries, and the library index sorts by the viewer's own
     * progress long before it sorts by anything an author declared.
     *
     * @return array<string, CourseManifest>
     */
    public static function all(): array {
        if ( self::$memo !== null ) {
            return self::$memo;
        }

        $cached = get_transient( self::cacheKey() );
        if ( is_array( $cached ) ) {
            self::$memo = self::hydrate( $cached );
            return self::$memo;
        }

        $raw = self::scan();
        set_transient( self::cacheKey(), $raw, self::CACHE_TTL );
        self::$memo = self::hydrate( $raw );

        return self::$memo;
    }

    /** One course, or null when the slug is not registered. */
    public static function get( string $slug ): ?CourseManifest {
        return self::all()[ $slug ] ?? null;
    }

    /**
     * A course's lessons, in the order its manifest declares.
     *
     * A slug the manifest names but no file provides is skipped rather
     * than rendered as a gap. That combination is what `check-courses.php`
     * fails a PR for, so at runtime it can only mean a half-deployed
     * install, where showing nine of ten lessons beats a fatal.
     *
     * @return array<string, CourseLesson> keyed by lesson slug
     */
    public static function lessons( string $slug ): array {
        $manifest = self::get( $slug );
        if ( $manifest === null ) {
            return [];
        }

        $lessons = [];
        foreach ( $manifest->lessonSlugs() as $lesson_slug ) {
            $lesson = CourseLesson::fromFile( $lesson_slug, self::lessonPath( $slug, $lesson_slug ) );
            if ( $lesson !== null ) {
                $lessons[ $lesson_slug ] = $lesson;
            }
        }

        return $lessons;
    }

    /** One lesson, or null when either slug does not resolve. */
    public static function lesson( string $course_slug, string $lesson_slug ): ?CourseLesson {
        $manifest = self::get( $course_slug );
        if ( $manifest === null || ! in_array( $lesson_slug, $manifest->lessonSlugs(), true ) ) {
            return null;
        }

        return CourseLesson::fromFile( $lesson_slug, self::lessonPath( $course_slug, $lesson_slug ) );
    }

    /**
     * Absolute path to a lesson's markdown for the current viewer, or null
     * when neither the localised nor the canonical file exists.
     */
    public static function lessonPath( string $course_slug, string $lesson_slug ): ?string {
        if ( ! CourseManifest::isValidSlug( $course_slug ) || ! CourseManifest::isValidSlug( $lesson_slug ) ) {
            return null;
        }

        return self::resolve( $course_slug, $lesson_slug . '.md' );
    }

    /**
     * Absolute path to a lesson's quiz payload, or null when it has none.
     * Quizzes are not localised separately: the payload sits beside the
     * canonical lesson and a translated course translates it in place.
     */
    public static function quizPath( string $course_slug, string $lesson_slug ): ?string {
        if ( ! CourseManifest::isValidSlug( $course_slug ) || ! CourseManifest::isValidSlug( $lesson_slug ) ) {
            return null;
        }

        return self::resolve( $course_slug, 'quizzes/' . $lesson_slug . '.json' );
    }

    /**
     * Drop the cached scan. Tests call it; a plugin update does not need
     * to, because the transient name carries the version.
     */
    public static function flushCache(): void {
        delete_transient( self::cacheKey() );
        self::$memo = null;
    }

    /** Corpus root as an absolute path, with a trailing slash. */
    public static function root(): string {
        return TT_PATH . self::ROOT . '/';
    }

    /**
     * Read every folder under `courses/`, keep the ones whose `course.md`
     * parses. Locale directories are skipped: `courses/nl_NL/` holds
     * translations of courses, not a course called `nl_NL`.
     *
     * @return array<string, array<string, string|list<string>>> raw front matter by slug
     */
    private static function scan(): array {
        $dirs = glob( self::root() . '*', GLOB_ONLYDIR );
        if ( ! is_array( $dirs ) ) {
            return [];
        }

        $found = [];
        foreach ( $dirs as $dir ) {
            $slug = basename( $dir );
            if ( ! CourseManifest::isValidSlug( $slug ) ) {
                continue;
            }

            // Metadata comes from the localised manifest when there is
            // one, so a translated title reaches the library index without
            // a round trip through the translation catalogue — the same
            // trade HelpTopics makes for topic titles.
            $path = self::resolve( $slug, CourseManifest::FILENAME );
            if ( $path === null ) {
                continue;
            }

            $data = DocFrontMatter::fromFile( $path );
            if ( CourseManifest::fromData( $slug, $data ) === null ) {
                continue;
            }

            $found[ $slug ] = $data;
        }

        uasort( $found, static function ( array $a, array $b ): int {
            return strcasecmp(
                DocFrontMatter::string( $a, 'title' ),
                DocFrontMatter::string( $b, 'title' )
            );
        } );

        return $found;
    }

    /**
     * Locale-aware path for a file inside a course folder. The localised
     * copy wins; the canonical file is the fallback. Returns null when
     * neither exists.
     */
    private static function resolve( string $course_slug, string $relative ): ?string {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

        if ( is_string( $locale ) && preg_match( '/^[a-zA-Z_]+$/', $locale ) ) {
            $localised = self::root() . $locale . '/' . $course_slug . '/' . $relative;
            if ( file_exists( $localised ) ) {
                return $localised;
            }
        }

        $canonical = self::root() . $course_slug . '/' . $relative;

        return file_exists( $canonical ) ? $canonical : null;
    }

    /**
     * Version- and locale-keyed.
     *
     * The locale belongs in the key because the scan itself is
     * locale-dependent: a translated `course.md` supplies the title the
     * index shows, so one cached scan cannot serve a Dutch and an English
     * request. Keying by locale is what makes that correct without a flush
     * on every `switch_locale`, which on a multilingual install would mean
     * rescanning the corpus several times per request.
     *
     * `flushCache()` therefore clears the current locale's entry. That is
     * enough for tests and for a developer editing the corpus; the other
     * locales expire on the TTL or on the next version bump.
     */
    private static function cacheKey(): string {
        $version = defined( 'TT_VERSION' ) ? TT_VERSION : 'dev';
        $locale  = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

        return 'tt_courses_' . $version . '_' . ( is_string( $locale ) ? $locale : 'x' );
    }
}
