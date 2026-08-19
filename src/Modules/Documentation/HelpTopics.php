<?php
namespace TT\Modules\Documentation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HelpTopics — the registry of in-product help topics.
 *
 * The registry is a projection of `docs/`, not a list beside it. Every
 * `docs/<slug>.md` carrying a front-matter block (see DocFrontMatter) is a
 * topic; every file without one is invisible to the in-product index, which
 * is how developer-facing documentation opts out.
 *
 * This used to be a hand-maintained PHP literal that had to be edited
 * alongside each new file. It drifted — dozens of shipped features had
 * documentation on disk that no reader could reach. Dropping a documented
 * file into `docs/` now registers it.
 *
 * Metadata is read from the *localised* file when one exists, so Dutch
 * titles and summaries come from `docs/nl_NL/<slug>.md` rather than from
 * the translation catalogue. A topic with no localised twin falls back to
 * English, the same way its body always has.
 */
class HelpTopics {

    /** Cache lifetime. Version-keyed as well, so an update busts it. */
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** Fallback when a topic omits `order:`. Sorts after curated entries. */
    private const DEFAULT_ORDER = 50;

    /** @var array<string, array<string, mixed>>|null in-process memo */
    private static $memo = null;

    /**
     * Every registered topic, keyed by slug and ordered for display:
     * by `order:` first so a group can be curated, then by title.
     *
     * The tuple keeps `title` / `group` / `summary` for existing callers
     * and adds the front-matter keys the docs surfaces consume — audience
     * today, the gating and view-mapping keys shortly.
     *
     * @return array<string, array{
     *   title: string,
     *   group: string,
     *   summary: string,
     *   audience: list<string>,
     *   views: list<string>,
     *   module: string,
     *   feature: string,
     *   tier: string,
     *   capability: string,
     *   order: int
     * }>
     */
    public static function all(): array {
        if ( self::$memo !== null ) {
            return self::$memo;
        }

        $key    = self::cacheKey();
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            self::$memo = $cached;
            return $cached;
        }

        $topics = self::scan();
        set_transient( $key, $topics, self::CACHE_TTL );
        self::$memo = $topics;
        return $topics;
    }

    /**
     * Drop the cached scan. Called from tests and by anything that writes
     * a doc file at runtime; a plugin update busts the cache on its own
     * through the version-keyed transient name.
     */
    public static function flushCache(): void {
        delete_transient( self::cacheKey() );
        self::$memo = null;
    }

    /**
     * Group labels in display order. Keys match each topic's `group`.
     *
     * Hand-ordered on purpose — this decides the order of the sidebar,
     * which a directory listing cannot express.
     *
     * @return array<string, string>
     */
    public static function groups(): array {
        return [
            'basics'        => __( 'Basics', 'talenttrack' ),
            'performance'   => __( 'Performance', 'talenttrack' ),
            'analytics'     => __( 'Analytics', 'talenttrack' ),
            'configuration' => __( 'Configuration', 'talenttrack' ),
            'frontend'      => __( 'Frontend & access', 'talenttrack' ),
            'mobile'        => __( 'Mobile install', 'talenttrack' ),
            'developer'     => __( 'Developer', 'talenttrack' ),
            'development'   => __( 'Development', 'talenttrack' ),
        ];
    }

    /**
     * Resolve a registered topic slug to the markdown file that should be
     * rendered for the current viewer, or null when the slug is not a
     * topic. Locale-aware: `docs/<locale>/<slug>.md` wins over the
     * canonical English file when it exists.
     */
    public static function filePath( string $slug ): ?string {
        return isset( self::all()[ $slug ] ) ? self::resolvePath( $slug ) : null;
    }

    /**
     * Default topic slug when none is requested.
     */
    public static function defaultSlug(): string {
        return 'getting-started';
    }

    /**
     * Read every `docs/*.md`, keep the ones carrying front matter.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function scan(): array {
        $files = glob( TT_PATH . 'docs/*.md' );
        if ( ! is_array( $files ) ) {
            return [];
        }

        $topics = [];
        foreach ( $files as $file ) {
            $slug = basename( $file, '.md' );
            if ( ! self::isValidSlug( $slug ) ) {
                continue;
            }

            // Metadata comes from the localised file when there is one, so
            // a translated title reaches the sidebar without a round trip
            // through the translation catalogue.
            $data = DocFrontMatter::fromFile( self::resolvePath( $slug ) );
            if ( $data === [] ) {
                $data = DocFrontMatter::fromFile( $file );
            }
            if ( $data === [] ) {
                continue;
            }

            $title = DocFrontMatter::string( $data, 'title' );
            $group = DocFrontMatter::string( $data, 'group' );
            if ( $title === '' || $group === '' ) {
                continue;
            }

            $order = DocFrontMatter::string( $data, 'order' );

            $topics[ $slug ] = [
                'title'      => $title,
                'group'      => $group,
                'summary'    => DocFrontMatter::string( $data, 'summary' ),
                'audience'   => DocFrontMatter::list( $data, 'audience' ),
                'views'      => DocFrontMatter::list( $data, 'views' ),
                'module'     => DocFrontMatter::string( $data, 'module' ),
                'feature'    => DocFrontMatter::string( $data, 'feature' ),
                'tier'       => DocFrontMatter::string( $data, 'tier' ),
                'capability' => DocFrontMatter::string( $data, 'capability' ),
                'order'      => is_numeric( $order ) ? (int) $order : self::DEFAULT_ORDER,
            ];
        }

        uasort( $topics, static function ( array $a, array $b ): int {
            return $a['order'] <=> $b['order'] ?: strcasecmp( $a['title'], $b['title'] );
        } );

        return $topics;
    }

    /**
     * Locale-aware path for a slug, without consulting the registry — the
     * scan itself needs this, and going through `filePath()` would recurse.
     * Returns null when neither the localised nor the canonical file exists.
     */
    private static function resolvePath( string $slug ): ?string {
        if ( ! self::isValidSlug( $slug ) ) {
            return null;
        }
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        if ( $locale ) {
            $localized = TT_PATH . 'docs/' . $locale . '/' . $slug . '.md';
            if ( file_exists( $localized ) ) {
                return $localized;
            }
        }
        $path = TT_PATH . 'docs/' . $slug . '.md';
        return file_exists( $path ) ? $path : null;
    }

    /**
     * Slugs are file names. Constrain them so no caller can traverse out
     * of the docs directory with one.
     */
    private static function isValidSlug( string $slug ): bool {
        return (bool) preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug );
    }

    private static function cacheKey(): string {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        return 'tt_help_topics_' . md5( (string) $locale . '|' . TT_VERSION );
    }
}
